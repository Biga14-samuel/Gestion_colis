<?php
/**
 * Module POD (Proof of Delivery) - Preuve de Livraison pour Agents
 * Permet aux agents de livrer des colis avec photo et signature
 */

require_once __DIR__ . '/utils/session.php';
SessionManager::start();
require_once 'config/database.php';
require_once __DIR__ . '/utils/notification_service.php';

$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['user_role'] ?? 'client';
$message = '';
$messageType = '';
$podOtpTtlSeconds = 10 * 60;
$podOtpMaxAttempts = 5;
$podOtpResendCooldown = 30;

// Vérifier la connexion et le rôle
if (!$user_id) {
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="access-denied">Accès refusé. Veuillez vous connecter.</div>';
    exit;
}
if (!in_array($user_role, ['agent', 'admin'], true)) {
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="access-denied">Accès réservé aux agents.</div>';
    exit;
}

// Récupérer les informations de l'agent
$stmt = $db->prepare("SELECT * FROM agents WHERE utilisateur_id = ?");
$stmt->execute([$user_id]);
$agent = $stmt->fetch();

if (!$agent) {
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="access-denied">Accès refusé. Profil agent introuvable.</div>';
    exit;
}

// Récupérer les livraisons assignées à l'agent via la table livraisons
$stmt = $db->prepare("
    SELECT c.*, 
           l.id as livraison_id, l.statut as livraison_statut,
           u.prenom as expediteur_prenom, u.nom as expediteur_nom,
           u.telephone as expediteur_tel,
           d.prenom as destinataire_prenom, d.nom as destinataire_nom,
           d.adresse as destinataire_adresse, d.telephone as destinataire_tel,
           a.zone_livraison
    FROM livraisons l
    JOIN colis c ON l.colis_id = c.id
    JOIN agents a ON l.agent_id = a.id
    LEFT JOIN utilisateurs u ON c.utilisateur_id = u.id
    LEFT JOIN utilisateurs d ON c.destinataire_id = d.id
    WHERE l.agent_id = ? 
    AND l.statut IN ('assignee', 'en_cours')
    ORDER BY l.date_assignation DESC
");
$stmt->execute([$agent['id'] ?? 0]);
$livraisons = $stmt->fetchAll();

// Récupérer les livraisons complétées aujourd'hui via la table livraisons
$stmt = $db->prepare("
    SELECT COUNT(*) as count 
    FROM livraisons l
    JOIN colis c ON l.colis_id = c.id
    WHERE l.agent_id = ? 
    AND l.statut = 'livree'
    AND DATE(l.date_livraison) = CURDATE()
");
$stmt->execute([$agent['id'] ?? 0]);
$todayDeliveries = $stmt->fetch();

function agent_has_colis(PDO $db, int $agentId, int $colisId, array $allowedStatuses = []): bool {
    $sql = "
        SELECT 1
        FROM livraisons
        WHERE colis_id = ? AND agent_id = ?
    ";
    $params = [$colisId, $agentId];
    if (!empty($allowedStatuses)) {
        $placeholders = implode(',', array_fill(0, count($allowedStatuses), '?'));
        $sql .= " AND statut IN ($placeholders)";
        $params = array_merge($params, $allowedStatuses);
    }
    $sql .= " LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

function generate_pod_otp(): string {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function fetch_pod_contact(PDO $db, int $colisId): ?array {
    $stmt = $db->prepare("
        SELECT c.id, c.code_tracking, c.telephone_destinataire, c.nom_destinataire,
               d.telephone AS destinataire_tel, d.prenom AS destinataire_prenom, d.nom AS destinataire_nom
        FROM colis c
        LEFT JOIN utilisateurs d ON c.destinataire_id = d.id
        WHERE c.id = ?
    ");
    $stmt->execute([$colisId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function create_in_app_notification(PDO $db, int $userId, string $type, string $title, string $message, string $priority = 'normal'): void {
    $stmt = $db->prepare("
        INSERT INTO notifications (utilisateur_id, type, titre, message, priorite, date_envoi)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$userId, $type, $title, $message, $priority]);
}

// Traitement de la livraison
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'send_pod_otp') {
        $colis_id = (int) ($_POST['colis_id'] ?? 0);
        if ($colis_id <= 0 || !agent_has_colis($db, (int) $agent['id'], $colis_id, ['assignee', 'en_cours'])) {
            echo json_encode(['success' => false, 'message' => 'Colis non assigné.']);
            exit;
        }

        $existing = $_SESSION['pod_otp'][$colis_id] ?? null;
        $lastSentAt = isset($existing['sent_at']) ? (int) $existing['sent_at'] : 0;
        if ($lastSentAt > 0 && (time() - $lastSentAt) < $podOtpResendCooldown) {
            $waitSeconds = $podOtpResendCooldown - (time() - $lastSentAt);
            echo json_encode([
                'success' => false,
                'message' => 'Veuillez patienter avant de renvoyer le code.',
                'retry_after' => $waitSeconds
            ]);
            exit;
        }

        $colis = fetch_pod_contact($db, $colis_id);
        if (!$colis) {
            echo json_encode(['success' => false, 'message' => 'Colis introuvable.']);
            exit;
        }

        $otp = generate_pod_otp();
        $otpHash = password_hash($otp, PASSWORD_DEFAULT);
        $_SESSION['pod_otp'][$colis_id] = [
            'hash' => $otpHash,
            'expires_at' => time() + $podOtpTtlSeconds,
            'attempts' => 0,
            'sent_at' => time()
        ];
        unset($_SESSION['otp_verified_for_colis'][$colis_id]);

        $phone = $colis['destinataire_tel'] ?: $colis['telephone_destinataire'];
        $trackingCode = $colis['code_tracking'] ?? ('#' . $colis_id);
        $recipientName = trim(($colis['destinataire_prenom'] ?? '') . ' ' . ($colis['destinataire_nom'] ?? ''));
        if ($recipientName === '') {
            $recipientName = $colis['nom_destinataire'] ?? 'Destinataire';
        }

        $messageText = "Votre code OTP de livraison pour le colis {$trackingCode} est: {$otp}. Il expire dans 10 minutes.";
        $smsSent = false;

        if (!empty($phone)) {
            try {
                $notificationService = new NotificationService();
                $smsResult = $notificationService->sendSMS($phone, $messageText);
                $smsSent = !empty($smsResult['success']);
            } catch (Exception $e) {
                $smsSent = false;
            }
        }

        if (!$smsSent) {
            create_in_app_notification(
                $db,
                (int) $user_id,
                'livraison',
                'Code OTP de livraison',
                "Code OTP pour {$trackingCode} ({$recipientName}): {$otp}",
                'high'
            );
        }

        echo json_encode([
            'success' => true,
            'message' => $smsSent ? 'Code OTP envoyé au destinataire.' : 'Code OTP généré (notification interne).',
            'fallback' => !$smsSent,
            'expires_in' => $podOtpTtlSeconds
        ]);
        exit;
    }

    if ($action === 'verify_pod_otp') {
        $colis_id = (int) ($_POST['colis_id'] ?? 0);
        $otp = trim($_POST['otp'] ?? '');

        if ($colis_id <= 0 || !agent_has_colis($db, (int) $agent['id'], $colis_id, ['assignee', 'en_cours'])) {
            echo json_encode(['success' => false, 'message' => 'Colis non assigné.']);
            exit;
        }

        if (!preg_match('/^[0-9]{6}$/', $otp)) {
            echo json_encode(['success' => false, 'message' => 'Code OTP invalide.']);
            exit;
        }

        $record = $_SESSION['pod_otp'][$colis_id] ?? null;
        if (!$record || empty($record['hash']) || empty($record['expires_at'])) {
            echo json_encode(['success' => false, 'message' => 'Aucun code OTP actif.']);
            exit;
        }

        $expiresAt = (int) $record['expires_at'];
        if (time() > $expiresAt) {
            unset($_SESSION['pod_otp'][$colis_id]);
            echo json_encode(['success' => false, 'message' => 'Code OTP expiré.']);
            exit;
        }

        $attempts = (int) ($record['attempts'] ?? 0);
        if ($attempts >= $podOtpMaxAttempts) {
            unset($_SESSION['pod_otp'][$colis_id]);
            echo json_encode(['success' => false, 'message' => 'Trop de tentatives.']);
            exit;
        }

        if (!password_verify($otp, (string) $record['hash'])) {
            $attempts++;
            $_SESSION['pod_otp'][$colis_id]['attempts'] = $attempts;
            $remaining = max(0, $podOtpMaxAttempts - $attempts);
            echo json_encode([
                'success' => false,
                'message' => 'Code OTP incorrect.',
                'attempts_remaining' => $remaining
            ]);
            exit;
        }

        unset($_SESSION['pod_otp'][$colis_id]);
        $_SESSION['otp_verified_for_colis'][$colis_id] = time() + $podOtpTtlSeconds;

        echo json_encode([
            'success' => true,
            'message' => 'Code OTP validé.',
            'verified_until' => $_SESSION['otp_verified_for_colis'][$colis_id]
        ]);
        exit;
    }
    
    if ($action === 'complete_delivery') {
        $colis_id = (int) ($_POST['colis_id'] ?? 0);
        $signature_data = $_POST['signature_data'] ?? '';
        $recipient_name = $_POST['recipient_name'] ?? '';
        $notes = $_POST['notes'] ?? '';
        $latitude = $_POST['latitude'] ?? null;
        $longitude = $_POST['longitude'] ?? null;

        if ($colis_id <= 0 || !agent_has_colis($db, (int) $agent['id'], $colis_id, ['assignee', 'en_cours'])) {
            $message = 'Ce colis ne vous est pas assigné.';
            $messageType = 'error';
        } else {
            $otpVerifiedUntil = (int) ($_SESSION['otp_verified_for_colis'][$colis_id] ?? 0);
            if ($otpVerifiedUntil <= time()) {
                if ($otpVerifiedUntil > 0) {
                    unset($_SESSION['otp_verified_for_colis'][$colis_id]);
                }
                $message = 'Validation OTP requise pour finaliser la livraison.';
                $messageType = 'error';
            }
        }

        if ($messageType !== 'error') {
        // Gérer l'upload de la photo
        $proof_photo_path = null;
        $photoError = null;
        if (!empty($_POST['proof_photo'])) {
            // PhotoBase64
            $photoData = trim($_POST['proof_photo']);
            $uploadDir = 'uploads/photos/';

            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            if (!preg_match('#^data:image/(png|jpeg);base64,#', $photoData)) {
                $photoError = 'Photo de preuve invalide.';
            } else {
                $base64 = substr($photoData, strpos($photoData, ',') + 1);
                $binary = base64_decode($base64, true);

                if ($binary === false) {
                    $photoError = 'Photo de preuve invalide.';
                } else {
                    $maxBytes = 5 * 1024 * 1024;
                    if (strlen($binary) > $maxBytes) {
                        $photoError = 'Photo trop volumineuse (max 5 Mo).';
                    }
                }

                if (!$photoError) {
                    $imageInfo = @getimagesizefromstring($binary);
                    $mime = $imageInfo['mime'] ?? '';
                    $realMime = '';
                    if (class_exists('finfo')) {
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $realMime = $finfo->buffer($binary) ?: '';
                    }
                    $allowedMimes = ['image/jpeg', 'image/png'];

                    if (!$imageInfo || !in_array($mime, $allowedMimes, true)) {
                        $photoError = 'Photo de preuve invalide.';
                    } elseif ($realMime !== '' && !in_array($realMime, $allowedMimes, true)) {
                        $photoError = 'Photo de preuve invalide.';
                    } else {
                        $extension = $mime === 'image/png' ? 'png' : 'jpg';
                        $filename = 'proof_' . $colis_id . '_' . time() . '.' . $extension;
                        $filepath = $uploadDir . $filename;
                        file_put_contents($filepath, $binary);
                        $proof_photo_path = $filepath;
                    }
                }
            }
        }

        if ($photoError) {
            $message = $photoError;
            $messageType = 'error';
        } else {
            try {
            $db->beginTransaction();
            
            // Déterminer le niveau de signature
            $signature_level = 'advanced';
            $otpVerifiedUntil = (int) ($_SESSION['otp_verified_for_colis'][$colis_id] ?? 0);
            if ($otpVerifiedUntil > time()) {
                unset($_SESSION['otp_verified_for_colis'][$colis_id]);
                if (empty($_SESSION['otp_verified_for_colis'])) {
                    unset($_SESSION['otp_verified_for_colis']);
                }
            } else {
                if ($otpVerifiedUntil > 0) {
                    unset($_SESSION['otp_verified_for_colis'][$colis_id]);
                }
                throw new RuntimeException('Validation OTP requise pour finaliser la livraison.');
            }
            
            // Mettre à jour le colis
            $stmt = $db->prepare("
                UPDATE colis c
                JOIN livraisons l ON l.colis_id = c.id AND l.agent_id = ?
                SET
                    statut = 'livre',
                    signature_data = ?,
                    signature_level = ?,
                    signature_timestamp = NOW(),
                    proof_photo_path = ?,
                    recipient_name = ?,
                    delivery_notes = ?,
                    date_livraison = NOW(),
                    delivered_at = NOW()
                WHERE c.id = ?
            ");
            $stmt->execute([
                (int) $agent['id'],
                $signature_data,
                $signature_level,
                $proof_photo_path,
                $recipient_name,
                $notes,
                $colis_id
            ]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('Colis non assigné à cet agent.');
            }
            
            // Mettre à jour la table livraisons
            $stmt = $db->prepare("
                UPDATE livraisons SET
                    statut = 'livree',
                    date_livraison = NOW(),
                    date_fin = NOW(),
                    photo_preuve = ?,
                    notes = ?
                WHERE colis_id = ? AND agent_id = ?
            ");
            $stmt->execute([$proof_photo_path, $notes, $colis_id, $agent['id']]);
            
            // Enregistrer dans l'historique
            $stmt = $db->prepare("
                INSERT INTO historique_colis (colis_id, ancien_statut, nouveau_statut, commentaire, utilisateur_id)
                VALUES (?, 'en_livraison', 'livre', ?, ?)
            ");
            $stmt->execute([$colis_id, "Livraison effectuée par $recipient_name", $user_id]);
            
            // Mettre à jour les statistiques de l'agent
            if ($agent) {
                $stmt = $db->prepare("
                    UPDATE agents SET 
                        total_livraisons = total_livraisons + 1,
                        total_earnings = total_earnings + commission
                    WHERE id = ?
                ");
                $stmt->execute([$agent['id']]);
            }
            
            // Créer une notification pour le destinataire
            $stmt = $db->prepare("SELECT destinataire_id, code_tracking FROM colis WHERE id = ?");
            $stmt->execute([$colis_id]);
            $colisInfo = $stmt->fetch();
            
            if ($colisInfo && $colisInfo['destinataire_id']) {
                $trackingCode = $colisInfo['code_tracking'] ?? ('#' . $colis_id);
                create_in_app_notification(
                    $db,
                    (int) $colisInfo['destinataire_id'],
                    'livraison',
                    'Colis livré',
                    "Votre colis {$trackingCode} a été livré avec succès !",
                    'normal'
                );
            }
            
            $db->commit();
            
            create_in_app_notification(
                $db,
                (int) $user_id,
                'livraison',
                'Livraison effectuée',
                'La livraison du colis #' . $colis_id . ' a été enregistrée.',
                'normal'
            );
            
            $message = 'Livraison effectuée avec succès !';
            $messageType = 'success';
            
            // Recharger les livraisons
            header('Location: agent_pod.php?success=1');
            exit;

            } catch (Exception $e) {
                $db->rollBack();
                $message = user_error_message($e, 'agent_pod.complete_delivery', 'Erreur lors de la livraison. Veuillez réessayer.');
                $messageType = 'error';
            }
        }
        }
    }
    
    if ($action === 'update_location') {
        $latitude = $_POST['latitude'] ?? '';
        $longitude = $_POST['longitude'] ?? '';
        
        if (!empty($latitude) && !empty($longitude) && $agent) {
            $stmt = $db->prepare("
                UPDATE agents SET 
                    latitude = ?, longitude = ?, last_location_update = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$latitude, $longitude, $agent['id']]);
            
            echo json_encode(['success' => true]);
            exit;
        }
    }
    
    if ($action === 'start_delivery') {
        $colis_id = (int) ($_POST['colis_id'] ?? 0);
        
        // Mettre à jour le statut dans livraisons et colis
        $stmt = $db->prepare("UPDATE livraisons SET statut = 'en_cours' WHERE colis_id = ? AND agent_id = ? AND statut = 'assignee'");
        $stmt->execute([$colis_id, $agent['id'] ?? 0]);

        if ($stmt->rowCount() === 0) {
            echo json_encode(['success' => false, 'message' => 'Colis non assigné ou déjà en cours.']);
            exit;
        }
        
        $stmt = $db->prepare("
            UPDATE colis c
            JOIN livraisons l ON l.colis_id = c.id AND l.agent_id = ?
            SET statut = 'en_livraison'
            WHERE c.id = ?
        ");
        $stmt->execute([$agent['id'] ?? 0, $colis_id]);
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($action === 'fail_delivery') {
        $colis_id = (int) ($_POST['colis_id'] ?? 0);
        $reason = $_POST['reason'] ?? '';
        
        try {
            // Mettre à jour le statut dans livraisons
            $stmt = $db->prepare("
                UPDATE livraisons SET 
                    statut = 'echec',
                    notes = ?
                WHERE colis_id = ? AND agent_id = ?
            ");
            $stmt->execute([$reason, $colis_id, $agent['id'] ?? 0]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('Colis non assigné.');
            }
            
            // Mettre à jour le colis
            $stmt = $db->prepare("
                UPDATE colis c
                JOIN livraisons l ON l.colis_id = c.id AND l.agent_id = ?
                SET
                    statut = 'retour',
                    delivery_notes = ?
                WHERE c.id = ?
            ");
            $stmt->execute([$agent['id'] ?? 0, $reason, $colis_id]);
            
            $message = 'Livraison marquée comme échouée. Le colis sera retourné.';
            $messageType = 'warning';
            
        } catch (Exception $e) {
            $message = user_error_message($e, 'agent_pod.fail_delivery', 'Erreur lors de la mise à jour de la livraison.');
            $messageType = 'error';
        }
    }
}

$statutLabels = [
    'en_attente' => 'En attente',
    'preparation' => 'En préparation',
    'en_livraison' => 'En livraison',
    'livre' => 'Livré',
    'annule' => 'Annulé',
    'retour' => 'En retour'
];

$statutColors = [
    'en_attente' => 'secondary',
    'preparation' => 'info',
    'en_livraison' => 'warning',
    'livre' => 'success',
    'annule' => 'danger',
    'retour' => 'secondary'
];
?>

<div id="page-content">

<div class="page-container">
    <div class="page-header">
        <div class="header-left">
            <button class="btn btn-back" onclick="loadPage('dashboard.php', 'Dashboard')">
                <i class="fas fa-arrow-left"></i>
            </button>
            <h1>
                <i class="fas fa-truck-loading" style="color: #00B4D8;"></i> 
                Preuve de Livraison
            </h1>
        </div>
        <?php if ($agent): ?>
            <div class="agent-stats">
                <span class="stat-item">
                    <i class="fas fa-map-marker-alt"></i>
                    Zone: <?= htmlspecialchars($agent['zone_livraison'] ?? 'Non définie') ?>
                </span>
                <span class="stat-item">
                    <i class="fas fa-check-circle"></i>
                    Aujourd'hui: <?= $todayDeliveries['count'] ?? 0 ?> livraisons
                </span>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?>">
            <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            Livraison enregistrée avec succès !
        </div>
    <?php endif; ?>

    <!-- Liste des livraisons -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-list"></i> Livraisons à effectuer</h3>
            <span class="badge badge-warning"><?= count($livraisons) ?></span>
        </div>
        <div class="card-body">
            <?php if (empty($livraisons)): ?>
                <div class="empty-state">
                    <i class="fas fa-truck fa-3x"></i>
                    <h3>Aucune livraison</h3>
                    <p>Vous n'avez pas de livraisons assignées.</p>
                </div>
            <?php else: ?>
                <div class="deliveries-grid">
                    <?php foreach ($livraisons as $livraison): ?>
                        <div class="delivery-card" id="delivery-<?= $livraison['id'] ?>">
                            <div class="delivery-header">
                                <span class="tracking-code"><?= htmlspecialchars($livraison['code_tracking'] ?? 'N/A') ?></span>
                                <span class="badge badge-<?= $statutColors[$livraison['statut']] ?>">
                                    <?= $statutLabels[$livraison['statut']] ?>
                                </span>
                            </div>
                            
                            <div class="delivery-info">
                                <div class="info-row">
                                    <i class="fas fa-user"></i>
                                    <span>Destinataire: <?= htmlspecialchars($livraison['destinataire_prenom'] . ' ' . $livraison['destinataire_nom']) ?></span>
                                </div>
                                <div class="info-row">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span><?= htmlspecialchars($livraison['destinataire_adresse'] ?? 'Adresse non spécifiée') ?></span>
                                </div>
                                <div class="info-row">
                                    <i class="fas fa-phone"></i>
                                    <span><?= htmlspecialchars($livraison['destinataire_tel'] ?? 'N/A') ?></span>
                                </div>
                            </div>
                            
                            <div class="delivery-description">
                                <i class="fas fa-box"></i>
                                <span><?= htmlspecialchars($livraison['description'] ?? 'Colis') ?></span>
                                <?php if ($livraison['fragile']): ?>
                                    <span class="badge badge-danger">Fragile</span>
                                <?php endif; ?>
                                <?php if ($livraison['urgent']): ?>
                                    <span class="badge badge-warning">Urgent</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="delivery-actions">
                                <button class="btn btn-primary" onclick="openDeliveryModal(<?= $livraison['id'] ?>)">
                                    <i class="fas fa-check"></i> Livrer
                                </button>
                                <button class="btn btn-danger" onclick="failDelivery(<?= $livraison['id'] ?>)">
                                    <i class="fas fa-times"></i> Échec
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Instructions pour l'agent -->
    <div class="card mt-4">
        <div class="card-header">
            <h3><i class="fas fa-info-circle"></i> Instructions de livraison</h3>
        </div>
        <div class="card-body">
            <div class="instructions-grid">
                <div class="instruction-step">
                    <div class="step-number">1</div>
                    <div class="step-content">
                        <h4>Scanner le QR Code</h4>
                        <p>Demandez au destinataire de présenter son QR Code Postal ID ou entrez manuellement le code.</p>
                    </div>
                </div>
                <div class="instruction-step">
                    <div class="step-number">2</div>
                    <div class="step-content">
                        <h4>Prendre une photo</h4>
                        <p>Photographiez le colis livré ou la preuve de réception (facultatif mais recommandé).</p>
                    </div>
                </div>
                <div class="instruction-step">
                    <div class="step-number">3</div>
                    <div class="step-content">
                        <h4>Faire signer</h4>
                        <p>Le destinataire signe sur l'écran puis valide le code OTP envoyé.</p>
                    </div>
                </div>
                <div class="instruction-step">
                    <div class="step-number">4</div>
                    <div class="step-content">
                        <h4>Confirmer</h4>
                        <p>Validez la livraison. Le reçu est automatiquement envoyé par email.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Backdrop pour les modals -->
<div id="modalBackdrop" class="modal-backdrop" onclick="closeAllModals()"></div>

<!-- Modal de livraison -->
<div class="modal modal-lg" id="deliveryModal">
    <div class="modal-header">
        <h2><i class="fas fa-truck"></i> Finaliser la livraison</h2>
        <button class="modal-close" onclick="closeModal('deliveryModal')">&times;</button>
    </div>
    <div class="modal-body" id="deliveryModalContent">
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('deliveryModal')">
            <i class="fas fa-times"></i> Fermer
        </button>
    </div>
</div>

<script>
let csrfToken = <?php echo json_encode(csrf_token()); ?>;
function refreshCsrfToken(response) {
    const newToken = response.headers.get('X-CSRF-Token');
    if (newToken) {
        csrfToken = newToken;
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) meta.content = newToken;
        document.querySelectorAll('input[name="csrf_token"]').forEach(input => {
            input.value = newToken;
        });
    }
    return response;
}
// Variables globales
let currentDeliveryId = null;
let locationWatcher = null;
let podOtpVerified = false;

// Fonctions pour gérer les modals
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    const backdrop = document.getElementById('modalBackdrop');
    if (modal) {
        modal.classList.add('active');
        if (backdrop) backdrop.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    const backdrop = document.getElementById('modalBackdrop');
    if (modal) {
        modal.classList.remove('active');
        if (backdrop) backdrop.classList.remove('active');
        document.body.style.overflow = '';
    }
}

function closeAllModals() {
    document.querySelectorAll('.modal.active').forEach(modal => {
        modal.classList.remove('active');
    });
    const backdrop = document.getElementById('modalBackdrop');
    if (backdrop) backdrop.classList.remove('active');
    document.body.style.overflow = '';
}

// Fermer avec la touche Echap
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAllModals();
    }
});

// Demander la localisation
function requestLocation() {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            position => {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                
                // Envoyer la localisation au serveur
                fetch('agent_pod.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-Token': csrfToken
                    },
                    body: `action=update_location&latitude=${lat}&longitude=${lng}`
                }).then(refreshCsrfToken);
            },
            error => {
                console.warn('Erreur de géolocalisation:', error.message);
            },
            { enableHighAccuracy: true }
        );
    }
}

function openDeliveryModal(colisId) {
    currentDeliveryId = colisId;
    
    // Récupérer les détails de la livraison
    fetch(`delivery_details.php?colis_id=${colisId}`)
        .then(response => response.text())
        .then(html => {
            document.getElementById('deliveryModalContent').innerHTML = html;
            openModal('deliveryModal');
            
            // Initialiser la géolocalisation
            requestLocation();
            
            // Initialiser le canvas de signature
            initSignatureCanvas();

            // Réinitialiser l'OTP
            resetPodOtpUi();

        });
}

function resetPodOtpUi() {
    podOtpVerified = false;
    const status = document.getElementById('podOtpStatus');
    if (status) {
        status.textContent = '';
        status.className = 'otp-status';
    }
    const input = document.getElementById('podOtpCode');
    if (input) {
        input.value = '';
    }
}

function showPodOtpStatus(message, type) {
    const status = document.getElementById('podOtpStatus');
    if (!status) return;
    status.textContent = message;
    status.className = `otp-status ${type || ''}`.trim();
}

function sendPodOtp() {
    if (!currentDeliveryId) {
        showPodOtpStatus('Veuillez sélectionner une livraison.', 'error');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'send_pod_otp');
    formData.append('colis_id', currentDeliveryId);

    fetch('agent_pod.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-CSRF-Token': csrfToken }
    })
    .then(response => {
        refreshCsrfToken(response);
        return response.json();
    })
    .then(data => {
        if (data.success) {
            podOtpVerified = false;
            showPodOtpStatus(data.message || 'Code OTP envoyé.', 'success');
        } else {
            showPodOtpStatus(data.message || 'Erreur lors de l\'envoi.', 'error');
        }
    })
    .catch(() => {
        showPodOtpStatus('Erreur lors de l\'envoi.', 'error');
    });
}

function verifyPodOtp() {
    const input = document.getElementById('podOtpCode');
    const otp = input ? input.value.trim() : '';

    if (!currentDeliveryId) {
        showPodOtpStatus('Veuillez sélectionner une livraison.', 'error');
        return;
    }
    if (!/^[0-9]{6}$/.test(otp)) {
        showPodOtpStatus('Veuillez entrer un code OTP valide (6 chiffres).', 'error');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'verify_pod_otp');
    formData.append('colis_id', currentDeliveryId);
    formData.append('otp', otp);

    fetch('agent_pod.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-CSRF-Token': csrfToken }
    })
    .then(response => {
        refreshCsrfToken(response);
        return response.json();
    })
    .then(data => {
        if (data.success) {
            podOtpVerified = true;
            showPodOtpStatus(data.message || 'OTP validé.', 'success');
        } else {
            podOtpVerified = false;
            showPodOtpStatus(data.message || 'OTP invalide.', 'error');
        }
    })
    .catch(() => {
        podOtpVerified = false;
        showPodOtpStatus('Erreur lors de la vérification.', 'error');
    });
}

