<?php
/**
 * Migration: Ajout de la table de rate limiting pour la connexion
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Accès interdit');
}

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();

$sql = "
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    email VARCHAR(255) NOT NULL DEFAULT '',
    attempts INT NOT NULL DEFAULT 0,
    last_attempt_at DATETIME NOT NULL,
    locked_until DATETIME NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_login_attempt (ip_address, email),
    KEY idx_locked_until (locked_until),
    KEY idx_last_attempt_at (last_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
";

try {
    $db->exec($sql);
    echo "✅ Table login_attempts créée ou déjà existante.\n";
} catch (PDOException $e) {
    echo "❌ Erreur lors de la création de login_attempts: " . $e->getMessage() . "\n";
    exit(1);
}
