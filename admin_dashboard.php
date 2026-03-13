<?php
/**
 * Dashboard Administrateur - Gestion_Colis
 * Vue complète et supervisée de l'application
 */

require_once __DIR__ . '/utils/session.php';
SessionManager::start();
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Vérifier si l'utilisateur est connecté et est admin
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$stmt = $db->prepare("SELECT * FROM utilisateurs WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user || $user['role'] !== 'admin') {
    session_destroy();
    header('Location: login.php');
    exit;
}

// ============================================
// STATISTIQUES GLOBALES
// ============================================

// Utilisateurs
$stmt = $db->query("SELECT COUNT(*) FROM utilisateurs");
$total_users = $stmt->fetchColumn();

// Répartition par rôle
$stmt = $db->query("SELECT role, COUNT(*) as count FROM utilisateurs GROUP BY role");
$users_by_role = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Nouveaux utilisateurs (30 derniers jours)
$stmt = $db->query("SELECT COUNT(*) FROM utilisateurs WHERE date_creation >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$new_users_30d = $stmt->fetchColumn();

// Colis
$stmt = $db->query("SELECT COUNT(*) FROM colis");
$total_colis = $stmt->fetchColumn();

// Colis par statut
$stmt = $db->query("SELECT statut, COUNT(*) as count FROM colis GROUP BY statut");
$colis_by_status = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Colis du mois
$stmt = $db->query("SELECT COUNT(*) FROM colis WHERE date_creation >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$colis_30d = $stmt->fetchColumn();

// Chiffre d'affaires (somme des paiements)
$stmt = $db->query("SELECT COALESCE(SUM(montant), 0) FROM paiements WHERE statut = 'paye'");
$total_revenue = $stmt->fetchColumn();

// Revenus du mois
$stmt = $db->query("SELECT COALESCE(SUM(montant), 0) FROM paiements WHERE statut = 'paye' AND date_paiement >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$revenue_30d = $stmt->fetchColumn();

// iBox
$stmt = $db->query("SELECT COUNT(*) FROM ibox");
$total_ibox = $stmt->fetchColumn();

// iBox par statut
$stmt = $db->query("SELECT statut, COUNT(*) as count FROM ibox GROUP BY statut");
$ibox_by_status = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Agents
$stmt = $db->query("SELECT COUNT(*) FROM agents");
$total_agents = $stmt->fetchColumn();

// Agents actifs
$stmt = $db->query("SELECT COUNT(*) FROM agents WHERE actif = 1");
$active_agents = $stmt->fetchColumn();

// Livraisons du jour
$stmt = $db->query("SELECT COUNT(*) FROM livraisons WHERE DATE(date_assignation) = CURDATE()");
$livraisons_today = $stmt->fetchColumn();

// Livraisons du mois
$stmt = $db->query("SELECT COUNT(*) FROM livraisons WHERE date_assignation >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$livraisons_30d = $stmt->fetchColumn();

// ============================================
// DONNÉES POUR LES TABLEAUX
// ============================================

// Derniers utilisateurs inscrits
$stmt = $db->query("SELECT * FROM utilisateurs ORDER BY date_creation DESC LIMIT 10");
$recent_users = $stmt->fetchAll();

// Derniers colis créés
$stmt = $db->query("
    SELECT c.*, u.prenom, u.nom, u.email 
    FROM colis c 
    JOIN utilisateurs u ON c.utilisateur_id = u.id 
    ORDER BY c.date_creation DESC 
    LIMIT 15
");
$recent_colis = $stmt->fetchAll();

// Activité des agents
$stmt = $db->query("
    SELECT a.*, u.prenom, u.nom, u.email,
           (SELECT COUNT(*) FROM livraisons WHERE agent_id = a.id) as total_livraisons
    FROM agents a 
    JOIN utilisateurs u ON a.utilisateur_id = u.id 
    ORDER BY total_livraisons DESC 
    LIMIT 10
");
$agents_performance = $stmt->fetchAll();

// Colis en attente de livraison
$stmt = $db->query("
    SELECT c.*, u.prenom, u.nom, l.id as livraison_id, l.agent_id, ag.numero_agent
    FROM colis c 
    JOIN utilisateurs u ON c.utilisateur_id = u.id
    LEFT JOIN livraisons l ON c.id = l.colis_id AND l.statut IN ('assignee', 'en_cours')
    LEFT JOIN agents ag ON l.agent_id = ag.id
    WHERE c.statut IN ('en_attente', 'en_livraison')
    ORDER BY c.date_creation DESC 
    LIMIT 20
");
$pending_colis = $stmt->fetchAll();

// Alertes et notifications système
$alertes = [];

// Vérifier les colis en retard (plus de 48h en attente)
$stmt = $db->query("
    SELECT COUNT(*) FROM colis 
    WHERE statut = 'en_attente' 
    AND date_creation < DATE_SUB(NOW(), INTERVAL 48 HOUR)
");
if ($stmt->fetchColumn() > 0) {
    $alertes[] = [
        'type' => 'warning',
        'icon' => 'exclamation-triangle',
        'message' => 'Des colis sont en attente depuis plus de 48h',
        'link' => 'views/admin/gestion_livraisons.php'
    ];
}

// Vérifier les agents inactifs (pas de livraison depuis 7 jours)
$stmt = $db->query("
    SELECT COUNT(*) FROM agents a 
    WHERE actif = 1 
    AND (SELECT MAX(date_assignation) FROM livraisons WHERE agent_id = a.id) < DATE_SUB(NOW(), INTERVAL 7 DAY)
");
if ($stmt->fetchColumn() > 0) {
    $alertes[] = [
        'type' => 'info',
        'icon' => 'user-clock',
        'message' => 'Des agents n\'ont pas effectué de livraison depuis 7 jours',
        'link' => 'views/admin/gestion_agents.php'
    ];
}

// Vérifier les iBox pleines
$stmt = $db->query("SELECT COUNT(*) FROM ibox WHERE statut = 'occupee'");
if ($stmt->fetchColumn() > 0) {
    $alertes[] = [
        'type' => 'success',
        'icon' => 'inbox',
        'message' => 'iBox occupées à vider',
        'link' => 'mes_ibox.php'
    ];
}

// ============================================
// DONNÉES POUR LES GRAPHIQUES
// ============================================

// Évolution des colis (12 derniers mois)
$stmt = $db->query("
    SELECT DATE_FORMAT(date_creation, '%Y-%m') as mois, COUNT(*) as total 
    FROM colis 
    WHERE date_creation >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(date_creation, '%Y-%m')
    ORDER BY mois ASC
");
$colis_monthly = $stmt->fetchAll();

// Répartition des paiements
$stmt = $db->query("
    SELECT mode_paiement, COUNT(*) as count 
    FROM paiements 
    WHERE statut = 'paye'
    GROUP BY mode_paiement
");
$payments_by_method = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<div id="page-content">
    <div class="admin-dashboard">
        <!-- En-tête du Dashboard Admin -->
        <div class="dashboard-header">
            <div class="header-content">
                <div class="header-badge">ADMINISTRATEUR</div>
                <h1>
                    <i class="fas fa-tachometer-alt" style="color: #00B4D8;"></i>
                    Tableau de Bord Administrateur
                </h1>
                <p>Supervision complète de l'application Gestion_Colis</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-secondary" onclick="loadPage('statistiques.php', 'Statistiques')">
                    <i class="fas fa-chart-bar"></i>
                    Statistiques Détaillées
                </button>
                <button class="btn btn-primary" onclick="loadPage('views/admin/gestion_utilisateurs.php', 'Utilisateurs')">
                    <i class="fas fa-users"></i>
                    Gérer Utilisateurs
                </button>
            </div>
        </div>
        
        <!-- Alertes Système -->
        <?php if (!empty($alertes)): ?>
        <div class="alerts-section">
            <h3><i class="fas fa-exclamation-circle"></i> Alertes Système</h3>
            <div class="alerts-grid">
                <?php foreach ($alertes as $alerte): ?>
                <div class="alert-card <?= $alerte['type'] ?>">
                    <i class="fas fa-<?= $alerte['icon'] ?>"></i>
                    <span><?= htmlspecialchars($alerte['message']) ?></span>
                    <button onclick="loadPage('<?= $alerte['link'] ?>', 'Gestion')">
                        <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Statistiques Principales -->
        <div class="stats-overview">
            <div class="stat-card large">
                <div class="stat-icon blue">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-value"><?= number_format($total_users) ?></span>
                    <span class="stat-label">Utilisateurs Totaux</span>
                    <span class="stat-change positive">
                        <i class="fas fa-arrow-up"></i>
                        +<?= $new_users_30d ?> ce mois
                    </span>
                </div>
            </div>
            
            <div class="stat-card large">
                <div class="stat-icon green">
                    <i class="fas fa-box"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-value"><?= number_format($total_colis) ?></span>
                    <span class="stat-label">Colis Totaux</span>
                    <span class="stat-change positive">
                        <i class="fas fa-arrow-up"></i>
                        +<?= $colis_30d ?> ce mois
                    </span>
                </div>
            </div>
            
            <div class="stat-card large">
                <div class="stat-icon orange">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-value"><?= number_format($total_revenue, 0, ',', ' ') ?> FCFA</span>
                    <span class="stat-label">Revenus Totaux</span>
                    <span class="stat-change positive">
                        <i class="fas fa-arrow-up"></i>
                        +<?= number_format($revenue_30d, 0, ',', ' ') ?> FCFA ce mois
                    </span>
                </div>
            </div>
            
            <div class="stat-card large">
                <div class="stat-icon purple">
                    <i class="fas fa-truck"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-value"><?= number_format($active_agents) ?>/<?= number_format($total_agents) ?></span>
                    <span class="stat-label">Agents Actifs</span>
                    <span class="stat-change neutral">
                        <i class="fas fa-truck"></i>
                        <?= $livraisons_today ?> aujourd'hui
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Section Graphiques -->
        <div class="charts-row">
            <div class="chart-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-line"></i> Évolution des Colis (12 mois)</h3>
                </div>
                <div class="card-body">
                    <canvas id="monthlyColisChart" height="250"></canvas>
                </div>
            </div>
            
            <div class="chart-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-pie"></i> Répartition par Statut</h3>
                </div>
                <div class="card-body">
                    <canvas id="colisStatusChart" height="250"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Tableaux de Données -->
        <div class="data-row">
            <!-- Derniers Utilisateurs -->
            <div class="data-card">
                <div class="card-header">
                    <h3><i class="fas fa-user-plus"></i> Derniers Utilisateurs</h3>
                    <span class="badge badge-info"><?= count($recent_users) ?></span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Nom</th>
                                    <th>Email</th>
                                    <th>Rôle</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_users as $u): ?>
                                <tr>
                                    <td><?= htmlspecialchars($u['prenom'] . ' ' . $u['nom']) ?></td>
                                    <td><?= htmlspecialchars($u['email']) ?></td>
                                    <td>
                                        <span class="badge badge-<?= $u['role'] === 'admin' ? 'danger' : ($u['role'] === 'agent' ? 'warning' : 'primary') ?>">
                                            <?= ucfirst($u['role']) ?>
                                        </span>
                                    </td>
                                    <td><?= date('d/m/Y', strtotime($u['date_creation'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Derniers Colis -->
            <div class="data-card">
                <div class="card-header">
                    <h3><i class="fas fa-box-open"></i> Derniers Colis</h3>
                    <span class="badge badge-success"><?= count($recent_colis) ?></span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Réf.</th>
                                    <th>Client</th>
                                    <th>Statut</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_colis as $c): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($c['reference_colis']) ?></code></td>
                                    <td><?= htmlspecialchars($c['prenom'] . ' ' . $c['nom']) ?></td>
                                    <td>
                                        <span class="badge badge-<?= 
                                            $c['statut'] === 'livre' ? 'success' : 
                                            ($c['statut'] === 'en_livraison' ? 'info' : 
                                            ($c['statut'] === 'annule' ? 'danger' : 'warning')) 
                                        ?>">
                                            <?= ucfirst(str_replace('_', ' ', $c['statut'])) ?>
                                        </span>
                                    </td>
                                    <td><?= date('d/m/Y', strtotime($c['date_creation'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Performances des Agents -->
        <div class="data-card full-width">
            <div class="card-header">
                <h3><i class="fas fa-trophy"></i> Performance des Agents</h3>
            </div>
            <div class="card-body">
                <div class="agents-grid">
                    <?php foreach ($agents_performance as $agent): ?>
                    <div class="agent-card">
                        <div class="agent-avatar">
                            <?= strtoupper(substr($agent['prenom'], 0, 1) . substr($agent['nom'], 0, 1)) ?>
                        </div>
                        <div class="agent-info">
                            <h4><?= htmlspecialchars($agent['prenom'] . ' ' . $agent['nom']) ?></h4>
                            <p class="agent-zone"><?= htmlspecialchars($agent['zone_livraison']) ?></p>
                            <div class="agent-stats">
                                <div class="agent-stat">
                                    <span class="stat-number"><?= number_format($agent['total_livraisons']) ?></span>
                                    <span class="stat-label">Livraisons</span>
                                </div>
                                <div class="agent-stat">
                                    <span class="stat-number"><?= number_format($agent['note_moyenne'], 1) ?></span>
                                    <span class="stat-label">Note</span>
                                </div>
                                <div class="agent-stat">
                                    <span class="stat-number"><?= $agent['actif'] ? 'Oui' : 'Non' ?></span>
                                    <span class="stat-label">Actif</span>
                                </div>
                            </div>
                        </div>
                        <div class="agent-actions">
                            <button class="btn btn-sm btn-secondary" onclick="loadPage('views/admin/gestion_agents.php?action=view&id=<?= $agent['id'] ?>', 'Agent')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- Actions Rapides Admin -->
        <div class="quick-actions">
            <h3><i class="fas fa-bolt"></i> Actions Rapides</h3>
            <div class="actions-grid">
                <button class="action-card" onclick="loadPage('views/admin/gestion_utilisateurs.php', 'Utilisateurs')">
                    <i class="fas fa-users-cog"></i>
                    <span>Gérer Utilisateurs</span>
                </button>
                <button class="action-card" onclick="loadPage('views/admin/gestion_agents.php', 'Agents')">
                    <i class="fas fa-truck-moving"></i>
                    <span>Gérer Agents</span>
                </button>
                <button class="action-card" onclick="loadPage('views/admin/gestion_livraisons.php', 'Livraisons')">
                    <i class="fas fa-tasks"></i>
                    <span>Assignations</span>
                </button>
                <button class="action-card" onclick="loadPage('statistiques.php', 'Statistiques')">
                    <i class="fas fa-chart-bar"></i>
                    <span>Statistiques</span>
                </button>
                <button class="action-card" onclick="loadPage('admin_notifications.php', 'Notifications')">
                    <i class="fas fa-bell"></i>
                    <span>Notifications</span>
                </button>
                <button class="action-card" onclick="loadPage('settings.php', 'Paramètres')">
                    <i class="fas fa-cogs"></i>
                    <span>Paramètres</span>
                </button>
                <button class="action-card" onclick="loadPage('admin_pickup_codes.php', 'Codes')">
                    <i class="fas fa-qrcode"></i>
                    <span>Codes Retrait</span>
                </button>
            </div>
        </div>
    </div>

<script>
// Données pour les graphiques
const monthlyColisData = {
    labels: <?= json_encode(array_map(function($item) { 
        $mois = ['', 'Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
        $parts = explode('-', $item['mois']);
        return $mois[intval($parts[1])] . ' ' . substr($parts[0], 2);
    }, $colis_monthly)) ?>,
    datasets: [{
        label: 'Colis créés',
        data: <?= json_encode(array_column($colis_monthly, 'total')) ?>,
        borderColor: '#00B4D8',
        backgroundColor: 'rgba(0, 180, 216, 0.1)',
        borderWidth: 2,
        fill: true,
        tension: 0.4
    }]
};

const colisStatusData = {
    labels: ['En attente', 'En livraison', 'Livré', 'Retourné', 'Annulé'],
    datasets: [{
        data: [
            <?= $colis_by_status['en_attente'] ?? 0 ?>,
            <?= $colis_by_status['en_livraison'] ?? 0 ?>,
            <?= $colis_by_status['livre'] ?? 0 ?>,
            <?= $colis_by_status['retourne'] ?? 0 ?>,
            <?= $colis_by_status['annule'] ?? 0 ?>
        ],
        backgroundColor: ['#F59E0B', '#3B82F6', '#10B981', '#8B5CF6', '#EF4444'],
        borderWidth: 0
    }]
};

// Initialiser les graphiques
document.addEventListener('DOMContentLoaded', function() {
    // Graphique évolution mensuelle
    const monthlyChartEl = document.getElementById('monthlyColisChart');
    if (monthlyChartEl) {
        new Chart(monthlyChartEl, {
            type: 'line',
            data: monthlyColisData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0, 180, 216, 0.1)' }
                    },
                    x: {
                        grid: { display: false }
                    }
                }
            }
        });
    }
    
    // Graphique statut
    const statusChartEl = document.getElementById('colisStatusChart');
    if (statusChartEl) {
        new Chart(statusChartEl, {
            type: 'doughnut',
            data: colisStatusData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { padding: 15 }
                    }
                }
            }
        });
    }
    
    console.log('%c🚀 Gestion_Colis - Dashboard Admin', 'color: #00B4D8; font-size: 16px; font-weight: bold;');
});
</script>

<style>
.admin-dashboard {
    padding: 0;
}

.dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 2rem;
    padding: 1.5rem;
    background: linear-gradient(135deg, #1E293B 0%, #0F172A 100%);
    border-radius: var(--radius-md);
    border: 1px solid rgba(0, 180, 216, 0.3);
}

.header-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    background: rgba(0, 180, 216, 0.2);
    border: 1px solid var(--tech-cyan);
    border-radius: var(--radius-full);
    color: var(--tech-cyan);
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 1px;
    margin-bottom: 0.5rem;
}

.dashboard-header h1 {
    color: var(--white);
    font-size: 1.5rem;
    margin-bottom: 0.25rem;
}

.dashboard-header p {
    color: rgba(255, 255, 255, 0.7);
    font-size: 0.9rem;
}

.header-actions {
    display: flex;
    gap: 0.75rem;
}

.alerts-section {
    margin-bottom: 1.5rem;
}

.alerts-section h3 {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 1rem;
    color: var(--warning);
}

.alerts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 0.75rem;
}

.alert-card {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    background: var(--bg-card);
    border-radius: var(--radius-md);
    border: 1px solid var(--border-color);
}

.alert-card.warning {
    background: rgba(245, 158, 11, 0.1);
    border-color: rgba(245, 158, 11, 0.3);
    color: var(--warning);
}

.alert-card.info {
    background: rgba(59, 130, 246, 0.1);
    border-color: rgba(59, 130, 246, 0.3);
    color: var(--info);
}

.alert-card.success {
    background: rgba(16, 185, 129, 0.1);
    border-color: rgba(16, 185, 129, 0.3);
    color: var(--success);
}

.alert-card span {
    flex: 1;
    font-size: 0.9rem;
}

.alert-card button {
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    padding: 0.25rem;
    transition: color var(--transition-fast);
}

.alert-card button:hover {
    color: var(--tech-cyan);
}

.stats-overview {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.stat-card.large {
    padding: 1.25rem;
}

.stat-card.large .stat-icon {
    width: 48px;
    height: 48px;
    font-size: 1.25rem;
}

.stat-card.large .stat-value {
    font-size: 1.75rem;
}

.charts-row {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 1rem;
    margin-bottom: 1.5rem;
}

@media (max-width: 1024px) {
    .charts-row {
        grid-template-columns: 1fr;
    }
}

.data-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.data-card.full-width {
    margin-bottom: 1.5rem;
}

.agents-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1rem;
}

.agent-card {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: var(--bg-tertiary);
    border-radius: var(--radius-md);
    border: 1px solid var(--border-color);
}

.agent-avatar {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, var(--tech-cyan), var(--tech-blue));
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--white);
    font-weight: 700;
    font-size: 0.9rem;
    flex-shrink: 0;
}

.agent-info {
    flex: 1;
}

.agent-info h4 {
    font-size: 0.95rem;
    margin-bottom: 0.25rem;
}

.agent-zone {
    color: var(--text-muted);
    font-size: 0.8rem;
    margin-bottom: 0.5rem;
}

.agent-stats {
    display: flex;
    gap: 1rem;
}

.agent-stat {
    text-align: center;
}

.agent-stat .stat-number {
    display: block;
    font-size: 1rem;
    font-weight: 700;
    color: var(--tech-cyan);
}

.agent-stat .stat-label {
    font-size: 0.7rem;
    color: var(--text-muted);
}

.quick-actions h3 {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 0.75rem;
}

.action-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    padding: 1.25rem 1rem;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: all var(--transition-fast);
    text-align: center;
}

.action-card:hover {
    border-color: var(--tech-cyan);
    transform: translateY(-2px);
    box-shadow: 0 4px 15px var(--glow-cyan);
}

.action-card i {
    font-size: 1.5rem;
    color: var(--tech-cyan);
}

.action-card:hover i {
    color: var(--tech-blue);
}

.action-card span {
    font-size: 0.85rem;
    font-weight: 500;
}
</style>

</div> <!-- Fin #page-content -->
