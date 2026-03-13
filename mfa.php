<?php
/**
 * Module MFA - Authentification à Deux Facteurs
 * Intégration avec Google Authenticator
 */

require_once __DIR__ . '/utils/session.php';
SessionManager::start();
require_once '../config/database.php';

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

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'setup_mfa') {
        // Générer le secret MFA
        $mfa_secret = generateMfaSecret();
        
        // Stocker temporairement le secret
        $_SESSION['pending_mfa_secret'] = $mfa_secret;
        $_SESSION['mfa_setup_step'] = 1;
        
        $message = 'Secret MFA généré. Scannez le QR code ci-dessous.';
        $messageType = 'success';
    }
    
    if ($action === 'verify_setup') {
        $code = $_POST['verification_code'] ?? '';
        $secret = $_SESSION['pending_mfa_secret'] ?? '';
        
        if (empty($code) || empty($secret)) {
            $message = 'Code de vérification requis.';
            $messageType = 'error';
        } elseif (verifyMfaCode($secret, $code)) {
            // Activer MFA pour l'utilisateur
            $stmt = $db->prepare("UPDATE utilisateurs SET mfa_secret = ?, mfa_enabled = 1 WHERE id = ?");
            $stmt->execute([$secret, $user_id]);
            
            // Générer les codes de secours
            $backupCodes = generateBackupCodes();
            $stmt = $db->prepare("UPDATE utilisateurs SET mfa_backup_codes = ? WHERE id = ?");
            $stmt->execute([json_encode($backupCodes), $user_id]);
            
            unset($_SESSION['pending_mfa_secret'], $_SESSION['mfa_setup_step']);
            
            // Créer une notification
            createNotification($user_id, 'security', 'Sécurité renforcée', 
                'L\'authentification à deux facteurs est maintenant activée sur votre compte.');
            
            $message = 'Félicitations ! L\'authentification à deux facteurs est activée.';
            $messageType = 'success';
        } else {
            $message = 'Code incorrect. Veuillez vérifier votre application Google Authenticator.';
            $messageType = 'error';
        }
    }
    
    if ($action === 'disable_mfa') {
        $code = $_POST['verification_code'] ?? '';
        $stmt = $db->prepare("SELECT mfa_secret FROM utilisateurs WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if ($user && verifyMfaCode($user['mfa_secret'], $code)) {
            $stmt = $db->prepare("UPDATE utilisateurs SET mfa_secret = NULL, mfa_enabled = 0, mfa_backup_codes = NULL WHERE id = ?");
            $stmt->execute([$user_id]);
            
            createNotification($user_id, 'security', 'Sécurité réduite', 
                'L\'authentification à deux facteurs a été désactivée.');
            
            $message = 'L\'authentification à deux facteurs a été désactivée.';
            $messageType = 'success';
        } else {
            $message = 'Code incorrect. L\'authentification à deux facteurs n\'a pas pu être désactivée.';
            $messageType = 'error';
        }
    }
    
    if ($action === 'use_backup_code') {
        $backupCode = $_POST['backup_code'] ?? '';
        $stmt = $db->prepare("SELECT mfa_backup_codes FROM utilisateurs WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if ($user && $user['mfa_backup_codes']) {
            $backupCodes = json_decode($user['mfa_backup_codes'], true);
            $index = array_search($backupCode, $backupCodes);
            
            if ($index !== false) {
                // Supprimer le code utilisé
                unset($backupCodes[$index]);
                $stmt = $db->prepare("UPDATE utilisateurs SET mfa_backup_codes = ? WHERE id = ?");
                $stmt->execute([json_encode(array_values($backupCodes)), $user_id]);
                
                // Créer une notification
                createNotification($user_id, 'security', 'Code de secours utilisé', 
                    'Un code de secours a été utilisé pour accéder à votre compte.');
                
                header('Location: ../dashboard.php');
                exit;
            }
        }
        $message = 'Code de secours invalide ou déjà utilisé.';
        $messageType = 'error';
    }
}

