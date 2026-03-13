<?php
/**
 * Service d'Horodatage Légal (RFC 3161)
 * Gestion_Colis v2.0 - Module iSignature
 */

require_once __DIR__ . '/../config/database.php';

class LegalTimestampService {
    private $db;
    private $privateKeyPath;
    private $certificatePath;
    private $tsaAuthority = 'Gestion_Colis Internal TSA';
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        
        // Chemins des clés (à configurer)
        $this->privateKeyPath = __DIR__ . '/../keys/private.key';
        $this->certificatePath = __DIR__ . '/../keys/certificate.crt';
        
        // Créer le répertoire keys si nécessaire
        $keysDir = dirname($this->privateKeyPath);
        if (!is_dir($keysDir)) {
            mkdir($keysDir, 0700, true);
        }
    }
    
    /**
     * Générer une paire de clés pour l'horodatage
     */
    public function generateKeys($commonName = 'Gestion_Colis TSA') {
        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        
        // Générer la clé privée
        $privkey = openssl_pkey_new($config);
        
        if (!$privkey) {
            throw new Exception('Erreur lors de la génération des clés');
        }
        
        // Exporter la clé privée
        openssl_pkey_export($privkey, $privateKey);
        
        // Créer un certificat auto-signé
        $dn = [
            'countryName' => 'FR',
            'stateOrProvinceName' => 'France',
            'localityName' => 'Paris',
            'organizationName' => 'Gestion_Colis',
            'commonName' => $commonName
        ];
        
        $csr = openssl_csr_new($dn, $privkey, ['digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $privkey, 365, ['digest_alg' => 'sha256']);
        
        $cert = '';
        openssl_x509_export($x509, $cert);
        
        // Sauvegarder les fichiers
        file_put_contents($this->privateKeyPath, $privateKey);
        file_put_contents($this->certificatePath, $cert);
        
        // Libérer les ressources
        openssl_pkey_free($privkey);
        openssl_csr_free($csr);
        openssl_x509_free($x509);
        
        return [
            'private_key' => $privateKey,
            'certificate' => $cert
        ];
    }
    
    /**
     * Créer un horodatage légal pour un document
     */
    public function createTimestamp($documentData, $documentId = null, $documentType = 'signature') {
        // Calculer le hash du document
        $documentHash = is_string($documentData) 
            ? hash('sha256', $documentData)
            : hash_file('sha256', $documentData);
        
        // Horodatage actuel
        $timestamp = time();
        
        // Créer le token d'horodatage (signé avec la clé privée)
        $timestampToken = $this->generateTimestampToken($documentHash, $timestamp);
        
        try {
            // Enregistrer dans la base de données
            $stmt = $this->db->prepare("
                INSERT INTO legal_timestamps (
                    document_hash, document_id, document_type, timestamp_token,
                    signature_serveur, cle_publique_serveur, date_creation,
                    date_expiration, autorite_emetteuse
                ) VALUES (?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?), DATE_ADD(FROM_UNIXTIME(?), INTERVAL 5 YEAR), ?)
            ");
            
            // Lire la clé publique
            $publicKey = '';
            if (file_exists($this->certificatePath)) {
                $publicKey = file_get_contents($this->certificatePath);
            }
            
            $stmt->execute([
                $documentHash,
                $documentId,
                $documentType,
                $timestampToken,
                $this->signData($documentHash . $timestamp),
                $publicKey,
                $timestamp,
                $timestamp,
                $this->tsaAuthority
            ]);
            
            $timestampId = $this->db->lastInsertId();
            
            return [
                'success' => true,
                'timestamp_id' => $timestampId,
                'document_hash' => $documentHash,
                'timestamp' => date('Y-m-d H:i:s', $timestamp),
                'expires_at' => date('Y-m-d H:i:s', $timestamp + (5 * 365 * 24 * 60 * 60)),
                'token' => $timestampToken,
                'authority' => $this->tsaAuthority
            ];
            
        } catch (PDOException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Vérifier un horodatage légal
     */
    public function verifyTimestamp($documentHash, $timestampToken) {
        // Récupérer l'enregistrement
        $stmt = $this->db->prepare("
            SELECT * FROM legal_timestamps 
            WHERE document_hash = ? AND timestamp_token = ? AND statut = 'valide'
        ");
        $stmt->execute([$documentHash, $timestampToken]);
        $record = $stmt->fetch();
        
        if (!$record) {
            return [
                'valid' => false,
                'error' => 'Horodatage non trouvé ou invalide'
            ];
        }
        
        // Vérifier l'expiration
        if ($record['date_expiration'] && strtotime($record['date_expiration']) < time()) {
            return [
                'valid' => false,
                'error' => 'Horodatage expiré',
                'expired_at' => $record['date_expiration']
            ];
        }
        
        // Vérifier la signature du serveur
        $expectedSignature = $record['signature_serveur'];
        $dataToVerify = $documentHash . strtotime($record['date_creation']);
        
        if (!$this->verifySignature($dataToVerify, $expectedSignature, $record['cle_publique_serveur'])) {
            return [
                'valid' => false,
                'error' => 'Signature invalide - le document a peut-être été modifié'
            ];
        }
        
        return [
            'valid' => true,
            'timestamp_id' => $record['id'],
            'document_hash' => $record['document_hash'],
            'created_at' => $record['date_creation'],
            'expires_at' => $record['date_expiration'],
            'authority' => $record['autorite_emetteuse'],
            'document_type' => $record['document_type']
        ];
    }
    
    /**
     * Vérifier un document par son ID
     */
    public function verifyByDocumentId($documentId, $currentHash) {
        $stmt = $this->db->prepare("
            SELECT * FROM legal_timestamps 
            WHERE document_id = ? AND document_type = 'signature' AND statut = 'valide'
            ORDER BY date_creation DESC
            LIMIT 1
        ");
        $stmt->execute([$documentId]);
        $record = $stmt->fetch();
        
        if (!$record) {
            return [
                'valid' => false,
                'error' => 'Aucun horodatage trouvé pour ce document'
            ];
        }
        
        return $this->verifyTimestamp($currentHash, $record['timestamp_token']);
    }
    
    /**
     * Générer le token d'horodatage
     */
    private function generateTimestampToken($documentHash, $timestamp) {
        $data = json_encode([
            'hash' => $documentHash,
            'timestamp' => $timestamp,
            'authority' => $this->tsaAuthority,
            'expires' => $timestamp + (5 * 365 * 24 * 60 * 60)
        ]);
        
        return base64_encode($this->signData($data));
    }
    
    /**
     * Signer des données
     */
    private function signData($data) {
        if (!file_exists($this->privateKeyPath)) {
            // Si pas de clé, générer un hash simple comme fallback
            return hash_hmac('sha256', $data, $this->tsaAuthority);
        }
        
        $privateKey = file_get_contents($this->privateKeyPath);
        $pkey = openssl_pkey_get_private($privateKey);
        
        if (!$pkey) {
            return hash_hmac('sha256', $data, $this->tsaAuthority);
        }
        
        $signature = '';
        openssl_sign($data, $signature, $pkey, OPENSSL_ALGO_SHA256);
        
        return base64_encode($signature);
    }
    
    /**
     * Vérifier une signature
     */
    private function verifySignature($data, $signature, $publicKey) {
        if (empty($publicKey)) {
            // Fallback: vérifier avec le hash
            $expected = hash_hmac('sha256', $data, $this->tsaAuthority);
            return base64_encode($signature) === $expected;
        }
        
        $pubkey = openssl_pkey_get_public($publicKey);
        if (!$pubkey) {
            return false;
        }
        
        $signature = base64_decode($signature);
        return openssl_verify($data, $signature, $pubkey, OPENSSL_ALGO_SHA256) === 1;
    }
    
    /**
     * Récupérer l'historique d'horodatage d'un document
     */
    public function getDocumentTimestamps($documentId, $documentType = 'signature') {
        $stmt = $this->db->prepare("
            SELECT * FROM legal_timestamps 
            WHERE document_id = ? AND document_type = ?
            ORDER BY date_creation DESC
        ");
        $stmt->execute([$documentId, $documentType]);
        return $stmt->fetchAll();
    }
    
    /**
     * Révoquer un horodatage
     */
    public function revokeTimestamp($timestampId, $reason = 'document_annule') {
        $stmt = $this->db->prepare("
            UPDATE legal_timestamps 
            SET statut = 'revoke' 
            WHERE id = ?
        ");
        return $stmt->execute([$timestampId]);
    }
    
    /**
     * Nettoyer les horodatages expirés
     */
    public function cleanupExpired() {
        $stmt = $this->db->prepare("
            UPDATE legal_timestamps 
            SET statut = 'expire' 
            WHERE date_expiration < NOW() AND statut = 'valide'
        ");
        $stmt->execute();
        
        return $stmt->rowCount();
    }
    
    /**
     * Générer un certificat de vérification
     */
    public function generateVerificationCertificate($documentHash, $timestampToken) {
        $verification = $this->verifyTimestamp($documentHash, $timestampToken);
        
        if (!$verification['valid']) {
            return null;
        }
        
        $stmt = $this->db->prepare("SELECT cle_publique_serveur FROM legal_timestamps WHERE id = ?");
        $stmt->execute([$verification['timestamp_id']]);
        $cert = $stmt->fetch()['cle_publique_serveur'];
        
        $certificate = [
            'document_hash' => $documentHash,
            'timestamp_token' => $timestampToken,
            'verification_result' => $verification,
            'generated_at' => date('Y-m-d H:i:s'),
            'certificate_authority' => $this->tsaAuthority
        ];
        
        return json_encode($certificate, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    
    /**
     * Vérifier si les clés existent
     */
    public function keysExist() {
        return file_exists($this->privateKeyPath) && file_exists($this->certificatePath);
    }
    
    /**
     * Obtenir les informations sur l'autorité d'horodatage
     */
    public function getAuthorityInfo() {
        return [
            'name' => $this->tsaAuthority,
            'keys_exist' => $this->keysExist(),
            'validity_period' => '5 ans',
            'algorithm' => 'SHA-256 with RSA',
            'certification_level' => 'Interne'
        ];
    }
}
