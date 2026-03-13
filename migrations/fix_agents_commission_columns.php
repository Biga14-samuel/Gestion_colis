<?php
/**
 * Migration: Unifier commission_rate et supprimer commission (doublon)
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

    if (!in_array('commission_rate', $columns, true)) {
        $db->exec("ALTER TABLE agents ADD COLUMN commission_rate DECIMAL(5,2) DEFAULT 0.00");
        $columns[] = 'commission_rate';
        echo "✅ Colonne commission_rate ajoutée.\n";
    }

    if (in_array('commission', $columns, true)) {
        $db->exec("
            UPDATE agents
            SET commission_rate = commission
            WHERE (commission_rate IS NULL OR commission_rate = 0)
              AND commission IS NOT NULL
              AND commission > 0
              AND commission <= 100
        ");
        $db->exec("ALTER TABLE agents DROP COLUMN commission");
        echo "✅ Colonne commission supprimée (valeurs migrées si applicable).\n";
    } else {
        echo "ℹ️ Colonne commission inexistante, rien à supprimer.\n";
    }
} catch (PDOException $e) {
    echo "❌ Erreur migration agents (commission): " . $e->getMessage() . "\n";
    exit(1);
}
