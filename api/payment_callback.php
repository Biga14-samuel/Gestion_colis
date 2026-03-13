<?php
/**
 * Webhook / callback Mobile Money (Orange Money & MTN MoMo)
 */

define('CSRF_EXEMPT', true);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/mobile_money_helper.php';

$provider = $_GET['provider'] ?? ($_POST['provider'] ?? '');
$provider = is_string($provider) ? $provider : '';

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$headers = function_exists('getallheaders') ? getallheaders() : [];

$helper = new MobileMoneyHelper();
$signatureCheck = $helper->verifyCallbackSignature($provider, $rawBody, $headers);
if (empty($signatureCheck['success'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $signatureCheck['message'] ?? 'Signature webhook invalide.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$result = $helper->handleCallback($provider, $payload, $headers);

if (empty($result['success'])) {
    http_response_code(400);
}
header('Content-Type: application/json');
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
