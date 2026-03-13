<?php
require_once __DIR__ . '/utils/session.php';
SessionManager::start();
require_once 'config/database.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="access-denied">Accès refusé. Veuillez vous connecter.</div>';
    exit;
}

$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$message = '';
$messageType = '';
$userEmailVerified = false;

try {
    $stmt = $db->prepare("SELECT email_verifie FROM utilisateurs WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $userEmailVerified = !empty(($stmt->fetch() ?? [])['email_verifie']);
} catch (Exception $e) {
    $userEmailVerified = false;
}

// Traitement AJAX de l'envoi par email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['envoyer_email'])) {
    $ajaxResponse = ['success' => false, 'message' => ''];
    
    try {
        // Charger la configuration email
        $emailConfig = [];
        $configFile = __DIR__ . '/config/email_config.php';
        if (file_exists($configFile)) {
            $emailConfig = require $configFile;
        }
        
        // Récupérer les informations de l'utilisateur et du Postal ID
        $stmt = $db->prepare("SELECT u.email, u.prenom, u.nom, p.identifiant_postal, p.type_piece, p.numero_piece, p.date_expiration 
                              FROM postal_id p 
                              JOIN utilisateurs u ON p.utilisateur_id = u.id 
                              WHERE p.utilisateur_id = ? AND p.actif = 1 
                              ORDER BY p.date_creation DESC LIMIT 1");
        $stmt->execute([$user_id]);
        $data = $stmt->fetch();
        
        if ($data) {
            // Charger le service d'emailing
            require_once __DIR__ . '/utils/email_service.php';
            
            // Configurer le service d'emailing
            $emailService = new EmailService([
                'method' => $emailConfig['method'] ?? 'mail',
                'smtp_host' => $emailConfig['smtp']['host'] ?? '',
                'smtp_port' => $emailConfig['smtp']['port'] ?? 587,
                'smtp_username' => $emailConfig['smtp']['username'] ?? '',
                'smtp_password' => $emailConfig['smtp']['password'] ?? '',
                'smtp_encryption' => $emailConfig['smtp']['encryption'] ?? 'tls',
                'from_email' => $emailConfig['from']['email'] ?? 'noreply@gestioncolis.com',
                'from_name' => $emailConfig['from']['name'] ?? 'Gestion_Colis',
                'reply_to' => $emailConfig['reply_to']['email'] ?? 'contact@gestioncolis.com',
                'debug' => $emailConfig['debug'] ?? false
            ]);
            
            // Envoyer l'email avec le template Postal ID
            $userName = $data['prenom'] . ' ' . $data['nom'];
            $idType = ucfirst($data['type_piece']);
            
            $result = $emailService->sendPostalID(
                $data['email'],
                $userName,
                $data['identifiant_postal'],
                $idType,
                $data['numero_piece'],
                $data['date_expiration']
            );
            
            if ($result) {
                $ajaxResponse['success'] = true;
                $ajaxResponse['message'] = 'Postal ID envoyé avec succès à votre adresse email !';
                
                // Logger l'envoi
                error_log("[Email] Postal ID envoyé à {$data['email']} pour {$userName}");
            } else {
                $error = $emailService->getLastError();
                $ajaxResponse['message'] = 'Erreur lors de l\'envoi de l\'email. Veuillez réessayer.' . ($error ? " Détails: $error" : '');
                
                // Logger l'erreur
                error_log("[Email] Erreur d'envoi: $error");
            }
        } else {
            $ajaxResponse['message'] = 'Aucun Postal ID actif trouvé.';
        }
    } catch (Exception $e) {
        $ajaxResponse['message'] = user_error_message($e, 'mon_postal_id.email', "Erreur lors de l'envoi de l'email.");
    }
    
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode($ajaxResponse);
        exit;
    }
    
    $message = $ajaxResponse['message'];
    $messageType = $ajaxResponse['success'] ? 'success' : 'error';
}

