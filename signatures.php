<?php
/**
 * Module iSignature - Signature Électronique à 3 Niveaux
 * Niveau 1: Simple (Canvas)
 * Niveau 2: Avancé (Canvas + OTP SMS)
 * Niveau 3: Qualifié (Canvas + Vérification identité + Horodatage légal)
 */

require_once __DIR__ . '/utils/session.php';
SessionManager::start();
require_once 'config/database.php';
require_once __DIR__ . '/utils/notification_helper.php';

$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'] ?? 0;
$message = '';
$messageType = '';
$signatureOtpTtlSeconds = 5 * 60;
$signatureOtpMaxAttempts = 3;

// Vérifier la connexion
if (!$user_id) {
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="access-denied">Accès refusé. Veuillez vous connecter.</div>';
    exit;
}

// Récupérer les informations de l'utilisateur
$stmt = $db->prepare("SELECT * FROM utilisateurs WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Récupérer les signatures de l'utilisateur
$stmt = $db->prepare("
    SELECT * FROM signatures 
    WHERE utilisateur_id = ? 
    ORDER BY created_at DESC
    LIMIT 20
");
$stmt->execute([$user_id]);
$signatures = $stmt->fetchAll();

// Traitement de la signature
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_signature') {
        $signature_level = $_POST['signature_level'] ?? 'simple';
        $signature_data = $_POST['signature_data'] ?? '';
        $document_hash = $_POST['document_hash'] ?? '';
        $document_name = $_POST['document_name'] ?? '';
        
        if (empty($signature_data)) {
            $message = 'La signature est requise.';
            $messageType = 'error';
        } else {
            try {
                // Vérifier le niveau de signature
                if ($signature_level === 'advanced') {
                    // Vérifier l'OTP
                    $otp = trim($_POST['otp'] ?? '');
                    $otpData = $_SESSION['signature_otp'] ?? null;
                    $expiresAt = is_array($otpData) ? (int) ($otpData['expires'] ?? 0) : 0;
                    $attempts = is_array($otpData) ? (int) ($otpData['attempts'] ?? 0) : 0;
                    $storedOtp = is_array($otpData) ? (string) ($otpData['code'] ?? '') : '';

                    if ($storedOtp === '' || $expiresAt === 0) {
                        $message = 'Code OTP expiré. Veuillez en demander un nouveau.';
                        $messageType = 'error';
                    } elseif (time() > $expiresAt) {
                        unset($_SESSION['signature_otp']);
                        $message = 'Code OTP expiré. Veuillez en demander un nouveau.';
                        $messageType = 'error';
                    } elseif ($attempts >= $signatureOtpMaxAttempts) {
                        unset($_SESSION['signature_otp']);
                        $message = 'Trop de tentatives. Veuillez générer un nouveau code.';
                        $messageType = 'error';
                    } elseif (!hash_equals($storedOtp, $otp)) {
                        $_SESSION['signature_otp']['attempts'] = $attempts + 1;
                        $message = 'Code OTP invalide.';
                        $messageType = 'error';
                    } else {
                        // OTP valide, enregistrer la signature
                        saveSignature($db, $user_id, $signature_level, $signature_data, $document_hash, $document_name);
                        unset($_SESSION['signature_otp']);
                        $message = 'Signature avancée enregistrée avec succès !';
                        $messageType = 'success';
                    }
                } elseif ($signature_level === 'qualified') {
                    // Vérifier l'identité
                    $id_verification = $_POST['id_verification'] ?? '';
                    $id_number = $_POST['id_number'] ?? '';
                    
                    if (empty($id_number)) {
                        $message = 'La vérification d\'identité est requise pour une signature qualifiée.';
                        $messageType = 'error';
                    } else {
                        // Vérifier avec la base de données
                        $stmt = $db->prepare("SELECT id FROM postal_id WHERE utilisateur_id = ? AND actif = 1");
                        $stmt->execute([$user_id]);
                        $postalId = $stmt->fetch();
                        
                        if ($postalId) {
                            saveSignature($db, $user_id, $signature_level, $signature_data, $document_hash, $document_name);
                            $message = 'Signature qualifiée enregistrée avec horodatage légal !';
                            $messageType = 'success';
                        } else {
                            $message = 'Vous devez avoir un Postal ID actif pour effectuer une signature qualifiée.';
                            $messageType = 'error';
                        }
                    }
                } else {
                    // Signature simple
                    saveSignature($db, $user_id, $signature_level, $signature_data, $document_hash, $document_name);
                    $message = 'Signature enregistrée avec succès !';
                    $messageType = 'success';
                }
                
            } catch (Exception $e) {
                $message = user_error_message($e, 'signatures.save', 'Erreur lors de l\'enregistrement de la signature.');
                $messageType = 'error';
            }
        }
    }
    
    if ($action === 'send_otp') {
        // Générer et envoyer OTP pour signature avancée
        $otp = generateOTP();
        $_SESSION['signature_otp'] = [
            'code' => $otp,
            'expires' => time() + $signatureOtpTtlSeconds,
            'attempts' => 0
        ];
        
        // Simuler l'envoi SMS (dans un vrai système, utiliser Twilio ou autre)
        $phone = $user['telephone'] ?? '';
        if (!empty($phone)) {
            createNotification($db, $user_id, 'security', 'Code OTP de signature', 
                "Votre code OTP pour signature avancée est: $otp");
            $message = 'Code OTP envoyé par SMS.';
        } else {
            createNotification($db, $user_id, 'security', 'Code OTP de signature', 
                "Votre code OTP pour signature avancée est: $otp");
            $message = 'Code OTP généré (aucun téléphone enregistré, code affiché dans les notifications).';
        }
        $messageType = 'success';
    }
}

