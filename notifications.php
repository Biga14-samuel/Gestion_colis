<?php
/**
 * Module Notifications - Système de Notifications en Temps Réel
 * Supporte: Email, SMS (simulé), Push, et Web
 */

require_once __DIR__ . '/utils/session.php';
SessionManager::start();
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'] ?? 0;
$message = '';
$messageType = '';

// Vérifier la connexion
if (!$user_id) {
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="access-denied">Accès refusé. Veuillez vous connecter.</div>';
    exit;
}

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'mark_read') {
        $notif_id = $_POST['notification_id'] ?? 0;
        
        $stmt = $db->prepare("UPDATE notifications SET lue = 1, date_lecture = NOW() WHERE id = ? AND utilisateur_id = ?");
        $stmt->execute([$notif_id, $user_id]);
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($action === 'mark_all_read') {
        $stmt = $db->prepare("UPDATE notifications SET lue = 1, date_lecture = NOW() WHERE utilisateur_id = ?");
        $stmt->execute([$user_id]);
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($action === 'delete_notification') {
        $notif_id = $_POST['notification_id'] ?? 0;
        
        $stmt = $db->prepare("DELETE FROM notifications WHERE id = ? AND utilisateur_id = ?");
        $stmt->execute([$notif_id, $user_id]);
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($action === 'update_preferences') {
        $notif_colis = isset($_POST['notif_colis']) ? 1 : 0;
        $notif_livraison = isset($_POST['notif_livraison']) ? 1 : 0;
        $notif_paiement = isset($_POST['notif_paiement']) ? 1 : 0;
        $notif_email = isset($_POST['notif_email']) ? 1 : 0;
        $notif_sms = isset($_POST['notif_sms']) ? 1 : 0;
        
        // Vérifier si les préférences existent
        $stmt = $db->prepare("SELECT id FROM notifications_preferences WHERE utilisateur_id = ?");
        $stmt->execute([$user_id]);
        
        if ($stmt->fetch()) {
            $stmt = $db->prepare("
                UPDATE notifications_preferences SET
                    notif_colis = ?,
                    notif_livraison = ?,
                    notif_paiement = ?,
                    notif_email = ?,
                    notif_sms = ?
                WHERE utilisateur_id = ?
            ");
            $stmt->execute([$notif_colis, $notif_livraison, $notif_paiement, $notif_email, $notif_sms, $user_id]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO notifications_preferences (
                    utilisateur_id, notif_colis, notif_livraison,
                    notif_paiement, notif_email, notif_sms
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$user_id, $notif_colis, $notif_livraison, $notif_paiement, $notif_email, $notif_sms]);
        }
        
        $message = 'Préférences de notifications mises à jour.';
        $messageType = 'success';
    }
}

// Récupérer les préférences de l'utilisateur
$stmt = $db->prepare("SELECT * FROM notifications_preferences WHERE utilisateur_id = ?");
$stmt->execute([$user_id]);
$preferences = $stmt->fetch() ?: [
    'notif_colis' => 1,
    'notif_livraison' => 1,
    'notif_paiement' => 1,
    'notif_email' => 1,
    'notif_sms' => 0
];

// Récupérer les notifications
$stmt = $db->prepare("
    SELECT * FROM notifications 
    WHERE utilisateur_id = ? 
    ORDER BY date_envoi DESC 
    LIMIT 50
");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll();

// Compter les notifications non lues
$stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE utilisateur_id = ? AND lue = 0");
$stmt->execute([$user_id]);
$unreadCount = $stmt->fetchColumn();

// Récupérer les types de notification pour le filtre
$typeLabels = [
    'colis' => 'Colis',
    'ibox' => 'iBox',
    'delivery' => 'Livraison',
    'payment' => 'Paiement',
    'signature' => 'Signature',
    'postal_id' => 'Postal ID',
    'security' => 'Sécurité',
    'system' => 'Système',
    'promotion' => 'Promotion'
];

$typeIcons = [
    'colis' => 'fa-box',
    'ibox' => 'fa-inbox',
    'delivery' => 'fa-truck',
    'payment' => 'fa-credit-card',
    'signature' => 'fa-signature',
    'postal_id' => 'fa-id-card',
    'security' => 'fa-shield-alt',
    'system' => 'fa-cog',
    'promotion' => 'fa-gift'
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
                <i class="fas fa-bell" style="color: #00B4D8;"></i> 
                Notifications
            </h1>
        </div>
        <?php if ($unreadCount > 0): ?>
            <button class="btn btn-secondary" onclick="markAllAsRead()">
                <i class="fas fa-check-double"></i>
                Tout marquer comme lu
            </button>
        <?php endif; ?>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?>">
            <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Statistiques des notifications -->
    <div class="notification-stats">
        <div class="stat-card">
            <div class="stat-icon bg-primary">
                <i class="fas fa-bell"></i>
            </div>
            <div class="stat-content">
                <span class="stat-number"><?= $unreadCount ?></span>
                <span class="stat-text">Non lues</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-secondary">
                <i class="fas fa-envelope"></i>
            </div>
            <div class="stat-content">
                <span class="stat-number"><?= count($notifications) ?></span>
                <span class="stat-text">Total</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-success">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-content">
                <span class="stat-number"><?= count($notifications) - $unreadCount ?></span>
                <span class="stat-text">Lues</span>
            </div>
        </div>
    </div>

    <!-- Filtres -->
    <div class="notification-filters">
        <button class="filter-btn active" data-filter="all">
            <i class="fas fa-list"></i> Toutes
        </button>
        <button class="filter-btn" data-filter="unread">
            <i class="fas fa-envelope"></i> Non lues
            <?php if ($unreadCount > 0): ?>
                <span class="badge"><?= $unreadCount ?></span>
            <?php endif; ?>
        </button>
        <?php foreach ($typeLabels as $type => $label): ?>
            <button class="filter-btn" data-filter="<?= $type ?>">
                <i class="fas <?= $typeIcons[$type] ?? 'fa-bell' ?>"></i>
                <?= $label ?>
            </button>
        <?php endforeach; ?>
    </div>

    <!-- Liste des notifications -->
    <div class="notification-list" id="notificationList">
        <?php if (empty($notifications)): ?>
            <div class="empty-state">
                <i class="fas fa-bell-slash fa-3x"></i>
                <h3>Aucune notification</h3>
                <p>Vous n'avez pas encore de notifications.</p>
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $notif): ?>
                <div class="notification-item <?= $notif['lue'] ? '' : 'unread' ?>" 
                     data-type="<?= htmlspecialchars($notif['type']) ?>"
                     data-id="<?= $notif['id'] ?>">
                    <div class="notification-icon">
                        <i class="fas <?= $typeIcons[$notif['type']] ?? 'fa-bell' ?>"></i>
                    </div>
                    <div class="notification-content">
                        <div class="notification-header">
                            <h4><?= htmlspecialchars($notif['titre']) ?></h4>
                            <span class="notification-time">
                                <?= timeAgo($notif['date_envoi']) ?>
                            </span>
                        </div>
                        <p class="notification-message"><?= htmlspecialchars($notif['message']) ?></p>
                    </div>
                    <div class="notification-actions">
                        <?php if (!$notif['lue']): ?>
                            <button class="btn-icon" onclick="markAsRead(<?= $notif['id'] ?>)" title="Marquer comme lu">
                                <i class="fas fa-check"></i>
                            </button>
                        <?php endif; ?>
                        <button class="btn-icon btn-delete" onclick="deleteNotification(<?= $notif['id'] ?>)" title="Supprimer">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Préférences de notifications -->
    <div class="card mt-4">
        <div class="card-header">
            <h3><i class="fas fa-cog"></i> Préférences de notifications</h3>
        </div>
        <div class="card-body">
            <form method="POST" id="preferencesForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="update_preferences">
                
                <div class="preferences-grid">
                    <div class="pref-section">
                        <h4><i class="fas fa-envelope"></i> Canaux de notification</h4>
                        
                        <label class="checkbox-card">
                            <input type="checkbox" name="notif_email" value="1" 
                                   <?= $preferences['notif_email'] ? 'checked' : '' ?>>
                            <div class="checkbox-content">
                                <span class="checkbox-title">Notifications par email</span>
                                <span class="checkbox-desc">Recevoir les notifications importantes par email</span>
                            </div>
                        </label>
                        
                        <label class="checkbox-card">
                            <input type="checkbox" name="notif_sms" value="1"
                                   <?= $preferences['notif_sms'] ? 'checked' : '' ?>>
                            <div class="checkbox-content">
                                <span class="checkbox-title">Notifications SMS</span>
                                <span class="checkbox-desc">Recevoir les alertes urgentes par SMS</span>
                            </div>
                        </label>
                        
                        <label class="checkbox-card">
                            <input type="checkbox" name="notif_colis" value="1"
                                   <?= $preferences['notif_colis'] ? 'checked' : '' ?>>
                            <div class="checkbox-content">
                                <span class="checkbox-title">Notifications colis</span>
                                <span class="checkbox-desc">Suivi des colis et mises à jour importantes</span>
                            </div>
                        </label>
                    </div>
                    
                    <div class="pref-section">
                        <h4><i class="fas fa-tag"></i> Types de notifications</h4>
                        
                        <label class="checkbox-card">
                            <input type="checkbox" name="notif_livraison" value="1"
                                   <?= $preferences['notif_livraison'] ? 'checked' : '' ?>>
                            <div class="checkbox-content">
                                <span class="checkbox-title">Alertes de livraison</span>
                                <span class="checkbox-desc">Suivi des colis et notifications de livraison</span>
                            </div>
                        </label>
                        
                        <label class="checkbox-card">
                            <input type="checkbox" name="notif_paiement" value="1"
                                   <?= $preferences['notif_paiement'] ? 'checked' : '' ?>>
                            <div class="checkbox-content">
                                <span class="checkbox-title">Notifications paiement</span>
                                <span class="checkbox-desc">Statut des paiements et reçus</span>
                            </div>
                        </label>
                    </div>
                </div>
                
                <div class="form-actions mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Enregistrer les préférences
                    </button>
                </div>
            </form>
        </div>
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
// ==========================================
// SYSTÈME DE NOTIFICATIONS AJAX - Gestion_Colis
// ==========================================

// Variables globales
let notificationPolling = null;
let currentFilter = 'all';
let isProcessing = false;

// Configuration des types de notifications
const notificationTypes = {
    'unread': { icon: 'fa-envelope', label: 'Non lues' },
    'colis': { icon: 'fa-box', label: 'Colis' },
    'ibox': { icon: 'fa-inbox', label: 'iBox' },
    'delivery': { icon: 'fa-truck', label: 'Livraison' },
    'payment': { icon: 'fa-credit-card', label: 'Paiement' },
    'signature': { icon: 'fa-signature', label: 'Signature' },
    'postal_id': { icon: 'fa-id-card', label: 'Postal ID' },
    'security': { icon: 'fa-shield-alt', label: 'Sécurité' },
    'system': { icon: 'fa-cog', label: 'Système' },
    'promotion': { icon: 'fa-gift', label: 'Promotion' }
};

// Initialisation au chargement de la page
document.addEventListener('DOMContentLoaded', function() {
    initializeNotificationSystem();
    initFilters();
    initPreferenceForm();
    startAutoRefresh();
});

function initializeNotificationSystem() {
    console.log('🚀 Système de notifications initialisé');
    
    // Ajouter les gestionnaires d'événements pour les liens de notification
    document.querySelectorAll('.notification-link').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const notificationId = this.closest('.notification-item').dataset.id;
            markAsRead(notificationId).then(() => {
                window.location.href = this.href;
            });
        });
    });
}