// Traitement AJAX de la création de Postal ID
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['creer_postal_id'])) {
    $type_piece = $_POST['type_piece'] ?? '';
    $numero_piece = trim($_POST['numero_piece'] ?? '');
    $date_expiration = $_POST['date_expiration'] ?? '';
    
    $errors = [];
    if (empty($type_piece)) $errors[] = "Le type de pièce est requis";
    if (empty($numero_piece)) $errors[] = "Le numéro de pièce est requis";
    
    $ajaxResponse = ['success' => false, 'message' => ''];
    
    if (empty($errors)) {
        if (!$userEmailVerified) {
            $ajaxResponse['message'] = "Veuillez vérifier votre email avant de créer un Postal ID.";
        } else {
        try {
            // Générer un code Postal ID unique
            $identifiant_postal = 'PID' . strtoupper(bin2hex(random_bytes(5)));
            
            // Vérifier si l'utilisateur a déjà un Postal ID actif
            $stmt = $db->prepare("SELECT id FROM postal_id WHERE utilisateur_id = ? AND actif = 1");
            $stmt->execute([$user_id]);
            if ($stmt->fetch()) {
                $ajaxResponse['message'] = "Vous avez déjà un Postal ID actif.";
            } else {
                // Générer les données QR code
                $qr_code_data = json_encode([
                    'id' => $identifiant_postal,
                    'user_id' => $user_id,
                    'created' => date('Y-m-d H:i:s')
                ]);
                
                // Calculer la date d'expiration (1 an par défaut)
                $exp_date = $date_expiration ? $date_expiration : date('Y-m-d', strtotime('+1 year'));
                
                $stmt = $db->prepare("
                    INSERT INTO postal_id (utilisateur_id, identifiant_postal, niveau_securite, date_expiration, qr_code_data, type_piece, numero_piece, actif, date_creation) 
                    VALUES (?, ?, 'basic', ?, ?, ?, ?, 1, NOW())
                ");
                
                if ($stmt->execute([$user_id, $identifiant_postal, $exp_date, $qr_code_data, $type_piece, $numero_piece])) {
                    $ajaxResponse['success'] = true;
                    $ajaxResponse['message'] = "Postal ID créé avec succès ! Code: " . $identifiant_postal;
                } else {
                    $ajaxResponse['message'] = "Erreur lors de la création du Postal ID.";
                }
            }
        } catch (Exception $e) {
            $ajaxResponse['message'] = user_error_message($e, 'mon_postal_id.create', "Erreur lors de la création du Postal ID.");
        }
        }
    } else {
        $ajaxResponse['message'] = implode("<br>", $errors);
    }
    
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode($ajaxResponse);
        exit;
    }
    
    $message = $ajaxResponse['message'];
    $messageType = $ajaxResponse['success'] ? 'success' : 'error';
}

