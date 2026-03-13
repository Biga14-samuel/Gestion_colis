<?php
session_start();

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="access-denied">Accès refusé. Veuillez vous connecter.</div>';
    exit;
}

require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$userId = $_SESSION['user_id'];

// Récupérer les codes de retrait de l'utilisateur
$pickup_codes = [];
try {
    $stmt = $db->prepare("
        SELECT pc.*, c.reference_colis, c.description, c.statut as colis_statut, i.localisation as ibox_localisation
        FROM pickup_codes pc
        JOIN colis c ON pc.colis_id = c.id
        LEFT JOIN ibox i ON c.ibox_id = i.id
        WHERE c.utilisateur_id = ?
        ORDER BY pc.date_creation DESC
    ");
    $stmt->execute([$userId]);
    $pickup_codes = $stmt->fetchAll();
} catch (PDOException $e) {
    $pickup_codes = [];
}

// Récupérer les stats
$stats = [
    'total' => count($pickup_codes),
    'utilises' => count(array_filter($pickup_codes, fn($c) => $c['utilise'] == 1)),
    'non_utilises' => count(array_filter($pickup_codes, fn($c) => $c['utilise'] == 0))
];

// Action AJAX pour copier le code
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'copy_code') {
    $code = $_POST['code'] ?? '';
    echo json_encode(['success' => true, 'code' => $code]);
    exit;
}
?>

<!-- Contenu pour le système SPA -->
<div id="page-content">

<div class="page-container">
    <div class="page-header">
        <h1><i class="fas fa-qrcode"></i> Mes Codes de Retrait</h1>
        <p>Accédez à vos codes de retrait pour récupérer vos colis</p>
    </div>

    <!-- Info box -->
    <div class="info-box">
        <i class="fas fa-info-circle"></i>
        <div>
            <strong>Information</strong>
            <p>Utilisez ces codes pour retirer vos colis dans les iBox ou auprès de vos livreurs. Chaque code est unique et expire après la période définie.</p>
        </div>
    </div>

    <!-- Statistiques -->
    <div class="stats-row">
        <div class="stat-pill">
            <i class="fas fa-qrcode"></i>
            <span><?= $stats['total'] ?> Code(s)</span>
        </div>
        <div class="stat-pill success">
            <i class="fas fa-check-circle"></i>
            <span><?= $stats['utilises'] ?> Utilisé(s)</span>
        </div>
        <div class="stat-pill warning">
            <i class="fas fa-clock"></i>
            <span><?= $stats['non_utilises'] ?> Actif(s)</span>
        </div>
    </div>

    <?php if (empty($pickup_codes)): ?>
        <div class="card">
            <div class="card-body">
                <div class="empty-state">
                    <i class="fas fa-qrcode fa-3x"></i>
                    <h3>Aucun Code de Retrait</h3>
                    <p>Vous n'avez pas encore de codes de retrait. Vos codes apparaîtront ici dès qu'un colis sera prêt pour vous.</p>
                    <button class="btn btn-primary" onclick="loadPage('creer_colis.php', 'Créer un Colis')">
                        <i class="fas fa-plus"></i> Créer un Colis
                    </button>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="codes-grid">
            <?php foreach ($pickup_codes as $code): ?>
                <div class="code-card">
                    <div class="code-main">
                        <div class="code-label">Code de retrait</div>
                        <div class="code-value" id="code-<?= $code['id'] ?>">
                            <?= htmlspecialchars($code['code'] ?? 'N/A') ?>
                        </div>
                        <div class="code-details">
                            <div class="code-detail">
                                <i class="fas fa-box"></i>
                                <span><?= htmlspecialchars($code['reference_colis'] ?? 'N/A') ?></span>
                            </div>
                            <div class="code-detail">
                                <i class="fas fa-map-marker-alt"></i>
                                <span><?= htmlspecialchars($code['ibox_localisation'] ?? 'Livraison standard') ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="code-actions">
                        <div class="code-status">
                            <span class="badge badge-<?= ($code['utilise'] ?? 0) ? 'success' : 'warning' ?>">
                                <?= ($code['utilise'] ?? 0) ? 'Utilisé' : 'Actif' ?>
                            </span>
                        </div>
                        <button class="btn btn-copy" onclick="copyCode(<?= $code['id'] ?>, '<?= htmlspecialchars($code['code'] ?? '') ?>')">
                            <i class="fas fa-copy"></i> Copier
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
/* Styles pour les codes de retrait - Compatibles SPA */
.page-container {
    padding: 1.5rem;
    max-width: 1000px;
    margin: 0 auto;
}

.page-header {
    margin-bottom: 1.5rem;
}