function saveSignature($db, $userId, $level, $data, $docHash, $docName) {
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // Générer un hash de signature
    $signatureHash = hash('sha256', $data . $timestamp . $userId);
    
    $stmt = $db->prepare("
        INSERT INTO signatures (
            utilisateur_id, signature_level, signature_data, 
            document_hash, document_name, signature_hash,
            ip_address, user_agent, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $userId, $level, $data, $docHash, $docName,
        $signatureHash, $ip, $userAgent, $timestamp
    ]);
    
    // Mettre à jour la signature par défaut de l'utilisateur
    $stmt = $db->prepare("UPDATE utilisateurs SET signature_data = ?, date_signature = ? WHERE id = ?");
    $stmt->execute([$data, $timestamp, $userId]);
}

function generateOTP() {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
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
                <i class="fas fa-signature" style="color: #00B4D8;"></i> 
                iSignature Électronique
            </h1>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?>">
            <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Niveaux de signature -->
    <div class="signature-levels">
        <div class="level-card" data-level="simple">
            <div class="level-header">
                <div class="level-icon">
                    <i class="fas fa-pen"></i>
                </div>
                <div class="level-info">
                    <h3>Niveau 1 - Simple</h3>
                    <span class="level-badge badge-success">Gratuit</span>
                </div>
            </div>
            <div class="level-features">
                <ul>
                    <li><i class="fas fa-check"></i> Signature manuscrite sur écran</li>
                    <li><i class="fas fa-check"></i> Preuve de réception basique</li>
                    <li><i class="fas fa-check"></i> Horodatage serveur</li>
                </ul>
            </div>
            <button class="btn btn-primary btn-block" onclick="selectLevel('simple')">
                <i class="fas fa-pen-fancy"></i> Signer
            </button>
        </div>

        <div class="level-card" data-level="advanced">
            <div class="level-header">
                <div class="level-icon">
                    <i class="fas fa-mobile-alt"></i>
                </div>
                <div class="level-info">
                    <h3>Niveau 2 - Avancé</h3>
                    <span class="level-badge badge-info">OTP SMS</span>
                </div>
            </div>
            <div class="level-features">
                <ul>
                    <li><i class="fas fa-check"></i> Tout du niveau 1</li>
                    <li><i class="fas fa-check"></i> Validation par code SMS</li>
                    <li><i class="fas fa-check"></i> Preuve d'identité partielle</li>
                    <li><i class="fas fa-check"></i> Certificat numérique</li>
                </ul>
            </div>
            <button class="btn btn-info btn-block" onclick="selectLevel('advanced')">
                <i class="fas fa-sms"></i> Signer avec OTP
            </button>
        </div>

        <div class="level-card" data-level="qualified">
            <div class="level-header">
                <div class="level-icon">
                    <i class="fas fa-certificate"></i>
                </div>
                <div class="level-info">
                    <h3>Niveau 3 - Qualifié</h3>
                    <span class="level-badge badge-warning">Vérification identité</span>
                </div>
            </div>
            <div class="level-features">
                <ul>
                    <li><i class="fas fa-check"></i> Tout du niveau 2</li>
                    <li><i class="fas fa-check"></i> Vérification Postal ID</li>
                    <li><i class="fas fa-check"></i> Horodatage légal (RFC 3161)</li>
                    <li><i class="fas fa-check"></i> Valeur probante juridique</li>
                    <li><i class="fas fa-check"></i> Certificat qualifié</li>
                </ul>
            </div>
            <button class="btn btn-warning btn-block" onclick="selectLevel('qualified')">
                <i class="fas fa-certificate"></i> Signer qualifiée
            </button>
        </div>
    </div>

    <!-- Zone de signature -->
    <div class="card" id="signatureCard" style="display: none;">
        <div class="card-header">
            <h3><i class="fas fa-pen-fancy"></i> Signature - Niveau <span id="currentLevel">Simple</span></h3>
            <button class="btn btn-secondary btn-sm" onclick="cancelSignature()">
                <i class="fas fa-times"></i> Annuler
            </button>
        </div>
        <div class="card-body">
            <!-- Document optionnel -->
            <div class="form-group" id="documentSection">
                <label for="document_name">
                    <i class="fas fa-file"></i> Document à signer (optionnel)
                </label>
                <input type="text" id="document_name" name="document_name" class="form-control" 
                       placeholder="Nom du document">
            </div>

            <!-- Canvas de signature -->
            <div class="signature-container">
                <canvas id="signatureCanvas" width="700" height="250"></canvas>
            </div>
            
            <div class="signature-tools">
                <div class="color-picker">
                    <label>Couleur:</label>
                    <button class="color-btn active" style="background: #00B4D8;" onclick="setColor('#00B4D8')"></button>
                    <button class="color-btn" style="background: #22C55E;" onclick="setColor('#22C55E')"></button>
                    <button class="color-btn" style="background: #EF4444;" onclick="setColor('#EF4444')"></button>
                    <button class="color-btn" style="background: #F59E0B;" onclick="setColor('#F59E0B')"></button>
                    <button class="color-btn" style="background: #000000;" onclick="setColor('#000000')"></button>
                </div>
                <div class="size-slider">
                    <label>Taille:</label>
                    <input type="range" id="brushSize" min="1" max="10" value="3" onchange="setSize(this.value)">
                </div>
            </div>

            <!-- OTP pour niveau avancé -->
            <div id="otpSection" style="display: none;">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        Un code OTP a été envoyé à votre téléphone. Entrez-le ci-dessous pour valider votre signature.
                    </div>
                </div>
                <div class="form-group">
                    <label for="otp_code">
                        <i class="fas fa-key"></i> Code OTP <span class="required">*</span>
                    </label>
                    <input type="text" id="otp_code" name="otp" class="form-control" 
                           placeholder="XXXXXX" maxlength="6" pattern="[0-9]{6}">
                    <button type="button" class="btn btn-secondary btn-sm mt-2" onclick="sendOTP()">
                        <i class="fas fa-sync"></i> Renvoyer le code
                    </button>
                </div>
            </div>

            <!-- Vérification identité pour niveau qualifié -->
            <div id="identitySection" style="display: none;">
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        Pour une signature qualifiée, vous devez vérifier votre identité avec votre Postal ID.
                    </div>
                </div>
                <div class="form-group">
                    <label for="id_number">
                        <i class="fas fa-id-card"></i> Numéro de pièce d'identité <span class="required">*</span>
                    </label>
                    <input type="text" id="id_number" name="id_number" class="form-control" 
                           placeholder="Entrez le numéro de votre pièce">
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="id_verification" name="id_verification" required>
                        <span class="checkbox-custom"></span>
                        Je certifie que les informations d'identité sont exactes.
                    </label>
                </div>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="clearSignature()">
                    <i class="fas fa-eraser"></i> Effacer
                </button>
                <button type="button" class="btn btn-primary" onclick="saveSignatureDocument()">
                    <i class="fas fa-save"></i> Enregistrer la signature
                </button>
            </div>
        </div>
    </div>

    <!-- Historique des signatures -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-history"></i> Historique des signatures</h3>
        </div>
        <div class="card-body">
            <?php if (empty($signatures)): ?>
                <div class="empty-state">
                    <i class="fas fa-signature fa-3x"></i>
                    <h3>Aucune signature</h3>
                    <p>Vos signatures électroniques apparaîtront ici.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Niveau</th>
                                <th>Document</th>
                                <th>Horodatage</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($signatures as $sig): ?>
                            <tr>
                                <td><?= date('d/m/Y H:i', strtotime($sig['created_at'])) ?></td>
                                <td>
                                    <span class="badge badge-<?= 
                                        $sig['signature_level'] === 'qualified' ? 'warning' : 
                                        ($sig['signature_level'] === 'advanced' ? 'info' : 'success') 
                                    ?>">
                                        <?= ucfirst($sig['signature_level']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($sig['document_name'] ?: '-') ?></td>
                                <td>
                                    <code><?= htmlspecialchars(substr($sig['signature_hash'], 0, 16)) ?>...</code>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-secondary" onclick="downloadCertificate(<?= (int) $sig['id'] ?>, '<?= htmlspecialchars($sig['signature_hash'], ENT_QUOTES, 'UTF-8') ?>')">
                                        <i class="fas fa-file-pdf"></i>
                                    </button>
                                    <button class="btn btn-sm btn-secondary" onclick="verifySignature(<?= (int) $sig['id'] ?>, '<?= htmlspecialchars($sig['signature_hash'], ENT_QUOTES, 'UTF-8') ?>')">
                                        <i class="fas fa-check-circle"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Signature par défaut actuelle -->
    <?php if (!empty($user['signature_data'])): ?>
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-star"></i> Signature par défaut</h3>
        </div>
        <div class="card-body">
            <div class="signature-preview">
                <img src="<?= htmlspecialchars($user['signature_data']) ?>" alt="Signature" class="signature-image">
                <p class="text-muted">
                    Enregistrée le <?= $user['date_signature'] ? date('d/m/Y à H:i', strtotime($user['date_signature'])) : 'jamais' ?>
                </p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Informations légales -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-balance-scale"></i> Valeur juridique des signatures</h3>
        </div>
        <div class="card-body">
            <div class="legal-info">
                <div class="legal-item">
                    <h4><i class="fas fa-check-circle text-success"></i> Signature Simple</h4>
                    <p>Acceptée comme preuve de réception pour la plupart des livraisons et transactions commerciales.</p>
                </div>
                <div class="legal-item">
                    <h4><i class="fas fa-check-double-circle text-info"></i> Signature Avancée</h4>
                    <p>Preuve renforcée de l'identité du signataire. Acceptée pour les documents contractuels de niveau moyen.</p>
                </div>
                <div class="legal-item">
                    <h4><i class="fas fa-certificate text-warning"></i> Signature Qualifiée</h4>
                    <p>Équivalente à une signature manuscrite selon le règlement eIDAS. Acceptée devant les tribunaux et administrations.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let csrfToken = <?php echo json_encode(csrf_token()); ?>;
function refreshCsrfToken(response) {
    const newToken = response.headers.get('X-CSRF-Token');
    if (newToken) {
        csrfToken = newToken;
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) meta.content = newToken;
        document.querySelectorAll('input[name="csrf_token"]').forEach(input => {
            input.value = newToken;
        });
    }
    return response;
}
let canvas, ctx;
let isDrawing = false;
let lastX = 0;
let lastY = 0;
let currentLevel = 'simple';
let brushColor = '#00B4D8';
let brushSize = 3;

document.addEventListener('DOMContentLoaded', function() {
    canvas = document.getElementById('signatureCanvas');
    ctx = canvas.getContext('2d');
    
    // Configurer le canvas
    ctx.strokeStyle = brushColor;
    ctx.lineWidth = brushSize;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    
    // Événements souris
    canvas.addEventListener('mousedown', startDrawing);
    canvas.addEventListener('mousemove', draw);
    canvas.addEventListener('mouseup', stopDrawing);
    canvas.addEventListener('mouseout', stopDrawing);
    
    // Événements tactiles
    canvas.addEventListener('touchstart', handleTouchStart);
    canvas.addEventListener('touchmove', handleTouchMove);
    canvas.addEventListener('touchend', stopDrawing);
});

function selectLevel(level) {
    currentLevel = level;
    
    document.getElementById('currentLevel').textContent = 
        level === 'simple' ? 'Simple' : 
        level === 'advanced' ? 'Avancé' : 'Qualifié';
    
    document.getElementById('signatureCard').style.display = 'block';
    document.getElementById('signatureCard').scrollIntoView({ behavior: 'smooth' });
    
    // Afficher/masquer les sections spécifiques au niveau
    document.getElementById('otpSection').style.display = level === 'advanced' ? 'block' : 'none';
    document.getElementById('identitySection').style.display = level === 'qualified' ? 'block' : 'none';
    
    // Réinitialiser le canvas
    clearSignature();
    
    // Envoyer OTP automatiquement pour le niveau avancé
    if (level === 'advanced') {
        sendOTP();
    }
}

function cancelSignature() {
    document.getElementById('signatureCard').style.display = 'none';
    currentLevel = 'simple';
}

function startDrawing(e) {
    isDrawing = true;
    [lastX, lastY] = [e.offsetX, e.offsetY];
}

function draw(e) {
    if (!isDrawing) return;
    
    ctx.beginPath();
    ctx.moveTo(lastX, lastY);
    ctx.lineTo(e.offsetX, e.offsetY);
    ctx.stroke();
    [lastX, lastY] = [e.offsetX, e.offsetY];
}

function stopDrawing() {
    isDrawing = false;
}

function handleTouchStart(e) {
    e.preventDefault();
    const touch = e.touches[0];
    const rect = canvas.getBoundingClientRect();
    isDrawing = true;
    [lastX, lastY] = [touch.clientX - rect.left, touch.clientY - rect.top];
}

function handleTouchMove(e) {
    e.preventDefault();
    if (!isDrawing) return;
    
    const touch = e.touches[0];
    const rect = canvas.getBoundingClientRect();
    const x = touch.clientX - rect.left;
    const y = touch.clientY - rect.top;
    
    ctx.beginPath();
    ctx.moveTo(lastX, lastY);
    ctx.lineTo(x, y);
    ctx.stroke();
    [lastX, lastY] = [x, y];
}

function setColor(color) {
    brushColor = color;
    ctx.strokeStyle = color;
    
    // Mettre à jour la classe active
    document.querySelectorAll('.color-btn').forEach(btn => {
        btn.classList.toggle('active', btn.style.background === color);
    });
}

function setSize(size) {
    brushSize = size;
    ctx.lineWidth = size;
}

function clearSignature() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.strokeStyle = brushColor;
    ctx.lineWidth = brushSize;
}