// Récupérer le Postal ID de l'utilisateur
try {
    $stmt = $db->prepare("SELECT * FROM postal_id WHERE utilisateur_id = ? ORDER BY date_creation DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $postal_id = $stmt->fetch();
    
    // Récupérer l'historique
    $stmt = $db->prepare("SELECT * FROM postal_id WHERE utilisateur_id = ? ORDER BY date_creation DESC");
    $stmt->execute([$user_id]);
    $historique = $stmt->fetchAll();
} catch (Exception $e) {
    $postal_id = null;
    $historique = [];
    $message = user_error_message($e, 'mon_postal_id.fetch', "Erreur lors de la récupération des données.");
    $messageType = 'error';
}
?>

<!-- Contenu pour le système SPA -->
<div id="page-content">

<div class="page-container">
    <div class="page-header">
        <h1><i class="fas fa-id-card" style="color: #00B4D8;"></i> Mon Postal ID</h1>
        <p class="text-muted">Gérez votre identité postale pour recevoir des colis</p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>" style="margin-bottom: 1.5rem;">
            <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
            <div><?php echo htmlspecialchars($message); ?></div>
        </div>
    <?php endif; ?>

    <?php if ($postal_id && $postal_id['actif']): ?>
        <!-- Postal ID Actif -->
        <div class="card" style="margin-bottom: 1.5rem; border-color: #00B4D8;">
            <div class="card-header" style="background: linear-gradient(135deg, rgba(0, 180, 216, 0.1), rgba(0, 168, 255, 0.1));">
                <h3><i class="fas fa-check-circle text-success"></i> Postal ID Actif</h3>
            </div>
            <div class="card-body">
                <div class="postal-id-card">
                    <div class="postal-id-header">
                        <div class="postal-logo">
                            <i class="fas fa-shipping-fast"></i>
                        </div>
                        <div class="postal-info">
                            <h2>Gestion_Colis</h2>
                            <span>Postal ID</span>
                        </div>
                    </div>
                    <div class="postal-id-code">
                        <span class="code-label">Votre Code</span>
                        <span class="code-value"><?php echo htmlspecialchars($postal_id['identifiant_postal']); ?></span>
                    </div>
                    <div class="postal-id-details">
                        <div class="detail-row">
                            <span class="label">Type de pièce</span>
                            <span class="value"><?php echo htmlspecialchars(ucfirst($postal_id['type_piece'])); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Numéro</span>
                            <span class="value"><?php echo htmlspecialchars($postal_id['numero_piece']); ?></span>
                        </div>
                        <?php if ($postal_id['date_expiration']): ?>
                        <div class="detail-row">
                            <span class="label">Expire le</span>
                            <span class="value"><?php echo date('d/m/Y', strtotime($postal_id['date_expiration'])); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="detail-row">
                            <span class="label">Créé le</span>
                            <span class="value"><?php echo date('d/m/Y', strtotime($postal_id['date_creation'])); ?></span>
                        </div>
                    </div>
                    <div class="postal-id-status">
                        <span class="status-badge active"><i class="fas fa-check"></i> Actif</span>
                    </div>
                </div>
                
                <div style="margin-top: 1.5rem; display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
                    <button class="btn btn-primary" onclick="imprimerPostalID()">
                        <i class="fas fa-print"></i> Imprimer
                    </button>
                    <button class="btn btn-secondary" onclick="partagerPostalID()">
                        <i class="fas fa-share-alt"></i> Partager
                    </button>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Formulaire de création -->
        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-header">
                <h3><i class="fas fa-plus-circle"></i> Créer mon Postal ID</h3>
            </div>
            <div class="card-body">
                <form id="creerPostalIdForm" method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="creer_postal_id" value="1">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="type_piece">
                                <i class="fas fa-id-card"></i> Type de pièce d'identité <span class="required">*</span>
                            </label>
                            <select id="type_piece" name="type_piece" class="form-control" required>
                                <option value="">Sélectionnez...</option>
                                <option value="cin">Carte Nationale d'Identité (CNI)</option>
                                <option value="passeport">Passeport</option>
                                <option value="permis">Permis de conduire</option>
                                <option value="autre">Autre pièce</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="numero_piece">
                                <i class="fas fa-hashtag"></i> Numéro de la pièce <span class="required">*</span>
                            </label>
                            <input type="text" id="numero_piece" name="numero_piece" class="form-control" 
                                   placeholder="Entrez le numéro de votre pièce" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="date_expiration">
                                <i class="fas fa-calendar"></i> Date d'expiration (optionnel)
                            </label>
                            <input type="date" id="date_expiration" name="date_expiration" class="form-control">
                        </div>
                    </div>
                    
                    <div class="alert alert-info" style="margin-top: 1rem;">
                        <i class="fas fa-info-circle"></i>
                        <div>
                            <strong>Qu'est-ce que le Postal ID ?</strong><br>
                            Votre Postal ID est un identifiant unique qui vous permet de recevoir des colis dans les points de retrait partenaires. Présentez ce code lors de vos retraits.
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="margin-top: 1rem;">
                        <i class="fas fa-plus"></i>
                        Créer mon Postal ID
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- Historique -->
    <?php if (!empty($historique)): ?>
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-history"></i> Historique</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Code Postal ID</th>
                            <th>Type</th>
                            <th>Numéro</th>
                            <th>Statut</th>
                            <th>Date de création</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historique as $pid): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($pid['identifiant_postal']); ?></td>
                            <td><?php echo htmlspecialchars(ucfirst($pid['type_piece'])); ?></td>
                            <td><?php echo htmlspecialchars($pid['numero_piece']); ?></td>
                            <td>
                                <span class="badge badge-<?php echo $pid['actif'] ? 'success' : 'secondary'; ?>">
                                    <?php echo $pid['actif'] ? 'Actif' : 'Inactif'; ?>
                                </span>
                            </td>
                            <td><?php echo date('d/m/Y H:i', strtotime($pid['date_creation'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
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
// Soumission du formulaire via AJAX pour le système SPA
document.getElementById('creerPostalIdForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Création...';
    submitBtn.disabled = true;
    
    fetch('mon_postal_id.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': csrfToken
        }
    })
    .then(response => {
        refreshCsrfToken(response);
        return response.json();
    })
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            setTimeout(() => {
                loadPage('mon_postal_id.php', 'Mon Postal ID');
            }, 1500);
        } else {
            showNotification(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        showNotification('Erreur lors de la création du Postal ID.', 'error');
    })
    .finally(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
});

