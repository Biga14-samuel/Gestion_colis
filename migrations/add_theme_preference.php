<?php
/**
 * Migration: Ajout de la préférence de thème utilisateur
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

    if (!in_array('theme_preference', $columns, true)) {
        $db->exec("ALTER TABLE utilisateurs ADD COLUMN theme_preference ENUM('light','dark') NOT NULL DEFAULT 'light'");
        echo "✅ Colonne theme_preference ajoutée.\n";
    }

    $db->exec("
        UPDATE utilisateurs
        SET theme_preference = 'light'
        WHERE theme_preference IS NULL OR theme_preference = ''
    ");
    echo "✅ Préférences de thème initialisées.\n";
} catch (PDOException $e) {
    echo "❌ Erreur migration theme: " . $e->getMessage() . "\n";
    exit(1);
}
