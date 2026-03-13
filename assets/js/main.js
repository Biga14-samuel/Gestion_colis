/**
 * =====================================================
 * GESTION_COLIS - INTERFACES UTILISATEUR
 * Interactions et fonctionnalités UI
 * =====================================================
 */

// Variables globales
let currentUser = null;
let notifications = [];

// =====================================================
// INITIALISATION
// =====================================================

document.addEventListener('DOMContentLoaded', () => {
    initSidebar();
    initSearch();
    initDropdowns();
    initModals();
    initForms();
    initPasswordToggles();
    loadUserData();
    
    console.log('%c🚀 Gestion_Colis - Interface Chargée', 'color: #00B4D8; font-size: 14px; font-weight: bold;');
});

// =====================================================
// SIDEBAR COLLAPSE
// =====================================================

function initSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const toggleBtn = document.getElementById('sidebar-toggle');
    
    if (toggleBtn) {
        toggleBtn.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            document.querySelector('.main-content').classList.toggle('expanded');
            
            // Sauvegarder l'état dans localStorage
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
        });
    }
    
    // Restaurer l'état de la sidebar
    const savedState = localStorage.getItem('sidebarCollapsed');
    if (savedState === 'true') {
        sidebar.classList.add('collapsed');
        document.querySelector('.main-content')?.classList.add('expanded');
    }
}

// =====================================================
// RECHERCHE GLOBALE
// =====================================================

function initSearch() {
    const searchInput = document.getElementById('global-search');
    const searchResults = document.getElementById('search-results');
    
    if (!searchInput || !searchResults) return;
    
    let debounceTimer;
    
    searchInput.addEventListener('input', (e) => {
        clearTimeout(debounceTimer);
        const query = e.target.value.trim();
        
        if (query.length < 2) {
            searchResults.classList.remove('active');
            return;
        }
        
        debounceTimer = setTimeout(() => {
            performSearch(query);
        }, 300);
    });
    
    searchInput.addEventListener('focus', () => {
        if (searchInput.value.trim().length >= 2) {
            searchResults.classList.add('active');
        }
    });
    
    // Fermer les résultats lors d'un clic extérieur
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.search-box')) {
            searchResults.classList.remove('active');
        }
    });
}

async function performSearch(query) {
    const searchResults = document.getElementById('search-results');
    if (!searchResults) return;
    
    // Recherche simple sans API - afficher un message
    searchResults.innerHTML = `
        <div class="search-result-item">
            <div style="text-align: center; color: var(--text-muted); padding: 1rem;">
                <i class="fas fa-search" style="font-size: 1.5rem; margin-bottom: 0.5rem; color: var(--tech-cyan);"></i>
                <div>Recherche: ${query}</div>
                <div style="font-size: 0.8rem; margin-top: 0.5rem;">Utilisez la navigation pour trouver vos colis</div>
            </div>
        </div>
    `;
    searchResults.classList.add('active');
}

function showDetails(type, id) {
    switch(type) {
        case 'colis':
            navigateTo('tracking', `code=${id}`);
            break;
        case 'utilisateur':
            navigateTo('gestion_utilisateurs', `id=${id}`);
            break;
        case 'ibox':
            navigateTo('mes_ibox', `id=${id}`);
            break;
    }
    
    const searchResults = document.getElementById('search-results');
    if (searchResults) {
        searchResults.classList.remove('active');
    }
}

// =====================================================
// MENUS DÉROULANTS
// =====================================================

function initDropdowns() {
    const dropdownToggles = document.querySelectorAll('.user-dropdown > .user-profile');
    
    dropdownToggles.forEach(toggle => {
        toggle.addEventListener('click', (e) => {
            e.stopPropagation();
            const dropdown = toggle.parentElement;
            dropdown.classList.toggle('active');
        });
    });
    
    // Fermer les dropdowns lors d'un clic extérieur
    document.addEventListener('click', () => {
        document.querySelectorAll('.dropdown-menu.active').forEach(menu => {
            menu.classList.remove('active');
        });
    });
}

// =====================================================
// MODALES
// =====================================================