.page-header h1 {
    font-family: 'Orbitron', sans-serif;
    font-size: 1.5rem;
    color: var(--text-dark, #0f172a);
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.page-header h1 i {
    color: var(--primary-cyan, #00B4D8);
}

.page-header p {
    color: var(--text-secondary, #64748b);
    margin-top: 0.25rem;
}

.card {
    background: #ffffff;
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 12px;
    overflow: hidden;
}

.card-body {
    padding: 1.5rem;
}

/* Info box */
.info-box {
    background: rgba(0, 180, 216, 0.08);
    border: 1px solid rgba(0, 180, 216, 0.2);
    border-radius: 12px;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: flex-start;
    gap: 1rem;
}

.info-box i {
    font-size: 1.25rem;
    color: var(--primary-cyan, #00B4D8);
    flex-shrink: 0;
    margin-top: 0.125rem;
}

.info-box strong {
    display: block;
    color: var(--text-dark, #0f172a);
    margin-bottom: 0.25rem;
}

.info-box p {
    color: var(--text-secondary, #64748b);
    font-size: 0.9rem;
    margin: 0;
    line-height: 1.5;
}

/* Stats row */
.stats-row {
    display: flex;
    gap: 1rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}

.stat-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: #ffffff;
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 50px;
    font-size: 0.9rem;
    font-weight: 500;
    color: var(--text-dark, #0f172a);
}

.stat-pill i {
    color: var(--primary-cyan, #00B4D8);
}

.stat-pill.success i {
    color: #22c55e;
}

.stat-pill.warning i {
    color: #f59e0b;
}

/* Codes grid */
.codes-grid {
    display: grid;
    gap: 1rem;
}

.code-card {
    background: #ffffff;
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 12px;
    padding: 1.25rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
    transition: all 0.3s ease;
}

.code-card:hover {
    border-color: var(--primary-cyan, #00B4D8);
    box-shadow: 0 4px 12px rgba(0, 180, 216, 0.1);
}

.code-main {
    flex: 1;
    min-width: 200px;
}

.code-label {
    font-size: 0.8rem;
    color: var(--text-secondary, #64748b);
    margin-bottom: 0.25rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.code-value {
    font-family: 'Orbitron', monospace;
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--primary-cyan, #00B4D8);
    letter-spacing: 2px;
    margin-bottom: 0.5rem;
}

.code-details {
    display: flex;
    gap: 1.5rem;
    flex-wrap: wrap;
}

.code-detail {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
    color: var(--text-secondary, #64748b);
}

.code-detail i {
    color: var(--primary-cyan, #00B4D8);
}

.code-actions {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.code-status {
    flex-shrink: 0;
}

.badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-success {
    background: rgba(34, 197, 94, 0.1);
    color: #22c55e;
}

.badge-warning {
    background: rgba(245, 158, 11, 0.1);
    color: #f59e0b;
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.625rem 1.25rem;
    border: none;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-primary {
    background: linear-gradient(135deg, var(--primary-cyan, #00B4D8), #0891b2);
    color: #ffffff;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 180, 216, 0.3);
}

.btn-copy {
    background: linear-gradient(135deg, var(--primary-cyan, #00B4D8), #0891b2);
    color: #ffffff;
}

.btn-copy:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 180, 216, 0.3);
}

/* Empty state */
.empty-state {
    text-align: center;
    padding: 3rem;
}

.empty-state i {
    font-size: 3rem;
    color: var(--border-color, #e2e8f0);
    margin-bottom: 1rem;
}

.empty-state h3 {
    font-family: 'Orbitron', sans-serif;
    color: var(--text-dark, #0f172a);
    margin-bottom: 0.5rem;
}

.empty-state p {
    color: var(--text-secondary, #64748b);
    max-width: 400px;
    margin: 0 auto 1.5rem;
}

/* Responsive */
@media (max-width: 640px) {
    .code-card {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .code-actions {
        width: 100%;
        justify-content: space-between;
    }
    
    .stats-row {
        flex-direction: column;
    }
    
    .stat-pill {
        justify-content: center;
    }
}
</style>

<script>
function copyCode(id, code) {
    if (!code) {
        showNotification('Code non disponible', 'error');
        return;
    }
    
    navigator.clipboard.writeText(code).then(() => {
        showNotification('Code copié : ' + code, 'success');
    }).catch(() => {
        // Fallback pour navigateurs plus anciens
        const textArea = document.createElement('textarea');
        textArea.value = code;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        showNotification('Code copié : ' + code, 'success');
    });
}

console.log('%c🚀 Gestion_Colis - Codes de Retrait SPA', 'color: #00B4D8; font-size: 16px; font-weight: bold;');
</script>

</div> <!-- Fin #page-content -->
