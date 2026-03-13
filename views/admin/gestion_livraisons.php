<?php
// Vérification de la connexion et des droits d'accès
require_once __DIR__ . '/../../utils/session.php';
SessionManager::start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="access-denied">Accès refusé. Vous devez être administrateur pour accéder à cette page.</div>';
    exit;
}

require_once '../../config/database.php';

$message = '';
$messageType = '';
$ajaxMode = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $ajaxResponse = ['success' => false, 'message' => ''];
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        switch ($action) {
            case 'assigner':
                $colis_id = (int)($_POST['colis_id'] ?? 0);
                $agent_id = (int)($_POST['agent_id'] ?? 0);
                
                if ($colis_id <= 0 || $agent_id <= 0) {
                    $ajaxResponse['message'] = 'Données invalides.';
                } else {
                    // Vérifier si le colis existe et n'est pas déjà assigné
                    $stmt = $db->prepare("SELECT id, statut, agent_id FROM colis WHERE id = ?");
                    $stmt->execute([$colis_id]);
                    $colis = $stmt->fetch();
                    
                    if (!$colis) {
                        $ajaxResponse['message'] = 'Colis non trouvé.';
                    } elseif ($colis['agent_id']) {
                        $ajaxResponse['message'] = 'Ce colis est déjà assigné à un agent.';
                    } else {
                        // Vérifier si l'agent existe et est actif
                        $stmt = $db->prepare("SELECT id, actif FROM agents WHERE id = ?");
                        $stmt->execute([$agent_id]);
                        $agent = $stmt->fetch();
                        
                        if (!$agent || $agent['actif'] != 1) {
                            $ajaxResponse['message'] = 'Agent non trouvé ou inactif.';
                        } else {
                            // Assigner le colis à l'agent
                            $stmt = $db->prepare("UPDATE colis SET agent_id = ?, statut = 'en_livraison' WHERE id = ?");
                            $stmt->execute([$agent_id, $colis_id]);
                            
                            // Créer l'entrée dans livraisons
                            $stmt = $db->prepare("INSERT INTO livraisons (colis_id, agent_id, date_assignation, statut) VALUES (?, ?, NOW(), 'assignee')");
                            $stmt->execute([$colis_id, $agent_id]);
                            
                            $ajaxResponse['success'] = true;
                            $ajaxResponse['message'] = 'Colis assigné avec succès à l\'agent.';
                        }
                    }
                }
                break;
                
            case 'reassigner':
                $colis_id = (int)($_POST['colis_id'] ?? 0);
                $nouveau_agent_id = (int)($_POST['agent_id'] ?? 0);
                
                if ($colis_id <= 0 || $nouveau_agent_id <= 0) {
                    $ajaxResponse['message'] = 'Données invalides.';
                } else {
                    // Vérifier le colis
                    $stmt = $db->prepare("SELECT id, agent_id FROM colis WHERE id = ?");
                    $stmt->execute([$colis_id]);
                    $colis = $stmt->fetch();
                    
                    if (!$colis) {
                        $ajaxResponse['message'] = 'Colis non trouvé.';
                    } else {
                        // Mettre à jour le colis
                        $stmt = $db->prepare("UPDATE colis SET agent_id = ? WHERE id = ?");
                        $stmt->execute([$nouveau_agent_id, $colis_id]);
                        
                        // Mettre à jour la livraison
                        $stmt = $db->prepare("UPDATE livraisons SET agent_id = ?, date_assignation = NOW() WHERE colis_id = ?");
                        $stmt->execute([$nouveau_agent_id, $colis_id]);
                        
                        $ajaxResponse['success'] = true;
                        $ajaxResponse['message'] = 'Colis réassigné avec succès.';
                    }
                }
                break;
                
            case 'annuler_assignation':
                $colis_id = (int)($_POST['colis_id'] ?? 0);
                
                if ($colis_id <= 0) {
                    $ajaxResponse['message'] = 'ID invalide.';
                } else {
                    // Récupérer l'ancien agent pour information
                    $stmt = $db->prepare("SELECT agent_id FROM colis WHERE id = ?");
                    $stmt->execute([$colis_id]);
                    $colis = $stmt->fetch();
                    
                    // Annuler l'assignation
                    $stmt = $db->prepare("UPDATE colis SET agent_id = NULL, statut = 'en_attente' WHERE id = ?");
                    $stmt->execute([$colis_id]);
                    
                    // Supprimer l'entrée de livraison
                    $stmt = $db->prepare("DELETE FROM livraisons WHERE colis_id = ?");
                    $stmt->execute([$colis_id]);
                    
                    $ajaxResponse['success'] = true;
                    $ajaxResponse['message'] = 'Assignation annulée avec succès.';
                }
                break;
        }
    } catch (PDOException $e) {
        $ajaxResponse['message'] = user_error_message($e, 'admin_livraisons.action', 'Erreur de base de données.');
    }
    
    if ($ajaxMode) {
        header('Content-Type: application/json');
        echo json_encode($ajaxResponse);
        exit;
    }
    
    $message = $ajaxResponse['message'];
    $messageType = $ajaxResponse['success'] ? 'success' : 'error';
}

