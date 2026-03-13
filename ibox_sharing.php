<?php
/**
 * Module iBox Sharing - Partage et Historique des Boîtes Virtuelles
 * Version corrigée avec support AJAX complet
 */

require_once __DIR__ . '/utils/session.php';
SessionManager::start();

// Vérifier la connexion AVANT d'inclure la base de données
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Accès refusé. Veuillez vous connecter.']);
        exit;
    }
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="access-denied">Accès refusé. Veuillez vous connecter.</div>';
    exit;
}

require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$message = '';
$messageType = '';

// Flag pour indiquer si c'est une requête AJAX
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Fonction pour envoyer une réponse JSON et terminer
function sendJsonResponse($data) {
    // Nettoyer tout output buffer
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    $response = ['success' => false, 'message' => '', 'data' => null];
    
    if ($action === 'share_ibox') {
        $ibox_id = $_POST['ibox_id'] ?? 0;
        $email = trim($_POST['email'] ?? '');
        $permission = $_POST['permission'] ?? 'view';
        $share_method = $_POST['share_method'] ?? 'email'; // Nouvelle option unifiée
        
        if (empty($email)) {
            $response['message'] = 'L\'adresse email est requise.';
        } elseif ($email === ($_SESSION['user_email'] ?? '')) {
            $response['message'] = 'Vous ne pouvez pas partager avec vous-même.';
        } else {
            try {
                // Vérifier que l'iBox appartient bien à l'utilisateur connecté
                $stmt = $db->prepare("SELECT id FROM ibox WHERE id = ? AND utilisateur_id = ?");
                $stmt->execute([$ibox_id, $user_id]);
                if (!$stmt->fetch()) {
                    $response['message'] = 'Accès refusé.';
                } else {
                    // Vérifier si l'utilisateur existe
                    $stmt = $db->prepare("SELECT id, email FROM utilisateurs WHERE email = ?");
                    $stmt->execute([$email]);
                    $recipient = $stmt->fetch();
                    
                    $shared_with_user_id = $recipient ? $recipient['id'] : null;
                    
                    // Vérifier si le partage existe déjà
                    $stmt = $db->prepare("
                        SELECT id FROM ibox_shares 
                        WHERE ibox_id = ? AND (shared_with_email = ? OR shared_with_user_id = ?)
                        AND is_active = 1
                    ");
                    $stmt->execute([$ibox_id, $email, $shared_with_user_id]);
                    if ($stmt->fetch()) {
                        $response['message'] = 'Cette boîte est déjà partagée avec cet utilisateur.';
                    } else {
                        // Créer le partage
                        $stmt = $db->prepare("
                            INSERT INTO ibox_shares (ibox_id, owner_id, shared_with_user_id, shared_with_email, permission_level, share_method)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$ibox_id, $user_id, $shared_with_user_id, $email, $permission, $share_method]);
                        
                        // Créer une notification pour le destinataire
                        if ($shared_with_user_id) {
                            $stmt = $db->prepare("SELECT code_box FROM ibox WHERE id = ?");
                            $stmt->execute([$ibox_id]);
                            $ibox = $stmt->fetch();
                            
                            $stmt = $db->prepare("
                                INSERT INTO notifications (utilisateur_id, type, titre, message, priorite, date_envoi)
                                VALUES (?, 'system', 'Partage iBox', ?, 'normal', NOW())
                            ");
                            $stmt->execute([$shared_with_user_id, 
                                'Une iBox (' . ($ibox['code_box'] ?? '') . ') vous a été partagée.']);
                        }
                        
                        // Logger l'action
                        logIboxAction($db, $ibox_id, $user_id, 'SHARE', "Partage avec $email via $share_method");
                        
                        $response['success'] = true;
                        $response['message'] = 'Boîte partagée avec succès !';
                    }
                }
            } catch (Exception $e) {
                $response['message'] = user_error_message($e, 'ibox_sharing.share', 'Erreur lors du partage de la iBox.');
            }
        }
    }
    
    if ($action === 'revoke_share') {
        $share_id = $_POST['share_id'] ?? 0;
        
        // Vérifier que l'utilisateur est bien le propriétaire de l'iBox
        $stmt = $db->prepare("
            SELECT s.ibox_id FROM ibox_shares s
            JOIN ibox ON s.ibox_id = ibox.id
            WHERE s.id = ? AND ibox.utilisateur_id = ?
        ");
        $stmt->execute([$share_id, $user_id]);
        $result = $stmt->fetch();
        
        if ($result) {
            $stmt = $db->prepare("UPDATE ibox_shares SET is_active = 0 WHERE id = ?");
            $stmt->execute([$share_id]);
            
            // Logger l'action
            logIboxAction($db, $result['ibox_id'], $user_id, 'REVOKE_SHARE', 'Partage révoqué');
            
            $response['success'] = true;
            $response['message'] = 'Partage révoqué avec succès.';
        } else {
            $response['message'] = 'Partage non trouvé ou vous n\'avez pas l\'autorisation.';
        }
    }
    
    if ($action === 'log_action') {
        $ibox_id = $_POST['ibox_id'] ?? 0;
        $action_type = $_POST['action_type'] ?? '';
        $details = trim($_POST['details'] ?? '');
        $detailsLen = function_exists('mb_strlen') ? mb_strlen($details) : strlen($details);

        if ($detailsLen > 500) {
            $response['message'] = 'Les détails ne doivent pas dépasser 500 caractères.';
        } else {
            logIboxAction($db, $ibox_id, $user_id, $action_type, $details);

            $response['success'] = true;
            $response['message'] = 'Action enregistrée';
        }
    }
    
    // Renvoyer la réponse JSON pour les requêtes AJAX
    if ($isAjax) {
        sendJsonResponse($response);
    } else {
        // Pour les requêtes non-AJAX, stocker le message et continuer
        $message = $response['message'];
        $messageType = $response['success'] ? 'success' : 'error';
    }
}

function logIboxAction($db, $iboxId, $userId, $actionType, $details) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $stmt = $db->prepare("
        INSERT INTO ibox_history (ibox_id, user_id, action_type, action_details, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$iboxId, $userId, $actionType, $details, $ip, $userAgent]);
}

// Récupérer l'historique d'une iBox
function getIboxHistory($db, $iboxId, $limit = 50) {
    $stmt = $db->prepare("
        SELECT h.*, u.prenom, u.nom
        FROM ibox_history h
        LEFT JOIN utilisateurs u ON h.user_id = u.id
        WHERE h.ibox_id = ?
        ORDER BY h.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$iboxId, $limit]);
    return $stmt->fetchAll();
}

