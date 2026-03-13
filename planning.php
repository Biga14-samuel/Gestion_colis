<?php
/**
 * Page de planification pour les agents de livraison
 */

session_start();

// Vérifier si l'utilisateur est connecté et est un agent
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['agent', 'admin'])) {
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="access-denied">Accès refusé. Vous devez être agent ou administrateur pour accéder à cette page.</div>';
    exit;
}

require_once 'config/database.php';

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'];
$message = '';
$messageType = '';

// Récupérer la date du jour
$today = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d', strtotime('sunday this week'));

// Récupérer les livraisons programmées pour la semaine
try {
    $database = new Database();
    $db = $database->getConnection();
    
    if ($userRole === 'admin') {
        // Admin voit toutes les livraisons
        $stmt = $db->prepare("
            SELECT c.*, u.nom as client_nom, u.prenom as client_prenom, u.telephone as client_phone
            FROM colis c
            LEFT JOIN utilisateurs u ON c.utilisateur_id = u.id
            WHERE c.date_livraison_estimee BETWEEN ? AND ?
            AND c.statut IN ('en_attente', 'en_preparation')
            ORDER BY c.date_livraison_estimee ASC
        ");
        $stmt->execute([$weekStart, $weekEnd]);
    } else {
        // Agent voit ses livraisons via la table livraisons
        $stmt = $db->prepare("
            SELECT c.*, u.nom as client_nom, u.prenom as client_prenom, u.telephone as client_phone,
                   l.id as livraison_id, l.statut as livraison_statut
            FROM colis c
            INNER JOIN livraisons l ON c.id = l.colis_id
            LEFT JOIN utilisateurs u ON c.utilisateur_id = u.id
            LEFT JOIN agents a ON l.agent_id = a.id
            WHERE a.utilisateur_id = ?
            AND c.date_livraison_estimee BETWEEN ? AND ?
            AND c.statut IN ('en_attente', 'en_preparation', 'en_livraison')
            ORDER BY c.date_livraison_estimee ASC
        ");
        $stmt->execute([$userId, $weekStart, $weekEnd]);
    }
    
    $livraisons_semaine = $stmt->fetchAll();
    
} catch (Exception $e) {
    $livraisons_semaine = [];
    $message = user_error_message($e, 'planning.fetch', 'Erreur lors de la récupération du planning.');
    $messageType = 'error';
}

// Récupérer les statistiques de la semaine via la table livraisons
try {
    if ($userRole === 'admin') {
        $stmt = $db->query("
            SELECT 
                COUNT(*) as total_livraisons,
                SUM(CASE WHEN c.statut = 'livre' THEN 1 ELSE 0 END) as livraisons_effectuees,
                SUM(CASE WHEN c.statut = 'en_livraison' THEN 1 ELSE 0 END) as en_cours,
                SUM(CASE WHEN c.statut = 'annule' THEN 1 ELSE 0 END) as annulees
            FROM colis c
            WHERE c.date_livraison_estimee BETWEEN '$weekStart' AND '$weekEnd'
        ");
    } else {
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_livraisons,
                SUM(CASE WHEN c.statut = 'livre' THEN 1 ELSE 0 END) as livraisons_effectuees,
                SUM(CASE WHEN c.statut = 'en_livraison' THEN 1 ELSE 0 END) as en_cours,
                SUM(CASE WHEN c.statut = 'annule' THEN 1 ELSE 0 END) as annulees
            FROM colis c
            INNER JOIN livraisons l ON c.id = l.colis_id
            INNER JOIN agents a ON l.agent_id = a.id
            WHERE a.utilisateur_id = ? AND c.date_livraison_estimee BETWEEN ? AND ?
        ");
        $stmt->execute([$userId, $weekStart, $weekEnd]);
    }
    $stats_semaine = $stmt->fetch();
} catch (Exception $e) {
    $stats_semaine = ['total_livraisons' => 0, 'livraisons_effectuees' => 0, 'en_cours' => 0, 'annulees' => 0];
}
?>

<!-- Contenu pour le système SPA -->
<div id="page-content">

<div class="page-container">
    <div class="page-header">
        <h1><i class="fas fa-calendar-alt" style="color: #00B4D8;"></i> Planning des Livraisons</h1>
        <div class="header-actions">
            <span class="date-range">
                <i class="fas fa-calendar-week"></i>
                <?php echo date('d/m', strtotime($weekStart)) . ' - ' . date('d/m/Y', strtotime($weekEnd)); ?>
            </span>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>" style="margin-bottom: 1.5rem;">
            <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
            <div><?php echo htmlspecialchars($message); ?></div>
        </div>
    <?php endif; ?>

    <!-- Statistiques de la semaine -->
    <div class="stats-grid" style="margin-bottom: 1.5rem;">
        <div class="stat-card">
            <div class="stat-icon blue">
                <i class="fas fa-truck"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?php echo $stats_semaine['total_livraisons'] ?? 0; ?></span>
                <span class="stat-label">Livraisons prévues</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?php echo $stats_semaine['livraisons_effectuees'] ?? 0; ?></span>
                <span class="stat-label">Effectuées</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon orange">
                <i class="fas fa-shipping-fast"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?php echo $stats_semaine['en_cours'] ?? 0; ?></span>
                <span class="stat-label">En cours</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple">
                <i class="fas fa-percentage"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value">
                    <?php 
                    $total = $stats_semaine['total_livraisons'] ?? 0;
                    $effectuees = $stats_semaine['livraisons_effectuees'] ?? 0;
                    echo $total > 0 ? round(($effectuees / $total) * 100) : 0;
                    ?>%
                </span>
                <span class="stat-label">Taux de complétion</span>
            </div>
        </div>
    </div>

    <!-- Planning de la semaine -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-list"></i> Livraisons de la semaine</h3>
        </div>
        <div class="card-body">
            <?php if (empty($livraisons_semaine)): ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-check"></i>
                    <p>Aucune livraison prévue pour cette semaine</p>
                </div>
            <?php else: ?>
                <div class="planning-timeline">
                    <?php foreach ($livraisons_semaine as $livraison): ?>
                        <div class="planning-item" data-date="<?php echo date('Y-m-d', strtotime($livraison['date_livraison_estimee'])); ?>">
                            <div class="planning-date">
                                <span class="day"><?php echo date('d', strtotime($livraison['date_livraison_estimee'])); ?></span>
                                <span class="month"><?php echo ucfirst(date('M', strtotime($livraison['date_livraison_estimee']))); ?></span>
                            </div>
                            <div class="planning-details">
                                <div class="planning-header">
                                    <h4><?php echo htmlspecialchars($livraison['reference_colis']); ?></h4>
                                    <span class="status-badge status-<?php echo str_replace(' ', '-', $livraison['statut']); ?>">
                                        <?php echo htmlspecialchars(ucfirst($livraison['statut'])); ?>
                                    </span>
                                </div>
                                <div class="planning-client">
                                    <i class="fas fa-user"></i>
                                    <?php echo htmlspecialchars($livraison['client_prenom'] . ' ' . $livraison['client_nom']); ?>
                                </div>
                                <div class="planning-address">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?php echo htmlspecialchars($livraison['adresse_livraison'] ?? 'Adresse non spécifiée'); ?>
                                </div>
                                <div class="planning-time">
                                    <i class="fas fa-clock"></i>
                                    <?php echo date('H:i', strtotime($livraison['date_livraison_estimee'])); ?>
                                </div>
                            </div>
                            <div class="planning-actions">
                                <button class="btn btn-sm btn-primary" onclick="loadPage('views/agent/mes_livraisons.php?colis_id=<?php echo $livraison['id']; ?>', 'Détails Livraison')">
                                    <i class="fas fa-eye"></i> Voir
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.planning-timeline {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.planning-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: rgba(15, 23, 42, 0.6);
    border: 1px solid rgba(0, 240, 255, 0.15);
    border-radius: 12px;
    transition: all 0.3s ease;
}

.planning-item:hover {
    border-color: rgba(0, 240, 255, 0.3);
    transform: translateX(5px);
}

.planning-date {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, var(--neon-cyan), var(--neon-blue));
    border-radius: 12px;
    color: #000;
}

.planning-date .day {
    font-size: 1.5rem;
    font-weight: 700;
    line-height: 1;
}

.planning-date .month {
    font-size: 0.75rem;
    text-transform: uppercase;
}

.planning-details {
    flex: 1;
}

.planning-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 0.5rem;
}

.planning-header h4 {
    margin: 0;
    color: var(--text-primary);
    font-size: 1rem;
}

.planning-client, .planning-address, .planning-time {
    font-size: 0.85rem;
    color: var(--text-secondary);
    margin-bottom: 0.25rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.planning-client i, .planning-address i, .planning-time i {
    color: #00B4D8;
    width: 16px;
    text-align: center;
}

.empty-state {
    text-align: center;
    padding: 3rem;
    color: var(--text-muted);
}

.empty-state i {
    font-size: 3rem;
    margin-bottom: 1rem;
    color: #00B4D8;
}

.date-range {
    background: rgba(0, 180, 216, 0.1);
    padding: 0.5rem 1rem;
    border-radius: 8px;
    color: #00B4D8;
    font-size: 0.9rem;
}
</style>

<script>
// Filtrer les livraisons par jour
function filterByDay(dayOffset) {
    const today = new Date();
    const targetDate = new Date(today);
    targetDate.setDate(today.getDate() + dayOffset);
    
    const targetDateStr = targetDate.toISOString().split('T')[0];
    
    document.querySelectorAll('.planning-item').forEach(item => {
        if (item.dataset.date === targetDateStr) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
}

// Afficher toutes les livraisons
function showAllDays() {
    document.querySelectorAll('.planning-item').forEach(item => {
        item.style.display = 'flex';
    });
}
</script>

</div> <!-- Fin #page-content -->
