<?php
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

// Recuperer l'ID du colis a modifier
$colisId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

try {
    $database = new Database();
    $db = $database->getConnection();

    // Fallback: si aucun ID n'est fourni, ouvrir le dernier colis "en_attente" modifiable
    if ($colisId <= 0) {
        if ($userRole === 'admin') {
            $stmt = $db->prepare("SELECT id FROM colis WHERE statut = 'en_attente' ORDER BY date_creation DESC LIMIT 1");
            $stmt->execute();
        } else {
            $stmt = $db->prepare("
                SELECT id 
                FROM colis 
                WHERE utilisateur_id = ? AND statut = 'en_attente' 
                ORDER BY date_creation DESC 
                LIMIT 1
            ");
            $stmt->execute([$userId]);
        }

        $fallbackId = (int) ($stmt->fetchColumn() ?: 0);
        if ($fallbackId > 0) {
            header('Location: modifier_colis.php?id=' . $fallbackId);
            exit;
        }

        echo '<div class="alert alert-warning">Aucun colis modifiable trouvé. Ouvrez cette page avec un paramètre <code>?id=...</code> depuis "Mes colis".</div>';
        exit;
    }
    
    // Recuperer les informations du colis
    if ($userRole === 'admin') {
        $stmt = $db->prepare("SELECT * FROM colis WHERE id = ?");
        $stmt->execute([$colisId]);
    } else {
        $stmt = $db->prepare("SELECT * FROM colis WHERE id = ? AND utilisateur_id = ?");
        $stmt->execute([$colisId, $userId]);
    }
    $colis = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$colis) {
        echo '<div class="alert alert-error">Colis non trouvé ou vous n\'avez pas l\'autorisation de le modifier.</div>';
        exit;
    }
    
    // Verifier que le colis peut etre modifie
    if (!in_array($colis['statut'], ['en_attente'])) {
        $message = 'Ce colis ne peut plus être modifié car il est déjà en cours de livraison ou livré.';
        $messageType = 'warning';
    }
    
} catch (PDOException $e) {
    $errorMessage = user_error_message($e, 'modifier_colis.fetch', 'Erreur de base de données.');
    echo '<div class="alert alert-error">' . htmlspecialchars($errorMessage) . '</div>';
    exit;
}

// Traitement de la modification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $messageType !== 'warning') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'modifier') {
        try {
            $db->beginTransaction();
            
            $description = trim($_POST['description'] ?? '');
            $poids = $_POST['poids'] ?? null;
            $dimensions = trim($_POST['dimensions'] ?? '');
            $instructions = trim($_POST['instructions'] ?? '');
            $fragile = isset($_POST['fragile']) ? 1 : 0;
            $urgent = isset($_POST['urgent']) ? 1 : 0;
            $descLen = function_exists('mb_strlen') ? mb_strlen($description) : strlen($description);
            $instrLen = function_exists('mb_strlen') ? mb_strlen($instructions) : strlen($instructions);
            $dimLen = function_exists('mb_strlen') ? mb_strlen($dimensions) : strlen($dimensions);
            
            // Validation
            if (empty($description)) {
                $message = 'La description est requise.';
                $messageType = 'error';
            } elseif ($descLen > 500) {
                $message = 'La description ne doit pas dépasser 500 caractères.';
                $messageType = 'error';
            } elseif ($instrLen > 500) {
                $message = 'Les instructions ne doivent pas dépasser 500 caractères.';
                $messageType = 'error';
            } elseif ($dimLen > 500) {
                $message = 'Les dimensions ne doivent pas dépasser 500 caractères.';
                $messageType = 'error';
            } else {
                // Mettre a jour le colis
                $stmt = $db->prepare("
                    UPDATE colis SET
                        description = ?,
                        poids = ?,
                        dimensions = ?,
                        instructions = ?,
                        fragile = ?,
                        urgent = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $description,
                    $poids ? (float)$poids : null,
                    $dimensions ?: null,
                    $instructions ?: null,
                    $fragile,
                    $urgent,
                    $colisId
                ]);
                
                // Ajouter une notification
                $stmt = $db->prepare("
                    INSERT INTO notifications (utilisateur_id, type, titre, message, priorite, date_envoi)
                    VALUES (?, 'colis', 'Colis modifié', ?, 'normal', NOW())
                ");
                $stmt->execute([$userId, 'Votre colis #' . $colisId . ' a été modifié avec succès.']);
                
                $db->commit();
                $message = 'Colis modifié avec succès.';
                $messageType = 'success';
                
                // Recharger les donnees du colis
                $stmt = $db->prepare("SELECT * FROM colis WHERE id = ?");
                $stmt->execute([$colisId]);
                $colis = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
            $db->rollBack();
            $message = user_error_message($e, 'modifier_colis.update', 'Erreur lors de la modification du colis.');
            $messageType = 'error';
        }
    }
}