$actionLabels = [
    'OPEN' => ['label' => 'Ouverture', 'icon' => 'fa-door-open', 'color' => 'success'],
    'CLOSE' => ['label' => 'Fermeture', 'icon' => 'fa-door-closed', 'color' => 'warning'],
    'DEPOSIT' => ['label' => 'Dépôt', 'icon' => 'fa-box-in', 'color' => 'primary'],
    'RETRIEVE' => ['label' => 'Retrait', 'icon' => 'fa-box-out', 'color' => 'info'],
    'SHARE' => ['label' => 'Partage', 'icon' => 'fa-share-alt', 'color' => 'secondary'],
    'REVOKE_SHARE' => ['label' => 'Révocation', 'icon' => 'fa-user-times', 'color' => 'danger'],
    'CREATE' => ['label' => 'Création', 'icon' => 'fa-plus-circle', 'color' => 'success'],
    'UPDATE' => ['label' => 'Modification', 'icon' => 'fa-edit', 'color' => 'info'],
    'ACCESS' => ['label' => 'Accès', 'icon' => 'fa-key', 'color' => 'secondary']
];

// Récupérer les iBox de l'utilisateur (propriétaire)
$stmt = $db->prepare("
    SELECT ibox.*, u.prenom, u.nom, u.email
    FROM ibox 
    LEFT JOIN utilisateurs u ON ibox.utilisateur_id = u.id
    WHERE ibox.utilisateur_id = ?
    ORDER BY ibox.date_creation DESC
");
$stmt->execute([$user_id]);
$my_ibox = $stmt->fetchAll();

// Récupérer les iBox partagées avec l'utilisateur
$stmt = $db->prepare("
    SELECT ibox.*, s.permission_level, s.shared_with_email, s.is_active,
           u.prenom as owner_prenom, u.nom as owner_nom, u.email as owner_email
    FROM ibox_shares s
    JOIN ibox ON s.ibox_id = ibox.id
    JOIN utilisateurs u ON ibox.utilisateur_id = u.id
    WHERE (s.shared_with_user_id = ? OR s.shared_with_email = (
        SELECT email FROM utilisateurs WHERE id = ?
    ))
    AND s.is_active = 1
    ORDER BY s.created_at DESC
");
$stmt->execute([$user_id, $user_id]);
$shared_ibox = $stmt->fetchAll();

// Récupérer les partages actifs pour les iBox de l'utilisateur
$stmt = $db->prepare("
    SELECT s.*, u.prenom, u.nom, u.email, ibox.code_box
    FROM ibox_shares s
    LEFT JOIN utilisateurs u ON s.shared_with_user_id = u.id
    JOIN ibox ON s.ibox_id = ibox.id
    WHERE ibox.utilisateur_id = ? AND s.is_active = 1
    ORDER BY s.created_at DESC
");
$stmt->execute([$user_id]);
$active_shares = $stmt->fetchAll();
?>

<div id="page-content">

<div class="page-container">
    <div class="page-header">
        <div class="header-left">
            <button class="btn btn-back" onclick="loadPage('mes_ibox.php', 'Mes iBox')">
                <i class="fas fa-arrow-left"></i>
            </button>
            <h1>
                <i class="fas fa-users" style="color: #00B4D8;"></i> 
                Partage & Historique iBox
            </h1>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?>">
            <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Mes iBox partagées -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-share-alt"></i> Mes Partages</h3>
        </div>
        <div class="card-body">
            <?php if (empty($my_ibox)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox fa-3x"></i>
                    <h3>Aucune iBox</h3>
                    <p>Créez d'abord une iBox pour pouvoir la partager.</p>
                    <button class="btn btn-primary" onclick="loadPage('mes_ibox.php', 'Mes iBox')">
                        <i class="fas fa-plus"></i> Créer une iBox
                    </button>
                </div>
            <?php else: ?>
                <div class="share-tabs">
                    <button class="tab-btn active" data-tab="my-shares">Mes Partages</button>
                    <button class="tab-btn" data-tab="shared-with-me">Partagées avec moi</button>
                </div>

                <!-- Mes partages -->
                <div class="tab-content active" id="my-shares">
                    <?php if (empty($active_shares)): ?>
                        <div class="empty-state small">
                            <i class="fas fa-users-slash fa-2x"></i>
                            <p>Aucun partage actif</p>
                            <p class="text-muted">Partagez vos iBox avec votre famille ou vos collègues.</p>
                        </div>
                    <?php else: ?>
                        <div class="shares-list">
                            <?php foreach ($active_shares as $share): ?>
                                <div class="share-item">
                                    <div class="share-info">
                                        <div class="share-ibox">
                                            <span class="code"><?= htmlspecialchars($share['code_box'] ?? 'N/A') ?></span>
                                            <span class="badge badge-<?= 
                                                $share['permission_level'] === 'manage' ? 'warning' : 
                                                ($share['permission_level'] === 'open' ? 'info' : 'secondary') 
                                            ?>">
                                                <?= ucfirst($share['permission_level']) ?>
                                            </span>
                                        </div>
                                        <div class="share-recipient">
                                            <i class="fas fa-user"></i>
                                            <?php if ($share['prenom'] || $share['nom']): ?>
                                                <?= htmlspecialchars(($share['prenom'] ?? '') . ' ' . ($share['nom'] ?? '') . ' (' . ($share['email'] ?? '') . ')' ) ?>
                                            <?php else: ?>
                                                <?= htmlspecialchars($share['shared_with_email'] ?? 'Email non spécifié') ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="share-date">
                                            <i class="fas fa-calendar"></i>
                                            <?= isset($share['created_at']) ? date('d/m/Y H:i', strtotime($share['created_at'])) : 'Date non disponible' ?>
                                        </div>
                                    </div>
                                    <div class="share-actions">
                                        <button class="btn btn-sm btn-secondary" 
                                                onclick="showHistory(<?= $share['ibox_id'] ?>)"
                                                title="Historique">
                                            <i class="fas fa-history"></i>
                                        </button>
                                        <form method="POST" style="display: inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="revoke_share">
                                            <input type="hidden" name="share_id" value="<?= $share['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" 
                                                    onclick="return confirm('Révoquer ce partage ?')"
                                                    title="Révoquer">
                                                <i class="fas fa-user-times"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Formulaire de partage unifié -->
                    <div class="share-form mt-4">
                        <h4><i class="fas fa-plus-circle"></i> Nouveau partage</h4>
                        <form method="POST" id="shareIboxForm">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="share_ibox">
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="ibox_id">
                                        <i class="fas fa-inbox"></i> iBox à partager
                                    </label>
                                    <select id="ibox_id" name="ibox_id" class="form-control" required>
                                        <option value="">Sélectionnez une iBox...</option>
                                        <?php foreach ($my_ibox as $ibox): ?>
                                            <option value="<?= $ibox['id'] ?>">
                                                <?= htmlspecialchars($ibox['code_box']) ?> - <?= htmlspecialchars($ibox['localisation']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="email">
                                        <i class="fas fa-envelope"></i> Email du destinataire
                                    </label>
                                    <input type="email" id="email" name="email" class="form-control" 
                                           placeholder="email@exemple.com" required>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="share_method">
                                        <i class="fas fa-share-alt"></i> Méthode de partage
                                    </label>
                                    <select id="share_method" name="share_method" class="form-control">
                                        <option value="email">Email</option>
                                        <option value="link">Lien de partage</option>
                                        <option value="direct">Partage direct</option>
                                    </select>
                                    <small class="form-hint">Sélectionnez la méthode de partage préférée</small>
                                </div>
                                
                                <div class="form-group">
                                    <label for="permission">
                                        <i class="fas fa-shield-alt"></i> Permission
                                    </label>
                                    <select id="permission" name="permission" class="form-control">
                                        <option value="view">Vue seule</option>
                                        <option value="open">Ouverture</option>
                                        <option value="manage">Gestion complète</option>
                                    </select>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-share-alt"></i> Partager
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Partagées avec moi -->
                <div class="tab-content" id="shared-with-me">
                    <?php if (empty($shared_ibox)): ?>
                        <div class="empty-state small">
                            <i class="fas fa-inbox fa-2x"></i>
                            <p>Aucune iBox partagée avec vous</p>
                            <p class="text-muted">Vos amis ou collègues peuvent partager leurs iBox avec vous.</p>
                        </div>
                    <?php else: ?>
                        <div class="shared-ibox-grid">
                            <?php foreach ($shared_ibox as $ibox): ?>
                                <div class="shared-ibox-card">
                                    <div class="shared-header">
                                        <span class="owner">
                                            <i class="fas fa-user"></i>
                                            <?= htmlspecialchars($ibox['owner_prenom'] . ' ' . $ibox['owner_nom']) ?>
                                        </span>
                                        <span class="badge badge-<?= 
                                            $ibox['permission_level'] === 'manage' ? 'warning' : 
                                            ($ibox['permission_level'] === 'open' ? 'info' : 'secondary') 
                                        ?>">
                                            <?= ucfirst($ibox['permission_level']) ?>
                                        </span>
                                    </div>
                                    <div class="shared-code"><?= htmlspecialchars($ibox['code_box']) ?></div>
                                    <div class="shared-location">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <?= htmlspecialchars($ibox['localisation']) ?>
                                    </div>
                                    <div class="shared-actions">
                                        <button class="btn btn-sm btn-primary" 
                                                onclick="logIboxAction(<?= $ibox['id'] ?>, 'ACCESS', 'Accès à l\'iBox')">
                                            <i class="fas fa-key"></i> Accéder
                                        </button>
                                        <button class="btn btn-sm btn-secondary" 
                                                onclick="showHistory(<?= $ibox['id'] ?>)">
                                            <i class="fas fa-history"></i> Historique
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Historique global -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-history"></i> Historique des Activités</h3>
        </div>
        <div class="card-body">
            <div class="history-filters">
                <select id="historyIboxFilter" class="form-control" onchange="filterHistory()">
                    <option value="all">Toutes les iBox</option>
                    <?php foreach ($my_ibox as $ibox): ?>
                        <option value="<?= $ibox['id'] ?>"><?= htmlspecialchars($ibox['code_box']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="history-timeline" id="historyTimeline">
                <?php 
                // Récupérer l'historique de toutes les iBox de l'utilisateur
                $allHistory = [];
                foreach ($my_ibox as $ibox) {
                    $history = getIboxHistory($db, $ibox['id'], 30);
                    $allHistory = array_merge($allHistory, $history);
                }
                // Trier par date décroissante
                usort($allHistory, function($a, $b) {
                    return strtotime($b['created_at']) - strtotime($a['created_at']);
                });
                ?>
                
                <?php if (empty($allHistory)): ?>
                    <div class="empty-state small">
                        <i class="fas fa-history fa-2x"></i>
                        <p>Aucun historique</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($allHistory as $entry): 
                        $actionInfo = $actionLabels[$entry['action_type']] ?? [
                            'label' => $entry['action_type'],
                            'icon' => 'fa-circle',
                            'color' => 'secondary'
                        ];
                    ?>
                        <div class="history-entry" data-ibox="<?= $entry['ibox_id'] ?>">
                            <div class="history-marker">
                                <div class="marker-dot bg-<?= $actionInfo['color'] ?>">
                                    <i class="fas <?= $actionInfo['icon'] ?>"></i>
                                </div>
                                <div class="marker-line"></div>
                            </div>
                            <div class="history-content">
                                <div class="history-header">
                                    <span class="history-action">
                                        <?= htmlspecialchars($actionInfo['label']) ?>
                                    </span>
                                    <span class="history-time">
                                        <?= date('d/m/Y H:i', strtotime($entry['created_at'])) ?>
                                    </span>
                                </div>
                                <div class="history-details">
                                    <?= htmlspecialchars($entry['action_details'] ?? '') ?>
                                </div>
                                <div class="history-user">
                                    <i class="fas fa-user"></i>
                                    <?= htmlspecialchars(($entry['prenom'] ?? '') . ' ' . ($entry['nom'] ?? 'Système')) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Backdrop pour les modals -->
<div id="modalBackdrop" class="modal-backdrop" onclick="closeAllModals()"></div>

<!-- Modal Historique iBox -->
<div class="modal" id="historyModal">
    <div class="modal-header">
        <h2><i class="fas fa-history"></i> Historique</h2>
        <button class="modal-close" onclick="closeModal('historyModal')">&times;</button>
    </div>
    <div class="modal-body" id="historyModalContent">
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-primary" onclick="closeModal('historyModal')">
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
// Gestion des onglets
document.querySelectorAll('.share-tabs .tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const tab = this.dataset.tab;
        
        document.querySelectorAll('.share-tabs .tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        
        this.classList.add('active');
        document.getElementById(tab).classList.add('active');
    });
});

// Gestion des formulaires de partage unifié
document.querySelectorAll('.share-form form').forEach(form => {
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Partage en cours...';
        submitBtn.disabled = true;
        
        fetch('ibox_sharing.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken
            }
        })
        .then(response => {
            refreshCsrfToken(response);
            if (!response.ok) {
                throw new Error('Erreur réseau: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                showNotification(data.message, 'success');
                // Recharger la page pour afficher le nouveau partage
                setTimeout(() => {
                    loadPage('ibox_sharing.php', 'Partage iBox');
                }, 1500);
            } else {
                showNotification(data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            showNotification('Erreur lors du partage: ' + error.message, 'error');
        })
        .finally(() => {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        });
    });
});

// Gestion des formulaires de révocation
document.querySelectorAll('.share-actions form').forEach(form => {
    form.addEventListener('submit', function(e) {
        if (!confirm('Êtes-vous sûr de vouloir révoquer ce partage ?')) {
            e.preventDefault();
            return false;
        }
    });
});

function logIboxAction(iboxId, actionType, details) {
    const formData = new FormData();
    formData.append('action', 'log_action');
    formData.append('ibox_id', iboxId);
    formData.append('action_type', actionType);
    formData.append('details', details);
    
    fetch('ibox_sharing.php', {
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
            showNotification('Action enregistrée avec succès', 'success');
            // Recharger la page pour voir les mises à jour
            setTimeout(() => {
                loadPage('ibox_sharing.php', 'Partage iBox');
            }, 1000);
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        showNotification('Erreur lors de l\'enregistrement de l\'action', 'error');
    });
}

function showHistory(iboxId) {
    // Afficher l'historique dans une nouvelle page
    loadPage('views/client/ibox_history.php?ibox_id=' + iboxId, 'Historique iBox');
}

function filterHistory() {
    const filter = document.getElementById('historyIboxFilter').value;
    const entries = document.querySelectorAll('.history-entry');
    
    entries.forEach(entry => {
        if (filter === 'all' || entry.dataset.ibox === filter) {
            entry.style.display = 'flex';
        } else {
            entry.style.display = 'none';
        }
    });
}

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

console.log('%c🚀 Gestion_Colis - iBox Sharing SPA', 'color: #00B4D8; font-size: 16px; font-weight: bold;');
</script>

<style>
.share-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    border-bottom: 1px solid var(--border-color);
    padding-bottom: 1rem;
}

.tab-btn {
    padding: 0.75rem 1.5rem;
    background: transparent;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    color: var(--gray-600);
    cursor: pointer;
    transition: all 0.2s;
}

.tab-btn:hover {
    background: rgba(0, 180, 216, 0.05);
}

.tab-btn.active {
    background: rgba(0, 180, 216, 0.1);
    border-color: #00B4D8;
    color: #00B4D8;
}

.shares-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.share-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    background: var(--gray-100);
    border: 1px solid var(--border-color);
    border-radius: 12px;
}

.share-info {
    flex: 1;
}

.share-ibox {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 0.5rem;
}

.share-ibox .code {
    font-family: var(--font-display);
    font-weight: 700;
    color: #00B4D8;
}

.share-recipient, .share-date {
    font-size: 0.85rem;
    color: var(--gray-600);
}

.share-recipient i, .share-date i {
    width: 16px;
    color: #00B4D8;
}

.share-actions {
    display: flex;
    gap: 0.5rem;
}

.shared-ibox-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 1rem;
}

