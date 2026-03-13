<?php
/**
 * Module QR Code - Génération de QR Codes pour Postal ID et iBox
 */

require_once __DIR__ . '/utils/session.php';
SessionManager::start();
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'] ?? 0;
$message = '';
$messageType = '';

// Vérifier la connexion
if (!$user_id) {
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="access-denied">Accès refusé. Veuillez vous connecter.</div>';
    exit;
}

// Récupérer les données de l'utilisateur
$stmt = $db->prepare("SELECT email, prenom, nom, postal_code FROM utilisateurs u LEFT JOIN postal_id p ON u.id = p.utilisateur_id AND p.actif = 1 WHERE u.id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Récupérer les iBox de l'utilisateur
$stmt = $db->prepare("SELECT * FROM ibox WHERE utilisateur_id = ? ORDER BY date_creation DESC");
$stmt->execute([$user_id]);
$ibox_list = $stmt->fetchAll();

// Traitement du regeneration du QR code Postal ID
if (isset($_POST['action']) && $_POST['action'] === 'regenerate_postal_qr') {
    $stmt = $db->prepare("SELECT id FROM postal_id WHERE utilisateur_id = ? AND actif = 1");
    $stmt->execute([$user_id]);
    $postal = $stmt->fetch();
    
    if ($postal) {
        // Générer un nouveau code unique
        $new_code = 'PID' . strtoupper(bin2hex(random_bytes(5)));
        $stmt = $db->prepare("UPDATE postal_id SET postal_code = ? WHERE id = ?");
        $stmt->execute([$new_code, $postal['id']]);
        
        createNotification($user_id, 'postal_id', 'QR Code Postal ID régénéré', 
            'Votre code Postal ID a été régénéré. L\'ancien n\'est plus valide.');
        
        $message = 'QR Code Postal ID régénéré avec succès.';
        $messageType = 'success';
        
        // Mettre à jour les données
        $user['postal_code'] = $new_code;
    }
}

// Générer l'URL OTPauth pour le QR code
function generateOtpauthUrl($type, $identifier, $secret, $issuer = 'Gestion_Colis') {
    $label = rawurlencode($issuer) . ':' . rawurlencode($identifier);
    
    $params = [
        'secret' => $secret,
        'issuer' => rawurlencode($issuer),
        'algorithm' => 'SHA1',
        'digits' => 6,
        'period' => 30
    ];
    
    return "otpauth://{$type}/{$label}?" . http_build_query($params);
}

// Générer le contenu du QR code
function generateQRContent($type, $data) {
    switch ($type) {
        case 'postal_id':
            return json_encode([
                'type' => 'postal_id',
                'id' => $data['postal_code'],
                'user' => $data['user_id'],
                'timestamp' => time()
            ]);
        
        case 'ibox':
            return json_encode([
                'type' => 'ibox',
                'id' => $data['code_box'],
                'location' => $data['localisation'],
                'access' => 'pending'
            ]);
        
        case 'delivery':
            return json_encode([
                'type' => 'delivery',
                'tracking' => $data['code_tracking'],
                'status' => $data['statut']
            ]);
        
        default:
            return $data;
    }
}

// Créer une notification
function createNotification($userId, $type, $title, $message) {
    $database = new Database();
    $db = $database->getConnection();
    
    $stmt = $db->prepare("
        INSERT INTO notifications (user_id, type, title, message) 
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $type, $title, $message]);
}

// Obtenir le QR code via API externe
function getQRCodeUrl($content, $size = 200) {
    $encoded = urlencode($content);
    return "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$$size}&data={$encoded}";
}
?>

<div id="page-content">