// Recuperer la liste des agents pour l'admin
$agents = [];
if ($userRole === 'admin') {
    try {
        $stmt = $db->query("SELECT id, nom, prenom, numero_agent, zone_livraison FROM agents ORDER BY nom, prenom");
        $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $agents = [];
    }
}

// Recuperer les notifications du colis (avec fallback)
$historique = [];
try {
    $stmt = $db->prepare("
        SELECT h.*, u.prenom, u.nom
        FROM historique_colis h
        LEFT JOIN utilisateurs u ON h.utilisateur_id = u.id
        WHERE h.colis_id = ?
        ORDER BY h.date_action DESC
        LIMIT 10
    ");
    $stmt->execute([$colisId]);
    $historique = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fallback vers la table notifications si historique_colis n'existe pas
    try {
        $stmt = $db->prepare("
            SELECT message as commentaire, date_envoi as date_action, titre as nouveau_statut
            FROM notifications
            WHERE utilisateur_id = ? AND type = 'colis'
            ORDER BY date_envoi DESC
            LIMIT 10
        ");
        $stmt->execute([$userId]);
        $historique = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e2) {
        $historique = [];
    }
}

$statusLabels = [
    'en_attente' => 'En attente',
    'preparation' => 'En préparation',
    'en_livraison' => 'En livraison',
    'livre' => 'Livré',
    'annule' => 'Annulé',
    'retour' => 'En retour'
];

$statusColors = [
    'en_attente' => 'secondary',
    'preparation' => 'info',
    'en_livraison' => 'warning',
    'livre' => 'success',
    'annule' => 'danger',
    'retour' => 'secondary'
];
?>

<div id="page-content">
<div class="page-container modifier-colis-page">
    <div class="page-header">
        <div class="header-left">
            <button class="btn btn-back" onclick="history.back()">
                <i class="fas fa-arrow-left"></i>
            </button>
            <h1>
                <i class="fas fa-edit"></i> Modifier le Colis
            </h1>
        </div>
        <div class="header-actions">
            <span class="badge badge-<?= $statusColors[$colis['statut']] ?? 'secondary' ?> badge-lg">
                <?= htmlspecialchars($statusLabels[$colis['statut']] ?? ucfirst($colis['statut'])) ?>
            </span>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?>">
            <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'warning' ? 'exclamation-triangle' : 'exclamation-circle') ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Informations du colis (lecture seule) -->
    <div class="info-bar">
        <div class="info-item">
            <span class="info-label">Code de tracking</span>
            <span class="info-value tracking-number"><?= htmlspecialchars($colis['code_tracking'] ?? 'N/A') ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">Date de création</span>
            <span class="info-value"><?= date('d/m/Y H:i', strtotime($colis['date_creation'])) ?></span>
        </div>
    </div>

    <div class="form-container">
        <?php if (in_array($colis['statut'], ['en_attente'])): ?>
            <form method="POST" id="modifierColisForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="modifier">
                
                <div class="form-section mc-section">
                    <h3><i class="fas fa-box"></i> Détails du Colis</h3>
                    
                    <div class="form-group mc-group">
                        <label for="description">Description du contenu <span class="required">*</span></label>
                        <textarea id="description" name="description" rows="3" required
                                  placeholder="Description détaillée du contenu du colis"><?= htmlspecialchars($colis['description'] ?? '') ?></textarea>
                    </div>
                    
                    <div class="form-row mc-row">
                        <div class="form-group mc-group">
                            <label for="poids">Poids (kg)</label>
                            <input type="number" id="poids" name="poids" 
                                   value="<?= htmlspecialchars($colis['poids'] ?? '') ?>"
                                   step="0.1" min="0" placeholder="0.0">
                        </div>
                        <div class="form-group mc-group">
                            <label for="dimensions">Dimensions</label>
                            <input type="text" id="dimensions" name="dimensions"
                                   value="<?= htmlspecialchars($colis['dimensions'] ?? '') ?>"
                                   placeholder="Ex: 30x20x15 cm">
                        </div>
                    </div>
                    
                    <div class="form-group mc-group">
                        <label for="instructions">Instructions de livraison</label>
                        <textarea id="instructions" name="instructions" rows="2"
                                  placeholder="Instructions spéciales pour la livraison (optionnel)"><?= htmlspecialchars($colis['instructions'] ?? '') ?></textarea>
                    </div>
                    
                    <div class="checkbox-group mc-checks">
                        <label class="checkbox-label">
                            <input type="checkbox" name="fragile" id="fragile" value="1"
                                   <?= ($colis['fragile'] ?? 0) ? 'checked' : '' ?>>
                            <i class="fas fa-exclamation-triangle text-warning"></i>
                            <span>Colis fragile</span>
                        </label>
                        
                        <label class="checkbox-label">
                            <input type="checkbox" name="urgent" id="urgent" value="1"
                                   <?= ($colis['urgent'] ?? 0) ? 'checked' : '' ?>>
                            <i class="fas fa-bolt text-danger"></i>
                            <span>Livraison urgente</span>
                        </label>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="history.back()">
                        <i class="fas fa-times"></i> Annuler
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Enregistrer les modifications
                    </button>
                </div>
            </form>
        <?php else: ?>
            <div class="alert alert-warning">
                <i class="fas fa-lock"></i>
                Ce colis ne peut plus être modifié car il est en cours de livraison ou déjà livré.
            </div>
            
            <div class="read-only-view">
                <div class="detail-row">
                    <div class="detail-card">
                        <h4>Détails du colis</h4>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="label">Description</span>
                                <span class="value"><?= htmlspecialchars($colis['description'] ?? 'Non spécifiée') ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="label">Poids</span>
                                <span class="value"><?= htmlspecialchars($colis['poids'] ?? 'Non spécifié') ?> kg</span>
                            </div>
                            <div class="detail-item">
                                <span class="label">Dimensions</span>
                                <span class="value"><?= htmlspecialchars($colis['dimensions'] ?? 'Non spécifiées') ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="label">Fragile</span>
                                <span class="value">
                                    <i class="fas <?= ($colis['fragile'] ?? 0) ? 'fa-check text-success' : 'fa-times text-muted' ?>"></i>
                                    <?= ($colis['fragile'] ?? 0) ? 'Oui' : 'Non' ?>
                                </span>
                            </div>
                            <div class="detail-item">
                                <span class="label">Urgent</span>
                                <span class="value">
                                    <i class="fas <?= ($colis['urgent'] ?? 0) ? 'fa-check text-danger' : 'fa-times text-muted' ?>"></i>
                                    <?= ($colis['urgent'] ?? 0) ? 'Oui' : 'Non' ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="history.back()">
                        <i class="fas fa-arrow-left"></i> Retour
                    </button>
                    <button type="button" class="btn btn-primary" onclick="generateColisPDF(<?= $colis['id'] ?>)">
                        <i class="fas fa-file-pdf"></i> Générer PDF
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Historique du colis -->
    <?php if (!empty($historique)): ?>
        <div class="card mt-4">
            <div class="card-header">
                <h3><i class="fas fa-history"></i> Historique des modifications</h3>
            </div>
            <div class="card-body">
                <div class="timeline-compact">
                    <?php foreach ($historique as $etape): ?>
                        <div class="timeline-item-compact">
                            <div class="timeline-marker-sm"></div>
                            <div class="timeline-content-sm">
                                <div class="timeline-header-sm">
                                    <span class="timeline-title-sm">
                                        <?php
                                        if ($etape['commentaire'] && $etape['commentaire'] !== $colis['statut']) {
                                            echo htmlspecialchars($etape['commentaire']);
                                        } else {
                                            echo 'Statut: ' . htmlspecialchars($statusLabels[$etape['nouveau_statut']] ?? ucfirst($etape['nouveau_statut']));
                                        }
                                        ?>
                                    </span>
                                    <span class="timeline-date-sm">
                                        <?= date('d/m/Y H:i', strtotime($etape['date_action'])) ?>
                                    </span>
                                </div>
                                <?php if ($etape['prenom']): ?>
                                    <span class="timeline-user-sm">
                                        Par: <?= htmlspecialchars($etape['prenom'] . ' ' . $etape['nom']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Styles pour la page Modifier Colis -->
<style>
/* Variables CSS du thème principal */
:root {
    --primary-cyan: #00B4D8;
    --primary-dark: #0891b2;
    --text-dark: #0f172a;
    --text-medium: #475569;
    --text-light: #64748b;
    --bg-light: #f8fafc;
    --bg-card: #ffffff;
    --border-light: #e2e8f0;
    --border-medium: #cbd5e1;
    --success-green: #22c55e;
    --warning-orange: #f59e0b;
    --danger-red: #ef4444;
    --font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

/* Reset et base */
#page-content {
    font-family: var(--font-family);
    color: var(--text-dark);
    line-height: 1.6;
    background-color: var(--bg-light);
    min-height: 100%;
}

#page-content * {
    box-sizing: border-box;
}

/* Container de page */
.page-container {
    padding: 1.25rem;
    max-width: 900px;
    margin: 0 auto;
}

/* Scope local pour eviter les conflits CSS inter-pages */
.modifier-colis-page .form-container {
    max-width: 700px;
    margin: 0 auto;
    padding: 1rem 1.1rem;
    background: #ffffff;
    border: 1px solid var(--border-light);
    border-radius: 18px;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
    overflow: visible;
}

/* Header de page */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
    gap: 1rem;
}

