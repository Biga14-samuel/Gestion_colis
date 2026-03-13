<?php
/**
 * Migration: Ajout du support de vérification email
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Accès interdit');
}

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();

try {
    $columns = $db->query("SHOW COLUMNS FROM utilisateurs")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('email_verification_token', $columns, true)) {
        $db->exec("ALTER TABLE utilisateurs ADD COLUMN email_verification_token VARCHAR(64) NULL");
        echo "✅ Colonne email_verification_token ajoutée.\n";
    }
    if (!in_array('email_verification_sent_at', $columns, true)) {
        $db->exec("ALTER TABLE utilisateurs ADD COLUMN email_verification_sent_at DATETIME NULL");
        echo "✅ Colonne email_verification_sent_at ajoutée.\n";
    }
    if (!in_array('email_verified_at', $columns, true)) {
        $db->exec("ALTER TABLE utilisateurs ADD COLUMN email_verified_at DATETIME NULL");
        echo "✅ Colonne email_verified_at ajoutée.\n";
    }

    $indexes = $db->query("SHOW INDEX FROM utilisateurs")->fetchAll(PDO::FETCH_ASSOC);
    $indexNames = array_unique(array_map(fn($row) => $row['Key_name'], $indexes));
    if (!in_array('uniq_email_verification_token', $indexNames, true)) {
        $db->exec("ALTER TABLE utilisateurs ADD UNIQUE KEY uniq_email_verification_token (email_verification_token)");
        echo "✅ Index uniq_email_verification_token ajouté.\n";
    }

    $db->exec("
        UPDATE utilisateurs
        SET email_verifie = 1,
            email_verified_at = COALESCE(email_verified_at, NOW())
        WHERE email_verifie = 0
    ");
    echo "✅ Utilisateurs existants marqués comme vérifiés.\n";

} catch (PDOException $e) {
    echo "❌ Erreur migration email verification: " . $e->getMessage() . "\n";
    exit(1);
}
