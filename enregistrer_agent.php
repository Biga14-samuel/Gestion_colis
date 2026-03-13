<?php
/**
 * =====================================================
 * ENREGISTREMENT D'AGENT - CORRIGÉ
 * Résolution des problèmes d'enregistrement et de sauvegarde
 * =====================================================
 */

require_once __DIR__ . '/utils/session.php';
SessionManager::start();
require_once 'config/database.php';
require_once 'utils/password_policy.php';

$database = new Database();
$db = $database->getConnection();

$message = '';
$messageType = '';

// Traitement du formulaire d'enregistrement d'agent
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ajaxMode = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $ajaxResponse = ['success' => false, 'message' => '', 'errors' => []];
    
    // Récupérer et nettoyer les données
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $mot_de_passe = $_POST['mot_de_passe'] ?? '';
    $mot_de_passe_confirm = $_POST['mot_de_passe_confirm'] ?? '';
    $matricule = trim($_POST['matricule'] ?? '');
    $zone = trim($_POST['zone'] ?? '');
    $poste = trim($_POST['poste'] ?? '');
    
    // Validation des champs obligatoires
    $errors = [];
    
    if (empty($nom)) {
        $errors[] = "Le nom est requis";
    }
    if (empty($prenom)) {
        $errors[] = "Le prénom est requis";
    }
    if (empty($email)) {
        $errors[] = "L'email est requis";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "L'email n'est pas valide";
    }
    if (empty($mot_de_passe)) {
        $errors[] = "Le mot de passe est requis";
    } else {
        $passwordErrors = validatePasswordPolicy($mot_de_passe);
        if (!empty($passwordErrors)) {
            $errors[] = $passwordErrors[0];
        }
    }
    if ($mot_de_passe !== $mot_de_passe_confirm) {
        $errors[] = "Les mots de passe ne correspondent pas";
    }
    if (empty($matricule)) {
        // Générer automatiquement un matricule si non fourni
        $matricule = 'AGT' . strtoupper(substr(md5(time() . rand()), 0, 6));
    }
    
    // Vérifier les doublons
    if (empty($errors)) {
        try {
            // Vérifier si l'email existe déjà
            $stmt = $db->prepare("SELECT id FROM utilisateurs WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = "Cet email est déjà utilisé par un autre compte.";
            }
            
            // Vérifier si le numéro d'agent existe déjà dans la table agents
            $stmt = $db->prepare("SELECT id FROM agents WHERE numero_agent = ?");
            $stmt->execute([$matricule]);
            if ($stmt->fetch()) {
                // Générer un nouveau matricule unique
                $matricule = 'AGT' . strtoupper(substr(md5(time() . rand() . microtime()), 0, 6));
            }
        } catch (PDOException $e) {
            $errors[] = user_error_message($e, 'enregistrer_agent.verification', "Erreur lors de la vérification des données.");
        }
    }
    
    // Procéder à l'enregistrement si pas d'erreurs
    if (empty($errors)) {
        try {
            // Hasher le mot de passe
            $mot_de_passe_hash = password_hash($mot_de_passe, PASSWORD_DEFAULT);
            
            // =====================================================
            // CORRECTION CRITIQUE : Enregistrer DANS LA TABLE AGENTS
            // avec le bon champ numero_agent (pas matricule)
            // =====================================================
            
            // Insérer l'agent dans la table agents
            $stmt = $db->prepare("
                INSERT INTO agents (
                    utilisateur_id, 
                    numero_agent, 
                    zone_livraison, 
                    telephone_pro, 
                    poste, 
                    date_embauche, 
                    actif, 
                    vehicule_type, 
                    commission_rate,
                    total_livraisons,
                    total_earnings,
                    note_moyenne,
                    date_certification
                ) VALUES (
                    0, ?, ?, ?, ?, NOW(), 1, 'moto', 5.00, 0, 0.00, 0.00, CURDATE()
                )
            ");
            
            $result = $stmt->execute([$matricule, $zone, $telephone, $poste]);
            
            if ($result) {
                $agent_id = $db->lastInsertId();
                
                // Créer l'entrée correspondante dans la table utilisateurs
                // pour l'authentification avec le rôle agent
                $stmt_user = $db->prepare("
                    INSERT INTO utilisateurs (nom, prenom, email, telephone, matricule, zone_livraison, mot_de_passe, role, actif, date_creation) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'agent', 1, NOW())
                ");
                
                $user_result = $stmt_user->execute([$nom, $prenom, $email, $telephone, $matricule, $zone, $mot_de_passe_hash]);
                
                if ($user_result) {
                    $user_id = $db->lastInsertId();
                    
                    // Mettre à jour l'agent avec le bon utilisateur_id
                    $stmt_update = $db->prepare("UPDATE agents SET utilisateur_id = ? WHERE id = ?");
                    $stmt_update->execute([$user_id, $agent_id]);
                    
                    error_log("Agent créé avec succès - ID agent: $agent_id, ID utilisateur: $user_id, Numéro agent: $matricule");
                }
                
                $ajaxResponse['success'] = true;
                $message_success = "Inscription réussie ! Votre compte agent a été créé avec succès.\n\nNuméro d'agent : $matricule\nID Agent : $agent_id";
                $ajaxResponse['message'] = $message_success;
                $ajaxResponse['matricule'] = $matricule;
                $ajaxResponse['agent_id'] = $agent_id;
                
                // Créer une notification pour l'admin
                try {
                    $stmt_notif = $db->prepare("
                        INSERT INTO notifications (utilisateur_id, titre, message, type, date_envoi) 
                        VALUES (?, 'Nouvel agent inscrit', ?, 'info', NOW())
                    ");
                    // Trouver un admin
                    $stmt_admin = $db->query("SELECT id FROM utilisateurs WHERE role = 'admin' LIMIT 1");
                    $admin = $stmt_admin->fetch();
                    if ($admin) {
                        $stmt_notif->execute([$admin['id'], "Un nouvel agent s'est inscrit : $prenom $nom (Matricule: $matricule)"]);
                    }
                } catch (PDOException $e) {
                    // Notification non critique
                    error_log('Erreur création notification: ' . $e->getMessage());
                }
            } else {
                $errors[] = "Erreur lors de la création du compte. Veuillez réessayer.";
            }
        } catch (PDOException $e) {
            $errors[] = user_error_message($e, 'enregistrer_agent.insert', "Erreur de base de données lors de l'enregistrement.");
        }
    }
    
    // Remplir les erreurs
    if (!empty($errors)) {
        $ajaxResponse['errors'] = $errors;
        $ajaxResponse['message'] = implode("\n", $errors);
    }
    
    // Réponse JSON pour AJAX
    if ($ajaxMode) {
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, must-revalidate');
        
        // Nettoyer les données pour éviter les caractères spéciaux problématiques
        $ajaxResponse['message'] = strip_tags($ajaxResponse['message']);
        
        $jsonOutput = json_encode($ajaxResponse, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($jsonOutput === false) {
            echo json_encode(['success' => false, 'message' => 'Erreur de traitement des données'], JSON_UNESCAPED_UNICODE);
        } else {
            echo $jsonOutput;
        }
        exit;
    }
    
    $message = $ajaxResponse['message'] ?? implode("\n", $errors);
    $messageType = $ajaxResponse['success'] ? 'success' : 'error';
}

// Récupérer les zones pour la liste déroulante
$zones = [];
try {
    $stmt = $db->query("SELECT DISTINCT zone_livraison FROM utilisateurs WHERE role = 'agent' AND zone_livraison IS NOT NULL AND zone_livraison != '' ORDER BY zone_livraison");
    $zones = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $zones = [];
}
?>

<!-- Contenu pour le système SPA -->
<div id="page-content">

<div class="page-container">
    <div class="page-header">
        <h1><i class="fas fa-user-tie"></i> Enregistrement Agent</h1>
        <p>Rejoignez notre équipe de livraison en vous enregistrant</p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?>">
            <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <div><?= htmlspecialchars($message) ?></div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-user-plus"></i> Formulaire d'inscription Agent</h3>
        </div>
        <div class="card-body">
            <form id="enregistrerAgentForm" method="POST">
                <?php echo csrf_field(); ?>
                <div class="form-row">
                    <div class="form-group">
                        <label for="matricule">
                            <i class="fas fa-id-badge"></i> Matricule Agent
                        </label>
                        <input 
                            type="text" 
                            id="matricule" 
                            name="matricule" 
                            placeholder="Ex: AGT001 (généré automatiquement si vide)"
                            class="form-control"
                            value="<?php echo htmlspecialchars($_POST['matricule'] ?? ''); ?>"
                        >
                        <small class="form-hint">Votre numéro d'identification d'agent (auto-généré si vide)</small>
                    </div>
                    <div class="form-group">
                        <label for="zone">
                            <i class="fas fa-map-marked-alt"></i> Zone de Livraison
                        </label>
                        <input 
                            type="text" 
                            id="zone" 
                            name="zone" 
                            list="zonesList"
                            placeholder="Ex: Centre-ville, Akwa, Bepanda"
                            class="form-control"
                            value="<?php echo htmlspecialchars($_POST['zone'] ?? ''); ?>"
                        >
                        <datalist id="zonesList">
                            <?php foreach ($zones as $zone): ?>
                                <option value="<?= htmlspecialchars($zone) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="nom">
                            <i class="fas fa-user"></i> Nom <span class="required">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="nom" 
                            name="nom" 
                            required 
                            placeholder="Entrez votre nom"
                            class="form-control"
                            value="<?php echo htmlspecialchars($_POST['nom'] ?? ''); ?>"
                        >
                    </div>
                    <div class="form-group">
                        <label for="prenom">
                            <i class="fas fa-user"></i> Prénom <span class="required">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="prenom" 
                            name="prenom" 
                            required 
                            placeholder="Entrez votre prénom"
                            class="form-control"
                            value="<?php echo htmlspecialchars($_POST['prenom'] ?? ''); ?>"
                        >
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="email">
                            <i class="fas fa-envelope"></i> Email <span class="required">*</span>
                        </label>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            required 
                            placeholder="agent@email.com"
                            class="form-control"
                            value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                        >
                    </div>
                    <div class="form-group">
                        <label for="telephone">
                            <i class="fas fa-phone"></i> Téléphone
                        </label>
                        <input 
                            type="tel" 
                            id="telephone" 
                            name="telephone" 
                            placeholder="Numéro de téléphone"
                            class="form-control"
                            value="<?php echo htmlspecialchars($_POST['telephone'] ?? ''); ?>"
                        >
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="poste">
                            <i class="fas fa-briefcase"></i> Poste/Fonction
                        </label>
                        <input 
                            type="text" 
                            id="poste" 
                            name="poste" 
                            placeholder="Ex: Livreur, Superviseur..."
                            class="form-control"
                            value="<?php echo htmlspecialchars($_POST['poste'] ?? ''); ?>"
                        >
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="mot_de_passe">
                            <i class="fas fa-lock"></i> Mot de passe <span class="required">*</span>
                        </label>
                        <div class="password-input">
                            <input 
                                type="password" 
                                id="mot_de_passe" 
                                name="mot_de_passe" 
                                required 
                                placeholder="Minimum 6 caractères"
                                class="form-control"
                            >
                            <button type="button" class="password-toggle" onclick="togglePassword('mot_de_passe')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="mot_de_passe_confirm">
                            <i class="fas fa-lock"></i> Confirmer le mot de passe <span class="required">*</span>
                        </label>
                        <div class="password-input">
                            <input 
                                type="password" 
                                id="mot_de_passe_confirm" 
                                name="mot_de_passe_confirm" 
                                required 
                                placeholder="Répétez le mot de passe"
                                class="form-control"
                            >
                            <button type="button" class="password-toggle" onclick="togglePassword('mot_de_passe_confirm')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="info-box">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <strong>Information :</strong> Votre demande d'inscription sera soumise à validation par l'administrateur. 
                        Vous recevrez une notification une fois votre compte activé.
                    </div>
                </div>

                <div class="form-actions">
                    <div class="form-buttons" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-user-plus"></i>
                            S'inscrire comme Agent
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="loadPage('dashboard.php', 'Tableau de Bord')" style="display: flex; align-items: center; justify-content: center; text-decoration: none;">
                            <i class="fas fa-arrow-left"></i>
                            Retour
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <div class="card" style="margin-top: 1.5rem;">
        <div class="card-header">
            <h3><i class="fas fa-question-circle"></i> Pourquoi s'inscrire ?</h3>
        </div>
        <div class="card-body">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem;">
                <div class="feature-item">
                    <i class="fas fa-truck" style="color: var(--primary-cyan); font-size: 2rem; margin-bottom: 0.5rem;"></i>
                    <h4 style="margin-bottom: 0.5rem;">Flexibilité</h4>
                    <p style="color: var(--text-medium); font-size: 0.9rem;">Gérez votre propre planning de livraisons</p>
                </div>
                <div class="feature-item">
                    <i class="fas fa-money-bill-wave" style="color: var(--success-green); font-size: 2rem; margin-bottom: 0.5rem;"></i>
                    <h4 style="margin-bottom: 0.5rem;">Revenus</h4>
                    <p style="color: var(--text-medium); font-size: 0.9rem;">Commissions compétitives pour chaque livraison</p>
                </div>
                <div class="feature-item">
                    <i class="fas fa-mobile-alt" style="color: var(--purple); font-size: 2rem; margin-bottom: 0.5rem;"></i>
                    <h4 style="margin-bottom: 0.5rem;">Outils modernes</h4>
                    <p style="color: var(--text-medium); font-size: 0.9rem;">Application mobile pour gérer vos affectations</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = <?php echo json_encode(csrf_token()); ?>;
// Soumission du formulaire via AJAX pour le système SPA
document.getElementById('enregistrerAgentForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Inscription en cours...';
    submitBtn.disabled = true;
    
    // Afficher un message de chargement
    showNotification('Traitement de votre inscription en cours...', 'info');
    
    fetch('enregistrer_agent.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': csrfToken
        }
    })
    .then(response => {
        console.log('Réponse reçue, status:', response.status);
        
        if (!response.ok) {
            throw new Error('Erreur réseau: ' + response.status + ' ' + response.statusText);
        }
        
        return response.text().then(text => {
            console.log('Réponse brute:', text.substring(0, 500));
            
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Erreur parsing JSON:', e);
                console.error('Réponse:', text.substring(0, 500));
                throw new Error('Erreur de réponse du serveur');
            }
        });
    })
    .then(data => {
        console.log('Données parsées:', data);
        
        if (data.success) {
            showNotification(data.message, 'success');
            
            // Afficher le matricule si disponible
            if (data.matricule) {
                setTimeout(() => {
                    showNotification('Votre matricule: ' + data.matricule, 'info');
                }, 1500);
            }
            
            // Rediriger vers la page de connexion après inscription réussie
            setTimeout(() => {
                showNotification('Vous allez être redirigé vers la page de connexion...', 'info');
                setTimeout(() => {
                    loadPage('login.php', 'Connexion');
                }, 1500);
            }, 3000);
        } else {
            // Afficher les erreurs détaillées
            if (data.errors && data.errors.length > 0) {
                const errorMsg = data.errors.join('\n');
                showNotification(errorMsg, 'error');
            } else {
                showNotification(data.message || 'Erreur lors de l\'inscription.', 'error');
            }
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        showNotification('Erreur lors de l\'inscription: ' + error.message, 'error');
    })
    .finally(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
});

