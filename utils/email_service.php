<?php
/**
 * Classe d'envoi d'emails simplifiée et autonome
 * Compatible avec SMTP et la fonction mail() native
 * Version améliorée avec meilleure gestion des erreurs
 */

class EmailService {
    private $config;
    private $lastError = '';
    private $logFile = '';
    
    public function __construct($config = null) {
        // Configuration par défaut
        $this->config = $config ?? [
            'method' => 'mail', // 'smtp', 'mail', 'sendmail', 'log'
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => 587,
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_encryption' => 'tls',
            'from_email' => 'noreply@gestioncolis.com',
            'from_name' => 'Gestion_Colis',
            'reply_to' => 'contact@gestioncolis.com',
            'debug' => false
        ];
        
        // Initialiser le fichier de log
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $this->logFile = $logDir . '/email_log.txt';
    }
    
    /**
     * Logger un message
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] $message\n";
        @file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
        
        if ($this->config['debug']) {
            echo $logEntry;
        }
    }
    
    /**
     * Envoyer un email
     */
    public function send($to, $subject, $body, $altBody = '', $attachments = []) {
        $this->lastError = '';
        
        // Valider l'adresse email
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->lastError = "Adresse email invalide: $to";
            $this->log("ERREUR: " . $this->lastError);
            return false;
        }
        
        $this->log("Tentative d'envoi à: $to - Sujet: $subject");
        
