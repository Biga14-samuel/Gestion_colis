<?php
/**
 * =====================================================
 * LISTE DES COLIS - CORRIGÉ
 * Ajout de la barre de recherche, PDF download réel, CSS compact
 * =====================================================
 */

// Verification de la connexion
require_once __DIR__ . '/../../utils/session.php';
SessionManager::start();
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="access-denied">Accès refusé. Veuillez vous connecter.</div>';
    exit;
}

require_once '../../config/database.php';

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'];
$message = '';
$messageType = '';

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        switch ($action) {
            case 'annuler':
                $colisId = (int)($_POST['colis_id'] ?? 0);
                
                if ($colisId <= 0) {
                    $message = 'ID de colis invalide.';
                    $messageType = 'error';
                } else {
                    // Verifier que le colis appartient a l'utilisateur
                    $stmt = $db->prepare("SELECT id, statut FROM colis WHERE id = ? AND utilisateur_id = ?");
                    $stmt->execute([$colisId, $userId]);
                    $colis = $stmt->fetch();
                    
                    if (!$colis) {
                        $message = 'Colis non trouvé ou vous n\'avez pas l\'autorisation.';
                        $messageType = 'error';
                    } elseif (!in_array($colis['statut'], ['en_attente'])) {
                        $message = 'Ce colis ne peut plus être annulé.';
                        $messageType = 'error';
                    } else {
                        // Supprimer les notifications liees
                        $stmt = $db->prepare("DELETE FROM notifications WHERE type = 'colis' AND message LIKE ?");
                        $stmt->execute(['%colis #' . $colisId . '%']);
                        
                        // Supprimer le colis directement de la base de données
                        $stmt = $db->prepare("DELETE FROM colis WHERE id = ?");
                        $stmt->execute([$colisId]);
                        
                        $message = 'Colis supprimé avec succès.';
                        $messageType = 'success';
                    }
                }
                break;
        }
    } catch (PDOException $e) {
        $message = user_error_message($e, 'mes_colis.action', 'Erreur de base de données.');
        $messageType = 'error';
    }
}

// Recuperation des colis
try {
    $database = new Database();
    $db = $database->getConnection();
    
    if ($userRole === 'admin') {
        $stmt = $db->query("
            SELECT c.*, u.nom as user_nom, u.prenom as user_prenom
            FROM colis c
            LEFT JOIN utilisateurs u ON c.utilisateur_id = u.id
            ORDER BY c.date_creation DESC
        ");
    } else {
        $stmt = $db->prepare("
            SELECT c.*, u.nom as user_nom, u.prenom as user_prenom
            FROM colis c
            LEFT JOIN utilisateurs u ON c.utilisateur_id = u.id
            WHERE c.utilisateur_id = ?
            ORDER BY c.date_creation DESC
        ");
        $stmt->execute([$userId]);
    }
    
    $colis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Statistiques
    if ($userRole === 'admin') {
        $stmt = $db->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN statut = 'livre' THEN 1 ELSE 0 END) as livres,
                SUM(CASE WHEN statut IN ('en_livraison', 'en_attente') THEN 1 ELSE 0 END) as en_cours,
                SUM(CASE WHEN statut = 'annule' THEN 1 ELSE 0 END) as annules
            FROM colis
        ");
    } else {
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN statut = 'livre' THEN 1 ELSE 0 END) as livres,
                SUM(CASE WHEN statut IN ('en_livraison', 'en_attente') THEN 1 ELSE 0 END) as en_cours,
                SUM(CASE WHEN statut = 'annule' THEN 1 ELSE 0 END) as annules
            FROM colis
            WHERE utilisateur_id = ?
        ");
        $stmt->execute([$userId]);
    }
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $colis = [];
    $stats = ['total' => 0, 'livres' => 0, 'en_cours' => 0, 'annules' => 0];
    $message = user_error_message($e, 'mes_colis.fetch', 'Erreur lors de la récupération des données.');
    $messageType = 'error';
}

$statusLabels = [
    'en_attente' => 'En attente',
    'en_livraison' => 'En livraison',
    'livre' => 'Livré',
    'annule' => 'Annulé',
    'retourne' => 'En retour'
];

$statusColors = [
    'en_attente' => 'secondary',
    'en_livraison' => 'warning',
    'livre' => 'success',
    'annule' => 'danger',
    'retourne' => 'secondary'
];

$statusProgress = [
    'en_attente' => 20,
    'en_livraison' => 70,
    'livre' => 100,
    'annule' => 0,
    'retourne' => 50
];
?>