function initModals() {
    const modalTriggers = document.querySelectorAll('[data-modal]');
    const modalCloseBtns = document.querySelectorAll('.modal-close, .modal-overlay');
    
    modalTriggers.forEach(trigger => {
        trigger.addEventListener('click', () => {
            const modalId = trigger.dataset.modal;
            openModal(modalId);
        });
    });
    
    modalCloseBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const modal = btn.closest('.modal-overlay');
            if (modal) {
                closeModal(modal);
            }
        });
    });
    
    // Fermer avec Échap
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeAllModals();
        }
    });
}

function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        // Créer ou activer le backdrop
        let backdrop = document.getElementById('modalBackdrop');
        if (!backdrop) {
            backdrop = document.createElement('div');
            backdrop.id = 'modalBackdrop';
            backdrop.className = 'modal-backdrop';
            backdrop.onclick = function() { closeAllModals(); };
            document.body.appendChild(backdrop);
        }
        backdrop.classList.add('active');
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalOrId) {
    let modal;
    if (typeof modalOrId === 'string') {
        modal = document.getElementById(modalOrId);
    } else {
        modal = modalOrId;
    }
    
    if (modal) {
        modal.classList.remove('active');
        
        // Fermer le backdrop si aucun modal n'est actif
        const activeModals = document.querySelectorAll('.modal.active, .modal-overlay.active');
        if (activeModals.length === 0) {
            const backdrop = document.getElementById('modalBackdrop');
            if (backdrop) backdrop.classList.remove('active');
            document.body.style.overflow = '';
        }
    }
}

function closeAllModals() {
    document.querySelectorAll('.modal.active').forEach(modal => {
        modal.classList.remove('active');
    });
    document.querySelectorAll('.modal-overlay.active').forEach(overlay => {
        overlay.classList.remove('active');
    });
    const backdrop = document.getElementById('modalBackdrop');
    if (backdrop) backdrop.classList.remove('active');
    document.body.style.overflow = '';
}

// =====================================================
// FORMULAIRES
// =====================================================

function initForms() {
    const forms = document.querySelectorAll('form[data-ajax]');
    
    forms.forEach(form => {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            await submitForm(form);
        });
    });
}

function getCsrfToken() {
    if (window.__CSRF_TOKEN) return window.__CSRF_TOKEN;
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) return meta.content;
    const input = document.querySelector('input[name="csrf_token"]');
    return input ? input.value : '';
}

function setCsrfToken(token) {
    if (!token) return;
    window.__CSRF_TOKEN = token;
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) meta.content = token;
    document.querySelectorAll('input[name="csrf_token"]').forEach(input => {
        input.value = token;
    });
}

(function registerCsrfFetchWrapper() {
    if (!window.fetch) return;
    const originalFetch = window.fetch.bind(window);
    window.fetch = async (input, init = {}) => {
        const options = init || {};
        const method = (options.method || 'GET').toUpperCase();
        if (method === 'POST') {
            const headers = new Headers(options.headers || {});
            const token = getCsrfToken();
            if (token) {
                headers.set('X-CSRF-Token', token);
            }
            options.headers = headers;
        }
        const response = await originalFetch(input, options);
        const newToken = response.headers.get('X-CSRF-Token');
        if (newToken) setCsrfToken(newToken);
        return response;
    };
})();

async function submitForm(form) {
    const formData = new FormData(form);
    const action = form.dataset.action || form.action;
    const csrfToken = getCsrfToken();
    if (csrfToken && !formData.has('csrf_token')) {
        formData.append('csrf_token', csrfToken);
    }
    
    showLoading();
    
    try {
        const headers = { 'X-Requested-With': 'XMLHttpRequest' };
        if (csrfToken) {
            headers['X-CSRF-Token'] = csrfToken;
        }

        const response = await fetch(action, {
            method: 'POST',
            body: formData,
            headers
        });
        
        const data = await response.json();
        
        if (data.success) {
            showNotification(data.message, 'success');
            
            if (data.redirect) {
                setTimeout(() => {
                    window.location.href = data.redirect;
                }, 1500);
            }
            
            if (data.reset) {
                form.reset();
            }
        } else {
            showNotification(data.message || 'Erreur lors de la soumission', 'error');
        }
    } catch (error) {
        console.error('Erreur de soumission:', error);
        showNotification('Erreur de connexion au serveur', 'error');
    } finally {
        hideLoading();
    }
}