function sendOTP() {
    const formData = new FormData();
    formData.append('action', 'send_otp');
    
    fetch('signatures.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken }
    })
    .then(response => {
        refreshCsrfToken(response);
        return response.json();
    })
    .then(data => {
        if (data.success) {
            showNotification('Code OTP envoyé !', 'success');
        }
    });
}

function saveSignatureDocument() {
    const signatureData = canvas.toDataURL('image/png');
    
    // Vérifier si le canvas est vide
    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const isEmpty = !imageData.data.some((channel, index) => {
        return index % 4 === 3 && channel > 0;
    });
    
    if (isEmpty) {
        showNotification('Veuillez signer avant d\'enregistrer.', 'error');
        return;
    }
    
    // Vérifications spécifiques au niveau
    if (currentLevel === 'advanced') {
        const otp = document.getElementById('otp_code').value;
        if (!otp || otp.length !== 6) {
            showNotification('Veuillez entrer le code OTP.', 'error');
            return;
        }
    }
    
    if (currentLevel === 'qualified') {
        const idNumber = document.getElementById('id_number').value;
        const idVerified = document.getElementById('id_verification').checked;
        
        if (!idNumber) {
            showNotification('Veuillez entrer votre numéro d\'identité.', 'error');
            return;
        }
        if (!idVerified) {
            showNotification('Veuillez accepter la vérification d\'identité.', 'error');
            return;
        }
    }
    
    const formData = new FormData();
    formData.append('action', 'save_signature');
    formData.append('signature_level', currentLevel);
    formData.append('signature_data', signatureData);
    formData.append('document_name', document.getElementById('document_name').value || '');
    
    if (currentLevel === 'advanced') {
        formData.append('otp', document.getElementById('otp_code').value);
    }
    
    if (currentLevel === 'qualified') {
        formData.append('id_number', document.getElementById('id_number').value);
        formData.append('id_verification', '1');
    }
    
    fetch('signatures.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken }
    })
    .then(response => {
        refreshCsrfToken(response);
        return response.json();
    })
    .then(data => {
        if (data.success) {
            showNotification(data.message || 'Signature enregistrée !', 'success');
            setTimeout(() => {
                loadPage('signatures.php', 'Signatures');
            }, 1500);
        } else {
            showNotification(data.message || 'Erreur lors de l\'enregistrement.', 'error');
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        showNotification('Erreur lors de l\'enregistrement.', 'error');
    });
}