// ==========================================
// SYSTÈME DE FILTRAGE AMÉLIORÉ
// ==========================================

function initFilters() {
    const filterContainer = document.querySelector('.notification-filters');
    if (!filterContainer) return;
    
    const filterBtns = filterContainer.querySelectorAll('.filter-btn');
    
    filterBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            if (isProcessing) return;
            
            const filter = this.dataset.filter;
            
            // Mise à jour visuelle des boutons
            filterBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            // Application du filtre avec animation
            applyFilter(filter);
        });
        
        // Support du clic droit pour filtrer uniquement les non-lues
        btn.addEventListener('contextmenu', function(e) {
            e.preventDefault();
            if (this.dataset.type === 'unread') {
                showToast('Astuce : Cliquez simplement sur "Non lues" pour filtrer', 'info');
            }
        });
    });
}

function applyFilter(filter) {
    currentFilter = filter;
    const items = document.querySelectorAll('.notification-item');
    const emptyState = document.querySelector('.empty-state');
    let visibleCount = 0;
    
    items.forEach((item, index) => {
        const itemType = item.dataset.type;
        const isUnread = item.classList.contains('unread');
        
        let shouldShow = false;
        
        switch(filter) {
            case 'all':
                shouldShow = true;
                break;
            case 'unread':
                shouldShow = isUnread;
                break;
            default:
                shouldShow = itemType === filter;
        }
        
        // Animation d'apparition
        if (shouldShow) {
            visibleCount++;
            item.style.display = 'flex';
            item.style.opacity = '0';
            item.style.transform = 'translateY(10px)';
            
            setTimeout(() => {
                item.style.transition = 'all 0.3s ease';
                item.style.opacity = '1';
                item.style.transform = 'translateY(0)';
            }, index * 30);
        } else {
            item.style.display = 'none';
        }
    });
    
    // Gérer l'état vide
    if (emptyState) {
        if (visibleCount === 0) {
            emptyState.style.display = 'flex';
            const emptyTitle = emptyState.querySelector('h3');
            if (emptyTitle) {
                if (filter === 'unread') {
                    emptyTitle.textContent = 'Aucune notification non lue';
                } else if (filter !== 'all') {
                    const typeInfo = notificationTypes[filter];
                    emptyTitle.textContent = `Aucune notification ${typeInfo ? typeInfo.label.toLowerCase() : filter}`;
                } else {
                    emptyTitle.textContent = 'Aucune notification';
                }
            }
        } else {
            emptyState.style.display = 'none';
        }
    }
}

