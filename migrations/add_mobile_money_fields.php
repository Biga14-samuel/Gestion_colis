<?php
/**
 * Ajout des champs Mobile Money sur la table colis
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Accès interdit');
}

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();

$queries = [
    "ALTER TABLE colis ADD COLUMN IF NOT EXISTS payment_amount DECIMAL(10,2) DEFAULT 0",
    "ALTER TABLE colis ADD COLUMN IF NOT EXISTS payment_currency VARCHAR(3) DEFAULT 'XAF'",
    "ALTER TABLE colis ADD COLUMN IF NOT EXISTS payment_provider ENUM('orange','mtn') NULL",
    "ALTER TABLE colis ADD COLUMN IF NOT EXISTS payment_reference VARCHAR(64) NULL",
    "ALTER TABLE colis ADD COLUMN IF NOT EXISTS payment_phone VARCHAR(20) NULL",
    "ALTER TABLE colis ADD COLUMN IF NOT EXISTS payment_metadata LONGTEXT NULL",
    "ALTER TABLE colis ADD COLUMN IF NOT EXISTS payment_last_error TEXT NULL",
    "ALTER TABLE colis MODIFY COLUMN payment_currency VARCHAR(3) DEFAULT 'XAF'",
    "ALTER TABLE colis MODIFY COLUMN payment_status ENUM('pending','paid','failed','cancelled','refunded') DEFAULT 'pending'",
    "ALTER TABLE paiements MODIFY COLUMN devise ENUM('EUR','USD','GBP','XAF') DEFAULT 'XAF'"
];

foreach ($queries as $sql) {
    try {
        $db->exec($sql);
        echo "✅ {$sql}\n";
    } catch (PDOException $e) {
        echo "⚠️ {$sql} -> " . $e->getMessage() . "\n";
    }
}