function failDelivery(colisId) {
    const reason = prompt('Raison de l\'échec de livraison:');
    if (reason) {
        const formData = new FormData();
        formData.append('action', 'fail_delivery');
        formData.append('colis_id', colisId);
        formData.append('reason', reason);
        
        fetch('agent_pod.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-CSRF-Token': csrfToken }
        })
        .then(response => {
            refreshCsrfToken(response);
            return response.text();
        })
        .then(html => {
            document.open();
            document.write(html);
            document.close();
        });
    }
}

function initSignatureCanvas() {
    const canvas = document.getElementById('podSignatureCanvas');
    if (!canvas) return;
    
    const ctx = canvas.getContext('2d');
    let isDrawing = false;
    let lastX = 0;
    let lastY = 0;
    
    ctx.strokeStyle = '#00B4D8';
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    
    function getPos(e) {
        const rect = canvas.getBoundingClientRect();
        if (e.touches) {
            return {
                x: e.touches[0].clientX - rect.left,
                y: e.touches[0].clientY - rect.top
            };
        }
        return {
            x: e.offsetX,
            y: e.offsetY
        };
    }
    
    function startDrawing(e) {
        isDrawing = true;
        const pos = getPos(e);
        lastX = pos.x;
        lastY = pos.y;
    }
    
    function draw(e) {
        if (!isDrawing) return;
        e.preventDefault();
        const pos = getPos(e);
        
        ctx.beginPath();
        ctx.moveTo(lastX, lastY);
        ctx.lineTo(pos.x, pos.y);
        ctx.stroke();
        
        lastX = pos.x;
        lastY = pos.y;
    }
    
    function stopDrawing() {
        isDrawing = false;
    }
    
    canvas.addEventListener('mousedown', startDrawing);
    canvas.addEventListener('mousemove', draw);
    canvas.addEventListener('mouseup', stopDrawing);
    canvas.addEventListener('mouseout', stopDrawing);
    
    canvas.addEventListener('touchstart', startDrawing);
    canvas.addEventListener('touchmove', draw);
    canvas.addEventListener('touchend', stopDrawing);
}