<div class="page-container">
    <div class="page-header">
        <div class="header-left">
            <button class="btn btn-back" onclick="loadPage('dashboard.php', 'Dashboard')">
                <i class="fas fa-arrow-left"></i>
            </button>
            <h1>
                <i class="fas fa-qrcode" style="color: #00B4D8;"></i> 
                Mes QR Codes
            </h1>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?>">
            <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Postal ID QR Code -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-id-card"></i> QR Code Postal ID</h3>
        </div>
        <div class="card-body">
            <?php if ($user && $user['postal_code']): ?>
                <div class="qr-section">
                    <div class="qr-display">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=<?= urlencode($user['postal_code']) ?>" 
                             alt="QR Code Postal ID"
                             class="qr-image">
                    </div>
                    
                    <div class="qr-info">
                        <div class="qr-code-value">
                            <span class="label">Code Postal ID</span>
                            <span class="value"><?= htmlspecialchars($user['postal_code']) ?></span>
                        </div>
                        
                        <p class="text-muted">
                            Présentez ce QR code dans les points de retrait partenaires 
                            pour recevoir vos colis.
                        </p>

                        <div class="qr-actions">
                            <button class="btn btn-primary" onclick="downloadQRCode('postal_id')">
                                <i class="fas fa-download"></i> Télécharger
                            </button>
                            <button class="btn btn-secondary" onclick="shareQRCode('postal_id')">
                                <i class="fas fa-share-alt"></i> Partager
                            </button>
                            <form method="POST" style="display: inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="regenerate_postal_qr">
                                <button type="submit" class="btn btn-warning" onclick="return confirm('Êtes-vous sûr de vouloir régénérer votre QR Code ? L\'ancien ne sera plus valide.');">
                                    <i class="fas fa-sync"></i> Régénérer
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <strong>Comment utiliser votre Postal ID ?</strong>
                        <ul style="margin: 0.5rem 0 0 1.5rem;">
                            <li>Présentez ce QR code au point de retrait</li>
                            <li>Le livreur scannera votre code pour vous identifier</li>
                            <li>Vous recevrez une notification lors d'un dépôt</li>
                        </ul>
                    </div>
                </div>

            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-id-card fa-3x"></i>
                    <h3>Aucun Postal ID actif</h3>
                    <p>Créez d'abord votre Postal ID pour obtenir votre QR code.</p>
                    <button class="btn btn-primary" onclick="loadPage('mon_postal_id.php', 'Mon Postal ID')">
                        <i class="fas fa-plus"></i> Créer mon Postal ID
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- iBox QR Codes -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-inbox"></i> QR Codes de mes iBox</h3>
        </div>
        <div class="card-body">
            <?php if (!empty($ibox_list)): ?>
                <div class="ibox-qr-grid">
                    <?php foreach ($ibox_list as $ibox): ?>
                        <div class="ibox-qr-card">
                            <div class="ibox-qr-header">
                                <span class="ibox-code"><?= htmlspecialchars($ibox['code_box']) ?></span>
                                <span class="badge badge-<?= $ibox['statut'] === 'disponible' ? 'success' : 'warning' ?>">
                                    <?= htmlspecialchars($ibox['statut']) ?>
                                </span>
                            </div>
                            
                            <div class="ibox-qr-image">
                                <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?= urlencode($ibox['code_box'] . '|' . $ibox['code_acces']) ?>" 
                                     alt="QR Code <?= htmlspecialchars($ibox['code_box']) ?>">
                            </div>
                            
                            <div class="ibox-qr-details">
                                <div class="detail">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span><?= htmlspecialchars($ibox['localisation']) ?></span>
                                </div>
                                <div class="detail">
                                    <i class="fas fa-key"></i>
                                    <span>Code: <?= htmlspecialchars($ibox['code_acces']) ?></span>
                                </div>
                            </div>
                            
                            <div class="ibox-qr-actions">
                                <button class="btn btn-sm btn-primary" onclick="downloadIboxQR(<?= htmlspecialchars(json_encode($ibox['code_box'])) ?>)">
                                    <i class="fas fa-download"></i>
                                </button>
                                <button class="btn btn-sm btn-secondary" onclick="showIboxDetails(<?= htmlspecialchars(json_encode($ibox)) ?>)">
                                    <i class="fas fa-info-circle"></i>
                                </button>
                                <button class="btn btn-sm btn-secondary" onclick="shareIboxQR(<?= htmlspecialchars(json_encode($ibox['code_box'])) ?>)">
                                    <i class="fas fa-share-alt"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox fa-3x"></i>
                    <h3>Aucune iBox</h3>
                    <p>Créez une boîte virtuelle pour obtenir son QR code.</p>
                    <button class="btn btn-primary" onclick="loadPage('mes_ibox.php', 'Mes iBox')">
                        <i class="fas fa-plus"></i> Créer une iBox
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Générateur QR Code dynamique -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-magic"></i> Générateur QR Code</h3>
        </div>
        <div class="card-body">
            <form id="customQRForm" method="POST">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label for="qr_type">
                        <i class="fas fa-list"></i> Type de contenu
                    </label>
                    <select id="qr_type" name="qr_type" class="form-control">
                        <option value="text">Texte simple</option>
                        <option value="url">URL</option>
                        <option value="email">Email</option>
                        <option value="phone">Téléphone</option>
                        <option value="wifi">WiFi</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="qr_content">
                        <i class="fas fa-pen"></i> Contenu
                    </label>
                    <textarea id="qr_content" name="qr_content" class="form-control" 
                              rows="3" placeholder="Entrez votre contenu ici"></textarea>
                </div>

                <div class="form-group">
                    <label for="qr_size">
                        <i class="fas fa-expand"></i> Taille
                    </label>
                    <select id="qr_size" name="qr_size" class="form-control">
                        <option value="150">Petit (150px)</option>
                        <option value="250" selected>Moyen (250px)</option>
                        <option value="350">Grand (350px)</option>
                        <option value="500">Très grand (500px)</option>
                    </select>
                </div>

                <button type="button" class="btn btn-primary" onclick="generateCustomQR()">
                    <i class="fas fa-qrcode"></i> Générer
                </button>
            </form>

            <div id="customQRResult" class="mt-4" style="display: none;">
                <div class="qr-section">
                    <div class="qr-display">
                        <img id="customQRImage" src="" alt="QR Code personnalisé">
                    </div>
                    <div class="qr-info">
                        <p>Votre QR code personnalisé est prêt !</p>
                        <button class="btn btn-primary" onclick="downloadCustomQR()">
                            <i class="fas fa-download"></i> Télécharger
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function downloadQRCode(type) {
    const size = 500;
    let url, filename;
    
    if (type === 'postal_id') {
        url = `https://api.qrserver.com/v1/create-qr-code/?size=${size}x${size}&data=<?= urlencode($user['postal_code'] ?? '') ?>`;
        filename = 'postal_id_qr.png';
    }
    
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.target = '_blank';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function shareQRCode(type) {
    const text = type === 'postal_id' 
        ? 'Mon Postal ID Gestion_Colis: <?= htmlspecialchars($user['postal_code'] ?? '') ?>'
        : '';
    
    if (navigator.share) {
        navigator.share({
            title: 'Mon QR Code Postal ID',
            text: text,
            url: window.location.href
        });
    } else {
        // Copier dans le presse-papier
        navigator.clipboard.writeText(text).then(() => {
            showNotification('QR Code copié dans le presse-papier', 'success');
        });
    }
}

function downloadIboxQR(codeBox) {
    const size = 500;
    const url = `https://api.qrserver.com/v1/create-qr-code/?size=${size}x${size}&data=${encodeURIComponent(codeBox)}`;
    
    const link = document.createElement('a');
    link.href = url;
    link.download = `ibox_${codeBox}_qr.png`;
    link.target = '_blank';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function showIboxDetails(ibox) {
    alert(`iBox: ${ibox.code_box}\nLocalisation: ${ibox.localisation}\nType: ${ibox.type_box}\nCapacité: ${ibox.capacite_max}\nCode d'accès: ${ibox.code_acces}`);
}

function shareIboxQR(codeBox) {
    const text = `Mon iBox Gestion_Colis: ${codeBox}`;
    
    if (navigator.share) {
        navigator.share({
            title: 'Mon QR Code iBox',
            text: text
        });
    } else {
        navigator.clipboard.writeText(text).then(() => {
            showNotification('Code copié dans le presse-papier', 'success');
        });
    }
}

function generateCustomQR() {
    const type = document.getElementById('qr_type').value;
    let content = document.getElementById('qr_content').value;
    const size = document.getElementById('qr_size').value;
    
    if (!content) {
        showNotification('Veuillez entrer du contenu', 'error');
        return;
    }
    
    // Formater le contenu selon le type
    switch (type) {
        case 'email':
            content = `mailto:${content}`;
            break;
        case 'phone':
            content = `tel:${content}`;
            break;
        case 'wifi':
            content = `WIFI:T:WPA;S:${content};;`;
            break;
    }
    
    const url = `https://api.qrserver.com/v1/create-qr-code/?size=${size}x${size}&data=${encodeURIComponent(content)}`;
    
    document.getElementById('customQRImage').src = url;
    document.getElementById('customQRResult').style.display = 'block';
    document.getElementById('customQRResult').dataset.content = content;
}

function downloadCustomQR() {
    const img = document.getElementById('customQRImage');
    const link = document.createElement('a');
    link.href = img.src;
    link.download = 'qr_code_personnalise.png';
    link.target = '_blank';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

console.log('%c🚀 Gestion_Colis - QR Codes SPA', 'color: #00B4D8; font-size: 16px; font-weight: bold;');
</script>

<style>
.qr-section {
    display: flex;
    gap: 2rem;
    align-items: flex-start;
}

.qr-display {
    background: #fff;
    padding: 1rem;
    border-radius: 16px;
    flex-shrink: 0;
}

.qr-image {
    display: block;
    max-width: 250px;
    height: auto;
}

.qr-info {
    flex: 1;
}

.qr-code-value {
    background: rgba(0, 180, 216, 0.1);
    border: 1px solid rgba(0, 180, 216, 0.3);
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1rem;
}

.qr-code-value .label {
    display: block;
    color: var(--text-secondary);
    font-size: 0.85rem;
    margin-bottom: 0.5rem;
}

.qr-code-value .value {
    display: block;
    font-family: 'Courier New', monospace;
    font-size: 1.2rem;
    font-weight: bold;
    color: #00B4D8;
    letter-spacing: 2px;
}

.qr-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.ibox-qr-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 1.5rem;
}

.ibox-qr-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1rem;
    text-align: center;
}

.ibox-qr-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.ibox-qr-header .ibox-code {
    font-family: var(--font-display);
    font-weight: 700;
    color: #00B4D8;
    font-size: 0.9rem;
}

.ibox-qr-image {
    background: #fff;
    padding: 0.5rem;
    border-radius: 8px;
    margin-bottom: 1rem;
    display: inline-block;
}

.ibox-qr-image img {
    width: 150px;
    height: 150px;
}

.ibox-qr-details {
    text-align: left;
    margin-bottom: 1rem;
}

.ibox-qr-details .detail {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.8rem;
    color: var(--text-secondary);
    margin-bottom: 0.25rem;
}

.ibox-qr-details .detail i {
    width: 16px;
    color: #00B4D8;
}

.ibox-qr-actions {
    display: flex;
    gap: 0.5rem;
    justify-content: center;
}

@media (max-width: 768px) {
    .qr-section {
        flex-direction: column;
        align-items: center;
    }
    
    .qr-info {
        text-align: center;
    }
    
    .qr-actions {
        justify-content: center;
    }
}
</style>

</div> <!-- Fin #page-content -->
