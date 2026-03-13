<?php
/**
 * =====================================================
 * GESTION_COLIS - CONFIGURATION BASE DE DONNÉES
 * Connexion MySQL avec PDO
 * =====================================================
 */

function is_development_env(): bool {
    $appEnv = strtolower((string) getenv('APP_ENV'));
    if (in_array($appEnv, ['dev', 'development', 'local'], true)) {
        return true;
    }
    if (PHP_SAPI === 'cli') {
        return true;
    }
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $addr = $_SERVER['REMOTE_ADDR'] ?? '';
    return $addr === '127.0.0.1' || $addr === '::1' || stripos($host, 'localhost') !== false;
}

$isDev = is_development_env();

if (!$isDev) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
}

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');

    $isHttps = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    if (!$isHttps && !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $isHttps = strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
    }
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
$envHost = getenv('DB_HOST');
$envName = getenv('DB_NAME');
$envUser = getenv('DB_USER');
$envPass = getenv('DB_PASS');
$hasRequired = $envHost !== false && $envName !== false && $envUser !== false && $envPass !== false;

if (!$isDev && !$hasRequired) {
    http_response_code(500);
    die("Configuration DB manquante. Veuillez définir DB_HOST, DB_NAME, DB_USER et DB_PASS.");
}

// Constantes pour la compatibilité avec les fichiers utilisant PDO directement
define('DB_HOST', $envHost !== false ? $envHost : ($isDev ? 'localhost' : ''));
define('DB_NAME', $envName !== false ? $envName : ($isDev ? 'gestion_colis' : ''));
define('DB_USER', $envUser !== false ? $envUser : ($isDev ? 'root' : ''));
define('DB_PASS', $envPass !== false ? $envPass : ($isDev ? '' : ''));
define('DB_DSN', 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4');

require_once __DIR__ . '/../utils/session.php';
require_once __DIR__ . '/../utils/csrf.php';
SessionManager::start();
if (!defined('CSRF_EXEMPT') || CSRF_EXEMPT !== true) {
    csrf_protect();
}

/**
 * Retourne un message utilisateur générique et journalise l'exception.
 */
function user_error_message(Throwable $e, string $context, string $fallback = 'Une erreur est survenue. Veuillez réessayer.'): string {
    error_log("[$context] " . $e->getMessage());
    return $fallback;
}

class Database {
    private $host = '';
    private $db_name = '';
    private $username = '';
    private $password = '';
    private $conn = null;
    private $shutdownRegistered = false;
    
    public function __construct() {
        $this->host = DB_HOST;
        $this->db_name = DB_NAME;
        $this->username = DB_USER;
        $this->password = DB_PASS;
    }
    
    public function getConnection() {
        if ($this->conn === null) {
            try {
                $this->conn = new PDO(
                    "mysql:host={$this->host};dbname={$this->db_name};charset=utf8mb4",
                    $this->username,
                    $this->password,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false
                    ]
                );
            } catch (PDOException $e) {
                // Enregistrer l'erreur mais ne pas révéler les détails en production
                error_log("Erreur de connexion: " . $e->getMessage());
                die("Erreur de connexion à la base de données. Veuillez vérifier la configuration.");
            }
        }

        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            $self = $this;
            register_shutdown_function(function () use ($self) {
                $self->closeConnection();
            });
        }
        
        return $this->conn;
    }
    
    public function closeConnection() {
        $this->conn = null;
    }

    public function __destruct() {
        $this->conn = null;
    }
}
