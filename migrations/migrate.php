<?php
/**
 * Runner de migrations (versionnement simple)
 * Usage:
 *  php migrations/migrate.php         # exécute les migrations non appliquées
 *  php migrations/migrate.php --baseline  # marque tout comme appliqué sans exécuter
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Accès interdit');
}

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();

$db->exec("
    CREATE TABLE IF NOT EXISTS schema_migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        migration VARCHAR(255) NOT NULL UNIQUE,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

$baseline = in_array('--baseline', $argv ?? [], true);

$files = glob(__DIR__ . '/*.php') ?: [];
$files = array_filter($files, function ($file) {
    $base = basename($file);
    return $base !== 'migrate.php' && $base[0] !== '.';
});
sort($files, SORT_STRING);

foreach ($files as $file) {
    $name = basename($file);

    $stmt = $db->prepare("SELECT 1 FROM schema_migrations WHERE migration = ?");
    $stmt->execute([$name]);
    if ($stmt->fetchColumn()) {
        echo "⏭  {$name} déjà appliquée.\n";
        continue;
    }

    if ($baseline) {
        $stmt = $db->prepare("INSERT INTO schema_migrations (migration) VALUES (?)");
        $stmt->execute([$name]);
        echo "✅ {$name} marquée comme appliquée (baseline).\n";
        continue;
    }

    echo "▶ {$name}\n";
    $migrationName = $name; // éviter toute modification accidentelle dans le scope du require
    require $file;

    $stmt = $db->prepare("INSERT INTO schema_migrations (migration) VALUES (?)");
    $stmt->execute([$migrationName]);
    echo "✅ {$migrationName} appliquée.\n";
}
