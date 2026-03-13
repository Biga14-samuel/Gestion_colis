<?php
require_once __DIR__ . '/utils/session.php';
SessionManager::start();
require_once 'config/database.php';
require_once 'utils/mfa_service.php';

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'] ?? 0;

// Vérifier la connexion
if (!$user_id) {
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="access-denied">Accès refusé. Veuillez vous connecter.</div>';
    exit;
}

$mfaService = new MFAService();
$message = '';
$messageType = '';

$userStmt = $db->prepare("SELECT mfa_active, mfa_verified_at FROM utilisateurs WHERE id = ?");
$userStmt->execute([$user_id]);
$user = $userStmt->fetch();

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'setup_mfa') {
        // Générer la configuration MFA
        $setup = $mfaService->generateSecret($user_id);
        $message = 'Configuration MFA générée. Scannez le QR code avec votre application.';
        $messageType = 'info';
        $mfaSetup = $setup;
    }
    
    elseif ($action === 'verify_mfa') {
        $code = $_POST['code'] ?? '';
        if (empty($code)) {
            $message = 'Veuillez entrer le code de vérification.';
            $messageType = 'error';
        } else {
            $result = $mfaService->enableMFA($user_id, $code);
            if ($result) {
                $message = 'L\'authentification à deux facteurs est maintenant activée !';
                $messageType = 'success';
                $user['mfa_active'] = true;
            } else {
                $message = 'Code de vérification invalide. Veuillez réessayer.';
                $messageType = 'error';
                $mfaSetup = $mfaService->generateSecret($user_id);
            }
        }
    }
    
    elseif ($action === 'disable_mfa') {
        $password = $_POST['password'] ?? '';
        $result = $mfaService->disableMFA($user_id, $password);
        if ($result['success']) {
            $message = $result['message'];
            $messageType = 'success';
            $user['mfa_active'] = false;
        } else {
            $message = $result['message'];
            $messageType = 'error';
        }
    }
}

// Récupérer les codes de backup restants
$backupCodesCount = $mfaService->getRemainingBackupCodesCount($user_id);
?>

<!-- Contenu pour le système SPA -->
<div id="page-content">