function downloadCertificate(id, token) {
    const params = new URLSearchParams();
    if (token) {
        params.set('token', token);
    } else if (id) {
        params.set('id', id);
    }
    const query = params.toString();
    window.open(`certificate.php${query ? `?${query}` : ''}`, '_blank');
}

function verifySignature(id, token) {
    const params = new URLSearchParams();
    if (id) params.set('id', id);
    if (token) params.set('token', token);
    const query = params.toString();
    window.open(`verify_signature.php${query ? `?${query}` : ''}`, '_blank');
}

console.log('%c🚀 Gestion_Colis - iSignature SPA', 'color: #00B4D8; font-size: 16px; font-weight: bold;');
</script>

<style>
.signature-levels {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.level-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 1.5rem;
    transition: all 0.3s ease;
}

.level-card:hover {
    border-color: #00B4D8;
    transform: translateY(-3px);
}

.level-card.active {
    border-color: #00B4D8;
    box-shadow: 0 8px 30px rgba(0, 180, 216, 0.2);
}

.level-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.level-icon {
    width: 50px;
    height: 50px;
    background: rgba(0, 180, 216, 0.1);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: #00B4D8;
}

.level-info h3 {
    margin: 0;
    font-size: 1rem;
    color: var(--text-primary);
}

.level-badge {
    font-size: 0.7rem;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    text-transform: uppercase;
}

