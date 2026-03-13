<?php
/**
 * Script de migration pour ajouter les nouvelles fonctionnalités
 * À exécuter une seule fois lors du déploiement
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Accès interdit');
}

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();

$migrations = [
    // 1. MFA Support
    "ALTER TABLE utilisateurs ADD COLUMN IF NOT EXISTS mfa_secret VARCHAR(255) NULL",
    "ALTER TABLE utilisateurs ADD COLUMN IF NOT EXISTS mfa_enabled TINYINT(1) DEFAULT 0",
    "ALTER TABLE utilisateurs ADD COLUMN IF NOT EXISTS mfa_backup_codes TEXT NULL",

    // 2. QR Codes Storage
    "ALTER TABLE utilisateurs ADD COLUMN IF NOT EXISTS qr_code_path VARCHAR(255) NULL",
    "ALTER TABLE ibox ADD COLUMN IF NOT EXISTS qr_code_path VARCHAR(255) NULL",

    // 3. iBox Sharing
    "CREATE TABLE IF NOT EXISTS ibox_shares (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ibox_id INT NOT NULL,
        owner_id INT NOT NULL,
        shared_with_user_id INT NULL,
        shared_with_email VARCHAR(255) NOT NULL,
        permission_level ENUM('view', 'open', 'manage') DEFAULT 'view',
        expires_at DATETIME NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (ibox_id) REFERENCES ibox(id) ON DELETE CASCADE,
        FOREIGN KEY (owner_id) REFERENCES utilisateurs(id) ON DELETE CASCADE,
        FOREIGN KEY (shared_with_user_id) REFERENCES utilisateurs(id) ON DELETE SET NULL
    )",

    // 4. iBox History
    "CREATE TABLE IF NOT EXISTS ibox_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ibox_id INT NOT NULL,
        user_id INT NULL,
        action_type VARCHAR(50) NOT NULL,
        action_details TEXT,
        ip_address VARCHAR(45) NULL,
        user_agent TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (ibox_id) REFERENCES ibox(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES utilisateurs(id) ON DELETE SET NULL,
        INDEX idx_ibox_id (ibox_id),
        INDEX idx_action_type (action_type),
        INDEX idx_created_at (created_at)
    )",

    // 5. Signatures and POD
    "ALTER TABLE colis ADD COLUMN IF NOT EXISTS signature_data LONGTEXT NULL",
    "ALTER TABLE colis ADD COLUMN IF NOT EXISTS signature_level ENUM('simple', 'advanced', 'qualified') DEFAULT 'simple'",
    "ALTER TABLE colis ADD COLUMN IF NOT EXISTS signature_timestamp DATETIME NULL",
    "ALTER TABLE colis ADD COLUMN IF NOT EXISTS signature_ip VARCHAR(45) NULL",
    "ALTER TABLE colis ADD COLUMN IF NOT EXISTS proof_photo_path VARCHAR(255) NULL",

    // 6. Payments
    "ALTER TABLE colis ADD COLUMN IF NOT EXISTS payment_status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending'",
    "ALTER TABLE colis ADD COLUMN IF NOT EXISTS payment_amount DECIMAL(10,2) DEFAULT 0",
    "ALTER TABLE colis ADD COLUMN IF NOT EXISTS payment_currency VARCHAR(3) DEFAULT 'XAF'",
    "ALTER TABLE colis ADD COLUMN IF NOT EXISTS stripe_payment_intent VARCHAR(255) NULL",
    "ALTER TABLE colis ADD COLUMN IF NOT EXISTS stripe_session_id VARCHAR(255) NULL",
    "ALTER TABLE colis ADD COLUMN IF NOT EXISTS paid_at DATETIME NULL",

    // 7. Notifications
    "CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        type VARCHAR(50) NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        link VARCHAR(500) NULL,
        is_read TINYINT(1) DEFAULT 0,
        read_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES utilisateurs(id) ON DELETE CASCADE,
        INDEX idx_user_id (user_id),
        INDEX idx_is_read (is_read),
        INDEX idx_created_at (created_at)
    )",

    // 8. Postal ID Enhancements
    "ALTER TABLE postal_id ADD COLUMN IF NOT EXISTS verification_level ENUM('basic', 'advanced', 'qualified') DEFAULT 'basic'",
    "ALTER TABLE postal_id ADD COLUMN IF NOT EXISTS document_path VARCHAR(255) NULL",
    "ALTER TABLE postal_id ADD COLUMN IF NOT EXISTS verified_at DATETIME NULL",
    "ALTER TABLE postal_id ADD COLUMN IF NOT EXISTS expires_at DATETIME NULL",

    // 9. Agent-specific fields
    "ALTER TABLE agents ADD COLUMN IF NOT EXISTS latitude DECIMAL(10,8) NULL",
    "ALTER TABLE agents ADD COLUMN IF NOT EXISTS longitude DECIMAL(11,8) NULL",
    "ALTER TABLE agents ADD COLUMN IF NOT EXISTS last_location_update DATETIME NULL",
    "ALTER TABLE agents ADD COLUMN IF NOT EXISTS commission_rate DECIMAL(5,2) DEFAULT 10.00",
    "ALTER TABLE agents ADD COLUMN IF NOT EXISTS total_livraisons INT DEFAULT 0",
    "ALTER TABLE agents ADD COLUMN IF NOT EXISTS total_earnings DECIMAL(10,2) DEFAULT 0",

    // 10. Audit log
    "CREATE TABLE IF NOT EXISTS audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        action VARCHAR(100) NOT NULL,
        entity_type VARCHAR(50) NOT NULL,
        entity_id INT NOT NULL,
        old_values JSON NULL,
        new_values JSON NULL,
        ip_address VARCHAR(45) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES utilisateurs(id) ON DELETE SET NULL,
        INDEX idx_user_id (user_id),
        INDEX idx_entity (entity_type, entity_id)
    )"
];

echo "=== Migration Gestion_Colis - Nouvelles Fonctionnalités ===\n\n";

$success = 0;
$errors = [];

foreach ($migrations as $index => $sql) {
    try {
        // Extract table/column name for display
        if (preg_match('/(ADD COLUMN|CREATE TABLE|ALTER TABLE)/', $sql, $matches)) {
            $type = $matches[1];
            if ($type === 'ADD COLUMN') {
                preg_match('/ADD COLUMN\s+(\w+)/', $sql, $colMatch);
                $name = $colMatch[1] ?? 'unknown';
            } elseif ($type === 'CREATE TABLE') {
                preg_match('/CREATE TABLE\s+IF NOT EXISTS\s+(\w+)/', $sql, $tableMatch);
                $name = $tableMatch[1] ?? 'unknown';
            } else {
                preg_match('/ALTER TABLE\s+\w+\s+ADD COLUMN\s+(\w+)/', $sql, $colMatch);
                $name = $colMatch[1] ?? 'unknown';
            }
        }

        $db->exec($sql);
        $success++;
        echo "✓ Migration $index : OK\n";
    } catch (PDOException $e) {
        $errors[] = "Erreur migration $index: " . $e->getMessage();
        echo "✗ Erreur migration $index: " . $e->getMessage() . "\n";
    }
}

echo "\n=== Résumé ===\n";
echo "Succès: $success\n";
echo "Erreurs: " . count($errors) . "\n";

if (!empty($errors)) {
    echo "\nDétails des erreurs:\n";
    foreach ($errors as $error) {
        echo "  - $error\n";
    }
}

echo "\n=== Installation des dépendances Composer ===\n";
// Vérifier si Composer est disponible
$composerPackages = [
    'chillerlan/php-qrcode' => 'Génération QR codes',
    'pragmarx/google2fa' => 'Authentification à deux facteurs',
    'phpmailer/phpmailer' => 'Envoi d\'emails',
    'stripe/stripe-php' => 'Paiements Stripe'
];

echo "Paquets recommandés pour les nouvelles fonctionnalités:\n";
foreach ($composerPackages as $package => $description) {
    echo "  - $package: $description\n";
}

echo "\nPour installer: composer require $package\n";

echo "\n=== Migration terminée ===\n";
