<?php
/**
 * Page Mon Compte - Version compacte
 * Compatible avec le système SPA
 */

// Verification de la connexion
require_once __DIR__ . '/../../utils/session.php';
SessionManager::start();
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="access-denied">Accès refusé. Veuillez vous connecter.</div>';
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/password_policy.php';

$userId = $_SESSION['user_id'];
$message = '';
$messageType = '';

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        switch ($action) {
            case 'update_profile':
                $nom = trim($_POST['nom'] ?? '');
                $prenom = trim($_POST['prenom'] ?? '');
                $telephone = trim($_POST['telephone'] ?? '');
                $adresse = trim($_POST['adresse'] ?? '');
                
                if (empty($nom) || empty($prenom)) {
                    $message = 'Le nom et le prénom sont obligatoires.';
                    $messageType = 'error';
                } else {
                    $stmt = $db->prepare("
                        UPDATE utilisateurs 
                        SET nom = ?, prenom = ?, telephone = ?, adresse = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$nom, $prenom, $telephone, $adresse, $userId]);
                    $message = 'Profil mis à jour avec succès.';
                    $messageType = 'success';
                    
                    // Mettre à jour la session
                    $_SESSION['user_nom'] = $nom;
                    $_SESSION['user_prenom'] = $prenom;
                }
                break;
                
            case 'change_password':
                $mot_de_passe_actuel = $_POST['mot_de_passe_actuel'] ?? '';
                $nouveau_mot_de_passe = $_POST['nouveau_mot_de_passe'] ?? '';
                $confirmer_mot_de_passe = $_POST['confirmer_mot_de_passe'] ?? '';
                
                // Verifier le mot de passe actuel
                $stmt = $db->prepare("SELECT mot_de_passe FROM utilisateurs WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
                
                if (!$user || !password_verify($mot_de_passe_actuel, $user['mot_de_passe'])) {
                    $message = 'Le mot de passe actuel est incorrect.';
                    $messageType = 'error';
                } elseif ($nouveau_mot_de_passe !== $confirmer_mot_de_passe) {
                    $message = 'Les nouveaux mots de passe ne correspondent pas.';
                    $messageType = 'error';
                } elseif (!empty(validatePasswordPolicy($nouveau_mot_de_passe))) {
                    $message = validatePasswordPolicy($nouveau_mot_de_passe)[0];
                    $messageType = 'error';
                } else {
                    $nouveau_mot_de_passe_hash = password_hash($nouveau_mot_de_passe, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?");
                    $stmt->execute([$nouveau_mot_de_passe_hash, $userId]);
                    $message = 'Mot de passe modifié avec succès.';
                    $messageType = 'success';
                }
                break;
        }
    } catch (PDOException $e) {
        $message = user_error_message($e, 'mon_compte.update', 'Erreur lors de la mise à jour du profil.');
        $messageType = 'error';
    }
}

