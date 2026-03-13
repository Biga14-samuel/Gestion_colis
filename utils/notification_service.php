<?php
/**
 * Service de Notifications (SMS/Email/Push)
 * Gestion_Colis v2.0
 */

require_once __DIR__ . '/../config/database.php';

class NotificationService {
    private $db;
    private $emailFrom = 'noreply@gestion-colis.com';
    private $emailFromName = 'Gestion_Colis';
    private $smsProvider = 'twilio'; // twilio, nexmo, ovh
    
    // Clés API (à configurer dans config/config.php)
    private $config = [
        'twilio' => [
            'sid' => '',
            'token' => '',
            'from' => '+33123456789'
        ],
        'nexmo' => [
            'key' => '',
            'secret' => '',
            'from' => 'gestion-colis'
        ]
    ];
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        
        // Charger la configuration
        $this->loadConfig();
    }
    
    /**
     * Charger la configuration
     */
    private function loadConfig() {
        $configFile = __DIR__ . '/../config/config.php';
        if (file_exists($configFile)) {
            $config = include $configFile;
            if (isset($config['notifications'])) {
                $this->config = array_merge($this->config, $config['notifications']);
            }
        }
    }
    
    /**
     * Envoyer une notification de colis disponible
     */
    public function notifyParcelReady($parcelId, $pickupCode) {
        $parcel = $this->getParcelWithDetails($parcelId);
        if (!$parcel) return false;
        
        $user = $this->getUserDetails($parcel['utilisateur_id']);
        
        $notifications = [];
        
        // Notification Email
        if ($user['email']) {
            $notifications['email'] = $this->sendEmail(
                $user['email'],
                'Votre colis est prêt pour retrait - ' . $parcel['code_tracking'],
                'parcel_ready',
                [
                    'user_name' => $user['prenom'] . ' ' . $user['nom'],
                    'tracking_code' => $parcel['code_tracking'],
                    'pickup_code' => $pickupCode,
                    'ibox_location' => $parcel['localisation'] ?? 'Non spécifié',
                    'description' => $parcel['description']
                ]
            );
        }
        
        // Notification SMS
        if ($user['tel']) {
            $notifications['sms'] = $this->sendSMS(
                $user['tel'],
                "Votre colis {$parcel['code_tracking']} est pret. Code de retrait: {$pickupCode}. Retirez-le dans l'iBox {$parcel['localisation']}"
            );
        }
        
        // Notification in-app
        $this->createInAppNotification(
            $parcel['utilisateur_id'],
            'colis',
            'Colis prêt pour retrait',
            "Votre colis {$parcel['code_tracking']} est disponible. Code: {$pickupCode}",
            'high'
        );
        
        return $notifications;
    }
    
    /**
     * Envoyer une notification de livraison
     */
    public function notifyDeliveryComplete($parcelId, $agentName) {
        $parcel = $this->getParcelWithDetails($parcelId);
        if (!$parcel) return false;
        
        $user = $this->getUserDetails($parcel['utilisateur_id']);
        
        // Notification Email
        if ($user['email']) {
            $this->sendEmail(
                $user['email'],
                'Votre colis a été livré - ' . $parcel['code_tracking'],
                'delivery_complete',
                [
                    'user_name' => $user['prenom'] . ' ' . $user['nom'],
                    'tracking_code' => $parcel['code_tracking'],
                    'agent_name' => $agentName,
                    'delivery_date' => date('d/m/Y à H:i')
                ]
            );
        }
        
        // Notification in-app
        $this->createInAppNotification(
            $parcel['utilisateur_id'],
            'livraison',
            'Colis livré',
            "Votre colis {$parcel['code_tracking']} a été livré par {$agentName}",
            'normal'
        );
        
        return true;
    }
    
    /**
     * Envoyer une notification de commission agent
     */
    public function notifyAgentCommission($agentId, $amount) {
        $agent = $this->getAgentDetails($agentId);
        if (!$agent) return false;
        
        $user = $this->getUserDetails($agent['utilisateur_id']);
        
        // Notification in-app
        $this->createInAppNotification(
            $agent['utilisateur_id'],
            'paiement',
            'Commission reçue',
            "Vous avez reçu une commission de {$amount} FCFA",
            'high'
        );
        
        return true;
    }
    
    /**
     * Envoyer un code de retrait par Email
     */
    public function sendPickupCodeEmail($email, $code, $trackingCode, $iboxLocation) {
        $subject = 'Votre code de retrait - ' . $trackingCode;
        
        $htmlBody = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: 'Segoe UI', Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #00B4D8, #0096C7); padding: 30px; text-align: center; color: white; }
                .content { padding: 30px; }
                .code-box { background: #f8f9fa; border: 2px dashed #00B4D8; padding: 20px; text-align: center; border-radius: 10px; margin: 20px 0; }
                .code { font-size: 32px; font-weight: bold; color: #00B4D8; letter-spacing: 5px; }
                .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>📦 Gestion_Colis</h1>
                    <p>Votre colis est prêt !</p>
                </div>
                <div class='content'>
                    <h2>Bonjour,</h2>
                    <p>Votre colis <strong>{$trackingCode}</strong> est disponible pour retrait.</p>
                    
                    <div class='code-box'>
                        <p style='margin: 0 0 10px 0; color: #666;'>Votre code de retrait :</p>
                        <p class='code'>{$code}</p>
                    </div>
                    
                    <p><strong>Point de retrait :</strong> {$iboxLocation}</p>
                    <p><em>Présentez ce code ou le QR code lors de votre passage pour récupérer votre colis.</em></p>
                </div>
                <div class='footer'>
                    <p>Ce message a été envoyé par Gestion_Colis</p>
                    <p>Pour toute question, contactez le support</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        return $this->sendEmailRaw($email, $subject, $htmlBody);
    }
    
    /**
     * Envoyer un SMS
     */
    public function sendSMS($phoneNumber, $message) {
        if (empty($this->config[$this->smsProvider]['sid'])) {
            // Mode simulation si pas de clé API
            error_log("[SMS SIMULATION] À {$phoneNumber}: {$message}");
            return ['success' => true, 'simulated' => true, 'message' => $message];
        }
        
        switch ($this->smsProvider) {
            case 'twilio':
                return $this->sendTwilioSMS($phoneNumber, $message);
            case 'nexmo':
                return $this->sendNexmoSMS($phoneNumber, $message);
            default:
                return $this->sendTwilioSMS($phoneNumber, $message);
        }
    }
    
    /**
     * Envoyer via Twilio
     */
    private function sendTwilioSMS($to, $message) {
        $sid = $this->config['twilio']['sid'];
        $token = $this->config['twilio']['token'];
        $from = $this->config['twilio']['from'];
        
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
        
        $data = [
            'To' => $to,
            'From' => $from,
            'Body' => $message
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_USERPWD, "{$sid}:{$token}");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 201) {
            return ['success' => true, 'response' => json_decode($response, true)];
        }
        
        error_log("[TWILIO ERROR] " . $response);
        return ['success' => false, 'error' => $response];
    }
    
    /**
     * Envoyer via Nexmo
     */
    private function sendNexmoSMS($to, $message) {
        $key = $this->config['nexmo']['key'];
        $secret = $this->config['nexmo']['secret'];
        $from = $this->config['nexmo']['from'];
        
        $url = "https://rest.nexmo.com/sms/json";
        
        $data = [
            'api_key' => $key,
            'api_secret' => $secret,
            'to' => $to,
            'from' => $from,
            'text' => $message
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $result = json_decode($response, true);
        
        if ($result['messages'][0]['status'] === '0') {
            return ['success' => true, 'response' => $result];
        }
        
        error_log("[NEXMO ERROR] " . $response);
        return ['success' => false, 'error' => $result];
    }
    
    /**
     * Envoyer un Email avec template
     */
    public function sendEmail($to, $subject, $templateName, $data = []) {
        $templates = [
            'parcel_ready' => $this->getParcelReadyTemplate(),
            'delivery_complete' => $this->getDeliveryCompleteTemplate(),
            'mfa_code' => $this->getMFATemplate()
        ];
        
        $template = $templates[$templateName] ?? $this->getDefaultTemplate();
        
        // Remplacer les variables
        foreach ($data as $key => $value) {
            $template = str_replace('{{' . $key . '}}', $value, $template);
        }
        
        return $this->sendEmailRaw($to, $subject, $template);
    }
    
    /**
     * Envoyer un Email brut
     */
    private function sendEmailRaw($to, $subject, $htmlBody) {
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->emailFromName . ' <' . $this->emailFrom . '>',
            'Reply-To: ' . $this->emailFrom,
            'X-Mailer: PHP/' . phpversion()
        ];
        
        if (mail($to, $subject, $htmlBody, implode("\r\n", $headers))) {
            return ['success' => true];
        }
        
        error_log("[EMAIL ERROR] Failed to send to {$to}");
        return ['success' => false];
    }
    
    /**
     * Créer une notification in-app
     */
    public function createInAppNotification($userId, $type, $title, $message, $priority = 'normal') {
        $stmt = $this->db->prepare("
            INSERT INTO notifications (utilisateur_id, type, titre, message, priorite, date_envoi)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        try {
            $stmt->execute([$userId, $type, $title, $message, $priority]);
            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("[NOTIFICATION ERROR] " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Récupérer les notifications d'un utilisateur
     */
    public function getUserNotifications($userId, $limit = 50, $unreadOnly = false) {
        $sql = "SELECT * FROM notifications WHERE utilisateur_id = ?";
        if ($unreadOnly) {
            $sql .= " AND lue = FALSE";
        }
        $sql .= " ORDER BY date_envoi DESC LIMIT ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    }
    
    /**
     * Marquer une notification comme lue
     */
    public function markAsRead($notificationId, $userId) {
        $stmt = $this->db->prepare("
            UPDATE notifications 
            SET lue = TRUE, date_lecture = NOW() 
            WHERE id = ? AND utilisateur_id = ?
        ");
        return $stmt->execute([$notificationId, $userId]);
    }
    
    /**
     * Récupérer les détails du colis
     */
    private function getParcelWithDetails($parcelId) {
        $stmt = $this->db->prepare("
            SELECT c.*, i.localisation 
            FROM colis c 
            LEFT JOIN ibox i ON c.ibox_id = i.id 
            WHERE c.id = ?
        ");
        $stmt->execute([$parcelId]);
        return $stmt->fetch();
    }
    
    /**
     * Récupérer les détails utilisateur
     */
    private function getUserDetails($userId) {
        $stmt = $this->db->prepare("SELECT * FROM utilisateurs WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }
    
    /**
     * Récupérer les détails agent
     */
    private function getAgentDetails($agentId) {
        $stmt = $this->db->prepare("SELECT * FROM agents WHERE id = ?");
        $stmt->execute([$agentId]);
        return $stmt->fetch();
    }
    
    // Templates Email
    private function getParcelReadyTemplate() {
        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: "Segoe UI", Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #00B4D8, #0096C7); padding: 30px; text-align: center; color: white; }
                .content { padding: 30px; }
                .code-box { background: #f8f9fa; border: 2px dashed #00B4D8; padding: 20px; text-align: center; border-radius: 10px; margin: 20px 0; }
                .code { font-size: 32px; font-weight: bold; color: #00B4D8; letter-spacing: 5px; }
                .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; }
                .btn { display: inline-block; padding: 12px 24px; background: #00B4D8; color: white; text-decoration: none; border-radius: 6px; margin-top: 10px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>📦 Gestion_Colis</h1>
                    <p>Votre colis est prêt pour retrait !</p>
                </div>
                <div class="content">
                    <h2>Bonjour {{user_name}},</h2>
                    <p>Excellent news ! Votre colis <strong>{{tracking_code}}</strong> est maintenant disponible pour retrait.</p>
                    
                    <div class="code-box">
                        <p style="margin: 0 0 10px 0; color: #666;">Votre code de retrait :</p>
                        <p class="code">{{pickup_code}}</p>
                    </div>
                    
                    <p><strong>📍 Point de retrait :</strong> {{ibox_location}}</p>
                    <p><strong>📦 Contenu :</strong> {{description}}</p>
                    
                    <p><em>Présentez ce code lors de votre passage pour récupérer votre colis.</em></p>
                    
                    <center>
                        <a href="#" class="btn">Suivre mon colis</a>
                    </center>
                </div>
                <div class="footer">
                    <p>Ce message a été envoyé par Gestion_Colis</p>
                    <p>Pour toute question, contactez le support</p>
                </div>
            </div>
        </body>
        </html>
        ';
    }
    
    private function getDeliveryCompleteTemplate() {
        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: "Segoe UI", Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #10B981, #059669); padding: 30px; text-align: center; color: white; }
                .content { padding: 30px; }
                .success-icon { font-size: 64px; text-align: center; margin: 20px 0; }
                .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>✅ Colis Livré !</h1>
                    <p>Votre colis est bien arrivé à destination</p>
                </div>
                <div class="content">
                    <h2>Bonjour {{user_name}},</h2>
                    <div class="success-icon">📦✅</div>
                    <p>Votre colis <strong>{{tracking_code}}</strong> a été livré avec succès !</p>
                    
                    <p><strong>📅 Date de livraison :</strong> {{delivery_date}}</p>
                    <p><strong>🚚 Livreur :</strong> {{agent_name}}</p>
                    
                    <p>Nous espérons que votre expérience avec Gestion_Colis vous satisfait. N\'hésitez pas à noter le livreur !</p>
                </div>
                <div class="footer">
                    <p>Merci de faire confiance à Gestion_Colis</p>
                </div>
            </div>
        </body>
        </html>
        ';
    }
    
    private function getMFATemplate() {
        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: "Segoe UI", Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; }
                .header { background: #1E293B; padding: 30px; text-align: center; color: white; }
                .content { padding: 30px; text-align: center; }
                .code { font-size: 48px; font-weight: bold; color: #00B4D8; letter-spacing: 10px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>🔐 Vérification en deux étapes</h1>
                </div>
                <div class="content">
                    <h2>Entrez ce code pour vous connecter :</h2>
                    <p class="code">{{code}}</p>
                    <p style="color: #666;">Ce code expire dans 10 minutes.</p>
                </div>
            </div>
        </body>
        </html>
        ';
    }
    
    private function getDefaultTemplate() {
        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: "Segoe UI", Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; padding: 30px; }
            </style>
        </head>
        <body>
            <div class="container">
                {{message}}
            </div>
        </body>
        </html>
        ';
    }
}
