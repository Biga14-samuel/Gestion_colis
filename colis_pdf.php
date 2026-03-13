<?php
/**
 * Colis PDF Generator - Générateur de PDF pour les Colis
 * Point d'entrée autonome pour télécharger les reçus de colis
 * Avec support du téléchargement réel
 */

require_once __DIR__ . '/utils/session.php';
SessionManager::start();
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'] ?? 0;
$is_admin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';

// Vérifier l'accès
if (!$user_id) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Accès refusé. Veuillez vous connecter.';
    exit;
}

// Récupérer l'ID du colis
$colis_id = $_GET['id'] ?? 0;

if (!$colis_id) {
    header('HTTP/1.1 400 Bad Request');
    echo 'ID de colis requis.';
    exit;
}

// Récupérer les informations du colis (admin peut voir tous les colis)
if ($is_admin) {
    $stmt = $db->prepare("
        SELECT c.*, 
               u.nom as expediteur_nom, u.prenom as expediteur_prenom, 
               u.email as expediteur_email, u.telephone as expediteur_telephone
        FROM colis c
        LEFT JOIN utilisateurs u ON c.utilisateur_id = u.id
        WHERE c.id = ?
    ");
    $stmt->execute([$colis_id]);
} else {
    $stmt = $db->prepare("
        SELECT c.*, 
               u.nom as expediteur_nom, u.prenom as expediteur_prenom, 
               u.email as expediteur_email, u.telephone as expediteur_telephone
        FROM colis c
        LEFT JOIN utilisateurs u ON c.utilisateur_id = u.id
        WHERE c.id = ? AND c.utilisateur_id = ?
    ");
    $stmt->execute([$colis_id, $user_id]);
}

$colis = $stmt->fetch();

if (!$colis) {
    header('HTTP/1.1 404 Not Found');
    echo 'Colis non trouvé.';
    exit;
}

// Vérifier si c'est une demande de download
$isDownload = isset($_GET['download']) && $_GET['download'] == '1';
$isPrint = isset($_GET['print']) && $_GET['print'] == '1';

// Format de date française
function formatDate($date) {
    if (empty($date)) return 'N/A';
    $d = new DateTime($date);
    return $d->format('d/m/Y à H:i');
}

// Récupérer les informations de l'agent assigné
$agent_info = null;
if (!empty($colis['agent_id'])) {
    $stmt = $db->prepare("
        SELECT u.nom, u.prenom, a.matricule, a.zone_livraison
        FROM agents a
        JOIN utilisateurs u ON a.utilisateur_id = u.id
        WHERE a.id = ?
    ");
    $stmt->execute([$colis['agent_id']]);
    $agent_info = $stmt->fetch();
}

// Récupérer la signature du client (si disponible)
$client_signature = '';
if (!empty($colis['signature_data'])) {
    // La signature est stockée comme données base64
    $client_signature = $colis['signature_data'];
} elseif (!empty($colis['signature_image'])) {
    // Ou comme chemin vers une image
    $signature_path = $colis['signature_image'];
    if (file_exists($signature_path)) {
        $client_signature = base64_encode(file_get_contents($signature_path));
    }
}

// Générer le HTML du reçu
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Bordereau - ' . htmlspecialchars($colis['code_tracking']) . '</title>
    <style>
        @page { margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 15px;
            background: #fff;
            color: #333;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            border: 3px solid #00B4D8;
            padding: 15px;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #00B4D8;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .header h1 {
            color: #00B4D8;
            margin: 0;
            font-size: 22px;
        }
        .header p {
            margin: 5px 0 0;
            color: #666;
            font-size: 12px;
        }
        .tracking-box {
            background: #00B4D8;
            color: white;
            padding: 12px 20px;
            font-size: 16px;
            font-weight: bold;
            text-align: center;
            margin: 15px 0;
            border-radius: 5px;
            font-family: "Courier New", monospace;
            letter-spacing: 1px;
        }
        .section {
            margin: 10px 0;
            padding: 10px;
            background: #f8f9fa;
            border-left: 4px solid #00B4D8;
        }
        .section-title {
            font-weight: bold;
            color: #00B4D8;
            margin-bottom: 8px;
            font-size: 13px;
            text-transform: uppercase;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 5px;
        }
        .info-row {
            padding: 4px 0;
            border-bottom: 1px solid #eee;
            font-size: 12px;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: bold;
            color: #666;
            font-size: 11px;
        }
        .info-value {
            color: #333;
        }
        .flags {
            display: flex;
            gap: 10px;
            margin-top: 5px;
        }
        .flag {
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
        }
        .flag.fragile {
            background: #fef3c7;
            color: #d97706;
            border: 1px solid #f59e0b;
        }
        .flag.urgent {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #ef4444;
        }
        .signature-box {
            border: 2px dashed #ccc;
            height: 70px;
            margin-top: 15px;
            text-align: center;
            padding: 10px;
            color: #999;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 10px;
            color: #999;
            border-top: 1px solid #eee;
            padding-top: 8px;
        }
        .barcode-placeholder {
            text-align: center;
            padding: 10px;
            margin: 10px 0;
        }
        .barcode-placeholder img {
            max-width: 200px;
            height: 60px;
        }
        @media print {
            body { padding: 0; margin: 0; }
            .container { border: 3px solid #00B4D8; }
            .no-print { display: none !important; }
        }
        @media screen {
            .print-button {
                position: fixed;
                bottom: 20px;
                right: 20px;
                padding: 12px 24px;
                background: #00B4D8;
                color: white;
                border: none;
                border-radius: 8px;
                cursor: pointer;
                font-size: 14px;
                font-weight: bold;
                box-shadow: 0 4px 12px rgba(0, 180, 216, 0.4);
                z-index: 1000;
            }
            .print-button:hover {
                background: #0891b2;
            }
        }
    </style>
</head>
<body>
    <button class="print-button no-print" onclick="window.print()">
        <i class="fas fa-print"></i> Imprimer le bordereau
    </button>
    
    <div class="container">
        <div class="header">
            <h1>BORDEREAU DE COLIS</h1>
            <p>Gestion Colis - Service de livraison professionnel</p>
        </div>
        
        <div class="tracking-box">
            Code: ' . htmlspecialchars($colis['code_tracking']) . '
        </div>
        
        <div class="section">
            <div class="section-title">EXPÉDITEUR / CLIENT</div>
            <div class="info-grid">
                <div class="info-row">
                    <span class="info-label">Nom:</span>
                    <span class="info-value">' . htmlspecialchars($colis['expediteur_prenom'] . ' ' . $colis['expediteur_nom']) . '</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Email:</span>
                    <span class="info-value">' . htmlspecialchars($colis['expediteur_email'] ?? 'N/A') . '</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Téléphone:</span>
                    <span class="info-value">' . htmlspecialchars($colis['expediteur_telephone'] ?? 'N/A') . '</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Date création:</span>
                    <span class="info-value">' . formatDate($colis['date_creation']) . '</span>
                </div>
            </div>
        </div>
        
        <div class="section">
            <div class="section-title">DÉTAILS DU COLIS</div>
            <div class="info-grid">
                <div class="info-row">
                    <span class="info-label">Référence:</span>
                    <span class="info-value">' . htmlspecialchars($colis['reference_colis']) . '</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Statut:</span>
                    <span class="info-value">' . htmlspecialchars(ucfirst($colis['statut'])) . '</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Description:</span>
                    <span class="info-value">' . htmlspecialchars($colis['description']) . '</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Poids:</span>
                    <span class="info-value">' . htmlspecialchars($colis['poids']) . ' kg</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Dimensions:</span>
                    <span class="info-value">' . htmlspecialchars($colis['dimensions'] ?? 'Non spécifié') . '</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Valeur déclarée:</span>
                    <span class="info-value">' . number_format($colis['valeur_declaree'] ?? 0, 0, ',', ' ') . ' FCFA</span>
                </div>
            </div>
            <div class="flags">
                <span class="flag fragile">' . ($colis['fragile'] ? '⚠ FRAGILE' : 'Non fragile') . '</span>
                <span class="flag urgent">' . ($colis['urgent'] ? '⏰ URGENT' : 'Non urgent') . '</span>
            </div>
        </div>';
        
if ($agent_info) {
    $html .= '
        <div class="section">
            <div class="section-title">AGENT RESPONSABLE</div>
            <div class="info-grid">
                <div class="info-row">
                    <span class="info-label">Agent:</span>
                    <span class="info-value">' . htmlspecialchars($agent_info['prenom'] . ' ' . $agent_info['nom']) . '</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Matricule:</span>
                    <span class="info-value">' . htmlspecialchars($agent_info['matricule']) . '</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Zone:</span>
                    <span class="info-value">' . htmlspecialchars($agent_info['zone_livraison'] ?? 'Non définie') . '</span>
                </div>
            </div>
        </div>';
}
        
if (!empty($colis['instructions'])) {
    $html .= '
        <div class="section">
            <div class="section-title">INSTRUCTIONS SPÉCIALES</div>
            <p style="font-size: 12px;">' . htmlspecialchars($colis['instructions']) . '</p>
        </div>';
}

$html .= '
        <!-- Signature section removed as per user request - no signature in PDF -->
        
        <div class="footer">
            <p>Document généré par Gestion Colis - ' . formatDate(date('Y-m-d H:i:s')) . '</p>
            <p>Ce bordereau doit accompagner le colis pendant toute la durée du transport.</p>
        </div>
    </div>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/js/all.min.js"></script>
</body>
</html>';

// Déterminer le comportement selon les paramètres
if ($isDownload || $isPrint) {
    // Pour download ou print, afficher directement le HTML imprimable
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
} else {
    // Mode normal : affichage avec possibilité de download/print
    // Générer le contenu complet avec le JavaScript pour le download
    $fullHtml = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Bordereau de Colis - ' . htmlspecialchars($colis['code_tracking']) . '</title>
        <link rel="stylesheet" href="assets/css/style.css">
        <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/js/all.min.js"></script>
    </head>
    <body>
        <div class="page-container">
            <div class="page-header">
                <h1><i class="fas fa-file-pdf"></i> Bordereau de Colis</h1>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="downloadPDF()">
                        <i class="fas fa-download"></i> Télécharger PDF
                    </button>
                    <button class="btn btn-secondary" onclick="window.print()">
                        <i class="fas fa-print"></i> Imprimer
                    </button>
                </div>
            </div>
            
            <div class="card">
                <div class="card-body">
                    ' . $html . '
                </div>
            </div>
        </div>
        
        <script>
        function downloadPDF() {
            // Ouvrir dans une nouvelle fenêtre pour download
            window.open("colis_pdf.php?id=' . $colis_id . '&download=1", "_blank");
            showNotification("Le bordereau va être téléchargé...", "info");
        }
        </script>
    </body>
    </html>';
    
    header('Content-Type: text/html; charset=UTF-8');
    echo $fullHtml;
}