// Recuperer les informations de l'utilisateur
try {
    $database = new Database();
    $db = $database->getConnection();
    
    $stmt = $db->prepare("SELECT * FROM utilisateurs WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Valeurs par défaut si les clés n'existent pas
    $user = array_merge([
        'nom' => '',
        'prenom' => '',
        'email' => '',
        'telephone' => '',
        'adresse' => '',
        'role' => 'utilisateur',
        'date_creation' => date('Y-m-d H:i:s')
    ], $user ?? []);
    
    // Recuperer les statistiques du client
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_colis,
            SUM(CASE WHEN statut = 'livre' THEN 1 ELSE 0 END) as colis_livres,
            SUM(CASE WHEN statut IN ('preparation', 'en_livraison', 'en_attente') THEN 1 ELSE 0 END) as colis_en_cours,
            SUM(CASE WHEN statut = 'annule' THEN 1 ELSE 0 END) as colis_annules
        FROM colis
        WHERE utilisateur_id = ?
    ");
    $stmt->execute([$userId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Valeurs par défaut pour les statistiques
    $stats = array_merge([
        'total_colis' => 0,
        'colis_livres' => 0,
        'colis_en_cours' => 0,
        'colis_annules' => 0
    ], $stats ?? []);
    
    // Recuperer les dernieres activites
    try {
        $stmt = $db->prepare("
            SELECT c.id, c.reference_colis, c.code_tracking, c.statut, c.date_creation, 
                   c.nom_destinataire, c.description, c.poids, c.description_colis
            FROM colis c
            WHERE c.utilisateur_id = ?
            ORDER BY c.date_creation DESC
            LIMIT 5
        ");
        $stmt->execute([$userId]);
        $dernieres_activites = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $dernieres_activites = [];
    }
    
} catch (PDOException $e) {
    $user = [
        'nom' => '',
        'prenom' => '',
        'email' => '',
        'telephone' => '',
        'adresse' => '',
        'role' => 'utilisateur',
        'date_creation' => date('Y-m-d H:i:s')
    ];
    $stats = ['total_colis' => 0, 'colis_livres' => 0, 'colis_en_cours' => 0, 'colis_annules' => 0];
    $dernieres_activites = [];
    $message = user_error_message($e, 'mon_compte.fetch', 'Erreur lors de la récupération des données.');
    $messageType = 'error';
}

$roleLabels = [
    'admin' => 'Administrateur',
    'agent' => 'Agent de livraison',
    'utilisateur' => 'Client'
];
?>

<!-- Contenu pour le système SPA -->
<div id="page-content" class="mon-compte-page">
<div class="page-container">
    <div class="page-header">
        <h1><i class="fas fa-user-circle"></i> Mon Compte</h1>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?>">
            <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="dashboard-grid">
        <!-- Profil utilisateur -->
        <div class="card profile-card">
            <div class="card-body">
                <div class="profile-header">
                    <div class="avatar-small">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="profile-info">
                        <h3><?= htmlspecialchars(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? '')) ?></h3>
                        <span class="badge badge-<?= ($user['role'] ?? '') === 'admin' ? 'danger' : 'info' ?>">
                            <?= htmlspecialchars($roleLabels[$user['role']] ?? ucfirst($user['role'] ?? 'utilisateur')) ?>
                        </span>
                    </div>
                </div>
                <div class="profile-details compact">
                    <div class="detail-row">
                        <i class="fas fa-envelope"></i>
                        <span><?= htmlspecialchars($user['email'] ?? 'Non disponible') ?></span>
                    </div>
                    <div class="detail-row">
                        <i class="fas fa-phone"></i>
                        <span><?= htmlspecialchars($user['telephone'] ?? 'Non renseigné') ?></span>
                    </div>
                    <div class="detail-row">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?= htmlspecialchars($user['adresse'] ?? 'Non renseignée') ?></span>
                    </div>
                    <div class="detail-row">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Membre depuis <?= date('d/m/Y', strtotime($user['date_creation'] ?? 'now')) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistiques compactes -->
        <div class="stats-grid compact">
            <div class="stat-card compact">
                <div class="stat-icon bg-primary">
                    <i class="fas fa-box"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-value"><?= $stats['total_colis'] ?? 0 ?></span>
                    <span class="stat-label">Total</span>
                </div>
            </div>
            <div class="stat-card compact">
                <div class="stat-icon bg-success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-value"><?= $stats['colis_livres'] ?? 0 ?></span>
                    <span class="stat-label">Livrés</span>
                </div>
            </div>
            <div class="stat-card compact">
                <div class="stat-icon bg-warning">
                    <i class="fas fa-truck"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-value"><?= $stats['colis_en_cours'] ?? 0 ?></span>
                    <span class="stat-label">En Cours</span>
                </div>
            </div>
            <div class="stat-card compact">
                <div class="stat-icon bg-secondary">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-value"><?= $stats['colis_annules'] ?? 0 ?></span>
                    <span class="stat-label">Annulés</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Onglets -->
    <div class="tabs-container compact">
        <div class="tabs compact">
            <button class="tab compact active" data-tab="profile">
                <i class="fas fa-user-edit"></i> Profil
            </button>
            <button class="tab compact" data-tab="password">
                <i class="fas fa-lock"></i> Mot de passe
            </button>
            <button class="tab compact" data-tab="activity">
                <i class="fas fa-history"></i> Activité
            </button>
        </div>
        
        <div class="tab-content active" id="profile">
            <div class="card compact">
                <div class="card-header compact">
                    <h4><i class="fas fa-user-edit"></i> Informations personnelles</h4>
                </div>
                <div class="card-body compact">
                    <form method="POST" class="profile-form">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="form-row compact">
                            <div class="form-group compact">
                                <label for="nom">Nom <span class="required">*</span></label>
                                <input type="text" id="nom" name="nom" class="form-control compact" 
                                       value="<?= htmlspecialchars($user['nom'] ?? '') ?>" required>
                            </div>
                            <div class="form-group compact">
                                <label for="prenom">Prénom <span class="required">*</span></label>
                                <input type="text" id="prenom" name="prenom" class="form-control compact"
                                       value="<?= htmlspecialchars($user['prenom'] ?? '') ?>" required>
                            </div>
                        </div>
                        
                        <div class="form-row compact">
                            <div class="form-group compact">
                                <label for="email">Email</label>
                                <input type="email" id="email" class="form-control compact" 
                                       value="<?= htmlspecialchars($user['email'] ?? '') ?>" disabled>
                                <small class="form-hint">L'email ne peut pas être modifié.</small>
                            </div>
                            <div class="form-group compact">
                                <label for="telephone">Téléphone</label>
                                <input type="tel" id="telephone" name="telephone" class="form-control compact"
                                       value="<?= htmlspecialchars($user['telephone'] ?? '') ?>">
                            </div>
                        </div>
                        
                        <div class="form-group compact">
                            <label for="adresse">Adresse</label>
                            <textarea id="adresse" name="adresse" class="form-control compact" rows="2"><?= htmlspecialchars($user['adresse'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="form-actions compact">
                            <button type="submit" class="btn btn-primary compact">
                                <i class="fas fa-save"></i> Enregistrer
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="tab-content" id="password">
            <div class="card compact">
                <div class="card-header compact">
                    <h4><i class="fas fa-lock"></i> Changer le mot de passe</h4>
                </div>
                <div class="card-body compact">
                    <form method="POST" class="password-form">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="form-group compact">
                            <label for="mot_de_passe_actuel">Mot de passe actuel <span class="required">*</span></label>
                            <div class="password-input compact">
                                <input type="password" id="mot_de_passe_actuel" name="mot_de_passe_actuel" 
                                       class="form-control compact" required>
                                <button type="button" class="password-toggle" onclick="togglePassword('mot_de_passe_actuel', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="form-group compact">
                            <label for="nouveau_mot_de_passe">Nouveau mot de passe <span class="required">*</span></label>
                            <div class="password-input compact">
                                <input type="password" id="nouveau_mot_de_passe" name="nouveau_mot_de_passe" 
                                       class="form-control compact" required minlength="8">
                                <button type="button" class="password-toggle" onclick="togglePassword('nouveau_mot_de_passe', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <small class="form-hint">Minimum 8 caractères.</small>
                        </div>
                        
                        <div class="form-group compact">
                            <label for="confirmer_mot_de_passe">Confirmation <span class="required">*</span></label>
                            <div class="password-input compact">
                                <input type="password" id="confirmer_mot_de_passe" name="confirmer_mot_de_passe" 
                                       class="form-control compact" required>
                                <button type="button" class="password-toggle" onclick="togglePassword('confirmer_mot_de_passe', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="form-actions compact">
                            <button type="submit" class="btn btn-primary compact">
                                <i class="fas fa-key"></i> Changer
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="tab-content" id="activity">
            <div class="card compact">
                <div class="card-header compact">
                    <h4><i class="fas fa-history"></i> Activité récente</h4>
                </div>
                <div class="card-body compact">
                    <?php if (empty($dernieres_activites)): ?>
                        <div class="empty-state compact">
                            <i class="fas fa-inbox"></i>
                            <p>Aucune activité récente.</p>
                        </div>
                    <?php else: ?>
                        <div class="activity-list compact">
                            <?php foreach ($dernieres_activites as $activite): ?>
                                <div class="activity-item compact">
                                    <div class="activity-icon compact">
                                        <i class="fas fa-box"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-header compact">
                                            <span class="activity-tracking"><?= htmlspecialchars($activite['reference_colis'] ?? $activite['code_tracking'] ?? 'N/A') ?></span>
                                            <span class="activity-date"><?= date('d/m/Y H:i', strtotime($activite['date_creation'] ?? 'now')) ?></span>
                                        </div>
                                        <div class="activity-details compact">
                                            <span class="badge badge-<?= 
                                                ($activite['statut'] ?? '') === 'livre' ? 'success' : 
                                                (($activite['statut'] ?? '') === 'annule' ? 'danger' : 
                                                (($activite['statut'] ?? '') === 'en_livraison' ? 'warning' : 'info'))
                                            ?>">
                                                <?= htmlspecialchars(ucfirst($activite['statut'] ?? 'inconnu')) ?>
                                            </span>
                                            <span class="activity-dest compact">→ <?= htmlspecialchars($activite['nom_destinataire'] ?? $activite['description'] ?? 'Non spécifié') ?></span>
                                        </div>
                                    </div>
                                    <button class="btn btn-sm btn-outline compact" onclick="viewColisDetails(<?= $activite['id'] ?? 0 ?>)">
                                        Détails
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<style>
:root {
    --primary: #00B4D8;
    --primary-dark: #0091B8;
    --bg-white: #fff;
    --bg-light: #f8fafc;
    --text-primary: #0f172a;
    --text-secondary: #475569;
    --text-muted: #64748b;
    --border: #e2e8f0;
    --success: #22c55e;
    --warning: #f59e0b;
    --error: #ef4444;
    
    --radius-sm: 4px;
    --radius-md: 6px;
    --radius-lg: 8px;
}

.page-container {
    max-width: 1000px;
    margin: 0 auto;
    padding: 1rem;
}

.page-header {
    margin-bottom: 1.5rem;
}

.page-header h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin: 0;
}

.page-header h1 i {
    color: var(--primary);
    font-size: 1.75rem;
}

.dashboard-grid {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 1.5rem;
    margin-bottom: 2rem;
}

@media (max-width: 992px) {
    .dashboard-grid {
        grid-template-columns: 1fr;
    }
}

/* Stats Grid */
.stats-grid.compact {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
}

.stat-card {
    background: var(--bg-white);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 1rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: all 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 16px rgba(0, 180, 216, 0.12);
    border-color: var(--primary);
}

.stat-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    color: white;
    flex-shrink: 0;
}

.bg-primary { background: var(--primary); }
.bg-success { background: var(--success); }
.bg-warning { background: var(--warning); }
.bg-secondary { background: var(--text-muted); }

.stat-info {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.stat-value {
    font-size: 1.4rem;
    font-weight: 700;
    color: var(--text-primary);
}

.stat-label {
    font-size: 0.7rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

@media (max-width: 768px) {
    .stats-grid.compact {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 480px) {
    .stats-grid.compact {
        grid-template-columns: 1fr;
    }
}

/* Profile Card */
.profile-card {
    background: var(--bg-white);
    border: 2px solid var(--primary);
    border-radius: var(--radius-lg);
    overflow: hidden;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(0, 180, 216, 0.08);
}

.profile-card:hover {
    box-shadow: 0 8px 24px rgba(0, 180, 216, 0.15);
    transform: translateY(-2px);
}

.card-body {
    padding: 1.5rem;
}

.profile-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.5rem;
    padding-bottom: 1.5rem;
    border-bottom: 2px solid var(--border);
}

.avatar-small {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.5rem;
    flex-shrink: 0;
}

.profile-info h3 {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 0.5rem 0;
}

.badge {
    display: inline-block;
    padding: 0.3rem 0.7rem;
    border-radius: 20px;
    font-size: 0.65rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.badge-danger { background: rgba(239, 68, 68, 0.15); color: var(--error); }
.badge-info { background: rgba(0, 180, 216, 0.15); color: var(--primary); }
.badge-success { background: rgba(34, 197, 94, 0.15); color: var(--success); }

.profile-details.compact {
    display: flex;
    flex-direction: column;
    gap: 0.8rem;
}

.detail-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 0.85rem;
}

.detail-row i {
    width: 18px;
    height: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--primary);
    font-size: 0.9rem;
    flex-shrink: 0;
}

.detail-row span {
    color: var(--text-secondary);
}

/* Tabs */
.tabs-container.compact {
    margin-top: 2rem;
}

.tabs.compact {
    display: flex;
    gap: 0;
    border-bottom: 2px solid var(--border);
    margin-bottom: 0;
}

.tab.compact {
    padding: 1rem 1.5rem;
    background: transparent;
    border: none;
    color: var(--text-secondary);
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.25s ease;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    position: relative;
}

.tab.compact:hover {
    color: var(--primary);
    background: rgba(0, 180, 216, 0.05);
}

.tab.compact.active {
    color: var(--primary);
    font-weight: 600;
}

.tab.compact.active::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--primary);
    border-radius: 2px 2px 0 0;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.card.compact {
    background: var(--bg-white);
    border-radius: var(--radius-lg);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    margin-bottom: 0;
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
}

.card.compact:hover {
    box-shadow: 0 4px 12px rgba(0, 180, 216, 0.1);
}

.card-header.compact {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid var(--border-color);
    background: linear-gradient(135deg, rgba(0, 180, 216, 0.03), rgba(8, 145, 178, 0.03));
}

.card-header.compact h4 {
    font-size: 0.9rem;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.6rem;
    color: var(--text-dark);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.card-header.compact h4 i {
    color: var(--primary-color);
    font-size: 0.95rem;
}

.card-body.compact {
    padding: 1.2rem;
}

.form-row.compact {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}

@media (max-width: 768px) {
    .form-row.compact {
        grid-template-columns: 1fr;
    }
}

.form-group.compact {
    margin-bottom: 0.75rem;
}

.form-group.compact label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-dark);
    margin-bottom: 0.4rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.required {
    color: var(--danger-color);
    margin-left: 0.2rem;
}

.form-control.compact {
    width: 100%;
    padding: 0.6rem 0.85rem;
    border: 2px solid var(--border-color);
    border-radius: var(--radius-md);
    font-size: 0.8rem;
    font-family: 'Rajdhani', sans-serif;
    color: var(--text-dark);
    background: var(--bg-white);
    transition: all 0.3s ease;
}

.form-control.compact:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(0, 180, 216, 0.15);
}

.form-control.compact:hover:not(:focus) {
    border-color: rgba(0, 180, 216, 0.4);
    box-shadow: 0 0 0 2px rgba(0, 180, 216, 0.05);
}

.form-control.compact:disabled {
    background: var(--bg-light);
    color: var(--text-light);
    cursor: not-allowed;
    opacity: 0.6;
}

textarea.form-control.compact {
    min-height: 60px;
    resize: vertical;
}

.form-hint {
    font-size: 0.65rem;
    color: var(--text-light);
    margin-top: 0.25rem;
    display: block;
}

.password-input.compact {
    position: relative;
    display: flex;
    align-items: center;
    margin-bottom: 0.5rem;
}

.password-input.compact .form-control.compact {
    padding-right: 2.8rem;
    flex: 1;
    margin-bottom: 0;
}

.password-toggle {
    position: absolute;
    right: 0.6rem;
    top: 50%;
    transform: translateY(-50%);
    background: transparent;
    border: 1px solid transparent;
    color: var(--text-light);
    cursor: pointer;
    padding: 0.5rem;
    border-radius: var(--radius-md);
    transition: all 0.25s ease;
    width: 34px;
    height: 34px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
}

.password-toggle:hover {
    background: rgba(0, 180, 216, 0.15);
    color: var(--primary-color);
    border-color: rgba(0, 180, 216, 0.3);
    transform: translateY(-50%) scale(1.05);
}

.password-toggle:active {
    transform: translateY(-50%) scale(0.95);
}

.password-toggle i {
    font-size: 0.85rem;
    pointer-events: none;
}

.form-actions.compact {
    margin-top: 1.2rem;
    padding-top: 1rem;
    border-top: 1px solid var(--border-color);
    display: flex;
    gap: 0.75rem;
    justify-content: flex-start;
}

.btn.compact {
    padding: 0.6rem 1.5rem;
    font-size: 0.75rem;
    gap: 0.5rem;
    border-radius: var(--radius-md);
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.btn-primary.compact {
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    color: white;
    border: none;
    font-family: 'Orbitron', sans-serif;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 180, 216, 0.3);
    position: relative;
    overflow: hidden;
}

.btn-primary.compact::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: rgba(255, 255, 255, 0.15);
    transition: left 0.3s ease;
}

.btn-primary.compact:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 180, 216, 0.5);
}