.level-features ul {
    list-style: none;
    padding: 0;
    margin: 0 0 1.5rem 0;
}

.level-features li {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0;
    color: rgba(255, 255, 255, 0.7);
    font-size: 0.9rem;
}

.level-features li i {
    color: #22C55E;
}

.signature-container {
    border: 2px dashed rgba(0, 240, 255, 0.3);
    border-radius: 12px;
    background: rgba(10, 14, 23, 0.5);
    padding: 1rem;
    display: flex;
    justify-content: center;
    margin-bottom: 1rem;
}

#signatureCanvas {
    background: #fff;
    border-radius: 8px;
    cursor: crosshair;
    max-width: 100%;
}

.signature-tools {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
    padding: 0.5rem 1rem;
    background: rgba(0, 0, 0, 0.03);
    border-radius: 8px;
}

.color-picker {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.color-picker label {
    color: var(--text-secondary);
    margin-right: 0.5rem;
}

.color-btn {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    border: 2px solid transparent;
    cursor: pointer;
    transition: all 0.2s;
}

.color-btn:hover {
    transform: scale(1.1);
}

.color-btn.active {
    border-color: #fff;
    box-shadow: 0 0 10px rgba(255, 255, 255, 0.5);
}

.size-slider {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.size-slider label {
    color: var(--text-secondary);
}

.legal-info {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
}

.legal-item {
    padding: 1rem;
    background: rgba(0, 0, 0, 0.02);
    border-radius: 8px;
    border: 1px solid var(--border-color);
}

.legal-item h4 {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
}

.legal-item p {
    color: var(--text-secondary);
    font-size: 0.9rem;
    margin: 0;
}

.signature-preview {
    text-align: center;
}

.signature-image {
    max-width: 300px;
    border: 1px solid rgba(0, 180, 216, 0.3);
    border-radius: 8px;
    padding: 0.5rem;
    background: #fff;
}
</style>

</div> <!-- Fin #page-content -->
