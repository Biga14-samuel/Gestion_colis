<?php
/**
 * Session hardening utilities
 * - Idle timeout
 * - Absolute timeout
 */

function session_start_if_needed(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (PHP_SAPI !== 'cli') {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
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
            setcookie(session_name(), '', [
                'expires' => $now - 42000,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => $params['secure'] ?? true,
                'httponly' => $params['httponly'] ?? true,
                'samesite' => $params['samesite'] ?? 'Lax'
            ]);
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

class SessionManager {
    public static function start(int $idleSeconds = 1800, int $absoluteSeconds = 28800): void {
        session_enforce_timeout($idleSeconds, $absoluteSeconds);
    }
}