// =====================================================
// MOT DE PASSE TOGGLE
// =====================================================

function initPasswordToggles() {
    const toggles = document.querySelectorAll('.toggle-password');
    
    toggles.forEach(toggle => {
        toggle.addEventListener('click', () => {
            const input = toggle.parentElement.querySelector('input[type="password"], input[type="text"]');
            const icon = toggle.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fas fa-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'fas fa-eye';
            }
        });
    });
}

// =====================================================
// DONNÉES UTILISATEUR
// =====================================================

async function loadUserData() {
    // Les données utilisateur sont déjà chargées par PHP
    // Aucune action supplémentaire nécessaire
    console.log('Données utilisateur chargées via PHP');
}

function updateUserDisplay(user) {
    // Mettre à jour l'avatar
    const userAvatars = document.querySelectorAll('.user-avatar');
    userAvatars.forEach(avatar => {
        const initials = user.prenom.charAt(0) + user.nom.charAt(0);
        avatar.textContent = initials.toUpperCase();
    });
    
    // Mettre à jour le nom
    const userNames = document.querySelectorAll('.user-name');
    userNames.forEach(name => {
        name.textContent = `${user.prenom} ${user.nom}`;
    });
    
    // Mettre à jour le rôle
    const userRoles = document.querySelectorAll('.user-role');
    userRoles.forEach(role => {
        role.textContent = user.role;
    });
}

// =====================================================
// NOTIFICATIONS
// =====================================================

function showNotification(message, type = 'info') {
    // Créer le conteneur de notifications si nécessaire
    let container = document.getElementById('notification-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'notification-container';
        container.style.cssText = `
            position: fixed;
            top: 100px;
            right: 20px;
            z-index: 4000;
            display: flex;
            flex-direction: column;
            gap: 10px;
        `;
        document.body.appendChild(container);
    }
    
    // Créer la notification
    const notification = document.createElement('div');
    notification.className = `alert alert-${type}`;
    notification.style.cssText = `
        min-width: 300px;
        max-width: 400px;
        animation: slideInRight 0.3s ease;
    `;
    
    const icon = type === 'success' ? 'fa-check-circle' : 
                 type === 'error' ? 'fa-exclamation-triangle' : 
                 type === 'warning' ? 'fa-exclamation-circle' : 'fa-info-circle';
    
    notification.innerHTML = `
        <i class="fas ${icon}"></i>
        <span>${message}</span>
        <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit; cursor: pointer;">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    container.appendChild(notification);
    
    // Auto-suppression après 5 secondes
    setTimeout(() => {
        notification.style.animation = 'fadeOut 0.3s ease';
        setTimeout(() => notification.remove(), 300);
    }, 5000);
}

// =====================================================
// LOADING
// =====================================================

let loadingCounter = 0;

function ensureLoadingOverlay() {
    let overlay = document.getElementById('loading-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'loading-overlay';
        overlay.className = 'loading-overlay';
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'polite');
        overlay.innerHTML = `
            <div class="spinner"></div>
            <div class="loading-text">Chargement...</div>
        `;
        document.body.appendChild(overlay);
    } else if (!overlay.classList.contains('loading-overlay')) {
        overlay.classList.add('loading-overlay');
    }
    return overlay;
}

function showLoading() {
    loadingCounter += 1;
    const overlay = ensureLoadingOverlay();
    overlay.classList.add('active');
}

function hideLoading() {
    loadingCounter = Math.max(loadingCounter - 1, 0);
    const overlay = document.getElementById('loading-overlay');
    if (overlay && loadingCounter === 0) {
        overlay.classList.remove('active');
    }
}

// Intercepter fetch pour afficher un loader sur les appels AJAX
(function attachFetchLoader() {
    if (!window.fetch) return;
    const originalFetch = window.fetch.bind(window);

    function hasNoLoadingHeader(headers) {
        if (!headers) return false;
        if (headers instanceof Headers) {
            return headers.get('X-No-Loading') || headers.get('x-no-loading');
        }
        if (Array.isArray(headers)) {
            return headers.some(([key]) => String(key).toLowerCase() === 'x-no-loading');
        }
        return headers['X-No-Loading'] || headers['x-no-loading'];
    }

    window.fetch = async function wrappedFetch(...args) {
        const options = args[1] || {};
        const skipLoading = options.skipLoading || hasNoLoadingHeader(options.headers);

        if (!skipLoading) {
            showLoading();
        }

        try {
            return await originalFetch(...args);
        } finally {
            if (!skipLoading) {
                hideLoading();
            }
        }
    };
})();

// =====================================================
// CONFIRMATION
// =====================================================

function confirmAction(message, callback) {
    if (confirm(message)) {
        callback();
    }
}

function showConfirmModal(title, message, confirmText, cancelText, onConfirm) {
    const modalHTML = `
        <div class="modal-overlay active" id="confirm-modal">
            <div class="modal" style="max-width: 400px;">
                <div class="modal-header">
                    <h3 class="modal-title">
                        <i class="fas fa-exclamation-triangle" style="color: var(--warning);"></i>
                        ${title}
                    </h3>
                    <button class="modal-close" onclick="closeModal(document.getElementById('confirm-modal'))">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <p>${message}</p>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" onclick="closeModal(document.getElementById('confirm-modal'))">
                        ${cancelText || 'Annuler'}
                    </button>
                    <button class="btn btn-danger" id="confirm-btn">
                        ${confirmText || 'Confirmer'}
                    </button>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    
    document.getElementById('confirm-btn').addEventListener('click', () => {
        onConfirm();
        closeModal(document.getElementById('confirm-modal'));
    });
}