.shared-ibox-card {
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1rem;
}

.shared-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.75rem;
}

.shared-header .owner {
    font-size: 0.85rem;
    color: var(--gray-600);
}

.shared-header .owner i {
    color: #00B4D8;
    margin-right: 0.25rem;
}

.shared-code {
    font-family: var(--font-display);
    font-size: 1.1rem;
    font-weight: 700;
    color: #00B4D8;
    margin-bottom: 0.5rem;
}

.shared-location {
    font-size: 0.85rem;
    color: var(--gray-600);
    margin-bottom: 1rem;
}

.shared-location i {
    color: #00B4D8;
}

.shared-actions {
    display: flex;
    gap: 0.5rem;
}

.history-filters {
    margin-bottom: 1.5rem;
}

.history-filters select {
    max-width: 300px;
}

.history-timeline {
    position: relative;
}

.history-entry {
    display: flex;
    gap: 1rem;
    margin-bottom: 1rem;
}

.history-marker {
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 40px;
}

.marker-dot {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    z-index: 1;
}

.marker-dot.bg-success { background: rgba(34, 197, 94, 0.2); color: #22C55E; }
.marker-dot.bg-warning { background: rgba(245, 158, 11, 0.2); color: #F59E0B; }
.marker-dot.bg-primary { background: rgba(0, 180, 216, 0.2); color: #00B4D8; }
.marker-dot.bg-info { background: rgba(59, 130, 246, 0.2); color: #3B82F6; }
.marker-dot.bg-secondary { background: rgba(100, 116, 139, 0.2); color: var(--text-secondary); }
.marker-dot.bg-danger { background: rgba(239, 68, 68, 0.2); color: #EF4444; }

.marker-line {
    width: 2px;
    flex: 1;
    background: var(--border-color);
    margin-top: 0.5rem;
}

.history-content {
    flex: 1;
    padding-bottom: 1.5rem;
}

.history-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.25rem;
}

.history-action {
    font-weight: 600;
    color: #fff;
}

.history-time {
    font-size: 0.8rem;
    color: rgba(255, 255, 255, 0.4);
}

.history-details {
    color: var(--text-secondary);
    font-size: 0.9rem;
    margin-bottom: 0.25rem;
}

.history-user {
    font-size: 0.8rem;
    color: var(--text-muted);
}

.history-user i {
    color: #00B4D8;
    margin-right: 0.25rem;
}

.empty-state.small {
    padding: 2rem;
    text-align: center;
}

.empty-state.small i {
    font-size: 2rem;
    color: var(--border-color);
    margin-bottom: 1rem;
}
</style>

</div> <!-- Fin #page-content -->
