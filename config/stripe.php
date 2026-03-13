<?php
/**
 * Configuration Stripe (paiement carte)
 * Les valeurs sensibles doivent être injectées via variables d'environnement.
 */

require_once __DIR__ . '/../utils/env_loader.php';

if (!defined('STRIPE_ENABLED')) {
    $enabled = getenv('STRIPE_ENABLED');
    define(
        'STRIPE_ENABLED',
        $enabled !== false && in_array(strtolower(trim((string) $enabled)), ['1', 'true', 'yes', 'on'], true)
    );
}

if (!defined('STRIPE_SECRET_KEY')) {
    define('STRIPE_SECRET_KEY', getenv('STRIPE_SECRET_KEY') ?: '');
}

if (!defined('STRIPE_WEBHOOK_SECRET')) {
    define('STRIPE_WEBHOOK_SECRET', getenv('STRIPE_WEBHOOK_SECRET') ?: '');
}

if (!defined('STRIPE_SIMULATE')) {
    $simulate = getenv('STRIPE_SIMULATE');
    define(
        'STRIPE_SIMULATE',
        $simulate !== false && in_array(strtolower(trim((string) $simulate)), ['1', 'true', 'yes', 'on'], true)
    );
}
