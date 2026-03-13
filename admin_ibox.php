<?php
/**
 * =====================================================
 * ADMIN - GESTION DES IBOX ET POINTS RELAIS
 * Réservé aux administrateurs uniquement
 * =====================================================
 */
session_start();
require_once __DIR__ . '/config/database.php';

// Accès réservé aux admins
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="access-denied"><i class="fas fa-lock"></i> Accès refusé. Veuillez vous connecter.</div>';
    exit;
}

$database = new Database();
$db = $database->getConnection();

// Vérifier le rôle admin
$stmt = $db->prepare("SELECT role FROM utilisateurs WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch();

if (!$currentUser || $currentUser['role'] !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="access-denied" style="padding:2rem;text-align:center;"><i class="fas fa-lock" style="font-size:3rem;color:#ef4444;"></i><h2>Accès Réservé</h2><p>Cette section est réservée aux administrateurs.</p></div>';
    exit;
}

$admin_id = $_SESSION['user_id'];
$ajaxResponse = ['success' => false, 'message' => ''];

// =====================================================
// TRAITEMENTS POST
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // CRÉER une iBox
    if ($action === 'creer_ibox') {
        $localisation    = trim($_POST['localisation'] ?? '');
        $type_box        = $_POST['type_box'] ?? 'medium';
        $temperature     = $_POST['temperature'] ?? 'ambiant';
        $nom_point       = trim($_POST['nom_point'] ?? '');
        $description_pt  = trim($_POST['description_point'] ?? '');
        $assignee_user   = intval($_POST['utilisateur_id'] ?? 0); // peut être 0 = admin propriétaire

        if (empty($localisation)) {
            $ajaxResponse['message'] = "La localisation est requise.";
        } else {
            try {
                $code_box    = 'BOX' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
                $code_acces  = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
                $capacite_map = ['small' => 5, 'medium' => 10, 'large' => 20, 'xlarge' => 50];
                $capacite_max = $capacite_map[$type_box] ?? 10;

                // Propriétaire : utilisateur assigné ou l'admin lui-même
                $owner_id = $assignee_user > 0 ? $assignee_user : $admin_id;

                $qr_data = json_encode([
                    'code_box'    => $code_box,
                    'code_acces'  => $code_acces,
                    'localisation'=> $localisation,
                    'nom_point'   => $nom_point,
                    'created_at'  => date('Y-m-d H:i:s')
                ]);

                $stmt = $db->prepare("
                    INSERT INTO ibox (utilisateur_id, code_box, localisation, type_box, capacite_max, temperature, code_acces, qr_code) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$owner_id, $code_box, $localisation, $type_box, $capacite_max, $temperature, $code_acces, $qr_data]);

                $ibox_id = $db->lastInsertId();

                $ajaxResponse['success'] = true;
                $ajaxResponse['message'] = "iBox <strong>$code_box</strong> créée avec succès ! Code d'accès : <strong>$code_acces</strong>";
                $ajaxResponse['code_box']   = $code_box;
                $ajaxResponse['code_acces'] = $code_acces;
                $ajaxResponse['ibox_id']    = $ibox_id;
            } catch (Exception $e) {
                $ajaxResponse['message'] = user_error_message($e, 'admin_ibox.creer', "Erreur lors de la création de l'iBox.");
            }
        }
    }

    // MODIFIER statut d'une iBox
    if ($action === 'modifier_statut') {
        $ibox_id = intval($_POST['ibox_id'] ?? 0);
        $statut  = $_POST['statut'] ?? '';
        $allowed = ['disponible', 'occupee', 'hors_service', 'recu'];
        if ($ibox_id > 0 && in_array($statut, $allowed)) {
            try {
                $stmt = $db->prepare("UPDATE ibox SET statut = ? WHERE id = ?");
                $stmt->execute([$statut, $ibox_id]);
                $ajaxResponse['success'] = true;
                $ajaxResponse['message'] = "Statut mis à jour.";
            } catch (Exception $e) {
                $ajaxResponse['message'] = user_error_message($e, 'admin_ibox.modifier_statut', "Erreur lors de la mise à jour du statut.");
            }
        } else {
            $ajaxResponse['message'] = "Données invalides.";
        }
    }

    // SUPPRIMER une iBox
    if ($action === 'supprimer_ibox') {
        $ibox_id = intval($_POST['ibox_id'] ?? 0);
        if ($ibox_id > 0) {
            try {
                // Vérifier qu'aucun colis actif n'est lié
                $stmt = $db->prepare("SELECT COUNT(*) as nb FROM colis WHERE ibox_id = ? AND statut NOT IN ('livre','annule','retourne')");
                $stmt->execute([$ibox_id]);
                $nb = $stmt->fetch()['nb'];
                if ($nb > 0) {
                    $ajaxResponse['message'] = "Impossible : $nb colis actif(s) lié(s) à cette iBox.";
                } else {
                    $stmt = $db->prepare("DELETE FROM ibox WHERE id = ?");
                    $stmt->execute([$ibox_id]);
                    $ajaxResponse['success'] = true;
                    $ajaxResponse['message'] = "iBox supprimée.";
                }
            } catch (Exception $e) {
                $ajaxResponse['message'] = user_error_message($e, 'admin_ibox.supprimer', "Erreur lors de la suppression de l'iBox.");
            }
        }
    }

    // Répondre en JSON si AJAX
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode($ajaxResponse, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// =====================================================
// RÉCUPÉRATION DES DONNÉES
// =====================================================
$ibox_list = [];
try {
    $stmt = $db->query("
        SELECT i.*, 
               u.nom as owner_nom, u.prenom as owner_prenom, u.email as owner_email, u.role as owner_role,
               (SELECT COUNT(*) FROM colis c WHERE c.ibox_id = i.id AND c.statut NOT IN ('livre','annule','retourne')) as colis_actifs,
               (SELECT COUNT(*) FROM colis c WHERE c.ibox_id = i.id) as total_colis
        FROM ibox i
        LEFT JOIN utilisateurs u ON i.utilisateur_id = u.id
        ORDER BY i.date_creation DESC
    ");
    $ibox_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("admin_ibox fetch: " . $e->getMessage());
}

// Stats rapides
$stats = [
    'total'        => count($ibox_list),
    'disponibles'  => 0,
    'occupees'     => 0,
    'hors_service' => 0,
];
foreach ($ibox_list as $b) {
    if ($b['statut'] === 'disponible')    $stats['disponibles']++;
    elseif ($b['statut'] === 'occupee')   $stats['occupees']++;
    elseif ($b['statut'] === 'hors_service') $stats['hors_service']++;
}

// Liste des utilisateurs pour le select d'assignation
$users = [];
try {
    $stmt = $db->query("SELECT id, nom, prenom, email, role FROM utilisateurs WHERE actif = 1 ORDER BY prenom, nom");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>

<!-- Contenu pour le système SPA -->
<div id="page-content">
<div class="page-container">

    <!-- En-tête -->
    <div class="page-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:2rem;">
        <div>
            <h1 style="margin:0;"><i class="fas fa-inbox" style="color:#00B4D8;"></i> Gestion des iBox &amp; Points Relais</h1>
            <p style="color:var(--text-muted); margin:0.25rem 0 0;">Administration des boîtes virtuelles du réseau</p>
        </div>
        <button class="btn btn-primary" onclick="document.getElementById('modalCreerIbox').style.display='flex'">
            <i class="fas fa-plus"></i> Créer une iBox
        </button>
    </div>

    <!-- Stats rapides -->
    <div class="stats-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:1rem; margin-bottom:2rem;">
        <div class="stat-card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:1.25rem; text-align:center;">
            <div style="font-size:2rem; font-weight:800; color:#00B4D8;"><?php echo $stats['total']; ?></div>
            <div style="color:var(--text-muted); font-size:0.85rem;">Total iBox</div>
        </div>
        <div class="stat-card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:1.25rem; text-align:center;">
            <div style="font-size:2rem; font-weight:800; color:#22c55e;"><?php echo $stats['disponibles']; ?></div>
            <div style="color:var(--text-muted); font-size:0.85rem;">Disponibles</div>
        </div>
        <div class="stat-card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:1.25rem; text-align:center;">
            <div style="font-size:2rem; font-weight:800; color:#f59e0b;"><?php echo $stats['occupees']; ?></div>
            <div style="color:var(--text-muted); font-size:0.85rem;">Occupées</div>
        </div>
        <div class="stat-card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:1.25rem; text-align:center;">
            <div style="font-size:2rem; font-weight:800; color:#ef4444;"><?php echo $stats['hors_service']; ?></div>
            <div style="color:var(--text-muted); font-size:0.85rem;">Hors service</div>
        </div>
    </div>

    <!-- Tableau des iBox -->
    <div class="card">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
            <h3><i class="fas fa-list"></i> Toutes les iBox (<?php echo $stats['total']; ?>)</h3>
            <input type="text" id="searchIbox" placeholder="🔍 Rechercher..." 
                   style="padding:0.5rem 1rem; border-radius:8px; border:1px solid var(--border-color); background:var(--bg-input); color:var(--text-primary); min-width:200px;"
                   onkeyup="filterTable()">
        </div>
        <div class="card-body" style="padding:0; overflow-x:auto;">
            <table id="iboxTable" style="width:100%; border-collapse:collapse; font-size:0.9rem;">
                <thead>
                    <tr style="background:var(--bg-tertiary); border-bottom:2px solid var(--border-color);">
                        <th style="padding:1rem; text-align:left;">Code Box</th>
                        <th style="padding:1rem; text-align:left;">Localisation</th>
                        <th style="padding:1rem; text-align:left;">Type</th>
                        <th style="padding:1rem; text-align:left;">Propriétaire</th>
                        <th style="padding:1rem; text-align:left;">Statut</th>
                        <th style="padding:1rem; text-align:left;">Colis actifs</th>
                        <th style="padding:1rem; text-align:left;">Code accès</th>
                        <th style="padding:1rem; text-align:left;">Créée le</th>
                        <th style="padding:1rem; text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ibox_list as $box): ?>
                    <tr style="border-bottom:1px solid var(--border-color); transition:background 0.2s;" 
                        onmouseover="this.style.background='var(--bg-hover)'" 
                        onmouseout="this.style.background=''">
                        <td style="padding:0.9rem 1rem;">
                            <span style="font-family:monospace; font-weight:700; color:#00B4D8; font-size:0.95rem;">
                                <?php echo htmlspecialchars($box['code_box']); ?>
                            </span>
                        </td>
                        <td style="padding:0.9rem 1rem;">
                            <i class="fas fa-map-marker-alt" style="color:#f59e0b; margin-right:4px;"></i>
                            <?php echo htmlspecialchars($box['localisation']); ?>
                        </td>
                        <td style="padding:0.9rem 1rem;">
                            <?php
                            $type_labels = ['small'=>'Petit','medium'=>'Moyen','large'=>'Grand','xlarge'=>'Très Grand'];
                            $type_colors = ['small'=>'#6366f1','medium'=>'#00B4D8','large'=>'#22c55e','xlarge'=>'#f59e0b'];
                            $tc = $type_colors[$box['type_box']] ?? '#6b7280';
                            ?>
                            <span style="background:<?php echo $tc; ?>22; color:<?php echo $tc; ?>; padding:3px 10px; border-radius:20px; font-size:0.8rem; font-weight:600;">
                                <?php echo $type_labels[$box['type_box']] ?? $box['type_box']; ?>
                                (<?php echo $box['capacite_max']; ?>)
                            </span>
                        </td>
                        <td style="padding:0.9rem 1rem;">
                            <?php if ($box['owner_nom']): ?>
                                <div style="font-weight:600;"><?php echo htmlspecialchars($box['owner_prenom'] . ' ' . $box['owner_nom']); ?></div>
                                <div style="font-size:0.78rem; color:var(--text-muted);"><?php echo htmlspecialchars($box['owner_email']); ?></div>
                                <span style="font-size:0.75rem; background:<?php echo $box['owner_role']==='admin'?'#ef444422':'#00B4D822'; ?>; color:<?php echo $box['owner_role']==='admin'?'#ef4444':'#00B4D8'; ?>; padding:1px 6px; border-radius:4px;">
                                    <?php echo ucfirst($box['owner_role']); ?>
                                </span>
                            <?php else: ?>
                                <span style="color:var(--text-muted);">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:0.9rem 1rem;">
                            <?php
                            $statut_config = [
                                'disponible'  => ['color'=>'#22c55e', 'bg'=>'#22c55e22', 'icon'=>'check-circle', 'label'=>'Disponible'],
                                'occupee'     => ['color'=>'#f59e0b', 'bg'=>'#f59e0b22', 'icon'=>'box-open',    'label'=>'Occupée'],
                                'hors_service'=> ['color'=>'#ef4444', 'bg'=>'#ef444422', 'icon'=>'times-circle', 'label'=>'Hors service'],
                                'recu'        => ['color'=>'#8b5cf6', 'bg'=>'#8b5cf622', 'icon'=>'inbox',       'label'=>'Reçu'],
                            ];
                            $sc = $statut_config[$box['statut']] ?? ['color'=>'#6b7280','bg'=>'#6b728022','icon'=>'question','label'=>$box['statut']];
                            ?>
                            <span style="background:<?php echo $sc['bg']; ?>; color:<?php echo $sc['color']; ?>; padding:4px 12px; border-radius:20px; font-size:0.8rem; font-weight:600; display:inline-flex; align-items:center; gap:5px;">
                                <i class="fas fa-<?php echo $sc['icon']; ?>"></i>
                                <?php echo $sc['label']; ?>
                            </span>
                        </td>
                        <td style="padding:0.9rem 1rem; text-align:center;">
                            <span style="font-weight:700; color:<?php echo $box['colis_actifs']>0?'#f59e0b':'#22c55e'; ?>; font-size:1.1rem;">
                                <?php echo $box['colis_actifs']; ?>
                            </span>
                            <span style="color:var(--text-muted); font-size:0.8rem;"> / <?php echo $box['total_colis']; ?></span>
                        </td>
                        <td style="padding:0.9rem 1rem;">
                            <span style="font-family:monospace; letter-spacing:3px; font-weight:700; color:#00B4D8;">
                                <?php echo htmlspecialchars($box['code_acces']); ?>
                            </span>
                        </td>
                        <td style="padding:0.9rem 1rem; color:var(--text-muted); font-size:0.85rem;">
                            <?php echo date('d/m/Y', strtotime($box['date_creation'])); ?>
                        </td>
                        <td style="padding:0.9rem 1rem; text-align:center;">
                            <div style="display:flex; gap:6px; justify-content:center; flex-wrap:wrap;">
                                <button onclick="ouvrirModifierStatut(<?php echo $box['id']; ?>, '<?php echo $box['statut']; ?>', '<?php echo htmlspecialchars($box['code_box']); ?>')"
                                        style="background:#00B4D822; color:#00B4D8; border:1px solid #00B4D8; padding:5px 10px; border-radius:6px; cursor:pointer; font-size:0.8rem;"
                                        title="Modifier statut">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button onclick="supprimerIbox(<?php echo $box['id']; ?>, '<?php echo htmlspecialchars($box['code_box']); ?>')"
                                        style="background:#ef444422; color:#ef4444; border:1px solid #ef4444; padding:5px 10px; border-radius:6px; cursor:pointer; font-size:0.8rem;"
                                        title="Supprimer">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($ibox_list)): ?>
                    <tr>
                        <td colspan="9" style="padding:4rem; text-align:center; color:var(--text-muted);">
                            <i class="fas fa-inbox" style="font-size:3rem; opacity:0.3; display:block; margin-bottom:1rem;"></i>
                            Aucune iBox dans le système. Créez la première !
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ==================== MODAL CRÉER IBOX ==================== -->
<div id="modalCreerIbox" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:9999; align-items:center; justify-content:center; padding:1rem;">
    <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-xl); padding:2rem; max-width:550px; width:100%; max-height:90vh; overflow-y:auto; position:relative;">
        <button onclick="document.getElementById('modalCreerIbox').style.display='none'"
                style="position:absolute; top:1rem; right:1rem; background:none; border:none; color:var(--text-muted); font-size:1.5rem; cursor:pointer;">
            <i class="fas fa-times"></i>
        </button>
        <h2 style="margin:0 0 1.5rem;"><i class="fas fa-plus-circle" style="color:#00B4D8;"></i> Créer une iBox</h2>
        
        <form id="formCreerIbox" method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="creer_ibox">

            <div style="margin-bottom:1.25rem;">
                <label style="display:block; margin-bottom:6px; font-weight:600; font-size:0.9rem;">
                    <i class="fas fa-map-marker-alt"></i> Localisation / Adresse *
                </label>
                <input type="text" name="localisation" class="form-control" required
                       placeholder="Ex: Centre Commercial Ydé Centre, Yaoundé">
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1.25rem;">
                <div>
                    <label style="display:block; margin-bottom:6px; font-weight:600; font-size:0.9rem;">
                        <i class="fas fa-expand"></i> Type de Box
                    </label>
                    <select name="type_box" class="form-control">
                        <option value="small">Petit (5 places)</option>
                        <option value="medium" selected>Moyen (10 places)</option>
                        <option value="large">Grand (20 places)</option>
                        <option value="xlarge">Très Grand (50 places)</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; margin-bottom:6px; font-weight:600; font-size:0.9rem;">
                        <i class="fas fa-thermometer-half"></i> Température
                    </label>
                    <select name="temperature" class="form-control">
                        <option value="ambiant" selected>Ambiant</option>
                        <option value="frigo">Réfrigéré</option>
                        <option value="congel">Congelé</option>
                    </select>
                </div>
            </div>

            <div style="margin-bottom:1.25rem;">
                <label style="display:block; margin-bottom:6px; font-weight:600; font-size:0.9rem;">
                    <i class="fas fa-user"></i> Assigner à un utilisateur (optionnel)
                </label>
                <select name="utilisateur_id" class="form-control">
                    <option value="0">— Admin (par défaut) —</option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?php echo $u['id']; ?>">
                        <?php echo htmlspecialchars($u['prenom'] . ' ' . $u['nom'] . ' (' . $u['role'] . ') - ' . $u['email']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:flex; gap:1rem; justify-content:flex-end; margin-top:1.5rem;">
                <button type="button" class="btn btn-secondary"
                        onclick="document.getElementById('modalCreerIbox').style.display='none'">
                    Annuler
                </button>
                <button type="submit" class="btn btn-primary" id="btnCreerIbox">
                    <i class="fas fa-plus"></i> Créer l'iBox
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== MODAL MODIFIER STATUT ==================== -->
<div id="modalModifierStatut" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-xl); padding:2rem; max-width:400px; width:90%;">
        <h3 style="margin:0 0 1.5rem;"><i class="fas fa-edit" style="color:#00B4D8;"></i> Modifier le statut</h3>
        <p id="modalStatutLabel" style="color:var(--text-muted); margin-bottom:1rem;"></p>
        <form id="formModifierStatut" method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="modifier_statut">
            <input type="hidden" name="ibox_id" id="inputModifIboxId">
            <select name="statut" id="selectNouveauStatut" class="form-control" style="margin-bottom:1.5rem;">
                <option value="disponible">✅ Disponible</option>
                <option value="occupee">📦 Occupée</option>
                <option value="hors_service">🔴 Hors service</option>
                <option value="recu">📬 Reçu</option>
            </select>
            <div style="display:flex; gap:1rem; justify-content:flex-end;">
                <button type="button" class="btn btn-secondary"
                        onclick="document.getElementById('modalModifierStatut').style.display='none'">
                    Annuler
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const csrfToken = <?php echo json_encode(csrf_token()); ?>;
// =====================================================
// FILTRER LE TABLEAU
// =====================================================
function filterTable() {
    const q = document.getElementById('searchIbox').value.toLowerCase();
    const rows = document.querySelectorAll('#iboxTable tbody tr');
    rows.forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

// =====================================================
// CRÉER IBOX
// =====================================================
document.getElementById('formCreerIbox').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnCreerIbox');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Création...';
    btn.disabled = true;

    fetch('admin_ibox.php', {
        method: 'POST',
        body: new FormData(this),
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showNotification('✅ ' + data.message, 'success');
            document.getElementById('modalCreerIbox').style.display = 'none';
            setTimeout(() => loadPage('views/admin/gestion_ibox.php', 'Gestion iBox'), 1200);
        } else {
            showNotification('❌ ' + data.message, 'error');
        }
    })
    .catch(() => showNotification('Erreur réseau', 'error'))
    .finally(() => {
        btn.innerHTML = '<i class="fas fa-plus"></i> Créer l\'iBox';
        btn.disabled = false;
    });
});

