<?php
/**
 * Certificate Generator - Générateur de Certificats de Signature
 * Point d'entrée autonome pour le téléchargement des certificats
 */

require_once __DIR__ . '/utils/session.php';
SessionManager::start();
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$user_id = (int) ($_SESSION['user_id'] ?? 0);

// Récupérer l'ID de la signature ou un token public
$signature_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$publicToken = trim($_GET['token'] ?? '');

if ($user_id === 0 && $publicToken === '') {
    header('HTTP/1.1 403 Forbidden');
    echo 'Accès refusé.';
    exit;
}

if ($user_id > 0 && $signature_id <= 0 && $publicToken === '') {
    header('HTTP/1.1 400 Bad Request');
    echo 'ID de signature requis.';
    exit;
}

// Récupérer la signature
if ($publicToken !== '') {
    if ($signature_id > 0) {
        $stmt = $db->prepare("SELECT * FROM signatures WHERE id = ? AND signature_hash = ?");
        $stmt->execute([$signature_id, $publicToken]);
    } else {
        $stmt = $db->prepare("SELECT * FROM signatures WHERE signature_hash = ?");
        $stmt->execute([$publicToken]);
    }
} else {
    $stmt = $db->prepare("SELECT * FROM signatures WHERE id = ? AND utilisateur_id = ?");
    $stmt->execute([$signature_id, $user_id]);
}
$signature = $stmt->fetch();

if (!$signature) {
    header('HTTP/1.1 404 Not Found');
    echo 'Signature non trouvée.';
    exit;
}
$signature_id = (int) ($signature['id'] ?? $signature_id);

// Récupérer les informations de l'utilisateur
$stmt = $db->prepare("SELECT * FROM utilisateurs WHERE id = ?");
$stmt->execute([(int) $signature['utilisateur_id']]);
$user = $stmt->fetch();

// Générer le certificat PDF
$autoload = __DIR__ . '/vendor/autoload.php';
$tcpdfPath = __DIR__ . '/vendor/tecnickcom/tcpdf/tcpdf.php';
if (!file_exists($autoload) || !file_exists($tcpdfPath)) {
    header('HTTP/1.1 500 Internal Server Error');
    echo 'Dépendance PDF manquante. Veuillez installer TCPDF via Composer.';
    exit;
}
require_once $autoload;
require_once $tcpdfPath;

// Créer le PDF
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Configurer le document
$pdf->SetCreator('Gestion Colis - iSignature');
$pdf->SetAuthor('Gestion Colis');
$pdf->SetTitle('Certificat de Signature Électronique');
$pdf->SetSubject('Certificat de Signature');

// Supprimer l'en-tête et le pied de page par défaut
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

// Ajouter une page
$pdf->AddPage();

// Définir les couleurs
$primaryColor = '#00B4D8';
$darkBg = '#0A0E17';
$textColor = '#FFFFFF';

// Fond sombre
$pdf->SetFillColor(10, 14, 23);
$pdf->Rect(0, 0, 210, 297, 'F');

// Bordure colorée
$pdf->SetDrawColor(0, 180, 216);
$pdf->SetLineWidth(2);
$pdf->Rect(10, 10, 190, 277);

// Titre principal
$pdf->SetFont('helvetica', 'B', 24);
$pdf->SetTextColor(0, 180, 216);
$pdf->SetXY(20, 25);
$pdf->Cell(170, 15, 'CERTIFICAT DE SIGNATURE', 0, 1, 'C');
$pdf->Cell(170, 10, 'ÉLECTRONIQUE', 0, 1, 'C');

// Ligne de séparation
$pdf->SetDrawColor(0, 180, 216);
$pdf->SetLineWidth(0.5);
$pdf->Line(30, 50, 180, 50);

// Numéro de certificat
$certNumber = 'CERT-' . strtoupper(substr($signature['signature_hash'], 0, 12)) . '-' . date('Y');
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetTextColor(100, 100, 100);
$pdf->SetXY(20, 60);
$pdf->Cell(170, 8, 'Numéro de certificat: ' . $certNumber, 0, 1, 'C');

// Informations du signataire
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetXY(30, 80);
$pdf->Cell(150, 10, 'INFORMATIONS DU SIGNATAIRE', 0, 1, 'L');

$pdf->SetFont('helvetica', '', 11);
$pdf->SetTextColor(200, 200, 200);

