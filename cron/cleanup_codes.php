<?php
/**
 * Nettoyage des codes de retrait expirés
 * Usage: php cron/cleanup_codes.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Accès interdit');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/pickup_code_service.php';

try {
    $service = new PickupCodeService();
    $count = $service->cleanupExpiredCodes();
    echo "✅ {$count} code(s) expiré(s) désactivé(s).\n";
} catch (Throwable $e) {
    echo "❌ Erreur cleanup codes: " . $e->getMessage() . "\n";
    exit(1);
}