.header-left {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.page-header h1 {
    font-family: 'Orbitron', sans-serif;
    font-size: 1.3rem;
    color: var(--text-dark);
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin: 0;
}

.page-header h1 i {
    color: var(--primary-cyan);
}

.btn-back {
    background: var(--bg-card);
    border: 1px solid var(--border-light);
    border-radius: 8px;
    padding: 0.5rem 0.75rem;
    cursor: pointer;
    color: var(--text-medium);
    transition: all 0.3s ease;
}

.btn-back:hover {
    border-color: var(--primary-cyan);
    color: var(--primary-cyan);
}

/* Badges */
.badge {
    display: inline-block;
    padding: 0.35rem 0.85rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-lg {
    padding: 0.5rem 1rem;
    font-size: 0.8rem;
}

.badge-secondary { background: rgba(100, 116, 139, 0.1); color: #64748b; }
.badge-info { background: rgba(0, 180, 216, 0.1); color: var(--primary-cyan); }
.badge-warning { background: rgba(245, 158, 11, 0.1); color: var(--warning-orange); }
.badge-success { background: rgba(34, 197, 94, 0.1); color: var(--success-green); }
.badge-danger { background: rgba(239, 68, 68, 0.1); color: var(--danger-red); }

/* Alertes */
.alert {
    padding: 1rem 1.25rem;
    border-radius: 10px;
    margin-bottom: 1.25rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-weight: 500;
    animation: slideDown 0.3s ease;
}

.alert-success {
    background: linear-gradient(135deg, rgba(34, 197, 94, 0.15), rgba(34, 197, 94, 0.05));
    color: #16a34a;
    border: 1px solid rgba(34, 197, 94, 0.3);
}

.alert-error {
    background: linear-gradient(135deg, rgba(239, 68, 68, 0.15), rgba(239, 68, 68, 0.05));
    color: var(--danger-red);
    border: 1px solid rgba(239, 68, 68, 0.3);
}

.alert-warning {
    background: linear-gradient(135deg, rgba(245, 158, 11, 0.15), rgba(245, 158, 11, 0.05));
    color: #d97706;
    border: 1px solid rgba(245, 158, 11, 0.3);
}

.alert i {
    font-size: 1.25rem;
    flex-shrink: 0;
}

/* Barre d'informations */
.info-bar {
    background: var(--bg-card);
    border: 1px solid var(--border-light);
    border-radius: 10px;
    padding: 1rem 1.25rem;
    display: flex;
    gap: 2rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.info-label {
    font-size: 0.75rem;
    color: var(--text-light);
    text-transform: uppercase;
    font-weight: 500;
}

.info-value {
    font-weight: 600;
    color: var(--text-dark);
}

.tracking-number {
    font-family: 'Courier New', monospace;
    color: var(--primary-cyan);
    font-size: 1rem;
}

/* Carte et formulaire */
.card {
    background: var(--bg-card);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 1.5rem;
}

.card-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--border-light);
    background: rgba(0, 180, 216, 0.03);
}

.card-header h3 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-dark);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin: 0;
}