function clearPodSignature() {
    const canvas = document.getElementById('podSignatureCanvas');
    if (canvas) {
        const ctx = canvas.getContext('2d');
        ctx.clearRect(0, 0, canvas.width, canvas.height);
    }
}

function capturePhoto() {
    const video = document.getElementById('cameraVideo');
    const canvas = document.getElementById('photoCanvas');
    
    if (video && canvas) {
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        
        const ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0);
        
        // Afficher la capture
        const photoPreview = document.getElementById('photoPreview');
        if (photoPreview) {
            photoPreview.src = canvas.toDataURL('image/jpeg');
            photoPreview.style.display = 'block';
        }
        
        // Stocker les données
        const photoInput = document.getElementById('proofPhotoData');
        if (photoInput) {
            photoInput.value = canvas.toDataURL('image/jpeg');
        }
        
        // Arrêter la caméra
        if (video.srcObject) {
            video.srcObject.getTracks().forEach(track => track.stop());
        }
    }
}

function startCamera() {
    const video = document.getElementById('cameraVideo');
    if (video) {
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
            .then(stream => {
                video.srcObject = stream;
                video.play();
            })
            .catch(err => {
                console.error('Erreur caméra:', err);
                alert('Impossible d\'accéder à la caméra. Veuillez utiliser un appareil mobile.');
            });
    }
}

