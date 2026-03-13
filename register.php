<?php
session_start();
require_once 'config/database.php';
require_once 'utils/password_policy.php';
require_once 'utils/email_verification.php';

// Rediriger vers le dashboard si déjà connecté
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$database = new Database();
$db = $database->getConnection();

// Traitement de l'inscription
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nom']) && isset($_POST['email'])) {
    $nom = trim($_POST['nom']);
    $prenom = trim($_POST['prenom']);
    $email = trim($_POST['email']);
    $tel = trim($_POST['telephone'] ?? $_POST['tel'] ?? '');
    $mot_de_passe = $_POST['mot_de_passe'];
    $confirmer_mot_de_passe = $_POST['confirmer_mot_de_passe'];
    $adresse = trim($_POST['adresse']);
    
    // Validation
    if (empty($nom)) $errors[] = "Le nom est requis";
    if (empty($prenom)) $errors[] = "Le prénom est requis";
    if (empty($email)) $errors[] = "L'email est requis";
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "L'email n'est pas valide";
    if (empty($mot_de_passe)) $errors[] = "Le mot de passe est requis";
    $passwordErrors = validatePasswordPolicy($mot_de_passe);
    if (!empty($passwordErrors)) $errors[] = $passwordErrors[0];
    if ($mot_de_passe !== $confirmer_mot_de_passe) $errors[] = "Les mots de passe ne correspondent pas";
    if (!isset($_POST['conditions']) || $_POST['conditions'] != '1') $errors[] = "Vous devez accepter les conditions générales";
    
    if (empty($errors)) {
        try {
            // Vérifier si l'email existe déjà
            $stmt = $db->prepare("SELECT id FROM utilisateurs WHERE email = ?");
            $stmt->execute([$email]);
            
            if ($stmt->fetch()) {
                $errors[] = "Cet email est déjà utilisé";
            } else {
                // Hacher le mot de passe
                $mot_de_passe_hash = password_hash($mot_de_passe, PASSWORD_DEFAULT);
                
                // Générer un token de vérification email
                $verificationToken = bin2hex(random_bytes(32));
                $verificationTokenHash = hash('sha256', $verificationToken);

                // Insérer le nouvel utilisateur
                $stmt = $db->prepare("
                    INSERT INTO utilisateurs (
                        nom, prenom, email, mot_de_passe, telephone, adresse, role,
                        email_verifie, email_verification_token, email_verification_sent_at,
                        mfa_active, date_creation
                    ) 
                    VALUES (?, ?, ?, ?, ?, ?, 'utilisateur', 0, ?, NOW(), 0, NOW())
                ");
                
                if ($stmt->execute([$nom, $prenom, $email, $mot_de_passe_hash, $tel, $adresse, $verificationTokenHash])) {
                    $fullName = trim($prenom . ' ' . $nom);
                    $emailSent = send_verification_email($email, $fullName, $verificationToken);

                    $_SESSION['success'] = "Inscription réussie ! Vérifiez votre email pour activer votre compte. Votre Postal ID sera généré après confirmation.";
                    if (!$emailSent) {
                        error_log("[Email] Échec envoi vérification: $email");
                        $_SESSION['success'] .= " Si vous ne recevez pas l'email, contactez le support.";
                    }
                    header('Location: login.php');
                    exit;
                } else {
                    $errors[] = "Erreur lors de l'inscription. Veuillez réessayer.";
                }
            }
        } catch (Exception $e) {
            $errors[] = user_error_message($e, 'register.create', "Erreur de base de données lors de l'inscription.");
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme) {
                document.documentElement.setAttribute('data-theme', savedTheme);
            }
        })();
    </script>
    <title>Inscription - Gestion_Colis</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Orbitron:wght@400;500;600;700;800;900&family=Rajdhani:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/images/favicon.png" sizes="32x32">
    <link rel="apple-touch-icon" href="assets/images/favicon.png">
    <link rel="shortcut icon" href="assets/images/favicon.png">
    
    <style>
        /* ========================================
           THÈME CLAIR MODERNE - INSCRIPTION
           ======================================== */
        
        :root {
            --neon-cyan: #00B4D8;
            --neon-blue: #0096C7;
            --neon-magenta: #EC4899;
            --neon-purple: #7C3AED;
            --light-bg: #F8FAFC;
            --text-on-dark: rgba(255, 255, 255, 0.92);
            --text-muted-on-dark: rgba(226, 232, 240, 0.75);
            --text-faint-on-dark: rgba(226, 232, 240, 0.65);
        }

        html[data-theme="dark"] {
            --light-bg: #0B1120;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Rajdhani', sans-serif;
            background: var(--light-bg);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem;
            position: relative;
            overflow-x: hidden;
        }
        
        /* Background Effects */
        .bg-grid {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: 
                linear-gradient(rgba(0, 180, 216, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 180, 216, 0.03) 1px, transparent 1px);
            background-size: 50px 50px;
            pointer-events: none;
            z-index: 0;
        }
        
        .bg-glow {
            position: fixed;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }
        
        .glow-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(60px);
            opacity: 0.3;
        }
        
        .glow-orb-1 {
            width: 250px;
            height: 250px;
            background: var(--neon-cyan);
            top: -80px;
            left: -80px;
        }
        
        .glow-orb-2 {
            width: 200px;
            height: 200px;
            background: var(--neon-purple);
            bottom: -80px;
            right: -80px;
        }
        
        .glow-orb-3 {
            width: 180px;
            height: 180px;
            background: var(--neon-purple);
            top: 50%;
            right: -50px;
            transform: translateY(-50%);
        }
        
        .register-wrapper {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 480px;
            margin: 0 auto;
        }
        
        .back-link {
            position: absolute;
            top: -30px;
            left: 0;
            color: var(--text-muted-on-dark);
            text-decoration: none;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            transition: all 0.3s ease;
        }
        
        .back-link:hover {
            color: var(--neon-cyan);
        }
        
        .form-container {
            background: rgba(10, 14, 23, 0.8);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            border-radius: 14px;
            border: 1px solid rgba(0, 240, 255, 0.2);
            overflow: hidden;
            width: 100%;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5), 0 0 40px rgba(0, 240, 255, 0.1);
            animation: slideUp 0.5s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .form-header {
            background: linear-gradient(135deg, var(--neon-cyan), var(--neon-blue));
            color: #000;
            padding: 1rem;
            text-align: center;
        }
        
        .form-header h1 {
            font-family: 'Orbitron', sans-serif;
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 0.15rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .form-header p {
            font-size: 0.75rem;
            opacity: 0.8;
        }
        
        .form-content {
            padding: 1rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.7rem;
        }
        
        .form-group {
            margin-bottom: 0.7rem;
        }
        
        .form-group label {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            margin-bottom: 0.35rem;
            font-weight: 600;
            color: var(--text-on-dark);
            font-size: 0.8rem;
        }
        
        .form-group label i {
            color: var(--neon-cyan);
            font-size: 0.75rem;
        }
        
        .form-control {
            width: 100%;
            padding: 0.6rem 0.8rem;
            background: rgba(15, 23, 42, 0.8);
            border: 2px solid rgba(0, 240, 255, 0.2);
            border-radius: 9px;
            color: #ffffff;
            font-size: 0.85rem;
            font-family: 'Rajdhani', sans-serif;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--neon-cyan);
            box-shadow: 0 0 20px rgba(0, 240, 255, 0.2);
        }
        
        .form-control::placeholder {
            color: var(--text-faint-on-dark);
            font-size: 0.8rem;
        }
        
        textarea.form-control {
            resize: vertical;
            min-height: 45px;
        }
        
        .password-wrapper {
            position: relative;
        }
        
        .toggle-password {
            position: absolute;
            right: 0.7rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-faint-on-dark);
            cursor: pointer;
            padding: 0.35rem;
            transition: all 0.3s ease;
            font-size: 0.8rem;
        }
        
        .toggle-password:hover {
            color: var(--neon-cyan);
        }
        
        .checkbox-label {
            display: flex !important;
            align-items: flex-start;
            gap: 0.5rem !important;
            cursor: pointer;
            font-size: 0.7rem !important;
            color: rgba(255, 255, 255, 0.7) !important;
            line-height: 1.25;
        }
        
        .checkbox-label input[type="checkbox"] {
            display: none;
        }
        
        .checkbox-custom {
            width: 16px;
            height: 16px;
            min-width: 16px;
            border: 2px solid rgba(0, 240, 255, 0.3);
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            margin-top: 1px;
        }
        
        .checkbox-label input:checked + .checkbox-custom {
            background: linear-gradient(135deg, var(--neon-cyan), var(--neon-blue));
            border-color: var(--neon-cyan);
        }
        
        .checkbox-label input:checked + .checkbox-custom::after {
            content: '✓';
            color: #000;
            font-size: 0.65rem;
            font-weight: bold;
        }
        
        .checkbox-text a {
            color: var(--neon-cyan);
            text-decoration: none;
        }
        
        .checkbox-text a:hover {
            text-decoration: underline;
        }
        
        .form-actions {
            margin-top: 1rem;
        }
        
        .btn-primary {
            width: 100%;
            padding: 0.7rem 1.3rem;
            background: linear-gradient(135deg, var(--neon-cyan), var(--neon-blue));
            color: #000;
            border: none;
            border-radius: 9px;
            font-family: 'Orbitron', sans-serif;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1.3px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 240, 255, 0.4);
        }
        
        .form-links {
            text-align: center;
            padding: 0.8rem 1rem;
            background: rgba(15, 23, 42, 0.5);
            border-top: 1px solid rgba(0, 240, 255, 0.1);
        }
        
        .form-links p {
            color: var(--text-muted-on-dark);
            font-size: 0.75rem;
        }
        
        .form-links a {
            color: var(--neon-cyan);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .form-links a:hover {
            text-shadow: 0 0 10px rgba(0, 240, 255, 0.5);
        }
        
        .alert {
            padding: 0.6rem 0.8rem;
            border-radius: 9px;
            margin-bottom: 0.7rem;
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            font-size: 0.75rem;
        }
        
        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #ef4444;
        }
        
        .alert-danger i {
            margin-top: 0.1rem;
            font-size: 0.8rem;
        }
        
        .alert-success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #22c55e;
        }
        
        @media (max-width: 576px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            body {
                padding: 0.3rem;
            }
            
            .form-header h1 {
                font-size: 1rem;
            }
            
            .register-wrapper {
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Background Effects -->
    <div class="bg-grid"></div>
    <div class="bg-glow">
        <div class="glow-orb glow-orb-1"></div>
        <div class="glow-orb glow-orb-2"></div>
        <div class="glow-orb glow-orb-3"></div>
    </div>
    
    <div class="register-wrapper">
        <a href="index.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Retour à l'accueil
        </a>
        
        <div class="form-container">
            <div class="form-header">
                <h1><i class="fas fa-user-plus"></i> Inscription</h1>
                <p>Créez votre compte Gestion_Colis</p>
            </div>
            
            <div class="form-content">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div>
                            <?php foreach ($errors as $error): ?>
                                <div><?php echo htmlspecialchars($error); ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <div><?php echo htmlspecialchars($success); ?></div>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="register.php">
                    <?php echo csrf_field(); ?>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="nom">
                                <i class="fas fa-user"></i> Nom
                            </label>
                            <input 
                                type="text" 
                                id="nom" 
                                name="nom" 
                                required 
                                placeholder="Votre nom"
                                class="form-control"
                                value="<?php echo htmlspecialchars($_POST['nom'] ?? ''); ?>"
                            >
                        </div>
                        <div class="form-group">
                            <label for="prenom">
                                <i class="fas fa-user"></i> Prénom
                            </label>
                            <input 
                                type="text" 
                                id="prenom" 
                                name="prenom" 
                                required 
                                placeholder="Votre prénom"
                                class="form-control"
                                value="<?php echo htmlspecialchars($_POST['prenom'] ?? ''); ?>"
                            >
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="email">
                            <i class="fas fa-envelope"></i> Adresse email
                        </label>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            required 
                            placeholder="votre@email.com"
                            class="form-control"
                            value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                        >
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="tel">
                                <i class="fas fa-phone"></i> Téléphone
                            </label>
                            <input 
                                type="tel" 
                                id="tel" 
                                name="telephone" 
                                placeholder="+237 6 12 34 56 78"
                                class="form-control"
                                value="<?php echo htmlspecialchars($_POST['tel'] ?? ''); ?>"
                            >
                        </div>
                        <div class="form-group">
                            <label for="mot_de_passe">
                                <i class="fas fa-lock"></i> Mot de passe
                            </label>
                            <div class="password-wrapper">
                                <input 
                                    type="password" 
                                    id="mot_de_passe" 
                                    name="mot_de_passe" 
                                    required 
                                    placeholder="Min. 6 caractères"
                                    class="form-control"
                                >
                                <button type="button" class="toggle-password" onclick="togglePassword('mot_de_passe')">
                                    <i class="fas fa-eye" id="toggleIcon-mot_de_passe"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirmer_mot_de_passe">
                            <i class="fas fa-lock"></i> Confirmer le mot de passe
                        </label>
                        <div class="password-wrapper">
                            <input 
                                type="password" 
                                id="confirmer_mot_de_passe" 
                                name="confirmer_mot_de_passe" 
                                required 
                                placeholder="Répétez votre mot de passe"
                                class="form-control"
                            >
                            <button type="button" class="toggle-password" onclick="togglePassword('confirmer_mot_de_passe')">
                                <i class="fas fa-eye" id="toggleIcon-confirmer_mot_de_passe"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="adresse">
                            <i class="fas fa-map-marker-alt"></i> Adresse
                        </label>
                        <textarea 
                            id="adresse" 
                            name="adresse" 
                            rows="2"
                            placeholder="Votre adresse complète"
                            class="form-control"
                        ><?php echo htmlspecialchars($_POST['adresse'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="conditions" value="1" required>
                            <span class="checkbox-custom"></span>
                            <span class="checkbox-text">
                                J'accepte les <a href="#" class="open-modal" data-modal="terms-modal">Conditions Générales</a>
                                et la <a href="#" class="open-modal" data-modal="privacy-modal">Politique de Confidentialité</a>
                            </span>
                        </label>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-user-plus"></i>
                            Créer mon compte
                        </button>
                    </div>
                </form>
            </div>
            
            <div class="form-links">
                <p>Déjà un compte ? 
                    <a href="login.php">Se connecter</a>
                </p>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="terms-modal" role="dialog" aria-modal="true" aria-labelledby="terms-title">
        <div class="modal modal-lg">
            <div class="modal-header">
                <h3 class="modal-title" id="terms-title">
                    <i class="fas fa-file-contract" style="color: var(--neon-cyan);"></i>
                    Conditions Générales
                </h3>
                <button class="modal-close" type="button" onclick="closeInlineModal('terms-modal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <p>En utilisant nos services, vous acceptez les points suivants :</p>
                <ul>
                    <li>L'utilisation de l'application implique l'acceptation des présentes conditions.</li>
                    <li>Vous êtes responsable de la confidentialité de votre compte et de vos identifiants.</li>
                    <li>Les informations personnelles sont traitées conformément à notre politique de confidentialité.</li>
                </ul>
                <p class="mt-2">Pour toute question, contactez notre support.</p>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="privacy-modal" role="dialog" aria-modal="true" aria-labelledby="privacy-title">
        <div class="modal modal-lg">
            <div class="modal-header">
                <h3 class="modal-title" id="privacy-title">
                    <i class="fas fa-user-shield" style="color: var(--neon-cyan);"></i>
                    Politique de Confidentialité
                </h3>
                <button class="modal-close" type="button" onclick="closeInlineModal('privacy-modal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <p>Nous protégeons vos données personnelles :</p>
                <ul>
                    <li>Les informations collectées servent uniquement à améliorer nos services.</li>
                    <li>Vos données ne sont pas vendues à des tiers.</li>
                    <li>Vous pouvez demander la suppression de vos données à tout moment.</li>
                </ul>
                <p class="mt-2">Pour toute demande, contactez notre support.</p>
            </div>
        </div>
    </div>
    
    <script>
        function openInlineModal(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.add('active');
            }
        }

        function closeInlineModal(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.remove('active');
            }
        }

        document.querySelectorAll('.open-modal').forEach(link => {
            link.addEventListener('click', function(event) {
                event.preventDefault();
                openInlineModal(this.getAttribute('data-modal'));
            });
        });

        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', (event) => {
                if (event.target === overlay) {
                    overlay.classList.remove('active');
                }
            });
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                document.querySelectorAll('.modal-overlay.active').forEach(modal => {
                    modal.classList.remove('active');
                });
            }
        });

        function togglePassword(fieldId) {
            const passwordField = document.getElementById(fieldId);
            const toggleIcon = document.getElementById('toggleIcon-' + fieldId);
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleIcon.className = 'fas fa-eye-slash';
            } else {
                passwordField.type = 'password';
                toggleIcon.className = 'fas fa-eye';
            }
        }
        
        // Validation du mot de passe en temps réel
        document.getElementById('confirmer_mot_de_passe').addEventListener('input', function() {
            const password = document.getElementById('mot_de_passe').value;
            const confirmPassword = this.value;
            
            if (confirmPassword && password !== confirmPassword) {
                this.style.borderColor = '#ef4444';
            } else {
                this.style.borderColor = 'rgba(0, 240, 255, 0.2)';
            }
        });
        
        document.getElementById('nom').focus();
        
        console.log('%c🚀 Gestion_Colis - Inscription', 'color: #00B4D8; font-size: 16px; font-weight: bold;');
    </script>
</body>
</html>