.card-header h3 i {
    color: var(--primary-cyan);
}

.card-body {
    padding: 1.5rem;
}

/* Sections de formulaire */
.form-section {
    margin-bottom: 1.5rem;
    width: 100%;
}

.form-section h3 {
    font-size: 1.1rem;
    color: var(--text-dark);
    margin-bottom: 1.25rem;
    margin-top: 0;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid var(--border-light);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.form-section h3 i {
    color: var(--primary-cyan);
}

/* Lignes de formulaire */
.form-row {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
    width: 100%;
}

/* Groupes de formulaire */
.form-group {
    margin-bottom: 1.25rem;
    width: 100%;
}

.form-group label {
    display: block;
    font-weight: 500;
    color: var(--text-dark);
    margin-bottom: 0.5rem;
    font-size: 0.875rem;
}

.required {
    color: var(--danger-red);
}

/* Champs de formulaire */
input[type="text"],
input[type="number"],
input[type="email"],
input[type="password"],
input[type="tel"],
input[type="date"],
select,
textarea {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 1px solid var(--border-light);
    border-radius: 8px;
    font-size: 0.95rem;
    transition: all 0.3s ease;
    background-color: #ffffff;
    color: var(--text-dark);
    font-family: inherit;
}

input:focus,
select:focus,
textarea:focus {
    outline: none;
    border-color: var(--primary-cyan);
    box-shadow: 0 0 0 3px rgba(0, 180, 216, 0.15);
}

textarea {
    resize: vertical;
    min-height: 100px;
    line-height: 1.5;
}

/* Renforce les styles contre les conflits globaux */
.modifier-colis-page #modifierColisForm,
.modifier-colis-page #modifierColisForm .form-section,
.modifier-colis-page #modifierColisForm .form-group {
    width: 100%;
}