// =====================================================
// UTILITAIRES
// =====================================================

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'XOF',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(amount);
}

function truncateText(text, maxLength) {
    if (text.length <= maxLength) return text;
    return text.substring(0, maxLength) + '...';
}

function generateTrackingCode() {
    return 'TRK' + new Date().toISOString().slice(0, 10).replace(/-/g, '') + 
           Math.random().toString(36).substr(2, 6).toUpperCase();
}

// =====================================================
// NAVIGATION - Fonction navigateTo
// =====================================================

/**
 * Navigate to a specific page with optional query parameters
 * @param {string} page - The page to navigate to
 * @param {string} params - Optional query parameters
 */
function navigateTo(page, params = '') {
    if (params) {
        loadPage(page + '?' + params, page.charAt(0).toUpperCase() + page.slice(1).replace('.php', '').replace('_', ' '));
    } else {
        loadPage(page, page.charAt(0).toUpperCase() + page.slice(1).replace('.php', '').replace('_', ' '));
    }
}

// =====================================================
// SYSTÈME SPA - NAVIGATION DYNAMIQUE
// =====================================================

/**
 * Charge une page dynamiquement via AJAX (SPA)
 * @param {string} pageUrl - L'URL de la page à charger
 * @param {string} pageTitle - Le titre de la page
 * @param {boolean} updateHistory - Faut-il mettre à jour l'URL du navigateur
 */