// Récupération des colis non assignés
try {
    $database = new Database();
    $db = $database->getConnection();
    
    $stmt = $db->query("
        SELECT c.*, u.nom as client_nom, u.prenom as client_prenom, u.email as client_email
        FROM colis c
        JOIN utilisateurs u ON c.utilisateur_id = u.id
        WHERE c.agent_id IS NULL AND c.statut IN ('en_attente', 'preparation')
        ORDER BY c.date_creation DESC
    ");
    $colis_non_assignes = $stmt->fetchAll();
} catch (PDOException $e) {
    $colis_non_assignes = [];
}

// Récupération des colis assignés
try {
    $stmt = $db->query("
        SELECT 
            c.*, 
            u.nom as client_nom, 
            u.prenom as client_prenom, 
            u.email as client_email,
            a.id as agent_id,
            au.nom as agent_nom,
            au.prenom as agent_prenom,
            au.telephone as agent_telephone,
            l.date_assignation,
            l.statut as livraison_statut
        FROM colis c
        JOIN utilisateurs u ON c.utilisateur_id = u.id
        LEFT JOIN agents a ON c.agent_id = a.id
        LEFT JOIN utilisateurs au ON a.utilisateur_id = au.id
        LEFT JOIN livraisons l ON c.id = l.colis_id
        WHERE c.agent_id IS NOT NULL
        ORDER BY l.date_assignation DESC
    ");
    $colis_assignes = $stmt->fetchAll();
} catch (PDOException $e) {
    $colis_assignes = [];
}

// Récupération des agents actifs
try {
    $stmt = $db->query("
        SELECT a.id, a.numero_agent, a.zone_livraison, u.nom, u.prenom, u.telephone
        FROM agents a
        JOIN utilisateurs u ON a.utilisateur_id = u.id
        WHERE a.actif = 1
        ORDER BY u.prenom, u.nom
    ");
    $agents = $stmt->fetchAll();
} catch (PDOException $e) {
    $agents = [];
}

// Statistiques
try {
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total_colis,
            SUM(CASE WHEN agent_id IS NOT NULL THEN 1 ELSE 0 END) as colis_assignes,
            SUM(CASE WHEN agent_id IS NULL THEN 1 ELSE 0 END) as colis_en_attente
        FROM colis 
        WHERE statut NOT IN ('livre', 'annule')
    ");
    $stats = $stmt->fetch();
} catch (PDOException $e) {
    $stats = ['total_colis' => 0, 'colis_assignes' => 0, 'colis_en_attente' => 0];
}

$statusLabels = [
    'en_attente' => 'En attente',
    'preparation' => 'En préparation',
    'en_livraison' => 'En livraison',
    'livre' => 'Livré',
    'annule' => 'Annulé'
];

$livraisonStatusLabels = [
    'assignee' => 'Assignée',
    'en_cours' => 'En cours',
    'livree' => 'Livrée',
    'annulee' => 'Annulée'
];
?>

<!-- Contenu pour le système SPA -->
<div id="page-content">

<div class="page-container">
    <div class="page-header">
        <h1><i class="fas fa-tasks"></i> Gestion des Assignations</h1>
        <p>Gérez l'attribution des colis aux agents de livraison</p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?>">
            <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Cartes de statistiques -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon bg-primary">
                <i class="fas fa-box"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?= $stats['total_colis'] ?? 0 ?></span>
                <span class="stat-label">Total Colis (Actifs)</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-success">
                <i class="fas fa-user-check"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?= $stats['colis_assignes'] ?? 0 ?></span>
                <span class="stat-label">Colis Assignés</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-warning">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?= $stats['colis_en_attente'] ?? 0 ?></span>
                <span class="stat-label">En Attente</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-info">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?= count($agents) ?></span>
                <span class="stat-label">Agents Disponibles</span>
            </div>
        </div>
    </div>

    <!-- Colis non assignés -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-box-open"></i> Colis en Attente d'Assignation</h3>
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchUnassigned" placeholder="Rechercher..." onkeyup="filterUnassigned()">
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($colis_non_assignes)): ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle"></i>
                    <p>Aucun colis en attente d'assignation.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table" id="unassignedTable">
                        <thead>
                            <tr>
                                <th>Référence</th>
                                <th>Client</th>
                                <th>Description</th>
                                <th>Poids</th>
                                <th>Statut</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($colis_non_assignes as $colis): ?>
                                <tr data-search="<?= strtolower($colis['reference_colis'] . ' ' . $colis['client_nom'] . ' ' . $colis['client_prenom']) ?>">
                                    <td>
                                        <span class="tracking-number"><?= htmlspecialchars($colis['reference_colis']) ?></span>
                                        <br><small class="text-muted"><?= htmlspecialchars($colis['code_tracking'] ?? 'N/A') ?></small>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($colis['client_prenom'] . ' ' . $colis['client_nom']) ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($colis['client_email']) ?></small>
                                    </td>
                                    <td><?= htmlspecialchars(substr($colis['description'], 0, 50)) ?>...</td>
                                    <td><?= htmlspecialchars($colis['poids']) ?> kg</td>
                                    <td>
                                        <span class="badge badge-<?= $colis['statut'] === 'en_attente' ? 'warning' : 'info' ?>">
                                            <?= htmlspecialchars($statusLabels[$colis['statut']] ?? ucfirst($colis['statut'])) ?>
                                        </span>
                                    </td>
                                    <td><?= date('d/m/Y H:i', strtotime($colis['date_creation'])) ?></td>
                                    <td class="actions">
                                        <button class="btn btn-sm btn-primary" onclick="openAssignModal(<?= $colis['id'] ?>, '<?= htmlspecialchars($colis['reference_colis']) ?>')">
                                            <i class="fas fa-user-plus"></i> Assigner
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Colis assignés -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-truck-loading"></i> Colis Assignés</h3>
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchAssigned" placeholder="Rechercher..." onkeyup="filterAssigned()">
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($colis_assignes)): ?>
                <div class="empty-state">
                    <i class="fas fa-truck"></i>
                    <p>Aucun colis assigné pour le moment.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table" id="assignedTable">
                        <thead>
                            <tr>
                                <th>Référence</th>
                                <th>Client</th>
                                <th>Agent Assigné</th>
                                <th>Zone</th>
                                <th>Statut Colis</th>
                                <th>Statut Livraison</th>
                                <th>Date Assignation</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($colis_assignes as $colis): ?>
                                <tr data-search="<?= strtolower($colis['reference_colis'] . ' ' . $colis['client_nom'] . ' ' . $colis['agent_nom']) ?>">
                                    <td>
                                        <span class="tracking-number"><?= htmlspecialchars($colis['reference_colis']) ?></span>
                                        <br><small class="text-muted"><?= htmlspecialchars($colis['code_tracking'] ?? 'N/A') ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($colis['client_prenom'] . ' ' . $colis['client_nom']) ?></td>
                                    <td>
                                        <?= htmlspecialchars($colis['agent_prenom'] . ' ' . $colis['agent_nom']) ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($colis['agent_telephone'] ?? '') ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($colis['zone_livraison'] ?? 'Non définie') ?></td>
                                    <td>
                                        <span class="badge badge-<?= $colis['statut'] === 'en_livraison' ? 'info' : ($colis['statut'] === 'livre' ? 'success' : 'warning') ?>">
                                            <?= htmlspecialchars($statusLabels[$colis['statut']] ?? ucfirst($colis['statut'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?= $colis['livraison_statut'] === 'livree' ? 'success' : ($colis['livraison_statut'] === 'en_cours' ? 'info' : 'secondary') ?>">
                                            <?= htmlspecialchars($livraisonStatusLabels[$colis['livraison_statut']] ?? ucfirst($colis['livraison_statut'])) ?>
                                        </span>
                                    </td>
                                    <td><?= date('d/m/Y H:i', strtotime($colis['date_assignation'])) ?></td>
                                    <td class="actions">
                                        <?php if ($colis['statut'] !== 'livre' && $colis['statut'] !== 'annule'): ?>
                                            <button class="btn-icon btn-edit" onclick="openReassignModal(<?= $colis['id'] ?>, <?= $colis['agent_id'] ?>)" title="Réassigner">
                                                <i class="fas fa-exchange-alt"></i>
                                            </button>
                                            <button class="btn-icon btn-delete" onclick="cancelAssignment(<?= $colis['id'] ?>, '<?= htmlspecialchars($colis['reference_colis']) ?>')" title="Annuler assignation">
                                                <i class="fas fa-user-minus"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
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

<!-- Modal d'assignation -->
<div id="assignModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-user-plus"></i> Assigner un Colis</h2>
            <button class="modal-close" onclick="closeModal('assignModal')">&times;</button>
        </div>
        <form id="assignForm" method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="assigner">
            <input type="hidden" name="colis_id" id="assignColisId" value="">
            
            <div class="modal-body">
                <p>Colis: <strong id="assignColisRef"></strong></p>
                
                <div class="form-group">
                    <label for="assignAgent">Sélectionner un Agent <span class="required">*</span></label>
                    <select id="assignAgent" name="agent_id" class="form-control" required>
                        <option value="">Choisir un agent...</option>
                        <?php foreach ($agents as $agent): ?>
                            <option value="<?= $agent['id'] ?>">
                                <?= htmlspecialchars($agent['prenom'] . ' ' . $agent['nom'] . ' - ' . ($agent['zone_livraison'] ?? 'Zone non définie')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Agents disponibles: <?= count($agents) ?></label>
                    <?php if (empty($agents)): ?>
                        <p class="text-warning"><i class="fas fa-exclamation-triangle"></i> Aucun agent actif n'est disponible. Veuillez d'abord créer des agents.</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('assignModal')">
                    <i class="fas fa-times"></i> Annuler
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check"></i> Assigner
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal de réassignation -->
<div id="reassignModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-exchange-alt"></i> Réassigner un Colis</h2>
            <button class="modal-close" onclick="closeModal('reassignModal')">&times;</button>
        </div>
        <form id="reassignForm" method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="reassigner">
            <input type="hidden" name="colis_id" id="reassignColisId" value="">
            
            <div class="modal-body">
                <p>Colis: <strong id="reassignColisRef"></strong></p>
                
                <div class="form-group">
                    <label for="reassignAgent">Nouvel Agent <span class="required">*</span></label>
                    <select id="reassignAgent" name="agent_id" class="form-control" required>
                        <option value="">Choisir un agent...</option>
                        <?php foreach ($agents as $agent): ?>
                            <option value="<?= $agent['id'] ?>">
                                <?= htmlspecialchars($agent['prenom'] . ' ' . $agent['nom'] . ' - ' . ($agent['zone_livraison'] ?? 'Zone non définie')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <p class="text-warning"><i class="fas fa-info-circle"></i> Le colis sera retiré de l'agent actuel et assigné au nouvel agent.</p>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('reassignModal')">
                    <i class="fas fa-times"></i> Annuler
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-exchange-alt"></i> Réassigner
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal de confirmation d'annulation -->
<div id="cancelAssignModal" class="modal">
    <div class="modal-content modal-sm">
        <div class="modal-header">
            <h2><i class="fas fa-exclamation-triangle text-warning"></i> Confirmer l'annulation</h2>
            <button class="modal-close" onclick="closeModal('cancelAssignModal')">&times;</button>
        </div>
        <div class="modal-body">
            <p>Êtes-vous sûr de vouloir annuler l'assignation du colis <strong id="cancelColisRef"></strong> ?</p>
            <p class="text-warning"><i class="fas fa-info-circle"></i> Le colis sera remis en attente d'assignation.</p>
        </div>
        <form method="POST" id="cancelAssignForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="annuler_assignation">
            <input type="hidden" name="colis_id" id="cancelColisId" value="">
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('cancelAssignModal')">
                    <i class="fas fa-times"></i> Non, conserver
                </button>
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-check"></i> Oui, annuler
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const csrfToken = <?php echo json_encode(csrf_token()); ?>;
// Filtrage des colis non assignés
function filterUnassigned() {
    const searchTerm = document.getElementById('searchUnassigned').value.toLowerCase();
    const rows = document.querySelectorAll('#unassignedTable tbody tr');
    
    rows.forEach(row => {
        const searchData = row.getAttribute('data-search') || '';
        row.style.display = searchData.includes(searchTerm) ? '' : 'none';
    });
}

// Filtrage des colis assignés
function filterAssigned() {
    const searchTerm = document.getElementById('searchAssigned').value.toLowerCase();
    const rows = document.querySelectorAll('#assignedTable tbody tr');
    
    rows.forEach(row => {
        const searchData = row.getAttribute('data-search') || '';
        row.style.display = searchData.includes(searchTerm) ? '' : 'none';
    });
}

// Ouvrir le modal d'assignation
function openAssignModal(colisId, colisRef) {
    document.getElementById('assignColisId').value = colisId;
    document.getElementById('assignColisRef').textContent = colisRef;
    document.getElementById('assignAgent').value = '';
    openModal('assignModal');
}

// Ouvrir le modal de réassignation
function openReassignModal(colisId, currentAgentId) {
    document.getElementById('reassignColisId').value = colisId;
    document.getElementById('reassignColisRef').textContent = document.querySelector(`tr[data-colis-id="${colisId}"]`)?.querySelector('.tracking-number')?.textContent || '';
    
    // Sélectionner l'agent actuel par défaut
    document.getElementById('reassignAgent').value = currentAgentId;
    openModal('reassignModal');
}

// Annuler une assignation
function cancelAssignment(colisId, colisRef) {
    document.getElementById('cancelColisId').value = colisId;
    document.getElementById('cancelColisRef').textContent = colisRef;
    openModal('cancelAssignModal');
}

// Soumission du formulaire d'assignation via AJAX
document.getElementById('assignForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Assignation...';
    submitBtn.disabled = true;
    
    fetch('views/admin/gestion_livraisons.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': csrfToken
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            closeModal('assignModal');
            setTimeout(() => {
                loadPage('views/admin/gestion_livraisons.php', 'Assignations');
            }, 1000);
        } else {
            showNotification(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        showNotification('Erreur lors de l\'assignation.', 'error');
    })
    .finally(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
});

// Soumission du formulaire de réassignation via AJAX
document.getElementById('reassignForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Réassignation...';
    submitBtn.disabled = true;
    
    fetch('views/admin/gestion_livraisons.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': csrfToken
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            closeModal('reassignModal');
            setTimeout(() => {
                loadPage('views/admin/gestion_livraisons.php', 'Assignations');
            }, 1000);
        } else {
            showNotification(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        showNotification('Erreur lors de la réassignation.', 'error');
    })
    .finally(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
});

// Soumission du formulaire d'annulation d'assignation via AJAX
document.getElementById('cancelAssignForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Annulation...';
    submitBtn.disabled = true;
    
    fetch('views/admin/gestion_livraisons.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': csrfToken
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            closeModal('cancelAssignModal');
            setTimeout(() => {
                loadPage('views/admin/gestion_livraisons.php', 'Assignations');
            }, 1000);
        } else {
            showNotification(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        showNotification('Erreur lors de l\'annulation.', 'error');
    })
    .finally(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
});

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

console.log('%c🚀 Gestion_Colis - Gestion des Assignations', 'color: #0891B2; font-size: 16px; font-weight: bold;');
</script>

</div> <!-- Fin #page-content -->