.modifier-colis-page #modifierColisForm input[type="text"],
.modifier-colis-page #modifierColisForm input[type="number"],
.modifier-colis-page #modifierColisForm select,
.modifier-colis-page #modifierColisForm textarea {
    display: block;
    width: 100% !important;
    max-width: none !important;
    min-width: 0;
}

.modifier-colis-page .form-section {
    background: #ffffff;
    border: 1px solid var(--border-light);
    border-radius: 14px;
    padding: 1.25rem;
}

.modifier-colis-page .form-section h3 {
    font-size: 1.15rem;
    font-weight: 700;
    margin: 0 0 0.75rem 0;
    line-height: 1.2;
}

.modifier-colis-page .form-section h3 i {
    color: var(--primary-dark);
}

.modifier-colis-page .checkbox-custom {
    display: none;
}

/* Bloc "Modifier Colis" totalement scope pour eviter les conflits */
.modifier-colis-page .mc-section {
    background: #ffffff;
    border: 1px solid var(--border-light);
    border-radius: 14px;
    padding: 0.85rem;
}

.modifier-colis-page .mc-row {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
}

.modifier-colis-page .mc-group {
    width: 100%;
    min-width: 0;
    margin-bottom: 0.7rem;
}

.modifier-colis-page .mc-group label {
    display: block;
    margin-bottom: 0.35rem;
    font-size: 0.82rem;
    font-weight: 600;
    color: #334155;
}

.modifier-colis-page .mc-group input[type="text"],
.modifier-colis-page .mc-group input[type="number"],
.modifier-colis-page .mc-group textarea {
    display: block;
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    border: 1px solid var(--border-medium) !important;
    border-radius: 8px !important;
    padding: 0.5rem 0.65rem !important;
    font-size: 0.88rem !important;
    line-height: 1.4;
    background: #ffffff !important;
    color: var(--text-dark) !important;
}

.modifier-colis-page .mc-group textarea {
    min-height: 78px;
    resize: vertical;
}

.modifier-colis-page .mc-checks {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: center;
}

/* Checkboxes */
.checkbox-group {
    display: flex;
    gap: 1.5rem;
    margin-top: 1rem;
    flex-wrap: wrap;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    font-size: 0.9rem;
}

.checkbox-label input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: var(--primary-cyan);
}

/* Boutons */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.55rem 1rem;
    border: none;
    border-radius: 8px;
    font-size: 0.86rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
}

.btn-primary {
    background: linear-gradient(135deg, var(--primary-cyan), var(--primary-dark));
    color: #ffffff;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 180, 216, 0.35);
}

