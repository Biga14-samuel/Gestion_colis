<?php
/**
 * Paramètres de l'Application - Gestion_Colis
 * Configuration globale et préférences utilisateur
 */

session_start();
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Récupérer les informations de l'utilisateur
$stmt = $db->prepare("SELECT * FROM utilisateurs WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$themePreference = $user['theme_preference'] ?? 'light';

// Traitement des formulaires
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Mise à jour du profil
    if ($action === 'update_profile') {
        $nom = trim($_POST['nom'] ?? '');
        $prenom = trim($_POST['prenom'] ?? '');
        $tel = trim($_POST['tel'] ?? '');
        $adresse = trim($_POST['adresse'] ?? '');
        
        if (empty($nom) || empty($prenom)) {
            $message = 'Le nom et le prénom sont requis.';
            $messageType = 'error';
        } else {
            try {
                $stmt = $db->prepare("
                    UPDATE utilisateurs 
                    SET nom = ?, prenom = ?, tel = ?, adresse = ?, date_modification = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$nom, $prenom, $tel, $adresse, $_SESSION['user_id']]);
                
                // Mettre à jour les données en session
                $_SESSION['user_nom'] = $nom;
                $_SESSION['user_prenom'] = $prenom;
                
                $message = 'Profil mis à jour avec succès !';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = user_error_message($e, 'settings.update_profile', 'Erreur lors de la mise à jour du profil.');
                $messageType = 'error';
            }
        }
    }
    
    // Changement de mot de passe
    if ($action === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (!password_verify($current_password, $user['mot_de_passe'])) {
            $message = 'Mot de passe actuel incorrect.';
            $messageType = 'error';
        } elseif (strlen($new_password) < 8) {
            $message = 'Le nouveau mot de passe doit contenir au moins 8 caractères.';
            $messageType = 'error';
        } elseif ($new_password !== $confirm_password) {
            $message = 'Les mots de passe ne correspondent pas.';
            $messageType = 'error';
        } else {
            try {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE utilisateurs SET mot_de_passe = ?, date_modification = NOW() WHERE id = ?");
                $stmt->execute([$hashed_password, $_SESSION['user_id']]);
                
                $message = 'Mot de passe modifié avec succès !';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Erreur lors du changement de mot de passe.';
                $messageType = 'error';
            }
        }
    }
    
    // Configuration des notifications
    if ($action === 'update_notifications') {
        $notif_colis = isset($_POST['notif_colis']) ? 1 : 0;
        $notif_livraison = isset($_POST['notif_livraison']) ? 1 : 0;
        $notif_paiement = isset($_POST['notif_paiement']) ? 1 : 0;
        $notif_email = isset($_POST['notif_email']) ? 1 : 0;
        $notif_sms = isset($_POST['notif_sms']) ? 1 : 0;
        
        try {
            // Créer ou mettre à jour les préférences de notification
            $stmt = $db->prepare("
                INSERT INTO notifications_preferences (utilisateur_id, notif_colis, notif_livraison, notif_paiement, notif_email, notif_sms)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    notif_colis = VALUES(notif_colis),
                    notif_livraison = VALUES(notif_livraison),
                    notif_paiement = VALUES(notif_paiement),
                    notif_email = VALUES(notif_email),
                    notif_sms = VALUES(notif_sms)
            ");
            $stmt->execute([$_SESSION['user_id'], $notif_colis, $notif_livraison, $notif_paiement, $notif_email, $notif_sms]);
            
            $message = 'Préférences de notifications mises à jour !';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Erreur lors de la mise à jour des notifications.';
            $messageType = 'error';
        }
    }

    // Préférences d'apparence (thème)
    if ($action === 'update_theme') {
        $theme = $_POST['theme_preference'] ?? 'light';
        $theme = in_array($theme, ['light', 'dark'], true) ? $theme : 'light';

        try {
            $stmt = $db->prepare("UPDATE utilisateurs SET theme_preference = ?, date_modification = NOW() WHERE id = ?");
            $stmt->execute([$theme, $_SESSION['user_id']]);

            $_SESSION['theme_preference'] = $theme;
            $user['theme_preference'] = $theme;
            $themePreference = $theme;

            $message = 'Thème mis à jour avec succès !';
            $messageType = 'success';

            if ($isAjax) {
                echo json_encode(['success' => true, 'message' => $message, 'theme' => $theme]);
                exit;
            }
        } catch (Exception $e) {
            $message = user_error_message($e, 'settings.update_theme', 'Erreur lors de la mise à jour du thème.');
            $messageType = 'error';
            if ($isAjax) {
                echo json_encode(['success' => false, 'message' => $message]);
                exit;
            }
        }
    }
    
    // Paramètres de l'application (Admin uniquement)
    if ($action === 'app_settings' && $user['role'] === 'admin') {
        $site_name = trim($_POST['site_name'] ?? 'Gestion_Colis');
        $contact_email = trim($_POST['contact_email'] ?? '');
        $commission_rate = floatval($_POST['commission_rate'] ?? 10);
        $max_ibox_per_user = intval($_POST['max_ibox_per_user'] ?? 5);
        
        try {
            // Sauvegarder les paramètres dans un fichier de configuration ou la base de données
            $stmt = $db->prepare("
                INSERT INTO app_settings (setting_key, setting_value)
                VALUES 
                    ('site_name', ?),
                    ('contact_email', ?),
                    ('commission_rate', ?),
                    ('max_ibox_per_user', ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $stmt->execute([$site_name, $contact_email, $commission_rate, $max_ibox_per_user]);
            
            $message = 'Paramètres de l\'application mis à jour !';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Erreur lors de la mise à jour des paramètres.';
            $messageType = 'error';
        }
    }
}

// Récupérer les préférences de notification
$notif_prefs = [];
try {
    $stmt = $db->prepare("SELECT * FROM notifications_preferences WHERE utilisateur_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $notif_prefs = $stmt->fetch() ?: [];
} catch (Exception $e) {
    $notif_prefs = [
        'notif_colis' => 1,
        'notif_livraison' => 1,
        'notif_paiement' => 1,
        'notif_email' => 1,
        'notif_sms' => 0
    ];
}

// Récupérer les paramètres de l'application (Admin)
$app_settings = [];
if ($user['role'] === 'admin') {
    try {
        $stmt = $db->query("SELECT setting_key, setting_value FROM app_settings");
        $settings = $stmt->fetchAll();
        foreach ($settings as $setting) {
            $app_settings[$setting['setting_key']] = $setting['setting_value'];
        }
    } catch (Exception $e) {
        $app_settings = [
            'site_name' => 'Gestion_Colis',
            'commission_rate' => 10,
            'max_ibox_per_user' => 5
        ];
    }
}
?>

<div id="page-content">
    <div class="page-container">
        <div class="page-header">
            <h1>
                <i class="fas fa-cog" style="color: #00B4D8;"></i>
                Paramètres
            </h1>
            <p>Gérez vos préférences et la configuration de l'application</p>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?= htmlspecialchars($messageType) ?>">
                <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        
        <!-- Onglets des paramètres -->
        <div class="settings-tabs">
            <button class="tab-btn active" data-tab="profile">
                <i class="fas fa-user"></i> Profil
            </button>
            <button class="tab-btn" data-tab="security">
                <i class="fas fa-shield-alt"></i> Sécurité
            </button>
            <button class="tab-btn" data-tab="notifications">
                <i class="fas fa-bell"></i> Notifications
            </button>
            <button class="tab-btn" data-tab="preferences">
                <i class="fas fa-sliders-h"></i> Préférences
            </button>
            <?php if ($user['role'] === 'admin'): ?>
                <button class="tab-btn" data-tab="app-settings">
                    <i class="fas fa-cogs"></i> Application
                </button>
            <?php endif; ?>
        </div>
        
        <!-- Profil -->
        <div class="tab-content active" id="tab-profile">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-user"></i> Informations Personnelles</h3>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="prenom">
                                    <i class="fas fa-user-tag"></i> Prénom
                                </label>
                                <input type="text" id="prenom" name="prenom" class="form-control" 
                                       value="<?= htmlspecialchars($user['prenom']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="nom">
                                    <i class="fas fa-user"></i> Nom
                                </label>
                                <input type="text" id="nom" name="nom" class="form-control" 
                                       value="<?= htmlspecialchars($user['nom']) ?>" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">
                                <i class="fas fa-envelope"></i> Adresse Email
                            </label>
                            <input type="email" id="email" class="form-control" 
                                   value="<?= htmlspecialchars($user['email']) ?>" disabled>
                            <small class="text-muted">L'email ne peut pas être modifié.</small>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="tel">
                                    <i class="fas fa-phone"></i> Téléphone
                                </label>
                                <input type="tel" id="tel" name="tel" class="form-control" 
                                       value="<?= htmlspecialchars($user['tel'] ?? '') ?>" 
                                       placeholder="+225 XX XX XXX XX">
                            </div>
                            <div class="form-group">
                                <label for="role">
                                    <i class="fas fa-id-badge"></i> Rôle
                                </label>
                                <input type="text" id="role" class="form-control" 
                                       value="<?= htmlspecialchars(ucfirst($user['role'])) ?>" disabled>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="adresse">
                                <i class="fas fa-map-marker-alt"></i> Adresse
                            </label>
                            <textarea id="adresse" name="adresse" class="form-control" 
                                      rows="2" placeholder="Votre adresse complète"><?= htmlspecialchars($user['adresse'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Enregistrer les modifications
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Sécurité -->
        <div class="tab-content" id="tab-security">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-key"></i> Changer le Mot de Passe</h3>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="form-group">
                            <label for="current_password">
                                <i class="fas fa-lock"></i> Mot de Passe Actuel
                            </label>
                            <input type="password" id="current_password" name="current_password" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_password">
                                <i class="fas fa-lock-open"></i> Nouveau Mot de Passe
                            </label>
                            <input type="password" id="new_password" name="new_password" class="form-control" 
                                   minlength="8" required>
                            <small class="text-muted">Minimum 8 caractères</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password">
                                <i class="fas fa-check-circle"></i> Confirmer le Nouveau Mot de Passe
                            </label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-key"></i> Changer le Mot de Passe
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-shield-alt"></i> Authentification à Deux Facteurs (MFA)</h3>
                </div>
                <div class="card-body">
                    <div class="mfa-status">
                        <div class="status-indicator <?= $user['mfa_active'] ? 'active' : '' ?>">
                            <i class="fas fa-<?= $user['mfa_active'] ? 'check-circle' : 'times-circle' ?>"></i>
                            <span><?= $user['mfa_active'] ? 'Activé' : 'Désactivé' ?></span>
                        </div>
                    </div>
                    <p class="text-muted mt-2">
                        <?= $user['mfa_active'] 
                            ? 'Votre compte est protégé par l\'authentification à deux facteurs.' 
                            : 'Activez l\'authentification à deux facteurs pour sécuriser votre compte.' ?>
                    </p>
                    <button class="btn btn-<?= $user['mfa_active'] ? 'secondary' : 'primary' ?>" 
                            onclick="loadPage('security_mfa.php', 'Sécurité MFA')">
                        <i class="fas fa-<?= $user['mfa_active'] ? 'cog' : 'shield-alt' ?>"></i>
                        <?= $user['mfa_active'] ? 'Gérer MFA' : 'Activer MFA' ?>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Notifications -->
        <div class="tab-content" id="tab-notifications">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-bell"></i> Préférences de Notifications</h3>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="update_notifications">
                        
                        <h4 class="mb-3">Types de Notifications</h4>
                        
                        <div class="checkbox-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="notif_colis" <?= ($notif_prefs['notif_colis'] ?? 1) ? 'checked' : '' ?>>
                                <span class="checkbox-custom"></span>
                                <div>
                                    <strong>Notifications de Colis</strong>
                                    <small>Recevoir des alertes lors de la création ou mise à jour de colis</small>
                                </div>
                            </label>
                            
                            <label class="checkbox-label">
                                <input type="checkbox" name="notif_livraison" <?= ($notif_prefs['notif_livraison'] ?? 1) ? 'checked' : '' ?>>
                                <span class="checkbox-custom"></span>
                                <div>
                                    <strong>Notifications de Livraison</strong>
                                    <small>Être informé de l'état de vos livraisons</small>
                                </div>
                            </label>
                            
                            <label class="checkbox-label">
                                <input type="checkbox" name="notif_paiement" <?= ($notif_prefs['notif_paiement'] ?? 1) ? 'checked' : '' ?>>
                                <span class="checkbox-custom"></span>
                                <div>
                                    <strong>Notifications de Paiement</strong>
                                    <small>Recevoir les confirmations et reçus de paiement</small>
                                </div>
                            </label>
                        </div>
                        
                        <h4 class="mt-4 mb-3">Canaux de Notification</h4>
                        
                        <div class="checkbox-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="notif_email" <?= ($notif_prefs['notif_email'] ?? 1) ? 'checked' : '' ?>>
                                <span class="checkbox-custom"></span>
                                <div>
                                    <i class="fas fa-envelope" style="color: #00B4D8;"></i>
                                    <strong> Notifications par Email</strong>
                                </div>
                            </label>
                            
                            <label class="checkbox-label">
                                <input type="checkbox" name="notif_sms" <?= ($notif_prefs['notif_sms'] ?? 0) ? 'checked' : '' ?>>
                                <span class="checkbox-custom"></span>
                                <div>
                                    <i class="fas fa-sms" style="color: #10B981;"></i>
                                    <strong> Notifications par SMS</strong>
                                </div>
                            </label>
                        </div>
                        
                        <div class="form-actions mt-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Enregistrer les préférences
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Préférences -->
        <div class="tab-content" id="tab-preferences">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-palette"></i> Apparence</h3>
                </div>
                <div class="card-body">
                    <form method="POST" id="theme-form" data-ajax>
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="update_theme">
                        <input type="hidden" name="theme_preference" id="theme_preference" value="<?= htmlspecialchars($themePreference) ?>">

                        <div class="theme-selector">
                            <h4>Thème de l'interface</h4>
                            <div class="theme-options">
                                <button type="button" class="theme-option <?= $themePreference === 'light' ? 'active' : '' ?>" data-theme="light">
                                    <div class="theme-preview light"></div>
                                    <span>Clair</span>
                                </button>
                                <button type="button" class="theme-option <?= $themePreference === 'dark' ? 'active' : '' ?>" data-theme="dark">
                                    <div class="theme-preview dark"></div>
                                    <span>Sombre</span>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-language"></i> Langue</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="language">
                            <i class="fas fa-globe"></i> Langue de l'interface
                        </label>
                        <select id="language" name="language" class="form-control">
                            <option value="fr">Français</option>
                            <option value="en">English</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Paramètres Application (Admin) -->
        <?php if ($user['role'] === 'admin'): ?>
        <div class="tab-content" id="tab-app-settings">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-cogs"></i> Configuration de l'Application</h3>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="app_settings">
                        
                        <div class="form-group">
                            <label for="site_name">
                                <i class="fas fa-heading"></i> Nom de l'Application
                            </label>
                            <input type="text" id="site_name" name="site_name" class="form-control" 
                                   value="<?= htmlspecialchars($app_settings['site_name'] ?? 'Gestion_Colis') ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="contact_email">
                                <i class="fas fa-envelope"></i> Email de Contact
                            </label>
                            <input type="email" id="contact_email" name="contact_email" class="form-control" 
                                   value="<?= htmlspecialchars($app_settings['contact_email'] ?? '') ?>" 
                                   placeholder="contact@gestion_colis.com">
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="commission_rate">
                                    <i class="fas fa-percentage"></i> Taux de Commission (%)
                                </label>
                                <input type="number" id="commission_rate" name="commission_rate" class="form-control" 
                                       value="<?= htmlspecialchars($app_settings['commission_rate'] ?? 10) ?>" 
                                       min="0" max="50" step="0.5">
                            </div>
                            
                            <div class="form-group">
                                <label for="max_ibox_per_user">
                                    <i class="fas fa-boxes"></i> Max iBox par Utilisateur
                                </label>
                                <input type="number" id="max_ibox_per_user" name="max_ibox_per_user" class="form-control" 
                                       value="<?= htmlspecialchars($app_settings['max_ibox_per_user'] ?? 5) ?>" 
                                       min="1" max="20">
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Enregistrer les paramètres
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-database"></i> Base de Données</h3>
                </div>
                <div class="card-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Total Utilisateurs</span>
                            <span class="info-value">
                                <?php
                                $stmt = $db->query("SELECT COUNT(*) FROM utilisateurs");
                                echo $stmt->fetchColumn();
                                ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Total Colis</span>
                            <span class="info-value">
                                <?php
                                $stmt = $db->query("SELECT COUNT(*) FROM colis");
                                echo $stmt->fetchColumn();
                                ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Total iBox</span>
                            <span class="info-value">
                                <?php
                                $stmt = $db->query("SELECT COUNT(*) FROM ibox");
                                echo $stmt->fetchColumn();
                                ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Agents Actifs</span>
                            <span class="info-value">
                                <?php
                                $stmt = $db->query("SELECT COUNT(*) FROM agents WHERE actif = 1");
                                echo $stmt->fetchColumn();
                                ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Gestion des onglets
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        // Désactiver tous les onglets
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        
        // Activer l'onglet sélectionné
        this.classList.add('active');
        const tabId = this.getAttribute('data-tab');
        document.getElementById('tab-' + tabId).classList.add('active');
    });
});

// Validation du changement de mot de passe
document.querySelector('form[action="change_password"]').addEventListener('submit', function(e) {
    const newPass = document.getElementById('new_password').value;
    const confirmPass = document.getElementById('confirm_password').value;
    
    if (newPass !== confirmPass) {
        e.preventDefault();
        showNotification('Les mots de passe ne correspondent pas.', 'error');
    }
});

// Sélection du thème
const themeForm = document.getElementById('theme-form');
const themeInput = document.getElementById('theme_preference');

document.querySelectorAll('.theme-option').forEach(option => {
    option.addEventListener('click', function() {
        document.querySelectorAll('.theme-option').forEach(o => o.classList.remove('active'));
        this.classList.add('active');
        
        const theme = this.getAttribute('data-theme');
        if (themeInput) {
            themeInput.value = theme;
        }

        // Sauvegarder dans localStorage
        localStorage.setItem('theme', theme);
        // Appliquer le thème
        document.documentElement.setAttribute('data-theme', theme);

        if (themeForm) {
            if (typeof themeForm.requestSubmit === 'function') {
                themeForm.requestSubmit();
            } else {
                themeForm.submit();
            }
        }
    });
});

// Charger le thème sauvegardé
const savedTheme = document.documentElement.getAttribute('data-theme') || localStorage.getItem('theme') || 'light';
const themeOption = document.querySelector(`.theme-option[data-theme="${savedTheme}"]`);
if (themeOption) {
    themeOption.classList.add('active');
    document.documentElement.setAttribute('data-theme', savedTheme);
    localStorage.setItem('theme', savedTheme);
}

console.log('%c🚀 Gestion_Colis - Paramètres', 'color: #00B4D8; font-size: 16px; font-weight: bold;');
</script>

<style>
.settings-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 2rem;
    padding: 1rem;
    background: var(--bg-card);
    border-radius: var(--radius-md);
    border: 1px solid var(--border-color);
}