// ==========================================
// FONCTIONS AJAX POUR LES ACTIONS
// ==========================================

async function markAsRead(notificationId) {
    if (isProcessing || !notificationId) return;
    
    const item = document.querySelector(`.notification-item[data-id="${notificationId}"]`);
    if (!item) return;
    
    try {
        isProcessing = true;
        showLoading(item);
        
        const response = await fetch('notifications.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: `action=mark_read&notification_id=${notificationId}`
        });

        refreshCsrfToken(response);
        const data = await response.json();
        
        if (data.success) {
            // Mise à jour visuelle de l'élément
            item.classList.remove('unread');
            item.style.borderColor = '';
            item.style.background = '';
            
            // Supprimer le bouton "marquer comme lu" s'il existe
            const readBtn = item.querySelector('.btn-icon[onclick^="markAsRead"]');
            if (readBtn) {
                readBtn.style.display = 'none';
            }
            
            // Mise à jour du compteur
            updateUnreadCount(-1);
            
            // Feedback utilisateur
            showToast('Notification marquée comme lue', 'success');
            
            // Animation de succès
            item.style.animation = 'pulse 0.3s ease';
            setTimeout(() => item.style.animation = '', 300);
        } else {
            showToast('Erreur lors du marquage', 'error');
        }
    } catch (error) {
        console.error('Erreur:', error);
        showToast('Erreur de connexion', 'error');
    } finally {
        isProcessing = false;
        hideLoading(item);
    }
}

