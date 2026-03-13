<?php
/**
 * Backfill expediteur_id à partir de utilisateur_id pour les colis existants
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Accès interdit');
}

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();

$sql = "
    UPDATE colis
    SET expediteur_id = utilisateur_id
    WHERE expediteur_id IS NULL
      AND utilisateur_id IS NOT NULL
";

try {
    $affected = $db->exec($sql);
    echo "✅ Backfill expediteur_id effectué ({$affected} lignes).\n";
} catch (PDOException $e) {
    echo "⚠️ Backfill expediteur_id échoué -> " . $e->getMessage() . "\n";
}
