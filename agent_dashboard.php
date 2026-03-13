<?php
require_once __DIR__ . '/utils/session.php';
SessionManager::start();
require_once 'config/database.php';
require_once 'utils/commission_service.php';

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'] ?? 0;

// Vérifier la connexion et le rôle agent
if (!$user_id) {
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="access-denied">Accès refusé. Veuillez vous connecter.</div>';
    exit;
}

$userStmt = $db->prepare("SELECT * FROM utilisateurs WHERE id = ?");
$userStmt->execute([$user_id]);
$user = $userStmt->fetch();

if ($user['role'] !== 'agent' && $user['role'] !== 'admin') {
    echo '<div class="access-denied">Cette page est réservée aux agents de livraison.</div>';
    exit;
}

$agentStmt = $db->prepare("SELECT * FROM agents WHERE utilisateur_id = ?");
$agentStmt->execute([$user_id]);
$agent = $agentStmt->fetch();

if (!$agent) {
    echo '<div class="access-denied">Profil agent non trouvé. Veuillez contacter l\'administration.</div>';
    exit;
}

$commissionService = new CommissionService();

// Récupérer les statistiques
$period = $_GET['period'] ?? 'month';
$summary = $commissionService->getAgentCommissionSummary($agent['id'], $period);

// Récupérer les commissions récentes
$commissions = $commissionService->getAgentCommissions($agent['id'], null, 20);

// Statistiques de livraison
$deliveryStats = $db->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN statut = 'terminee' THEN 1 ELSE 0 END) as delivered,
        SUM(CASE WHEN statut = 'annulee' THEN 1 ELSE 0 END) as cancelled,
        AVG(evaluation) as avg_rating,
        AVG(duree_minutes) as avg_time
    FROM livraisons 
    WHERE agent_id = ?
");
$deliveryStats->execute([$agent['id']]);
$stats = $deliveryStats->fetch();

$successRate = $stats['total'] > 0 ? round(($stats['delivered'] / $stats['total']) * 100, 1) : 0;
?>

<!-- Contenu pour le système SPA -->
<div id="page-content">

<div class="page-container">
    <div class="page-header">
        <h1><i class="fas fa-truck"></i> Tableau de Bord Agent</h1>
        <div class="header-actions">
            <select id="periodFilter" onchange="filterPeriod(this.value)" class="form-control" style="width: auto;">
                <option value="week" <?php echo $period === 'week' ? 'selected' : ''; ?>>Cette semaine</option>
                <option value="month" <?php echo $period === 'month' ? 'selected' : ''; ?>>Ce mois</option>
                <option value="year" <?php echo $period === 'year' ? 'selected' : ''; ?>>Cette année</option>
            </select>
        </div>
    </div>

    <!-- Statistiques principales -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon bg-primary">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?php echo number_format($summary['pending_amount'] ?? 0, 2); ?> FCFA</span>
                <span class="stat-label">En attente</span>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon bg-success">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?php echo number_format($summary['paid_amount'] ?? 0, 2); ?> FCFA</span>
                <span class="stat-label">Payé</span>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon bg-info">
                <i class="fas fa-box"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?php echo $summary['total_deliveries'] ?? 0; ?></span>
                <span class="stat-label">Livraisons</span>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon bg-warning">
                <i class="fas fa-star"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?php echo number_format($stats['avg_rating'] ?? 0, 1); ?>/5</span>
                <span class="stat-label">Note moyenne</span>
            </div>
        </div>
    </div>

    <!-- Performances et Revenus -->
    <div class="dashboard-grid">
        <!-- Carte de revenus -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-wallet"></i> Revenus</h3>
            </div>
            <div class="card-body">
                <div class="revenue-display">
                    <div class="revenue-main">
                        <span class="revenue-amount"><?php echo number_format($summary['total_amount'] ?? 0, 2); ?> FCFA</span>
                        <span class="revenue-label">Total des commissions</span>
                    </div>
                    
                    <div class="revenue-breakdown">
                        <div class="revenue-item">
                            <span class="amount"><?php echo number_format($summary['pending_amount'] ?? 0, 2); ?> FCFA</span>
                            <span class="label">En attente</span>
                            <div class="progress-bar">
                                <div class="progress-fill bg-warning" style="width: 100%"></div>
                            </div>
                        </div>
                        <div class="revenue-item">
                            <span class="amount"><?php echo number_format($summary['paid_amount'] ?? 0, 2); ?> FCFA</span>
                            <span class="label">Payé</span>
                            <div class="progress-bar">
                                <div class="progress-fill bg-success" style="width: 100%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Carte de performance -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-chart-line"></i> Performance</h3>
            </div>
            <div class="card-body">
                <div class="performance-stats">
                    <div class="perf-item">
                        <div class="perf-icon">
                            <i class="fas fa-check"></i>
                        </div>
                        <div class="perf-info">
                            <span class="perf-value"><?php echo $stats['delivered'] ?? 0; ?></span>
                            <span class="perf-label">Livrées</span>
                        </div>
                    </div>
                    
                    <div class="perf-item">
                        <div class="perf-icon danger">
                            <i class="fas fa-times"></i>
                        </div>
                        <div class="perf-info">
                            <span class="perf-value"><?php echo $stats['cancelled'] ?? 0; ?></span>
                            <span class="perf-label">Annulées</span>
                        </div>
                    </div>
                    
                    <div class="perf-item">
                        <div class="perf-icon success">
                            <i class="fas fa-percentage"></i>
                        </div>
                        <div class="perf-info">
                            <span class="perf-value"><?php echo $successRate; ?>%</span>
                            <span class="perf-label">Taux de succès</span>
                        </div>
                    </div>
                    
                    <div class="perf-item">
                        <div class="perf-icon info">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="perf-info">
                            <span class="perf-value"><?php echo round($stats['avg_time'] ?? 0); ?>min</span>
                            <span class="perf-label">Temps moyen</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Historique des commissions -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-history"></i> Historique des Commissions</h3>
            <button class="btn btn-secondary" onclick="exportCommissions()">
                <i class="fas fa-download"></i> Export CSV
            </button>
        </div>
        <div class="card-body">
            <?php if (empty($commissions)): ?>
                <div class="empty-state">
                    <i class="fas fa-receipt fa-3x"></i>
                    <h3>Aucune commission</h3>
                    <p>Vos commissions apparaissent ici après avoir livré des colis.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Colis</th>
                                <th>Base</th>
                                <th>KM</th>
                                <th>Bonus</th>
                                <th>Total</th>
                                <th>Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($commissions as $comm): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($comm['date_calcul'])); ?></td>
                                    <td>
                                        <?php if ($comm['colis_id']): ?>
                                            <a href="#" onclick="viewParcel(<?php echo $comm['colis_id']; ?>)">
                                                #<?php echo $comm['colis_id']; ?>
                                            </a>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo number_format($comm['montant_base'], 2); ?> FCFA</td>
                                    <td><?php echo number_format($comm['montant_km'], 2); ?> FCFA</td>
                                    <td><?php echo number_format($comm['montant_bonus'], 2); ?> FCFA</td>
                                    <td><strong><?php echo number_format($comm['montant_total'], 2); ?> FCFA</strong></td>
                                    <td>
                                        <span class="badge badge-<?php 
                                            echo match($comm['statut']) {
                                                'paye' => 'success',
                                                'en_attente' => 'warning',
                                                'approuve' => 'info',
                                                'annule' => 'danger',
                                                default => 'secondary'
                                            };
                                        ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $comm['statut'])); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Informations Agent -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-id-card"></i> Mon Profil Agent</h3>
        </div>
        <div class="card-body">
            <div class="agent-info-grid">
                <div class="info-item">
                    <span class="label">Numéro d'agent</span>
                    <span class="value"><?php echo htmlspecialchars($agent['numero_agent']); ?></span>
                </div>
                <div class="info-item">
                    <span class="label">Zone de livraison</span>
                    <span class="value"><?php echo htmlspecialchars($agent['zone_livraison']); ?></span>
                </div>
                <div class="info-item">
                    <span class="label">Véhicule</span>
                    <span class="value"><?php echo ucfirst(htmlspecialchars($agent['vehicule_type'])); ?></span>
                </div>
                <div class="info-item">
                    <span class="label">Total livraisons</span>
                    <span class="value"><?php echo number_format($agent['total_livraisons']); ?></span>
                </div>
                <div class="info-item">
                    <span class="label">Note moyenne</span>
                    <span class="value"><?php echo number_format($agent['note_moyenne'], 2); ?>/5 ⭐</span>
                </div>
                <div class="info-item">
                    <span class="label">Taux de commission</span>
                    <span class="value"><?php echo number_format($agent['commission_rate'], 2); ?>%</span>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.dashboard-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.revenue-display {
    padding: 1rem;
}

