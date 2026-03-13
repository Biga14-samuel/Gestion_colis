<?php
require_once __DIR__ . '/utils/session.php';
SessionManager::start();
require_once 'config/database.php';
require_once 'utils/mfa_service.php';

$pendingUserId = (int) ($_SESSION['mfa_pending_user_id'] ?? 0);
$pendingCreated = (int) ($_SESSION['mfa_pending_created_at'] ?? 0);
$pendingEmail = $_SESSION['mfa_pending_email'] ?? '';
$ttlSeconds = 600;

if ($pendingUserId <= 0 || $pendingCreated <= 0 || (time() - $pendingCreated) > $ttlSeconds) {
    unset($_SESSION['mfa_pending_user_id'], $_SESSION['mfa_pending_created_at'], $_SESSION['mfa_pending_email']);
    header('Location: login.php');
    exit;
}

$database = new Database();
$db = $database->getConnection();

$stmt = $db->prepare("SELECT id, email, prenom, nom, role, theme_preference, mfa_secret, mfa_active, mfa_enabled FROM utilisateurs WHERE id = ? LIMIT 1");
$stmt->execute([$pendingUserId]);
$user = $stmt->fetch();

if (!$user) {
    unset($_SESSION['mfa_pending_user_id'], $_SESSION['mfa_pending_created_at'], $_SESSION['mfa_pending_email']);
    header('Location: login.php');
    exit;
}

$mfaEnabled = !empty($user['mfa_active']) || !empty($user['mfa_enabled']);
if (!$mfaEnabled || empty($user['mfa_secret'])) {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_nom'] = $user['nom'];
    $_SESSION['user_prenom'] = $user['prenom'];
    $_SESSION['theme_preference'] = $user['theme_preference'] ?? 'light';
    unset($_SESSION['mfa_pending_user_id'], $_SESSION['mfa_pending_created_at'], $_SESSION['mfa_pending_email']);
    header('Location: dashboard.php');
    exit;
}

$message = '';
$messageType = 'error';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['mfa_code'] ?? '');
    if ($code === '') {
        $message = 'Veuillez entrer le code de vérification.';
    } else {
        $mfaService = new MFAService();
        $verified = $mfaService->verifyCode($pendingUserId, $code);
        if ($verified) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_nom'] = $user['nom'];
            $_SESSION['user_prenom'] = $user['prenom'];
            $_SESSION['theme_preference'] = $user['theme_preference'] ?? 'light';
            unset($_SESSION['mfa_pending_user_id'], $_SESSION['mfa_pending_created_at'], $_SESSION['mfa_pending_email']);
            header('Location: dashboard.php');
            exit;
        }

        $message = 'Code MFA invalide. Veuillez réessayer.';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vérification MFA - Gestion_Colis</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Orbitron:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background: #F8FAFC; font-family: 'Inter', sans-serif; margin: 0; }
        .mfa-shell { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem; }
        .mfa-card { background: #fff; border-radius: 16px; padding: 2rem; width: min(420px, 100%); box-shadow: 0 20px 50px rgba(15,23,42,0.08); border: 1px solid rgba(15,23,42,0.08); }
        .mfa-card h1 { margin: 0 0 0.75rem; font-size: 1.4rem; }
        .mfa-card p { margin: 0 0 1.5rem; color: #475569; }
        .mfa-card label { display: block; font-weight: 600; margin-bottom: 0.5rem; }
        .mfa-card input { width: 100%; padding: 0.75rem 1rem; border: 1px solid rgba(15,23,42,0.15); border-radius: 10px; font-size: 1rem; }
        .mfa-card button { margin-top: 1rem; width: 100%; padding: 0.75rem 1rem; border: none; border-radius: 10px; background: #00B4D8; color: #fff; font-weight: 600; cursor: pointer; }
        .mfa-alert { margin-bottom: 1rem; padding: 0.75rem 1rem; border-radius: 10px; background: rgba(239,68,68,0.1); color: #b91c1c; }
    </style>
</head>
<body>
    <div class="mfa-shell">
        <div class="mfa-card">
            <h1><i class="fas fa-shield-alt" style="color:#00B4D8;"></i> Vérification MFA</h1>
            <p>Entrez le code de votre application d’authentification pour continuer. <strong><?php echo htmlspecialchars((string) $pendingEmail); ?></strong></p>

            <?php if ($message): ?>
                <div class="mfa-alert"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <form method="post">
                <?php echo csrf_field(); ?>
                <label for="mfa_code">Code MFA</label>
                <input id="mfa_code" name="mfa_code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" required>
                <button type="submit">Valider</button>
            </form>
        </div>
    </div>
</body>
</html>