async function markAllAsRead() {
    if (isProcessing) return;
    
    const confirmBtn = event?.target?.closest('button');
    if (confirmBtn) {
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Traitement...';
    }
    
    try {
        isProcessing = true;
        
        const response = await fetch('notifications.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: 'action=mark_all_read'
        });

        refreshCsrfToken(response);
        const data = await response.json();
        
        if (data.success) {
            // Mise à jour de tous les éléments
            document.querySelectorAll('.notification-item.unread').forEach(item => {
                item.classList.remove('unread');
                item.style.borderColor = '';
                item.style.background = '';
                
                const readBtn = item.querySelector('.btn-icon[onclick^="markAsRead"]');
                if (readBtn) {
                    readBtn.style.display = 'none';
                }
            });
            
            // Réinitialiser le compteur
            updateUnreadCount(-document.querySelectorAll('.notification-item.unread').length);
            
            showToast('Toutes les notifications ont été marquées comme lues', 'success');
            
            // Rafraîchir les filtres
            applyFilter(currentFilter);
        } else {
            showToast('Erreur lors du traitement', 'error');
        }
    } catch (error) {
        console.error('Erreur:', error);
        showToast('Erreur de connexion', 'error');
    } finally {
        isProcessing = false;
        if (confirmBtn) {
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = '<i class="fas fa-check-double"></i> Tout marquer comme lu';
        }
    }
}

