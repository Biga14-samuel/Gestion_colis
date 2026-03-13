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
                $mot_de_passe = $_POST['mot_de_passe'] ?? '';
                $role = $_POST['role'] ?? 'utilisateur';
                $allowedRoles = ['utilisateur', 'agent', 'admin'];
                if (!in_array($role, $allowedRoles, true)) {
                    $role = 'utilisateur';
                }
                
                if (empty($nom) || empty($prenom) || empty($email) || empty($mot_de_passe)) {
                    $ajaxResponse['message'] = 'Tous les champs obligatoires doivent être remplis.';
                } else {
                        $passwordErrors = PasswordPolicy::validate($mot_de_passe);
                    if (!empty($passwordErrors)) {
                        $ajaxResponse['message'] = $passwordErrors[0];
                        break;
                    }
                    // Verifier si l'email existe deja
                    $stmt = $db->prepare("SELECT id FROM utilisateurs WHERE email = ?");
                    $stmt->execute([$email]);
                    if ($stmt->fetch()) {
                        $ajaxResponse['message'] = 'Cet email est déjà utilisé.';
                    } else {
                        $mot_de_passe_hash = password_hash($mot_de_passe, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("INSERT INTO utilisateurs (nom, prenom, email, telephone, mot_de_passe, role, date_creation) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                        $stmt->execute([$nom, $prenom, $email, $telephone, $mot_de_passe_hash, $role]);
                        $ajaxResponse['success'] = true;
                        $ajaxResponse['message'] = 'Utilisateur ajouté avec succès.';
                    }
                }
                break;
                
            case 'modifier':
                $id = (int)($_POST['id'] ?? 0);
                $nom = trim($_POST['nom'] ?? '');
                $prenom = trim($_POST['prenom'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $telephone = trim($_POST['telephone'] ?? '');
                $role = $_POST['role'] ?? 'utilisateur';
                $allowedRoles = ['utilisateur', 'agent', 'admin'];
                if (!in_array($role, $allowedRoles, true)) {
                    $ajaxResponse['message'] = 'Rôle invalide.';
                    break;
                }
                $mot_de_passe = $_POST['mot_de_passe'] ?? '';
                
                if ($id <= 0 || empty($nom) || empty($prenom) || empty($email)) {
                    $ajaxResponse['message'] = 'Données invalides.';
                } else {
                    if ($id === (int) $_SESSION['user_id'] && $role !== 'admin') {
                        $ajaxResponse['message'] = 'Vous ne pouvez pas retirer votre rôle administrateur.';
                        break;
                    }
                    if (!empty($mot_de_passe)) {
                        $passwordErrors = PasswordPolicy::validate($mot_de_passe);
                        if (!empty($passwordErrors)) {
                            $ajaxResponse['message'] = $passwordErrors[0];
                            break;
                        }
                    }
                    // Verifier si l'email existe deja (sauf pour cet utilisateur)
                    $stmt = $db->prepare("SELECT id FROM utilisateurs WHERE email = ? AND id != ?");
                    $stmt->execute([$email, $id]);
                    if ($stmt->fetch()) {
                        $ajaxResponse['message'] = 'Cet email est déjà utilisé par un autre utilisateur.';
                    } else {
                        if (!empty($mot_de_passe)) {
                            $mot_de_passe_hash = password_hash($mot_de_passe, PASSWORD_DEFAULT);
                            $stmt = $db->prepare("UPDATE utilisateurs SET nom = ?, prenom = ?, email = ?, telephone = ?, mot_de_passe = ?, role = ? WHERE id = ?");
                            $stmt->execute([$nom, $prenom, $email, $telephone, $mot_de_passe_hash, $role, $id]);
                        } else {
                            $stmt = $db->prepare("UPDATE utilisateurs SET nom = ?, prenom = ?, email = ?, telephone = ?, role = ? WHERE id = ?");
                            $stmt->execute([$nom, $prenom, $email, $telephone, $role, $id]);
                        }
                        $ajaxResponse['success'] = true;
                        $ajaxResponse['message'] = 'Utilisateur modifié avec succès.';
                    }
                }
                break;
                
            case 'supprimer':
                $id = (int)($_POST['id'] ?? 0);
                
                // Empêcher la suppression de soi-même
                if ($id === (int)$_SESSION['user_id']) {
                    $ajaxResponse['message'] = 'Vous ne pouvez pas supprimer votre propre compte.';
                } elseif ($id <= 0) {
                    $ajaxResponse['message'] = 'ID invalide.';
                } else {
                    $stmt = $db->prepare("DELETE FROM utilisateurs WHERE id = ?");
                    $stmt->execute([$id]);
                    $ajaxResponse['success'] = true;
                    $ajaxResponse['message'] = 'Utilisateur supprimé avec succès.';
                }
                break;
        }
    } catch (PDOException $e) {
        $ajaxResponse['message'] = user_error_message($e, 'admin_users.action', 'Erreur de base de données.');
    }
    
    if ($ajaxMode) {
        header('Content-Type: application/json');
        echo json_encode($ajaxResponse);
        exit;
    }
    
    $message = $ajaxResponse['message'];
    $messageType = $ajaxResponse['success'] ? 'success' : 'error';
}

// Recuperation des utilisateurs
try {
    $database = new Database();
    $db = $database->getConnection();
    
    $stmt = $db->query("
        SELECT id, nom, prenom, email, telephone, role, date_creation, dernier_connexion 
        FROM utilisateurs 
        ORDER BY date_creation DESC
    ");
    $utilisateurs = $stmt->fetchAll();
} catch (PDOException $e) {
    $utilisateurs = [];
    $message = user_error_message($e, 'admin_users.fetch', 'Erreur lors de la récupération des utilisateurs.');
    $messageType = 'error';
}
?>

<!-- Contenu pour le système SPA -->
<div id="page-content">

<div class="page-container">
    <div class="page-header">
        <h1><i class="fas fa-users-cog"></i> Gestion des Utilisateurs</h1>
        <div class="header-actions">
            <button class="btn btn-primary" onclick="openModal('utilisateurModal')">
                <i class="fas fa-plus"></i> Nouvel Utilisateur
            </button>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?>">
            <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-list"></i> Liste des Utilisateurs</h3>
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchUsers" placeholder="Rechercher un utilisateur..." onkeyup="filterUsers()">
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="data-table" id="usersTable">
                    <thead>
                        <tr>
                            <th onclick="sortTable(0)">ID <i class="fas fa-sort"></i></th>
                            <th onclick="sortTable(1)">Nom <i class="fas fa-sort"></i></th>
                            <th onclick="sortTable(2)">Prénom <i class="fas fa-sort"></i></th>
                            <th onclick="sortTable(3)">Email <i class="fas fa-sort"></i></th>
                            <th>Téléphone</th>
                            <th onclick="sortTable(5)">Rôle <i class="fas fa-sort"></i></th>
                            <th onclick="sortTable(6)">Date de création <i class="fas fa-sort"></i></th>
                            <th>Dernière connexion</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($utilisateurs)): ?>
                            <tr>
                                <td colspan="9" class="text-center">Aucun utilisateur trouvé.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($utilisateurs as $user): ?>
                                <tr data-user-id="<?= $user['id'] ?>">
                                    <td><?= htmlspecialchars($user['id']) ?></td>
                                    <td><?= htmlspecialchars($user['nom']) ?></td>
                                    <td><?= htmlspecialchars($user['prenom']) ?></td>
                                    <td><?= htmlspecialchars($user['email']) ?></td>
                                    <td><?= htmlspecialchars($user['telephone'] ?? '-') ?></td>
                                    <td>
                                        <span class="badge badge-<?= $user['role'] === 'admin' ? 'danger' : ($user['role'] === 'agent' ? 'warning' : 'info') ?>">
                                            <?= htmlspecialchars(ucfirst($user['role'])) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($user['date_creation']))) ?></td>
                                    <td><?= $user['dernier_connexion'] ? htmlspecialchars(date('d/m/Y H:i', strtotime($user['dernier_connexion']))) : 'Jamais' ?></td>
                                    <td class="actions">
                                        <button class="btn-icon btn-edit" onclick="editUser(<?= $user['id'] ?>)" title="Modifier">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                            <button class="btn-icon btn-delete" onclick="deleteUser(<?= $user['id'] ?>, '<?= htmlspecialchars($user['prenom'] . ' ' . $user['nom']) ?>')" title="Supprimer">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
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

