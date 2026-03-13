<?php
/**
 * Fichier de configuration des chemins
 * À inclure au début de chaque fichier PHP
 */

// Définir le chemin racine du projet
define('PROJECT_ROOT', dirname(__DIR__));

// Charger la configuration de la base de données
require_once PROJECT_ROOT . '/config/database.php';

/**
 * Fonction helper pour résoudre les chemins relatifs
 * @param string $path - Chemin relatif au projet
 * @return string - Chemin absolu
 */
function resolvePath($path) {
    return PROJECT_ROOT . '/' . ltrim($path, '/');
}

/**
 * Obtenir l'URL base du projet
 * @return string
 */
function getBaseUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $scriptName = dirname($_SERVER['SCRIPT_NAME']);
    if ($scriptName === '/' || $scriptName === '\\') {
        $scriptName = '';
    }
    return $protocol . '://' . $host . $scriptName;
}

/**
 * Inclure un fichier de vue avec les variables appropriées
 * @param string $view - Nom de la vue (sans extension)
 * @param array $data - Données à passer à la vue
 * @return string - Contenu HTML rendu
 */
function renderView($view, $data = []) {
    extract($data);
    $viewFile = PROJECT_ROOT . '/views/' . $view . '.php';
    
    if (file_exists($viewFile)) {
        ob_start();
        include $viewFile;
        return ob_get_clean();
    }
    
    return '<div class="alert alert-danger">Vue non trouvée: ' . htmlspecialchars($view) . '</div>';
}