window.loadPage = async function(pageUrl, pageTitle = 'Gestion Colis', updateHistory = true) {
    const contentContainer = document.getElementById('page-content');
    const sidebar = document.querySelector('.sidebar');
    
    if (!contentContainer) {
        console.error('Erreur critique: Conteneur #page-content introuvable.');
        showNotification('Erreur de structure: conteneur de page manquant', 'error');
        return;
    }
    
    // Fermer la sidebar mobile lors du chargement d'une nouvelle page
    if (window.innerWidth <= 1024 && sidebar) {
        sidebar.classList.remove('open');
        const overlay = document.querySelector('.sidebar-overlay');
        if (overlay) overlay.classList.remove('active');
        document.body.style.overflow = '';
    }
    
    // Afficher le loader
    showLoading();
    
    try {
        // Récupérer la page via fetch
        const response = await fetch(pageUrl, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        if (!response.ok) {
            throw new Error('Erreur de chargement: ' + response.status);
        }
        
        const html = await response.text();
        
        // Parser le HTML retourné
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        
        // Vérifier les erreurs de parsing
        const parseError = doc.querySelector('parsererror');
        if (parseError) {
            throw new Error('Erreur de parsing HTML');
        }
        
        // Extraire le contenu de la zone principale
        const newContent = doc.querySelector('#page-content');
        
        if (!newContent) {
            throw new Error('Structure de page invalide: Balise #page-content manquante dans ' + pageUrl);
        }
        
        // Mettre à jour le titre de la page si fourni
        const pageTitleEl = document.getElementById('page-title');
        if (pageTitle && pageTitleEl) {
            pageTitleEl.textContent = pageTitle;
        }
        
        // Effacer le contenu actuel avec animation
        contentContainer.style.opacity = '0';
        contentContainer.style.transform = 'translateY(-10px)';
        
        // Attendre que l'animation de sortie soit terminée
        setTimeout(() => {
            // Sauvegarder le contenu actuel avant de remplacer
            const oldScripts = contentContainer.querySelectorAll('script');
            
            // Remplacer le contenu
            contentContainer.innerHTML = newContent.innerHTML;
            
            // Ajouter l'animation d'entrée
            contentContainer.style.opacity = '1';
            contentContainer.style.transform = 'translateY(0)';
            
            // EXTRAIRE ET EXÉCUTER LES SCRIPTS DE LA PAGE CHARGÉE
            executePageScripts(newContent);
            
            // Recharger les scripts de la page (pour les composants globaux)
            reloadPageScripts();
            
            // Mettre à jour l'historique du navigateur
            if (updateHistory) {
                try {
                    window.history.pushState({ path: pageUrl }, '', pageUrl);
                } catch (e) {
                    console.warn('Impossible de mettre à jour l\'historique:', e);
                }
            }
            
            // Masquer le loader
            hideLoading();
            
            // Supprimer la classe d'animation après qu'elle soit terminée
            setTimeout(() => {
                contentContainer.classList.remove('page-fade-in');
            }, 300);
            
        }, 150);
    } catch (error) {
        console.error('Erreur loadPage:', error);
        hideLoading();
        
        // Afficher l'erreur dans le conteneur
        contentContainer.innerHTML = `
            <div class="page-container" style="padding: 2rem;">
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <h4 style="margin-bottom: 0.5rem;">Erreur de chargement</h4>
                        <p>${error.message}</p>
                        <button class="btn btn-primary" onclick="loadPage('${pageUrl}', '${pageTitle}')" style="margin-top: 1rem;">
                            <i class="fas fa-redo"></i> Réessayer
                        </button>
                    </div>
                </div>
            </div>
        `;
        contentContainer.style.opacity = '1';
        contentContainer.style.transform = 'translateY(0)';
    }
};

// Export pour utilisation dans d'autres scripts (APRÈS loadPage)
window.GestionColis = {
    showNotification,
    showLoading,
    hideLoading,
    openModal,
    closeModal,
    closeAllModals,
    confirmAction,
    formatDate,
    formatCurrency,
    truncateText,
    loadPage: window.loadPage
};

/**
 * Exécute les scripts JavaScript d'une page chargée dynamiquement
 * Cette fonction est nécessaire car les scripts dans innerHTML ne sont pas exécutés automatiquement
 */
function executePageScripts(contentElement) {
    const scripts = contentElement.querySelectorAll('script');
    
    scripts.forEach(script => {
        if (script.src) {
            // Script externe - charger et exécuter
            const newScript = document.createElement('script');
            newScript.src = script.src;
            newScript.async = false;
            document.head.appendChild(newScript);
        } else if (script.textContent) {
            // Script inline - exécuter avec Function constructor
            try {
                // La clé est d'utiliser "new Function" avec un contexte global
                // Cela garantit que les fonctions sont disponibles globalement
                const scriptText = script.textContent;
                
                // Exécuter le script dans le contexte global window
                // Enveloppons dans une IIFE auto-exécutante pour éviter les conflits de variables
                const executeScript = new Function('window', 'document', 'console', scriptText);
                executeScript(window, document, console);
                
            } catch (error) {
                console.error('Erreur lors de l\'exécution du script:', error);
            }
        }
    });
    
    if (scripts.length > 0) {
        console.log(`✅ ${scripts.length} script(s) exécuté(s)`);
    }
}

/**
 * Recharge les scripts d'une page après chargement AJAX
 */
function reloadPageScripts() {
    // Réinitialiser les événements des formulaires
    initForms();
    
    // Réinitialiser les modales
    initModals();
    
    // Réinitialiser les toggles de mot de passe
    initPasswordToggles();
    
    // Initialiser le pad de signature s'il existe sur la page
    initSignaturePad();
    
    console.log('✅ Scripts de la page rechargés');
}

// =====================================================
// PAD DE SIGNATURE - FONCTIONS GLOBALES AMÉLIORÉES
// =====================================================

// Variables globales pour le canvas de signature
let signatureCanvas = null;
let signatureCtx = null;
let isDrawing = false;
let lastX = 0;
let lastY = 0;
let resizeObserver = null;

// Initialiser le pad de signature avec support complet tactile et responsive
function initSignaturePad() {
    signatureCanvas = document.getElementById('signature-pad');
    
    if (!signatureCanvas) {
        console.log('ℹ️ Pas de canvas de signature sur cette page');
        return false;
    }
    
    console.log('✅ Canvas trouvé, initialisation du pad de signature...');
    
    // Obtenir le contexte 2D
    signatureCtx = signatureCanvas.getContext('2d');
    
    // Fonction pour configurer les dimensions du canvas
    function setupCanvasDimensions() {
        const rect = signatureCanvas.getBoundingClientRect();
        const containerWidth = signatureCanvas.parentElement?.clientWidth || rect.width || 400;
        
        // Utiliser les dimensions du conteneur pour un rendu responsive
        const displayWidth = Math.min(containerWidth, 400);
        const displayHeight = 150;
        
        // Définir les dimensions internes (résolution) du canvas
        signatureCanvas.width = displayWidth * window.devicePixelRatio;
        signatureCanvas.height = displayHeight * window.devicePixelRatio;
        
        // Ajuster le style CSS pour l'affichage
        signatureCanvas.style.width = displayWidth + 'px';
        signatureCanvas.style.height = displayHeight + 'px';
        
        // Mettre à l'échelle le contexte pour dessiner en pixels logiques
        signatureCtx.scale(window.devicePixelRatio, window.devicePixelRatio);
        
        // Reconfigurer le style de dessin
        configureDrawingStyle();
        
        console.log(`📐 Canvas configuré: ${displayWidth}x${displayHeight}px (interne: ${signatureCanvas.width}x${signatureCanvas.height}px)`);
    }
    
    // Configurer le style de dessin
    function configureDrawingStyle() {
        signatureCtx.strokeStyle = '#1E293B';
        signatureCtx.lineWidth = 2.5;
        signatureCtx.lineCap = 'round';
        signatureCtx.lineJoin = 'round';
        signatureCtx.shadowColor = 'rgba(0, 0, 0, 0.1)';
        signatureCtx.shadowBlur = 1;
    }
    
    // Initialiser les dimensions
    setupCanvasDimensions();
    
    // Réinitialiser les variables
    isDrawing = false;
    lastX = 0;
    lastY = 0;
    
    // Utiliser ResizeObserver pour ajuster automatiquement les dimensions
    if (typeof ResizeObserver !== 'undefined') {
        if (resizeObserver) {
            resizeObserver.disconnect();
        }
        resizeObserver = new ResizeObserver(() => {
            // Ne pas redimensionner pendant le dessin
            if (!isDrawing) {
                setupCanvasDimensions();
            }
        });
        resizeObserver.observe(signatureCanvas.parentElement);
    }
    
    // Supprimer les anciens écouteurs pour éviter les doublons
    signatureCanvas.onmousedown = null;
    signatureCanvas.onmousemove = null;
    signatureCanvas.onmouseup = null;
    signatureCanvas.onmouseout = null;
    signatureCanvas.ontouchstart = null;
    signatureCanvas.ontouchmove = null;
    signatureCanvas.ontouchend = null;
    
    // Ajouter les écouteurs Souris
    signatureCanvas.addEventListener('mousedown', handleMouseDown, { passive: false });
    signatureCanvas.addEventListener('mousemove', handleMouseMove, { passive: false });
    signatureCanvas.addEventListener('mouseup', handleMouseUp, { passive: false });
    signatureCanvas.addEventListener('mouseout', handleMouseOut, { passive: false });
    
    // Ajouter les écouteurs Tactiles avec support complet
    signatureCanvas.addEventListener('touchstart', handleTouchStart, { passive: false });
    signatureCanvas.addEventListener('touchmove', handleTouchMove, { passive: false });
    signatureCanvas.addEventListener('touchend', handleTouchEnd, { passive: false });
    signatureCanvas.addEventListener('touchcancel', handleTouchEnd, { passive: false });
    
    // Empêcher le scroll sur le canvas
    signatureCanvas.style.touchAction = 'none';
    signatureCanvas.style.webkitTouchCallout = 'none';
    
    console.log('🎉 Pad de signature initialisé avec succès!');
    return true;
}

// Obtenir la position de la souris par rapport au canvas
function getMousePos(e) {
    const rect = signatureCanvas.getBoundingClientRect();
    const scaleX = signatureCanvas.width / rect.width / window.devicePixelRatio;
    const scaleY = signatureCanvas.height / rect.height / window.devicePixelRatio;
    return {
        x: (e.clientX - rect.left) * scaleX,
        y: (e.clientY - rect.top) * scaleY
    };
}

// Obtenir la position tactile par rapport au canvas
function getTouchPos(e) {
    const rect = signatureCanvas.getBoundingClientRect();
    const touch = e.touches[0] || e.changedTouches[0];
    const scaleX = signatureCanvas.width / rect.width / window.devicePixelRatio;
    const scaleY = signatureCanvas.height / rect.height / window.devicePixelRatio;
    return {
        x: (touch.clientX - rect.left) * scaleX,
        y: (touch.clientY - rect.top) * scaleY
    };
}

// Dessiner un point de départ
function drawStartPoint(x, y) {
    signatureCtx.beginPath();
    signatureCtx.arc(x, y, 3, 0, Math.PI * 2);
    signatureCtx.fillStyle = '#1E293B';
    signatureCtx.fill();
}

// Gestionnaires d'événements Souris
function handleMouseDown(e) {
    e.preventDefault();
    isDrawing = true;
    const coords = getMousePos(e);
    lastX = coords.x;
    lastY = coords.y;
    drawStartPoint(lastX, lastY);
    
    const signatureStatus = document.getElementById('signature-status');
    if (signatureStatus) {
        signatureStatus.innerHTML = '<i class="fas fa-pen" style="margin-right: 5px; color: #00B4D8;"></i> Dessinez votre signature';
    }
}

function handleMouseMove(e) {
    if (!isDrawing) return;
    e.preventDefault();
    
    const coords = getMousePos(e);
    
    signatureCtx.beginPath();
    signatureCtx.moveTo(lastX, lastY);
    signatureCtx.lineTo(coords.x, coords.y);
    signatureCtx.stroke();
    
    lastX = coords.x;
    lastY = coords.y;
}

function handleMouseUp(e) {
    e.preventDefault();
    isDrawing = false;
    
    const signatureStatus = document.getElementById('signature-status');
    if (signatureStatus) {
        signatureStatus.innerHTML = '<i class="fas fa-check-circle" style="margin-right: 5px; color: #22c55e;"></i> Signature enregistrée';
    }
    
    // Mettre à jour le statut visuel
    const signatureContainer = document.querySelector('.signature-container');
    if (signatureContainer) {
        signatureContainer.style.borderColor = '#22c55e';
    }
    
    console.log('✅ Signature terminée');
}

function handleMouseOut(e) {
    e.preventDefault();
    isDrawing = false;
}

// Gestionnaires d'événements Tactiles
function handleTouchStart(e) {
    e.preventDefault();
    e.stopPropagation();
    
    if (e.touches.length === 0) return;
    
    isDrawing = true;
    const coords = getTouchPos(e);
    lastX = coords.x;
    lastY = coords.y;
    drawStartPoint(lastX, lastY);
    
    const signatureStatus = document.getElementById('signature-status');
    if (signatureStatus) {
        signatureStatus.innerHTML = '<i class="fas fa-pen" style="margin-right: 5px; color: #00B4D8;"></i> Dessinez votre signature';
    }
}

function handleTouchMove(e) {
    if (!isDrawing) return;
    e.preventDefault();
    e.stopPropagation();
    
    if (e.touches.length === 0) return;
    
    const coords = getTouchPos(e);
    
    signatureCtx.beginPath();
    signatureCtx.moveTo(lastX, lastY);
    signatureCtx.lineTo(coords.x, coords.y);
    signatureCtx.stroke();
    
    lastX = coords.x;
    lastY = coords.y;
}

function handleTouchEnd(e) {
    e.preventDefault();
    e.stopPropagation();
    isDrawing = false;
    
    const signatureStatus = document.getElementById('signature-status');
    if (signatureStatus) {
        signatureStatus.innerHTML = '<i class="fas fa-check-circle" style="margin-right: 5px; color: #22c55e;"></i> Signature enregistrée';
    }
    
    // Mettre à jour le statut visuel
    const signatureContainer = document.querySelector('.signature-container');
    if (signatureContainer) {
        signatureContainer.style.borderColor = '#22c55e';
    }
}

// Effacer la signature - cette fonction doit être disponible globalement pour le bouton
window.clearSignature = function() {
    if (!signatureCanvas) {
        signatureCanvas = document.getElementById('signature-pad');
    }
    if (!signatureCanvas) return;
    
    const ctx = signatureCanvas.getContext('2d');
    ctx.clearRect(0, 0, signatureCanvas.width, signatureCanvas.height);
    
    const signatureDataInput = document.getElementById('signature_data');
    if (signatureDataInput) {
        signatureDataInput.value = '';
    }
    
    // Réinitialiser le statut visuel
    const signatureContainer = document.querySelector('.signature-container');
    const signatureStatus = document.getElementById('signature-status');
    const signatureWarning = document.getElementById('signature-warning');
    
    if (signatureContainer) {
        signatureContainer.style.borderColor = 'var(--border-light)';
    }
    if (signatureStatus) {
        signatureStatus.innerHTML = '<i class="fas fa-info-circle" style="margin-right: 5px;"></i> Signez avec la souris ou le doigt';
    }
    if (signatureWarning) {
        signatureWarning.style.display = 'none';
    }
    
    // Réinitialiser les variables de dessin
    isDrawing = false;
    lastX = 0;
    lastY = 0;
    
    console.log('🗑️ Signature effacée');
};

// Vérifier si le canvas est vide
window.checkCanvasBlank = function() {
    if (!signatureCanvas) {
        signatureCanvas = document.getElementById('signature-pad');
    }
    if (!signatureCanvas) return true;
    
    // Méthode plus robuste : vérifier le contenu du canvas
    try {
        const ctx = signatureCanvas.getContext('2d');
        const imageData = ctx.getImageData(0, 0, signatureCanvas.width, signatureCanvas.height);
        const data = imageData.data;
        
        // Compter les pixels non-transparents
        let nonTransparentPixels = 0;
        const step = 4 * window.devicePixelRatio; // Échantillonner tous les pixels à l'échelle
        
        for (let i = 3; i < data.length; i += step) {
            if (data[i] > 50) { // Seuil de détection
                nonTransparentPixels++;
            }
        }
        
        // Retourner true (vide) si moins de 10 pixels détectés
        return nonTransparentPixels < 10;
    } catch (e) {
        console.warn('Erreur lors de la vérification du canvas:', e);
        return true;
    }
};

// Gestionnaire d'historique (Boutons Précédent/Suivant du navigateur)
window.addEventListener('popstate', (event) => {
    if (event.state && event.state.path) {
        loadPage(event.state.path, 'Gestion Colis', false);
    } else {
        location.reload();
    }
});

/**
 * Ferme la sidebar mobile
 */
function closeMobileSidebar() {
    const sidebar = document.querySelector('.sidebar');
    if (sidebar && window.innerWidth <= 1024) {
        sidebar.classList.remove('open');
        const overlay = document.querySelector('.sidebar-overlay');
        overlay?.classList.remove('active');
        document.body.style.overflow = '';
    }
}

// Initialiser les liens de navigation pour fermer la sidebar mobile
document.addEventListener('DOMContentLoaded', () => {
    // Ajouter les écouteurs pour fermer la sidebar lors du clic sur les liens
    document.querySelectorAll('.sidebar .nav-link, .sidebar a[href]').forEach(link => {
        link.addEventListener('click', () => {
            // Fermer la sidebar mobile après un court délai pour permettre la navigation
            setTimeout(closeMobileSidebar, 100);
        });
    });
    
    // Masquer le loader après le chargement initial
    setTimeout(() => {
        hideLoading();
    }, 500);
    
    console.log('%c🚀 Gestion_Colis - Navigation SPA Initialisée', 'color: #00B4D8; font-size: 14px; font-weight: bold;');
});