<!-- Modal d'ajout/modification d'utilisateur -->
<div id="utilisateurModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle"><i class="fas fa-user-plus"></i> Nouvel Utilisateur</h2>
            <button class="modal-close" onclick="closeModal('utilisateurModal')">&times;</button>
        </div>
        <form id="utilisateurForm" method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" id="formAction" value="ajouter">
            <input type="hidden" name="id" id="userId" value="">
            
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label for="nom">Nom <span class="required">*</span></label>
                        <input type="text" id="nom" name="nom" required placeholder="Entrez le nom">
                    </div>
                    <div class="form-group">
                        <label for="prenom">Prénom <span class="required">*</span></label>
                        <input type="text" id="prenom" name="prenom" required placeholder="Entrez le prénom">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="email">Email <span class="required">*</span></label>
                        <input type="email" id="email" name="email" required placeholder="entrez@email.com">
                    </div>
                    <div class="form-group">
                        <label for="telephone">Téléphone</label>
                        <input type="tel" id="telephone" name="telephone" placeholder="Numéro de téléphone">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="role">Rôle <span class="required">*</span></label>
                        <select id="role" name="role" required>
                            <option value="client">Client</option>
                            <option value="agent">Agent</option>
                            <option value="admin">Administrateur</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="mot_de_passe" id="passwordLabel">Mot de passe <span class="required">*</span></label>
                        <div class="password-input">
                            <input type="password" id="mot_de_passe" name="mot_de_passe" placeholder="Mot de passe">
                            <button type="button" class="password-toggle" onclick="togglePassword('mot_de_passe')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small id="passwordHint" class="form-hint">Laissez vide pour conserver le mot de passe actuel</small>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('utilisateurModal')">
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
<div id="deleteModal" class="modal">
    <div class="modal-content modal-sm">
        <div class="modal-header">
            <h2><i class="fas fa-exclamation-triangle text-warning"></i> Confirmer la suppression</h2>
            <button class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
        </div>
        <div class="modal-body">
            <p>Êtes-vous sûr de vouloir supprimer l'utilisateur <strong id="deleteUserName"></strong> ?</p>
            <p class="text-warning"><i class="fas fa-info-circle"></i> Cette action est irréversible.</p>
        </div>
        <form method="POST" id="deleteForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="supprimer">
            <input type="hidden" name="id" id="deleteUserId" value="">
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('deleteModal')">
                    <i class="fas fa-times"></i> Annuler
                </button>
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Supprimer
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const csrfToken = <?php echo json_encode(csrf_token()); ?>;
// Soumission du formulaire via AJAX pour le système SPA
document.getElementById('utilisateurForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enregistrement...';
    submitBtn.disabled = true;
    
    fetch('views/admin/gestion_utilisateurs.php', {
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
            closeModal('utilisateurModal');
            // Recharger la page après un court délai
            setTimeout(() => {
                loadPage('views/admin/gestion_utilisateurs.php', 'Utilisateurs');
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
document.getElementById('deleteForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Suppression...';
    submitBtn.disabled = true;
    
    fetch('views/admin/gestion_utilisateurs.php', {
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
            closeModal('deleteModal');
            // Recharger la page après un court délai
            setTimeout(() => {
                loadPage('views/admin/gestion_utilisateurs.php', 'Utilisateurs');
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
    // Initialiser le tri de la table
    initTableSort();
});

function filterUsers() {
    const searchTerm = document.getElementById('searchUsers').value.toLowerCase();
    const rows = document.querySelectorAll('#usersTable tbody tr');
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
    });
}

function sortTable(colIndex) {
    const table = document.getElementById('usersTable');
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const headers = table.querySelectorAll('th');
    
    const currentSort = headers[colIndex].getAttribute('data-sort');
    const newSort = currentSort === 'asc' ? 'desc' : 'asc';
    
    // Reinitialiser tous les indicateurs de tri
    headers.forEach(th => {
        th.removeAttribute('data-sort');
        th.querySelector('i').className = 'fas fa-sort';
    });
    
    // Trier les lignes
    rows.sort((a, b) => {
        const aText = a.cells[colIndex].textContent.trim();
        const bText = b.cells[colIndex].textContent.trim();
        
        // Essayer de comparer comme des nombres si possible
        const aNum = parseFloat(aText);
        const bNum = parseFloat(bText);
        
        if (!isNaN(aNum) && !isNaN(bNum)) {
            return newSort === 'asc' ? aNum - bNum : bNum - aNum;
        }
        
        return newSort === 'asc' ? aText.localeCompare(bText) : bText.localeCompare(aText);
    });
    
    // Mettre a jour les icones
    headers[colIndex].setAttribute('data-sort', newSort);
    headers[colIndex].querySelector('i').className = newSort === 'asc' ? 'fas fa-sort-up' : 'fas fa-sort-down';
    
    // Reinserer les lignes dans l'ordre
    rows.forEach(row => tbody.appendChild(row));
}

function editUser(userId) {
    const row = document.querySelector(`tr[data-user-id="${userId}"]`);
    if (!row) return;
    
    const cells = row.querySelectorAll('td');
    
    document.getElementById('formAction').value = 'modifier';
    document.getElementById('userId').value = userId;
    document.getElementById('nom').value = cells[1].textContent;
    document.getElementById('prenom').value = cells[2].textContent;
    document.getElementById('email').value = cells[3].textContent;
    document.getElementById('telephone').value = cells[4].textContent === '-' ? '' : cells[4].textContent;
    
    // Determiner le role
    const roleBadge = cells[5].querySelector('.badge');
    const role = roleBadge.textContent.toLowerCase();
    document.getElementById('role').value = role;
    
    // Pour la modification, le mot de passe est optionnel
    document.getElementById('mot_de_passe').value = '';
    document.getElementById('mot_de_passe').removeAttribute('required');
    document.getElementById('passwordLabel').innerHTML = 'Mot de passe <span class="optional">(optionnel)</span>';
    document.getElementById('passwordHint').style.display = 'block';
    
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-edit"></i> Modifier l\'utilisateur';
    
    openModal('utilisateurModal');
}

function deleteUser(userId, userName) {
    document.getElementById('deleteUserId').value = userId;
    document.getElementById('deleteUserName').textContent = userName;
    openModal('deleteModal');
}

// Reinitialiser le formulaire lors de l'ouverture du modal pour ajout
document.querySelector('button[onclick="openModal(\'utilisateurModal\')"]')?.addEventListener('click', function() {
    document.getElementById('utilisateurForm').reset();
    document.getElementById('formAction').value = 'ajouter';
    document.getElementById('userId').value = '';
    document.getElementById('mot_de_passe').setAttribute('required', 'required');
    document.getElementById('passwordLabel').innerHTML = 'Mot de passe <span class="required">*</span>';
    document.getElementById('passwordHint').style.display = 'none';
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-plus"></i> Nouvel Utilisateur';
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
