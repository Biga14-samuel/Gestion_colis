<?php
require_once __DIR__ . '/utils/session.php';
SessionManager::start();

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="access-denied">Accès refusé. Veuillez vous connecter.</div>';
    exit;
}

require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$userId = $_SESSION['user_id'];

// Récupérer les notifications de l'utilisateur
$notifications = [];
try {
    $stmt = $db->prepare("
        SELECT id, titre, message, type, lue, date_envoi, lien
        FROM notifications
        WHERE utilisateur_id = ?
        ORDER BY date_envoi DESC
        LIMIT 50
    ");
    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll();
} catch (PDOException $e) {
    $notifications = [];
}

// Marquer une notification comme lue
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_read') {
    $notif_id = $_POST['notif_id'] ?? 0;
    try {
        $stmt = $db->prepare("UPDATE notifications SET lue = 1 WHERE id = ? AND utilisateur_id = ?");
        $stmt->execute([$notif_id, $userId]);
        echo json_encode(['success' => true]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => user_error_message($e, 'notifications.mark_read', 'Erreur lors de la mise à jour.')]);
        exit;
    }
}

// Marquer toutes les notifications comme lues
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
    try {
        $stmt = $db->prepare("UPDATE notifications SET lue = 1 WHERE utilisateur_id = ?");
        $stmt->execute([$userId]);
        echo json_encode(['success' => true]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => user_error_message($e, 'notifications.mark_all_read', 'Erreur lors de la mise à jour.')]);
        exit;
    }
}

// Compter les notifications non lues
$unread_count = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM notifications WHERE utilisateur_id = ? AND lue = 0");
    $stmt->execute([$userId]);
    $unread_count = $stmt->fetch()['total'];
} catch (PDOException $e) {
    $unread_count = 0;
}

$typeIcons = [
    'info' => 'fa-info-circle',
    'alerte' => 'fa-exclamation-triangle',
    'succes' => 'fa-check-circle',
    'erreur' => 'fa-times-circle',
    'colis' => 'fa-box',
    'ibox' => 'fa-inbox',
    'systeme' => 'fa-cog'
];

$typeColors = [
    'info' => 'info',
    'alerte' => 'warning',
    'succes' => 'success',
    'erreur' => 'danger',
    'colis' => 'primary',
    'ibox' => 'primary',
    'systeme' => 'secondary'
];
?>

<!-- Contenu pour le système SPA -->
<div id="page-content">

<div class="page-container">
    <div class="page-header">
        <h1><i class="fas fa-bell"></i> Mes Notifications</h1>
        <p>Restez informé de toutes les activités</p>
    </div>

    <!-- Actions en haut -->
    <?php if ($unread_count > 0): ?>
        <div style="margin-bottom: 1.5rem; display: flex; justify-content: flex-end;">
            <button class="btn btn-outline" onclick="markAllAsRead()">
                <i class="fas fa-check-double"></i> Marquer tout comme lu (<?= $unread_count ?>)
            </button>
        </div>
    <?php endif; ?>

    <?php if (empty($notifications)): ?>
        <div class="card">
            <div class="card-body">
                <div class="empty-state">
                    <i class="fas fa-bell-slash fa-3x"></i>
                    <h3>Aucune Notification</h3>
                    <p>Vous n'avez pas encore de notifications. Vos alertes et mises à jour apparaîtront ici.</p>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="notifications-list">
            <?php foreach ($notifications as $notif): ?>
                <div class="notification-item <?= ($notif['lue'] ?? 0) ? '' : 'unread' ?>" 
                     data-notif-id="<?= $notif['id'] ?>"
                     onclick="markAsRead(<?= $notif['id'] ?>)">
                    <div class="notification-icon <?= $typeColors[$notif['type']] ?? 'info' ?>">
                        <i class="fas <?= $typeIcons[$notif['type']] ?? 'fa-bell' ?>"></i>
                    </div>
                    <div class="notification-content">
                        <div class="notification-header">
                            <span class="notification-title"><?= htmlspecialchars($notif['titre'] ?? 'Notification') ?></span>
                            <span class="notification-time">
                                <?php 
                                $date = new DateTime($notif['date_envoi'] ?? 'now');
                                $now = new DateTime();
                                $diff = $now->diff($date);
                                
                                if ($diff->days > 0) {
                                    echo $date->format('d/m/Y');
                                } elseif ($diff->h > 0) {
                                    echo 'Il y a ' . $diff->h . 'h';
                                } elseif ($diff->i > 0) {
                                    echo 'Il y a ' . $diff->i . ' min';
                                } else {
                                    echo 'À l\'instant';
                                }
                                ?>
                            </span>
                        </div>
                        <div class="notification-message"><?= htmlspecialchars($notif['message'] ?? '') ?></div>
                        <?php if (!empty($notif['lien'])): ?>
                            <div class="notification-link" onclick="event.stopPropagation(); loadPage('<?= htmlspecialchars($notif['lien']) ?>', 'Détails')">
                                <i class="fas fa-external-link-alt"></i> Voir plus de détails
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (($notif['lue'] ?? 0) == 0): ?>
                        <div class="unread-indicator"></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
/* Styles pour les notifications - Compatibles avec le thème SPA */
.page-container {
    padding: 1.5rem;
    max-width: 900px;
    margin: 0 auto;
}

.page-header {
    margin-bottom: 1.5rem;
}