function completeDelivery() {
    const canvas = document.getElementById('podSignatureCanvas');
    if (!canvas) return;
    
    // Vérifier la signature
    const imageData = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height);
    const hasSignature = imageData.data.some((channel, index) => {
        return index % 4 === 3 && channel > 0;
    });
    
    const recipientName = document.getElementById('recipientName')?.value || '';
    
    if (!recipientName) {
        alert('Veuillez entrer le nom du destinataire.');
        return;
    }

    if (!podOtpVerified) {
        showPodOtpStatus('Veuillez valider le code OTP avant de confirmer.', 'error');
        return;
    }
    
    // Préparer les données
    const formData = new FormData();
    formData.append('action', 'complete_delivery');
    formData.append('colis_id', currentDeliveryId);
    formData.append('signature_data', canvas.toDataURL('image/png'));
    formData.append('recipient_name', recipientName);
    formData.append('notes', document.getElementById('deliveryNotes')?.value || '');
    formData.append('proof_photo', document.getElementById('proofPhotoData')?.value || '');
    
    fetch('agent_pod.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-CSRF-Token': csrfToken }
    })
    .then(response => {
        refreshCsrfToken(response);
        return response.text();
    })
    .then(html => {
        document.open();
        document.write(html);
        document.close();
    });
}