.revenue-main {
    text-align: center;
    padding: 1.5rem;
    background: linear-gradient(135deg, rgba(0, 180, 216, 0.1), rgba(0, 150, 199, 0.1));
    border-radius: 12px;
    margin-bottom: 1.5rem;
}

.revenue-amount {
    display: block;
    font-size: 2.5rem;
    font-weight: 700;
    color: #00B4D8;
}

.revenue-label {
    color: var(--text-secondary);
}

.revenue-breakdown {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

.revenue-item {
    text-align: center;
    padding: 1rem;
    background: #f8f9fa;
    border-radius: 8px;
}

.revenue-item .amount {
    display: block;
    font-size: 1.25rem;
    font-weight: 600;
    margin-bottom: 0.25rem;
}

.revenue-item .label {
    font-size: 0.85rem;
    color: var(--text-secondary);
    margin-bottom: 0.5rem;
    display: block;
}

.performance-stats {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}

.perf-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: #f8f9fa;
    border-radius: 8px;
}

.perf-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    background: rgba(0, 180, 216, 0.2);
    color: #00B4D8;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
}

.perf-icon.danger {
    background: rgba(239, 68, 68, 0.2);
    color: #EF4444;
}

.perf-icon.success {
    background: rgba(34, 197, 94, 0.2);
    color: #22C55E;
}

.perf-icon.info {
    background: rgba(59, 130, 246, 0.2);
    color: #3B82F6;
}

.perf-value {
    display: block;
    font-size: 1.25rem;
    font-weight: 600;
}

.perf-label {
    font-size: 0.85rem;
    color: var(--text-secondary);
}

.agent-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.info-item {
    padding: 1rem;
    background: #f8f9fa;
    border-radius: 8px;
}

.info-item .label {
    display: block;
    font-size: 0.85rem;
    color: var(--text-secondary);
    margin-bottom: 0.25rem;
}

.info-item .value {
    font-size: 1rem;
    font-weight: 500;
}

@media (max-width: 768px) {
    .performance-stats {
        grid-template-columns: 1fr;
    }
    
    .revenue-breakdown {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
function filterPeriod(period) {
    loadPage('agent_dashboard.php?period=' + period, 'Dashboard Agent');
}

function viewParcel(parcelId) {
    loadPage('delivery_details.php?id=' + parcelId, 'Détails Colis');
}

function exportCommissions() {
    const period = document.getElementById('periodFilter').value;
    window.open('api/export_commissions.php?period=' + period, '_blank');
}

console.log('%c🚀 Gestion_Colis - Dashboard Agent', 'color: #00B4D8; font-size: 16px; font-weight: bold;');
</script>

</div> <!-- Fin #page-content -->
