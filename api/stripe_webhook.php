<?php
/**
 * Stripe Webhook Endpoint
 */

define('CSRF_EXEMPT', true);

require_once __DIR__ . '/../utils/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/stripe_helper.php';

$rawBody = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

$stripeHelper = new StripeHelper();
$result = $stripeHelper->handleWebhook($rawBody, $signature);

if (!$result['success']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $result['message'] ?? 'Webhook invalide']);
    exit;
}

$event = $result['data'] ?? [];
$eventType = $result['event'] ?? '';
$sessionId = null;

if ($eventType === 'checkout.session.completed') {
    $sessionId = $event['data']['object']['id'] ?? null;
}

if ($sessionId) {
    $verify = $stripeHelper->verifyPayment($sessionId);
    if ($verify['success']) {
        $stripeHelper->applyStripePayment($verify['colis_ids'] ?? [], $sessionId, 0, $verify);
    }
}

echo json_encode(['success' => true]);
