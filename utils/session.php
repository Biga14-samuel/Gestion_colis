<?php
/**
 * Session hardening utilities
 * - Idle timeout
 * - Absolute timeout
 */

function session_start_if_needed(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
}

function session_enforce_timeout(int $idleSeconds = 1800, int $absoluteSeconds = 28800): void {
    if (PHP_SAPI === 'cli') {
        return;
    }

    session_start_if_needed();

    $now = time();
    $startedAt = $_SESSION['__session_started_at'] ?? $now;
    $lastActivity = $_SESSION['__last_activity_at'] ?? $now;

    $idleExpired = ($now - (int) $lastActivity) > $idleSeconds;
    $absoluteExpired = ($now - (int) $startedAt) > $absoluteSeconds;

    if ($idleExpired || $absoluteExpired) {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                $now - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
        @session_start();
        $_SESSION['error'] = 'Votre session a expiré. Veuillez vous reconnecter.';
        $_SESSION['__session_started_at'] = $now;
        $_SESSION['__last_activity_at'] = $now;
        return;
    }

    $_SESSION['__session_started_at'] = $startedAt;
    $_SESSION['__last_activity_at'] = $now;
}
