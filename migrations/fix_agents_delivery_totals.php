<?php
/**
 * Migration: Unifier les compteurs de livraisons des agents
 * - Conserver total_livraisons
 * - Migrer les valeurs depuis total_deliveries
 * - Supprimer total_deliveries
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Accès interdit');
}

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();

try {
    $columns = $db->query("SHOW COLUMNS FROM agents")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('total_livraisons', $columns, true)) {
        $db->exec("ALTER TABLE agents ADD COLUMN total_livraisons INT DEFAULT 0");
        echo "✅ Colonne total_livraisons ajoutée.\n";
    }

    if (in_array('total_deliveries', $columns, true)) {
        $db->exec("
            UPDATE agents
            SET total_livraisons = GREATEST(COALESCE(total_livraisons, 0), COALESCE(total_deliveries, 0))
        ");
        $db->exec("ALTER TABLE agents DROP COLUMN total_deliveries");
        echo "✅ Colonne total_deliveries supprimée (valeurs migrées).\n";
    } else {
        echo "ℹ️ Colonne total_deliveries inexistante, rien à supprimer.\n";
    }
} catch (PDOException $e) {
    echo "❌ Erreur migration agents: " . $e->getMessage() . "\n";
    exit(1);
}
