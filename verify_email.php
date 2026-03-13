<?php
require_once __DIR__ . '/utils/session.php';
SessionManager::start();
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$token = trim($_GET['token'] ?? '');
$message = '';
$messageType = 'error';

if (empty($token)) {
    $message = "Lien de vérification invalide.";
} else {
    try {
        $tokenHash = hash('sha256', $token);
        $stmt = $db->prepare("
            SELECT id, email_verifie, email_verification_sent_at
            FROM utilisateurs
            WHERE email_verification_token = ?
            LIMIT 1
        ");
        $stmt->execute([$tokenHash]);
        $user = $stmt->fetch();

        if (!$user) {
            $message = "Lien de vérification invalide ou expiré.";
        } elseif (!empty($user['email_verifie'])) {
            $message = "Votre email est déjà vérifié.";
            $messageType = 'success';
        } elseif (empty($user['email_verification_sent_at']) || strtotime($user['email_verification_sent_at']) < strtotime('-48 hours')) {
            $message = "Lien de vérification expiré. Veuillez demander un nouveau lien depuis la page de connexion.";
        } else {
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("
                    UPDATE utilisateurs
                    SET email_verifie = 1,
                        email_verification_token = NULL,
                        email_verified_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$user['id']]);

                $stmt = $db->prepare("
                    SELECT identifiant_postal
                    FROM postal_id
                    WHERE utilisateur_id = ?
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $stmt->execute([$user['id']]);
                $postal = $stmt->fetch();

                if (!$postal) {
                    $postal_id_code = 'PID' . strtoupper(bin2hex(random_bytes(6)));
                    $qr_data = json_encode([
                        'ver' => 1,
                        'code' => $postal_id_code,
                        'created' => date('Y-m-d')
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    $stmt = $db->prepare("
                        INSERT INTO postal_id (utilisateur_id, identifiant_postal, niveau_securite, date_expiration, qr_code_data, actif)
                        VALUES (?, ?, 'basic', DATE_ADD(NOW(), INTERVAL 2 YEAR), ?, 1)
                    ");
                    $stmt->execute([$user['id'], $postal_id_code, $qr_data]);
                    $postal = ['identifiant_postal' => $postal_id_code];
                }

                $db->commit();

                $message = "Votre email a été vérifié avec succès. Vous pouvez vous connecter.";
                if (!empty($postal['identifiant_postal'])) {
                    $message .= " Votre Postal ID: " . $postal['identifiant_postal'] . ".";
                }
                $messageType = 'success';
            } catch (Exception $e) {
                $db->rollBack();
                $message = user_error_message($e, 'verify_email', "Erreur lors de la vérification. Veuillez réessayer.");
            }
        }
    } catch (Exception $e) {
        $message = user_error_message($e, 'verify_email', "Erreur lors de la vérification. Veuillez réessayer.");
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vérification Email - Gestion_Colis</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body style="font-family: 'Rajdhani', sans-serif; background: #F8FAFC; display:flex; align-items:center; justify-content:center; min-height:100vh; padding:2rem;">
    <div style="max-width:520px; width:100%; background:#fff; border:1px solid rgba(0,0,0,0.08); border-radius:16px; padding:2rem; text-align:center;">
        <h1 style="margin-bottom:1rem;">Vérification Email</h1>
        <p style="color:#334155; margin-bottom:1.5rem;"><?php echo htmlspecialchars($message); ?></p>
        <a href="login.php" style="display:inline-block; padding:0.75rem 1.5rem; background:#00B4D8; color:#000; text-decoration:none; border-radius:10px; font-weight:600;">
            Aller à la connexion
        </a>
    </div>
</body>
</html>
