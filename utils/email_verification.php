<?php
/**
 * Email verification helper
 */

require_once __DIR__ . '/email_service.php';

function build_base_url(): string {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . '://' . $host;
}

function send_verification_email(string $email, string $name, string $token): bool {
    $emailConfig = [];
    $configFile = __DIR__ . '/../config/email_config.php';
    if (file_exists($configFile)) {
        $emailConfig = require $configFile;
    }

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

    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $verifyUrl = build_base_url() . '/verify_email.php?token=' . urlencode($token);

    $subject = 'Vérification de votre email';
    $body = "
        <p>Bonjour {$safeName},</p>
        <p>Merci pour votre inscription. Pour activer votre compte, veuillez vérifier votre adresse email en cliquant sur le lien ci-dessous :</p>
        <p><a href=\"{$verifyUrl}\">Vérifier mon email</a></p>
        <p>Si vous n'êtes pas à l'origine de cette demande, vous pouvez ignorer cet email.</p>
    ";

    $altBody = "Bonjour {$name},\n\n" .
        "Merci pour votre inscription. Pour activer votre compte, ouvrez ce lien : {$verifyUrl}\n\n" .
        "Si vous n'êtes pas à l'origine de cette demande, ignorez cet email.";

    return $emailService->send($email, $subject, $body, $altBody);
}