<!-- Contenu pour le système SPA -->
<div id="page-content">

<div class="page-container">
    <div class="page-header">
        <h1><i class="fas fa-box"></i> Mes Colis</h1>
        <div class="header-actions">
            <button class="btn btn-primary" onclick="loadPage('creer_colis.php', 'Créer un Colis')">
                <i class="fas fa-plus"></i> Nouveau Colis
            </button>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?>">
            <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Cartes de statistiques compactes -->
    <div class="stats-grid-compact">
        <div class="stat-card-compact">
            <div class="stat-icon-compact bg-primary">
                <i class="fas fa-box"></i>
            </div>
            <div class="stat-info-compact">
                <span class="stat-value-compact"><?= $stats['total'] ?? 0 ?></span>
                <span class="stat-label-compact">Total</span>
            </div>
        </div>
        <div class="stat-card-compact">
            <div class="stat-icon-compact bg-success">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-info-compact">
                <span class="stat-value-compact"><?= $stats['livres'] ?? 0 ?></span>
                <span class="stat-label-compact">Livrés</span>
            </div>
        </div>
        <div class="stat-card-compact">
            <div class="stat-icon-compact bg-warning">
                <i class="fas fa-truck"></i>
            </div>
            <div class="stat-info-compact">
                <span class="stat-value-compact"><?= $stats['en_cours'] ?? 0 ?></span>
                <span class="stat-label-compact">En Cours</span>
            </div>
        </div>
        <div class="stat-card-compact">
            <div class="stat-icon-compact bg-danger">
                <i class="fas fa-times-circle"></i>
            </div>
            <div class="stat-info-compact">
                <span class="stat-value-compact"><?= $stats['annules'] ?? 0 ?></span>
                <span class="stat-label-compact">Annulés</span>
            </div>
        </div>
    </div>

    <!-- Filtres et recherche -->
    <div class="filters-bar-compact">
        <div class="search-box-compact">
            <i class="fas fa-search search-icon-compact"></i>
            <input type="text" id="searchColis" class="search-input-compact" 
                   placeholder="Rechercher un colis..." onkeyup="filterColis()">
        </div>
        <div class="filter-group-compact">
            <select id="filterStatut" class="filter-select-compact" onchange="filterColis()">
                <option value="all">Tous les statuts</option>
                <option value="en_livraison">En livraison</option>
                <option value="livre">Livré</option>
                <option value="annule">Annulé</option>
            </select>
        </div>
        <div class="filter-group-compact">
            <input type="date" id="filterDate" class="filter-date-compact" onchange="filterColis()">
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-list"></i> Liste des Colis</h3>
            <div class="header-info">
                <span class="text-muted"><?= count($colis) ?> colis trouvé(s)</span>
            </div>
        </div>
        <div class="card-body-compact">
            <?php if (empty($colis)): ?>
                <div class="empty-state">
                    <i class="fas fa-box-open fa-3x"></i>
                    <h3>Aucun colis trouvé</h3>
                    <p>Vous n'avez pas encore créé de colis.</p>
                    <button class="btn btn-primary" onclick="loadPage('creer_colis.php', 'Créer un Colis')">
                        <i class="fas fa-plus"></i> Créer votre premier colis
                    </button>
                </div>
            <?php else: ?>
                <!-- Tableau des colis compact -->
                <div class="table-responsive-compact">
                    <table class="data-table-compact" id="colisTable">
                        <thead>
                            <tr>
                                <th onclick="sortTableColis(0)">Code <i class="fas fa-sort"></i></th>
                                <th onclick="sortTableColis(1)">Réf. <i class="fas fa-sort"></i></th>
                                <th>Description</th>
                                <th onclick="sortTableColis(3)">Statut <i class="fas fa-sort"></i></th>
                                <th>Poids</th>
                                <th onclick="sortTableColis(5)">Date <i class="fas fa-sort"></i></th>
                                <th>Agent</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Récupérer les informations des livreurs pour chaque colis
                            foreach ($colis as $c): 
                                // Récupérer l'agent assigné au colis
                                $stmtAgent = $db->prepare("
                                    SELECT u.nom, u.prenom 
                                    FROM livraisons l
                                    JOIN agents ag ON l.agent_id = ag.id
                                    JOIN utilisateurs u ON ag.utilisateur_id = u.id
                                    WHERE l.colis_id = ? AND l.statut != 'annulee'
                                    ORDER BY l.date_assignation DESC
                                    LIMIT 1
                                ");
                                $stmtAgent->execute([$c['id']]);
                                $agent = $stmtAgent->fetch();
                            ?>
                                <tr data-statut="<?= $c['statut'] ?>" 
                                    data-date="<?= date('Y-m-d', strtotime($c['date_creation'])) ?>"
                                    data-search="<?= strtolower($c['code_tracking'] . ' ' . $c['reference_colis'] . ' ' . $c['description']) ?>">
                                    <td class="tracking-cell">
                                        <span class="tracking-code-compact"><?= htmlspecialchars($c['code_tracking'] ?? $c['reference_colis']) ?></span>
                                        <?php if ($c['fragile']): ?>
                                            <span class="badge badge-warning badge-sm" title="Colis fragile">
                                                <i class="fas fa-exclamation-triangle"></i>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($c['urgent']): ?>
                                            <span class="badge badge-danger badge-sm" title="Urgent">
                                                <i class="fas fa-bolt"></i>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="ref-cell"><?= htmlspecialchars($c['reference_colis']) ?></td>
                                    <td class="desc-cell"><?= htmlspecialchars(substr($c['description'] ?? 'Sans description', 0, 25)) ?>...</td>
                                    <td class="status-cell">
                                        <span class="badge badge-<?= $statusColors[$c['statut']] ?? 'secondary' ?> badge-sm">
                                            <?= htmlspecialchars($statusLabels[$c['statut']] ?? ucfirst($c['statut'])) ?>
                                        </span>
                                    </td>
                                    <td class="weight-cell"><?= $c['poids'] ? $c['poids'] . ' kg' : '-' ?></td>
                                    <td class="date-cell"><?= date('d/m', strtotime($c['date_creation'])) ?></td>
                                    <td class="agent-cell">
                                        <?php if ($agent): ?>
                                            <?= htmlspecialchars(substr($agent['prenom'] ?? '', 0, 1) . '. ' . ($agent['nom'] ?? '')) ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="actions-cell-compact">
                                        <button class="btn-icon-compact btn-tracking" 
                                                onclick="openTracking('<?= htmlspecialchars($c['code_tracking'] ?? $c['reference_colis']) ?>')" 
                                                title="Suivre">
                                            <i class="fas fa-search-location"></i>
                                        </button>
                                        
                                        <button class="btn-icon-compact btn-pdf" 
                                                onclick="downloadColisPDF(<?= $c['id'] ?>, '<?= htmlspecialchars($c['code_tracking'] ?? $c['reference_colis']) ?>')" 
                                                title="Télécharger PDF">
                                            <i class="fas fa-file-pdf"></i>
                                        </button>
                                        
                                        <button class="btn-icon-compact btn-print" 
                                                onclick="imprimerColis(<?= $c['id'] ?>)" 
                                                title="Imprimer">
                                            <i class="fas fa-print"></i>
                                        </button>
                                        
                                        <button class="btn-icon-compact btn-share" 
                                                onclick="shareColis(<?= $c['id'] ?>, '<?= htmlspecialchars($c['code_tracking'] ?? $c['reference_colis']) ?>')" 
                                                title="Partager">
                                            <i class="fas fa-share-alt"></i>
                                        </button>
                                        
                                        <?php if (in_array($c['statut'], ['en_attente'])): ?>
                                            <button class="btn-icon-compact btn-edit" 
                                                    onclick="loadPage('views/client/modifier_colis.php?id=<?= $c['id'] ?>', 'Modifier le Colis')" 
                                                    title="Modifier">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            
                                            <button class="btn-icon-compact btn-danger" 
                                                    onclick="confirmAnnulerColis(<?= $c['id'] ?>, '<?= htmlspecialchars($c['code_tracking'] ?? $c['reference_colis']) ?>')" 
                                                    title="Annuler">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Backdrop pour les modals -->
<div id="modalBackdrop" class="modal-backdrop" onclick="closeAllModals()"></div>

<!-- Modal de confirmation d'annulation -->
<div id="annulerModal" class="modal modal-sm">
    <div class="modal-header">
        <h2><i class="fas fa-exclamation-triangle text-warning"></i> Confirmer la suppression</h2>
        <button class="modal-close" onclick="closeModal('annulerModal')">&times;</button>
    </div>
    <div class="modal-body">
        <p>Êtes-vous sûr de vouloir supprimer le colis <strong id="annulerColisNumber"></strong> ?</p>
        <p class="text-danger">
            <i class="fas fa-info-circle"></i>
            Cette action est irréversible. Le colis sera supprimé définitivement de la liste.
        </p>
    </div>
    <form method="POST" id="annulerForm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="annuler">
        <input type="hidden" name="colis_id" id="annulerColisId" value="">
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('annulerModal')">
                <i class="fas fa-times"></i> Non, garder
            </button>
            <button type="submit" class="btn btn-danger">
                <i class="fas fa-trash"></i> Oui, supprimer
            </button>
        </div>
    </form>
</div>

<script>
function filterColis() {
    const searchTerm = document.getElementById('searchColis').value.toLowerCase();
    const statutFilter = document.getElementById('filterStatut').value;
    const dateFilter = document.getElementById('filterDate').value;
    
    const rows = document.querySelectorAll('#colisTable tbody tr');
    let visibleCount = 0;
    
    rows.forEach(row => {
        let show = true;
        
        // Filtre par recherche
        if (searchTerm && !row.dataset.search.includes(searchTerm)) {
            show = false;
        }
        
        // Filtre par statut
        if (statutFilter !== 'all' && row.dataset.statut !== statutFilter) {
            show = false;
        }
        
        // Filtre par date
        if (dateFilter && row.dataset.date !== dateFilter) {
            show = false;
        }
        
        row.style.display = show ? '' : 'none';
        if (show) visibleCount++;
    });
    
    // Mettre à jour le compteur
    document.querySelector('.header-info span').textContent = visibleCount + ' colis trouvé(s)';
}

function sortTableColis(colIndex) {
    const table = document.getElementById('colisTable');
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const headers = table.querySelectorAll('th');
    
    const currentSort = headers[colIndex].getAttribute('data-sort');
    const newSort = currentSort === 'asc' ? 'desc' : 'asc';
    
    headers.forEach(th => {
        th.removeAttribute('data-sort');
        th.querySelector('i').className = 'fas fa-sort';
    });
    
    rows.sort((a, b) => {
        const aText = a.cells[colIndex].textContent.trim();
        const bText = b.cells[colIndex].textContent.trim();
        
        // Essayer de convertir en nombres
        const aNum = parseFloat(aText.replace(/[^0-9,.-]/g, '').replace(',', '.'));
        const bNum = parseFloat(bText.replace(/[^0-9,.-]/g, '').replace(',', '.'));
        
        if (!isNaN(aNum) && !isNaN(bNum)) {
            return newSort === 'asc' ? aNum - bNum : bNum - aNum;
        }
        
        // Sinon, comparer comme texte
        return newSort === 'asc' ? aText.localeCompare(bText) : bText.localeCompare(aText);
    });
    
    headers[colIndex].setAttribute('data-sort', newSort);
    headers[colIndex].querySelector('i').className = newSort === 'asc' ? 'fas fa-sort-up' : 'fas fa-sort-down';
    
    rows.forEach(row => tbody.appendChild(row));
}

function viewColisDetails(colisId) {
    // Ouvrir la page de détails du colis
    loadPage('views/client/colis_details.php?id=' + colisId, 'Détails du Colis');
}

function confirmAnnulerColis(colisId, colisNumber) {
    document.getElementById('annulerColisId').value = colisId;
    document.getElementById('annulerColisNumber').textContent = colisNumber;
    openModal('annulerModal');
}

function openTracking(trackingNumber) {
    // Ouvrir la page de suivi dans le dashboard
    loadPage('tracking.php?code=' + encodeURIComponent(trackingNumber), 'Suivi de Colis');
}

function downloadColisPDF(colisId, trackingNumber) {
    // Télécharger le PDF directement au lieu de l'ouvrir dans un nouvel onglet
    const url = 'colis_pdf.php?id=' + colisId + '&download=1';
    
    // Créer un lien de téléchargement temporaire
    const link = document.createElement('a');
    link.href = url;
    link.download = 'bordereau_' + trackingNumber + '.pdf';
    link.target = '_blank';
    
    // Afficher une notification
    showNotification('Téléchargement du bordereau en cours...', 'info');
    
    // Ouvrir dans une nouvelle fenêtre pour le téléchargement
    window.open(url, '_blank');
}

function downloadFacturePDF(colisId) {
    // Ouvrir le générateur de facture PDF dans une nouvelle fenêtre
    window.open('facture_pdf.php?id=' + colisId, '_blank');
}

function imprimerColis(colisId) {
    // Imprimer les détails du colis
    window.open('colis_pdf.php?id=' + colisId + '&print=1', '_blank');
}

function shareColis(colisId, trackingNumber) {
    const shareData = {
        title: 'Colis ' + trackingNumber,
        text: 'Suivez votre colis ' + trackingNumber + ' avec Gestion Colis',
        url: window.location.origin + window.location.pathname.replace('views/client/mes_colis.php', '') + 'views/tracking.php?code=' + trackingNumber
    };
    
    // Vérifier si l'API Web Share est disponible
    if (navigator.share) {
        navigator.share(shareData)
            .then(() => showNotification('Colis partagé avec succès!', 'success'))
            .catch((error) => {
                console.log('Erreur de partage:', error);
                copyTrackingLink(trackingNumber);
            });
    } else {
        // Fallback pour les navigateurs qui ne supportent pas Web Share API
        copyTrackingLink(trackingNumber);
    }
}

function copyTrackingLink(trackingNumber) {
    const trackingUrl = window.location.origin + window.location.pathname.replace('views/client/mes_colis.php', '') + 'views/tracking.php?code=' + trackingNumber;
    
    navigator.clipboard.writeText(trackingUrl).then(() => {
        showNotification('Lien de suivi copié dans le presse-papier!', 'success');
    }).catch(() => {
        const textArea = document.createElement('textarea');
        textArea.value = trackingUrl;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        showNotification('Lien de suivi copié!', 'success');
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

// Initialiser le compteur
document.addEventListener('DOMContentLoaded', function() {
    const rows = document.querySelectorAll('#colisTable tbody tr:not([style*="display: none"])');
    document.querySelector('.header-info span').textContent = rows.length + ' colis trouvé(s)';
});
</script>

<style>
/* =====================================================
   STYLES COMPACTS POUR LA LISTE DES COLIS
   ===================================================== */

/* Stats Grid Compact */
.stats-grid-compact {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.stat-card-compact {
    background: var(--bg-card, #fff);
    border-radius: 8px;
    padding: 0.75rem;
    border: 1px solid var(--border-color, #e2e8f0);
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.stat-icon-compact {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1rem;
}

.stat-icon-compact.bg-primary { background: var(--tech-cyan, #00B4D8); }
.stat-icon-compact.bg-success { background: #22c55e; }
.stat-icon-compact.bg-warning { background: #f59e0b; }
.stat-icon-compact.bg-danger { background: #ef4444; }

.stat-info-compact {
    display: flex;
    flex-direction: column;
}

.stat-value-compact {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--text-primary, #1e293b);
    line-height: 1.2;
}

.stat-label-compact {
    font-size: 0.75rem;
    color: var(--text-muted, #64748b);
}

/* Filters Bar Compact */
.filters-bar-compact {
    display: flex;
    gap: 0.75rem;
    margin-bottom: 1rem;
    flex-wrap: wrap;
    align-items: center;
}

.search-box-compact {
    position: relative;
    flex: 1;
    min-width: 200px;
}

.search-icon-compact {
    position: absolute;
    left: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted, #64748b);
}

.search-input-compact {
    width: 100%;
    padding: 0.625rem 0.75rem 0.625rem 2.5rem;
    background: var(--bg-card, #fff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 8px;
    color: var(--text-primary, #1e293b);
    font-size: 0.875rem;
}

.search-input-compact:focus {
    outline: none;
    border-color: var(--tech-cyan, #00B4D8);
    box-shadow: 0 0 0 3px rgba(0, 180, 216, 0.1);
}

.filter-group-compact {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.filter-select-compact {
    padding: 0.625rem 0.75rem;
    background: var(--bg-card, #fff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 8px;
    color: var(--text-primary, #1e293b);
    font-size: 0.875rem;
    cursor: pointer;
}

.filter-date-compact {
    padding: 0.625rem 0.75rem;
    background: var(--bg-card, #fff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 8px;
    color: var(--text-primary, #1e293b);
    font-size: 0.875rem;
}

/* Card Body Compact */
.card-body-compact {
    padding: 0;
}

/* Table Responsive Compact */
.table-responsive-compact {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.table-responsive-compact::-webkit-scrollbar {
    height: 6px;
}

.table-responsive-compact::-webkit-scrollbar-thumb {
    background: var(--tech-cyan, #00B4D8);
    border-radius: 3px;
}

/* Data Table Compact */
.data-table-compact {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
    background: var(--white, #fff);
}

.data-table-compact thead {
    background: linear-gradient(135deg, var(--tech-cyan, #00B4D8), var(--tech-blue, #0891b2));
    color: white;
}

.data-table-compact thead th {
    padding: 0.75rem 0.5rem;
    text-align: left;
    font-weight: 600;
    white-space: nowrap;
    cursor: pointer;
    transition: background 0.2s;
    font-size: 0.8rem;
}

.data-table-compact thead th:hover {
    background: rgba(255, 255, 255, 0.15);
}

.data-table-compact thead th i {
    margin-left: 0.25rem;
    opacity: 0.7;
    font-size: 0.7rem;
}

.data-table-compact tbody tr {
    border-bottom: 1px solid var(--border-color, #e2e8f0);
    transition: background 0.2s;
}

.data-table-compact tbody tr:nth-of-type(even) {
    background-color: rgba(0, 180, 216, 0.02);
}

.data-table-compact tbody tr:hover {
    background-color: rgba(0, 180, 216, 0.06);
}

.data-table-compact td {
    padding: 0.625rem 0.5rem;
    vertical-align: middle;
    color: var(--text-primary, #1e293b);
}

/* Cell Styles */
.tracking-cell {
    white-space: nowrap;
}

.tracking-code-compact {
    font-family: 'Courier New', monospace;
    font-weight: 600;
    color: var(--tech-cyan, #00B4D8);
    font-size: 0.8rem;
}

.ref-cell {
    font-weight: 500;
    color: var(--text-secondary, #475569);
}

.desc-cell {
    max-width: 150px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--text-muted, #64748b);
}

.status-cell {
    white-space: nowrap;
}

.weight-cell {
    text-align: center;
    color: var(--text-secondary, #475569);
}

.date-cell {
    white-space: nowrap;
    color: var(--text-muted, #64748b);
    font-size: 0.8rem;
}

.agent-cell {
    color: var(--text-secondary, #475569);
    font-size: 0.8rem;
}

/* Badges Compact */
.badge-sm {
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 600;
}

.badge-warning {
    background: rgba(245, 158, 11, 0.15);
    color: #f59e0b;
}

.badge-danger {
    background: rgba(239, 68, 68, 0.15);
    color: #ef4444;
}

/* Actions Cell Compact */
.actions-cell-compact {
    display: flex;
    gap: 0.25rem;
    justify-content: center;
    white-space: nowrap;
}

.btn-icon-compact {
    width: 28px;
    height: 28px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    transition: all 0.2s;
}

.btn-icon-compact:hover {
    transform: scale(1.1);
}

.btn-icon-compact.btn-tracking {
    background: rgba(234, 179, 8, 0.15);
    color: #eab308;
}

.btn-icon-compact.btn-tracking:hover {
    background: #eab308;
    color: white;
}

.btn-icon-compact.btn-pdf {
    background: rgba(239, 68, 68, 0.15);
    color: #ef4444;
}

.btn-icon-compact.btn-pdf:hover {
    background: #ef4444;
    color: white;
}

.btn-icon-compact.btn-print {
    background: rgba(100, 116, 139, 0.15);
    color: #64748b;
}

.btn-icon-compact.btn-print:hover {
    background: #64748b;
    color: white;
}

.btn-icon-compact.btn-edit {
    background: rgba(59, 130, 246, 0.15);
    color: #3b82f6;
}

.btn-icon-compact.btn-edit:hover {
    background: #3b82f6;
    color: white;
}

.btn-icon-compact.btn-danger {
    background: rgba(239, 68, 68, 0.15);
    color: #ef4444;
}

.btn-icon-compact.btn-danger:hover {
    background: #ef4444;
    color: white;
}

.btn-icon-compact.btn-share {
    background: rgba(16, 185, 129, 0.15);
    color: #10b981;
}

.btn-icon-compact.btn-share:hover {
    background: #10b981;
    color: white;
}

/* Responsive */
@media (max-width: 768px) {
    .stats-grid-compact {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .filters-bar-compact {
        flex-direction: column;
        align-items: stretch;
    }
    
    .search-box-compact {
        min-width: 100%;
    }
    
    .data-table-compact {
        font-size: 0.8rem;
    }
    
    .actions-cell-compact {
        gap: 0.15rem;
    }
    
    .btn-icon-compact {
        width: 24px;
        height: 24px;
        font-size: 0.7rem;
    }
}

@media (max-width: 480px) {
    .stats-grid-compact {
        grid-template-columns: 1fr;
    }
    
    .stat-card-compact {
        padding: 0.5rem;
    }
}
</style>
