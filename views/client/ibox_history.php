<?php
/**
 * Historique d'accès iBox - Vue client
 */

require_once __DIR__ . '/../../utils/session.php';
SessionManager::start();
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="access-denied">Accès refusé. Veuillez vous connecter.</div>';
    exit;
}

require_once __DIR__ . '/../../config/database.php';

$userId = $_SESSION['user_id'];
$message = '';
$messageType = '';

try {
    $database = new Database();
    $db = $database->getConnection();

    $stmt = $db->prepare("
        SELECT l.*, i.code_box, i.localisation
        FROM ibox_access_logs l
        LEFT JOIN ibox i ON l.ibox_id = i.id
        WHERE l.utilisateur_id = ?
        ORDER BY l.date_action DESC
        LIMIT 100
    ");
    $stmt->execute([$userId]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $logs = [];
    $message = user_error_message($e, 'ibox_history.fetch', 'Erreur lors de la récupération de l’historique iBox.');
    $messageType = 'error';
}

$total = count($logs);
$authorized = count(array_filter($logs, fn($l) => ($l['action'] ?? '') === 'acces_autorise'));
$refused = count(array_filter($logs, fn($l) => ($l['action'] ?? '') === 'acces_refuse'));

$actionLabels = [
    'ouverture' => 'Ouverture',
    'fermeture' => 'Fermeture',
    'depot' => 'Dépôt',
    'retour_scan' => 'Retour',
    'acces_autorise' => 'Accès autorisé',
    'acces_refuse' => 'Accès refusé',
];

$actionBadges = [
    'acces_autorise' => 'success',
    'acces_refuse' => 'danger',
    'depot' => 'info',
    'ouverture' => 'primary',
    'fermeture' => 'secondary',
    'retour_scan' => 'warning',
];
?>

<!-- Contenu pour le système SPA -->
<div id="page-content">
<div class="page-container">
    <div class="page-header">
        <h1><i class="fas fa-history"></i> Historique iBox</h1>
        <p class="text-muted">Derniers accès liés à vos boîtes virtuelles</p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?>">
            <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-list"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?= $total ?></span>
                <span class="stat-label">Total d'accès</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-success"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?= $authorized ?></span>
                <span class="stat-label">Accès autorisés</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-danger"><i class="fas fa-times-circle"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?= $refused ?></span>
                <span class="stat-label">Accès refusés</span>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-inbox"></i> Détails des accès</h3>
        </div>

        <?php if (empty($logs)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <p>Aucun historique iBox disponible.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>iBox</th>
                            <th>Action</th>
                            <th>Code utilisé</th>
                            <th>Adresse IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <?php
                            $action = $log['action'] ?? '';
                            $label = $actionLabels[$action] ?? ucfirst(str_replace('_', ' ', $action));
                            $badge = $actionBadges[$action] ?? 'secondary';
                            ?>
                            <tr>
                                <td><?= $log['date_action'] ? date('d/m/Y H:i', strtotime($log['date_action'])) : '-' ?></td>
                                <td>
                                    <?= htmlspecialchars($log['code_box'] ?? '-') ?>
                                    <div class="text-muted small"><?= htmlspecialchars($log['localisation'] ?? '') ?></div>
                                </td>
                                <td><span class="badge badge-<?= $badge ?>"><?= htmlspecialchars($label) ?></span></td>
                                <td><?= htmlspecialchars($log['code_utilise'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($log['ip_address'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>
