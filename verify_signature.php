<?php
/**
 * Signature Verifier - Vérificateur de Signatures Électroniques
 * Point d'entrée autonome pour la vérification des signatures
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
$publicView = $user_id === 0;

if ($user_id > 0 && $signature_id <= 0) {
    die('ID de signature requis.');
}
if ($publicView && $publicToken === '') {
    http_response_code(403);
    die('Accès refusé.');
}

// Récupérer la signature
if ($user_id) {
    // Utilisateur connecté - vérifier qu'il a le droit de voir cette signature
    $stmt = $db->prepare("SELECT * FROM signatures WHERE id = ? AND utilisateur_id = ?");
    $stmt->execute([$signature_id, $user_id]);
} else {
    // Visiteur - exiger un token opaque (hash) pour l'accès public
    if ($signature_id > 0) {
        $stmt = $db->prepare("
            SELECT id, utilisateur_id, signature_level, signature_data, signature_hash, created_at
            FROM signatures
            WHERE id = ? AND signature_hash = ?
        ");
        $stmt->execute([$signature_id, $publicToken]);
    } else {
        $stmt = $db->prepare("
            SELECT id, utilisateur_id, signature_level, signature_data, signature_hash, created_at
            FROM signatures
            WHERE signature_hash = ?
        ");
        $stmt->execute([$publicToken]);
    }
}

$signature = $stmt->fetch();

if (!$signature) {
    http_response_code(404);
    die('Signature non trouvée.');
}
$signature_id = (int) ($signature['id'] ?? $signature_id);

// Récupérer les informations de l'utilisateur si disponible
$user = null;
if ($user_id) {
    $stmt = $db->prepare("SELECT * FROM utilisateurs WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
}

$verificationResult = verifySignature($signature, $db, $publicView);

function verifySignature($signature, $db, bool $publicView = false) {
    $result = [
        'valid' => true,
        'message' => 'Signature vérifiée avec succès',
        'details' => []
    ];

    // Vérifier l'intégrité du hash
    $expectedHash = hash('sha256', $signature['signature_data'] . $signature['created_at'] . $signature['utilisateur_id']);
    
    if (!hash_equals((string) $signature['signature_hash'], (string) $expectedHash)) {
        $result['valid'] = false;
        $result['message'] = 'Attention: L\'intégrité de la signature n\'a pas pu être vérifiée.';
        $result['details'][] = 'Le hash de signature ne correspond pas aux données enregistrées.';
    }

    if (!$publicView) {
        // Vérifier le niveau de signature
        $result['details'][] = 'Niveau de signature: ' . ucfirst($signature['signature_level']);

        // Vérifier la date
        $signDate = strtotime($signature['created_at']);
        $now = time();
        $ageInDays = ($now - $signDate) / (24 * 60 * 60);
        
        if ($ageInDays < 1) {
            $result['details'][] = 'Signée il y a moins d\'un jour';
        } elseif ($ageInDays < 30) {
            $result['details'][] = 'Signée il y a ' . floor($ageInDays) . ' jour(s)';
        } else {
            $result['details'][] = 'Signée le ' . date('d/m/Y', $signDate);
        }

        // Vérifications spécifiques au niveau
        if ($signature['signature_level'] === 'qualified') {
            $result['details'][] = 'Type: Signature qualifiée avec vérification d\'identité';
            $result['details'][] = 'Valeur probante juridique confirmée';
        } elseif ($signature['signature_level'] === 'advanced') {
            $result['details'][] = 'Type: Signature avancée avec validation OTP';
            $result['details'][] = 'Identité du signataire partiellement vérifiée';
        } else {
            $result['details'][] = 'Type: Signature simple';
            $result['details'][] = 'Preuve de réception basique';
        }
    }

    return $result;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vérification de Signature - Gestion Colis</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #00B4D8;
            --success: #22C55E;
            --warning: #F59E0B;
            --danger: #EF4444;
            --bg-primary: #F8FAFC;
            --bg-card: #FFFFFF;
            --text-primary: #1E293B;
            --text-secondary: #64748B;
            --border-color: #E2E8F0;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }
        
        .verification-container {
            max-width: 700px;
            margin: 0 auto;
        }
        
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }
        
        .card-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .card-header h2 {
            margin: 0;
            font-size: 1.25rem;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        .verification-status {
            text-align: center;
            padding: 2rem;
        }
        
        .status-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2.5rem;
        }
        
        .status-icon.valid {
            background: rgba(34, 197, 94, 0.1);
            color: var(--success);
            border: 2px solid var(--success);
        }
        
        .status-icon.invalid {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border: 2px solid var(--danger);
        }
        
        .status-text {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .status-text.valid {
            color: var(--success);
        }
        
        .status-text.invalid {
            color: var(--danger);
        }
        
        .detail-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .detail-list li {
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .detail-list li:last-child {
            border-bottom: none;
        }
        
        .detail-list i {
            color: var(--primary);
            width: 20px;
        }
        
        .hash-display {
            background: rgba(0, 0, 0, 0.05);
            padding: 1rem;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
            word-break: break-all;
            color: var(--text-secondary);
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: all 0.3s ease;
            text-decoration: none;
            font-size: 0.9rem;
        }
        
        .btn-primary {
            background: var(--primary);
            color: #FFFFFF;
        }
        
        .btn-primary:hover {
            background: #0096B4;
        }
        
        .btn-secondary {
            background: var(--border-color);
            color: var(--text-primary);
        }
        
        .btn-secondary:hover {
            background: #CBD5E1;
        }
        
        .actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 1.5rem;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }
        
        .alert-info {
            background: rgba(0, 180, 216, 0.1);
            border: 1px solid rgba(0, 180, 216, 0.3);
            color: var(--primary);
        }
    </style>
</head>
<body>
    <div class="verification-container">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-check-circle" style="color: var(--primary);"></i>
                <h2>Vérification de Signature Électronique</h2>
            </div>
            <div class="card-body">
                <div class="verification-status">
                    <div class="status-icon <?= $verificationResult['valid'] ? 'valid' : 'invalid' ?>">
                        <i class="fas <?= $verificationResult['valid'] ? 'fa-check' : 'fa-exclamation-triangle' ?>"></i>
                    </div>
                    <div class="status-text <?= $verificationResult['valid'] ? 'valid' : 'invalid' ?>">
                        <?= htmlspecialchars($verificationResult['message']) ?>
                    </div>
                </div>
                
                <ul class="detail-list">
                    <?php foreach ($verificationResult['details'] as $detail): ?>
                    <li>
                        <i class="fas fa-info-circle"></i>
                        <?= htmlspecialchars($detail) ?>
                    </li>
                    <?php endforeach; ?>
                    <li>
                        <i class="fas fa-calendar"></i>
                        Signature datée du <?= date('d/m/Y à H:i:s', strtotime($signature['created_at'])) ?>
                    </li>
                    <?php if ($user_id && !empty($signature['document_name'])): ?>
                    <li>
                        <i class="fas fa-file"></i>
                        Document: <?= htmlspecialchars($signature['document_name']) ?>
                    </li>
                    <?php endif; ?>
                </ul>
                
                <div style="margin-top: 1.5rem;">
                    <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 0.5rem;">
                        <i class="fas fa-fingerprint"></i> Hash de signature:
                    </p>
                    <div class="hash-display">
                        <?= htmlspecialchars($signature['signature_hash']) ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            <div>
                <strong>À propos de cette vérification</strong><br>
                Ce certificat confirme l'intégrité et l'authenticité de la signature électronique. 
                Le hash unique permet de vérifier que les données n'ont pas été modifiées depuis la signature.
            </div>
        </div>
        
        <div class="actions">
            <?php if ($user_id): ?>
            <a href="signatures.php" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> Retour aux signatures
            </a>
            <?php else: ?>
            <a href="index.php" class="btn btn-primary">
                <i class="fas fa-home"></i> Accueil
            </a>
            <?php endif; ?>
            <?php if ($user_id): ?>
            <a href="certificate.php?token=<?= urlencode($signature['signature_hash']) ?>" class="btn btn-secondary" target="_blank">
                <i class="fas fa-file-pdf"></i> Télécharger le certificat
            </a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
