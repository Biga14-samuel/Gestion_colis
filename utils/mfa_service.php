<?php
/**
 * Service d'Authentification Multi-Facteurs (MFA)
 * Gestion_Colis v2.0
 */

require_once __DIR__ . '/../config/database.php';

class MFAService {
    private $db;
    private $issuer = 'Gestion_Colis';
    private $algorithm = 'sha1';
    private $digits = 6;
    private $period = 30;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }
    
    /**
     * Générer une nouvelle configuration MFA pour un utilisateur
     */
    public function generateSecret($userId) {
        $secret = $this->generateBase32Secret();
        
        // Stocker le secret
        $stmt = $this->db->prepare("UPDATE utilisateurs SET mfa_secret = ? WHERE id = ?");
        $stmt->execute([$secret, $userId]);
        
        // Générer les codes de backup
        $backupCodes = $this->generateBackupCodes();
        $backupCodesHashed = password_hash(json_encode($backupCodes), PASSWORD_DEFAULT);
        
        $stmt = $this->db->prepare("UPDATE utilisateurs SET mfa_backup_codes = ? WHERE id = ?");
        $stmt->execute([$backupCodesHashed, $userId]);
        
        // Récupérer les informations utilisateur
        $stmt = $this->db->prepare("SELECT email, prenom, nom FROM utilisateurs WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        // Générer le QR Code
        $accountName = $user['email'];
        $qrCodeUrl = $this->getQRCodeUrl($secret, $accountName, $user['prenom'] . ' ' . $user['nom']);
        
        return [
            'secret' => $secret,
            'qr_code_url' => $qrCodeUrl,
            'backup_codes' => $backupCodes,
            'setup_string' => $this->getSetupString($secret, $accountName, $user['prenom'] . ' ' . $user['nom'])
        ];
    }
    
    /**
     * Vérifier le code TOTP
     */
    public function verifyCode($userId, $code) {
        $stmt = $this->db->prepare("SELECT mfa_secret, mfa_active, mfa_enabled FROM utilisateurs WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        $enabled = !empty($user['mfa_active']) || !empty($user['mfa_enabled']);
        if (!$user || !$enabled || empty($user['mfa_secret'])) {
            return false;
        }
        
        // Vérifier le code TOTP principal
        if ($this->verifyTOTP($user['mfa_secret'], $code)) {
            return 'totp';
        }
        
        // Vérifier les codes de backup
        if ($this->verifyBackupCode($userId, $code)) {
            return 'backup';
        }
        
        return false;
    }
    
    /**
     * Activer le MFA après vérification
     */
    public function enableMFA($userId, $code) {
        $verification = $this->verifyCode($userId, $code);
        
        if ($verification) {
            $stmt = $this->db->prepare("
                UPDATE utilisateurs 
                SET mfa_active = TRUE, mfa_verified_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$userId]);
            return true;
        }
        
        return false;
    }
    
    /**
     * Désactiver le MFA
     */
    public function disableMFA($userId, $password) {
        // Vérifier le mot de passe d'abord
        $stmt = $this->db->prepare("SELECT mot_de_passe FROM utilisateurs WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($password, $user['mot_de_passe'])) {
            return ['success' => false, 'message' => 'Mot de passe incorrect'];
        }
        
        // Désactiver le MFA
        $stmt = $this->db->prepare("
            UPDATE utilisateurs 
            SET mfa_active = FALSE, mfa_secret = NULL, mfa_backup_codes = NULL, mfa_verified_at = NULL 
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        
        return ['success' => true, 'message' => 'MFA désactivé avec succès'];
    }
    
    /**
     * Vérifier un code de backup
     */
    private function verifyBackupCode($userId, $code) {
        $stmt = $this->db->prepare("SELECT mfa_backup_codes FROM utilisateurs WHERE id = ?");
        $stmt->execute([$userId]);
        $backupCodesHashed = $stmt->fetch()['mfa_backup_codes'];
        
        if (!$backupCodesHashed) return false;
        
        $backupCodes = json_decode($backupCodesHashed, true);
        
        foreach ($backupCodes as $index => $backupCode) {
            if (password_verify($code, $backupCode['hash'])) {
                // Supprimer le code utilisé
                unset($backupCodes[$index]);
                $newHash = password_hash(json_encode(array_values($backupCodes)), PASSWORD_DEFAULT);
                
                $stmt = $this->db->prepare("UPDATE utilisateurs SET mfa_backup_codes = ? WHERE id = ?");
                $stmt->execute([$newHash, $userId]);
                
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Vérifier le code TOTP
     */
    private function verifyTOTP($secret, $code) {
        $time = floor(time() / $this->period);
        $codes = [
            $this->generateTOTP($secret, $time - 1),
            $this->generateTOTP($secret, $time),
            $this->generateTOTP($secret, $time + 1)
        ];
        
        return in_array($code, $codes);
    }
    
    /**
     * Générer le code TOTP
     */
    private function generateTOTP($secret, $time) {
        $hash = hash_hmac($this->algorithm, $this->intToHex($time), $this->base32Decode($secret));
        $offset = hexdec(substr($hash, strlen($hash) - 1)) & 0x0F;
        $truncatedHash = substr($hash, $offset * 2, 8);
        $code = hexdec($truncatedHash) % pow(10, $this->digits);
        
        return str_pad($code, $this->digits, '0', STR_PAD_LEFT);
    }
    
    /**
     * Générer un secret Base32
     */
    private function generateBase32Secret($length = 32) {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $secret;
    }
    
    /**
     * Générer les codes de backup
     */
    private function generateBackupCodes($count = 10, $length = 8) {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $code = '';
            for ($j = 0; $j < $length; $j++) {
                $code .= random_int(0, 9);
            }
            $codes[] = [
                'code' => chunk_split($code, 4, '-'),
                'hash' => password_hash($code, PASSWORD_DEFAULT),
                'used' => false
            ];
        }
        return $codes;
    }
    
    /**
     * Générer l'URL du QR Code
     */
    private function getQRCodeUrl($secret, $accountName, $holderName) {
        $otpauthUrl = sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=%s&digits=%d&period=%d',
            rawurlencode($this->issuer),
            rawurlencode($accountName),
            $secret,
            rawurlencode($this->issuer),
            $this->algorithm,
            $this->digits,
            $this->period
        );
        
        // Générer le QR Code via API Google Charts
        $encodedUrl = urlencode($otpauthUrl);
        return "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data={$encodedUrl}";
    }
    
    /**
     * Générer la chaîne de configuration manuelle
     */
    private function getSetupString($secret, $accountName, $holderName) {
        return sprintf(
            '%s:%s\nSecret: %s\nIssuer: %s',
            $holderName,
            $accountName,
            chunk_split($secret, 4, ' '),
            $this->issuer
        );
    }
    
    /**
     * Décoder Base32
     */
    private function base32Decode($secret) {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binaryString = '';
        
        foreach (str_split(strtoupper($secret)) as $char) {
            $val = strpos($alphabet, $char);
            if ($val === false) continue;
            $binaryString .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
        }
        
        return pack('H*', bin2hex(str_split($binaryString, 8)[0]));
    }
    
    /**
     * Convertir entier en hexadécimal
     */
    private function intToHex($int) {
        return str_pad(dechex($int), 16, '0', STR_PAD_LEFT);
    }
    
    /**
     * Vérifier si MFA est activé pour un utilisateur
     */
    public function isMFAEnabled($userId) {
        $stmt = $this->db->prepare("SELECT mfa_active FROM utilisateurs WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch()['mfa_active'] ?? false;
    }
    
    /**
     * Récupérer les codes de backup restants
     */
    public function getRemainingBackupCodesCount($userId) {
        $stmt = $this->db->prepare("SELECT mfa_backup_codes FROM utilisateurs WHERE id = ?");
        $stmt->execute([$userId]);
        $codes = json_decode($stmt->fetch()['mfa_backup_codes'] ?? '[]', true);
        return count($codes);
    }
}
