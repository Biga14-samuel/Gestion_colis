<?php
/**
 * Configuration globale (commissions, notifications, codes de retrait)
 * Les valeurs sensibles doivent être injectées via variables d'environnement.
 */

if (!function_exists('env_bool')) {
    function env_bool(string $key, bool $default = false): bool {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }

        $value = strtolower(trim((string) $value));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('env_int')) {
    function env_int(string $key, int $default): int {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }
        return (int) $value;
    }
}

if (!function_exists('env_float')) {
    function env_float(string $key, float $default): float {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }
        return (float) $value;
    }
}

return [
    'commissions' => [
        'base_rate' => env_float('COMMISSION_BASE_RATE', 2.50),
        'km_rate' => env_float('COMMISSION_KM_RATE', 0.45),
        'urgent_bonus' => env_float('COMMISSION_URGENT_BONUS', 1.50),
        'fragile_bonus' => env_float('COMMISSION_FRAGILE_BONUS', 0.75),
        'weight_threshold' => env_float('COMMISSION_WEIGHT_THRESHOLD', 5.0),
        'weight_bonus' => env_float('COMMISSION_WEIGHT_BONUS', 0.50),
        'min_commission' => env_float('COMMISSION_MIN', 1.50),
        'max_commission' => env_float('COMMISSION_MAX', 15.00),
    ],
    'pickup_codes' => [
        'code_length' => env_int('PICKUP_CODE_LENGTH', 6),
        'code_expiry_hours' => env_int('PICKUP_CODE_EXPIRY_HOURS', 72),
        'max_attempts' => env_int('PICKUP_CODE_MAX_ATTEMPTS', 3),
        'allow_multiple_uses' => env_bool('PICKUP_CODE_ALLOW_MULTI', false),
    ],
    'stripe' => [
        'enabled' => env_bool('STRIPE_ENABLED', false),
        'secret_key' => getenv('STRIPE_SECRET_KEY') ?: '',
        'webhook_secret' => getenv('STRIPE_WEBHOOK_SECRET') ?: '',
    ],
    'mobile_money' => [
        'mtn_api_key' => getenv('MOMO_API_KEY') ?: (getenv('MTN_API_KEY') ?: ''),
        'orange_api_key' => getenv('OM_MERCHANT_KEY') ?: (getenv('ORANGE_API_KEY') ?: ''),
    ],
    'notifications' => [
        'twilio' => [
            'sid' => getenv('TWILIO_SID') ?: '',
            'token' => getenv('TWILIO_TOKEN') ?: '',
            'from' => getenv('TWILIO_FROM') ?: '+33123456789',
        ],
        'nexmo' => [
            'key' => getenv('NEXMO_KEY') ?: '',
            'secret' => getenv('NEXMO_SECRET') ?: '',
            'from' => getenv('NEXMO_FROM') ?: 'gestion-colis',
        ],
    ],
];