<div class="page-container">
    <div class="page-header">
        <h1><i class="fas fa-shield-alt" style="color: #00B4D8;"></i> Sécurité du Compte</h1>
        <p>Gérez l'authentification à deux facteurs (MFA)</p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>" style="margin-bottom: 1.5rem;">
            <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
            <div><?php echo htmlspecialchars($message); ?></div>
        </div>
    <?php endif; ?>

    <!-- Statut MFA Actuel -->
    <div class="card" style="margin-bottom: 1.5rem;">
        <div class="card-header">
            <h3><i class="fas fa-info-circle"></i> État de la Sécurité</h3>
        </div>
        <div class="card-body">
            <div class="security-status">
                <div class="status-item">
                    <div class="status-icon <?php echo $user['mfa_active'] ? 'status-active' : 'status-inactive'; ?>">
                        <i class="fas fa-<?php echo $user['mfa_active'] ? 'lock' : 'unlock'; ?>"></i>
                    </div>
                    <div class="status-info">
                        <h4>Authentification à Deux Facteurs</h4>
                        <p><?php echo $user['mfa_active'] ? '✅ Activée' : '❌ Non activée'; ?></p>
                        <?php if ($user['mfa_verified_at']): ?>
                            <small class="text-muted">Activé le <?php echo date('d/m/Y à H:i', strtotime($user['mfa_verified_at'])); ?></small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$user['mfa_active'] && !isset($mfaSetup)): ?>
        <!-- Formulaire d'activation MFA -->
        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-header">
                <h3><i class="fas fa-qrcode"></i> Activer l'Authentification à Deux Facteurs</h3>
            </div>
            <div class="card-body">
                <div class="mfa-setup-info">
                    <div class="info-icon">
                        <i class="fas fa-mobile-alt fa-3x" style="color: #00B4D8;"></i>
                    </div>
                    <div class="info-text">
                        <h4>Pourquoi activer MFA ?</h4>
                        <ul>
                            <li>Protection renforcée contre les accès non autorisés</li>
                            <li>Code temporaire envoyé sur votre téléphone</li>
                            <li> Sécurité accrue pour vos transactions</li>
                        </ul>
                    </div>
                </div>

                <form method="POST" style="margin-top: 1.5rem;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="setup_mfa">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-cog"></i>
                        Configurer MFA
                    </button>
                </form>
            </div>
        </div>
    <?php elseif (isset($mfaSetup)): ?>
        <!-- Étape 1: QR Code -->
        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-header">
                <h3><i class="fas fa-qrcode"></i> Configuration de l'Application d'Authentification</h3>
            </div>
            <div class="card-body">
                <div class="qr-setup">
                    <div class="qr-image">
                        <img src="<?php echo $mfaSetup['qr_code_url']; ?>" alt="QR Code MFA" style="max-width: 200px; border-radius: 10px;">
                    </div>
                    
                    <div class="setup-instructions">
                        <h4>Instructions de configuration :</h4>
                        <ol>
                            <li>Installez une application d'authentification (Google Authenticator, Authy, etc.)</li>
                            <li>Scannez le QR code ci-dessus</li>
                            <li>Entrez le code à 6 chiffres généré par l'application</li>
                        </ol>
                        
                        <div class="manual-entry">
                            <h5>Entrée manuelle :</h5>
                            <code style="display: block; padding: 10px; background: #f5f5f5; border-radius: 5px; word-break: break-all;">
                                <?php echo htmlspecialchars($mfaSetup['setup_string']); ?>
                            </code>
                        </div>
                    </div>
                </div>

                <!-- Codes de Backup -->
                <div class="backup-codes-section" style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #e5e5e5;">
                    <h4><i class="fas fa-key"></i> Codes de Secours</h4>
                    <p class="text-muted">Conservez ces codes dans un endroit sûr. Vous pourrez les utiliser si vous perdez l'accès à votre application d'authentification.</p>
                    
                    <div class="backup-codes-grid">
                        <?php foreach ($mfaSetup['backup_codes'] as $code): ?>
                            <code class="backup-code"><?php echo htmlspecialchars($code['code']); ?></code>
                        <?php endforeach; ?>
                    </div>
                    
                    <p class="warning-text" style="color: #f59e0b; margin-top: 1rem;">
                        <i class="fas fa-exclamation-triangle"></i>
                        Ces codes ne seront plus visibles après activation !
                    </p>
                </div>

                <!-- Formulaire de vérification -->
                <div class="verify-form" style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #e5e5e5;">
                    <h4><i class="fas fa-check-circle"></i> Vérification</h4>
                    <p>Entrez le code de votre application pour activer MFA :</p>
                    
                    <form method="POST">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="verify_mfa">
                        
                        <div class="form-group">
                            <label for="mfa_code">Code de vérification</label>
                            <input type="text" id="mfa_code" name="code" 
                                   class="form-control" 
                                   placeholder="000000" 
                                   pattern="[0-9]{6}"
                                   maxlength="6"
                                   style="max-width: 200px; text-align: center; font-size: 1.5rem; letter-spacing: 5px;"
                                   required>
                        </div>
                        
                        <button type="submit" class="btn btn-primary" style="margin-top: 1rem;">
                            <i class="fas fa-check"></i>
                            Activer MFA
                        </button>
                    </form>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- MFA déjà activé - Options de gestion -->
        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-header">
                <h3><i class="fas fa-cog"></i> Gestion de la Sécurité</h3>
            </div>
            <div class="card-body">
                <div class="stats-grid" style="margin-bottom: 1.5rem;">
                    <div class="stat-card">
                        <div class="stat-icon bg-success">
                            <i class="fas fa-key"></i>
                        </div>
                        <div class="stat-info">
                            <span class="stat-value"><?php echo $backupCodesCount; ?></span>
                            <span class="stat-label">Codes de secours restants</span>
                        </div>
                    </div>
                </div>

                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <strong>Vos codes de secours</strong><br>
                        Vous avez <?php echo $backupCodesCount; ?> codes de secours disponibles. 
                        Si vous les utilisez tous, vous devrez désactiver et réactiver MFA pour en obtenir de nouveaux.
                    </div>
                </div>

                <hr style="margin: 1.5rem 0;">

                <h4><i class="fas fa-lock-open"></i> Désactiver MFA</h4>
                <p class="text-muted">La désactivation de MFA rendra votre compte moins sécurisé.</p>

                <form method="POST" onsubmit="return confirm('Êtes-vous sûr de vouloir désactiver l\'authentification à deux facteurs ?');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="disable_mfa">
                    
                    <div class="form-group">
                        <label for="password">Entrez votre mot de passe pour confirmer</label>
                        <input type="password" id="password" name="password" class="form-control" required>
                    </div>
                    
                    <button type="submit" class="btn btn-danger" style="margin-top: 1rem;">
                        <i class="fas fa-times"></i>
                        Désactiver MFA
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- Conseils de sécurité -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-lightbulb"></i> Conseils de Sécurité</h3>
        </div>
        <div class="card-body">
            <ul class="security-tips">
                <li><strong>Ne partagez jamais vos codes</strong> - Ni votre mot de passe, ni vos codes MFA</li>
                <li><strong>Utilisez une application d'authentification</strong> - Plus sécurisé que les SMS</li>
                <li><strong>Conservez les codes de secours</strong> - Dans un endroit sûr hors ligne</li>
                <li><strong>Mettez à jour votre téléphone</strong> - Gardez votre appareil sécurisé</li>
            </ul>
        </div>
    </div>
