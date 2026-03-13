<?php
/**
 * Service de Codes de Retrait (PIN/QR)
 * Gestion_Colis v2.0 - Module iBox
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/notification_service.php';

class PickupCodeService {
    private $db;
    private $notificationService;
    
    // Configuration
    private $config = [
        'code_length' => 6,
        'code_expiry_hours' => 72,     // 3 jours
        'max_attempts' => 3,
        'allow_multiple_uses' => false
    ];
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->notificationService = new NotificationService();
        
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
            if (isset($config['pickup_codes'])) {
                $this->config = array_merge($this->config, $config['pickup_codes']);
            }
        }
    }
    
    /**
     * Générer un code de retrait pour un colis
     */
    public function generateCode($parcelId, $type = 'pin', $notifyUser = true) {
        // Vérifier si un code existe déjà et est valide
        $existingCode = $this->getValidCode($parcelId);
        if ($existingCode) {
            return [
                'success' => true,
                'code' => $existingCode['code_pin'],
                'qr_code' => $existingCode['qr_code_data'],
                'existing' => true,
                'expires_at' => $existingCode['expires_at']
            ];
        }
        
        // Récupérer les détails du colis
        $parcel = $this->getParcelDetails($parcelId);
        if (!$parcel) {
            return ['success' => false, 'error' => 'Colis non trouvé'];
        }
        
        // Générer le code PIN
        $pin = $this->generatePIN();
        $codeHash = password_hash($pin, PASSWORD_DEFAULT);
        
        // Générer les données QR si nécessaire
        $qrData = null;
        if ($type === 'qr' || $type === 'both') {
            $qrData = $this->generateQRData($parcelId, $pin, $parcel['code_tracking']);
        }
        
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$this->config['code_expiry_hours']} hours"));
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO pickup_codes (
                    colis_id, ibox_id, code_pin, code_hash, type_code,
                    qr_code_data, expires_at, actif, date_creation
                ) VALUES (?, ?, ?, ?, ?, ?, ?, TRUE, NOW())
            ");
            
            $stmt->execute([
                $parcelId,
                $parcel['ibox_id'],
                $pin,
                $codeHash,
                $type === 'qr' ? 'qr' : 'pin',
                $qrData
            ]);
            
            $codeId = $this->db->lastInsertId();
            
            // Envoyer la notification si demandé
            if ($notifyUser) {
                $this->sendPickupNotifications($parcel, $pin);
            }
            
            return [
                'success' => true,
                'code_id' => $codeId,
                'code' => $pin,
                'qr_code' => $qrData,
                'expires_at' => $expiresAt,
                'existing' => false
            ];
            
        } catch (PDOException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Vérifier et utiliser un code de retrait
     */
    public function verifyAndUseCode($parcelId, $code, $userId = null) {
        // Récupérer le code valide
        $stmt = $this->db->prepare("
            SELECT * FROM pickup_codes 
            WHERE colis_id = ? AND actif = TRUE 
            AND expires_at > NOW() 
            ORDER BY date_creation DESC 
            LIMIT 1
        ");
        $stmt->execute([$parcelId]);
        $pickupCode = $stmt->fetch();
        
        if (!$pickupCode) {
            return [
                'success' => false,
                'error' => 'Code invalide ou expiré',
                'attempts_remaining' => 0
            ];
        }
        
        // Vérifier si le code a été trop utilisé
        if ($pickupCode['nombre_utilisations'] >= $pickupCode['nombre_utilisations_max']) {
            return [
                'success' => false,
                'error' => 'Code déjà utilisé',
                'used_at' => $pickupCode['utilise_le']
            ];
        }
        
        // Vérifier le code
        if (password_verify($code, $pickupCode['code_hash'])) {
            // Marquer comme utilisé
            $this->markAsUsed($pickupCode['id']);
            
            // Logger l'accès
            $this->logAccess($pickupCode['id'], $userId, 'acces_autorise', true);
            
            return [
                'success' => true,
                'message' => 'Code valide - retrait autorisé',
                'code_type' => $pickupCode['type_code'],
                'ibox_id' => $pickupCode['ibox_id']
            ];
        }
        
        // Code incorrect - logger l'échec
        $this->logAccess($pickupCode['id'], $userId, 'acces_refuse', false);
        
        // Calculer les tentatives restantes
        $attemptsUsed = $pickupCode['nombre_utilisations'] + 1;
        $attemptsRemaining = max(0, $this->config['max_attempts'] - $attemptsUsed);
        
        if ($attemptsRemaining === 0) {
            // Désactiver le code après trop d'échecs
            $this->deactivateCode($pickupCode['id']);
            return [
                'success' => false,
                'error' => 'Trop de tentatives - code désactivé',
                'attempts_remaining' => 0
            ];
        }
        
        return [
            'success' => false,
            'error' => 'Code incorrect',
            'attempts_remaining' => $attemptsRemaining
        ];
    }
    
    /**
     * Régénérer un code de retrait
     */
    public function regenerateCode($parcelId, $userId) {
        // Désactiver l'ancien code
        $this->deactivateAllCodes($parcelId);
        
        // Générer un nouveau code
        return $this->generateCode($parcelId, 'pin', true);
    }
    
    /**
     * Désactiver un code
     */
    public function deactivateCode($codeId) {
        $stmt = $this->db->prepare("
            UPDATE pickup_codes 
            SET actif = FALSE 
            WHERE id = ?
        ");
        return $stmt->execute([$codeId]);
    }
    
    /**
     * Désactiver tous les codes d'un colis
     */
    private function deactivateAllCodes($parcelId) {
        $stmt = $this->db->prepare("
            UPDATE pickup_codes 
            SET actif = FALSE 
            WHERE colis_id = ? AND expires_at > NOW()
        ");
        return $stmt->execute([$parcelId]);
    }
    
    /**
     * Marquer un code comme utilisé
     */
    private function markAsUsed($codeId) {
        $stmt = $this->db->prepare("
            UPDATE pickup_codes 
            SET nombre_utilisations = nombre_utilisations + 1,
                utilise_le = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$codeId]);
        
        // Désactiver si utilisation unique
        $stmt = $this->db->prepare("
            UPDATE pickup_codes 
            SET actif = FALSE 
            WHERE id = ? AND nombre_utilisations >= nombre_utilisations_max
        ");
        $stmt->execute([$codeId]);
    }
    /**
     * Logger un accès
     */
    private function logAccess($codeId, $userId, $action, $success) {
        $stmt = $this->db->prepare("
            INSERT INTO ibox_access_logs (
                ibox_id, utilisateur_id, agent_id, action, 
                code_utilise, ip_address, details, date_action
            )
            SELECT pc.ibox_id, ?, NULL, ?, ?, ?, NOW()
            FROM pickup_codes pc
            WHERE pc.id = ?
        ");
        
        $stmt->execute([
            $userId,
            $action,
            $success ? $this->getCodeDisplay($codeId) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $codeId
        ]);
    }
    
    /**
     * Récupérer le code affiché (masqué)
     */
    private function getCodeDisplay($codeId) {
        $stmt = $this->db->prepare("SELECT code_pin FROM pickup_codes WHERE id = ?");
        $stmt->execute([$codeId]);
        $code = $stmt->fetch()['code_pin'];
        return substr($code, 0, 2) . '****' . substr($code, -2);
    }
    
    /**
     * Récupérer les détails du colis
     */
    private function getParcelDetails($parcelId) {
        $stmt = $this->db->prepare("
            SELECT c.*, i.localisation, i.code_box 
            FROM colis c 
            LEFT JOIN ibox i ON c.ibox_id = i.id 
            WHERE c.id = ?
        ");
        $stmt->execute([$parcelId]);
        return $stmt->fetch();
    }
    
    /**
     * Récupérer un code valide
     */
    private function getValidCode($parcelId) {
        $stmt = $this->db->prepare("
            SELECT * FROM pickup_codes 
            WHERE colis_id = ? AND actif = TRUE 
            AND expires_at > NOW()
            ORDER BY date_creation DESC 
            LIMIT 1
        ");
        $stmt->execute([$parcelId]);
        return $stmt->fetch();
    }
    
    /**
     * Récupérer tous les codes d'un colis
     */
    public function getParcelCodes($parcelId) {
        $stmt = $this->db->prepare("
            SELECT * FROM pickup_codes 
            WHERE colis_id = ? 
            ORDER BY date_creation DESC
        ");
        $stmt->execute([$parcelId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Générer un PIN aléatoire
     */
    private function generatePIN() {
        $pin = '';
        for ($i = 0; $i < $this->config['code_length']; $i++) {
            $pin .= random_int(0, 9);
        }
        return $pin;
    }
    
    /**
     * Générer les données QR
     */
    private function generateQRData($parcelId, $pin, $trackingCode) {
        $data = json_encode([
            'type' => 'pickup',
            'parcel_id' => $parcelId,
            'code' => $pin,
            'tracking' => $trackingCode,
            'expires' => date('c', strtotime("+{$this->config['code_expiry_hours']} hours")),
            'app' => 'gestion-colis'
        ]);
        
        // Encoder en base64 pour compresser
        return base64_encode($data);
    }
    
    /**
     * Générer l'URL du QR Code
     */
    public function getQRCodeUrl($qrData) {
        $decoded = json_decode(base64_decode($qrData), true);
        if (!$decoded) {
            // Essayer de parser directement
            $decoded = json_decode($qrData, true);
        }
        
        if (!$decoded) {
            return null;
        }
        
        // URL de l'API de génération de QR code
        $pickupUrl = "gestion-colis://pickup?code={$decoded['code']}&parcel={$decoded['parcel_id']}";
        $encodedUrl = urlencode($pickupUrl);
        
        return "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data={$encodedUrl}";
    }
    
    /**
     * Envoyer les notifications de retrait
     */
    private function sendPickupNotifications($parcel, $pin) {
        // Récupérer l'utilisateur
        $stmt = $this->db->prepare("SELECT * FROM utilisateurs WHERE id = ?");
        $stmt->execute([$parcel['utilisateur_id']]);
        $user = $stmt->fetch();
        
        if (!$user) return;
        
        // Email
        if ($user['email']) {
            $this->notificationService->sendPickupCodeEmail(
                $user['email'],
                $pin,
                $parcel['code_tracking'],
                $parcel['localisation'] ?? $parcel['code_box']
            );
        }
        
        // SMS
        if ($user['tel']) {
            $message = "Votre colis {$parcel['code_tracking']} est pret. ";
            $message .= "Code de retrait: {$pin}. ";
            $message .= "Retirez-le dans l'iBox {$parcel['code_box']}";
            
            $this->notificationService->sendSMS($user['tel'], $message);
        }
        
        // Notification in-app
        $this->notificationService->createInAppNotification(
            $parcel['utilisateur_id'],
            'colis',
            'Code de retrait disponible',
            "Votre colis {$parcel['code_tracking']} est pret. Code: {$pin}",
            'high'
        );
    }
    
    /**
     * Nettoyer les codes expirés
     */
    public function cleanupExpiredCodes() {
        $stmt = $this->db->prepare("
            UPDATE pickup_codes 
            SET actif = FALSE 
            WHERE expires_at < NOW() AND actif = TRUE
        ");
        $stmt->execute();
        
        return $stmt->rowCount();
    }
    
    /**
     * Statistiques des codes
     */
    public function getCodeStatistics($startDate = null, $endDate = null) {
        $sql = "
            SELECT 
                COUNT(*) as total_codes,
                SUM(CASE WHEN actif = TRUE AND expires_at > NOW() THEN 1 ELSE 0 END) as active_codes,
                SUM(CASE WHEN utilise_le IS NOT NULL THEN 1 ELSE 0 END) as used_codes,
                SUM(CASE WHEN expires_at < NOW() AND actif = TRUE THEN 1 ELSE 0 END) as expired_codes,
                COUNT(DISTINCT colis_id) as unique_parcels
            FROM pickup_codes
        ";
        
        if ($startDate && $endDate) {
            $sql .= " WHERE date_creation BETWEEN ? AND ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$startDate, $endDate]);
        } else {
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
        }
        
        return $stmt->fetch();
    }
    
    /**
     * Vérifier la validité d'un code
     */
    public function checkCodeValidity($codeId) {
        $stmt = $this->db->prepare("
            SELECT *, 
                   expires_at > NOW() as is_valid,
                   nombre_utilisations < nombre_utilisations_max as can_use
            FROM pickup_codes 
            WHERE id = ?
        ");
        $stmt->execute([$codeId]);
        return $stmt->fetch();
    }
}