// Récupérer le statut MFA de l'utilisateur
$stmt = $db->prepare("SELECT mfa_secret, mfa_enabled, mfa_backup_codes FROM utilisateurs WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$mfaEnabled = $user['mfa_enabled'] ?? false;
$pendingSecret = $_SESSION['pending_mfa_secret'] ?? '';

// Fonctions MFA
function generateMfaSecret() {
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < 16; $i++) {
        $secret .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $secret;
}

function verifyMfaCode($secret, $code) {
    if (empty($secret) || empty($code)) return false;
    
    // Vérification avec l'algorithme TOTP
    $time = floor(time() / 30);
    
    for ($i = -1; $i <= 1; $i++) {
        $t = $time + $i;
        $base32 = base32_decode($secret);
        $pack = pack('J', $t);
        $hash = hash_hmac('sha1', $base32 ? $pack : str_repeat("\0", 8), $base32 ?: 'JSEC', true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $truncatedHash = ord(substr($hash, $offset, 1)) & 0x7F;
        $otp = str_pad($truncatedHash % 1000000, 6, '0', STR_PAD_LEFT);
        
        if ($otp === $code) {
            return true;
        }
    }
    
    return false;
}

function base32_decode($input) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $input = strtoupper($input);
    $binary = '';
    
    for ($i = 0; $i < strlen($input); $i++) {
        $val = strpos($alphabet, $input[$i]);
        if ($val === false) continue;
        $binary .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
    }
    
    $bytes = str_split($binary, 8);
    $decoded = '';
    foreach ($bytes as $byte) {
        $decoded .= chr(bindec($byte));
    }
    
    return $decoded;
}

function generateBackupCodes() {
    $codes = [];
    for ($i = 0; $i < 10; $i++) {
        $code = '';
        for ($j = 0; $j < 8; $j++) {
            $code .= rand(0, 9);
            if ($j === 3) $code .= '-';
        }
        $codes[] = $code;
    }
    return $codes;
}

function createNotification($userId, $type, $title, $message) {
    require_once '../config/database.php';
    $database = new Database();
    $db = $database->getConnection();
    
    $stmt = $db->prepare("
        INSERT INTO notifications (user_id, type, title, message) 
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $type, $title, $message]);
}

// Générer le QR code si en cours de configuration
$qrCodeUrl = '';
if ($pendingSecret) {
    $stmt = $db->prepare("SELECT email, prenom, nom FROM utilisateurs WHERE id = ?");
    $stmt->execute([$user_id]);
    $userInfo = $stmt->fetch();
    
    $issuer = 'Gestion_Colis';
    $account = $userInfo['email'] ?? 'user@example.com';
    $qrCodeUrl = generateQRCodeUrl($account, $pendingSecret, $issuer);
}

function generateQRCodeUrl($account, $secret, $issuer) {
    $otpauth = sprintf(
        'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
        rawurlencode($issuer),
        rawurlencode($account),
        $secret,
        rawurlencode($issuer)
    );
    return $otpauth;
}
?>

<div id="page-content">