.page-header h1 {
    font-family: 'Orbitron', sans-serif;
    font-size: 1.5rem;
    color: var(--text-dark, #0f172a);
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.page-header h1 i {
    color: var(--primary-cyan, #00B4D8);
}

.page-header p {
    color: var(--text-secondary, #64748b);
    margin-top: 0.25rem;
}

.card {
    background: #ffffff;
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 12px;
    overflow: hidden;
}

.card-body {
    padding: 1.5rem;
}

/* Empty state */
.empty-state {
    text-align: center;
    padding: 3rem;
    color: var(--text-secondary, #64748b);
}

.empty-state i {
    font-size: 3rem;
    color: var(--border-color, #e2e8f0);
    margin-bottom: 1rem;
}

.empty-state h3 {
    font-family: 'Orbitron', sans-serif;
    color: var(--text-dark, #0f172a);
    margin-bottom: 0.5rem;
}

/* Notifications list */
.notifications-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.notification-item {
    background: #ffffff;
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 12px;
    padding: 1rem 1.25rem;
    display: flex;
    gap: 1rem;
    align-items: flex-start;
    transition: all 0.3s ease;
    cursor: pointer;
    position: relative;
}

.notification-item:hover {
    border-color: var(--primary-cyan, #00B4D8);
    transform: translateX(5px);
    box-shadow: 0 2px 8px rgba(0, 180, 216, 0.1);
}

.notification-item.unread {
    border-left: 4px solid var(--primary-cyan, #00B4D8);
    background: rgba(0, 180, 216, 0.02);
}

.unread-indicator {
    width: 8px;
    height: 8px;
    background: var(--primary-cyan, #00B4D8);
    border-radius: 50%;
    position: absolute;
    top: 1rem;
    right: 1rem;
}

.notification-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    flex-shrink: 0;
}

.notification-icon.info {
    background: rgba(0, 180, 216, 0.15);
    color: var(--primary-cyan, #00B4D8);
}

.notification-icon.success {
    background: rgba(34, 197, 94, 0.15);
    color: #22c55e;
}

.notification-icon.warning {
    background: rgba(245, 158, 11, 0.15);
    color: #f59e0b;
}

.notification-icon.danger {
    background: rgba(239, 68, 68, 0.15);
    color: #ef4444;
}

.notification-icon.primary {
    background: rgba(59, 130, 246, 0.15);
    color: #3b82f6;
}

.notification-icon.secondary {
    background: rgba(100, 116, 139, 0.15);
    color: #64748b;
}

.notification-content {
    flex: 1;
    min-width: 0;
}

.notification-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.25rem;
    gap: 1rem;
}

.notification-title {
    font-weight: 600;
    color: var(--text-dark, #0f172a);
}

.notification-time {
    font-size: 0.8rem;
    color: var(--text-secondary, #64748b);
    flex-shrink: 0;
}

.notification-message {
    color: var(--text-secondary, #64748b);
    font-size: 0.9rem;
    line-height: 1.5;
}

.notification-link {
    margin-top: 0.5rem;
    font-size: 0.85rem;
    color: var(--primary-cyan, #00B4D8);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

.notification-link:hover {
    text-decoration: underline;
}

/* Boutons */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.25rem;
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    background: #ffffff;
    color: var(--text-dark, #0f172a);
}

.btn-outline {
    background: transparent;
}

.btn-outline:hover {
    border-color: var(--primary-cyan, #00B4D8);
    color: var(--primary-cyan, #00B4D8);
    background: rgba(0, 180, 216, 0.05);
}

/* Responsive */
@media (max-width: 640px) {
    .notification-item {
        padding: 0.875rem 1rem;
    }
    
    .notification-icon {
        width: 36px;
        height: 36px;
        font-size: 1rem;
    }
    
    .notification-header {
        flex-direction: column;
        gap: 0.25rem;
    }
}
</style>

<script>
const csrfToken = <?php echo json_encode(csrf_token()); ?>;
function markAsRead(notifId) {
    const item = document.querySelector(`.notification-item[data-notif-id="${notifId}"]`);
    if (item && item.classList.contains('unread')) {
        // Appel AJAX pour marquer comme lu
        fetch('mes_notifications.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken
            },
            body: 'action=mark_read&notif_id=' + notifId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                item.classList.remove('unread');
                item.querySelector('.unread-indicator')?.remove();
                
                // Mettre à jour le compteur
                const btn = document.querySelector('button[onclick="markAllAsRead()"]');
                if (btn) {
                    const match = btn.textContent.match(/\((\d+)\)/);
                    if (match) {
                        let count = parseInt(match[1]) - 1;
                        if (count > 0) {
                            btn.innerHTML = '<i class="fas fa-check-double"></i> Marquer tout comme lu (' + count + ')';
                        } else {
                            btn.remove();
                        }
                    }
                }
            }
        })
        .catch(error => console.error('Erreur:', error));
    }
}

function markAllAsRead() {
    fetch('mes_notifications.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': csrfToken
        },
        body: 'action=mark_all_read'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Toutes les notifications ont été marquées comme lues', 'success');
            setTimeout(() => {
                loadPage('mes_notifications.php', 'Notifications');
            }, 1000);
        }
    })
    .catch(error => console.error('Erreur:', error));
}

console.log('%c🚀 Gestion_Colis - Notifications SPA', 'color: #00B4D8; font-size: 16px; font-weight: bold;');
</script>

</div> <!-- Fin #page-content -->