.btn-secondary {
    background: var(--bg-card);
    border: 1px solid var(--border-light);
    color: var(--text-medium);
}

.btn-secondary:hover {
    border-color: var(--primary-cyan);
    color: var(--primary-cyan);
}

/* Actions du formulaire */
.form-actions {
    margin-top: 1rem;
    padding-top: 0.9rem;
    border-top: 1px solid var(--border-light);
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
}

/* Vue lecture seule */
.read-only-view {
    padding: 1rem 0;
}

.detail-card {
    background: var(--bg-light);
    border-radius: 10px;
    padding: 1.25rem;
}

.detail-card h4 {
    font-size: 1rem;
    color: var(--text-dark);
    margin-bottom: 1rem;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1rem;
}

.detail-item {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.detail-item .label {
    font-size: 0.75rem;
    color: var(--text-light);
    text-transform: uppercase;
}

.detail-item .value {
    font-weight: 500;
    color: var(--text-dark);
}

.text-success { color: var(--success-green); }
.text-danger { color: var(--danger-red); }
.text-muted { color: var(--text-light); }
.text-warning { color: var(--warning-orange); }

/* Timeline */
.timeline-compact {
    position: relative;
    padding-left: 1.5rem;
}

.timeline-compact::before {
    content: '';
    position: absolute;
    left: 5px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: var(--border-light);
}

.timeline-item-compact {
    position: relative;
    padding-bottom: 1rem;
}

.timeline-marker-sm {
    position: absolute;
    left: -1.5rem;
    top: 0;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: var(--primary-cyan);
    border: 2px solid var(--bg-card);
}

.timeline-content-sm {
    padding-left: 0.5rem;
}

.timeline-header-sm {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.25rem;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.timeline-title-sm {
    font-weight: 500;
    color: var(--text-dark);
    font-size: 0.9rem;
}

.timeline-date-sm {
    font-size: 0.8rem;
    color: var(--text-light);
}

.timeline-user-sm {
    font-size: 0.8rem;
    color: var(--text-medium);
}

/* Responsive */
@media (max-width: 768px) {
    .page-container {
        padding: 1rem;
    }
    
    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .header-left {
        width: 100%;
    }
    
    .form-row {
        grid-template-columns: 1fr;
    }

    .modifier-colis-page .form-container {
        padding: 0.75rem;
    }

    .modifier-colis-page .form-section {
        padding: 0.75rem;
    }

    .modifier-colis-page .form-section h3 {
        font-size: 1.05rem;
    }

    .modifier-colis-page .mc-row {
        grid-template-columns: 1fr;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .form-actions .btn {
        width: 100%;
        justify-content: center;
    }
    
    .checkbox-group {
        flex-direction: column;
        gap: 0.75rem;
    }
    
    .info-bar {
        flex-direction: column;
        gap: 1rem;
    }
}

@media (max-width: 480px) {
    .page-header h1 {
        font-size: 1.1rem;
    }
    
    .card-body {
        padding: 1rem;
    }
    
    .detail-grid {
        grid-template-columns: 1fr;
    }
}

/* Animations */
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-8px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Loading state */
.btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* Focus visible pour l'accessibilité */
input:focus-visible,
select:focus-visible,
textarea:focus-visible,
button:focus-visible {
    outline: 2px solid var(--primary-cyan);
    outline-offset: 2px;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Validation du formulaire
    const form = document.getElementById('modifierColisForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            let isValid = true;
            const requiredFields = form.querySelectorAll('[required]');
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    field.classList.add('error');
                } else {
                    field.classList.remove('error');
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                showNotification('Veuillez remplir tous les champs obligatoires.', 'error');
            }
        });
        
        // Retirer la classe d'erreur quand l'utilisateur commence a remplir
        form.querySelectorAll('input, textarea, select').forEach(field => {
            field.addEventListener('input', function() {
                this.classList.remove('error');
            });
        });
    }
});

function generateColisPDF(colisId) {
    // Ouvrir le générateur PDF dans une nouvelle fenêtre - chemin absolu
    window.open('colis_pdf.php?id=' + colisId, '_blank');
}

console.log('%c🚀 Gestion_Colis - Modifier Colis SPA', 'color: #00B4D8; font-size: 16px; font-weight: bold;');
</script>

</div> <!-- Fin #page-content -->