console.log('%c🚀 Gestion_Colis - POD Agent SPA', 'color: #00B4D8; font-size: 16px; font-weight: bold;');
</script>

<style>
.agent-stats {
    display: flex;
    gap: 1.5rem;
}

.stat-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: rgba(0, 180, 216, 0.1);
    border-radius: 20px;
    font-size: 0.9rem;
    color: var(--text-secondary);
}

.stat-item i {
    color: #00B4D8;
}

.deliveries-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1.5rem;
}

.delivery-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.25rem;
    transition: all 0.2s;
}

.delivery-card:hover {
    border-color: rgba(0, 180, 216, 0.3);
}

.delivery-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--border-color);
}

.tracking-code {
    font-family: var(--font-display);
    font-weight: 700;
    font-size: 1.1rem;
    color: #00B4D8;
}

.delivery-info {
    margin-bottom: 1rem;
}

.info-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.35rem 0;
    font-size: 0.9rem;
    color: var(--text-secondary);
}

.info-row i {
    width: 18px;
    color: #00B4D8;
}

.delivery-description {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem;
    background: rgba(0, 0, 0, 0.02);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    margin-bottom: 1rem;
    font-size: 0.9rem;
}

.delivery-description i {
    color: #00B4D8;
}

.delivery-actions {
    display: flex;
    gap: 0.5rem;
}

