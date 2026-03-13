<?php
/**
 * Page de suivi de colis - Version SPA
 */

require_once __DIR__ . '/../utils/session.php';
SessionManager::start();
require_once '../config/database.php';

$trackingResult = null;
$trackingError = null;

// Traitement de la recherche de suivi
if (isset($_GET['code']) && !empty($_GET['code'])) {
    $code = trim($_GET['code']);
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Rechercher le colis
        $stmt = $db->prepare("
            SELECT c.*, u.nom as client_nom, u.prenom as client_prenom, u.email as client_email
            FROM colis c
            LEFT JOIN utilisateurs u ON c.utilisateur_id = u.id
            WHERE c.code_tracking = ? OR c.reference_colis = ?
        ");
        $stmt->execute([$code, $code]);
        $colis = $stmt->fetch();
        
        if ($colis) {
            // Récupérer l'historique (avec fallback si la table n'existe pas)
            $historique = [];
            try {
                $stmt = $db->prepare("
                    SELECT h.*, u.prenom, u.nom
                    FROM historique_colis h
                    LEFT JOIN utilisateurs u ON h.utilisateur_id = u.id
                    WHERE h.colis_id = ?
                    ORDER BY h.date_action DESC
                ");
                $stmt->execute([$colis['id']]);
                $historique = $stmt->fetchAll();
            } catch (PDOException $e) {
                // Si la table n'existe pas, créer un historique basique à partir du colis
                $historique = [[
                    'nouveau_statut' => $colis['statut'],
                    'date_action' => $colis['date_creation'],
                    'commentaire' => 'Colis créé'
                ]];
            }
            
            $trackingResult = [
                'colis' => $colis,
                'historique' => $historique
            ];
        } else {
            $trackingError = 'Colis non trouvé. Vérifiez votre numéro de suivi.';
        }
    } catch (Exception $e) {
        $trackingError = user_error_message($e, 'tracking.search', 'Erreur lors de la recherche. Veuillez réessayer.');
    }
}

// Libellé des statuts
$statusLabels = [
    'en_attente' => 'En attente',
    'preparation' => 'En préparation',
    'en_livraison' => 'En livraison',
    'livre' => 'Livré',
    'annule' => 'Annulé',
    'retourne' => 'En retour'
];

$statusClasses = [
    'en_attente' => 'status-en-attente',
    'preparation' => 'status-en-attente',
    'en_livraison' => 'status-en-livraison',
    'livre' => 'status-livre',
    'annule' => 'status-annule',
    'retourne' => 'status-annule'
];
?>

<!-- Contenu pour le système SPA -->
<div id="page-content">

<div class="page-container">
    <div class="page-header">
        <h1><i class="fas fa-search-location" style="color: #00B4D8;"></i> Suivi de Colis</h1>
        <p class="text-muted">Entrez votre numéro de suivi pour connaître l'état de votre livraison</p>
    </div>

    <?php if ($trackingError): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-triangle"></i>
            <div><?php echo htmlspecialchars($trackingError); ?></div>
        </div>
    <?php endif; ?>

    <div class="card" style="margin-bottom: 1.5rem;">
        <div class="card-body">
            <form id="trackingForm" method="GET" action="views/tracking.php">
                <div class="form-row">
                    <div class="form-group" style="flex: 1;">
                        <label for="trackingNumber">
                            <i class="fas fa-barcode"></i> Numéro de suivi
                        </label>
                        <div style="display: flex; gap: 10px;">
                            <input type="text" 
                                   id="trackingNumber" 
                                   name="code"
                                   class="form-control" 
                                   placeholder="Entrez TRK2024..."
                                   value="<?php echo isset($_GET['code']) ? htmlspecialchars($_GET['code']) : ''; ?>"
                                   required>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Suivre
                            </button>
                        </div>
                        <small class="form-hint">
                            <i class="fas fa-info-circle"></i> Utilisez le code TRK ou la référence du colis
                        </small>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if ($trackingResult): ?>
        <?php
        $colis = $trackingResult['colis'];
        $historique = $trackingResult['historique'];
        $statusClass = $statusClasses[$colis['statut']] ?? 'status-en-attente';
        $statusLabel = $statusLabels[$colis['statut']] ?? $colis['statut'];
        ?>
        
        <!-- Résultat du suivi -->
        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-header" style="background: linear-gradient(135deg, rgba(0, 180, 216, 0.1), rgba(0, 168, 255, 0.1));">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <h3 style="margin: 0;"><i class="fas fa-box"></i> <?php echo htmlspecialchars($colis['code_tracking']); ?></h3>
                        <small class="text-muted">
                            Réf: <?php echo htmlspecialchars($colis['reference_colis'] ?: 'Non définie'); ?>
                        </small>
                    </div>
                    <span class="badge badge-<?php echo strpos($statusClass, 'livre') !== false ? 'success' : (strpos($statusClass, 'livraison') !== false ? 'info' : 'warning'); ?>">
                        <?php echo htmlspecialchars($statusLabel); ?>
                    </span>
                </div>
            </div>
            <div class="card-body">
                <!-- Timeline de l'historique -->
                <h4 style="margin-bottom: 1rem;"><i class="fas fa-history"></i> Historique de livraison</h4>
                
                <div class="tracking-timeline" style="position: relative; padding-left: 40px;">
                    <?php foreach ($historique as $index => $item): ?>
                        <?php $isCompleted = $index < count($historique) - 1; ?>
                        <div class="timeline-item" style="position: relative; padding-bottom: 1.5rem; <?php echo $isCompleted ? '' : 'padding-bottom: 0;'; ?>">
                            <?php if ($index < count($historique) - 1): ?>
                                <div style="position: absolute; left: 15px; top: 40px; bottom: 0; width: 2px; background: #e2e8f0;"></div>
                            <?php endif; ?>
                            <div class="timeline-marker" style="
                                position: absolute;
                                left: -30px;
                                top: 0;
                                width: 32px;
                                height: 32px;
                                border-radius: 50%;
                                background: <?php echo $isCompleted ? '#22c55e' : '#00B4D8'; ?>;
                                color: white;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                            ">
                                <i class="fas <?php echo $isCompleted ? 'fa-check' : 'fa-clock'; ?>"></i>
                            </div>
                            <div class="timeline-content">
                                <div class="timeline-title" style="font-weight: 600; margin-bottom: 0.25rem;">
                                    <?php echo htmlspecialchars($statusLabels[$item['nouveau_statut']] ?? ucfirst($item['nouveau_statut'])); ?>
                                </div>
                                <div class="timeline-date" style="font-size: 0.85rem; color: #64748b;">
                                    <?php echo $item['date_action'] ? date('d/m/Y à H:i', strtotime($item['date_action'])) : 'Date non disponible'; ?>
                                </div>
                                <?php if (!empty($item['commentaire'])): ?>
                                    <p style="margin: 0.5rem 0 0; font-size: 0.9rem; color: #64748b;">
                                        <?php echo htmlspecialchars($item['commentaire']); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Détails du colis -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-info-circle"></i> Détails du colis</h3>
            </div>
            <div class="card-body">
                <div class="colis-details" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                    <div class="detail-card" style="background: var(--bg-gray, #f8fafc); padding: 1rem; border-radius: 8px;">
                        <h4 style="color: #64748b; font-size: 0.85rem; margin-bottom: 0.5rem;">Description</h4>
                        <p style="margin: 0; font-weight: 500;"><?php echo htmlspecialchars($colis['description'] ?: 'Non spécifiée'); ?></p>
                    </div>
                    <div class="detail-card" style="background: var(--bg-gray, #f8fafc); padding: 1rem; border-radius: 8px;">
                        <h4 style="color: #64748b; font-size: 0.85rem; margin-bottom: 0.5rem;">Poids</h4>
                        <p style="margin: 0; font-weight: 500;"><?php echo $colis['poids'] ? htmlspecialchars($colis['poids']) . ' kg' : 'Non spécifié'; ?></p>
                    </div>
                    <div class="detail-card" style="background: var(--bg-gray, #f8fafc); padding: 1rem; border-radius: 8px;">
                        <h4 style="color: #64748b; font-size: 0.85rem; margin-bottom: 0.5rem;">Date de création</h4>
                        <p style="margin: 0; font-weight: 500;"><?php echo $colis['date_creation'] ? date('d/m/Y H:i', strtotime($colis['date_creation'])) : 'Non disponible'; ?></p>
                    </div>
                    <div class="detail-card" style="background: var(--bg-gray, #f8fafc); padding: 1rem; border-radius: 8px;">
                        <h4 style="color: #64748b; font-size: 0.85rem; margin-bottom: 0.5rem;">Dernière mise à jour</h4>
                        <p style="margin: 0; font-weight: 500;"><?php echo $colis['date_mise_a_jour'] ? date('d/m/Y H:i', strtotime($colis['date_mise_a_jour'])) : 'Non disponible'; ?></p>
                    </div>
                    <div class="detail-card" style="background: var(--bg-gray, #f8fafc); padding: 1rem; border-radius: 8px;">
                        <h4 style="color: #64748b; font-size: 0.85rem; margin-bottom: 0.5rem;">Fragile</h4>
                        <p style="margin: 0; font-weight: 500;">
                            <?php if ($colis['fragile']): ?>
                                <span style="color: #f59e0b;"><i class="fas fa-exclamation-triangle"></i> Oui</span>
                            <?php else: ?>
                                Non
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="detail-card" style="background: var(--bg-gray, #f8fafc); padding: 1rem; border-radius: 8px;">
                        <h4 style="color: #64748b; font-size: 0.85rem; margin-bottom: 0.5rem;">Urgent</h4>
                        <p style="margin: 0; font-weight: 500;">
                            <?php if ($colis['urgent']): ?>
                                <span style="color: #dc2626;"><i class="fas fa-bolt"></i> Oui</span>
                            <?php else: ?>
                                Non
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                
                <?php if (!empty($colis['instructions'])): ?>
                    <div class="detail-card" style="background: rgba(0, 180, 216, 0.1); padding: 1rem; border-radius: 8px; margin-top: 1rem; border-left: 3px solid #00B4D8;">
                        <h4 style="color: #00B4D8; font-size: 0.85rem; margin-bottom: 0.5rem;">
                            <i class="fas fa-comment"></i> Instructions de livraison
                        </h4>
                        <p style="margin: 0;"><?php echo htmlspecialchars($colis['instructions']); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif (!isset($_GET['code'])): ?>
        <div class="card">
            <div class="card-body" style="text-align: center; padding: 3rem;">
                <i class="fas fa-search-location fa-4x" style="color: #00B4D8; margin-bottom: 1rem;"></i>
                <h3>Recherchez votre colis</h3>
                <p class="text-muted">Entrez votre code de suivi (TRK...) dans le champ ci-dessus pour suivre votre colis en temps réel.</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// Formulaire de suivi AJAX
document.getElementById('trackingForm')?.addEventListener('submit', function(e) {
    const trackingNumber = document.getElementById('trackingNumber').value.trim();
    if (trackingNumber) {
        // Recharger la page avec le paramètre de suivi
        window.location.href = 'views/tracking.php?code=' + encodeURIComponent(trackingNumber);
    }
    e.preventDefault();
});

// Initialiser le focus et le placeholder
document.addEventListener('DOMContentLoaded', function() {
    const trackingInput = document.getElementById('trackingNumber');
    if (trackingInput && !trackingInput.value) {
        trackingInput.focus();
    }
    
    // Gestion du bouton de recherche pour le mode SPA
    const searchBtn = document.querySelector('#trackingForm button[type="submit"]');
    if (searchBtn) {
        searchBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const trackingNumber = document.getElementById('trackingNumber').value.trim();
            if (trackingNumber) {
                window.location.href = 'views/tracking.php?code=' + encodeURIComponent(trackingNumber);
            }
        });
    }
});

console.log('%c🚀 Gestion_Colis - Suivi de Colis SPA', 'color: #00B4D8; font-size: 16px; font-weight: bold;');
</script>

<style>
.status-en-attente { background: rgba(245, 158, 11, 0.2); color: #d97706; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.85rem; font-weight: 600; }
.status-en-livraison { background: rgba(0, 180, 216, 0.2); color: #0891b2; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.85rem; font-weight: 600; }
.status-livre { background: rgba(34, 197, 94, 0.2); color: #16a34a; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.85rem; font-weight: 600; }
.status-annule { background: rgba(220, 38, 38, 0.2); color: #dc2626; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.85rem; font-weight: 600; }
</style>

</div> <!-- Fin #page-content -->
