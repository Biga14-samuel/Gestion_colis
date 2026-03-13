<?php
/**
 * Configuration du service d'emailing
 * 
 * Instructions de configuration :
 * 
 * 1. Pour Gmail :
 *    - method: 'smtp'
 *    - smtp_host: 'smtp.gmail.com'
 *    - smtp_port: 587
 *    - smtp_username: 'votre.email@gmail.com'
 *    - smtp_password: 'votre_mot_de_passe_app' (ou mot de passe d'application)
 *    - smtp_encryption: 'tls'
 * 
 * 2. Pour Sendinblue (Brevo) :
 *    - method: 'smtp'
 *    - smtp_host: 'smtp-relay.sendinblue.com'
 *    - smtp_port: 587
 *    - smtp_username: 'votre_clé_api'
 *    - smtp_password: 'votre_mot_de_passe'
 * 
 * 3. Pour Mailgun :
 *    - method: 'smtp'
 *    - smtp_host: 'smtp.mailgun.org'
 *    - smtp_port: 587
 *    - smtp_username: 'postmaster@votre_domaine.mailgun.org'
 *    - smtp_password: 'votre_mot_de_passe'
 * 
 * 4. Pour la fonction mail() native (serveur mutualisé) :
 *    - method: 'mail'
 * 
 * 5. Pour le développement (logs uniquement) :
 *    - method: 'log'
 */

// Détection automatique du mode
$isDevelopment = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']) || 
                 strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false;

return [
    // Méthode d'envoi : 'smtp', 'mail', 'sendmail', 'log'
    // En développement, on utilise 'log' pour ne pas générer d'erreurs
    'method' => $isDevelopment ? 'log' : 'mail',
    
    // Configuration SMTP (si method = 'smtp')
    'smtp' => [
        'host' => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
        'port' => (int)(getenv('SMTP_PORT') ?: 587),
        'username' => getenv('SMTP_USERNAME') ?: '',
        'password' => getenv('SMTP_PASSWORD') ?: '',
        'encryption' => getenv('SMTP_ENCRYPTION') ?: 'tls',
        'timeout' => 30
    ],
    
    // Configuration de l'expéditeur
    'from' => [
        'email' => getenv('FROM_EMAIL') ?: 'noreply@gestioncolis.com',
        'name' => getenv('FROM_NAME') ?: 'Gestion_Colis'
    ],
    
    // Réponse
    'reply_to' => [
        'email' => getenv('REPLY_TO_EMAIL') ?: 'contact@gestioncolis.com',
        'name' => getenv('REPLY_TO_NAME') ?: 'Service Client Gestion_Colis'
    ],
    
    // Destinataire pour les emails de test
    'test_email' => getenv('TEST_EMAIL') ?: 'admin@gestioncolis.com',
    
    // Mode débogage (affiche les détails dans les logs)
    'debug' => $isDevelopment,
    
    // Limite d'emails par heure (protection anti-spam)
    'hourly_limit' => 50
];
