<?php
/**
 * Page de statistiques pour l'administrateur
 */

session_start();

// Vérifier si l'utilisateur est connecté et est admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="access-denied">Accès refusé. Vous devez être administrateur pour accéder à cette page.</div>';
    exit;
}

require_once 'config/database.php';

// Charger le barème des commissions
$commissionRates = [
    'base_rate' => 2.50,
    'km_rate' => 0.45,
    'urgent_bonus' => 1.50,
    'fragile_bonus' => 0.75,
    'weight_threshold' => 5.0,
    'weight_bonus' => 0.50,
    'min_commission' => 1.50,
    'max_commission' => 15.00
];
$configFile = __DIR__ . '/config/config.php';
if (file_exists($configFile)) {
    $appConfig = require $configFile;
    if (is_array($appConfig) && isset($appConfig['commissions']) && is_array($appConfig['commissions'])) {
        $commissionRates = array_merge($commissionRates, $appConfig['commissions']);
    }
}

function format_fcfa($amount): string {
    return number_format((float) $amount, 0, ',', ' ') . ' FCFA';
}

$stats = [
    'utilisateurs' => 0,
    'colis' => 0,
    'ibox' => 0,
    'agents' => 0
];
$mobile_stats = [
    'total_count' => 0,
    'paid_count' => 0,
    'pending_count' => 0,
    'paid_amount' => 0,
    'pending_amount' => 0,
    'orange_paid' => 0,
    'mtn_paid' => 0
];
$commission_stats = [
    'total_count' => 0,
    'pending_count' => 0,
    'paid_count' => 0,
    'pending_amount' => 0,
    'paid_amount' => 0,
    'total_amount' => 0
];

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Statistiques générales
    $stmt = $db->query("SELECT COUNT(*) as total FROM utilisateurs");
    $stats['utilisateurs'] = $stmt->fetch()['total'];
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM colis");
    $stats['colis'] = $stmt->fetch()['total'];
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM ibox");
    $stats['ibox'] = $stmt->fetch()['total'];
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM agents WHERE actif = 1");
    $stats['agents'] = $stmt->fetch()['total'];

    // Statistiques Mobile Money (Orange / MTN)
    $stmt = $db->query("
        SELECT
            SUM(CASE WHEN payment_provider IN ('orange','mtn') THEN 1 ELSE 0 END) as total_count,
            SUM(CASE WHEN payment_provider IN ('orange','mtn') AND payment_status = 'paid' THEN 1 ELSE 0 END) as paid_count,
            SUM(CASE WHEN payment_provider IN ('orange','mtn') AND payment_status = 'pending' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN payment_provider IN ('orange','mtn') AND payment_status = 'paid' THEN payment_amount ELSE 0 END) as paid_amount,
            SUM(CASE WHEN payment_provider IN ('orange','mtn') AND payment_status = 'pending' THEN payment_amount ELSE 0 END) as pending_amount,
            SUM(CASE WHEN payment_provider = 'orange' AND payment_status = 'paid' THEN payment_amount ELSE 0 END) as orange_paid,
            SUM(CASE WHEN payment_provider = 'mtn' AND payment_status = 'paid' THEN payment_amount ELSE 0 END) as mtn_paid
        FROM colis
    ");
    $mobile_stats = array_merge($mobile_stats, $stmt->fetch() ?: []);

    // Statistiques Commissions Agents
    $stmt = $db->query("
        SELECT
            COUNT(*) as total_count,
            SUM(CASE WHEN statut = 'en_attente' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN statut = 'paye' THEN 1 ELSE 0 END) as paid_count,
            SUM(CASE WHEN statut = 'en_attente' THEN montant_total ELSE 0 END) as pending_amount,
            SUM(CASE WHEN statut = 'paye' THEN montant_total ELSE 0 END) as paid_amount,
            SUM(montant_total) as total_amount
        FROM agent_commissions
    ");
    $commission_stats = array_merge($commission_stats, $stmt->fetch() ?: []);
    
    // Colis par mois (12 derniers mois)
    $stmt = $db->query("
        SELECT MONTH(date_creation) as mois, YEAR(date_creation) as annee, COUNT(*) as total 
        FROM colis 
        WHERE date_creation >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY YEAR(date_creation), MONTH(date_creation)
        ORDER BY annee DESC, mois DESC
        LIMIT 12
    ");
    $colis_par_mois = $stmt->fetchAll();
    
    // Colis par statut
    $stmt = $db->query("SELECT statut, COUNT(*) as total FROM colis GROUP BY statut");
    $colis_par_statut = $stmt->fetchAll();
    
    // Revenus par mois
    $stmt = $db->query("
        SELECT MONTH(date_paiement) as mois, YEAR(date_paiement) as annee, SUM(montant) as total 
        FROM paiements 
        WHERE statut = 'paye' AND date_paiement >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY YEAR(date_paiement), MONTH(date_paiement)
        ORDER BY annee DESC, mois DESC
        LIMIT 12
    ");
    $revenus_par_mois = $stmt->fetchAll();
    
    // Top 5 utilisateurs par nombre de colis
    $stmt = $db->query("
        SELECT u.nom, u.prenom, COUNT(c.id) as total_colis
        FROM utilisateurs u
        LEFT JOIN colis c ON c.utilisateur_id = u.id
        WHERE u.role = 'utilisateur'
        GROUP BY u.id
        ORDER BY total_colis DESC
        LIMIT 5
    ");
    $top_utilisateurs = $stmt->fetchAll();
    
    // Top 5 agents par livraison
    $stmt = $db->query("
        SELECT u.nom, u.prenom, COUNT(c.id) as total_livraisons
        FROM utilisateurs u
        LEFT JOIN colis c ON c.agent_id = u.id
        WHERE u.role = 'agent'
        GROUP BY u.id
        ORDER BY total_livraisons DESC
        LIMIT 5
    ");
    $top_agents = $stmt->fetchAll();
    
    // Taux de livraison par agent
    $stmt = $db->query("
        SELECT u.nom, u.prenom, 
               COUNT(c.id) as total,
               SUM(CASE WHEN c.statut = 'livre' THEN 1 ELSE 0 END) as livres
        FROM utilisateurs u
        LEFT JOIN colis c ON c.agent_id = u.id
        WHERE u.role = 'agent'
        GROUP BY u.id
        HAVING total > 0
        ORDER BY livres DESC
        LIMIT 10
    ");
    $performance_agents = $stmt->fetchAll();
    
} catch (Exception $e) {
    $error = user_error_message($e, 'statistiques.fetch', 'Erreur lors de la récupération des statistiques.');
}
?>

<!-- Contenu pour le système SPA -->
<div id="page-content">

<div class="page-container">
    <div class="page-header">
        <h1><i class="fas fa-chart-bar" style="color: #00B4D8;"></i> Statistiques Détaillées</h1>
        <div class="header-actions">
            <button class="btn btn-primary" onclick="exportStats()">
                <i class="fas fa-download"></i> Exporter
            </button>
        </div>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-error" style="margin-bottom: 1.5rem;">
            <i class="fas fa-exclamation-triangle"></i>
            <div><?php echo htmlspecialchars($error); ?></div>
        </div>
    <?php endif; ?>

    <!-- Statistiques Cards -->
    <div class="stats-grid" style="margin-bottom: 1.5rem;">
        <div class="stat-card">
            <div class="stat-icon blue">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?php echo number_format($stats['utilisateurs'] ?? 0); ?></span>
                <span class="stat-label">Utilisateurs</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">
                <i class="fas fa-box"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?php echo number_format($stats['colis'] ?? 0); ?></span>
                <span class="stat-label">Colis Totaux</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon orange">
                <i class="fas fa-inbox"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?php echo number_format($stats['ibox'] ?? 0); ?></span>
                <span class="stat-label">iBox Créées</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple">
                <i class="fas fa-truck"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?php echo number_format($stats['agents'] ?? 0); ?></span>
                <span class="stat-label">Agents Actifs</span>
            </div>
        </div>
    </div>

    <div class="stats-grid" style="margin-bottom: 1.5rem;">
        <div class="stat-card">
            <div class="stat-icon blue">
                <i class="fas fa-mobile-alt"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?php echo format_fcfa($mobile_stats['paid_amount'] ?? 0); ?></span>
                <span class="stat-label">Mobile Money payé (<?php echo (int) ($mobile_stats['paid_count'] ?? 0); ?>)</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon orange">
                <i class="fas fa-hourglass-half"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?php echo format_fcfa($mobile_stats['pending_amount'] ?? 0); ?></span>
                <span class="stat-label">Mobile Money en attente (<?php echo (int) ($mobile_stats['pending_count'] ?? 0); ?>)</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">
                <i class="fas fa-coins"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?php echo format_fcfa($commission_stats['paid_amount'] ?? 0); ?></span>
                <span class="stat-label">Commissions payées (<?php echo (int) ($commission_stats['paid_count'] ?? 0); ?>)</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple">
                <i class="fas fa-wallet"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?php echo format_fcfa($commission_stats['pending_amount'] ?? 0); ?></span>
                <span class="stat-label">Commissions en attente (<?php echo (int) ($commission_stats['pending_count'] ?? 0); ?>)</span>
            </div>
        </div>
    </div>

    <!-- Graphiques -->
    <div class="charts-grid" style="margin-bottom: 1.5rem;">
        <div class="chart-card">
            <h3 class="chart-title">
                <i class="fas fa-chart-line"></i>
                Évolution des Colis (12 derniers mois)
            </h3>
            <div class="chart-container">
                <canvas id="colisMoisChart"></canvas>
            </div>
        </div>
        <div class="chart-card">
            <h3 class="chart-title">
                <i class="fas fa-chart-pie"></i>
                Répartition par Statut
            </h3>
            <div class="chart-container">
                <canvas id="colisStatutChart"></canvas>
            </div>
        </div>
    </div>

    <div class="charts-grid" style="margin-bottom: 1.5rem;">
        <div class="chart-card">
            <h3 class="chart-title">
                <i class="fas fa-chart-line"></i>
                Revenus Mensuels
            </h3>
            <div class="chart-container">
                <canvas id="revenusMoisChart"></canvas>
            </div>
        </div>
        <div class="chart-card">
            <h3 class="chart-title">
                <i class="fas fa-chart-bar"></i>
                Performance des Agents
            </h3>
            <div class="chart-container">
                <canvas id="performanceAgentsChart"></canvas>
            </div>
        </div>
    </div>

    <div class="charts-grid" style="margin-bottom: 1.5rem;">
        <div class="chart-card">
            <h3 class="chart-title">
                <i class="fas fa-mobile-alt"></i>
                Mobile Money par opérateur
            </h3>
            <div class="chart-container">
                <canvas id="mobileMoneyOperatorChart"></canvas>
            </div>
        </div>
        <div class="chart-card">
            <h3 class="chart-title">
                <i class="fas fa-sliders-h"></i>
                Barème des commissions
            </h3>
            <div class="card-body" style="padding: 0;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Élément</th>
                            <th>Valeur</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Base par colis</td>
                            <td><?php echo format_fcfa($commissionRates['base_rate']); ?></td>
                        </tr>
                        <tr>
                            <td>Distance (par km)</td>
                            <td><?php echo format_fcfa($commissionRates['km_rate']); ?> / km</td>
                        </tr>
                        <tr>
                            <td>Bonus urgent</td>
                            <td><?php echo format_fcfa($commissionRates['urgent_bonus']); ?></td>
                        </tr>
                        <tr>
                            <td>Bonus fragile</td>
                            <td><?php echo format_fcfa($commissionRates['fragile_bonus']); ?></td>
                        </tr>
                        <tr>
                            <td>Seuil poids</td>
                            <td><?php echo number_format((float) $commissionRates['weight_threshold'], 2, ',', ' '); ?> kg</td>
                        </tr>
                        <tr>
                            <td>Bonus poids (par kg)</td>
                            <td><?php echo format_fcfa($commissionRates['weight_bonus']); ?> / kg</td>
                        </tr>
                        <tr>
                            <td>Commission minimum</td>
                            <td><?php echo format_fcfa($commissionRates['min_commission']); ?></td>
                        </tr>
                        <tr>
                            <td>Commission maximum</td>
                            <td><?php echo format_fcfa($commissionRates['max_commission']); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Tableaux -->
    <div class="dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 1.5rem;">
        <!-- Top Utilisateurs -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-trophy"></i> Top 5 Utilisateurs</h3>
            </div>
            <div class="card-body">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Rang</th>
                            <th>Nom</th>
                            <th>Colis</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($top_utilisateurs)): ?>
                            <tr>
                                <td colspan="3" class="text-center">Aucune donnée</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($top_utilisateurs as $index => $user): ?>
                                <tr>
                                    <td>
                                        <?php if ($index === 0): ?>
                                            <i class="fas fa-medal" style="color: gold;"></i>
                                        <?php elseif ($index === 1): ?>
                                            <i class="fas fa-medal" style="color: silver;"></i>
                                        <?php elseif ($index === 2): ?>
                                            <i class="fas fa-medal" style="color: #cd7f32;"></i>
                                        <?php else: ?>
                                            <?php echo $index + 1; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?></td>
                                    <td><strong><?php echo $user['total_colis']; ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Top Agents -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-award"></i> Top 5 Agents</h3>
            </div>
            <div class="card-body">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Rang</th>
                            <th>Nom</th>
                            <th>Livraisons</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($top_agents)): ?>
                            <tr>
                                <td colspan="3" class="text-center">Aucune donnée</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($top_agents as $index => $agent): ?>
                                <tr>
                                    <td>
                                        <?php if ($index === 0): ?>
                                            <i class="fas fa-medal" style="color: gold;"></i>
                                        <?php elseif ($index === 1): ?>
                                            <i class="fas fa-medal" style="color: silver;"></i>
                                        <?php elseif ($index === 2): ?>
                                            <i class="fas fa-medal" style="color: #cd7f32;"></i>
                                        <?php else: ?>
                                            <?php echo $index + 1; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($agent['prenom'] . ' ' . $agent['nom']); ?></td>
                                    <td><strong><?php echo $agent['total_livraisons']; ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// Données pour les graphiques
const colisMoisData = {
    labels: <?php echo json_encode(array_map(function($item) {
        $mois = ['', 'Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
        return $mois[$item['mois']] . ' ' . substr($item['annee'], 2);
    }, array_reverse($colis_par_mois))); ?>,
    datasets: [{
        label: 'Colis créés',
        data: <?php echo json_encode(array_column(array_reverse($colis_par_mois), 'total')); ?>,
        borderColor: '#00B4D8',
        backgroundColor: 'rgba(0, 240, 255, 0.1)',
        borderWidth: 3,
        fill: true,
        tension: 0.4
    }]
};

const colisStatutData = {
    labels: <?php echo json_encode(array_column($colis_par_statut, 'statut')); ?>,
    datasets: [{
        data: <?php echo json_encode(array_column($colis_par_statut, 'total')); ?>,
        backgroundColor: ['#22C55E', '#F59E0B', '#3B82F6', '#8B5CF6', '#EF4444'],
        borderWidth: 3,
        borderColor: '#0a0e17'
    }]
};

const revenusMoisData = {
    labels: <?php echo json_encode(array_map(function($item) {
        $mois = ['', 'Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
        return $mois[$item['mois']] . ' ' . substr($item['annee'], 2);
    }, array_reverse($revenus_par_mois))); ?>,
    datasets: [{
        label: 'Revenus (FCFA)',
        data: <?php echo json_encode(array_map(function($item) {
            return $item['total'] ?? 0;
        }, array_reverse($revenus_par_mois))); ?>,
        backgroundColor: 'rgba(0, 255, 136, 0.8)',
        borderColor: '#00FF88',
        borderWidth: 2,
        borderRadius: 6
    }]
};

const performanceAgentsData = {
    labels: <?php echo json_encode(array_map(function($item) {
        return $item['prenom'] . ' ' . substr($item['nom'], 0, 1) . '.';
    }, $performance_agents)); ?>,
    datasets: [{
        label: 'Livrés',
        data: <?php echo json_encode(array_map(function($item) {
            return $item['livres'] ?? 0;
        }, $performance_agents)); ?>,
        backgroundColor: '#22C55E'
    }, {
        label: 'Total',
        data: <?php echo json_encode(array_map(function($item) {
            return $item['total'] ?? 0;
        }, $performance_agents)); ?>,
        backgroundColor: 'rgba(0, 240, 255, 0.3)'
    }]
};

const mobileMoneyOperatorData = {
    labels: ['Orange Money', 'MTN MoMo'],
    datasets: [{
        data: <?php echo json_encode([
            (float) ($mobile_stats['orange_paid'] ?? 0),
            (float) ($mobile_stats['mtn_paid'] ?? 0)
        ]); ?>,
        backgroundColor: ['#F97316', '#FACC15'],
        borderWidth: 3,
        borderColor: '#0a0e17'
    }]
};

// Initialiser les graphiques après le chargement
document.addEventListener('DOMContentLoaded', function() {
    // Graphique colis par mois
    const colisMoisChart = document.getElementById('colisMoisChart');
    if (colisMoisChart) {
        new Chart(colisMoisChart.getContext('2d'), {
            type: 'line',
            data: colisMoisData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { ticks: { color: '#94A3B8' }, grid: { color: 'rgba(0, 240, 255, 0.1)' } },
                    y: { ticks: { color: '#94A3B8' }, grid: { color: 'rgba(0, 240, 255, 0.1)' } }
                }
            }
        });
    }

    // Graphique colis par statut
    const colisStatutChart = document.getElementById('colisStatutChart');
    if (colisStatutChart) {
        new Chart(colisStatutChart.getContext('2d'), {
            type: 'doughnut',
            data: colisStatutData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { color: '#E2E8F0', padding: 15 } }
                }
            }
        });
    }

    // Graphique revenus par mois
    const revenusMoisChart = document.getElementById('revenusMoisChart');
    if (revenusMoisChart) {
        new Chart(revenusMoisChart.getContext('2d'), {
            type: 'bar',
            data: revenusMoisData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { ticks: { color: '#94A3B8' }, grid: { display: false } },
                    y: { ticks: { color: '#94A3B8' }, grid: { color: 'rgba(0, 255, 136, 0.1)' } }
                }
            }
        });
    }

    // Graphique performance agents
    const performanceAgentsChart = document.getElementById('performanceAgentsChart');
    if (performanceAgentsChart) {
        new Chart(performanceAgentsChart.getContext('2d'), {
            type: 'bar',
            data: performanceAgentsData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', labels: { color: '#E2E8F0' } }
                },
                scales: {
                    x: { ticks: { color: '#94A3B8' }, grid: { display: false } },
                    y: { ticks: { color: '#94A3B8' }, grid: { color: 'rgba(0, 240, 255, 0.1)' } }
                }
            }
        });
    }

    // Graphique Mobile Money par opérateur
    const mobileMoneyOperatorChart = document.getElementById('mobileMoneyOperatorChart');
    if (mobileMoneyOperatorChart) {
        new Chart(mobileMoneyOperatorChart.getContext('2d'), {
            type: 'doughnut',
            data: mobileMoneyOperatorData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { color: '#E2E8F0', padding: 15 } }
                }
            }
        });
    }
});

function exportStats() {
    alert('Fonction d\'export en cours de développement.');
}
</script>

</div> <!-- Fin #page-content -->