function imprimerPostalID() {
    window.print();
}

// Fonction de partage native utilisant l'API Web Share
function partagerPostalID() {
    // Récupérer les données du Postal ID depuis la page
    const postalCode = document.querySelector('.code-value')?.textContent?.trim();
    const postalType = document.querySelectorAll('.detail-row .value')[0]?.textContent?.trim();
    const postalNumber = document.querySelectorAll('.detail-row .value')[1]?.textContent?.trim();
    
    if (!postalCode) {
        showNotification('Impossible de récupérer les informations du Postal ID.', 'error');
        return;
    }
    
    // Préparer le texte de partage
    const shareText = `🎉 *Mon Postal ID - Gestion_Colis*\n\n` +
                      `📋 *Code Postal ID:* ${postalCode}\n` +
                      `🪪 *Type:* ${postalType}\n` +
                      `🔢 *Numéro:* ${postalNumber}\n\n` +
                      `Je peux maintenant recevoir des colis dans les points de retrait partenaires !`;
    
    // Vérifier si l'API Web Share est disponible
    if (navigator.share) {
        navigator.share({
            title: 'Mon Postal ID - Gestion_Colis',
            text: shareText,
            url: window.location.href
        })
        .then(() => {
            console.log('Partage réussi');
            showNotification('Postal ID partagé avec succès !', 'success');
        })
        .catch((error) => {
            console.log('Partage annulé ou erreur:', error);
            // En cas d'annulation ou d'erreur, proposer le fallback
            proposerOptionsPartage(shareText);
        });
    } else {
        // Fallback pour les navigateurs qui ne supportent pas Web Share API
        proposerOptionsPartage(shareText);
    }
}

// Proposer les options de partage alternatives
function proposerOptionsPartage(shareText) {
    // Créer un menu de partage personnalisé
    const shareMenu = document.createElement('div');
    shareMenu.className = 'share-menu-modal';
    shareMenu.innerHTML = `
        <div class="share-menu-content">
            <div class="share-menu-header">
                <h3><i class="fas fa-share-alt"></i> Partager mon Postal ID</h3>
                <button class="close-share-menu" onclick="fermerMenuPartage()">&times;</button>
            </div>
            <div class="share-menu-options">
                <button class="share-option" onclick="copierPressePapier('${shareText.replace(/'/g, "\\'")}')">
                    <i class="fas fa-copy"></i>
                    <span>Copier le texte</span>
                </button>
                <button class="share-option" onclick="partagerViaWhatsApp('${shareText.replace(/'/g, "\\'")}')">
                    <i class="fab fa-whatsapp"></i>
                    <span>WhatsApp</span>
                </button>
                <button class="share-option" onclick="partagerViaEmail('${shareText.replace(/'/g, "\\'")}')">
                    <i class="fas fa-envelope"></i>
                    <span>Email</span>
                </button>
                <button class="share-option" onclick="partagerViaSMS('${shareText.replace(/'/g, "\\'")}')">
                    <i class="fas fa-sms"></i>
                    <span>SMS</span>
                </button>
            </div>
        </div>
    `;
    
    // Ajouter au DOM
    document.body.appendChild(shareMenu);
    
    // Afficher avec animation
    setTimeout(() => {
        shareMenu.classList.add('active');
    }, 10);
    
    // Fermer si on clique à l'extérieur
    shareMenu.addEventListener('click', function(e) {
        if (e.target === shareMenu) {
            fermerMenuPartage();
        }
    });
}

// Fermer le menu de partage
function fermerMenuPartage() {
    const shareMenu = document.querySelector('.share-menu-modal');
    if (shareMenu) {
        shareMenu.classList.remove('active');
        setTimeout(() => {
            shareMenu.remove();
        }, 300);
    }
}

// Copier dans le presse-papier
function copierPressePapier(text) {
    navigator.clipboard.writeText(text.replace(/\*/g, '')).then(() => {
        showNotification('Informations copiées dans le presse-papier !', 'success');
        fermerMenuPartage();
    }).catch(() => {
        showNotification('Erreur lors de la copie.', 'error');
    });
}

