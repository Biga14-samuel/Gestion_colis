<?php
require_once __DIR__ . '/utils/session.php';
SessionManager::start();
require_once 'config/database.php';
require_once 'utils/email_verification.php';

// Rediriger vers le dashboard si déjà connecté
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$database = new Database();
$db = $database->getConnection();

// Rate limiting configuration (brute force protection)
$rateLimitConfig = [
    'ip' => [
        'max_attempts' => 20,
        'window_minutes' => 15,
        'lock_minutes' => 15
    ],
    'email' => [
        'max_attempts' => 5,
        'window_minutes' => 15,
        'lock_minutes' => 15
    ]
];

function get_client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!is_string($ip) || $ip === '') {
        return '0.0.0.0';
    }
    return $ip;
}

function normalize_email(string $email): string {
    return strtolower(trim($email));
}

function rate_limit_message(int $retryAfterSeconds): string {
    $minutes = (int) ceil(max(1, $retryAfterSeconds) / 60);
    $suffix = $minutes > 1 ? 's' : '';
    return "Trop de tentatives de connexion. Réessayez dans {$minutes} minute{$suffix}.";
}

function rate_limit_fetch(PDO $db, string $ip, string $emailKey): ?array {
    $stmt = $db->prepare("
        SELECT attempts, last_attempt_at, locked_until
        FROM login_attempts
        WHERE ip_address = ? AND email = ?
        LIMIT 1
    ");
    $stmt->execute([$ip, $emailKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function rate_limit_state(?array $attempt, DateTimeImmutable $now, array $config): array {
    $attempts = $attempt ? (int) $attempt['attempts'] : 0;
    $lastAttemptAt = null;
    $lockedUntil = null;

    if ($attempt && !empty($attempt['last_attempt_at'])) {
        $lastAttemptAt = new DateTimeImmutable($attempt['last_attempt_at']);
    }
    if ($attempt && !empty($attempt['locked_until'])) {
        $lockedUntil = new DateTimeImmutable($attempt['locked_until']);
    }

    if ($lockedUntil && $now < $lockedUntil) {
        return [
            'blocked' => true,
            'attempts' => $attempts,
            'locked_until' => $lockedUntil,
            'retry_after' => $lockedUntil->getTimestamp() - $now->getTimestamp()
        ];
    }

    if ($lockedUntil && $now >= $lockedUntil) {
        $lockedUntil = null;
        $attempts = 0;
    }

    $windowMinutes = max(1, (int) $config['window_minutes']);
    $windowStart = $now->sub(new DateInterval('PT' . $windowMinutes . 'M'));
    if ($lastAttemptAt && $lastAttemptAt < $windowStart) {
        $attempts = 0;
    }

    return [
        'blocked' => false,
        'attempts' => $attempts,
        'locked_until' => $lockedUntil,
        'retry_after' => 0
    ];
}

function rate_limit_upsert(
    PDO $db,
    string $ip,
    string $emailKey,
    int $attempts,
    DateTimeImmutable $now,
    ?DateTimeImmutable $lockedUntil,
    string $userAgent
): void {
    $stmt = $db->prepare("
        INSERT INTO login_attempts (ip_address, email, attempts, last_attempt_at, locked_until, user_agent)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            attempts = VALUES(attempts),
            last_attempt_at = VALUES(last_attempt_at),
            locked_until = VALUES(locked_until),
            user_agent = VALUES(user_agent)
    ");
    $stmt->execute([
        $ip,
        $emailKey,
        $attempts,
        $now->format('Y-m-d H:i:s'),
        $lockedUntil ? $lockedUntil->format('Y-m-d H:i:s') : null,
        $userAgent
    ]);
}

function rate_limit_clear(PDO $db, string $ip, string $emailKey): void {
    $stmt = $db->prepare("DELETE FROM login_attempts WHERE ip_address = ? AND email = ?");
    $stmt->execute([$ip, $emailKey]);
}

function rate_limit_check_all(PDO $db, string $ip, array $keys, DateTimeImmutable $now): array {
    $blocked = false;
    $retryAfter = 0;

    foreach ($keys as $entry) {
        $attempt = rate_limit_fetch($db, $ip, $entry['email']);
        $state = rate_limit_state($attempt, $now, $entry['config']);
        if ($state['blocked']) {
            $blocked = true;
            $retryAfter = max($retryAfter, $state['retry_after']);
        }
    }

    return ['blocked' => $blocked, 'retry_after' => $retryAfter];
}

function rate_limit_register_failure(
    PDO $db,
    string $ip,
    array $keys,
    DateTimeImmutable $now,
    string $userAgent
): array {
    $locked = false;
    $retryAfter = 0;

    foreach ($keys as $entry) {
        $attempt = rate_limit_fetch($db, $ip, $entry['email']);
        $state = rate_limit_state($attempt, $now, $entry['config']);
        $attempts = $state['attempts'] + 1;
        $lockedUntil = null;

        if ($attempts >= (int) $entry['config']['max_attempts']) {
            $lockMinutes = max(1, (int) $entry['config']['lock_minutes']);
            $lockedUntil = $now->add(new DateInterval('PT' . $lockMinutes . 'M'));
            $locked = true;
            $retryAfter = max($retryAfter, $lockedUntil->getTimestamp() - $now->getTimestamp());
        }

        rate_limit_upsert($db, $ip, $entry['email'], $attempts, $now, $lockedUntil, $userAgent);
    }

    return ['locked' => $locked, 'retry_after' => $retryAfter];
}

// Traitement de la connexion
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    $email = trim($_POST['email']);
    $mot_de_passe = $_POST['mot_de_passe'] ?? '';
    
    if (empty($email) || empty($mot_de_passe)) {
        $error = "Email et mot de passe requis";
    } else {
        try {
            $ip = get_client_ip();
            $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);
            $emailKey = normalize_email($email);
            $now = new DateTimeImmutable('now');

            $rateLimitKeys = [
                ['email' => $emailKey, 'config' => $rateLimitConfig['email']],
                ['email' => '', 'config' => $rateLimitConfig['ip']]
            ];

            $blockInfo = rate_limit_check_all($db, $ip, $rateLimitKeys, $now);
            if ($blockInfo['blocked']) {
                http_response_code(429);
                $error = rate_limit_message($blockInfo['retry_after']);
            } else {
                $stmt = $db->prepare("SELECT * FROM utilisateurs WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                
                if ($user && password_verify($mot_de_passe, $user['mot_de_passe'])) {
                    if (empty($user['email_verifie'])) {
                        $shouldSend = true;
                        if (!empty($user['email_verification_sent_at'])) {
                            try {
                                $sentAt = new DateTimeImmutable($user['email_verification_sent_at']);
                                if (($now->getTimestamp() - $sentAt->getTimestamp()) < 300) {
                                    $shouldSend = false;
                                }
                            } catch (Exception $e) {
                                $shouldSend = true;
                            }
                        }

                        if ($shouldSend) {
                            $verificationToken = bin2hex(random_bytes(32));
                            $verificationTokenHash = hash('sha256', $verificationToken);
                            $stmt = $db->prepare("
                                UPDATE utilisateurs
                                SET email_verification_token = ?, email_verification_sent_at = NOW()
                                WHERE id = ?
                            ");
                            $stmt->execute([$verificationTokenHash, $user['id']]);
                            $fullName = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
                            $sent = send_verification_email($user['email'], $fullName ?: $user['email'], $verificationToken);
                            if (!$sent) {
                                error_log('[Email] Échec envoi vérification: ' . $user['email']);
                            }
                        }

                        rate_limit_clear($db, $ip, $emailKey);
                        rate_limit_clear($db, $ip, '');
                        $error = "Votre email n'est pas vérifié. Un lien de vérification vient de vous être envoyé.";
                    } else {
                    // Régénérer l'ID de session pour prévenir les attaques de fixation de session
                    session_regenerate_id(true);
                    
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['user_nom'] = $user['nom'];
                    $_SESSION['user_prenom'] = $user['prenom'];
                    $_SESSION['theme_preference'] = $user['theme_preference'] ?? 'light';

                    rate_limit_clear($db, $ip, $emailKey);
                    rate_limit_clear($db, $ip, '');
                    
                    header('Location: dashboard.php');
                    exit;
                    }
                } else {
                    $failureInfo = rate_limit_register_failure($db, $ip, $rateLimitKeys, $now, $userAgent);
                    if ($failureInfo['locked']) {
                        http_response_code(429);
                        $error = rate_limit_message($failureInfo['retry_after']);
                    } else {
                        $error = "Email ou mot de passe incorrect";
                    }
                }
            }
        } catch (Exception $e) {
            $error = user_error_message($e, 'login', "Erreur de connexion. Veuillez réessayer plus tard.");
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
    <title>Connexion - Gestion_Colis</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Orbitron:wght@400;500;600;700;800;900&family=Rajdhani:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/images/favicon.png" sizes="32x32">
    <link rel="apple-touch-icon" href="assets/images/favicon.png">
    <link rel="shortcut icon" href="assets/images/favicon.png">
    
    <style>
        /* ========================================
           THÈME CLAIR MODERNE - CONNEXION
           ======================================== */
        
        :root {
            --neon-cyan: #00B4D8;
            --neon-blue: #0096C7;
            --neon-magenta: #EC4899;
            --light-bg: #F8FAFC;
            --white: #FFFFFF;
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
            padding: 2rem;
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
            filter: blur(80px);
            opacity: 0.4;
        }
        
        .glow-orb-1 {
            width: 400px;
            height: 400px;
            background: var(--neon-cyan);
            top: -100px;
            left: -100px;
        }
        
        .glow-orb-2 {
            width: 300px;
            height: 300px;
            background: var(--neon-magenta);
            bottom: -100px;
            right: -100px;
        }
        
        .login-wrapper {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 450px;
        }
        
        .back-link {
            position: absolute;
            top: -50px;
            left: 0;
            color: var(--text-muted-on-dark);
            text-decoration: none;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }
        
        .back-link:hover {
            color: var(--neon-cyan);
        }
        
        .form-container {
            background: rgba(10, 14, 23, 0.8);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            border-radius: 20px;
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
            padding: 2.5rem;
            text-align: center;
        }
        
        .form-header h1 {
            font-family: 'Orbitron', sans-serif;
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
        }
        
        .form-header p {
            font-size: 0.9rem;
            opacity: 0.8;
        }
        
        .form-content {
            padding: 2.5rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
            font-weight: 600;
            color: var(--text-on-dark);
            font-size: 0.95rem;
        }
        
        .form-group label i {
            color: var(--neon-cyan);
        }
        
        .form-control {
            width: 100%;
            padding: 1rem 1.25rem;
            background: rgba(15, 23, 42, 0.8);
            border: 2px solid rgba(0, 240, 255, 0.2);
            border-radius: 12px;
            color: #ffffff;
            font-size: 1rem;
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
        }
        
        .password-wrapper {
            position: relative;
        }
        
        .toggle-password {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-faint-on-dark);
            cursor: pointer;
            padding: 0.5rem;
            transition: all 0.3s ease;
        }
        
        .toggle-password:hover {
            color: var(--neon-cyan);
        }
        
        .form-actions {
            margin-top: 2rem;
        }
        
        .btn-primary {
            width: 100%;
            padding: 1rem 2rem;
            background: linear-gradient(135deg, var(--neon-cyan), var(--neon-blue));
            color: #000;
            border: none;
            border-radius: 12px;
            font-family: 'Orbitron', sans-serif;
            font-size: 1rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 240, 255, 0.4);
        }
        
        .form-links {
            text-align: center;
            padding: 1.5rem 2.5rem;
            background: rgba(15, 23, 42, 0.5);
            border-top: 1px solid rgba(0, 240, 255, 0.1);
        }
        
        .form-links p {
            color: var(--text-muted-on-dark);
            font-size: 0.9rem;
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
            padding: 1rem 1.25rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            font-size: 0.9rem;
        }
        
        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #ef4444;
        }
        
        .alert-danger i {
            margin-top: 0.2rem;
        }
    </style>
</head>
<body>
    <!-- Background Effects -->
    <div class="bg-grid"></div>
    <div class="bg-glow">
        <div class="glow-orb glow-orb-1"></div>
        <div class="glow-orb glow-orb-2"></div>
    </div>
    
    <div class="login-wrapper">
        <a href="index.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Retour à l'accueil
        </a>
        
        <div class="form-container">
            <div class="form-header">
                <h1><i class="fas fa-sign-in-alt"></i> Connexion</h1>
                <p>Accédez à votre espace Gestion_Colis</p>
            </div>
            
            <div class="form-content">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div><?php echo htmlspecialchars((string) $_SESSION['error']); unset($_SESSION['error']); ?></div>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="login.php">
                    <?php echo csrf_field(); ?>
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
                                placeholder="Votre mot de passe"
                                class="form-control"
                            >
                            <button type="button" class="toggle-password" onclick="togglePassword('mot_de_passe')">
                                <i class="fas fa-eye" id="toggleIcon-mot_de_passe"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-sign-in-alt"></i>
                            Se connecter
                        </button>
                    </div>
                </form>
            </div>
            
            <div class="form-links">
                <p>Pas encore de compte ? 
                    <a href="register.php">S'inscrire</a>
                </p>
            </div>
        </div>
    </div>
    
    <script>
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
        
        document.getElementById('email').focus();
        
        console.log('%c🚀 Gestion_Colis - Connexion', 'color: #00B4D8; font-size: 16px; font-weight: bold;');
    </script>
</body>
</html>