        try {
            switch ($this->config['method']) {
                case 'smtp':
                    $result = $this->sendViaSMTP($to, $subject, $body, $altBody, $attachments);
                    break;
                case 'sendmail':
                    $result = $this->sendViaSendmail($to, $subject, $body);
                    break;
                case 'log':
                    $result = $this->sendViaLog($to, $subject, $body);
                    break;
                case 'mail':
                default:
                    $result = $this->sendViaMail($to, $subject, $body);
                    break;
            }
            
            if ($result) {
                $this->log("SUCCÈS: Email envoyé à $to");
            } else {
                $this->log("ÉCHEC: " . $this->lastError);
            }
            
            return $result;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            $this->log("EXCEPTION: " . $this->lastError);
            return false;
        }
    }
    
    /**
     * Envoyer via la fonction mail() native de PHP
     */
    private function sendViaMail($to, $subject, $body) {
        // Encoder le sujet en UTF-8
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        
        // Construire les entêtes
        $headers = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'From: ' . $this->config['from_name'] . ' <' . $this->config['from_email'] . '>';
        $headers[] = 'Reply-To: ' . $this->config['reply_to'];
        $headers[] = 'X-Mailer: PHP/' . phpversion();
        $headers[] = 'X-Priority: 1';
        $headers[] = 'Importance: High';
        
        $headerString = implode("\r\n", $headers);
        
        // Ajouter des entêtes de réception pour améliorer la délivrabilité
        $headerString .= "\r\nReturn-Path: " . $this->config['from_email'];
        
        $result = @mail($to, $encodedSubject, $body, $headerString);
        
        if (!$result) {
            $error = error_get_last();
            $this->lastError = 'Erreur fonction mail(): ' . ($error ? $error['message'] : 'Erreur inconnue');
        }
        
        return $result;
    }
    
    /**
     * Envoyer via SMTP (implémentation basique)
     */
    private function sendViaSMTP($to, $subject, $body, $altBody, $attachments) {
        // Vérifier que les paramètres SMTP sont configurés
        if (empty($this->config['smtp_host']) || empty($this->config['smtp_username'])) {
            // Fallback vers la fonction mail() si SMTP non configuré
            return $this->sendViaMail($to, $subject, $body);
        }
        
        // Créer la connexion socket SMTP
        $socket = @fsockopen(
            ($this->config['smtp_encryption'] === 'ssl' ? 'ssl://' : '') . $this->config['smtp_host'],
            $this->config['smtp_port'],
            $errno,
            $errstr,
            30
        );
        
        if (!$socket) {
            $this->lastError = "Erreur de connexion SMTP: $errstr ($errno)";
            return $this->sendViaMail($to, $subject, $body);
        }
        
        // Lire la réponse du serveur
        $response = fgets($socket, 515);
        
        // Commandes SMTP
        $commands = [
            'EHLO ' . gethostname(),
            'AUTH LOGIN',
            base64_encode($this->config['smtp_username']),
            base64_encode($this->config['smtp_password']),
            'MAIL FROM:<' . $this->config['from_email'] . '>',
            'RCPT TO:<' . $to . '>',
            'DATA'
        ];
        
        foreach ($commands as $cmd) {
            fputs($socket, $cmd . "\r\n");
            $response = fgets($socket, 515);
            
            if ($this->config['debug']) {
                echo "SMTP: $cmd -> $response";
            }
        }
        
        // Construire le message
        $boundary = uniqid('BOUNDARY_');
        $message = "From: {$this->config['from_name']} <{$this->config['from_email']}>\r\n";
        $message .= "To: $to\r\n";
        $message .= "Subject: $subject\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n\r\n";
        
        $message .= "--$boundary\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= base64_encode($altBody) . "\r\n\r\n";
        
        $message .= "--$boundary\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= base64_encode($body) . "\r\n\r\n";
        
        $message .= "--$boundary--\r\n.\r\n";
        
        fputs($socket, $message);
        $response = fgets($socket, 515);
        
        fputs($socket, "QUIT\r\n");
        fclose($socket);
        
        return strpos($response, '250') !== false;
    }
    
    /**
     * Envoyer via Sendmail
     */
    private function sendViaSendmail($to, $subject, $body) {
        $sendmailPath = ini_get('sendmail_path');
        if (empty($sendmailPath)) {
            $sendmailPath = '/usr/sbin/sendmail';
        }
        $sendmail = escapeshellcmd($sendmailPath);
        $fromEmail = escapeshellarg($this->config['from_email']);
        
        $headers = $this->buildHeaders();
        $subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        
        $pipes = [];
        $process = proc_open(
            "$sendmail -t -f $fromEmail",
            ['w' => ['pipe', 'w']],
            $pipes
        );
        
        if (!is_resource($process)) {
            $this->lastError = 'Impossible d\'ouvrir le processus sendmail';
            return $this->sendViaMail($to, $subject, $body);
        }
        
        fwrite($pipes[0], "To: $to\r\n");
        fwrite($pipes[0], "Subject: $subject\r\n");
        fwrite($pipes[0], $headers);
        fwrite($pipes[0], "\r\n");
        fwrite($pipes[0], $body);
        
        fclose($pipes[0]);
        proc_close($process);
        
        return true;
    }
    
    /**
     * Logger l'email (pour développement)
     */
    private function sendViaLog($to, $subject, $body) {
        $logDir = __DIR__ . '/../../logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $logFile = $logDir . '/emails.log';
        $logEntry = str_repeat('=', 50) . "\n";
        $logEntry .= "Date: " . date('Y-m-d H:i:s') . "\n";
        $logEntry .= "To: $to\n";
        $logEntry .= "Subject: $subject\n";
        $logEntry .= "Body: " . substr($body, 0, 500) . "...\n";
        $logEntry .= str_repeat('=', 50) . "\n\n";
        
        file_put_contents($logFile, $logEntry, FILE_APPEND);
        
        return true;
    }
    
    /**
     * Construire les entêtes de base
     */
    private function buildHeaders() {
        return "From: {$this->config['from_name']} <{$this->config['from_email']}>\r\n" .
               "Reply-To: {$this->config['reply_to']}\r\n" .
               "MIME-Version: 1.0\r\n" .
               "Content-Type: text/html; charset=UTF-8\r\n" .
               "X-Mailer: Gestion_Colis/" . (defined('APP_VERSION') ? APP_VERSION : '1.0');
    }
    
    /**
     * Envoyer un email avec le template Postal ID
     */
    public function sendPostalID($to, $userName, $postalCode, $idType, $idNumber, $expirationDate = null) {
        $subject = 'Votre Postal ID - Gestion_Colis';
        
        $expirationText = $expirationDate ? 
            "<p><strong>Expire le:</strong> " . date('d/m/Y', strtotime($expirationDate)) . "</p>" : 
            "";
        
        $body = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Votre Postal ID</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; background-color: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #00B4D8, #00A8FF); color: white; padding: 30px 20px; text-align: center; border-radius: 12px 12px 0 0; }
        .header h1 { font-size: 24px; margin-bottom: 5px; }
        .header p { font-size: 14px; opacity: 0.9; }
        .content { background: #ffffff; padding: 30px; border: 1px solid #e2e8f0; border-top: none; }
        .greeting { margin-bottom: 20px; }
        .greeting p { font-size: 16px; }
        .postal-card { background: linear-gradient(135deg, rgba(0, 180, 216, 0.05), rgba(0, 168, 255, 0.05)); border: 2px solid #00B4D8; border-radius: 12px; padding: 25px; margin: 25px 0; text-align: center; }
        .postal-card h2 { color: #00B4D8; font-size: 14px; text-transform: uppercase; letter-spacing: 2px; margin-bottom: 15px; }
        .postal-code { font-family: 'Courier New', monospace; font-size: 28px; font-weight: bold; color: #00B4D8; letter-spacing: 4px; margin: 15px 0; padding: 15px; background: rgba(0, 180, 216, 0.1); border-radius: 8px; }
        .info-table { width: 100%; margin: 20px 0; }
        .info-table tr { border-bottom: 1px solid #e2e8f0; }
        .info-table td { padding: 12px 0; }
        .info-table td:first-child { color: #64748b; width: 40%; }
        .info-table td:last-child { font-weight: 600; color: #0f172a; }
        .instructions { background: #f8fafc; border-radius: 8px; padding: 20px; margin-top: 20px; }
        .instructions h3 { color: #00B4D8; font-size: 16px; margin-bottom: 10px; }
        .instructions ul { margin-left: 20px; }
        .instructions li { margin: 8px 0; color: #64748b; }
        .footer { text-align: center; padding: 25px 20px; color: #94a3b8; font-size: 13px; }
        .footer p { margin: 5px 0; }
        .divider { height: 1px; background: linear-gradient(90deg, transparent, #e2e8f0, transparent); margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎉 Votre Postal ID</h1>
            <p>Gestion_Colis - Service de livraison</p>
        </div>
        <div class="content">
            <div class="greeting">
                <p>Bonjour <strong>{$userName}</strong>,</p>
            </div>
            <p>Votre Postal ID a été créé avec succès ! Voici vos informations pour recevoir des colis dans nos points de retrait partenaires :</p>
            
            <div class="postal-card">
                <h2>Code Postal ID</h2>
                <div class="postal-code">{$postalCode}</div>
                <table class="info-table">
                    <tr>
                        <td>Type de pièce</td>
                        <td>{$idType}</td>
                    </tr>
                    <tr>
                        <td>Numéro de pièce</td>
                        <td>{$idNumber}</td>
                    </tr>
                    {$expirationText}
                </table>
            </div>
            
            <div class="instructions">
                <h3>📋 Comment utiliser votre Postal ID ?</h3>
                <ul>
                    <li>Présentez ce code lors de vos retraits dans les points partenaires</li>
                    <li>Ce code est unique et personnel - ne le partagez qu'avec les personnes de confiance</li>
                    <li>Vérifiez régulièrement la date d'expiration de vos documents</li>
                    <li>En cas de perte, contactez notre service client</li>
                </ul>
            </div>
        </div>
        <div class="footer">
            <p>📦 Gestion_Colis - Votre partenaire de livraison</p>
            <p>© 2024 Tous droits réservés</p>
            <p>Cet email a été envoyé à {$to}</p>
        </div>
    </div>
</body>
</html>
HTML;
        
        $altBody = <<<TEXT
Bonjour {$userName},

Votre Postal ID - Gestion_Colis

Votre code Postal ID: {$postalCode}

Type de pièce: {$idType}
Numéro de pièce: {$idNumber}
{$expirationText}

Comment l'utiliser ?
- Présentez ce code lors de vos retraits dans les points partenaires
- Ce code est unique et personnel
- Ne le partagez qu'avec les personnes de confiance

© 2024 Gestion_Colis
TEXT;
        
        return $this->send($to, $subject, $body, $altBody);
    }
    
    /**
     * Envoyer un email de notification générique
     */
    public function sendNotification($to, $title, $message, $buttonText = '', $buttonUrl = '') {
        $subject = $title . ' - Gestion_Colis';
        
        $buttonHtml = '';
        if ($buttonText && $buttonUrl) {
            $buttonHtml = <<<HTML
<div style="text-align: center; margin-top: 25px;">
    <a href="{$buttonUrl}" style="display: inline-block; padding: 14px 30px; background: linear-gradient(135deg, #00B4D8, #00A8FF); color: white; text-decoration: none; border-radius: 8px; font-weight: 600;">
        {$buttonText}
    </a>
</div>
HTML;
        }
        
        $body = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; background-color: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #00B4D8, #00A8FF); color: white; padding: 30px 20px; text-align: center; border-radius: 12px 12px 0 0; }
        .header h1 { font-size: 24px; }
        .content { background: #ffffff; padding: 30px; border: 1px solid #e2e8f0; border-top: none; }
        .message-box { background: #f8fafc; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .footer { text-align: center; padding: 25px 20px; color: #94a3b8; font-size: 13px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎉 {$title}</h1>
        </div>
        <div class="content">
            <div class="message-box">
                {$message}
            </div>
            {$buttonHtml}
        </div>
        <div class="footer">
            <p>© 2024 Gestion_Colis</p>
        </div>
    </div>
</body>
</html>
HTML;
        
        return $this->send($to, $subject, $body);
    }
    
    /**
     * Obtenir la dernière erreur
     */
    public function getLastError() {
        return $this->lastError;
    }
    
    /**
     * Tester la configuration email
     */
    public function testConnection($testEmail) {
        $result = $this->send(
            $testEmail,
            'Test de configuration - Gestion_Colis',
            '<p>Votre configuration email fonctionne correctement !</p>',
            'Votre configuration email fonctionne correctement !'
        );
        
        return $result;
    }
}
