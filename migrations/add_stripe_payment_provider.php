<?php
/**
 * Migration: Ajout du provider Stripe pour les paiements
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Accès interdit');
}

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();

$queries = [
    "ALTER TABLE colis ADD COLUMN IF NOT EXISTS payment_provider ENUM('orange','mtn','stripe') NULL",
    "ALTER TABLE colis MODIFY COLUMN payment_provider ENUM('orange','mtn','stripe') NULL"
];

foreach ($queries as $sql) {
    try {
        $db->exec($sql);
        echo "✅ {$sql}\n";
    } catch (PDOException $e) {
        echo "⚠️ {$sql} -> " . $e->getMessage() . "\n";
    }
}
