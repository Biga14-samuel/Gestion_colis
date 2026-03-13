<?php
/**
 * Delivery Details - Modal Content Generator
 * Point d'entrée autonome pour récupérer les détails de livraison
 */

require_once __DIR__ . '/utils/session.php';
SessionManager::start();
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'] ?? 0;
$colis_id = $_GET['colis_id'] ?? 0;

// Vérifier l'accès
if (!$user_id || !$colis_id) {
    echo '<div class="alert alert-danger">Paramètres invalides.</div>';
    exit;
}

// Récupérer les détails du colis
$stmt = $db->prepare("
    SELECT c.*, 
           u.prenom as expediteur_prenom, u.nom as expediteur_nom,
           d.prenom as destinataire_prenom, d.nom as destinataire_nom,
           d.adresse as destinataire_adresse, d.telephone as destinataire_tel,
           a.zone_livraison
    FROM colis c
    LEFT JOIN utilisateurs u ON c.expediteur_id = u.id
    LEFT JOIN utilisateurs d ON c.destinataire_id = d.id
    LEFT JOIN agents a ON c.agent_id = a.id
    WHERE c.id = ? AND c.agent_id = (
        SELECT id FROM agents WHERE utilisateur_id = ?
    )
");
$stmt->execute([$colis_id, $user_id]);
$colis = $stmt->fetch();

if (!$colis) {
    echo '<div class="alert alert-danger">Colis non trouvé ou non assigné.</div>';
    exit;
}

$statutLabels = [
    'en_attente' => 'En attente',
    'preparation' => 'En préparation',
    'en_livraison' => 'En livraison',
    'livre' => 'Livré',
    'annule' => 'Annulé',
    'retour' => 'En retour'
];
?>

<div class="pod-form-section">
    <h4><i class="fas fa-box"></i> Informations du colis</h4>
    <div class="info-grid">
        <div class="info-item">
            <span class="info-label">Code de suivi:</span>
            <span class="info-value" style="color: #00B4D8; font-weight: bold;"><?= htmlspecialchars($colis['code_tracking']) ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">Statut:</span>
            <span class="info-value"><?= $statutLabels[$colis['statut']] ?? $colis['statut'] ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">Description:</span>
            <span class="info-value"><?= htmlspecialchars($colis['description'] ?? 'Colis standard') ?></span>
        </div>
    </div>
</div>

<div class="pod-form-section">
    <h4><i class="fas fa-user"></i> Destinataire</h4>
    <div class="info-grid">
        <div class="info-item">
            <span class="info-label">Nom:</span>
            <span class="info-value"><?= htmlspecialchars($colis['destinataire_prenom'] . ' ' . $colis['destinataire_nom']) ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">Adresse:</span>
            <span class="info-value"><?= htmlspecialchars($colis['destinataire_adresse'] ?? 'Non spécifiée') ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">Téléphone:</span>
            <span class="info-value"><?= htmlspecialchars($colis['destinataire_tel'] ?? 'N/A') ?></span>
        </div>
    </div>
</div>

<div class="pod-form-section">
    <h4><i class="fas fa-map-marker-alt"></i> Localisation</h4>
    <div class="form-group">
        <label for="deliveryLatitude">Latitude (optionnel):</label>
        <input type="text" id="deliveryLatitude" name="latitude" class="form-control" placeholder="Coordonnées GPS">
    </div>
    <div class="form-group">
        <label for="deliveryLongitude">Longitude (optionnel):</label>
        <input type="text" id="deliveryLongitude" name="longitude" class="form-control" placeholder="Coordonnées GPS">
    </div>
    <button type="button" class="btn btn-secondary" onclick="captureCurrentLocation()">
        <i class="fas fa-crosshairs"></i> Capturer la position actuelle
    </button>
</div>

<div class="pod-form-section">
    <h4><i class="fas fa-camera"></i> Preuve de livraison (photo)</h4>
    <div class="camera-container">
        <video id="cameraVideo" autoplay playsinline></video>
        <canvas id="photoCanvas" style="display: none;"></canvas>
    </div>
    <div style="display: flex; gap: 0.5rem; margin-bottom: 1rem;">
        <button type="button" class="btn btn-secondary" onclick="startCamera()">
            <i class="fas fa-video"></i> Démarrer caméra
        </button>
        <button type="button" class="btn btn-secondary" onclick="capturePhoto()">
            <i class="fas fa-camera"></i> Prendre photo
        </button>
    </div>
    <img id="photoPreview" style="max-width: 100%; display: none; border-radius: 8px; margin-bottom: 1rem;">
    <input type="hidden" id="proofPhotoData" name="proof_photo">
</div>

<div class="pod-form-section">
    <h4><i class="fas fa-pen-fancy"></i> Signature du destinataire</h4>
    <div class="pod-signature-container">
        <canvas id="podSignatureCanvas" width="700" height="200"></canvas>
    </div>
    <div class="signature-tools">
        <button type="button" class="btn btn-secondary" onclick="clearPodSignature()">
            <i class="fas fa-eraser"></i> Effacer
        </button>
    </div>
</div>

<div class="pod-form-section">
    <h4><i class="fas fa-clipboard-list"></i> Détails de la livraison</h4>
    <div class="form-group">
        <label for="recipientName">
            Nom du destinataire <span class="required">*</span>
        </label>
        <input type="text" id="recipientName" name="recipient_name" class="form-control" required>
    </div>
    <div class="form-group">
        <label for="deliveryNotes">Notes:</label>
        <textarea id="deliveryNotes" name="notes" class="form-control" rows="3" placeholder="Notes optionnelles sur la livraison"></textarea>
    </div>
</div>

<div class="form-actions" style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem;">
    <button type="button" class="btn btn-secondary" onclick="closeModal('deliveryModal')">
        <i class="fas fa-times"></i> Annuler
    </button>
    <button type="button" class="btn btn-primary" onclick="completeDelivery()">
        <i class="fas fa-check"></i> Confirmer la livraison
    </button>
</div>

<script>
// Initialiser la géolocalisation
function captureCurrentLocation() {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            position => {
                document.getElementById('deliveryLatitude').value = position.coords.latitude;
                document.getElementById('deliveryLongitude').value = position.coords.longitude;
                alert('Position capturée avec succès !');
            },
            error => {
                alert('Erreur de géolocalisation: ' + error.message);
            },
            { enableHighAccuracy: true }
        );
    } else {
        alert('La géolocalisation n\'est pas prise en charge par ce navigateur.');
    }
}
</script>

<style>
.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.info-item {
    background: rgba(255, 255, 255, 0.05);
    padding: 0.75rem;
    border-radius: 8px;
}

.info-label {
    display: block;
    font-size: 0.8rem;
    color: rgba(255, 255, 255, 0.5);
    margin-bottom: 0.25rem;
}

.info-value {
    font-size: 0.95rem;
    color: #fff;
}

.camera-container {
    background: #000;
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 1rem;
}

#cameraVideo {
    width: 100%;
    display: block;
    max-height: 300px;
}

.pod-signature-container {
    border: 2px dashed rgba(0, 240, 255, 0.3);
    border-radius: 12px;
    background: rgba(10, 14, 23, 0.5);
    padding: 1rem;
}

#podSignatureCanvas {
    background: #fff;
    border-radius: 8px;
    cursor: crosshair;
    max-width: 100%;
}

.signature-tools {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.5rem;
}
</style>
