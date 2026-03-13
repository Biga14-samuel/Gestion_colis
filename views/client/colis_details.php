<?php
/**
 * Page de détails d'un colis
 * Affiche les informations complètes d'un colis
 */

session_start();
require_once '../../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="access-denied">Accès refusé. Veuillez vous connecter.</div>';
    exit;
}

$database = new Database();
$db = $database->getConnection();

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'];
$colisId = (int)($_GET['id'] ?? 0);

if ($colisId <= 0) {
    echo '<div class="container"><div class="alert alert-error">Colis non trouvé.</div></div>';
    exit;
}

// Récupérer les détails du colis
try {
    if ($userRole === 'admin') {
        $stmt = $db->prepare("
            SELECT c.*, u.nom as user_nom, u.prenom as user_prenom, u.email as user_email, u.telephone as user_telephone,
                   a.nom as agent_nom, a.prenom as agent_prenom
            FROM colis c
            LEFT JOIN utilisateurs u ON c.utilisateur_id = u.id
            LEFT JOIN agents ag ON c.agent_id = ag.id
            LEFT JOIN utilisateurs a ON ag.utilisateur_id = a.id
            WHERE c.id = ?
        ");
        $stmt->execute([$colisId]);
    } else {
        $stmt = $db->prepare("
            SELECT c.*, u.nom as user_nom, u.prenom as user_prenom, u.email as user_email, u.telephone as user_telephone,
                   a.nom as agent_nom, a.prenom as agent_prenom
            FROM colis c
            LEFT JOIN utilisateurs u ON c.utilisateur_id = u.id
            LEFT JOIN agents ag ON c.agent_id = ag.id
            LEFT JOIN utilisateurs a ON ag.utilisateur_id = a.id
            WHERE c.id = ? AND c.utilisateur_id = ?
        ");
        $stmt->execute([$colisId, $userId]);
    }
    
    $colis = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$colis) {
        echo '<div class="container"><div class="alert alert-error">Colis non trouvé ou vous n\'avez pas l\'autorisation.</div></div>';
        exit;
    }
    
    // Récupérer l'historique du suivi (avec fallback si la table n'existe pas)
    $suivi = [];
    try {
        $stmt = $db->prepare("
            SELECT * FROM suivi_colis
            WHERE colis_id = ?
            ORDER BY date_suivi DESC
        ");
        $stmt->execute([$colisId]);
        $suivi = $stmt->fetchAll();
    } catch (PDOException $e) {
        // La table suivi_colis n'existe pas, utiliser historique_colis comme fallback
        try {
            $stmt = $db->prepare("
                SELECT nouveau_statut as statut, commentaire, date_action as date_suivi
                FROM historique_colis
                WHERE colis_id = ?
                ORDER BY date_action DESC
            ");
            $stmt->execute([$colisId]);
            $suivi = $stmt->fetchAll();
        } catch (PDOException $e2) {
            $suivi = [];
        }
    }
    
    // Récupérer les événements de livraison
    $livraisons = [];
    try {
        $stmt = $db->prepare("
            SELECT l.*, u.nom, u.prenom
            FROM livraisons l
            LEFT JOIN utilisateurs u ON l.signature_livreur_id = u.id
            WHERE l.colis_id = ?
            ORDER BY l.date_assignation DESC
        ");
        $stmt->execute([$colisId]);
        $livraisons = $stmt->fetchAll();
    } catch (PDOException $e) {
        $livraisons = [];
    }
    
} catch (PDOException $e) {
    $errorMessage = user_error_message($e, 'colis_details.fetch', 'Erreur lors de la récupération du colis.');
    echo '<div class="container"><div class="alert alert-error">' . htmlspecialchars($errorMessage) . '</div></div>';
    exit;
}

$statusLabels = [
    'en_attente' => 'En attente',
    'en_livraison' => 'En livraison',
    'livre' => 'Livré',
    'annule' => 'Annulé',
    'retourne' => 'En retour'
];

$statusColors = [
    'en_attente' => 'warning',
    'en_livraison' => 'info',
    'livre' => 'success',
    'annule' => 'danger',
    'retourne' => 'secondary'
];
?>

<div id="page-content">
    <div class="page-container">
        <div class="page-header">
            <div class="header-left">
                <button class="btn btn-back" onclick="loadPage('views/client/mes_colis.php', 'Mes Colis')">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <h1><i class="fas fa-box-open" style="color: #00B4D8;"></i> Détails du Colis</h1>
            </div>
            <div class="header-actions">
                <button class="btn btn-secondary" onclick="downloadColisPDF(<?= $colis['id'] ?>, '<?= htmlspecialchars($colis['code_tracking'] ?? $colis['reference_colis']) ?>')">
                    <i class="fas fa-file-pdf"></i> Télécharger PDF
                </button>
            </div>
        </div>

        <!-- Carte principale des détails -->
        <div class="details-card">
            <div class="details-header">
                <div class="tracking-info">
                    <span class="tracking-label">Code de suivi</span>
                    <span class="tracking-code"><?= htmlspecialchars($colis['code_tracking'] ?? $colis['reference_colis']) ?></span>
                </div>
                <div class="status-info">
                    <span class="badge badge-<?= $statusColors[$colis['statut']] ?? 'secondary' ?> badge-lg">
                        <?= htmlspecialchars($statusLabels[$colis['statut']] ?? ucfirst($colis['statut'])) ?>
                    </span>
                </div>
            </div>

            <div class="details-body">
                <!-- Informations principales -->
                <div class="details-section">
                    <h3><i class="fas fa-info-circle"></i> Informations générales</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Référence</span>
                            <span class="info-value"><?= htmlspecialchars($colis['reference_colis']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Date de création</span>
                            <span class="info-value"><?= date('d/m/Y H:i', strtotime($colis['date_creation'])) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Description</span>
                            <span class="info-value"><?= htmlspecialchars($colis['description'] ?? 'Aucune') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Type de colis</span>
                            <span class="info-value"><?= htmlspecialchars(ucfirst($colis['type_colis'] ?? 'Standard')) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Poids</span>
                            <span class="info-value"><?= htmlspecialchars($colis['poids'] ?? 'N/A') ?> kg</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Dimensions</span>
                            <span class="info-value">
                                <?= htmlspecialchars($colis['longueur'] ?? 'N/A') ?> x 
                                <?= htmlspecialchars($colis['largeur'] ?? 'N/A') ?> x 
                                <?= htmlspecialchars($colis['hauteur'] ?? 'N/A') ?> cm
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Informations d'expédition -->
                <div class="details-section">
                    <h3><i class="fas fa-shipping-fast"></i> Expédition</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Expéditeur</span>
                            <span class="info-value">
                                <?= htmlspecialchars($colis['user_prenom'] . ' ' . $colis['user_nom']) ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Email</span>
                            <span class="info-value"><?= htmlspecialchars($colis['user_email'] ?? 'N/A') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Téléphone</span>
                            <span class="info-value"><?= htmlspecialchars($colis['user_telephone'] ?? 'N/A') ?></span>
                        </div>
                        <div class="info-item full-width">
                            <span class="info-label">Adresse de départ</span>
                            <span class="info-value"><?= htmlspecialchars($colis['adresse_depart'] ?? 'N/A') ?></span>
                        </div>
                    </div>
                </div>

                <!-- Informations de livraison -->
                <div class="details-section">
                    <h3><i class="fas fa-map-marker-alt"></i> Livraison</h3>
                    <div class="info-grid">
                        <div class="info-item full-width">
                            <span class="info-label">Adresse de livraison</span>
                            <span class="info-value"><?= htmlspecialchars($colis['adresse_livraison'] ?? 'N/A') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Ville</span>
                            <span class="info-value"><?= htmlspecialchars($colis['ville_livraison'] ?? 'N/A') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Code postal</span>
                            <span class="info-value"><?= htmlspecialchars($colis['code_postal_livraison'] ?? 'N/A') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Pays</span>
                            <span class="info-value"><?= htmlspecialchars($colis['pays_livraison'] ?? 'N/A') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Destinataire</span>
                            <span class="info-value"><?= htmlspecialchars($colis['nom_destinataire'] ?? 'N/A') ?></span>
                        </div>
                    </div>
                </div>

                <!-- Agent responsable -->
                <?php if ($colis['agent_nom']): ?>
                <div class="details-section">
                    <h3><i class="fas fa-user-shield"></i> Agent responsable</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Nom</span>
                            <span class="info-value">
                                <?= htmlspecialchars($colis['agent_prenom'] . ' ' . $colis['agent_nom']) ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Paiement -->
                <div class="details-section">
                    <h3><i class="fas fa-money-bill-wave"></i> Paiement</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Mode de paiement</span>
                            <?php
                            $paymentMethod = 'Paiement à la livraison (COD)';
                            if (!empty($colis['payment_provider'])) {
                                $paymentMethod = $colis['payment_provider'] === 'orange' ? 'Orange Money' : 'MTN MoMo';
                            }
                            ?>
                            <span class="info-value"><?= htmlspecialchars($paymentMethod) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Frais de livraison</span>
                            <span class="info-value highlight">
                                <?= formatCurrency($colis['frais_livraison'] ?? 0) ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Valeur déclarée</span>
                            <span class="info-value highlight">
                                <?= formatCurrency($colis['valeur_declaree'] ?? 0) ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Total à payer</span>
                            <span class="info-value highlight total">
                                <?= formatCurrency(($colis['frais_livraison'] ?? 0) + ($colis['valeur_declaree'] ?? 0)) ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Options -->
                <div class="details-section">
                    <h3><i class="fas fa-cogs"></i> Options</h3>
                    <div class="options-list">
                        <div class="option-item <?= $colis['fragile'] ? 'active' : '' ?>">
                            <i class="fas <?= $colis['fragile'] ? 'fa-check text-success' : 'fa-times text-muted' ?>"></i>
                            <span>Colis fragile</span>
                        </div>
                        <div class="option-item <?= $colis['urgent'] ? 'active' : '' ?>">
                            <i class="fas <?= $colis['urgent'] ? 'fa-check text-success' : 'fa-times text-muted' ?>"></i>
                            <span>Livraison urgente</span>
                        </div>
                        <div class="option-item <?= $colis['assurance'] ? 'active' : '' ?>">
                            <i class="fas <?= $colis['assurance'] ? 'fa-check text-success' : 'fa-times text-muted' ?>"></i>
                            <span>Assurance incluse</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Suivi du colis -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-history"></i> Historique de suivi</h3>
            </div>
            <div class="card-body">
                <?php if (empty($suivi)): ?>
                    <div class="empty-state">
                        <i class="fas fa-history fa-3x"></i>
                        <p>Aucun historique de suivi disponible.</p>
                    </div>
                <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($suivi as $event): ?>
                            <div class="timeline-item">
                                <div class="timeline-marker"></div>
                                <div class="timeline-content">
                                    <div class="timeline-header">
                                        <span class="timeline-title">
                                            <?= htmlspecialchars($event['statut'] ?? 'Mise à jour') ?>
                                        </span>
                                        <span class="timeline-date">
                                            <?= date('d/m/Y H:i', strtotime($event['date_suivi'])) ?>
                                        </span>
                                    </div>
                                    <?php if ($event['commentaire']): ?>
                                        <p class="timeline-description">
                                            <?= htmlspecialchars($event['commentaire']) ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function downloadColisPDF(colisId, trackingNumber) {
    // Chemin absolu pour le PDF
    window.open('colis_pdf.php?id=' + colisId, '_blank');
}

console.log('%c🚀 Gestion_Colis - Détails Colis', 'color: #00B4D8; font-size: 16px; font-weight: bold;');
</script>

<style>
.details-card {
    background: var(--bg-card, #fff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 12px;
    margin-bottom: 1.5rem;
    overflow: hidden;
}

.details-header {
    background: linear-gradient(135deg, rgba(0, 180, 216, 0.1), rgba(0, 168, 255, 0.05));
    padding: 1.5rem;
    border-bottom: 2px solid var(--primary-cyan, #00B4D8);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.tracking-label {
    display: block;
    font-size: 0.75rem;
    color: var(--text-secondary, #64748b);
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 0.25rem;
}

.tracking-code {
    font-family: 'Orbitron', monospace;
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--text-dark, #0f172a);
    letter-spacing: 2px;
}

.badge-lg {
    padding: 0.5rem 1rem;
    font-size: 0.9rem;
}

.details-body {
    padding: 1.5rem;
}

.details-section {
    margin-bottom: 2rem;
}

.details-section:last-child {
    margin-bottom: 0;
}

.details-section h3 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-dark, #0f172a);
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--border-color, #e2e8f0);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.details-section h3 i {
    color: var(--primary-cyan, #00B4D8);
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 1rem;
}

.info-item {
    padding: 0.75rem;
    background: var(--bg-gray, #f8fafc);
    border-radius: 8px;
}

.info-item.full-width {
    grid-column: 1 / -1;
}

.info-label {
    display: block;
    font-size: 0.75rem;
    color: var(--text-secondary, #64748b);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.25rem;
}

.info-value {
    font-weight: 600;
    color: var(--text-dark, #0f172a);
    word-break: break-word;
}

.info-value.highlight {
    color: var(--primary-cyan, #00B4D8);
    font-size: 1.1rem;
}

.info-value.total {
    color: var(--success-green, #059669);
    font-size: 1.25rem;
}

.options-list {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

.option-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: var(--bg-gray, #f8fafc);
    border-radius: 20px;
    font-size: 0.9rem;
}

.option-item.active {
    background: rgba(34, 197, 94, 0.1);
    color: var(--success-green, #059669);
}

.text-success { color: #22c55e; }
.text-muted { color: #94a3b8; }

/* Timeline */
.timeline {
    position: relative;
    padding-left: 2rem;
}

.timeline::before {
    content: '';
    position: absolute;
    left: 7px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: var(--border-color, #e2e8f0);
}

.timeline-item {
    position: relative;
    padding-bottom: 1.5rem;
}

.timeline-item:last-child {
    padding-bottom: 0;
}

.timeline-marker {
    position: absolute;
    left: -2rem;
    top: 0;
    width: 16px;
    height: 16px;
    background: var(--primary-cyan, #00B4D8);
    border-radius: 50%;
    border: 3px solid #fff;
    box-shadow: 0 0 0 2px var(--primary-cyan, #00B4D8);
}

.timeline-content {
    background: var(--bg-gray, #f8fafc);
    padding: 1rem;
    border-radius: 8px;
}

.timeline-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}

.timeline-title {
    font-weight: 600;
    color: var(--text-dark, #0f172a);
}

.timeline-date {
    font-size: 0.8rem;
    color: var(--text-secondary, #64748b);
}

.timeline-description {
    color: var(--text-medium, #475569);
    font-size: 0.9rem;
    margin: 0;
}

.btn-back {
    background: var(--bg-gray, #f1f5f9);
    border: 1px solid var(--border-color, #e2e8f0);
    padding: 0.5rem 1rem;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-back:hover {
    background: var(--primary-cyan, #00B4D8);
    color: #fff;
    border-color: var(--primary-cyan, #00B4D8);
}

.header-left {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.header-actions {
    display: flex;
    gap: 0.5rem;
}

.empty-state {
    text-align: center;
    padding: 3rem;
    color: var(--text-secondary, #64748b);
}

.empty-state i {
    color: var(--border-color, #e2e8f0);
    margin-bottom: 1rem;
}

@media (max-width: 768px) {
    .details-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .info-grid {
        grid-template-columns: 1fr;
    }
    
    .timeline {
        padding-left: 1.5rem;
    }
    
    .timeline-marker {
        left: -1.5rem;
    }
}
</style>
