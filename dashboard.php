<?php
require_once __DIR__ . '/utils/session.php';
SessionManager::start();

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Obtenir les informations de l'utilisateur
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$stmt = $db->prepare("SELECT id, nom, prenom, role, theme_preference FROM utilisateurs WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$themePreference = $user['theme_preference'] ?? ($_SESSION['theme_preference'] ?? 'light');
$_SESSION['theme_preference'] = $themePreference;

// Obtenir les statistiques selon le rôle - avec gestion d'erreurs défensive
$stats = [
    'utilisateurs' => 0,
    'colis' => 0,
    'ibox' => 0,
    'agents' => 0,
    'total_livraisons' => 0,
    'today' => 0,
    'this_week' => 0,
    'pending' => 0,
    'delivered' => 0,
    'livraisons' => 0,
    'postal_id' => 0
];
$stats_mensuelles = [];
$stats_statuts = [];
$performance = [];

function resolve_agent_id(PDO $db, int $userId): int {
    try {
        $stmt = $db->prepare("SELECT id FROM agents WHERE utilisateur_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $agentInfo = $stmt->fetch();
        if ($agentInfo && !empty($agentInfo['id'])) {
            return (int) $agentInfo['id'];
        }
    } catch (PDOException $e) {
        // Ignorer: fallback sur userId pour compatibilité legacy
    }
    return (int) $userId;
}

if ($user['role'] === 'admin') {
    try {
        $stmt = $db->query("SELECT COUNT(*) as total FROM utilisateurs");
        $result = $stmt->fetch();
        $stats['utilisateurs'] = $result['total'] ?? 0;
    } catch (PDOException $e) { /* Table users peut ne pas exister */ }
    
    try {
        $stmt = $db->query("SELECT COUNT(*) as total FROM colis");
        $result = $stmt->fetch();
        $stats['colis'] = $result['total'] ?? 0;
    } catch (PDOException $e) { /* Table colis peut ne pas exister */ }
    
    try {
        $stmt = $db->query("SELECT COUNT(*) as total FROM ibox");
        $result = $stmt->fetch();
        $stats['ibox'] = $result['total'] ?? 0;
    } catch (PDOException $e) { /* Table ibox peut ne pas exister */ }
    
    try {
        $stmt = $db->query("SELECT COUNT(*) as total FROM agents WHERE actif = 1");
        $result = $stmt->fetch();
        $stats['agents'] = $result['total'] ?? 0;
    } catch (PDOException $e) { /* Table agents peut ne pas exister */ }
    
    try {
        $stmt = $db->query("SELECT MONTH(date_creation) as mois, COUNT(*) as total FROM colis WHERE YEAR(date_creation) = YEAR(CURDATE()) GROUP BY MONTH(date_creation)");
        $stats_mensuelles = $stmt->fetchAll();
    } catch (PDOException $e) { $stats_mensuelles = []; }
    
    try {
        $stmt = $db->query("SELECT statut, COUNT(*) as total FROM colis GROUP BY statut");
        $stats_statuts = $stmt->fetchAll();
    } catch (PDOException $e) { $stats_statuts = []; }
    
} elseif ($user['role'] === 'agent') {
    // Pour les agents, récupérer les statistiques basées sur les colis
    $agent_id = resolve_agent_id($db, (int) $user['id']);
    $agentFilterSql = "(agent_id = ? OR agent_id IN (SELECT id FROM agents WHERE utilisateur_id = ?))";
    $agentFilterParams = [$agent_id, (int) $user['id']];
    
    // Récupérer les statistiques des livraisons
    // Total des livraisons assignées à cet agent
    try {
        // Compter les colis où agent_id correspond (soit dans agents.id soit dans colis.agent_id utilisant utilisateur_id)
        $stmt = $db->prepare("
            SELECT COUNT(*) as total 
            FROM colis c 
            WHERE $agentFilterSql
        ");
        $stmt->execute($agentFilterParams);
        $result = $stmt->fetch();
        $stats['total_livraisons'] = $result['total'] ?? 0;
    } catch (PDOException $e) { 
        // Si la table livraisons n'existe pas, compter directement dans colis
        try {
            $stmt = $db->prepare("SELECT COUNT(*) as total FROM colis WHERE $agentFilterSql");
            $stmt->execute($agentFilterParams);
            $result = $stmt->fetch();
            $stats['total_livraisons'] = $result['total'] ?? 0;
        } catch (PDOException $e2) {
            $stats['total_livraisons'] = 0;
        }
    }
    
    // Aujourd'hui
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as total FROM colis 
            WHERE $agentFilterSql
            AND DATE(date_creation) = CURDATE()
        ");
        $stmt->execute($agentFilterParams);
        $result = $stmt->fetch();
        $stats['today'] = $result['total'] ?? 0;
    } catch (PDOException $e) { $stats['today'] = 0; }
    
    // Cette semaine
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as total FROM colis 
            WHERE $agentFilterSql
            AND WEEK(date_creation) = WEEK(CURDATE()) AND YEAR(date_creation) = YEAR(CURDATE())
        ");
        $stmt->execute($agentFilterParams);
        $result = $stmt->fetch();
        $stats['this_week'] = $result['total'] ?? 0;
    } catch (PDOException $e) { $stats['this_week'] = 0; }
    
    // En attente (statut en_attente ou en_cours)
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as total FROM colis 
            WHERE $agentFilterSql
            AND statut IN ('en_attente', 'en_cours')
        ");
        $stmt->execute($agentFilterParams);
        $result = $stmt->fetch();
        $stats['pending'] = $result['total'] ?? 0;
    } catch (PDOException $e) { $stats['pending'] = 0; }
    
    // Livrés (statut livre ou terminee)
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as total FROM colis 
            WHERE $agentFilterSql
            AND statut IN ('livre')
        ");
        $stmt->execute($agentFilterParams);
        $result = $stmt->fetch();
        $stats['delivered'] = $result['total'] ?? 0;
    } catch (PDOException $e) { $stats['delivered'] = 0; }
    
    // Performance des 7 derniers jours
    try {
        $stmt = $db->prepare("
            SELECT DATE(date_creation) as date, COUNT(*) as total 
            FROM colis 
            WHERE $agentFilterSql
            AND date_creation >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY DATE(date_creation)
            ORDER BY date DESC
        ");
        $stmt->execute($agentFilterParams);
        $performance = $stmt->fetchAll();
    } catch (PDOException $e) { 
        $performance = [];
        // Créer des données vides pour les 7 derniers jours
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $performance[] = ['date' => $date, 'total' => 0];
        }
        $performance = array_reverse($performance);
    }
    
    $stats['livraisons'] = $stats['total_livraisons'];
    $stats['colis'] = $stats['delivered'];
    
    $stats['ibox'] = 0;
    $stats['utilisateurs'] = 1;
} else {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM colis WHERE utilisateur_id = ?");
        $stmt->execute([$user['id']]);
        $result = $stmt->fetch();
        $stats['colis'] = $result['total'] ?? 0;
    } catch (PDOException $e) { $stats['colis'] = 0; }
    
    try {
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM ibox WHERE utilisateur_id = ?");
        $stmt->execute([$user['id']]);
        $result = $stmt->fetch();
        $stats['ibox'] = $result['total'] ?? 0;
    } catch (PDOException $e) { $stats['ibox'] = 0; }
    
    try {
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM postal_id WHERE utilisateur_id = ? AND actif = 1");
        $stmt->execute([$user['id']]);
        $result = $stmt->fetch();
        $stats['postal_id'] = $result['total'] ?? 0;
    } catch (PDOException $e) { $stats['postal_id'] = 0; }
    
    $stats['utilisateurs'] = 1;
}

