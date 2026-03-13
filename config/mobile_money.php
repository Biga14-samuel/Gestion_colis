<?php
/**
 * Configuration Mobile Money (Orange Money + MTN MoMo)
 * Les valeurs sensibles doivent être injectées via variables d'environnement.
 */

function mobile_money_config(): array {
    $appEnv = strtolower((string) getenv('APP_ENV'));
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $addr = $_SERVER['REMOTE_ADDR'] ?? '';
    $isDev = in_array($appEnv, ['dev', 'development', 'local', 'test'], true)
        || PHP_SAPI === 'cli'
        || $addr === '127.0.0.1'
        || $addr === '::1'
        || stripos($host, 'localhost') !== false;

    $simulateEnv = getenv('MOBILE_MONEY_SIMULATE');
    $simulate = $simulateEnv !== false
        ? in_array(strtolower($simulateEnv), ['1', 'true', 'yes'], true)
        : $isDev;

    $omExtraFieldsRaw = getenv('OM_EXTRA_FIELDS');
    $omExtraFields = [];
    if ($omExtraFieldsRaw) {
        $decoded = json_decode($omExtraFieldsRaw, true);
        if (is_array($decoded)) {
            $omExtraFields = $decoded;
        }
    }

    return [
        'simulate' => $simulate,
        'currency' => getenv('MOBILE_MONEY_CURRENCY') ?: 'XAF',
        'orange' => [
            'enabled' => getenv('OM_ENABLED') === '1',
            'client_id' => getenv('OM_CLIENT_ID') ?: '',
            'client_secret' => getenv('OM_CLIENT_SECRET') ?: '',
            'merchant_key' => getenv('OM_MERCHANT_KEY') ?: '',
            'webhook_secret' => getenv('OM_WEBHOOK_SECRET') ?: '',
            'token_url' => getenv('OM_TOKEN_URL') ?: 'https://api.orange.com/oauth/v3/token',
            'payment_url' => getenv('OM_PAYMENT_URL') ?: '',
            'status_url' => getenv('OM_STATUS_URL') ?: '',
            'notif_url' => getenv('OM_NOTIF_URL') ?: '',
            'return_url' => getenv('OM_RETURN_URL') ?: '',
            'cancel_url' => getenv('OM_CANCEL_URL') ?: '',
            'country' => getenv('OM_COUNTRY') ?: 'cm',
            'language' => getenv('OM_LANGUAGE') ?: 'fr',
            'payer_field' => getenv('OM_PAYER_FIELD') ?: '',
            'extra_fields' => $omExtraFields,
        ],
        'mtn' => [
            'enabled' => getenv('MOMO_ENABLED') === '1',
            'base_url' => getenv('MOMO_BASE_URL') ?: 'https://sandbox.momodeveloper.mtn.com',
            'subscription_key' => getenv('MOMO_SUBSCRIPTION_KEY') ?: '',
            'api_user' => getenv('MOMO_API_USER') ?: '',
            'api_key' => getenv('MOMO_API_KEY') ?: '',
            'target_env' => getenv('MOMO_TARGET_ENV') ?: 'sandbox',
            'callback_url' => getenv('MOMO_CALLBACK_URL') ?: '',
            'webhook_secret' => getenv('MOMO_WEBHOOK_SECRET') ?: '',
        ],
    ];
}