// =====================================================
// MODIFIER STATUT
// =====================================================
function ouvrirModifierStatut(iboxId, statutActuel, codeBox) {
    document.getElementById('inputModifIboxId').value = iboxId;
    document.getElementById('selectNouveauStatut').value = statutActuel;
    document.getElementById('modalStatutLabel').textContent = 'iBox : ' + codeBox;
    document.getElementById('modalModifierStatut').style.display = 'flex';
}

document.getElementById('formModifierStatut').addEventListener('submit', function(e) {
    e.preventDefault();
    fetch('admin_ibox.php', {
        method: 'POST',
        body: new FormData(this),
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showNotification('✅ Statut mis à jour', 'success');
            document.getElementById('modalModifierStatut').style.display = 'none';
            setTimeout(() => loadPage('views/admin/gestion_ibox.php', 'Gestion iBox'), 1000);
        } else {
            showNotification('❌ ' + data.message, 'error');
        }
    });
});

// =====================================================
// SUPPRIMER IBOX
// =====================================================
function supprimerIbox(iboxId, codeBox) {
    if (!confirm(`Supprimer l'iBox ${codeBox} ?\n\nCette action est irréversible.`)) return;

    const fd = new FormData();
    fd.append('action', 'supprimer_ibox');
    fd.append('ibox_id', iboxId);

    fetch('admin_ibox.php', {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showNotification('✅ ' + data.message, 'success');
            setTimeout(() => loadPage('views/admin/gestion_ibox.php', 'Gestion iBox'), 1000);
        } else {
            showNotification('❌ ' + data.message, 'error');
        }
    });
}

// Fermer modals en cliquant à l'extérieur
document.getElementById('modalCreerIbox').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
document.getElementById('modalModifierStatut').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});

console.log('%c🚀 Admin iBox - Gestion des Boîtes Virtuelles', 'color:#00B4D8; font-size:14px; font-weight:bold;');
</script>

</div><!-- fin #page-content -->
