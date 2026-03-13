<?php
/**
 * Générateur de Factures PDF Simplifié - Gestion_Colis
 * Compatible avec le schéma de base de données actuel
 */

require_once __DIR__ . '/utils/session.php';
SessionManager::start();
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Vérifier l'authentification
if (!isset($_SESSION['user_id'])) {
    die('Accès refusé');
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? '';

// Récupérer l'utilisateur
$stmt = $db->prepare("SELECT * FROM utilisateurs WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    die('Utilisateur non trouvé');
}

// Récupérer l'ID du colis
$colis_id = $_GET['id'] ?? 0;

if (!$colis_id) {
    die('ID de colis requis.');
}

// Récupérer les informations du colis
$stmt = $db->prepare("
    SELECT 
        c.*,
        u.nom as user_nom, 
        u.prenom as user_prenom, 
        u.email as user_email,
        u.tel as user_tel,
        u.adresse as user_adresse,
        l.date_fin as date_livraison_effective,
        l.notes_agent as notes_livraison,
        ag.numero_agent as agent_code
    FROM colis c
    JOIN utilisateurs u ON c.utilisateur_id = u.id
    LEFT JOIN livraisons l ON c.id = l.colis_id AND l.statut IN ('livree', 'en_cours')
    LEFT JOIN agents ag ON l.agent_id = ag.id
    WHERE c.id = ?
");
$stmt->execute([$colis_id]);
$colis = $stmt->fetch();

if (!$colis) {
    die('Colis non trouvé.');
}

// Vérifier les droits d'accès
if ($user_role !== 'admin' && $colis['utilisateur_id'] != $user_id) {
    die('Accès interdit à cette facture.');
}

// Récupérer les informations de paiement
$stmt = $db->prepare("
    SELECT * FROM paiements 
    WHERE colis_id = ? 
    ORDER BY date_paiement DESC
    LIMIT 1
");
$stmt->execute([$colis_id]);
$paiement = $stmt->fetch();

// Si le colis n'est pas livré, générer un récapitulatif au lieu d'une facture
$is_invoice = ($colis['statut'] === 'livre');

// Générer le PDF
require_once('utils/pdf_generator.php');

$pdf = new PDF();

// En-tête du document
$pdf->AddPage();

// Logo et titre
$pdf->SetFont('Arial', 'B', 20);
$pdf->SetTextColor(0, 180, 216);
$pdf->Cell(0, 12, 'GESTION_COLIS', 0, 1, 'C');

$pdf->SetFont('Arial', 'B', 14);
$pdf->SetTextColor(100, 100, 100);
$pdf->Cell(0, 8, $is_invoice ? 'FACTURE' : 'RÉCAPITULATIF', 0, 1, 'C');

$pdf->Ln(5);

// Numéro de document
$doc_number = $is_invoice ? 'FAC-' . str_pad($colis['id'], 6, '0', STR_PAD_LEFT) : 'REC-' . date('Ymd');
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, 'Document N°: ' . $doc_number, 0, 1, 'R');
$pdf->Cell(0, 6, 'Date: ' . date('d/m/Y', strtotime($colis['date_creation'])), 0, 1, 'R');

$pdf->Ln(10);

// Informations client
$pdf->SetFillColor(240, 240, 240);
$pdf->Rect(15, $pdf->GetY(), 180, 30, 'F');

$pdf->SetFont('Arial', 'B', 11);
$pdf->SetTextColor(0, 180, 216);
$pdf->Cell(0, 8, 'INFORMATIONS CLIENT', 0, 1, 'L');

$pdf->SetFont('Arial', '', 10);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(0, 6, 'Nom: ' . $colis['user_prenom'] . ' ' . $colis['user_nom'], 0, 1, 'L');
$pdf->Cell(0, 6, 'Email: ' . $colis['user_email'], 0, 1, 'L');
if (!empty($colis['user_tel'])) {
    $pdf->Cell(0, 6, 'Téléphone: ' . $colis['user_tel'], 0, 1, 'L');
}

$pdf->Ln(15);

// Informations du colis
$pdf->SetFont('Arial', 'B', 11);
$pdf->SetTextColor(0, 180, 216);
$pdf->Cell(0, 8, 'DÉTAILS DU COLIS', 0, 1, 'L');

$pdf->SetFont('Arial', '', 10);
$pdf->SetTextColor(0, 0, 0);

// Tableau
$pdf->SetFillColor(240, 240, 240);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(60, 10, 'Référence', 1, 0, 'C', true);
$pdf->Cell(60, 10, 'Code Tracking', 1, 0, 'C', true);
$pdf->Cell(30, 10, 'Statut', 1, 0, 'C', true);
$pdf->Cell(40, 10, 'Date', 1, 1, 'C', true);

$pdf->SetFont('Arial', '', 9);
$pdf->Cell(60, 8, $colis['reference_colis'], 1, 0, 'C');
$pdf->Cell(60, 8, $colis['code_tracking'], 1, 0, 'C');
$pdf->Cell(30, 8, ucfirst(str_replace('_', ' ', $colis['statut'])), 1, 0, 'C');
$pdf->Cell(40, 8, date('d/m/Y', strtotime($colis['date_creation'])), 1, 1, 'C');

$pdf->Ln(5);

// Description et caractéristiques
$pdf->SetFont('Arial', '', 10);
$description = !empty($colis['description']) ? $colis['description'] : 'Colis standard';
$pdf->Cell(0, 6, 'Description: ' . $description, 0, 1, 'L');
$pdf->Cell(0, 6, 'Poids: ' . $colis['poids'] . ' kg' . (!empty($colis['dimensions']) ? ' - Dimensions: ' . $colis['dimensions'] : ''), 0, 1, 'L');
$pdf->Cell(0, 6, 'Valeur déclarée: ' . number_format($colis['valeur_declaree'], 0, ',', ' ') . ' XOF', 0, 1, 'L');

if ($colis['fragile']) {
    $pdf->SetTextColor(245, 158, 11);
    $pdf->Cell(0, 6, '⚠ Colis fragile', 0, 1, 'L');
}
if ($colis['urgent']) {
    $pdf->SetTextColor(239, 68, 68);
    $pdf->Cell(0, 6, '⏰ Livraison urgente', 0, 1, 'L');
}

$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(10);

// Informations de livraison
if ($colis['statut'] === 'livre' && $colis['date_livraison_effective']) {
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetTextColor(16, 185, 129);
    $pdf->Cell(0, 8, 'LIVRAISON EFFECTUÉE', 0, 1, 'L');
    
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 6, 'Date de livraison: ' . date('d/m/Y à H:i', strtotime($colis['date_livraison_effective'])), 0, 1, 'L');
    if (!empty($colis['agent_code'])) {
        $pdf->Cell(0, 6, 'Livreur: Agent ' . $colis['agent_code'], 0, 1, 'L');
    }
    if (!empty($colis['notes_livraison'])) {
        $pdf->Cell(0, 6, 'Notes: ' . $colis['notes_livraison'], 0, 1, 'L');
    }
    $pdf->Ln(10);
}

// Section Paiement
if ($paiement) {
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetTextColor(0, 180, 216);
    $pdf->Cell(0, 8, 'INFORMATIONS DE PAIEMENT', 0, 1, 'L');
    
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(60, 6, 'Mode: ' . ucfirst($paiement['mode_paiement']), 0, 0);
    $pdf->Cell(60, 6, 'Statut: ' . ucfirst($paiement['statut']), 0, 0);
    $pdf->Cell(0, 6, 'Date: ' . ($paiement['date_paiement'] ? date('d/m/Y', strtotime($paiement['date_paiement'])) : '-'), 0, 1);
    $pdf->Ln(5);
}

// Totaux
$montant = $colis['valeur_declaree'] > 0 ? $colis['valeur_declaree'] : 1000;

$pdf->SetFillColor(240, 240, 240);
$pdf->Rect(110, $pdf->GetY(), 85, 25, 'F');

$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(40, 8, 'Sous-total:', 0, 0, 'R');
$pdf->Cell(45, 8, number_format($montant, 0, ',', ' ') . ' XOF', 0, 1, 'R');

$pdf->Cell(40, 8, 'Frais de service:', 0, 0, 'R');
$pdf->Cell(45, 8, '500 XOF', 0, 1, 'R');

$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.5);
$pdf->Line(150, $pdf->GetY(), 195, $pdf->GetY());

