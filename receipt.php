<?php
/**
 * Payment Receipt Generator - Générateur de Reçus de Paiement
 * Point d'entrée autonome pour télécharger les reçus
 */

require_once __DIR__ . '/utils/session.php';
SessionManager::start();
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'] ?? 0;

// Vérifier l'accès
if (!$user_id) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Accès refusé. Veuillez vous connecter.';
    exit;
}

// Récupérer l'ID du paiement
$payment_id = $_GET['payment_id'] ?? 0;

if (!$payment_id) {
    header('HTTP/1.1 400 Bad Request');
    echo 'ID de paiement requis.';
    exit;
}

// Récupérer les informations du paiement
$stmt = $db->prepare("
    SELECT c.*, u.prenom, u.nom, u.email
    FROM colis c
    LEFT JOIN utilisateurs u ON c.expediteur_id = u.id
    WHERE c.id = ? AND (c.expediteur_id = ? OR c.destinataire_id = ?)
");
$stmt->execute([$payment_id, $user_id, $user_id]);
$payment = $stmt->fetch();

if (!$payment) {
    header('HTTP/1.1 404 Not Found');
    echo 'Paiement non trouvé.';
    exit;
}

// Générer le reçu PDF
require_once 'utils/tcpdf/tcpdf.php';

// Créer le PDF
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Configurer le document
$pdf->SetCreator('Gestion Colis - Paiements');
$pdf->SetAuthor('Gestion Colis');
$pdf->SetTitle('Reçu de Paiement');
$pdf->SetSubject('Reçu de paiement pour livraison');

// Supprimer l'en-tête et le pied de page par défaut
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

// Ajouter une page
$pdf->AddPage();

// Définir les couleurs
$primaryColor = '#00B4D8';

// Fond sombre
$pdf->SetFillColor(10, 14, 23);
$pdf->Rect(0, 0, 210, 297, 'F');

// Bordure colorée
$pdf->SetDrawColor(0, 180, 216);
$pdf->SetLineWidth(2);
$pdf->Rect(10, 10, 190, 277);

// Logo / Titre
$pdf->SetFont('helvetica', 'B', 20);
$pdf->SetTextColor(0, 180, 216);
$pdf->SetXY(20, 25);
$pdf->Cell(170, 12, 'REÇU DE PAIEMENT', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(100, 100, 100);
$pdf->SetXY(20, 42);
$pdf->Cell(170, 6, 'Gestion Colis - Service de livraison', 0, 1, 'C');

// Ligne de séparation
$pdf->SetDrawColor(0, 180, 216);
$pdf->SetLineWidth(0.5);
$pdf->Line(20, 52, 190, 52);

// Numéro de reçu
$receiptNumber = 'RCPT-' . str_pad($payment_id, 8, '0', STR_PAD_LEFT) . '-' . date('Y');
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetTextColor(100, 100, 100);
$pdf->SetXY(20, 60);
$pdf->Cell(170, 7, 'Numéro de reçu: ' . $receiptNumber, 0, 1, 'L');

// Informations du client
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetXY(25, 75);
$pdf->Cell(160, 8, 'INFORMATIONS DU CLIENT', 0, 1, 'L');

$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(200, 200, 200);

$y = 88;
$pdf->SetXY(30, $y);
$pdf->Cell(50, 6, 'Nom:', 0, 0);
$pdf->SetXY(80, $y);
$pdf->Cell(0, 6, htmlspecialchars($payment['prenom'] . ' ' . $payment['nom']), 0, 1);

$y += 10;
$pdf->SetXY(30, $y);
$pdf->Cell(50, 6, 'Email:', 0, 0);
$pdf->SetXY(80, $y);
$pdf->Cell(0, 6, htmlspecialchars($payment['email'] ?? 'Non spécifié'), 0, 1);

// Détails du paiement
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetXY(25, $y + 15);
$pdf->Cell(160, 8, 'DÉTAILS DU PAIEMENT', 0, 1, 'L');

$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(200, 200, 200);

$y += 28;
$pdf->SetXY(30, $y);
$pdf->Cell(50, 6, 'Colis:', 0, 0);
$pdf->SetXY(80, $y);
$pdf->Cell(0, 6, htmlspecialchars($payment['code_tracking'] ?? 'N/A'), 0, 1);

$y += 10;
$pdf->SetXY(30, $y);
$pdf->Cell(50, 6, 'Description:', 0, 0);
$pdf->SetXY(80, $y);
$pdf->Cell(0, 6, htmlspecialchars($payment['description'] ?? 'Frais de livraison'), 0, 1);

$y += 10;
$pdf->SetXY(30, $y);
$pdf->Cell(50, 6, 'Date de paiement:', 0, 0);
$pdf->SetXY(80, $y);
$pdf->Cell(0, 6, $payment['paid_at'] ? date('d/m/Y à H:i', strtotime($payment['paid_at'])) : date('d/m/Y à H:i'), 0, 1);

$y += 10;
$pdf->SetXY(30, $y);
$pdf->Cell(50, 6, 'Statut:', 0, 0);
$pdf->SetXY(80, $y);
$pdf->SetTextColor(34, 197, 94);
$pdf->Cell(0, 6, 'PAYÉ', 0, 1);
$pdf->SetTextColor(200, 200, 200);

// Montant
$y += 15;
$pdf->SetXY(30, $y);
$pdf->Cell(50, 8, 'Montant total:', 0, 0);
$pdf->SetXY(80, $y);
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(0, 180, 216);
$currencyLabel = strtoupper($payment['payment_currency'] ?? 'XAF');
if ($currencyLabel === 'XAF') {
    $currencyLabel = 'FCFA';
}
$pdf->Cell(0, 8, number_format($payment['payment_amount'], 2, ',', ' ') . ' ' . $currencyLabel, 0, 1);

// Méthode de paiement
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(200, 200, 200);
$y += 15;
$pdf->SetXY(30, $y);
$provider = $payment['payment_provider'] ?? '';
$providerLabel = 'Mobile Money';
if ($provider === 'orange') {
    $providerLabel = 'Orange Money';
} elseif ($provider === 'mtn') {
    $providerLabel = 'MTN MoMo';
} elseif (!empty($payment['stripe_session_id'])) {
    $providerLabel = 'Carte bancaire (Stripe)';
}

$pdf->Cell(50, 6, 'Mode de paiement:', 0, 0);
$pdf->SetXY(80, $y);
$pdf->Cell(0, 6, $providerLabel, 0, 1);

$reference = $payment['payment_reference'] ?? $payment['stripe_session_id'] ?? '';

// Référence de transaction
if (!empty($reference)) {
    $y += 10;
    $pdf->SetXY(30, $y);
    $pdf->Cell(50, 6, 'Référence:', 0, 0);
    $pdf->SetXY(80, $y);
    $pdf->SetFont('courier', '', 8);
    $pdf->Cell(0, 6, substr($reference, 0, 30) . '...', 0, 1);
}

// Pied de page
$pdf->SetFont('helvetica', 'I', 8);
$pdf->SetTextColor(100, 100, 100);
$y = 255;
$pdf->SetXY(20, $y);
$pdf->Cell(170, 5, 'Ce reçu a été généré automatiquement par le système Gestion Colis.', 0, 1, 'C');
$pdf->SetXY(20, $y + 5);
$pdf->Cell(170, 5, 'Pour toute question, veuillez contacter le support.', 0, 1, 'C');
$pdf->SetXY(20, $y + 10);
$pdf->Cell(170, 5, 'Date d\'émission: ' . date('d/m/Y à H:i:s'), 0, 1, 'C');

// Sortie du PDF
$pdf->Output('recu_paiement_' . $payment_id . '.pdf', 'I');
