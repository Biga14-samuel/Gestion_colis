<?php
// Verification de la connexion et des droits d'acces
require_once __DIR__ . '/../../utils/session.php';
SessionManager::start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="access-denied">Accès refusé. Vous devez être administrateur pour accéder à cette page.</div>';
    exit;
}

require_once '../../config/database.php';
require_once '../../utils/password_policy.php';

// Traitement des actions POST
$message = '';
$messageType = '';
$ajaxMode = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $ajaxResponse = ['success' => false, 'message' => ''];
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        switch ($action) {
            case 'ajouter':
                $nom = trim($_POST['nom'] ?? '');
                $prenom = trim($_POST['prenom'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $telephone = trim($_POST['telephone'] ?? '');
                $matricule = trim($_POST['matricule'] ?? '');
                $zone = trim($_POST['zone'] ?? '');
                $mot_de_passe = $_POST['mot_de_passe'] ?? '';
                
                if (empty($nom) || empty($prenom) || empty($email) || empty($mot_de_passe) || empty($matricule)) {
                    $ajaxResponse['message'] = 'Tous les champs obligatoires doivent être remplis.';
                } else {
                    // Verifier si le matricule ou l'email existe deja
                    $stmt = $db->prepare("SELECT id FROM utilisateurs WHERE email = ? OR matricule = ?");
                    $stmt->execute([$email, $matricule]);
                    if ($stmt->fetch()) {
                        $ajaxResponse['message'] = 'Cet email ou ce matricule est déjà utilisé.';
                    } else {
                        $passwordErrors = PasswordPolicy::validate($mot_de_passe);
                        if (!empty($passwordErrors)) {
                            $ajaxResponse['message'] = $passwordErrors[0];
                        } else {
                        $mot_de_passe_hash = password_hash($mot_de_passe, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("INSERT INTO utilisateurs (nom, prenom, email, telephone, matricule, zone_livraison, mot_de_passe, role, date_creation) VALUES (?, ?, ?, ?, ?, ?, ?, 'agent', NOW())");
                        $stmt->execute([$nom, $prenom, $email, $telephone, $matricule, $zone, $mot_de_passe_hash]);
                        $ajaxResponse['success'] = true;
                        $ajaxResponse['message'] = 'Agent ajouté avec succès.';
                    }
                    }
                }
                break;
                
            case 'modifier':
                $id = (int)($_POST['id'] ?? 0);
                $nom = trim($_POST['nom'] ?? '');
                $prenom = trim($_POST['prenom'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $telephone = trim($_POST['telephone'] ?? '');
                $matricule = trim($_POST['matricule'] ?? '');
                $zone = trim($_POST['zone'] ?? '');
                $statut = $_POST['statut'] ?? 'actif';
                $mot_de_passe = $_POST['mot_de_passe'] ?? '';
                
                if ($id <= 0 || empty($nom) || empty($prenom) || empty($email) || empty($matricule)) {
                    $ajaxResponse['message'] = 'Données invalides.';
                } else {
                    // Verifier si le matricule ou l'email existe deja (sauf pour cet agent)
                    $stmt = $db->prepare("SELECT id FROM utilisateurs WHERE (email = ? OR matricule = ?) AND id != ?");
                    $stmt->execute([$email, $matricule, $id]);
                    if ($stmt->fetch()) {
                        $ajaxResponse['message'] = 'Cet email ou ce matricule est déjà utilisé par un autre utilisateur.';
                    } else {
                        if (!empty($mot_de_passe)) {
                            $passwordErrors = PasswordPolicy::validate($mot_de_passe);
                            if (!empty($passwordErrors)) {
                                $ajaxResponse['message'] = $passwordErrors[0];
                                break;
                            }
                            $mot_de_passe_hash = password_hash($mot_de_passe, PASSWORD_DEFAULT);
                            $stmt = $db->prepare("UPDATE utilisateurs SET nom = ?, prenom = ?, email = ?, telephone = ?, matricule = ?, zone_livraison = ?, statut = ?, mot_de_passe = ? WHERE id = ?");
                            $stmt->execute([$nom, $prenom, $email, $telephone, $matricule, $zone, $statut, $mot_de_passe_hash, $id]);
                        } else {
                            $stmt = $db->prepare("UPDATE utilisateurs SET nom = ?, prenom = ?, email = ?, telephone = ?, matricule = ?, zone_livraison = ?, statut = ? WHERE id = ?");
                            $stmt->execute([$nom, $prenom, $email, $telephone, $matricule, $zone, $statut, $id]);
                        }
                        $ajaxResponse['success'] = true;
                        $ajaxResponse['message'] = 'Agent modifié avec succès.';
                    }
                }
                break;
                
            case 'supprimer':
                $id = (int)($_POST['id'] ?? 0);
                
                if ($id <= 0) {
                    $ajaxResponse['message'] = 'ID invalide.';
                } else {
                    $stmt = $db->prepare("DELETE FROM utilisateurs WHERE id = ? AND role = 'agent'");
                    $stmt->execute([$id]);
                    $ajaxResponse['success'] = true;
                    $ajaxResponse['message'] = 'Agent supprimé avec succès.';
                }
                break;
        }
    } catch (PDOException $e) {
        $ajaxResponse['message'] = user_error_message($e, 'admin_agents.action', 'Erreur de base de données.');
    }
    
    if ($ajaxMode) {
        header('Content-Type: application/json');
        echo json_encode($ajaxResponse);
        exit;
    }
    
    $message = $ajaxResponse['message'];
    $messageType = $ajaxResponse['success'] ? 'success' : 'error';
}

// Recuperation des agents avec leurs statistiques
try {
    $database = new Database();
    $db = $database->getConnection();
    
    $stmt = $db->query("
        SELECT 
            u.id, u.nom, u.prenom, u.email, u.telephone, u.matricule, 
            u.zone_livraison, u.statut, u.date_creation, u.dernier_connexion,
            COUNT(DISTINCT c.id) as total_colis,
            SUM(CASE WHEN c.statut = 'livre' THEN 1 ELSE 0 END) as colis_livres,
            SUM(CASE WHEN c.statut != 'livre' AND c.statut != 'annule' THEN 1 ELSE 0 END) as colis_en_cours
        FROM utilisateurs u
        LEFT JOIN colis c ON c.agent_id = u.id
        WHERE u.role = 'agent'
        GROUP BY u.id
        ORDER BY u.date_creation DESC
    ");
    $agents = $stmt->fetchAll();
} catch (PDOException $e) {
    $agents = [];
    $message = user_error_message($e, 'admin_agents.fetch', 'Erreur lors de la récupération des agents.');
    $messageType = 'error';
}

// Recuperation des zones de livraison disponibles
try {
    $stmt = $db->query("SELECT DISTINCT zone_livraison FROM utilisateurs WHERE role = 'agent' AND zone_livraison IS NOT NULL AND zone_livraison != ''");
    $zones = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $zones = [];
}
?>

<!-- Contenu pour le système SPA -->
<div id="page-content">

<div class="page-container">
    <div class="page-header">
        <h1><i class="fas fa-shipping-fast"></i> Gestion des Agents de Livraison</h1>
        <div class="header-actions">
            <button class="btn btn-primary" onclick="openModal('agentModal')">
                <i class="fas fa-plus"></i> Nouvel Agent
            </button>
        </div>
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
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?= count($agents) ?></span>
                <span class="stat-label">Total Agents</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-success">
                <i class="fas fa-user-check"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?= count(array_filter($agents, fn($a) => $a['statut'] === 'actif')) ?></span>
                <span class="stat-label">Agents Actifs</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-warning">
                <i class="fas fa-truck"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?= array_sum(array_column($agents, 'colis_livres')) ?></span>
                <span class="stat-label">Colis Livrés</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-info">
                <i class="fas fa-box-open"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?= array_sum(array_column($agents, 'colis_en_cours')) ?></span>
                <span class="stat-label">En Cours</span>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-list"></i> Liste des Agents</h3>
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchAgents" placeholder="Rechercher un agent..." onkeyup="filterAgents()">
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="data-table" id="agentsTable">
                    <thead>
                        <tr>
                            <th onclick="sortTable(0)">Matricule <i class="fas fa-sort"></i></th>
                            <th onclick="sortTable(1)">Nom <i class="fas fa-sort"></i></th>
                            <th onclick="sortTable(2)">Prénom <i class="fas fa-sort"></i></th>
                            <th>Email</th>
                            <th>Téléphone</th>
                            <th>Zone</th>
                            <th onclick="sortTable(6)">Statut <i class="fas fa-sort"></i></th>
                            <th>Total Colis</th>
                            <th>Livrés</th>
                            <th>En Cours</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($agents)): ?>
                            <tr>
                                <td colspan="11" class="text-center">Aucun agent trouvé.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($agents as $agent): ?>
                                <tr data-agent-id="<?= $agent['id'] ?>">
                                    <td><?= htmlspecialchars($agent['matricule']) ?></td>
                                    <td><?= htmlspecialchars($agent['nom']) ?></td>
                                    <td><?= htmlspecialchars($agent['prenom']) ?></td>
                                    <td><?= htmlspecialchars($agent['email']) ?></td>
                                    <td><?= htmlspecialchars($agent['telephone'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($agent['zone_livraison'] ?? 'Non définie') ?></td>
                                    <td>
                                        <span class="badge badge-<?= $agent['statut'] === 'actif' ? 'success' : 'secondary' ?>">
                                            <?= htmlspecialchars(ucfirst($agent['statut'])) ?>
                                        </span>
                                    </td>
                                    <td><?= $agent['total_colis'] ?? 0 ?></td>
                                    <td>
                                        <span class="text-success"><?= $agent['colis_livres'] ?? 0 ?></span>
                                    </td>
                                    <td>
                                        <span class="text-warning"><?= $agent['colis_en_cours'] ?? 0 ?></span>
                                    </td>
                                    <td class="actions">
                                        <button class="btn-icon btn-edit" onclick="editAgent(<?= $agent['id'] ?>)" title="Modifier">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn-icon btn-view" onclick="viewAgentDetails(<?= $agent['id'] ?>)" title="Voir les détails">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn-icon btn-delete" onclick="deleteAgent(<?= $agent['id'] ?>, '<?= htmlspecialchars($agent['prenom'] . ' ' . $agent['nom']) ?>')" title="Supprimer">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Backdrop pour les modals -->
<div id="modalBackdrop" class="modal-backdrop" onclick="closeAllModals()"></div>

<!-- Modal d'ajout/modification d'agent -->
<div id="agentModal" class="modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2 id="agentModalTitle"><i class="fas fa-user-plus"></i> Nouvel Agent</h2>
            <button class="modal-close" onclick="closeModal('agentModal')">&times;</button>
        </div>
        <form id="agentForm" method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" id="agentFormAction" value="ajouter">
            <input type="hidden" name="id" id="agentId" value="">
            
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label for="matricule">Matricule <span class="required">*</span></label>
                        <input type="text" id="matricule" name="matricule" required placeholder="Ex:AGT001" pattern="[A-Z0-9]+">
                    </div>
                    <div class="form-group">
                        <label for="zone">Zone de Livraison</label>
                        <input type="text" id="zone" name="zone" list="zonesList" placeholder="Ex: Centre-ville">
                        <datalist id="zonesList">
                            <?php foreach ($zones as $zone): ?>
                                <option value="<?= htmlspecialchars($zone) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="agent_nom">Nom <span class="required">*</span></label>
                        <input type="text" id="agent_nom" name="nom" required placeholder="Entrez le nom">
                    </div>
                    <div class="form-group">
                        <label for="agent_prenom">Prénom <span class="required">*</span></label>
                        <input type="text" id="agent_prenom" name="prenom" required placeholder="Entrez le prénom">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="agent_email">Email <span class="required">*</span></label>
                        <input type="email" id="agent_email" name="email" required placeholder="agent@email.com">
                    </div>
                    <div class="form-group">
                        <label for="agent_telephone">Téléphone</label>
                        <input type="tel" id="agent_telephone" name="telephone" placeholder="Numéro de téléphone">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="agent_statut" id="statutLabel">Statut <span class="required">*</span></label>
                        <select id="agent_statut" name="statut" required>
                            <option value="actif">Actif</option>
                            <option value="inactif">Inactif</option>
                            <option value="en_conge">En congés</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="agent_mot_de_passe" id="agentPasswordLabel">Mot de passe <span class="required">*</span></label>
                        <div class="password-input">
                            <input type="password" id="agent_mot_de_passe" name="mot_de_passe" placeholder="Mot de passe">
                            <button type="button" class="password-toggle" onclick="togglePassword('agent_mot_de_passe')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small id="agentPasswordHint" class="form-hint">Laissez vide pour conserver le mot de passe actuel</small>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('agentModal')">
                    <i class="fas fa-times"></i> Annuler
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal de confirmation de suppression -->
<div id="deleteAgentModal" class="modal">
    <div class="modal-content modal-sm">
        <div class="modal-header">
            <h2><i class="fas fa-exclamation-triangle text-warning"></i> Confirmer la suppression</h2>
            <button class="modal-close" onclick="closeModal('deleteAgentModal')">&times;</button>
        </div>
        <div class="modal-body">
            <p>Êtes-vous sûr de vouloir supprimer l'agent <strong id="deleteAgentName"></strong> ?</p>
            <p class="text-warning"><i class="fas fa-info-circle"></i> Cette action est irréversible. Les colis assignés à cet agent devront être réassignés.</p>
        </div>
        <form method="POST" id="deleteAgentForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="supprimer">
            <input type="hidden" name="id" id="deleteAgentId" value="">
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('deleteAgentModal')">
                    <i class="fas fa-times"></i> Annuler
                </button>
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Supprimer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal de details d'agent -->
<div id="agentDetailsModal" class="modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2><i class="fas fa-user-circle"></i> Détails de l'Agent</h2>
            <button class="modal-close" onclick="closeModal('agentDetailsModal')">&times;</button>
        </div>
        <div class="modal-body" id="agentDetailsContent">
            <!-- Le contenu sera charge dynamiquement -->
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('agentDetailsModal')">
                <i class="fas fa-times"></i> Fermer
            </button>
        </div>
    </div>
</div>

<script>
const csrfToken = <?php echo json_encode(csrf_token()); ?>;
// Soumission du formulaire via AJAX pour le système SPA
document.getElementById('agentForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enregistrement...';
    submitBtn.disabled = true;
    
    fetch('views/admin/gestion_agents.php', {
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
            closeModal('agentModal');
            setTimeout(() => {
                loadPage('views/admin/gestion_agents.php', 'Agents');
            }, 1000);
        } else {
            showNotification(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        showNotification('Erreur lors de l\'enregistrement.', 'error');
    })
    .finally(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
});

// Soumission du formulaire de suppression via AJAX
document.getElementById('deleteAgentForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Suppression...';
    submitBtn.disabled = true;
    
    fetch('views/admin/gestion_agents.php', {
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
            closeModal('deleteAgentModal');
            setTimeout(() => {
                loadPage('views/admin/gestion_agents.php', 'Agents');
            }, 1000);
        } else {
            showNotification(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        showNotification('Erreur lors de la suppression.', 'error');
    })
    .finally(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
});

document.addEventListener('DOMContentLoaded', function() {
    initTableSort();
});

function filterAgents() {
    const searchTerm = document.getElementById('searchAgents').value.toLowerCase();
    const rows = document.querySelectorAll('#agentsTable tbody tr');
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
    });
}

function sortTable(colIndex) {
    const table = document.getElementById('agentsTable');
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
        
        const aNum = parseFloat(aText);
        const bNum = parseFloat(bText);
        
        if (!isNaN(aNum) && !isNaN(bNum)) {
            return newSort === 'asc' ? aNum - bNum : bNum - aNum;
        }
        
        return newSort === 'asc' ? aText.localeCompare(bText) : bText.localeCompare(aText);
    });
    
    headers[colIndex].setAttribute('data-sort', newSort);
    headers[colIndex].querySelector('i').className = newSort === 'asc' ? 'fas fa-sort-up' : 'fas fa-sort-down';
    
    rows.forEach(row => tbody.appendChild(row));
}

function editAgent(agentId) {
    const row = document.querySelector(`tr[data-agent-id="${agentId}"]`);
    if (!row) return;
    
    const cells = row.querySelectorAll('td');
    
    document.getElementById('agentFormAction').value = 'modifier';
    document.getElementById('agentId').value = agentId;
    document.getElementById('matricule').value = cells[0].textContent;
    document.getElementById('agent_nom').value = cells[1].textContent;
    document.getElementById('agent_prenom').value = cells[2].textContent;
    document.getElementById('agent_email').value = cells[3].textContent;
    document.getElementById('agent_telephone').value = cells[4].textContent === '-' ? '' : cells[4].textContent;
    document.getElementById('zone').value = cells[5].textContent === 'Non définie' ? '' : cells[5].textContent;
    
    const statutBadge = cells[6].querySelector('.badge');
    const statut = statutBadge.textContent.toLowerCase().replace('é', 'e').replace(' ', '_');
    document.getElementById('agent_statut').value = statut;
    
    document.getElementById('agent_mot_de_passe').value = '';
    document.getElementById('agent_mot_de_passe').removeAttribute('required');
    document.getElementById('agentPasswordLabel').innerHTML = 'Mot de passe <span class="optional">(optionnel)</span>';
    document.getElementById('agentPasswordHint').style.display = 'block';
    
    document.getElementById('agentModalTitle').innerHTML = '<i class="fas fa-user-edit"></i> Modifier l\'agent';
    
    openModal('agentModal');
}

function deleteAgent(agentId, agentName) {
    document.getElementById('deleteAgentId').value = agentId;
    document.getElementById('deleteAgentName').textContent = agentName;
    openModal('deleteAgentModal');
}

function viewAgentDetails(agentId) {
    const row = document.querySelector(`tr[data-agent-id="${agentId}"]`);
    if (!row) return;
    
    const cells = row.querySelectorAll('td');
    
    const totalColis = cells[7].textContent;
    const colisLivres = cells[8].textContent;
    const colisEnCours = cells[9].textContent;
    const tauxLivraison = totalColis > 0 ? Math.round((parseInt(colisLivres) / parseInt(totalColis)) * 100) : 0;
    
    const content = `
        <div class="agent-details">
            <div class="detail-section">
                <h4>Informations Personnelles</h4>
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-label">Matricule</span>
                        <span class="detail-value">${cells[0].textContent}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Nom complet</span>
                        <span class="detail-value">${cells[2].textContent} ${cells[1].textContent}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Email</span>
                        <span class="detail-value">${cells[3].textContent}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Téléphone</span>
                        <span class="detail-value">${cells[4].textContent}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Zone de livraison</span>
                        <span class="detail-value">${cells[5].textContent}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Statut</span>
                        <span class="detail-value">${cells[6].innerHTML}</span>
                    </div>
                </div>
            </div>
            
            <div class="detail-section">
                <h4>Statistiques de Livraison</h4>
                <div class="stats-mini-grid">
                    <div class="stat-mini">
                        <span class="stat-mini-value">${totalColis}</span>
                        <span class="stat-mini-label">Total Colis</span>
                    </div>
                    <div class="stat-mini">
                        <span class="stat-mini-value text-success">${colisLivres}</span>
                        <span class="stat-mini-label">Livrés</span>
                    </div>
                    <div class="stat-mini">
                        <span class="stat-mini-value text-warning">${colisEnCours}</span>
                        <span class="stat-mini-label">En Cours</span>
                    </div>
                    <div class="stat-mini">
                        <span class="stat-mini-value">${tauxLivraison}%</span>
                        <span class="stat-mini-label">Taux de Livraison</span>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    document.getElementById('agentDetailsContent').innerHTML = content;
    openModal('agentDetailsModal');
}

// Reinitialiser le formulaire lors de l'ouverture du modal pour ajout
document.querySelector('button[onclick="openModal(\'agentModal\')"]')?.addEventListener('click', function() {
    document.getElementById('agentForm').reset();
    document.getElementById('agentFormAction').value = 'ajouter';
    document.getElementById('agentId').value = '';
    document.getElementById('agent_mot_de_passe').setAttribute('required', 'required');
    document.getElementById('agentPasswordLabel').innerHTML = 'Mot de passe <span class="required">*</span>';
    document.getElementById('agentPasswordHint').style.display = 'none';
    document.getElementById('agentModalTitle').innerHTML = '<i class="fas fa-user-plus"></i> Nouvel Agent';
});

function initTableSort() {
    // Les en-tetes cliquables sont deja configures dans le HTML
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
</script>

</div> <!-- Fin #page-content -->