</div>

<style>
.security-status {
    display: flex;
    justify-content: center;
    padding: 1rem;
}

.status-item {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    padding: 1.5rem 2rem;
    background: #f8f9fa;
    border-radius: 12px;
}

.status-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.status-active {
    background: rgba(34, 197, 94, 0.2);
    color: #22C55E;
}

.status-inactive {
    background: rgba(239, 68, 68, 0.2);
    color: #EF4444;
}

.status-info h4 {
    margin: 0 0 0.5rem 0;
    font-size: 1.1rem;
}

.status-info p {
    margin: 0;
    font-size: 1rem;
}

.mfa-setup-info {
    display: flex;
    align-items: center;
    gap: 2rem;
    padding: 2rem;
    background: #f8f9fa;
    border-radius: 12px;
}

.mfa-setup-info .info-text {
    flex: 1;
}

.mfa-setup-info ul {
    margin-top: 1rem;
    padding-left: 1.5rem;
}

.mfa-setup-info li {
    margin-bottom: 0.5rem;
}

.qr-setup {
    display: flex;
    gap: 2rem;
    align-items: flex-start;
}

@media (max-width: 768px) {
    .qr-setup {
        flex-direction: column;
        align-items: center;
    }
    
    .mfa-setup-info {
        flex-direction: column;
        text-align: center;
    }
}

.backup-codes-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 0.5rem;
    margin-top: 1rem;
}

.backup-code {
    padding: 0.5rem;
    background: #f5f5f5;
    border-radius: 4px;
    text-align: center;
    font-family: monospace;
}

.security-tips {
    padding-left: 1.5rem;
}

.security-tips li {
    margin-bottom: 0.75rem;
}
</style>

</div> <!-- Fin #page-content -->

<script>
// Auto-formatage du code MFA
document.getElementById('mfa_code')?.addEventListener('input', function(e) {
    this.value = this.value.replace(/\D/g, '').slice(0, 6);
});

console.log('%c🚀 Gestion_Colis - Sécurité MFA', 'color: #00B4D8; font-size: 16px; font-weight: bold;');
</script>
