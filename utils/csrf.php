<?php
/**
 * =====================================================
 * GESTION_COLIS - CSRF Protection Utilities
 * Token de session pour protéger les formulaires POST
 * =====================================================
 */

function csrf_ensure_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
}

function csrf_token(): string {
    csrf_ensure_session();

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    $token = csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_meta_tag(): string {
    $token = csrf_token();
    return '<meta name="csrf-token" content="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_request_token(): string {
    if (isset($_POST['csrf_token'])) {
        return (string) $_POST['csrf_token'];
    }

    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return is_string($header) ? $header : '';
}

function csrf_is_valid(?string $token): bool {
    if (!$token) {
        return false;
    }

    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (!is_string($sessionToken) || $sessionToken === '') {
        return false;
    }

    return hash_equals($sessionToken, $token);
}

function csrf_invalidate(): void {
    unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
}

function csrf_fail(): void {
    http_response_code(400);

    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $hasCsrfHeader = !empty($_SERVER['HTTP_X_CSRF_TOKEN']);
    $wantsJson = $isAjax || $hasCsrfHeader || stripos($accept, 'application/json') !== false;

    $friendlyMessage = 'Votre session a expiré ou le formulaire a déjà été soumis. ' .
        'Veuillez actualiser la page puis réessayer.';

    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $friendlyMessage,
            'code' => 'csrf_invalid'
        ]);
    } else {
        echo $friendlyMessage;
    }

    exit;
}

function csrf_protect(): void {
    if (PHP_SAPI === 'cli') {
        return;
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if (strtoupper($method) !== 'POST') {
        return;
    }

    csrf_ensure_session();
    $token = csrf_request_token();

    if (!csrf_is_valid($token)) {
        csrf_fail();
    }

    // One-time token: invalidate after successful check.
    csrf_invalidate();
    $newToken = csrf_token();
    if (!headers_sent()) {
        header('X-CSRF-Token: ' . $newToken);
    }
}