async function deleteNotification(notificationId) {
    if (isProcessing || !notificationId) return;
    
    const item = document.querySelector(`.notification-item[data-id="${notificationId}"]`);
    if (!item) return;
    
    // Demander confirmation avec une alerte personnalisée
    if (!await showConfirmDialog('Supprimer cette notification ?', 'Cette action est irréversible.')) {
        return;
    }
    
    try {
        isProcessing = true;
        showLoading(item);
        
        const response = await fetch('notifications.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: `action=delete_notification&notification_id=${notificationId}`
        });

        refreshCsrfToken(response);
        const data = await response.json();
        
        if (data.success) {
            // Animation de suppression
            item.style.transition = 'all 0.3s ease';
            item.style.opacity = '0';
            item.style.transform = 'translateX(-20px)';
            
            setTimeout(() => {
                item.remove();
                updateTotalCount();
                checkEmptyState();
            }, 300);
            
            // Mise à jour du compteur si non lue
            if (item.classList.contains('unread')) {
                updateUnreadCount(-1);
            }
            
            showToast('Notification supprimée', 'success');
        } else {
            showToast('Erreur lors de la suppression', 'error');
        }
    } catch (error) {
        console.error('Erreur:', error);
        showToast('Erreur de connexion', 'error');
    } finally {
        isProcessing = false;
        hideLoading(item);
    }
}

// ==========================================
// FONCTIONS UTILITAIRES
// ==========================================

function updateUnreadCount(change) {
    const counterElements = document.querySelectorAll('.stat-number');
    counterElements.forEach(el => {
        if (el.textContent.match(/^\d+$/)) {
            let current = parseInt(el.textContent);
            current = Math.max(0, current + change);
            el.textContent = current;
        }
    });
    
    // Mettre à jour ou masquer le bouton "Tout marquer comme lu"
    const markAllBtn = document.querySelector('.btn-secondary');
    const unreadCount = parseInt(counterElements[0]?.textContent || 0);
    
    if (markAllBtn) {
        markAllBtn.style.display = unreadCount > 0 ? 'inline-flex' : 'none';
    }
}

function updateTotalCount() {
    const totalItems = document.querySelectorAll('.notification-item').length;
    const counterElements = document.querySelectorAll('.stat-number');
    if (counterElements[1]) {
        counterElements[1].textContent = totalItems;
    }
    if (counterElements[2]) {
        counterElements[2].textContent = totalItems - parseInt(counterElements[0]?.textContent || 0);
    }
}

function checkEmptyState() {
    const remainingItems = document.querySelectorAll('.notification-item:not([style*="display: none"])').length;
    const emptyState = document.querySelector('.empty-state');
    
    if (emptyState && remainingItems === 0) {
        emptyState.style.display = 'flex';
    }
}

function showLoading(element) {
    element.style.opacity = '0.7';
    element.style.pointerEvents = 'none';
}

function hideLoading(element) {
    element.style.opacity = '1';
    element.style.pointerEvents = 'auto';
}

// ==========================================
// SYSTÈME DE NOTIFICATIONS TOAST
// ==========================================

function showToast(message, type = 'info') {
    // Supprimer les toasts existants
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(t => t.remove());
    
    const toast = document.createElement('div');
    toast.className = `toast-notification toast-${type}`;
    toast.innerHTML = `
        <div class="toast-icon">
            <i class="fas ${getToastIcon(type)}"></i>
        </div>
        <div class="toast-message">${escapeHtml(message)}</div>
        <button class="toast-close" onclick="this.parentElement.remove()">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    // Styles du toast
    toast.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 14px 20px;
        background: ${getToastBg(type)};
        color: white;
        border-radius: 10px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        z-index: 10000;
        animation: slideIn 0.3s ease;
        font-family: inherit;
        max-width: 350px;
    `;
    
    document.body.appendChild(toast);
    
    // Auto-suppression
    setTimeout(() => {
        if (toast.parentElement) {
            toast.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }
    }, 4000);
}

function getToastIcon(type) {
    const icons = {
        'success': 'fa-check-circle',
        'error': 'fa-exclamation-circle',
        'warning': 'fa-exclamation-triangle',
        'info': 'fa-info-circle'
    };
    return icons[type] || icons.info;
}