.delivery-actions .btn {
    flex: 1;
}

.instructions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1.5rem;
}

.instruction-step {
    display: flex;
    gap: 1rem;
    padding: 1rem;
    background: rgba(0, 0, 0, 0.02);
    border: 1px solid var(--border-color);
    border-radius: 12px;
}

.step-number {
    width: 40px;
    height: 40px;
    background: rgba(0, 180, 216, 0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    font-weight: 700;
    color: #00B4D8;
    flex-shrink: 0;
}

.step-content h4 {
    color: var(--text-primary);
    margin: 0 0 0.5rem 0;
    font-size: 1rem;
}

.step-content p {
    color: var(--text-secondary);
    font-size: 0.9rem;
    margin: 0;
}

/* Modal styles */
.modal-lg {
    max-width: 800px;
}

.pod-form-section {
    margin-bottom: 1.5rem;
}

.pod-form-section h4 {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 1rem;
    color: #00B4D8;
}

.camera-container {
    position: relative;
    background: var(--gray-100);
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 1rem;
    border: 1px solid var(--border-color);
}

#cameraVideo {
    width: 100%;
    display: block;
}

#photoCanvas {
    display: none;
}

#photoPreview {
    max-width: 100%;
    border-radius: 8px;
    margin-top: 1rem;
}

.pod-signature-container {
    border: 2px dashed rgba(0, 180, 216, 0.3);
    border-radius: 12px;
    background: rgba(0, 0, 0, 0.02);
    padding: 1rem;
    margin-bottom: 1rem;
}

#podSignatureCanvas {
    background: #fff;
    border-radius: 8px;
    cursor: crosshair;
    max-width: 100%;
}

@media (max-width: 768px) {
    .agent-stats {
        flex-direction: column;
        gap: 0.5rem;
    }
    
    .deliveries-grid {
        grid-template-columns: 1fr;
    }
    
    .delivery-actions {
        flex-direction: column;
    }
}
</style>

</div> <!-- Fin #page-content -->