.btn-primary.compact:hover::before {
    left: 100%;
}

.btn-primary.compact:active {
    transform: translateY(0px);
}

.btn-primary.compact:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

.btn-outline.compact {
    padding: 0.35rem 0.75rem;
    font-size: 0.7rem;
    border: 1.5px solid var(--primary-color);
    background: transparent;
    color: var(--primary-color);
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-outline.compact:hover {
    background: var(--primary-color);
    color: white;
    transform: translateY(-1px);
}

.btn-sm {
    padding: 0.4rem 0.85rem;
    font-size: 0.7rem;
    border: 1.5px solid var(--border-color);
    background: var(--bg-white);
    color: var(--primary-color);
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: all 0.2s ease;
    font-weight: 500;
}

.btn-sm:hover {
    background: var(--primary-color);
    color: white;
    border-color: var(--primary-color);
    transform: translateY(-1px);
}

.alert {
    padding: 0.65rem 0.85rem;
    border-radius: var(--radius-md);
    margin-bottom: 0.85rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.75rem;
}

.alert-success {
    background: #dcfce7;
    color: #166534;
    border-left: 3px solid var(--success-color);
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border-left: 3px solid var(--danger-color);
}

.alert i {
    font-size: 0.9rem;
}

.activity-list.compact {
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
}

.activity-item.compact {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.65rem;
    background: var(--bg-light);
    border-radius: var(--radius-md);
    transition: all 0.2s;
    border: 1px solid var(--border-color);
}

.activity-item.compact:hover {
    background: #e0f2fe;
    box-shadow: 0 2px 6px rgba(0, 180, 216, 0.1);
}

.activity-icon.compact {
    width: 32px;
    height: 32px;
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 0.8rem;
    flex-shrink: 0;
}

.activity-content {
    flex: 1;
    min-width: 0;
}

.activity-header.compact {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.3rem;
    gap: 0.5rem;
}

.activity-tracking {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-dark);
}

