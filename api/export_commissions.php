<?php
/**
 * API d'export des commissions
 * Gestion_Colis v2.0
 */

session_start();
require_once 'config/database.php';
require_once 'utils/commission_service.php';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="commissions_export_' . date('Y-m-d') . '.csv"');

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'] ?? 0;

if (!$user_id) {
    http_response_code(403);
    exit('Accès refusé');
}

// Vérifier le rôle
$userStmt = $db->prepare("SELECT role FROM utilisateurs WHERE id = ?");
$userStmt->execute([$user_id]);
$user = $userStmt->fetch();

if (!$user || ($user['role'] !== 'agent' && $user['role'] !== 'admin')) {
    http_response_code(403);
    exit('Accès refusé');
}

// Récupérer l'agent
$agentStmt = $db->prepare("SELECT id FROM agents WHERE utilisateur_id = ?");
$agentStmt->execute([$user_id]);
$agent = $agentStmt->fetch();

if (!$agent) {
    http_response_code(404);
    exit('Agent non trouvé');
}

// Récupérer les commissions
$period = $_GET['period'] ?? 'month';
$dateFilter = match($period) {
    'week' => "DATE_SUB(NOW(), INTERVAL 1 WEEK)",
    'month' => "DATE_SUB(NOW(), INTERVAL 1 MONTH)",
    'year' => "DATE_SUB(NOW(), INTERVAL 1 YEAR)",
    default => "DATE_SUB(NOW(), INTERVAL 1 MONTH)"
};

$stmt = $db->prepare("
    SELECT ac.*, c.code_tracking
    FROM agent_commissions ac
    LEFT JOIN colis c ON ac.colis_id = c.id
    WHERE ac.agent_id = ? AND ac.date_calcul >= {$dateFilter}
    ORDER BY ac.date_calcul DESC
");
$stmt->execute([$agent['id']]);
$commissions = $stmt->fetchAll();

// Créer le CSV
$output = fopen('php://output', 'w');

// BOM UTF-8 pour Excel
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// En-têtes
fputcsv($output, [
    'Date de calcul',
    'Numéro de colis',
    'Montant de base (FCFA)',
    'Montant KM (FCFA)',
    'Bonus (FCFA)',
    'Total (FCFA)',
    'Statut',
    'Date de paiement'
], ';');

// Données
foreach ($commissions as $comm) {
    fputcsv($output, [
        date('d/m/Y H:i', strtotime($comm['date_calcul'])),
        $comm['code_tracking'] ?? '-',
        number_format($comm['montant_base'], 2, ',', ''),
        number_format($comm['montant_km'], 2, ',', ''),
        number_format($comm['montant_bonus'], 2, ',', ''),
        number_format($comm['montant_total'], 2, ',', ''),
        ucfirst(str_replace('_', ' ', $comm['statut'])),
        $comm['date_paiement'] ? date('d/m/Y H:i', strtotime($comm['date_paiement'])) : '-'
    ], ';');
}

// Totaux
$totalBase = array_sum(array_column($commissions, 'montant_base'));
$totalKm = array_sum(array_column($commissions, 'montant_km'));
$totalBonus = array_sum(array_column($commissions, 'montant_bonus'));
$total = array_sum(array_column($commissions, 'montant_total'));

fputcsv($output, [], ';');
fputcsv($output, ['TOTAL', '', number_format($totalBase, 2, ',', ''), number_format($totalKm, 2, ',', ''), number_format($totalBonus, 2, ',', ''), number_format($total, 2, ',', ''), '', ''], ';');

fclose($output);