$pdf->SetFont('Arial', 'B', 12);
$pdf->SetTextColor(0, 180, 216);
$pdf->Cell(40, 10, 'TOTAL:', 0, 0, 'R');
$pdf->Cell(45, 10, number_format($montant + 500, 0, ',', ' ') . ' XOF', 0, 1, 'R');

$pdf->Ln(15);

// Pied de page
$pdf->SetFont('Arial', 'I', 8);
$pdf->SetTextColor(150, 150, 150);
$pdf->Cell(0, 5, 'Document généré par Gestion_Colis le ' . date('d/m/Y à H:i'), 0, 1, 'C');
$pdf->Cell(0, 5, $is_invoice ? 'Ce document constitue une preuve de livraison et un justificatif de paiement.' : 'Ce document est un récapitulatif de votre colis.', 0, 1, 'C');

// Signatures
$pdf->Ln(15);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(85, 5, 'Signature du client', 0, 0, 'C');
$pdf->Cell(20, 5, '', 0, 0);
$pdf->Cell(85, 5, 'Cachet / Signature du livreur', 0, 1, 'C');

$pdf->Rect(15, $pdf->GetY(), 85, 25);
$pdf->Rect(110, $pdf->GetY(), 85, 25);

// Sortie du PDF
$filename = $is_invoice ? 'facture_' : 'recapitulatif_';
$pdf->Output('D', $filename . $colis['reference_colis'] . '.pdf');