// Partager via WhatsApp
function partagerViaWhatsApp(text) {
    const cleanText = text.replace(/\*/g, '');
    window.open(`https://wa.me/?text=${encodeURIComponent(cleanText)}`, '_blank');
    fermerMenuPartage();
}

// Partager via Email
function partagerViaEmail(text) {
    const subject = encodeURIComponent('Mon Postal ID - Gestion_Colis');
    const body = encodeURIComponent(text.replace(/\*/g, ''));
    window.open(`mailto:?subject=${subject}&body=${body}`, '_blank');
    fermerMenuPartage();
}

// Partager via SMS
function partagerViaSMS(text) {
    const cleanText = text.replace(/\*/g, '');
    window.open(`sms:?body=${encodeURIComponent(cleanText)}`, '_blank');
    fermerMenuPartage();
}

console.log('%c🚀 Gestion_Colis - Mon Postal ID SPA', 'color: #00B4D8; font-size: 16px; font-weight: bold;');
</script>

<style>
.postal-id-card {
    background: linear-gradient(135deg, var(--gray-50), var(--gray-100));
    border: 2px solid #00B4D8;
    border-radius: 16px;
    padding: 2rem;
    max-width: 450px;
    margin: 0 auto;
    position: relative;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
}

.postal-id-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #00B4D8, #00A8FF);
}

.postal-id-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.postal-logo {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #00B4D8, #00A8FF);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1.5rem;
}

.postal-info h2 {
    font-family: 'Orbitron', sans-serif;
    font-size: 1rem;
    margin: 0;
    color: #00B4D8;
}

.postal-info span {
    font-size: 0.8rem;
    color: var(--text-secondary);
}

.postal-id-code {
    text-align: center;
    margin-bottom: 1.5rem;
    padding: 1rem;
    background: rgba(0, 180, 216, 0.1);
    border-radius: 8px;
}

.code-label {
    display: block;
    font-size: 0.75rem;
    color: var(--text-secondary);
    margin-bottom: 0.5rem;
}

.code-value {
    display: block;
    font-family: 'Orbitron', sans-serif;
    font-size: 1.5rem;
    font-weight: 700;
    color: #00B4D8;
    letter-spacing: 3px;
}

.postal-id-details {
    margin-bottom: 1rem;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--border-color);
}

.detail-row .label {
    color: var(--text-secondary);
    font-size: 0.85rem;
}

.detail-row .value {
    font-weight: 600;
    color: var(--text-primary);
}

.postal-id-status {
    text-align: center;
    margin-top: 1rem;
}

.status-badge.active {
    background: rgba(34, 197, 94, 0.2);
    color: #22C55E;
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

@media print {
    body * {
        visibility: hidden;
    }
    .postal-id-card, .postal-id-card * {
        visibility: visible;
    }
    .postal-id-card {
        position: absolute;
        left: 50%;
        top: 50%;
        transform: translate(-50%, -50%);
    }
}

/* Menu de partage modal */
.share-menu-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
}

.share-menu-modal.active {
    opacity: 1;
    visibility: visible;
}

.share-menu-content {
    background: #ffffff;
    border-radius: 16px;
    padding: 0;
    max-width: 400px;
    width: 90%;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    transform: scale(0.9) translateY(20px);
    transition: all 0.3s ease;
}

.share-menu-modal.active .share-menu-content {
    transform: scale(1) translateY(0);
}

.share-menu-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
}

.share-menu-header h3 {
    margin: 0;
    font-size: 1.1rem;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.share-menu-header h3 i {
    color: var(--primary-cyan);
}

.close-share-menu {
    background: none;
    border: none;
    font-size: 1.5rem;
    color: var(--text-secondary);
    cursor: pointer;
    padding: 0.25rem;
    line-height: 1;
    transition: color 0.2s ease;
}

.close-share-menu:hover {
    color: var(--text-primary);
}

.share-menu-options {
    padding: 1rem 1.5rem 1.5rem;
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.75rem;
}

.share-option {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 1rem;
    background: var(--gray-50);
    border: 2px solid var(--border-color);
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.2s ease;
    color: var(--text-primary);
}

.share-option:hover {
    background: var(--primary-cyan);
    border-color: var(--primary-cyan);
    color: #ffffff;
    transform: translateY(-2px);
}

.share-option i {
    font-size: 1.5rem;
}

.share-option span {
    font-size: 0.85rem;
    font-weight: 500;
}
</style>

</div> <!-- Fin #page-content -->