// Fonction pour basculer la visibilité du mot de passe
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const button = field.parentElement.querySelector('.password-toggle i');
    
    if (field.type === 'password') {
        field.type = 'text';
        button.classList.remove('fa-eye');
        button.classList.add('fa-eye-slash');
    } else {
        field.type = 'password';
        button.classList.remove('fa-eye-slash');
        button.classList.add('fa-eye');
    }
}

// Validation en temps réel du mot de passe
document.getElementById('mot_de_passe').addEventListener('input', function() {
    const password = this.value;
    const confirmField = document.getElementById('mot_de_passe_confirm');
    
    if (confirmField.value && password !== confirmField.value) {
        confirmField.setCustomValidity('Les mots de passe ne correspondent pas');
    } else {
        confirmField.setCustomValidity('');
    }
});

document.getElementById('mot_de_passe_confirm').addEventListener('input', function() {
    const password = document.getElementById('mot_de_passe').value;
    
    if (password && this.value !== password) {
        this.setCustomValidity('Les mots de passe ne correspondent pas');
    } else {
        this.setCustomValidity('');
    }
});

// Générer automatiquement un matricule si vide
document.getElementById('matricule').addEventListener('blur', function() {
    if (!this.value.trim()) {
        const now = new Date();
        const matricule = 'AGT' + now.getFullYear().toString().slice(-2) + 
                          Math.random().toString(36).substr(2, 4).toUpperCase();
        this.value = matricule;
    }
});

// Focus sur le premier champ
document.getElementById('nom').focus();

console.log('%c🚀 Gestion_Colis - Enregistrement Agent CORRIGÉ', 'color: #0891B2; font-size: 16px; font-weight: bold;');
</script>

</div> <!-- Fin #page-content -->