<div class="page-container">
    <div class="page-header">
        <div class="header-left">
            <button class="btn btn-back" onclick="loadPage('mon_compte.php', 'Mon Compte')">
                <i class="fas fa-arrow-left"></i>
            </button>
            <h1>
                <i class="fas fa-shield-alt" style="color: #00B4D8;"></i> 
                Authentification à Deux Facteurs
            </h1>
        </div>
        <span class="badge badge-<?= $mfaEnabled ? 'success' : 'warning' ?> badge-lg">
            <?= $mfaEnabled ? 'Activé' : 'Désactivé' ?>
        </span>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?>">
            <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if (!$mfaEnabled && !$pendingSecret): ?>
        <!-- Configuration MFA -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-cog"></i> Activer l'authentification à deux facteurs</h3>
            </div>
            <div class="card-body">
                <div class="info-box info-box-primary">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <strong>Qu'est-ce que l'authentification à deux facteurs ?</strong>
                        <p>L'authentification à deux facteurs (2FA) ajoute une couche de sécurité supplémentaire à votre compte. 
                        Chaque fois que vous vous connecterez, vous devrez saisir un code temporaire généré par votre téléphone 
                        en plus de votre mot de passe.</p>
                    </div>
                </div>

                <div class="benefits-grid">
                    <div class="benefit-item">
                        <i class="fas fa-lock"></i>
                        <h4>Protection renforcée</h4>
                        <p>Même si quelqu'un vole votre mot de passe, il ne pourra pas accéder à votre compte sans votre téléphone.</p>
                    </div>
                    <div class="benefit-item">
                        <i class="fas fa-mobile-alt"></i>
                        <h4>Facile à utiliser</h4>
                        <p>Utilisez Google Authenticator ou toute application compatible TOTP sur votre smartphone.</p>
                    </div>
                    <div class="benefit-item">
                        <i class="fas fa-clock"></i>
                        <h4>Codes temporaires</h4>
                        <p>Les codes changent toutes les 30 secondes et ne peuvent pas être réutilisés.</p>
                    </div>
                </div>

                <form method="POST" class="mt-4">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="setup_mfa">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-qrcode"></i>
                        Configurer l'authentification à deux facteurs
                    </button>
                </form>
            </div>
        </div>

    <?php elseif ($pendingSecret && !$mfaEnabled): ?>
        <!-- Étape 1: QR Code -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-mobile-alt"></i> Scannez le QR code</h3>
            </div>
            <div class="card-body">
                <p class="text-muted mb-3">
                    Ouvrez votre application Google Authenticator (ou toute application TOTP compatible) 
                    et scannez ce QR code pour ajouter votre compte.
                </p>

                <div class="qr-container">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= urlencode($qrCodeUrl) ?>" 
                         alt="QR Code MFA" 
                         class="qr-image">
                </div>

                <div class="manual-entry mt-4">
                    <p><strong>Ou entrez ce code manuellement :</strong></p>
                    <code class="secret-code"><?= chunk_split($pendingSecret, 4, ' ') ?></code>
                </div>

                <div class="alert alert-warning mt-3">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong>Conservez vos codes de secours !</strong>
                        <p>Après la configuration, vous recevoira 10 codes de secours à utiliser si vous perdez accès à votre application d'authentification.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Étape 2: Vérification -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-check-circle"></i> Vérification</h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="verify_setup">
                    
                    <div class="form-group">
                        <label for="verification_code">
                            <i class="fas fa-key"></i> Code de vérification <span class="required">*</span>
                        </label>
                        <input type="text" 
                               id="verification_code" 
                               name="verification_code" 
                               class="form-control" 
                               placeholder="Entrez le code à 6 chiffres"
                               maxlength="6"
                               pattern="[0-9]{6}"
                               required
                               autocomplete="off">
                        <small class="form-hint">Entrez le code généré par votre application d'authentification</small>
                    </div>

                    <div class="form-actions">
                        <a href="mfa.php?cancel=1" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Annuler
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-check"></i> Vérifier et activer
                        </button>
                    </div>
                </form>
            </div>
        </div>

    <?php elseif ($mfaEnabled): ?>
        <!-- MFA Activé - Options -->
        <div class="card">
            <div class="card-header" style="background: linear-gradient(135deg, rgba(34, 197, 94, 0.2), rgba(16, 185, 129, 0.1));">
                <h3><i class="fas fa-check-circle text-success"></i> Authentification à deux facteurs activée</h3>
            </div>
            <div class="card-body">
                <div class="status-info">
                    <div class="status-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div class="status-text">
                        <h4>Votre compte est protégé</h4>
                        <p>L'authentification à deux facteurs est activée. Vous devez saisir un code de votre application d'authentification lors de chaque connexion.</p>
                    </div>
                </div>

                <hr style="margin: 2rem 0; border-color: rgba(255,255,255,0.1);">

                <h4><i class="fas fa-key"></i> Codes de secours</h4>
                <p class="text-muted">Utilisez ces codes si vous n'avez pas accès à votre application d'authentification.</p>

                <?php 
                $backupCodes = json_decode($user['mfa_backup_codes'] ?? '[]', true);
                if (!empty($backupCodes)):
                ?>
                <div class="backup-codes-grid">
                    <?php foreach ($backupCodes as $code): ?>
                        <code class="backup-code"><?= htmlspecialchars($code) ?></code>
                    <?php endforeach; ?>
                </div>
                <p class="text-muted mt-2"><small>Codes restants : <?= count($backupCodes) ?></small></p>
                <?php else: ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    Aucun code de secours disponible. Désactivez et réactivez MFA pour en générer de nouveaux.
                </div>
                <?php endif; ?>

                <hr style="margin: 2rem 0; border-color: rgba(255,255,255,0.1);">

                <h4><i class="fas fa-cog"></i> Gestion</h4>
                <form method="POST" class="mt-3">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="disable_mfa">
                    
                    <div class="form-group">
                        <label for="verification_code_disable">
                            <i class="fas fa-key"></i> Code de vérification pour désactiver <span class="required">*</span>
                        </label>
                        <input type="text" 
                               id="verification_code_disable" 
                               name="verification_code" 
                               class="form-control" 
                               placeholder="Entrez le code à 6 chiffres"
                               maxlength="6"
                               pattern="[0-9]{6}"
                               required
                               autocomplete="off">
                    </div>

                    <button type="submit" class="btn btn-danger" onclick="return confirm('Êtes-vous sûr de vouloir désactiver l\'authentification à deux facteurs ? Votre compte sera moins sécurisé.');">
                        <i class="fas fa-shield-alt"></i>
                        Désactiver l'authentification à deux facteurs
                    </button>
                </form>
            </div>
        </div>

        <!-- Codes de secours pour connexion -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-life-ring"></i> Problème de connexion ?</h3>
            </div>
            <div class="card-body">
                <p>Si vous n'avez pas accès à votre application d'authentification, vous pouvez utiliser un code de secours.</p>
                
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="use_backup_code">
                    
                    <div class="form-group">
                        <label for="backup_code">
                            <i class="fas fa-key"></i> Code de secours <span class="required">*</span>
                        </label>
                        <input type="text" 
                               id="backup_code" 
                               name="backup_code" 
                               class="form-control" 
                               placeholder="XXXX-XXXX"
                               pattern="[0-9]{4}-[0-9]{4}"
                               required
                               autocomplete="off">
                    </div>

                    <button type="submit" class="btn btn-secondary">
                        <i class="fas fa-sign-in-alt"></i>
                        Utiliser un code de secours
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- Applications recommandées -->
    <div class="card mt-4">
        <div class="card-header">
            <h3><i class="fas fa-download"></i> Applications recommandées</h3>
        </div>
        <div class="card-body">
            <div class="apps-grid">
                <div class="app-item">
                    <i class="fab fa-google"></i>
                    <div>
                        <strong>Google Authenticator</strong>
                        <p>iOS / Android</p>
                    </div>
                </div>
                <div class="app-item">
                    <i class="fab fa-microsoft"></i>
                    <div>
                        <strong>Microsoft Authenticator</strong>
                        <p>iOS / Android / Windows</p>
                    </div>
                </div>
                <div class="app-item">
                    <i class="fas fa-shield-alt"></i>
                    <div>
                        <strong>Authy</strong>
                        <p>iOS / Android / Desktop</p>
                    </div>
                </div>
                <div class="app-item">
                    <i name="fa-free-code-camp"></i>
                    <div>
                        <strong>FreeOTP</strong>
                        <p>iOS / Android</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.benefits-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-top: 2rem;
}