$y = 95;
$pdf->SetXY(35, $y);
$pdf->Cell(50, 7, 'Nom:', 0, 0);
$pdf->SetXY(85, $y);
$pdf->Cell(0, 7, htmlspecialchars($user['nom'] . ' ' . $user['prenom']), 0, 1);

$y += 10;
$pdf->SetXY(35, $y);
$pdf->Cell(50, 7, 'Email:', 0, 0);
$pdf->SetXY(85, $y);
$pdf->Cell(0, 7, htmlspecialchars($user['email']), 0, 1);

$y += 10;
$pdf->SetXY(35, $y);
$pdf->Cell(50, 7, 'ID Utilisateur:', 0, 0);
$pdf->SetXY(85, $y);
$pdf->Cell(0, 7, '#' . $user_id, 0, 1);

// Détails de la signature
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetXY(30, $y + 15);
$pdf->Cell(150, 10, 'DÉTAILS DE LA SIGNATURE', 0, 1, 'L');

$pdf->SetFont('helvetica', '', 11);
$pdf->SetTextColor(200, 200, 200);

$y += 30;
$levelLabels = [
    'simple' => 'Simple',
    'advanced' => 'Avancé (avec OTP)',
    'qualified' => 'Qualifié (avec vérification identité)'
];

$pdf->SetXY(35, $y);
$pdf->Cell(50, 7, 'Niveau de signature:', 0, 0);
$pdf->SetXY(85, $y);
$pdf->SetTextColor(0, 180, 216);
$pdf->Cell(0, 7, $levelLabels[$signature['signature_level']] ?? ucfirst($signature['signature_level']), 0, 1);

$pdf->SetTextColor(200, 200, 200);
$y += 10;
$pdf->SetXY(35, $y);
$pdf->Cell(50, 7, 'Date et heure:', 0, 0);
$pdf->SetXY(85, $y);
$pdf->Cell(0, 7, date('d/m/Y à H:i:s', strtotime($signature['created_at'])), 0, 1);

$y += 10;
$pdf->SetXY(35, $y);
$pdf->Cell(50, 7, 'Document:', 0, 0);
$pdf->SetXY(85, $y);
$pdf->Cell(0, 7, htmlspecialchars($signature['document_name'] ?: 'Non spécifié'), 0, 1);

$y += 10;
$pdf->SetXY(35, $y);
$pdf->Cell(50, 7, 'Adresse IP:', 0, 0);
$pdf->SetXY(85, $y);
$pdf->Cell(0, 7, htmlspecialchars($signature['ip_address'] ?: 'Non enregistrée'), 0, 1);

// Hash de signature
$y += 15;
$pdf->SetXY(35, $y);
$pdf->Cell(50, 7, 'Hash de signature:', 0, 0);
$pdf->SetXY(85, $y);
$pdf->SetFont('courier', '', 8);
$pdf->Cell(0, 7, htmlspecialchars($signature['signature_hash']), 0, 1);

// Aperçu de la signature
if (!empty($signature['signature_data'])) {
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY(30, $y + 20);
    $pdf->Cell(150, 10, 'APERÇU DE LA SIGNATURE', 0, 1, 'L');

    // Extraire les données base64
    $signatureData = str_replace('data:image/png;base64,', '', $signature['signature_data']);
    $signatureData = base64_decode($signatureData);

    // Ajouter l'image
    $pdf->Image('@' . $signatureData, 35, $y + 35, 80, 40, 'PNG');
}

// Mention légale
$pdf->SetFont('helvetica', 'I', 8);
$pdf->SetTextColor(100, 100, 100);
$legalY = 250;
$pdf->SetXY(20, $legalY);
$pdf->Cell(170, 5, 'Ce certificat a été généré par le système de signature électronique Gestion Colis.', 0, 1, 'C');
$pdf->SetXY(20, $legalY + 5);
$pdf->Cell(170, 5, 'L\'intégrité de ce document peut être vérifiée via le hash de signature unique ci-dessus.', 0, 1, 'C');
$pdf->SetXY(20, $legalY + 10);
$pdf->Cell(170, 5, 'Date de génération: ' . date('d/m/Y à H:i:s'), 0, 1, 'C');

// Sortie du PDF
$pdf->Output('certificat_signature_' . $signature_id . '.pdf', 'I');