.activity-date {
    font-size: 0.65rem;
    color: var(--text-light);
}

.activity-details.compact {
    display: flex;
    align-items: center;
    gap: 0.6rem;
}

.activity-dest.compact {
    font-size: 0.7rem;
    color: var(--text-medium);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.empty-state.compact {
    text-align: center;
    padding: 2rem 1rem;
}

.empty-state.compact i {
    font-size: 2.5rem;
    color: var(--text-light);
    margin-bottom: 0.75rem;
}

.empty-state.compact p {
    font-size: 0.85rem;
    color: var(--text-medium);
    margin: 0;
}

@media (max-width: 768px) {
    .page-container {
        padding: 0.6rem;
    }
    
    .page-header h1 {
        font-size: 1rem;
    }
    
    .dashboard-grid {
        gap: 0.65rem;
    }
    
    .avatar-small {
        width: 40px;
        height: 40px;
        font-size: 1rem;
    }
    
    .profile-info h3 {
        font-size: 0.9rem;
    }
    
    .tabs.compact {
        overflow-x: auto;
        padding-bottom: 0.3rem;
    }
    
    .tab.compact {
        padding: 0.5rem 0.85rem;
        font-size: 0.7rem;
        white-space: nowrap;
    }
    
    .activity-header.compact {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.3rem;
    }
    
    .activity-dest.compact {
        max-width: 100%;
    }
}

@media (max-width: 480px) {
    .page-container {
        padding: 0.5rem;
    }
    
    .page-header h1 {
        font-size: 0.95rem;
        gap: 0.4rem;
    }
    
    .card-body,
    .card-body.compact {
        padding: 0.75rem;
    }
    
    .btn.compact {
        padding: 0.5rem 1rem;
        font-size: 0.7rem;
    }
    
    .activity-item.compact {
        flex-direction: column;
        align-items: stretch;
        text-align: center;
    }
    
    .activity-icon.compact {
        align-self: center;
    }
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>

<script>
const csrfToken = <?php echo json_encode(csrf_token()); ?>;
document.addEventListener('DOMContentLoaded', function() {
    const tabs = document.querySelectorAll('.tab');
    const tabContents = document.querySelectorAll('.tab-content');
    
    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            const tabId = this.dataset.tab;
            tabs.forEach(t => t.classList.remove('active'));
            tabContents.forEach(c => c.classList.remove('active'));
            this.classList.add('active');
            document.getElementById(tabId).classList.add('active');
        });
    });
    
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ...';
            submitBtn.disabled = true;
            
            try {
                const response = await fetch('views/client/mon_compte.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': csrfToken
                    }
                });
                
                if (!response.ok) {
                    throw new Error('Erreur réseau');
                }
                
                if (typeof loadPage === 'function') {
                    await loadPage('views/client/mon_compte.php', 'Mon Compte');
                } else {
                    window.location.reload();
                }
                
            } catch (error) {
                console.error('Erreur:', error);
                
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-error';
                alertDiv.innerHTML = `
                    <i class="fas fa-exclamation-circle"></i>
                    Erreur lors de l'envoi.
                `;
                
                const firstAlert = document.querySelector('.alert');
                if (firstAlert) {
                    firstAlert.parentNode.insertBefore(alertDiv, firstAlert.nextSibling);
                } else {
                    document.querySelector('.page-header').after(alertDiv);
                }
                
                setTimeout(() => {
                    if (alertDiv.parentNode) {
                        alertDiv.remove();
                    }
                }, 3000);
                
            } finally {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        });
    });
    
    const newPassword = document.getElementById('nouveau_mot_de_passe');
    const confirmPassword = document.getElementById('confirmer_mot_de_passe');
    
    if (newPassword && confirmPassword) {
        function validatePasswords() {
            if (newPassword.value && confirmPassword.value) {
                if (newPassword.value !== confirmPassword.value) {
                    confirmPassword.style.borderColor = '#ef4444';
                    confirmPassword.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.15)';
                } else {
                    confirmPassword.style.borderColor = '#22c55e';
                    confirmPassword.style.boxShadow = '0 0 0 3px rgba(34, 197, 94, 0.15)';
                }
            } else {
                confirmPassword.style.borderColor = '';
                confirmPassword.style.boxShadow = '';
            }
        }
        
        newPassword.addEventListener('input', validatePasswords);
        confirmPassword.addEventListener('input', validatePasswords);
    }
    
    const passwordToggles = document.querySelectorAll('.password-toggle');
    passwordToggles.forEach(toggle => {
        toggle.setAttribute('aria-label', 'Afficher le mot de passe');
        toggle.setAttribute('type', 'button');
    });
});

function viewColisDetails(colisId) {
    if (colisId > 0) {
        if (typeof loadPage === 'function') {
            loadPage(`views/client/colis_details.php?id=${colisId}`, 'Détails Colis');
        } else {
            window.location.href = `views/client/colis_details.php?id=${colisId}`;
        }
    }
}

function togglePassword(fieldId, button) {
    const field = document.getElementById(fieldId);
    const icon = button.querySelector('i');
    
    if (field.type === 'password') {
        field.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
        button.setAttribute('aria-label', 'Cacher le mot de passe');
    } else {
        field.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
        button.setAttribute('aria-label', 'Afficher le mot de passe');
    }
}
</script>
