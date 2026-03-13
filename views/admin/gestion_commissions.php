<?php
// Vérification de la connexion et des droits d'accès
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="access-denied">Accès refusé. Vous devez être administrateur pour accéder à cette page.</div>';
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/commission_service.php';

$message = '';
$messageType = '';
$ajaxMode = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $ajaxResponse = ['success' => false, 'message' => ''];

    try {
        $service = new CommissionService();

        switch ($action) {
            case 'mark_paid':
                $commissionId = (int) ($_POST['commission_id'] ?? 0);
                $transactionId = trim($_POST['transaction_id'] ?? '');

                if ($commissionId <= 0) {
                    $ajaxResponse['message'] = 'ID de commission invalide.';
                    break;
                }

                $ok = $service->markAsPaid($commissionId, $transactionId ?: null);
                $ajaxResponse['success'] = (bool) $ok;
                $ajaxResponse['message'] = $ok
                    ? 'Commission marquée comme payée.'
                    : 'Impossible de mettre à jour la commission.';
                break;

            case 'mark_paid_bulk':
                $ids = $_POST['commission_ids'] ?? [];
                $transactionId = trim($_POST['transaction_id'] ?? '');
                $ids = array_values(array_filter(array_map('intval', (array) $ids)));

                if (empty($ids)) {
                    $ajaxResponse['message'] = 'Aucune commission sélectionnée.';
                    break;
                }

                $count = $service->markMultipleAsPaid($ids, $transactionId ?: null);
                $ajaxResponse['success'] = (bool) $count;
                $ajaxResponse['message'] = $count
                    ? "{$count} commission(s) marquée(s) comme payée(s)."
                    : 'Impossible de mettre à jour les commissions.';
                break;
        }
    } catch (PDOException $e) {
        $ajaxResponse['message'] = user_error_message($e, 'admin_commissions.action', 'Erreur de base de données.');
    }

    if ($ajaxMode) {
        header('Content-Type: application/json');
        echo json_encode($ajaxResponse);
        exit;
    }

    $message = $ajaxResponse['message'];
    $messageType = $ajaxResponse['success'] ? 'success' : 'error';
}

// Récupération des données
try {
    $database = new Database();
    $db = $database->getConnection();

    $summary = $db->query("
        SELECT
            COUNT(*) as total_count,
            SUM(CASE WHEN statut = 'en_attente' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN statut = 'paye' THEN 1 ELSE 0 END) as paid_count,
            SUM(CASE WHEN statut = 'en_attente' THEN montant_total ELSE 0 END) as pending_amount,
            SUM(CASE WHEN statut = 'paye' THEN montant_total ELSE 0 END) as paid_amount,
            SUM(montant_total) as total_amount
        FROM agent_commissions
    ")->fetch(PDO::FETCH_ASSOC);

    $summary = array_merge([
        'total_count' => 0,
        'pending_count' => 0,
        'paid_count' => 0,
        'pending_amount' => 0,
        'paid_amount' => 0,
        'total_amount' => 0,
    ], $summary ?: []);

    $service = new CommissionService();
    $pendingCommissions = $service->getPendingCommissions();

    $recentPaid = $db->query("
        SELECT ac.*, u.nom, u.prenom, c.code_tracking
        FROM agent_commissions ac
        JOIN agents a ON ac.agent_id = a.id
        JOIN utilisateurs u ON a.utilisateur_id = u.id
        LEFT JOIN colis c ON ac.colis_id = c.id
        WHERE ac.statut = 'paye'
        ORDER BY ac.date_paiement DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $summary = [
        'total_count' => 0,
        'pending_count' => 0,
        'paid_count' => 0,
        'pending_amount' => 0,
        'paid_amount' => 0,
        'total_amount' => 0,
    ];
    $pendingCommissions = [];
    $recentPaid = [];
    $message = user_error_message($e, 'admin_commissions.fetch', 'Erreur lors de la récupération des commissions.');
    $messageType = 'error';
}
?>

<!-- Contenu pour le système SPA -->
<div id="page-content">
<div class="page-container">
    <div class="page-header">
        <h1><i class="fas fa-hand-holding-usd"></i> Commissions Agents</h1>
        <div class="header-actions">
            <span class="badge badge-info">Total: <?= number_format((float) $summary['total_amount'], 0, ',', ' ') ?> FCFA</span>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?>">
            <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-wallet"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?= number_format((float) $summary['pending_amount'], 0, ',', ' ') ?> FCFA</span>
                <span class="stat-label">En attente (<?= (int) $summary['pending_count'] ?>)</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-success"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?= number_format((float) $summary['paid_amount'], 0, ',', ' ') ?> FCFA</span>
                <span class="stat-label">Payées (<?= (int) $summary['paid_count'] ?>)</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-warning"><i class="fas fa-coins"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?= number_format((float) $summary['total_amount'], 0, ',', ' ') ?> FCFA</span>
                <span class="stat-label">Total commissions</span>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-clock"></i> Commissions en attente</h3>
        </div>

        <?php if (empty($pendingCommissions)): ?>
            <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <p>Aucune commission en attente.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Agent</th>
                            <th>Colis</th>
                            <th>Montant</th>
                            <th>Date calcul</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingCommissions as $commission): ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars(trim(($commission['prenom'] ?? '') . ' ' . ($commission['nom'] ?? ''))) ?>
                                    <div class="text-muted small"><?= htmlspecialchars($commission['email'] ?? '') ?></div>
                                </td>
                                <td><?= htmlspecialchars($commission['code_tracking'] ?? '-') ?></td>
                                <td><?= number_format((float) $commission['montant_total'], 0, ',', ' ') ?> FCFA</td>
                                <td><?= $commission['date_calcul'] ? date('d/m/Y H:i', strtotime($commission['date_calcul'])) : '-' ?></td>
                                <td>
                                    <form method="post" class="inline-form">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="mark_paid">
                                        <input type="hidden" name="commission_id" value="<?= (int) $commission['id'] ?>">
                                        <input type="text" name="transaction_id" placeholder="Transaction (optionnel)" class="input-sm">
                                        <button class="btn btn-success btn-sm" type="submit">
                                            <i class="fas fa-check"></i> Marquer payé
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card" style="margin-top:1.5rem;">
        <div class="card-header">
            <h3><i class="fas fa-history"></i> Derniers paiements</h3>
        </div>

        <?php if (empty($recentPaid)): ?>
            <div class="empty-state">
                <i class="fas fa-receipt"></i>
                <p>Aucun paiement récent.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Agent</th>
                            <th>Colis</th>
                            <th>Montant</th>
                            <th>Date paiement</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentPaid as $commission): ?>
                            <tr>
                                <td><?= htmlspecialchars(trim(($commission['prenom'] ?? '') . ' ' . ($commission['nom'] ?? ''))) ?></td>
                                <td><?= htmlspecialchars($commission['code_tracking'] ?? '-') ?></td>
                                <td><?= number_format((float) $commission['montant_total'], 0, ',', ' ') ?> FCFA</td>
                                <td><?= $commission['date_paiement'] ? date('d/m/Y H:i', strtotime($commission['date_paiement'])) : '-' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>