.tab-btn {
    padding: 0.75rem 1.25rem;
    background: transparent;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    color: var(--text-secondary);
    cursor: pointer;
    transition: all var(--transition-fast);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 500;
}

.tab-btn:hover {
    border-color: var(--tech-cyan);
    color: var(--tech-cyan);
}

.tab-btn.active {
    background: linear-gradient(135deg, var(--tech-cyan), var(--tech-blue));
    border-color: transparent;
    color: var(--white);
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.mfa-status {
    margin-bottom: 1rem;
}

.status-indicator {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    border-radius: var(--radius-full);
    color: var(--error);
}

.status-indicator.active {
    background: rgba(34, 197, 94, 0.1);
    border-color: rgba(34, 197, 94, 0.3);
    color: var(--success);
}

.checkbox-group {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.checkbox-label {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 1rem;
    background: var(--bg-tertiary);
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: all var(--transition-fast);
}

.checkbox-label:hover {
    background: rgba(0, 180, 216, 0.1);
}

.checkbox-label input[type="checkbox"] {
    display: none;
}

.checkbox-custom {
    width: 20px;
    height: 20px;
    border: 2px solid var(--border-color);
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all var(--transition-fast);
    flex-shrink: 0;
    margin-top: 2px;
}

.checkbox-label input:checked + .checkbox-custom {
    background: var(--tech-cyan);
    border-color: var(--tech-cyan);
}

.checkbox-label input:checked + .checkbox-custom::after {
    content: '✓';
    color: white;
    font-size: 12px;
    font-weight: bold;
}

.checkbox-label small {
    display: block;
    color: var(--text-muted);
    margin-top: 0.25rem;
}

.theme-selector h4 {
    margin-bottom: 1rem;
}

.theme-options {
    display: flex;
    gap: 1rem;
}

.theme-option {
    background: transparent;
    border: 2px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 0.5rem;
    cursor: pointer;
    transition: all var(--transition-fast);
    text-align: center;
}

.theme-option:hover {
    border-color: var(--tech-cyan);
}

.theme-option.active {
    border-color: var(--tech-cyan);
    background: rgba(0, 180, 216, 0.1);
}

.theme-preview {
    width: 80px;
    height: 50px;
    border-radius: var(--radius-sm);
    margin-bottom: 0.5rem;
}

.theme-preview.light {
    background: linear-gradient(135deg, #F8FAFC, #E2E8F0);
}

.theme-preview.dark {
    background: linear-gradient(135deg, #1E293B, #0F172A);
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
}

.info-item {
    padding: 1rem;
    background: var(--bg-tertiary);
    border-radius: var(--radius-md);
    text-align: center;
}

.info-label {
    display: block;
    color: var(--text-muted);
    font-size: 0.85rem;
    margin-bottom: 0.5rem;
}

.info-value {
    font-family: var(--font-display);
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--tech-cyan);
}

.mt-2 { margin-top: 0.5rem; }
.mt-3 { margin-top: 1rem; }
.mt-4 { margin-top: 1.5rem; }
.mb-3 { margin-bottom: 1rem; }
</style>