function getToastBg(type) {
    const colors = {
        'success': '#22C55E',
        'error': '#EF4444',
        'warning': '#F59E0B',
        'info': '#00B4D8'
    };
    return colors[type] || colors.info;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

async function showConfirmDialog(title, message) {
    return new Promise((resolve) => {
        // Supprimer les dialogues existants
        document.querySelectorAll('.confirm-dialog-overlay').forEach(d => d.remove());
        
        const overlay = document.createElement('div');
        overlay.className = 'confirm-dialog-overlay';
        overlay.innerHTML = `
            <div class="confirm-dialog">
                <div class="confirm-header">
                    <i class="fas fa-exclamation-triangle" style="color: #F59E0B;"></i>
                    <h3>${escapeHtml(title)}</h3>
                </div>
                <p>${escapeHtml(message)}</p>
                <div class="confirm-actions">
                    <button class="btn btn-secondary" id="confirmCancel">Annuler</button>
                    <button class="btn btn-danger" id="confirmOk">Supprimer</button>
                </div>
            </div>
        `;
        
        overlay.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10001;
            animation: fadeIn 0.2s ease;
        `;
        
        const dialog = overlay.querySelector('.confirm-dialog');
        dialog.style.cssText = `
            background: #1e293b;
            padding: 24px;
            border-radius: 12px;
            max-width: 400px;
            width: 90%;
            text-align: center;
            animation: scaleIn 0.2s ease;
        `;
        
        const header = overlay.querySelector('.confirm-header');
        header.style.cssText = `
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 16px;
            font-size: 1.2rem;
        `;
        
        const actions = overlay.querySelector('.confirm-actions');
        actions.style.cssText = `
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 24px;
        `;
        
        document.body.appendChild(overlay);
        
        overlay.querySelector('#confirmCancel').addEventListener('click', () => {
            overlay.remove();
            resolve(false);
        });
        
        overlay.querySelector('#confirmOk').addEventListener('click', () => {
            overlay.remove();
            resolve(true);
        });
        
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                overlay.remove();
                resolve(false);
            }
        });
    });
}

// ==========================================
// FORMULAIRE DE PRÉFÉRENCES
// ==========================================

function initPreferenceForm() {
    const form = document.getElementById('preferencesForm');
    if (!form) return;
    
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalContent = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enregistrement...';
        
        try {
            const formData = new FormData(form);
            formData.append('action', 'update_preferences');
            
            const response = await fetch('notifications.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-CSRF-Token': csrfToken }
            });

            refreshCsrfToken(response);
            const data = await response.json();
            
            if (data.success || !data.error) {
                showToast('Préférences de notifications mises à jour', 'success');
            } else {
                showToast('Erreur lors de la mise à jour', 'error');
            }
        } catch (error) {
            console.error('Erreur:', error);
            showToast('Erreur de connexion', 'error');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalContent;
        }
    });
}

// ==========================================
// AUTO-REFRESH (sans rechargement)
// ==========================================

function startAutoRefresh() {
    // Rafraîchir les notifications toutes les 30 secondes
    setInterval(async () => {
        try {
            const response = await fetch('notifications.php?ajax=1', {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            if (response.ok) {
                const text = await response.text();
                // Vérifier s'il y a de nouvelles notifications
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = text;
                const newUnreadMatch = text.match(/unreadCount\s*=\s*(\d+)/);
                
                if (newUnreadMatch) {
                    const currentCount = parseInt(document.querySelector('.stat-number')?.textContent || 0);
                    const newCount = parseInt(newUnreadMatch[1]);
                    
                    if (newCount > currentCount) {
                        // Nouvelles notifications détectées
                        playNotificationSound();
                        showToast(`${newCount - currentCount} nouvelle(s) notification(s)`, 'info');
                    }
                }
            }
        } catch (error) {
            // Silencieux - pas d'action nécessaire
        }
    }, 30000);
}

// Nettoyer le polling lors de la navigation
document.addEventListener('pageUnload', function() {
    if (notificationPolling) {
        clearInterval(notificationPolling);
    }
});

// Jouer un son de notification (si autorisé)
function playNotificationSound() {
    try {
        const audio = new Audio();
        audio.src = 'data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdH2Onp+cnJmXm52fo6CgoJ+enp6enp2dnZ2dnZuampqampqZmZmZmZmYmJiYmJeXl5eWlpaWlpaVlZWVlJSUlJSUk5OTk5OTkpKSkpKRkZGRkZCQkJCQj4+Pj46Ojo6NjY2NjIyMjIuLi4uKioqKiYmJiYiIiIiHh4eHhoaGhoWFhYWEhISEg4ODg4KCgoKBgYGBgICAf39/f35+fn59fX19fHx8fHt7e3t6enp6eXl5eXh4eHh3d3d3dnZ2dnV1dXV0dHR0c3Nzc3JycnJxcXFxcHBwcG9vb29ubm5ubW1tbWxsbGxra2tram"';
        // Son simplifié - en production, utiliser un fichier audio réel
        console.log('🔔 Nouvelle notification');
    } catch (e) {
        console.log('🔔 Nouvelle notification');
    }
}

console.log('%c🚀 Gestion_Colis - Notifications AJAX', 'color: #00B4D8; font-size: 16px; font-weight: bold;');
console.log('%c✓ Système de filtrage et actions AJAX actifs', 'color: #22C55E; font-size: 12px;');
</script>

<style>
/* ==========================================
   STYLES AMÉLIORÉS - SYSTÈME DE NOTIFICATIONS
   ========================================== */

/* Animation Keyframes */
@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateX(100px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes slideOut {
    from {
        opacity: 1;
        transform: translateX(0);
    }
    to {
        opacity: 0;
        transform: translateX(100px);
    }
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes scaleIn {
    from {
        opacity: 0;
        transform: scale(0.9);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.02); }
}

/* Stats Container */
.notification-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: all 0.3s ease;
}

.stat-card:hover {
    border-color: rgba(0, 180, 216, 0.3);
    transform: translateY(-2px);
}

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
}

.stat-icon.bg-primary { 
    background: rgba(0, 180, 216, 0.15); 
    color: #00B4D8; 
}

.stat-icon.bg-secondary { 
    background: rgba(100, 116, 139, 0.15); 
    color: var(--text-secondary); 
}

.stat-icon.bg-success { 
    background: rgba(34, 197, 94, 0.15); 
    color: #22C55E; 
}

.stat-icon.bg-warning { 
    background: rgba(245, 158, 11, 0.15); 
    color: #F59E0B; 
}

.stat-content {
    display: flex;
    flex-direction: column;
}

.stat-number {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
    line-height: 1.2;
}

.stat-text {
    font-size: 0.8rem;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Filters Container */
.notification-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    padding: 1rem;
    background: rgba(0, 0, 0, 0.02);
    border-radius: 12px;
    border: 1px solid var(--border-color);
}

.filter-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.6rem 1rem;
    background: transparent;
    border: 1px solid var(--border-color);
    border-radius: 20px;
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 0.85rem;
    font-weight: 500;
}

.filter-btn:hover {
    background: rgba(0, 180, 216, 0.1);
    border-color: rgba(0, 180, 216, 0.3);
    color: #00B4D8;
}

.filter-btn.active {
    background: rgba(0, 180, 216, 0.2);
    border-color: #00B4D8;
    color: #00B4D8;
}

.filter-btn .badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    background: rgba(0, 180, 216, 0.2);
    border-radius: 10px;
    font-size: 0.75rem;
    font-weight: 600;
    color: #00B4D8;
}

.filter-btn.active .badge {
    background: #00B4D8;
    color: white;
}

/* Notification List */
.notification-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.notification-item {
    display: flex;
    gap: 1rem;
    padding: 1rem 1.25rem;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.notification-item::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    height: 100%;
    width: 4px;
    background: transparent;
    transition: all 0.3s ease;
}

.notification-item.unread {
    border-color: rgba(0, 180, 216, 0.3);
    background: linear-gradient(135deg, rgba(0, 180, 216, 0.08) 0%, var(--bg-card) 100%);
}

.notification-item.unread::before {
    background: linear-gradient(180deg, #00B4D8 0%, #0096b3 100%);
}

.notification-item:hover {
    border-color: rgba(0, 180, 216, 0.5);
    transform: translateX(4px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.notification-item.unread:hover {
    box-shadow: 0 4px 20px rgba(0, 180, 216, 0.15);
}

.notification-icon {
    width: 48px;
    height: 48px;
    background: rgba(0, 180, 216, 0.12);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    color: #00B4D8;
    flex-shrink: 0;
    transition: all 0.3s ease;
}

.notification-item:hover .notification-icon {
    background: rgba(0, 180, 216, 0.2);
    transform: scale(1.05);
}

.notification-content {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.notification-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
}

.notification-header h4 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-primary);
    line-height: 1.3;
}

.notification-time {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    font-size: 0.75rem;
    color: var(--text-muted);
    white-space: nowrap;
}

.notification-message {
    margin: 0;
    font-size: 0.9rem;
    color: var(--text-secondary);
    line-height: 1.5;
}

.notification-link {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    margin-top: 0.5rem;
    font-size: 0.85rem;
    color: #00B4D8;
    text-decoration: none;
    font-weight: 500;
    transition: all 0.2s ease;
}

.notification-link:hover {
    color: #0096b3;
    gap: 0.6rem;
}

/* Notification Actions */
.notification-actions {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    flex-shrink: 0;
}

.btn-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    border: 1px solid var(--border-color);
    background: rgba(0, 0, 0, 0.02);
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
}

.btn-icon:hover {
    background: rgba(0, 180, 216, 0.15);
    border-color: rgba(0, 180, 216, 0.3);
    color: #00B4D8;
    transform: scale(1.1);
}

.btn-icon.btn-delete:hover {
    background: rgba(239, 68, 68, 0.15);
    border-color: rgba(239, 68, 68, 0.3);
    color: #EF4444;
}

/* Empty State */
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 4rem 2rem;
    text-align: center;
    color: var(--text-muted);
}

.empty-state i {
    font-size: 3.5rem;
    margin-bottom: 1.5rem;
    opacity: 0.5;
}

.empty-state h3 {
    margin: 0 0 0.5rem;
    font-size: 1.25rem;
    color: var(--text-secondary);
}

.empty-state p {
    margin: 0;
    font-size: 0.95rem;
}

/* Preferences Section */
.preferences-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 2rem;
}

.pref-section {
    padding: 1.5rem;
    background: rgba(0, 0, 0, 0.02);
    border-radius: 12px;
    border: 1px solid var(--border-color);
}

.pref-section h4 {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin: 0 0 1.25rem;
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-primary);
}

.pref-section h4 i {
    color: #00B4D8;
}

.checkbox-card {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    padding: 1rem;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    margin-bottom: 0.75rem;
    cursor: pointer;
    transition: all 0.2s ease;
}

.checkbox-card:hover {
    border-color: rgba(0, 180, 216, 0.3);
    background: rgba(0, 180, 216, 0.05);
}

.checkbox-card input[type="checkbox"] {
    margin-top: 0.2rem;
    width: 20px;
    height: 20px;
    accent-color: #00B4D8;
    cursor: pointer;
    flex-shrink: 0;
}

.checkbox-content {
    flex: 1;
}

.checkbox-title {
    display: block;
    font-weight: 500;
    color: var(--text-primary);
    margin-bottom: 0.25rem;
    font-size: 0.95rem;
}

.checkbox-desc {
    font-size: 0.85rem;
    color: var(--text-muted);
    line-height: 1.4;
}

/* Responsive Design */
@media (max-width: 768px) {
    .notification-item {
        flex-direction: column;
        padding: 1rem;
    }
    
    .notification-icon {
        width: 40px;
        height: 40px;
        font-size: 1rem;
    }
    
    .notification-actions {
        flex-direction: row;
        margin-top: 0.75rem;
        padding-top: 0.75rem;
        border-top: 1px solid var(--border-color);
    }
    
    .notification-header {
        flex-direction: column;
        gap: 0.25rem;
    }
    
    .notification-filters {
        overflow-x: auto;
        flex-wrap: nowrap;
        padding-bottom: 0.75rem;
        -webkit-overflow-scrolling: touch;
    }
    
    .filter-btn {
        flex-shrink: 0;
    }
    
    .preferences-grid {
        grid-template-columns: 1fr;
    }
    
    .toast-notification {
        left: 10px;
        right: 10px;
        bottom: 10px;
        max-width: none;
    }
}

@media (max-width: 480px) {
    .stat-card {
        padding: 0.75rem;
    }
    
    .stat-icon {
        width: 40px;
        height: 40px;
        font-size: 1rem;
    }
    
    .stat-number {
        font-size: 1.25rem;
    }
    
    .filter-btn {
        padding: 0.5rem 0.75rem;
        font-size: 0.8rem;
    }
}
</style>

</div> <!-- Fin #page-content -->

<?php
// Fonction utilitaire pour afficher le temps écoulé
function timeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) return 'À l\'instant';
    if ($diff < 3600) return 'Il y a ' . floor($diff / 60) . ' min';
    if ($diff < 86400) return 'Il y a ' . floor($diff / 3600) . ' h';
    if ($diff < 604800) return 'Il y a ' . floor($diff / 86400) . ' j';
    
    return date('d/m/Y à H:i', $time);
}
?>
