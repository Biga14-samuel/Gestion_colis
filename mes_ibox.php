<?php
/**
 * =====================================================
 * MES IBOX - VUE UTILISATEUR (CONSULTATION UNIQUEMENT)
 * La création d'iBox est réservée aux administrateurs
 * =====================================================
 */
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="access-denied">Accès refusé. Veuillez vous connecter.</div>';
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

// Récupérer les iBox attribuées à cet utilisateur
$ibox_list = [];
try {
    $stmt = $db->prepare("
        SELECT i.*, 
               (SELECT COUNT(*) FROM colis c WHERE c.ibox_id = i.id AND c.statut NOT IN ('livre','annule','retourne')) as colis_actifs
        FROM ibox i
        WHERE i.utilisateur_id = ?
        ORDER BY i.date_creation DESC
    ");
    $stmt->execute([$user_id]);
    $ibox_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $ibox_list = [];
}

// Récupérer les iBox partagées avec l'utilisateur
$shared_ibox = [];
try {
    $stmt = $db->prepare("
        SELECT i.*, u.prenom as owner_prenom, u.nom as owner_nom,
               (SELECT COUNT(*) FROM colis c WHERE c.ibox_id = i.id AND c.statut NOT IN ('livre','annule','retourne')) as colis_actifs
        FROM ibox_shares s
        JOIN ibox i ON s.ibox_id = i.id
        JOIN utilisateurs u ON i.utilisateur_id = u.id
        WHERE (s.shared_with_user_id = ? 
               OR s.shared_with_email = (SELECT email FROM utilisateurs WHERE id = ?))
          AND s.is_active = 1
        ORDER BY i.date_creation DESC
    ");
    $stmt->execute([$user_id, $user_id]);
    $shared_ibox = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $shared_ibox = [];
}

$total = count($ibox_list) + count($shared_ibox);
?>

<!-- Contenu pour le système SPA -->
<div id="page-content">
<div class="page-container">

    <div class="page-header" style="margin-bottom:2rem;">
        <h1><i class="fas fa-inbox" style="color:#00B4D8;"></i> Mes Boîtes Virtuelles (iBox)</h1>
        <p style="color:var(--text-muted);">Les iBox sont créées et gérées par l'administrateur du réseau</p>
    </div>

    <?php if ($total === 0): ?>
    <div style="text-align:center; padding:4rem 2rem; background:var(--bg-card); border:1px dashed var(--border-color); border-radius:var(--radius-lg);">
        <i class="fas fa-inbox" style="font-size:4rem; color:var(--text-muted); opacity:0.3; display:block; margin-bottom:1rem;"></i>
        <h3 style="margin-bottom:0.5rem;">Aucune iBox associée</h3>
        <p style="color:var(--text-muted); margin-bottom:1.5rem;">
            Aucune boîte virtuelle n'est actuellement assignée à votre compte.<br>
            Contactez l'administrateur pour obtenir une iBox.
        </p>
    </div>
    <?php endif; ?>

    <!-- Mes iBox propres -->
    <?php if (!empty($ibox_list)): ?>
    <div style="margin-bottom:2rem;">
        <h3 style="margin-bottom:1rem; display:flex; align-items:center; gap:0.5rem;">
            <i class="fas fa-box" style="color:#00B4D8;"></i>
            Mes iBox (<?php echo count($ibox_list); ?>)
        </h3>
        <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:1.5rem;">
            <?php foreach ($ibox_list as $box): ?>
                <?php
                $statut_config = [
                    'disponible'   => ['color'=>'#22c55e', 'label'=>'Disponible'],
                    'occupee'      => ['color'=>'#f59e0b', 'label'=>'Occupée'],
                    'hors_service' => ['color'=>'#ef4444', 'label'=>'Hors service'],
                    'recu'         => ['color'=>'#8b5cf6', 'label'=>'Reçu'],
                ];
                $sc = $statut_config[$box['statut']] ?? ['color'=>'#6b7280','label'=>$box['statut']];
                ?>
                <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:1.5rem; transition:all 0.3s;"
                     onmouseover="this.style.borderColor='#00B4D8'; this.style.boxShadow='0 8px 30px rgba(0,180,216,0.15)'"
                     onmouseout="this.style.borderColor='var(--border-color)'; this.style.boxShadow=''">

                    <div style="display:flex; justify-content:space-between; align-items:start; margin-bottom:1rem;">
                        <div>
                            <div style="font-family:monospace; font-size:1.2rem; font-weight:800; color:#00B4D8;">
                                <?php echo htmlspecialchars($box['code_box']); ?>
                            </div>
                            <div style="color:var(--text-muted); font-size:0.88rem; margin-top:4px;">
                                <i class="fas fa-map-marker-alt" style="color:#f59e0b;"></i>
                                <?php echo htmlspecialchars($box['localisation']); ?>
                            </div>
                        </div>
                        <span style="background:<?php echo $sc['color']; ?>22; color:<?php echo $sc['color']; ?>; padding:4px 12px; border-radius:20px; font-size:0.78rem; font-weight:700; white-space:nowrap;">
                            <?php echo $sc['label']; ?>
                        </span>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; background:var(--bg-tertiary); border-radius:8px; padding:1rem; margin-bottom:1rem; font-size:0.85rem;">
                        <div>
                            <div style="color:var(--text-muted); font-size:0.75rem;">Type</div>
                            <div style="font-weight:600; text-transform:capitalize;"><?php echo $box['type_box']; ?></div>
                        </div>
                        <div>
                            <div style="color:var(--text-muted); font-size:0.75rem;">Capacité</div>
                            <div style="font-weight:600;"><?php echo $box['capacite_max']; ?> places</div>
                        </div>
                        <div>
                            <div style="color:var(--text-muted); font-size:0.75rem;">Température</div>
                            <div style="font-weight:600; text-transform:capitalize;"><?php echo $box['temperature']; ?></div>
                        </div>
                        <div>
                            <div style="color:var(--text-muted); font-size:0.75rem;">Colis actifs</div>
                            <div style="font-weight:700; color:<?php echo $box['colis_actifs']>0?'#f59e0b':'#22c55e'; ?>;">
                                <?php echo $box['colis_actifs']; ?>
                            </div>
                        </div>
                    </div>

                    <div style="display:flex; align-items:center; justify-content:space-between; padding:0.75rem 1rem; background:rgba(0,180,216,0.06); border:1px solid rgba(0,180,216,0.2); border-radius:8px; margin-bottom:1rem;">
                        <span style="color:var(--text-muted); font-size:0.82rem;">Code d'accès</span>
                        <span style="font-family:monospace; font-size:1.3rem; font-weight:800; letter-spacing:4px; color:#00B4D8;">
                            <?php echo htmlspecialchars($box['code_acces']); ?>
                        </span>
                    </div>

                    <div style="display:flex; gap:0.5rem;">
                        <button onclick="loadPage('ibox_sharing.php', 'Partager iBox')"
                                style="flex:1; background:rgba(0,180,216,0.1); color:#00B4D8; border:1px solid rgba(0,180,216,0.3); padding:8px; border-radius:8px; cursor:pointer; font-size:0.85rem; font-weight:600; display:flex; align-items:center; justify-content:center; gap:6px;">
                            <i class="fas fa-share-alt"></i> Partager
                        </button>
                        <button onclick="loadPage('qr_codes.php', 'QR Codes')"
                                style="flex:1; background:rgba(139,92,246,0.1); color:#8b5cf6; border:1px solid rgba(139,92,246,0.3); padding:8px; border-radius:8px; cursor:pointer; font-size:0.85rem; font-weight:600; display:flex; align-items:center; justify-content:center; gap:6px;">
                            <i class="fas fa-qrcode"></i> QR Code
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- iBox partagées -->
    <?php if (!empty($shared_ibox)): ?>
    <div>
        <h3 style="margin-bottom:1rem; display:flex; align-items:center; gap:0.5rem;">
            <i class="fas fa-share-alt" style="color:#8b5cf6;"></i>
            iBox partagées avec moi (<?php echo count($shared_ibox); ?>)
        </h3>
        <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:1.5rem;">
            <?php foreach ($shared_ibox as $box): ?>
                <?php
                $sc = $statut_config[$box['statut']] ?? ['color'=>'#6b7280','label'=>$box['statut']];
                ?>
                <div style="background:var(--bg-card); border:1px solid rgba(139,92,246,0.3); border-radius:var(--radius-lg); padding:1.5rem;">
                    <div style="display:flex; justify-content:space-between; align-items:start; margin-bottom:1rem;">
                        <div>
                            <div style="font-family:monospace; font-size:1.1rem; font-weight:800; color:#8b5cf6;">
                                <?php echo htmlspecialchars($box['code_box']); ?>
                            </div>
                            <div style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;">
                                Propriétaire : <?php echo htmlspecialchars($box['owner_prenom'] . ' ' . $box['owner_nom']); ?>
                            </div>
                            <div style="color:var(--text-muted); font-size:0.85rem; margin-top:4px;">
                                <i class="fas fa-map-marker-alt"></i>
                                <?php echo htmlspecialchars($box['localisation']); ?>
                            </div>
                        </div>
                        <span style="background:<?php echo $sc['color']; ?>22; color:<?php echo $sc['color']; ?>; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:700;">
                            <?php echo $sc['label']; ?>
                        </span>
                    </div>
                    <div style="padding:0.75rem 1rem; background:rgba(139,92,246,0.06); border:1px solid rgba(139,92,246,0.2); border-radius:8px; text-align:center;">
                        <span style="color:var(--text-muted); font-size:0.82rem;">Code d'accès</span><br>
                        <span style="font-family:monospace; font-size:1.3rem; font-weight:800; letter-spacing:4px; color:#8b5cf6;">
                            <?php echo htmlspecialchars($box['code_acces']); ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>
</div><!-- fin #page-content -->