.benefit-item {
    background: rgba(0, 180, 216, 0.05);
    border: 1px solid rgba(0, 180, 216, 0.1);
    border-radius: 12px;
    padding: 1.5rem;
    text-align: center;
}

.benefit-item i {
    font-size: 2rem;
    color: #00B4D8;
    margin-bottom: 1rem;
}

.benefit-item h4 {
    color: var(--text-primary);
    margin-bottom: 0.5rem;
}

.benefit-item p {
    color: var(--text-secondary);
    font-size: 0.9rem;
    margin: 0;
}

.qr-container {
    display: flex;
    justify-content: center;
    padding: 2rem;
    background: #fff;
    border-radius: 16px;
    max-width: 250px;
    margin: 0 auto;
}

.qr-image {
    width: 200px;
    height: 200px;
}

.manual-entry {
    text-align: center;
    padding: 1rem;
    background: rgba(0, 180, 216, 0.05);
    border-radius: 8px;
}

.secret-code {
    display: inline-block;
    padding: 1rem;
    background: rgba(0, 0, 0, 0.03);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    font-size: 1.2rem;
    letter-spacing: 3px;
    color: #00B4D8;
}

.backup-codes-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.5rem;
}

.backup-code {
    padding: 0.5rem;
    background: rgba(0, 180, 216, 0.1);
    border: 1px solid rgba(0, 180, 216, 0.2);
    border-radius: 4px;
    text-align: center;
    font-size: 0.9rem;
}

.status-info {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    padding: 1.5rem;
    background: rgba(34, 197, 94, 0.1);
    border: 1px solid rgba(34, 197, 94, 0.2);
    border-radius: 12px;
}

.status-icon {
    width: 60px;
    height: 60px;
    background: rgba(34, 197, 94, 0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: #22C55E;
}

.status-text h4 {
    color: #22C55E;
    margin-bottom: 0.5rem;
}

.status-text p {
    color: var(--text-secondary);
    margin: 0;
}

.apps-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.app-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: rgba(0, 0, 0, 0.02);
    border: 1px solid var(--border-color);
    border-radius: 8px;
}

.app-item i {
    font-size: 2rem;
    color: #00B4D8;
}

.app-item strong {
    display: block;
    color: var(--text-primary);
}

.app-item p {
    color: var(--text-secondary);
    font-size: 0.85rem;
    margin: 0;
}
</style>

</div> <!-- Fin #page-content -->