// Obtenir les colis récents - avec gestion d'erreurs défensive
$colis_recents = [];
try {
    if ($user['role'] === 'admin') {
        $stmt = $db->query("SELECT c.*, u.nom, u.prenom FROM colis c JOIN utilisateurs u ON c.utilisateur_id = u.id ORDER BY c.date_creation DESC LIMIT 8");
        $colis_recents = $stmt->fetchAll();
    } elseif ($user['role'] === 'agent') {
        // Pour les agents, récupérer les colis assignés directement depuis la table colis
        $agent_id_for_history = $agent_id ?? $user['id'];
        $stmt = $db->prepare("
            SELECT c.*, u.nom, u.prenom 
            FROM colis c 
            LEFT JOIN utilisateurs u ON c.utilisateur_id = u.id 
            WHERE c.agent_id = ? OR c.agent_id IN (SELECT id FROM agents WHERE utilisateur_id = ?)
            ORDER BY c.date_creation DESC LIMIT 8
        ");
        $stmt->execute([$agent_id_for_history, $user['id']]);
        $colis_recents = $stmt->fetchAll();
    } else {
        $stmt = $db->prepare("SELECT * FROM colis WHERE utilisateur_id = ? ORDER BY date_creation DESC LIMIT 8");
        $stmt->execute([$user['id']]);
        $colis_recents = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    $colis_recents = [];
}
?>

<!DOCTYPE html>
<html lang="fr" data-theme="<?= htmlspecialchars($themePreference) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo csrf_meta_tag(); ?>
    <script>
        (function() {
            const theme = <?php echo json_encode($themePreference); ?>;
            if (theme) {
                document.documentElement.setAttribute('data-theme', theme);
                localStorage.setItem('theme', theme);
            }
        })();
    </script>
    <title>Tableau de Bord - Gestion_Colis</title>
    
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Orbitron:wght@500;600;700;800&family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="assets/js/main.js"></script>
    <link rel="stylesheet" href="assets/css/style.css">
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/images/favicon.png" sizes="32x32">
    <link rel="apple-touch-icon" href="assets/images/favicon.png">
    <link rel="shortcut icon" href="assets/images/favicon.png">
    
    <style>
        /* ========================================
           THÈME PROFESSIONNEL BLANC - DASHBOARD
           ======================================== */
        
        :root {
            --primary-blue: #2563EB;
            --primary-cyan: #0891B2;
            --success-green: #059669;
            --warning-orange: #D97706;
            --danger-red: #DC2626;
            --purple: #7C3AED;
            
            /* Couleurs professionnelles blanches */
            --white: #FFFFFF;
            --bg-light: #F8FAFC;
            --bg-gray: #F1F5F9;
            --border-light: #E2E8F0;
            --border-medium: #CBD5E1;
            --surface-card: #FFFFFF;
            --overlay-bg: rgba(248, 250, 252, 0.95);
            
            /* Textes haute lisibilité */
            --text-dark: #0F172A;
            --text-dark-gray: #1E293B;
            --text-medium: #475569;
            --text-light: #64748B;
            
            /* Sidebar */
            --sidebar-width: 250px;
            --sidebar-collapsed-width: 70px;
        }

        [data-theme="dark"] {
            --bg-light: #0B1120;
            --bg-gray: #0F172A;
            --border-light: #1F2937;
            --border-medium: #334155;
            --surface-card: #0F172A;
            --overlay-bg: rgba(2, 6, 23, 0.9);

            --text-dark: #E2E8F0;
            --text-dark-gray: #CBD5E1;
            --text-medium: #94A3B8;
            --text-light: #94A3B8;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', 'Rajdhani', sans-serif;
            background: var(--bg-light);
            color: var(--text-dark);
            line-height: 1.6;
            font-size: 15px;
            min-width: 320px;
            overflow-x: hidden;
            overflow-y: auto;
        }
        
        /* Layout principal */
        .app-container {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }
        
        /* Sidebar - Style professionnel foncé */
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, #1E293B 0%, #0F172A 100%);
            border-right: 3px solid var(--primary-cyan);
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 4px 0 15px rgba(0, 0, 0, 0.15);
            transition: width 0.3s ease, transform 0.3s ease;
        }
        
        /* Sidebar collapsed state */
        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
        }
        
        /* Hide scrollbar but keep functionality */
        .sidebar::-webkit-scrollbar {
            width: 6px;
        }
        
        .sidebar::-webkit-scrollbar-thumb {
            background: var(--primary-cyan);
            border-radius: 3px;
        }
        
        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 2px solid rgba(8, 145, 178, 0.3);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(0, 0, 0, 0.2);
            min-height: 80px;
            white-space: nowrap;
            overflow: hidden;
        }
        
        .logo {
            font-family: 'Orbitron', sans-serif;
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--white);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .logo i {
            color: var(--primary-cyan);
            font-size: 1.4rem;
            flex-shrink: 0;
        }
        
        .logo span {
            white-space: nowrap;
            overflow: hidden;
            transition: opacity 0.3s ease;
        }
        
        .sidebar-toggle {
            background: var(--primary-cyan);
            border: 2px solid var(--white);
            color: var(--white);
            width: 36px;
            height: 36px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            flex-shrink: 0;
        }
        
        .sidebar-toggle:hover {
            background: var(--surface-card);
            color: var(--primary-cyan);
        }
        
        .sidebar-nav {
            padding: 1rem 0;
        }
        
        .nav-section {
            margin-bottom: 0.75rem;
        }
        
        .nav-section-title {
            font-family: 'Orbitron', sans-serif;
            font-size: 0.7rem;
            color: var(--primary-cyan);
            text-transform: uppercase;
            letter-spacing: 1.5px;
            padding: 0.75rem 1.5rem;
            font-weight: 700;
            opacity: 0.9;
            white-space: nowrap;
            overflow: hidden;
            transition: opacity 0.3s ease;
        }
        
        .nav-item {
            margin: 0.15rem 0.75rem;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 1.25rem;
            color: #E2E8F0;
            text-decoration: none;
            transition: all 0.25s ease;
            position: relative;
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            background: none;
            width: calc(100% - 1.5rem);
            text-align: left;
            font-family: inherit;
            border-radius: 8px;
            white-space: nowrap;
            overflow: hidden;
        }
        
        .nav-link:hover, .nav-link.active {
            color: var(--white);
            background: rgba(8, 145, 178, 0.25);
        }
        
        .nav-link.active {
            background: rgba(8, 145, 178, 0.3);
            border-left: 3px solid var(--primary-cyan);
        }
        
        .nav-link i {
            width: 22px;
            text-align: center;
            color: var(--primary-cyan);
            font-size: 1.05rem;
            flex-shrink: 0;
        }
        
        .nav-link:hover i, .nav-link.active i {
            color: var(--white);
        }
        
        .nav-link span {
            white-space: nowrap;
            overflow: hidden;
            transition: opacity 0.3s ease;
        }
        
        /* Hide text when collapsed */
        .sidebar.collapsed .logo span,
        .sidebar.collapsed .nav-section-title,
        .sidebar.collapsed .nav-link span {
            opacity: 0;
            width: 0;
            display: none;
        }
        
        .sidebar.collapsed .nav-link {
            justify-content: center;
            padding: 0.875rem;
        }
        
        .sidebar.collapsed .sidebar-header {
            justify-content: center;
            padding: 1.5rem 0.75rem;
        }
        
        /* Contenu principal */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
            width: calc(100% - var(--sidebar-width));
            position: relative;
            overflow-x: hidden;
        }
        
        .main-content.sidebar-collapsed {
            margin-left: 70px !important;
            width: calc(100% - 70px) !important;
        }
        
        /* En-tête */
        .header {
            background: var(--surface-card);
            border-bottom: 2px solid var(--primary-cyan);
            padding: 0.75rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 998;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
            flex-shrink: 0;
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .menu-toggle-btn {
            background: var(--primary-cyan);
            border: 2px solid var(--primary-cyan);
            color: var(--white);
            width: 40px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }
        
        .menu-toggle-btn:hover {
            background: var(--surface-card);
            color: var(--primary-cyan);
        }
        
        .page-title {
            font-family: 'Orbitron', sans-serif;
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--text-dark);
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 1.25rem;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 1rem;
            background: var(--bg-gray);
            border: 2px solid var(--border-light);
            border-radius: 10px;
        }
        
        .user-avatar {
            width: 38px;
            height: 38px;
            background: linear-gradient(135deg, var(--primary-cyan), var(--primary-blue));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 700;
            font-size: 0.85rem;
            border: 2px solid var(--white);
        }
        
        .user-name {
            font-family: 'Rajdhani', sans-serif;
            color: var(--text-dark);
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .user-role {
            color: var(--primary-cyan);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        
        .logout-btn {
            padding: 0.6rem 1.25rem;
            background: var(--danger-red);
            color: var(--white);
            border: 2px solid var(--danger-red);
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            transition: all 0.3s ease;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .logout-btn:hover {
            background: var(--surface-card);
            color: var(--danger-red);
        }
        
        /* Zone de contenu */
        .content-area {
            padding: 1rem 1.25rem;
            flex: 1;
            width: 100%;
            max-width: 100%;
            overflow-y: auto;
            overflow-x: hidden;
            min-height: calc(100vh - 60px);
        }
        
        /* Messages */
        .message {
            padding: 0.75rem 1.25rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.9rem;
            font-weight: 500;
            border: 2px solid;
        }
        
        .success-message {
            background: rgba(5, 150, 105, 0.1);
            color: var(--success-green);
            border-color: var(--success-green);
        }
        
        .error-message {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger-red);
            border-color: var(--danger-red);
        }
        
        /* Titres de section */
        .section-title {
            font-family: 'Orbitron', sans-serif;
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 1rem;
            padding-left: 0.6rem;
            border-left: 3px solid var(--primary-cyan);
        }
        
        /* Grille de statistiques - 4 cartes alignées */
        .stats-section {
            margin-bottom: 1.5rem;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
        }
        
        .stat-card {
            background: var(--surface-card);
            border-radius: 12px;
            padding: 1.25rem;
            transition: all 0.3s ease;
            position: relative;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border: none;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }
        
        .stat-card.card-green::before { background: linear-gradient(90deg, #10B981, #059669); }
        .stat-card.card-blue::before { background: linear-gradient(90deg, #3B82F6, #2563EB); }
        .stat-card.card-orange::before { background: linear-gradient(90deg, #F59E0B, #D97706); }
        .stat-card.card-purple::before { background: linear-gradient(90deg, #8B5CF6, #7C3AED); }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }
        
        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.75rem;
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            color: var(--white);
        }
        
        .stat-icon.green { background: linear-gradient(135deg, #10B981, #059669); }
        .stat-icon.blue { background: linear-gradient(135deg, #3B82F6, #2563EB); }
        .stat-icon.orange { background: linear-gradient(135deg, #F59E0B, #D97706); }
        .stat-icon.purple { background: linear-gradient(135deg, #8B5CF6, #7C3AED); }
        
        .stat-content {
            display: flex;
            flex-direction: column;
        }
        
        .stat-number {
            font-family: 'Orbitron', sans-serif;
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
            line-height: 1;
        }
        
        .stat-label {
            color: var(--text-medium);
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .stat-trend {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 0.5rem;
        }
        
        .stat-trend.up { color: #10B981; }
        .stat-trend.down { color: #EF4444; }
        
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 600px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Section principale avec colonnes */
        .dashboard-main {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        @media (max-width: 1024px) {
            .dashboard-main {
                grid-template-columns: 1fr;
            }
        }
        
        .panel-card {
            background: var(--surface-card);
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            overflow: hidden;
        }
        
        .panel-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .panel-title {
            font-family: 'Orbitron', sans-serif;
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .panel-title i {
            color: var(--primary-cyan);
        }
        
        .panel-body {
            padding: 1rem 1.25rem;
        }
        
        /* Graphiques - Section bas de page */
        .charts-section {
            margin-bottom: 1.5rem;
        }
        
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }
        
        @media (max-width: 1024px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .chart-card {
            background: var(--surface-card);
            border-radius: 12px;
            padding: 1.25rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
        }
        
        .chart-card:hover {
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }
        
        .chart-title {
            font-family: 'Orbitron', sans-serif;
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .chart-title i {
            color: var(--primary-cyan);
        }
        
        .chart-container {
            position: relative;
            height: 280px;
        }
        
        /* Activité récente - Liste compacte */
        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            max-height: 320px;
            overflow-y: auto;
        }
        
        .activity-list::-webkit-scrollbar {
            width: 4px;
        }
        
        .activity-list::-webkit-scrollbar-thumb {
            background: var(--border-medium);
            border-radius: 2px;
        }
        
        .activity-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            background: var(--bg-gray);
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        
        .activity-item:hover {
            background: rgba(8, 145, 178, 0.08);
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary-cyan), var(--primary-blue));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: 1rem;
            flex-shrink: 0;
        }
        
        .activity-content {
            flex: 1;
            min-width: 0;
        }
        
        .activity-title {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.9rem;
            margin-bottom: 0.1rem;
        }
        
        .activity-description {
            color: var(--text-light);
            font-size: 0.8rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .activity-meta {
            text-align: right;
            flex-shrink: 0;
        }
        
        .activity-date {
            color: var(--text-medium);
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        /* Badges de statut */
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            margin-top: 0.5rem;
            border: 1.5px solid;
        }
        
        .status-en-attente {
            background: rgba(217, 119, 6, 0.1);
            color: var(--warning-orange);
            border-color: var(--warning-orange);
        }
        
        .status-en-livraison {
            background: rgba(8, 145, 178, 0.1);
            color: var(--primary-cyan);
            border-color: var(--primary-cyan);
        }
        
        .status-livre {
            background: rgba(5, 150, 105, 0.1);
            color: var(--success-green);
            border-color: var(--success-green);
        }
        
        .status-annule {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger-red);
            border-color: var(--danger-red);
        }
        
        /* Actions rapides - Boutons stylisés */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }
        
        .quick-action-btn {
            padding: 1rem;
            background: var(--bg-gray);
            border: none;
            border-radius: 10px;
            color: var(--text-dark);
            text-decoration: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            cursor: pointer;
            font-family: inherit;
            min-height: 80px;
        }
        
        .quick-action-btn:hover {
            background: linear-gradient(135deg, var(--primary-cyan), var(--primary-blue));
            color: var(--white);
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(8, 145, 178, 0.25);
        }
        
        .quick-action-btn i {
            font-size: 1.5rem;
            color: var(--primary-cyan);
        }
        
        .quick-action-btn:hover i {
            color: var(--white);
        }
        
        /* Ancien style d'actions (pour la section en bas) */
        .actions-section {
            margin-bottom: 1.5rem;
            display: none;
        }
        
        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-fade-in {
            animation: fadeInUp 0.5s ease-out forwards;
        }
        
        /* Overlay mobile pour sidebar */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .sidebar-overlay.active {
            display: block;
            opacity: 1;
        }
        
        /* Responsive */
        @media (max-width: 1280px) {
            :root {
                --sidebar-width: 260px;
            }
            
            .stat-card {
                min-width: 160px;
            }
        }
        
        @media (max-width: 1024px) {
            :root {
                --sidebar-width: 280px;
            }
            
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
            }
            
            .main-content.sidebar-collapsed {
                margin-left: 0;
                width: 100%;
            }
            
            .menu-toggle-btn {
                display: flex;
            }
            
            /* Disable collapse on mobile */
            .sidebar-toggle {
                display: none;
            }
        }
        
        @media (max-width: 768px) {
            .header {
                padding: 1rem;
            }
            
            .content-area {
                padding: 1rem;
            }
            
            .page-title {
                font-size: 1.15rem;
            }
            
            .stats-grid {
                flex-direction: column;
                gap: 1rem;
            }
            
            .stat-card {
                min-width: auto;
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .activity-grid {
                grid-template-columns: 1fr;
            }
            
            .user-profile .user-name {
                display: none;
            }
            
            .user-profile {
                padding: 0.5rem;
            }
        }
        
        /* Overlay de chargement */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--overlay-bg);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .loading-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 4px solid rgba(8, 145, 178, 0.2);
            border-top-color: var(--primary-cyan);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .loading-text {
            margin-top: 1rem;
            color: var(--primary-cyan);
            font-family: 'Orbitron', sans-serif;
            font-size: 0.9rem;
            letter-spacing: 2px;
        }
        
        /* ========================================
           SECTION AGENTS - Styles pour l'aperçu
           ======================================== */
        
        .agents-section {
            margin-bottom: 1.5rem;
        }
        
        .agents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
        }
        
        .agent-card-mini {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 1rem;
            background: var(--surface-card);
            border: 1px solid var(--border-light);
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        
        .agent-card-mini:hover {
            border-color: var(--primary-cyan);
            box-shadow: 0 4px 12px rgba(8, 145, 178, 0.15);
            transform: translateX(3px);
        }
        
        .agent-avatar {
            width: 42px;
            height: 42px;
            background: linear-gradient(135deg, var(--primary-cyan), var(--primary-blue));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: 1rem;
            flex-shrink: 0;
        }
        
        .agent-info {
            flex: 1;
            min-width: 0;
        }
        
        .agent-name {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.9rem;
            margin-bottom: 0.15rem;
        }
        
        .agent-zone {
            font-size: 0.8rem;
            color: var(--text-light);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .agent-status {
            flex-shrink: 0;
        }
        
        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--text-light);
        }
        
        .status-dot.active {
            background: var(--success-green);
            box-shadow: 0 0 8px rgba(5, 150, 105, 0.5);
        }
        
        .empty-agents {
            grid-column: 1 / -1;
            text-align: center;
            padding: 2rem;
            color: var(--text-light);
        }
        
        .empty-agents i {
            font-size: 2.5rem;
            color: var(--border-medium);
            margin-bottom: 0.75rem;
        }
        
        .view-all-agents {
            grid-column: 1 / -1;
            padding: 0.875rem;
            background: linear-gradient(135deg, var(--primary-cyan), var(--primary-blue));
            color: var(--white);
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .view-all-agents:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(8, 145, 178, 0.3);
        }
        
        /* ========================================
           FORMULAIRES RÉDUITS - Styles compacts
           ======================================== */
        
        .form-row.compact {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.75rem;
            margin-bottom: 0.875rem;
        }
        
        .form-section-title {
            font-family: 'Orbitron', sans-serif;
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.75rem;
            padding-left: 0.5rem;
            border-left: 3px solid var(--primary-cyan);
        }
        
        /* Réduire la taille des champs de formulaire - Version compacte */
        .form-group {
            margin-bottom: 0.625rem;
        }
        
        .form-group label {
            font-size: 0.8rem;
            margin-bottom: 0.25rem;
        }
        
        .form-control {
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            border-radius: 6px;
        }
        
        textarea.form-control {
            min-height: 60px;
        }
        
        select.form-control {
            padding-right: 2rem;
        }
        
        .form-actions {
            margin-top: 0.875rem;
            padding-top: 0.75rem;
        }
        
        .form-hint {
            font-size: 0.75rem;
            margin-top: 0.2rem;
        }
        
        .btn {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            border-radius: 6px;
        }
        
        .btn-sm {
            padding: 0.35rem 0.75rem;
            font-size: 0.8rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-cyan), #0891b2);
            border: none;
        }
        
        .card {
            border-radius: 10px;
        }
        
        .card-header {
            padding: 0.75rem 1rem;
        }
        
        .card-body {
            padding: 1rem;
        }
        }
        
        .card-body {
            padding: 1.25rem;
        }
        
        .page-header {
            margin-bottom: 1.25rem;
        }
        
        .page-header h1 {
            font-size: 1.3rem;
        }
    </style>
</head>
<body>
    <!-- Overlay de chargement -->
    <div class="loading-overlay" id="loading-overlay">
        <div class="loading-spinner"></div>
        <div class="loading-text">CHARGEMENT...</div>
    </div>
    
    <!-- Overlay mobile pour sidebar -->
    <div class="sidebar-overlay" id="sidebar-overlay"></div>
    
    <div class="app-container">
        <!-- Sidebar -->
        <nav class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo" onclick="loadPage('dashboard.php', 'Tableau de Bord')">
                    <i class="fas fa-shipping-fast"></i>
                    <span>Gestion_Colis</span>
                </div>
                <button class="sidebar-toggle" id="sidebar-toggle" title="Réduire/Etendre">
                    <i class="fas fa-chevron-left"></i>
                </button>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Principal</div>
                    
                    <div class="nav-item">
                        <button class="nav-link active" onclick="loadPage('dashboard.php', 'Tableau de Bord')">
                            <i class="fas fa-tachometer-alt"></i>
                            <span>Tableau de Bord</span>
                        </button>
                    </div>
                </div>
                
                <?php if ($user['role'] === 'utilisateur'): ?>
                    <!-- Menu principal pour les utilisateurs -->
                    <div class="nav-section">
                        <div class="nav-section-title">Mon Compte</div>
                        
                        <div class="nav-item">
                            <button class="nav-link" onclick="loadPage('views/client/mon_compte.php', 'Mon Compte')">
                                <i class="fas fa-user"></i>
                                <span>Mon Compte</span>
                            </button>
                        </div>
                        
                        <div class="nav-item">
                            <button class="nav-link" onclick="loadPage('mon_postal_id.php', 'Mon Postal ID')">
                                <i class="fas fa-id-card"></i>
                                <span>Mon Postal ID</span>
                            </button>
                        </div>
                        
                        <div class="nav-item">
                            <button class="nav-link" onclick="loadPage('mes_notifications.php', 'Notifications')">
                                <i class="fas fa-bell"></i>
                                <span>Notifications</span>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Gestion des Colis -->
                    <div class="nav-section">
                        <div class="nav-section-title">Mes Colis</div>
                        
                        <div class="nav-item">
                            <button class="nav-link" onclick="loadPage('creer_colis.php', 'Créer un Colis')">
                                <i class="fas fa-plus-circle"></i>
                                <span>Créer un Colis</span>
                            </button>
                        </div>
                        
                        <div class="nav-item">
                            <button class="nav-link" onclick="loadPage('views/client/mes_colis.php', 'Mes Colis')">
                                <i class="fas fa-box"></i>
                                <span>Mes Colis</span>
                            </button>
                        </div>
                        
                        <div class="nav-item">
                            <button class="nav-link" onclick="loadPage('views/tracking.php', 'Suivi de Colis')">
                                <i class="fas fa-map-marker-alt"></i>
                                <span>Suivi de Colis</span>
                            </button>
                        </div>
                        
                        <div class="nav-item">
                            <button class="nav-link" onclick="loadPage('mes_codes_retrait.php', 'Codes de Retrait')">
                                <i class="fas fa-qrcode"></i>
                                <span>Codes de Retrait</span>
                            </button>
                        </div>
                    </div>
                    
                    <!-- iBox - consultation uniquement pour les utilisateurs -->
                    <div class="nav-section">
                        <div class="nav-section-title">Boîtes Virtuelles</div>
                        
                        <div class="nav-item">
                            <button class="nav-link" onclick="loadPage('mes_ibox.php', 'Mes iBox')">
                                <i class="fas fa-inbox"></i>
                                <span>Mes iBox</span>
                            </button>
                        </div>
                    </div>
                <?php elseif ($user['role'] === 'agent'): ?>
                    <!-- Menu principal pour les agents livreurs -->
                    <div class="nav-section">
                        <div class="nav-section-title">Mes Livraisons</div>
                        
                        <div class="nav-item">
                            <button class="nav-link" onclick="loadPage('views/agent/mes_livraisons.php', 'Mes Livraisons')">
                                <i class="fas fa-truck"></i>
                                <span>Mes Livraisons</span>
                            </button>
                        </div>
                        
                        <div class="nav-item">
                            <button class="nav-link" onclick="loadPage('agent_dashboard.php', 'Mon Dashboard')">
                                <i class="fas fa-chart-line"></i>
                                <span>Mon Dashboard</span>
                            </button>
                        </div>
                        
                        <div class="nav-item">
                            <button class="nav-link" onclick="loadPage('planning.php', 'Planning')">
                                <i class="fas fa-calendar"></i>
                                <span>Planning</span>
                            </button>
                        </div>
                    </div>
                    
                    <div class="nav-section">
                        <div class="nav-section-title">Preuves de Livraison</div>
                        
                        <div class="nav-item">
                            <button class="nav-link" onclick="loadPage('agent_pod.php', 'Scanner Signature')">
                                <i class="fas fa-signature"></i>
                                <span>Scanner Signature</span>
                            </button>
                        </div>
                        
                        <div class="nav-item">
                            <button class="nav-link" onclick="loadPage('agent_notifications.php', 'Notifications')">
                                <i class="fas fa-bell"></i>
                                <span>Notifications</span>
                            </button>
                        </div>
                    </div>
                    
                    <div class="nav-section">
                        <div class="nav-section-title">Mon Compte</div>
                        
                        <div class="nav-item">
                            <button class="nav-link" onclick="loadPage('agent_commissions.php', 'Mes Commissions')">
                                <i class="fas fa-money-bill-wave"></i>
                                <span>Mes Commissions</span>
                            </button>
                        </div>
                        
                        <div class="nav-item">
                            <button class="nav-link" onclick="loadPage('views/client/mon_compte.php', 'Mon Profil')">
                                <i class="fas fa-user-cog"></i>
                                <span>Mon Profil</span>
                            </button>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Dashboard administrateur -->
                    <div class="nav-section">
                        <div class="nav-section-title">Dashboard</div>
                        
                        <div class="nav-item">
                            <button class="nav-link" onclick="loadPage('admin_dashboard.php', 'Dashboard Administrateur')">
                                <i class="fas fa-gauge-high"></i>
                                <span>Dashboard Admin</span>
                            </button>
                        </div>
                    </div>

                    <!-- Section Gestion des Agents - Section principale et visible -->
                    <div class="nav-section">
                        <div class="nav-section-title">Gestion des Agents</div>
                        
                        <div class="nav-item">
                            <button class="nav-link active" onclick="loadPage('views/admin/gestion_agents.php', 'Agents de Livraison')">
                                <i class="fas fa-users-cog"></i>
                                <span>Agents de Livraison</span>
                            </button>
                        </div>
                        
                        <div class="nav-item">
                            <button class="nav-link" onclick="loadPage('views/admin/gestion_commissions.php', 'Commissions')">
                                <i class="fas fa-money-bill-wave"></i>
                                <span>Commissions</span>
                            </button>
                        </div>
                        
                        <div class="nav-item">
                            <button class="nav-link" onclick="loadPage('views/admin/gestion_livraisons.php', 'Assignations')">
                                <i class="fas fa-tasks"></i>
                                <span>Assignations</span>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Menu Administration -->
                    <div class="nav-section">
                        <div class="nav-section-title">Administration</div>
                        
                        <div class="nav-item">
                            <button class="nav-link" onclick="loadPage('views/admin/gestion_utilisateurs.php', 'Utilisateurs')">
                                <i class="fas fa-users"></i>
                                <span>Utilisateurs</span>
                            </button>
                        </div>
                        
                        <div class="nav-item">
                            <button class="nav-link" onclick="loadPage('admin_pickup_codes.php', 'Codes Retrait')">
                                <i class="fas fa-qrcode"></i>
                                <span>Codes Retrait</span>
                            </button>
                        </div>
                        
                        <div class="nav-item">
                            <button class="nav-link" onclick="loadPage('views/admin/gestion_ibox.php', 'Gestion iBox')">
                                <i class="fas fa-inbox"></i>
                                <span>Gestion iBox</span>
                            </button>
                        </div>
                    </div>
                    
                    <div class="nav-section">
                        <div class="nav-section-title">Suivi & Analyse</div>
                        
                        <div class="nav-item">
                            <button class="nav-link" onclick="loadPage('statistiques.php', 'Statistiques')">
                                <i class="fas fa-chart-bar"></i>
                                <span>Statistiques</span>
                            </button>
                        </div>
                        
                    </div>
                    
                    <div class="nav-section">
                        <div class="nav-section-title">Mon Compte</div>
                        
                        <div class="nav-item">
                            <button class="nav-link" onclick="loadPage('admin_timestamps.php', 'Horodatages')">
                                <i class="fas fa-clock"></i>
                                <span>Horodatages</span>
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="nav-section">
                    <div class="nav-section-title">Navigation</div>
                    
                    <div class="nav-item">
                        <button class="nav-link" onclick="loadPage('index.php', 'Accueil')">
                            <i class="fas fa-home"></i>
                            <span>Accueil</span>
                        </button>
                    </div>
                    
                    <div class="nav-item">
                        <a href="logout.php" class="nav-link">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Déconnexion</span>
                        </a>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Contenu principal -->
        <main class="main-content" id="main-content">
            <!-- En-tête -->
            <header class="header">
                <div class="header-left">
                    <button class="menu-toggle-btn" id="mobile-toggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h1 class="page-title" id="page-title">Tableau de Bord</h1>
                </div>
                
                <div class="header-right">
                    <div class="user-profile">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1)); ?>
                        </div>
                        <div>
                            <div class="user-name"><?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?></div>
                            <div class="user-role"><?php echo htmlspecialchars($user['role']); ?></div>
                        </div>
                    </div>
                    <a href="logout.php" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i>
                        Déconnexion
                    </a>
                </div>
            </header>

            <!-- Zone de contenu -->
            <div class="content-area" id="page-content">
                <!-- Messages -->
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="message success-message animate-fade-in">
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="message error-message animate-fade-in">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                    </div>
                <?php endif; ?>

                <!-- Section Statistiques - 4 cartes alignées -->
                <section class="stats-section">
                    <h2 class="section-title">Vue d'ensemble</h2>
                    <div class="stats-grid">
                        <?php if ($user['role'] === 'admin'): ?>
                            <div class="stat-card card-green animate-fade-in">
                                <div class="stat-header">
                                    <div class="stat-icon green">
                                        <i class="fas fa-users"></i>
                                    </div>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-number"><?php echo number_format($stats['utilisateurs']); ?></div>
                                    <div class="stat-label">Utilisateurs Totaux</div>
                                    <div class="stat-trend up"><i class="fas fa-arrow-up"></i> Actifs</div>
                                </div>
                            </div>
                            <div class="stat-card card-blue animate-fade-in" style="animation-delay: 0.1s;">
                                <div class="stat-header">
                                    <div class="stat-icon blue">
                                        <i class="fas fa-box"></i>
                                    </div>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-number"><?php echo number_format($stats['colis']); ?></div>
                                    <div class="stat-label">Colis Enregistrés</div>
                                    <div class="stat-trend up"><i class="fas fa-arrow-up"></i> En cours</div>
                                </div>
                            </div>
                            <div class="stat-card card-orange animate-fade-in" style="animation-delay: 0.2s;">
                                <div class="stat-header">
                                    <div class="stat-icon orange">
                                        <i class="fas fa-inbox"></i>
                                    </div>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-number"><?php echo number_format($stats['ibox']); ?></div>
                                    <div class="stat-label">iBox Créées</div>
                                    <div class="stat-trend up"><i class="fas fa-arrow-up"></i> Disponibles</div>
                                </div>
                            </div>
                            <div class="stat-card card-purple animate-fade-in" style="animation-delay: 0.3s;">
                                <div class="stat-header">
                                    <div class="stat-icon purple">
                                        <i class="fas fa-truck"></i>
                                    </div>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-number"><?php echo number_format($stats['agents']); ?></div>
                                    <div class="stat-label">Agents Actifs</div>
                                    <div class="stat-trend up"><i class="fas fa-check"></i> Opérationnels</div>
                                </div>
                            </div>
                        <?php elseif ($user['role'] === 'agent'): ?>
                            <div class="stat-card card-green animate-fade-in">
                                <div class="stat-header">
                                    <div class="stat-icon green">
                                        <i class="fas fa-shipping-fast"></i>
                                    </div>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-number"><?php echo number_format($stats['pending'] ?? 0); ?></div>
                                    <div class="stat-label">En Attente</div>
                                    <div class="stat-trend"><i class="fas fa-clock"></i> À traiter</div>
                                </div>
                            </div>
                            <div class="stat-card card-blue animate-fade-in" style="animation-delay: 0.1s;">
                                <div class="stat-header">
                                    <div class="stat-icon blue">
                                        <i class="fas fa-calendar-check"></i>
                                    </div>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-number"><?php echo number_format($stats['today'] ?? 0); ?></div>
                                    <div class="stat-label">Aujourd'hui</div>
                                    <div class="stat-trend up"><i class="fas fa-arrow-up"></i> Livraisons</div>
                                </div>
                            </div>
                            <div class="stat-card card-orange animate-fade-in" style="animation-delay: 0.2s;">
                                <div class="stat-header">
                                    <div class="stat-icon orange">
                                        <i class="fas fa-calendar-week"></i>
                                    </div>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-number"><?php echo number_format($stats['this_week'] ?? 0); ?></div>
                                    <div class="stat-label">Cette Semaine</div>
                                    <div class="stat-trend up"><i class="fas fa-chart-line"></i> Performance</div>
                                </div>
                            </div>
                            <div class="stat-card card-purple animate-fade-in" style="animation-delay: 0.3s;">
                                <div class="stat-header">
                                    <div class="stat-icon purple">
                                        <i class="fas fa-trophy"></i>
                                    </div>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-number"><?php echo number_format($stats['total_livraisons'] ?? 0); ?></div>
                                    <div class="stat-label">Total Livraisons</div>
                                    <div class="stat-trend up"><i class="fas fa-star"></i> Excellent</div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="stat-card card-green animate-fade-in">
                                <div class="stat-header">
                                    <div class="stat-icon green">
                                        <i class="fas fa-box"></i>
                                    </div>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-number"><?php echo number_format($stats['colis']); ?></div>
                                    <div class="stat-label">Mes Colis</div>
                                    <div class="stat-trend up"><i class="fas fa-arrow-up"></i> Actifs</div>
                                </div>
                            </div>
                            <div class="stat-card card-blue animate-fade-in" style="animation-delay: 0.1s;">
                                <div class="stat-header">
                                    <div class="stat-icon blue">
                                        <i class="fas fa-inbox"></i>
                                    </div>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-number"><?php echo number_format($stats['ibox']); ?></div>
                                    <div class="stat-label">Mes iBox</div>
                                    <div class="stat-trend up"><i class="fas fa-check"></i> Configurées</div>
                                </div>
                            </div>
                            <div class="stat-card card-orange animate-fade-in" style="animation-delay: 0.2s;">
                                <div class="stat-header">
                                    <div class="stat-icon orange">
                                        <i class="fas fa-id-card"></i>
                                    </div>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-number"><?php echo number_format($stats['postal_id'] ?? 0); ?></div>
                                    <div class="stat-label">Postal ID</div>
                                    <div class="stat-trend up"><i class="fas fa-shield-alt"></i> Vérifié</div>
                                </div>
                            </div>
                            <div class="stat-card card-purple animate-fade-in" style="animation-delay: 0.3s;">
                                <div class="stat-header">
                                    <div class="stat-icon purple">
                                        <i class="fas fa-bell"></i>
                                    </div>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-number">0</div>
                                    <div class="stat-label">Notifications</div>
                                    <div class="stat-trend"><i class="fas fa-check-circle"></i> À jour</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- Section Agents - Statistiques pour Admin -->
                <?php if ($user['role'] === 'admin'): ?>
                <section class="agents-section" id="agents-overview">
                    <h2 class="section-title">Agents de Livraison</h2>
                    <div class="agents-grid">
                        <?php
                        // Récupérer la liste des agents pour l'aperçu
                        $agents_preview = [];
                        try {
                            $stmt = $db->prepare("
                                SELECT u.id, u.prenom, u.nom, u.matricule, u.zone_livraison, 
                                       a.date_creation as agent_since
                                FROM agents a
                                JOIN utilisateurs u ON a.utilisateur_id = u.id
                                WHERE a.actif = 1
                                ORDER BY u.prenom, u.nom
                                LIMIT 6
                            ");
                            $stmt->execute();
                            $agents_preview = $stmt->fetchAll();
                        } catch (PDOException $e) {
                            $agents_preview = [];
                        }
                        ?>
                        <?php if (empty($agents_preview)): ?>
                            <div class="empty-agents">
                                <i class="fas fa-users-slash"></i>
                                <p>Aucun agent enregistré</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($agents_preview as $agent): ?>
                                <div class="agent-card-mini">
                                    <div class="agent-avatar">
                                        <i class="fas fa-user-tie"></i>
                                    </div>
                                    <div class="agent-info">
                                        <div class="agent-name"><?php echo htmlspecialchars($agent['prenom'] . ' ' . $agent['nom']); ?></div>
                                        <div class="agent-zone"><?php echo htmlspecialchars($agent['zone_livraison'] ?? 'Zone non définie'); ?></div>
                                    </div>
                                    <div class="agent-status">
                                        <span class="status-dot active"></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <button class="btn btn-primary view-all-agents" onclick="loadPage('views/admin/gestion_agents.php', 'Gestion des Agents')">
                                <i class="fas fa-users"></i>
                                Voir tous les agents
                            </button>
                        <?php endif; ?>
                    </div>
                </section>
                <?php endif; ?>

                <!-- Section principale : Activité + Actions Rapides côte à côte -->
                <div class="dashboard-main">
                    <!-- Panneau Activité Récente -->
                    <div class="panel-card">
                        <div class="panel-header">
                            <h3 class="panel-title">
                                <i class="fas fa-history"></i>
                                Activité Récente
                            </h3>
                            <a href="#" style="color: var(--primary-cyan); font-size: 0.85rem; font-weight: 500;">Voir tout</a>
                        </div>
                        <div class="panel-body">
                            <div class="activity-list">
                                <?php if (empty($colis_recents)): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon">
                                            <i class="fas fa-info-circle"></i>
                                        </div>
                                        <div class="activity-content">
                                            <div class="activity-title">Aucune activité récente</div>
                                            <div class="activity-description">Commencez par créer votre premier colis</div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <?php foreach (array_slice($colis_recents, 0, 5) as $index => $colis): ?>
                                        <div class="activity-item animate-fade-in" style="animation-delay: <?php echo $index * 0.1; ?>s;">
                                            <div class="activity-icon">
                                                <i class="fas fa-box"></i>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-title">
                                                    <?php 
                                                    if ($user['role'] === 'admin' || $user['role'] === 'agent') {
                                                        echo htmlspecialchars($colis['reference_colis']);
                                                    } else {
                                                        echo htmlspecialchars($colis['reference_colis']);
                                                    }
                                                    ?>
                                                </div>
                                                <div class="activity-description">
                                                    <?php echo htmlspecialchars(substr($colis['description'] ?? 'Aucune description', 0, 40)); ?>
                                                </div>
                                            </div>
                                            <div class="activity-meta">
                                                <?php if (isset($colis['statut'])): ?>
                                                    <span class="status-badge status-<?php echo str_replace(' ', '-', $colis['statut']); ?>">
                                                        <?php echo htmlspecialchars($colis['statut']); ?>
                                                    </span>
                                                <?php endif; ?>
                                                <div class="activity-date">
                                                    <?php 
                                                    $date = new DateTime($colis['date_creation'] ?? $colis['date_assignation']);
                                                    echo $date->format('d/m H:i');
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Panneau Actions Rapides -->
                    <div class="panel-card">
                        <div class="panel-header">
                            <h3 class="panel-title">
                                <i class="fas fa-bolt"></i>
                                Accès Rapide
                            </h3>
                        </div>
                        <div class="panel-body">
                            <div class="quick-actions">
                                <?php if ($user['role'] === 'utilisateur'): ?>
                                    <button class="quick-action-btn" onclick="loadPage('creer_colis.php', 'Créer un Colis')">
                                        <i class="fas fa-plus-circle"></i>
                                        Nouveau Colis
                                    </button>
                                    <button class="quick-action-btn" onclick="loadPage('views/client/mes_colis.php', 'Mes Colis')">
                                        <i class="fas fa-boxes"></i>
                                        Mes Colis
                                    </button>
                                    <button class="quick-action-btn" onclick="loadPage('mes_ibox.php', 'Mes iBox')">
                                        <i class="fas fa-inbox"></i>
                                        Mes iBox
                                    </button>
                                    <button class="quick-action-btn" onclick="loadPage('views/tracking.php', 'Suivi')">
                                        <i class="fas fa-map-marker-alt"></i>
                                        Suivi Colis
                                    </button>
                                <?php elseif ($user['role'] === 'agent'): ?>
                                    <button class="quick-action-btn" onclick="loadPage('views/agent/mes_livraisons.php', 'Mes Livraisons')">
                                        <i class="fas fa-truck"></i>
                                        Livraisons
                                    </button>
                                    <button class="quick-action-btn" onclick="loadPage('agent_pod.php', 'Preuve de Livraison')">
                                        <i class="fas fa-camera"></i>
                                        Preuve (POD)
                                    </button>
                                    <button class="quick-action-btn" onclick="loadPage('planning.php', 'Planning')">
                                        <i class="fas fa-calendar-alt"></i>
                                        Planning
                                    </button>
                                    <button class="quick-action-btn" onclick="loadPage('agent_commissions.php', 'Mes Commissions')">
                                        <i class="fas fa-money-bill-wave"></i>
                                        Commissions
                                    </button>
                                <?php else: ?>
                                    <button class="quick-action-btn" onclick="loadPage('views/admin/gestion_utilisateurs.php', 'Utilisateurs')">
                                        <i class="fas fa-users"></i>
                                        Utilisateurs
                                    </button>
                                    <button class="quick-action-btn" onclick="loadPage('views/admin/gestion_agents.php', 'Agents')">
                                        <i class="fas fa-user-tie"></i>
                                        Agents
                                    </button>
                                    <button class="quick-action-btn" onclick="loadPage('views/admin/gestion_livraisons.php', 'Assignations')">
                                        <i class="fas fa-tasks"></i>
                                        Assignations
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section Graphiques - EN BAS DE LA PAGE -->
                <?php if ($user['role'] === 'admin' && !empty($stats_mensuelles)): ?>
                    <section class="charts-section">
                        <h2 class="section-title">Analyses en Temps Réel</h2>
                        <div class="charts-grid">
                            <div class="chart-card">
                                <h3 class="chart-title">
                                    <i class="fas fa-chart-line"></i>
                                    Évolution Mensuelle (<?php echo date('Y'); ?>)
                                </h3>
                                <div class="chart-container">
                                    <canvas id="monthlyChart"></canvas>
                                </div>
                            </div>
                            
                            <?php if (!empty($stats_statuts)): ?>
                            <div class="chart-card">
                                <h3 class="chart-title">
                                    <i class="fas fa-chart-pie"></i>
                                    Répartition par Statut
                                </h3>
                                <div class="chart-container">
                                    <canvas id="statusChart"></canvas>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($user['role'] === 'agent' && !empty($performance)): ?>
                    <section class="charts-section">
                        <h2 class="section-title">Performance en Temps Réel</h2>
                        <div class="charts-grid">
                            <div class="chart-card">
                                <h3 class="chart-title">
                                    <i class="fas fa-chart-bar"></i>
                                    7 Derniers Jours
                                </h3>
                                <div class="chart-container">
                                    <canvas id="performanceChart"></canvas>
                                </div>
                            </div>
                            <div class="chart-card">
                                <h3 class="chart-title">
                                    <i class="fas fa-bullseye"></i>
                                    Objectifs Hebdomadaires
                                </h3>
                                <div class="chart-container">
                                    <canvas id="goalsChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($user['role'] === 'utilisateur'): ?>
                    <section class="charts-section">
                        <h2 class="section-title">Suivi de mes Colis</h2>
                        <div class="charts-grid">
                            <div class="chart-card">
                                <h3 class="chart-title">
                                    <i class="fas fa-chart-pie"></i>
                                    Statut de mes Colis
                                </h3>
                                <div class="chart-container">
                                    <canvas id="userColisChart"></canvas>
                                </div>
                            </div>
                            <div class="chart-card">
                                <h3 class="chart-title">
                                    <i class="fas fa-chart-area"></i>
                                    Historique des Envois
                                </h3>
                                <div class="chart-container">
                                    <canvas id="userHistoryChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        // Système SPA - Chargement dynamique des pages
        let currentPage = 'dashboard.php';
        let isSidebarCollapsed = false;
        
        async function loadPage(pageUrl, pageTitle) {
            if (currentPage === pageUrl && pageUrl !== 'dashboard.php') {
                return;
            }
            
            const sidebar = document.getElementById('sidebar');
            const sidebarOverlay = document.getElementById('sidebar-overlay');
            
            // Fermer la sidebar mobile
            if (window.innerWidth <= 1024) {
                sidebar.classList.remove('open');
                sidebarOverlay.classList.remove('active');
            }
            
            const loadingOverlay = document.getElementById('loading-overlay');
            if (typeof showLoading === 'function') {
                showLoading();
            } else if (loadingOverlay) {
                loadingOverlay.classList.add('active');
            }
            
            try {
                const response = await fetch(pageUrl, {
                    method: 'GET',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                
                if (!response.ok) {
                    throw new Error('Erreur de chargement: ' + response.status);
                }
                
                const html = await response.text();
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const parseError = doc.querySelector('parsererror');
                
                if (parseError) {
                    throw new Error('Erreur de parsing HTML');
                }
                
                const newContent = doc.querySelector('#page-content');
                
                if (newContent) {
                    document.getElementById('page-title').textContent = pageTitle;
                    const currentContent = document.getElementById('page-content');
                    currentContent.style.opacity = '0';
                    currentContent.style.transform = 'translateY(-10px)';
                    
                    setTimeout(() => {
                        currentContent.innerHTML = newContent.innerHTML;
                        currentContent.style.opacity = '1';
                        currentContent.style.transform = 'translateY(0)';
                        reloadPageScripts();
                        
                        if (pageUrl.includes('dashboard.php')) {
                            setTimeout(() => {
                                initDashboardCharts();
                            }, 100);
                        }
                        
                        updateActiveNav(pageUrl);
                        currentPage = pageUrl;
                        if (typeof hideLoading === 'function') {
                            hideLoading();
                        } else if (loadingOverlay) {
                            loadingOverlay.classList.remove('active');
                        }
                    }, 150);
                } else {
                    throw new Error('Structure de page invalide');
                }
            } catch (error) {
                console.error('Erreur:', error);
                if (typeof hideLoading === 'function') {
                    hideLoading();
                } else if (loadingOverlay) {
                    loadingOverlay.classList.remove('active');
                }
                
                const currentContent = document.getElementById('page-content');
                currentContent.innerHTML = `
                    <div class="message error-message">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div>
                            <strong>Erreur de chargement</strong>
                            <p>${error.message}</p>
                        </div>
                    </div>
                `;
            }
        }
        
        function updateActiveNav(pageUrl) {
            const navLinks = document.querySelectorAll('.nav-link');
            navLinks.forEach(link => {
                link.classList.remove('active');
                const onclickAttr = link.getAttribute('onclick');
                if (onclickAttr && onclickAttr.includes(pageUrl)) {
                    link.classList.add('active');
                }
            });
        }
        
        function reloadPageScripts() {
            // Supprimer les anciens styles de page pour éviter les conflits
            const oldStyles = document.querySelectorAll('style[data-page-style="true"]');
            oldStyles.forEach(style => style.remove());
            
            // Gestion des balises <style> pour appliquer les CSS
            const styles = document.querySelectorAll('#page-content style');
            styles.forEach(oldStyle => {
                // Vérifier si un style avec le même contenu existe déjà
                const newStyle = document.createElement('style');
                newStyle.textContent = oldStyle.textContent;
                newStyle.setAttribute('data-page-style', 'true');
                
                // Déplacer le style dans le head pour qu'il soit appliqué
                document.head.appendChild(newStyle);
                oldStyle.remove();
            });
            
            // Gestion des balises <script>
            const scripts = document.querySelectorAll('#page-content script');
            scripts.forEach(oldScript => {
                const newScript = document.createElement('script');
                newScript.type = oldScript.type || 'text/javascript';
                if (oldScript.src) {
                    newScript.src = oldScript.src;
                } else {
                    newScript.textContent = oldScript.textContent;
                }
                oldScript.parentNode.replaceChild(newScript, oldScript);
            });
        }
        
        // Gestion du toggle sidebar (collapse/expand)
        function initSidebarToggle() {
            const sidebarToggle = document.getElementById('sidebar-toggle');
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('main-content');
            
            if (!sidebarToggle || !sidebar || !mainContent) {
                console.error('Éléments du sidebar non trouvés');
                return;
            }
            
            sidebarToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                isSidebarCollapsed = !isSidebarCollapsed;
                
                if (isSidebarCollapsed) {
                    sidebar.classList.add('collapsed');
                    mainContent.classList.add('sidebar-collapsed');
                    
                    // Changer l'icône
                    const icon = this.querySelector('i');
                    if (icon) {
                        icon.classList.remove('fa-chevron-left');
                        icon.classList.add('fa-chevron-right');
                    }
                } else {
                    sidebar.classList.remove('collapsed');
                    mainContent.classList.remove('sidebar-collapsed');
                    
                    // Changer l'icône
                    const icon = this.querySelector('i');
                    if (icon) {
                        icon.classList.remove('fa-chevron-right');
                        icon.classList.add('fa-chevron-left');
                    }
                }
                
                // Sauvegarder l'état dans localStorage
                localStorage.setItem('sidebarCollapsed', isSidebarCollapsed);
            });
        }
        
        // Toggle mobile (ouvrir la sidebar)
        function initMobileToggle() {
            const mobileToggle = document.getElementById('mobile-toggle');
            const sidebar = document.getElementById('sidebar');
            const sidebarOverlay = document.getElementById('sidebar-overlay');
            
            if (!mobileToggle || !sidebar || !sidebarOverlay) {
                return;
            }
            
            mobileToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                sidebar.classList.toggle('open');
                sidebarOverlay.classList.toggle('active');
            });
            
            // Fermer en cliquant sur l'overlay
            sidebarOverlay.addEventListener('click', function() {
                sidebar.classList.remove('open');
                this.classList.remove('active');
            });
        }
        
        // Fermer sidebar mobile au clic sur le contenu
        function initCloseOnContentClick() {
            const mainContent = document.getElementById('main-content');
            const sidebar = document.getElementById('sidebar');
            const sidebarOverlay = document.getElementById('sidebar-overlay');
            
            if (!mainContent || !sidebar) {
                return;
            }
            
            mainContent.addEventListener('click', function(e) {
                // Ne pas fermer si on clique sur certains éléments
                if (e.target.closest('.action-btn') || 
                    e.target.closest('button') || 
                    e.target.closest('a')) {
                    return;
                }
                
                if (window.innerWidth <= 1024 && sidebar.classList.contains('open')) {
                    sidebar.classList.remove('open');
                    sidebarOverlay.classList.remove('active');
                }
            });
        }
        
        // Charger l'état de la sidebar au démarrage
        function loadSidebarState() {
            const savedState = localStorage.getItem('sidebarCollapsed');
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('main-content');
            const sidebarToggle = document.getElementById('sidebar-toggle');
            
            // Par défaut, la sidebar est étendue (comportement normal)
            // On ne réduit que si l'utilisateur a explicitement demandé à réduire
            if (savedState === 'true' && sidebar && mainContent) {
                isSidebarCollapsed = true;
                sidebar.classList.add('collapsed');
                mainContent.classList.add('sidebar-collapsed');
                
                if (sidebarToggle) {
                    const icon = sidebarToggle.querySelector('i');
                    if (icon) {
                        icon.classList.remove('fa-chevron-left');
                        icon.classList.add('fa-chevron-right');
                    }
                }
            } else {
                // S'assurer que la sidebar est étendue par défaut
                if (sidebar && mainContent) {
                    sidebar.classList.remove('collapsed');
                    mainContent.classList.remove('sidebar-collapsed');
                }
                if (sidebarToggle) {
                    const icon = sidebarToggle.querySelector('i');
                    if (icon) {
                        icon.classList.remove('fa-chevron-right');
                        icon.classList.add('fa-chevron-left');
                    }
                }
            }
        }
        
        // Empêcher la propagation des clics dans la sidebar
        function initSidebarClickStop() {
            const sidebar = document.getElementById('sidebar');
            if (sidebar) {
                sidebar.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            }
        }
        
        // Initialisation des graphiques
        Chart.defaults.color = '#475569';
        Chart.defaults.borderColor = 'rgba(8, 145, 178, 0.15)';
        
        function initDashboardCharts() {
            // S'assurer que Chart.js est chargé
            if (typeof Chart === 'undefined') {
                console.error('Chart.js n\'est pas chargé');
                return;
            }
            
            // Graphique mensuel
            const monthlyChartEl = document.getElementById('monthlyChart');
            if (monthlyChartEl && typeof monthlyChartData !== 'undefined') {
                if (monthlyChartEl.chart) monthlyChartEl.chart.destroy();
                try {
                    monthlyChartEl.chart = new Chart(monthlyChartEl.getContext('2d'), {
                        type: 'line',
                        data: monthlyChartData,
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { labels: { color: '#1E293B', font: { weight: 'bold' } } }
                            },
                            scales: {
                                x: { ticks: { color: '#475569', font: { weight: 'bold' } }, grid: { color: 'rgba(8, 145, 178, 0.1)' } },
                                y: { ticks: { color: '#475569', font: { weight: 'bold' } }, grid: { color: 'rgba(8, 145, 178, 0.1)' } }
                            }
                        }
                    });
                } catch (e) {
                    console.error('Erreur création graphique mensuel:', e);
                }
            }
            
            // Graphique des statuts
            const statusChartEl = document.getElementById('statusChart');
            if (statusChartEl && typeof statusChartData !== 'undefined') {
                if (statusChartEl.chart) statusChartEl.chart.destroy();
                try {
                    statusChartEl.chart = new Chart(statusChartEl.getContext('2d'), {
                        type: 'doughnut',
                        data: statusChartData,
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { position: 'bottom', labels: { color: '#1E293B', padding: 15, font: { weight: 'bold' } } }
                            }
                        }
                    });
                } catch (e) {
                    console.error('Erreur création graphique statuts:', e);
                }
            }
            
            // Graphique de performance
            const performanceChartEl = document.getElementById('performanceChart');
            if (performanceChartEl && typeof performanceChartData !== 'undefined') {
                if (performanceChartEl.chart) performanceChartEl.chart.destroy();
                try {
                    performanceChartEl.chart = new Chart(performanceChartEl.getContext('2d'), {
                        type: 'bar',
                        data: performanceChartData,
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { labels: { color: '#1E293B', font: { weight: 'bold' } } }
                            },
                            scales: {
                                x: { ticks: { color: '#475569', font: { weight: 'bold' } }, grid: { color: 'rgba(8, 145, 178, 0.1)' } },
                                y: { ticks: { color: '#475569', font: { weight: 'bold' } }, grid: { color: 'rgba(8, 145, 178, 0.1)' } }
                            }
                        }
                    });
                } catch (e) {
                    console.error('Erreur création graphique performance:', e);
                }
            }
            
            // Graphique userColisChart
            const userColisChartEl = document.getElementById('userColisChart');
            if (userColisChartEl && typeof userColisChartData !== 'undefined') {
                if (userColisChartEl.chart) userColisChartEl.chart.destroy();
                try {
                    userColisChartEl.chart = new Chart(userColisChartEl.getContext('2d'), {
                        type: 'doughnut',
                        data: userColisChartData,
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { position: 'bottom', labels: { color: '#1E293B', padding: 15, font: { weight: 'bold' } } }
                            }
                        }
                    });
                } catch (e) {
                    console.error('Erreur création graphique userColisChart:', e);
                }
            }
            
            // Graphique userHistoryChart
            const userHistoryChartEl = document.getElementById('userHistoryChart');
            if (userHistoryChartEl && typeof userHistoryChartData !== 'undefined') {
                if (userHistoryChartEl.chart) userHistoryChartEl.chart.destroy();
                try {
                    userHistoryChartEl.chart = new Chart(userHistoryChartEl.getContext('2d'), {
                        type: 'line',
                        data: userHistoryChartData,
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { labels: { color: '#1E293B', font: { weight: 'bold' } } }
                            },
                            scales: {
                                x: { ticks: { color: '#475569', font: { weight: 'bold' } }, grid: { color: 'rgba(8, 145, 178, 0.1)' } },
                                y: { ticks: { color: '#475569', font: { weight: 'bold' } }, grid: { color: 'rgba(8, 145, 178, 0.1)' } }
                            }
                        }
                    });
                } catch (e) {
                    console.error('Erreur création graphique userHistoryChart:', e);
                }
            }
        }
        
        // Données des graphiques
        <?php if ($user['role'] === 'admin'): ?>
            <?php if (!empty($stats_mensuelles)): ?>
            monthlyChartData = {
                labels: <?php echo json_encode(array_map(function($item) {
                    $mois = ['', 'Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
                    return $mois[$item['mois']] ?? 'Mois ' . $item['mois'];
                }, $stats_mensuelles)); ?>,
                datasets: [{
                    label: 'Nombre de Colis',
                    data: <?php echo json_encode(array_column($stats_mensuelles, 'total')); ?>,
                    borderColor: '#0891B2',
                    backgroundColor: 'rgba(8, 145, 178, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#0891B2',
                    pointBorderColor: '#FFFFFF',
                    pointBorderWidth: 2,
                    pointRadius: 6
                }]
            };
            <?php endif; ?>
            
            <?php if (!empty($stats_statuts)): ?>
            statusChartData = {
                labels: <?php echo json_encode(array_column($stats_statuts, 'statut')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($stats_statuts, 'total')); ?>,
                    backgroundColor: ['#059669', '#D97706', '#3B82F6', '#7C3AED', '#DC2626'],
                    borderWidth: 3,
                    borderColor: '#FFFFFF'
                }]
            };
            <?php endif; ?>
        <?php endif; ?>
        
        <?php if ($user['role'] === 'agent' && !empty($performance)): ?>
            performanceChartData = {
                labels: <?php echo json_encode(array_map(function($item) { return date('d/m', strtotime($item['date'])); }, $performance)); ?>,
                datasets: [{
                    label: 'Livraisons',
                    data: <?php echo json_encode(array_column($performance, 'total')); ?>,
                    backgroundColor: 'rgba(8, 145, 178, 0.85)',
                    borderColor: '#0891B2',
                    borderWidth: 2,
                    borderRadius: 6
                }]
            };
        <?php endif; ?>
        
        // Initialisation au chargement
        document.addEventListener('DOMContentLoaded', function() {
            // Charger l'état de la sidebar
            loadSidebarState();
            
            // Initialiser les toggles
            initSidebarToggle();
            initMobileToggle();
            initCloseOnContentClick();
            initSidebarClickStop();
            
            // Initialiser les graphiques
            initDashboardCharts();
            
            console.log('%c🚀 Gestion_Colis - Dashboard', 'color: #0891B2; font-size: 16px; font-weight: bold;');
            console.log('👤 Utilisateur: <?php echo $user["prenom"] . " " . $user["nom"]; ?>');
            console.log('🔑 Rôle: <?php echo $user["role"]; ?>');
            console.log('📊 État de la sidebar: ' + (isSidebarCollapsed ? 'Réduite' : 'Étendue'));
        });
    </script>
</body>
</html>
