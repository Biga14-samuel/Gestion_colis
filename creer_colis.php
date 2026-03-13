<?php
/**
 * =====================================================
 * CRÉATION DE COLIS - CORRIGÉ
 * Correction de l'affichage des agents et améliorations
 * =====================================================
 */
require_once __DIR__ . '/utils/session.php';
SessionManager::start();
// Activer le buffer de sortie pour capturer les erreurs PHP
ob_start();
require_once 'config/database.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'message' => 'Accès refusé. Veuillez vous connecter.']);
    exit;
}

$database = new Database();
$db = $database->getConnection();
$userId = $_SESSION['user_id'];

// Activer l'affichage des erreurs pour le diagnostic
error_reporting(E_ALL);
ini_set('display_errors', 0); // Ne pas afficher directement mais logger

// =====================================================
// RÉCUPÉRATION DU POSTAL ID UTILISATEUR
// =====================================================
$userPostalId = null;
try {
    $stmt = $db->prepare("
        SELECT identifiant_postal, type_piece, numero_piece, date_expiration 
        FROM postal_id 
        WHERE utilisateur_id = ? AND actif = 1 
        ORDER BY date_creation DESC 
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $userPostalId = $stmt->fetch();
} catch (Exception $e) {
    $userPostalId = null;
    error_log('Erreur récupération Postal ID: ' . $e->getMessage());
}

// =====================================================
// RÉCUPÉRATION DES INFORMATIONS PIÈCE D'IDENTITÉ
// =====================================================
$pieceIdentite = null;
try {
    $stmt = $db->prepare("
        SELECT type_piece, numero_piece, date_expiration 
        FROM pieces_identite 
        WHERE utilisateur_id = ? AND actif = 1 
        ORDER BY date_creation DESC 
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $pieceIdentite = $stmt->fetch();
} catch (Exception $e) {
    $pieceIdentite = null;
    error_log('Erreur récupération pièce identité: ' . $e->getMessage());
}

// =====================================================
// RÉCUPÉRATION DES AGENTS DISPONIBLES - CORRIGÉ v2
// =====================================================
$agents = [];
try {
    // Jointure correcte: agents.numero_agent (pas matricule qui n'existe pas dans agents)
    $stmt = $db->prepare("
        SELECT a.id, a.numero_agent, u.nom, u.prenom, a.zone_livraison, a.actif
        FROM agents a 
        INNER JOIN utilisateurs u ON a.utilisateur_id = u.id 
        WHERE a.actif = 1 AND u.actif = 1
        ORDER BY u.prenom ASC, u.nom ASC
    ");
    $stmt->execute();
    $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log('Agents disponibles: ' . count($agents));
} catch (Exception $e) {
    $agents = [];
    error_log('Erreur récupération agents: ' . $e->getMessage());
}

// Fallback: récupérer aussi les utilisateurs avec role=agent sans entrée dans agents
if (empty($agents)) {
    try {
        $stmt = $db->prepare("
            SELECT u.id, u.matricule as numero_agent, u.nom, u.prenom, u.zone_livraison, u.actif
            FROM utilisateurs u 
            WHERE u.role = 'agent' AND u.actif = 1
            ORDER BY u.prenom ASC, u.nom ASC
        ");
        $stmt->execute();
        $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log('Agents fallback (utilisateurs): ' . count($agents));
    } catch (Exception $e) {
        error_log('Erreur fallback agents: ' . $e->getMessage());
    }
}

// Récupérer toutes les iBox disponibles du réseau (gérées par l'admin)
$ibox_list = [];
try {
    $stmt = $db->prepare("
        SELECT id, code_box, localisation, type_box, statut, capacite_max
        FROM ibox 
        WHERE statut = 'disponible'
        ORDER BY localisation ASC, date_creation DESC
    ");
    $stmt->execute();
    $ibox_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $ibox_list = [];
}

// Les iBox partagées ne sont plus affichées ici - toutes les iBox du réseau sont disponibles

// Traitement AJAX du formulaire
$ajaxResponse = ['success' => false, 'message' => '', 'redirect' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reference_colis'])) {
    try {
    $reference_colis = trim($_POST['reference_colis']);
    $description = trim($_POST['description'] ?? '');
    $poids = isset($_POST['poids']) ? floatval($_POST['poids']) : 0;
    $valeur_declaree = isset($_POST['valeur_declaree']) ? floatval($_POST['valeur_declaree']) : 0;
    $agent_id = isset($_POST['agent_id']) ? intval($_POST['agent_id']) : 0;
    $ibox_id = isset($_POST['ibox_id']) ? intval($_POST['ibox_id']) : 0;
    $fragile = isset($_POST['fragile']) ? 1 : 0;
    $urgent = isset($_POST['urgent']) ? 1 : 0;
    $instructions = isset($_POST['instructions']) ? trim($_POST['instructions']) : '';
    $signature_data = isset($_POST['signature_data']) ? $_POST['signature_data'] : '';
    
    $errors = [];
    $descLen = function_exists('mb_strlen') ? mb_strlen($description) : strlen($description);
    $instrLen = function_exists('mb_strlen') ? mb_strlen($instructions) : strlen($instructions);
    
    if (empty($reference_colis)) $errors[] = "La référence du colis est requise";
    if (empty($description)) $errors[] = "La description est requise";
    if ($descLen > 500) $errors[] = "La description ne doit pas dépasser 500 caractères";
    if ($instrLen > 500) $errors[] = "Les instructions ne doivent pas dépasser 500 caractères";
    // Le poids est renseigné par l'agent lors de la collecte - pas obligatoire pour l'utilisateur
    
    // Récupérer la zone de livraison de l'agent sélectionné et valider l'agent
    $zone_livraison = null;
    $valid_agent_id = 0; // Pour stocker l'agent_id validé
    if ($agent_id > 0) {
        try {
            // Vérifier d'abord si l'agent existe dans la table agents
            $stmt = $db->prepare("SELECT id, zone_livraison, zone_affectation FROM agents WHERE id = ? AND actif = 1");
            $stmt->execute([$agent_id]);
            $agent_data = $stmt->fetch();
            
            if ($agent_data) {
                // Agent trouvé dans la table agents - c'est valide
                $valid_agent_id = $agent_data['id'];
                $zone_livraison = $agent_data['zone_livraison'] ?? $agent_data['zone_affectation'] ?? null;
            } else {
                // Agent non trouvé dans la table agents - vérifier si c'est un utilisateur avec rôle agent
                $stmt = $db->prepare("SELECT id, zone_livraison FROM utilisateurs WHERE id = ? AND role = 'agent' AND actif = 1");
                $stmt->execute([$agent_id]);
                $user_agent = $stmt->fetch();
                
                if ($user_agent) {
                    // L'utilisateur existe et a le rôle agent, mais n'a pas d'entrée dans la table agents
                    // On ne peut pas l'assigner car la contrainte de clé étrangère l'exige
                    $errors[] = "L'agent sélectionné n'est pas correctement configuré dans le système.";
                    error_log("Tentative d'assignation d'agent sans entrée dans table agents: utilisateur_id=" . $agent_id);
                } else {
                    // Ni agent ni utilisateur trouvé
                    $errors[] = "Agent non trouvé dans le système.";
                }
            }
        } catch (Exception $e) {
            error_log('Erreur récupération agent: ' . $e->getMessage());
        }
    }
    
    // Vérifier que l'iBox existe et est accessible
    if ($ibox_id > 0) {
        try {
            $stmt = $db->prepare("
                SELECT i.* FROM ibox i
                LEFT JOIN ibox_shares s ON i.id = s.ibox_id AND s.is_active = 1
                    AND (s.shared_with_user_id = ? OR s.shared_with_email = 
                        (SELECT email FROM utilisateurs WHERE id = ?))
                WHERE i.id = ? AND i.statut IN ('disponible', 'recu')
            ");
            $stmt->execute([$userId, $userId, $ibox_id]);
            $ibox = $stmt->fetch();
            if (!$ibox) {
                $errors[] = "L'iBox sélectionnée n'est pas accessible.";
            }
        } catch (Exception $e) {
            error_log('Erreur vérification iBox: ' . $e->getMessage());
            $errors[] = "Erreur lors de la vérification de l'iBox.";
        }
    }
    
    if (empty($errors)) {
        try {
            // Générer un code de tracking unique
            $code_tracking = 'TRK' . date('Ymd') . str_pad(rand(1, 999999), 6, '0', STR_PAD_LEFT);
            
            // Valeur par défaut pour les dimensions (non obligatoire pour l'utilisateur)
            $dimensions = 'Non spécifié';
            
            // Vérifier si la colonne zone_livraison existe dans la table colis
            $zone_colonne_existe = false;
            try {
                $stmt = $db->query("SHOW COLUMNS FROM colis LIKE 'zone_livraison'");
                $zone_colonne_existe = $stmt->rowCount() > 0;
            } catch (Exception $e) {
                $zone_colonne_existe = false;
            }
            
            // Construire la requête INSERT en fonction des colonnes disponibles
            $insert_cols = "utilisateur_id, ibox_id, agent_id, reference_colis, description, poids, dimensions, valeur_declaree, fragile, urgent, statut, code_tracking, instructions";
            $insert_vals = "?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'en_attente', ?, ?";
            $insert_params = [$userId, $ibox_id > 0 ? $ibox_id : null, $valid_agent_id > 0 ? $valid_agent_id : null, $reference_colis, $description, $poids, $dimensions, $valeur_declaree, $fragile, $urgent, $code_tracking, $instructions];
            
            // Ajouter zone_livraison si la colonne existe
            if ($zone_colonne_existe && $zone_livraison !== null) {
                $insert_cols .= ", zone_livraison";
                $insert_vals .= ", ?";
                $insert_params[] = $zone_livraison;
            }
            
            $stmt = $db->prepare("INSERT INTO colis (" . $insert_cols . ") VALUES (" . $insert_vals . ")");
            
            // Utiliser les paramètres dynamiques
            if ($stmt->execute($insert_params)) {
                $colis_id = $db->lastInsertId();
                
                // Enregistrer la signature si elle existe
                if (!empty($signature_data) && strpos($signature_data, 'data:image') === 0) {
                    $signature_dir = __DIR__ . '/uploads/signatures';
                    if (!is_dir($signature_dir)) {
                        @mkdir($signature_dir, 0755, true);
                    }
                    
                    $signature_filename = 'signature_' . $colis_id . '_' . time() . '.png';
                    $signature_path = $signature_dir . '/' . $signature_filename;
                    
                    // Decoder l'image base64 et l'enregistrer
                    $signature_data_clean = str_replace(['data:image/png;base64,', 'data:image/jpeg;base64,'], '', $signature_data);
                    $signature_binary = base64_decode($signature_data_clean, true);
                    
                    if ($signature_binary !== false && @file_put_contents($signature_path, $signature_binary)) {
                        // Mettre à jour le colis avec le chemin de la signature
                        try {
                            $stmt_sig = $db->prepare("UPDATE colis SET signature_image = ? WHERE id = ?");
                            $stmt_sig->execute(['uploads/signatures/' . $signature_filename, $colis_id]);
                        } catch (Exception $e) {
                            // La colonne signature_image peut ne pas exister
                            error_log('Erreur enregistrement signature: ' . $e->getMessage());
                        }
                    }
                }
                
                // Si un agent est attribué, créer une entrée dans la table LIVRAISONS
                if ($valid_agent_id > 0) {
                    try {
                        $stmt_livraison = $db->prepare("
                            INSERT INTO livraisons (colis_id, agent_id, date_assignation, statut) 
                            VALUES (?, ?, NOW(), 'assignee')
                        ");
                        $stmt_livraison->execute([$colis_id, $valid_agent_id]);
                    } catch (Exception $e) {
                        error_log('Erreur création livraison: ' . $e->getMessage());
                        // Ne pas bloquer la création du colis si la livraison échoue
                    }
                }
                
                // Si le colis est destiné à une iBox, générer le code de retrait et mettre à jour le statut
                if ($ibox_id > 0) {
                    try {
                        require_once 'utils/pickup_code_service.php';
                        if (class_exists('PickupCodeService')) {
                            $pickupService = new PickupCodeService();
                            $pickupResult = $pickupService->generateCode($colis_id, 'pin', false);
                            $ajaxResponse['pickup_code'] = $pickupResult['code'] ?? null;
                        } else {
                            // Générer un code de retrait simple si le service n'est pas disponible
                            $pickup_code = strtoupper(substr(md5($colis_id . time()), 0, 6));
                            $ajaxResponse['pickup_code'] = $pickup_code;
                            
                            // Enregistrer le code manuellement
                            $stmt_pickup = $db->prepare("INSERT INTO pickup_codes (colis_id, code, type_code, date_expiration) VALUES (?, ?, 'pin', DATE_ADD(NOW(), INTERVAL 30 DAY))");
                            $stmt_pickup->execute([$colis_id, $pickup_code]);
                        }
                        
                        // Mettre à jour le statut de l'iBox à "recu" (contenant un colis)
                        $stmt = $db->prepare("UPDATE ibox SET statut = 'recu' WHERE id = ?");
                        $stmt->execute([$ibox_id]);
                    } catch (Exception $e) {
                        error_log('Erreur iBox: ' . $e->getMessage());
                        // Ne pas bloquer la création du colis pour une erreur iBox
                        $ajaxResponse['pickup_code'] = null;
                    }
                }
                
                $ajaxResponse['success'] = true;
                $message = "Colis créé avec succès ! Code de tracking : " . $code_tracking;
                if ($ibox_id > 0 && !empty($ajaxResponse['pickup_code'])) {
                    $message .= "\n\nCe colis sera livré dans une iBox.\nCode de retrait : " . $ajaxResponse['pickup_code'];
                }
                $ajaxResponse['message'] = $message;
                $ajaxResponse['redirect'] = 'views/client/mes_colis.php';
            } else {
                $errors[] = "Erreur lors de la création du colis";
            }
        } catch (Exception $e) {
            $errors[] = user_error_message($e, 'creer_colis.create', "Erreur lors de la création du colis.");
        }
    }
    
    if (!empty($errors)) {
        $ajaxResponse['message'] = implode("\n", $errors);
    }
    
    // Si c'est une requête AJAX, retourner JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        // Vider le buffer avant d'envoyer JSON
        ob_end_clean();
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, must-revalidate');
        
        // Nettoyer le message
        $ajaxResponse['message'] = strip_tags($ajaxResponse['message']);
        
        $jsonOutput = json_encode($ajaxResponse, JSON_UNESCAPED_UNICODE);
        if ($jsonOutput === false) {
            echo json_encode(['success' => false, 'message' => 'Erreur de traitement des données'], JSON_UNESCAPED_UNICODE);
        } else {
            echo $jsonOutput;
        }
        exit;
    }
    } catch (Exception $e) {
        error_log('Erreur globale création colis: ' . $e->getMessage());
        $ajaxResponse['message'] = "Une erreur est survenue lors de la création du colis. Veuillez réessayer.";
    }
}
?>

<!-- Contenu pour le système SPA -->
<div id="page-content">

<div class="page-container">
    <div class="page-header">
        <h1><i class="fas fa-box"></i> Créer un Colis</h1>
        <p>Enregistrez votre colis dans le système</p>
    </div>

    <div class="card">
        <div class="card-body">
            <?php if (isset($_SESSION['error']) && !empty($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div><?php echo htmlspecialchars($_SESSION['error']); ?></div>
                </div>
            <?php endif; ?>

            <form id="creerColisForm" method="POST" action="creer_colis.php">
                <?php echo csrf_field(); ?>
                <!-- Poids géré par l'agent à la collecte -->
                <input type="hidden" name="poids" value="0">
                <div class="form-row">
                    <div class="form-group">
                        <label for="reference_colis">
                            <i class="fas fa-hashtag"></i> Référence du Colis
                        </label>
                        <input 
                            type="text" 
                            id="reference_colis" 
                            name="reference_colis" 
                            required 
                            placeholder="Ex: COLIS001"
                            class="form-control"
                            value="<?php echo htmlspecialchars($_POST['reference_colis'] ?? ''); ?>"
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label for="description">
                        <i class="fas fa-file-text"></i> Description
                    </label>
                    <textarea 
                        id="description" 
                        name="description" 
                        required 
                        rows="3"
                        placeholder="Décrivez le contenu de votre colis..."
                        class="form-control"
                    ><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="valeur_declaree">
                            <i class="fas fa-coins"></i> Valeur Déclarée (FCFA)
                        </label>
                        <input 
                            type="number" 
                            id="valeur_declaree" 
                            name="valeur_declaree" 
                            step="0.01"
                            min="0"
                            placeholder="0.00"
                            class="form-control"
                            value="<?php echo htmlspecialchars($_POST['valeur_declaree'] ?? ''); ?>"
                        >
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group" style="flex: 1;">
                        <label for="agent_id">
                            <i class="fas fa-user-tie"></i> Agent responsable (optionnel)
                        </label>
                        <select id="agent_id" name="agent_id" class="form-control">
                            <option value="">Sélectionner un agent...</option>
                            <?php if (!empty($agents)): ?>
                                <?php foreach ($agents as $agent): ?>
                                    <?php 
                                    $agentName = trim(($agent['prenom'] ?? '') . ' ' . ($agent['nom'] ?? ''));
                                    $agentZone = $agent['zone_livraison'] ?? 'Zone non définie';
                                    $agentNum = $agent['numero_agent'] ?? ($agent['matricule'] ?? '');
                                    ?>
                                    <option value="<?php echo $agent['id']; ?>">
                                        <?php echo htmlspecialchars($agentName . ' (' . $agentNum . ') - ' . $agentZone); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>Aucun agent disponible pour le moment</option>
                            <?php endif; ?>
                        </select>
                        <small class="form-hint" style="color: var(--text-muted); font-size: 0.8rem;">
                            <i class="fas fa-info-circle"></i> Sélectionnez un agent pour la livraison de votre colis
                        </small>
                    </div>
                </div>

                <!-- Section Postal ID Info et Pièce d'Identité (affiché automatiquement avec champs pré-remplis) -->
                <div class="form-section-title">
                    <i class="fas fa-id-card"></i>
                    Informations d'Identité
                </div>
                
                <?php if ($userPostalId || $pieceIdentite): ?>
                <div class="form-row compact">
                    <div class="form-group">
                        <label for="type_piece">
                            <i class="fas fa-id-card"></i> Type de pièce <span class="required">*</span>
                        </label>
                        <select id="type_piece" name="type_piece" class="form-control" required>
                            <option value="">Sélectionnez...</option>
                            <option value="carte_nationale" <?php echo (isset($pieceIdentite['type_piece']) && $pieceIdentite['type_piece'] === 'carte_nationale') ? 'selected' : ''; ?>>
                                Carte Nationale d'Identité
                            </option>
                            <option value="passeport" <?php echo (isset($pieceIdentite['type_piece']) && $pieceIdentite['type_piece'] === 'passeport') ? 'selected' : ''; ?>>
                                Passeport
                            </option>
                            <option value="permis_conduire" <?php echo (isset($pieceIdentite['type_piece']) && $pieceIdentite['type_piece'] === 'permis_conduire') ? 'selected' : ''; ?>>
                                Permis de Conduire
                            </option>
                            <option value="autre" <?php echo (isset($pieceIdentite['type_piece']) && $pieceIdentite['type_piece'] === 'autre') ? 'selected' : ''; ?>>
                                Autre pièce
                            </option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="numero_piece">
                            <i class="fas fa-hashtag"></i> Numéro de pièce <span class="required">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="numero_piece" 
                            name="numero_piece" 
                            required 
                            placeholder="Numéro d'identification"
                            class="form-control"
                            value="<?php echo htmlspecialchars($pieceIdentite['numero_piece'] ?? ''); ?>"
                        >
                    </div>
                </div>
                <?php else: ?>
                <div class="form-row compact">
                    <div class="form-group">
                        <label for="type_piece">
                            <i class="fas fa-id-card"></i> Type de pièce <span class="required">*</span>
                        </label>
                        <select id="type_piece" name="type_piece" class="form-control" required>
                            <option value="">Sélectionnez...</option>
                            <option value="carte_nationale">Carte Nationale d'Identité</option>
                            <option value="passeport">Passeport</option>
                            <option value="permis_conduire">Permis de Conduire</option>
                            <option value="autre">Autre pièce</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="numero_piece">
                            <i class="fas fa-hashtag"></i> Numéro de pièce <span class="required">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="numero_piece" 
                            name="numero_piece" 
                            required 
                            placeholder="Numéro d'identification"
                            class="form-control"
                            value=""
                        >
                    </div>
                </div>
                <?php endif; ?>

                <!-- Section iBox / Point relais -->
                <div class="form-row">
                    <div class="form-group" style="flex: 1;">
                        <label for="ibox_id">
                            <i class="fas fa-inbox"></i> Point relais iBox (optionnel)
                        </label>
                        <select id="ibox_id" name="ibox_id" class="form-control">
                            <option value="">Livraison standard (sans iBox)</option>
                            <?php if (!empty($ibox_list)): ?>
                                <?php foreach ($ibox_list as $ibox): ?>
                                    <option value="<?php echo $ibox['id']; ?>">
                                        <?php echo htmlspecialchars($ibox['code_box'] . ' — ' . $ibox['localisation'] . ' (' . ucfirst($ibox['type_box']) . ', ' . $ibox['capacite_max'] . ' places)'); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>Aucune iBox disponible actuellement</option>
                            <?php endif; ?>
                        </select>
                        <small class="form-hint" style="color: var(--text-muted); font-size: 0.8rem;">
                            <i class="fas fa-info-circle"></i> Le colis sera livré dans l'iBox sélectionnée et un code de retrait sera généré.
                        </small>
                    </div>
                </div>

                <div class="form-group">
                    <label for="instructions">
                        <i class="fas fa-comment"></i> Instructions Spéciales
                    </label>
                    <textarea 
                        id="instructions" 
                        name="instructions" 
                        rows="2"
                        placeholder="Instructions particulières pour la livraison..."
                        class="form-control"
                    ><?php echo htmlspecialchars($_POST['instructions'] ?? ''); ?></textarea>
                </div>

                <div style="display: flex; gap: 1rem; margin-bottom: 1.5rem;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                        <input type="checkbox" name="fragile" <?php echo isset($_POST['fragile']) ? 'checked' : ''; ?> 
                               style="width: 18px; height: 18px; accent-color: #00B4D8;">
                        <span><i class="fas fa-exclamation-triangle" style="color: #f59e0b;"></i> Colis fragile</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                        <input type="checkbox" name="urgent" <?php echo isset($_POST['urgent']) ? 'checked' : ''; ?>
                               style="width: 18px; height: 18px; accent-color: #00B4D8;">
                        <span><i class="fas fa-bolt" style="color: #00B4D8;"></i> Livraison urgente</span>
                    </label>
                </div>

                <!-- Zone de signature tactile -->
                <div class="form-group" id="signature-group">
                    <label for="signature">
                        <i class="fas fa-signature"></i> Signature du client <span class="required">*</span>
                    </label>
                    <div class="signature-container" style="border: 2px dashed var(--border-color); border-radius: 12px; padding: 15px; background: linear-gradient(135deg, #fff 0%, #f8fafc 100%); position: relative;">
                        <!-- Canvas de signature avec support tactile complet -->
                        <canvas id="signature-pad" style="border: 2px solid #e2e8f0; border-radius: 8px; cursor: crosshair; width: 100%; max-width: 500px; height: 180px; touch-action: none; background: #fff; display: block; margin: 0 auto;"></canvas>
                        <div style="margin-top: 12px; display: flex; gap: 12px; align-items: center; justify-content: center; flex-wrap: wrap;">
                            <button type="button" class="btn btn-secondary" onclick="clearSignature()" style="padding: 8px 16px; font-size: 0.85rem;">
                                <i class="fas fa-eraser"></i> Effacer
                            </button>
                            <span id="signature-status" style="font-size: 0.85rem; color: var(--text-secondary); display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-info-circle" style="color: #00B4D8;"></i> Signez avec la souris ou le doigt (support tactile)
                            </span>
                        </div>
                    </div>
                    <small class="form-hint" style="color: #dc2626; display: none; margin-top: 8px;" id="signature-warning">
                        <i class="fas fa-exclamation-triangle"></i> La signature est obligatoire pour créer le colis
                    </small>
                    <input type="hidden" name="signature_data" id="signature_data">
                </div>

                <div class="form-actions">
                    <div class="form-buttons" style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus"></i>
                            Créer le Colis
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="loadPage('views/client/mes_colis.php', 'Mes Colis')" style="display: flex; align-items: center; justify-content: center; text-decoration: none;">
                            <i class="fas fa-arrow-left"></i>
                            Retour
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const csrfToken = <?php echo json_encode(csrf_token()); ?>;
// =====================================================
// SCRIPT DE PAGE - SIGNATURE PAD CORRIGÉ
// =====================================================

// Fonction d'initialisation immédiate (pas de DOMContentLoaded requis)
function initSignaturePage() {
    console.log('🎨 Initialisation du pad de signature...');
    
    const canvas = document.getElementById('signature-pad');
    const container = document.querySelector('.signature-container');
    const statusEl = document.getElementById('signature-status');
    
    if (!canvas) {
        console.error('❌ Canvas de signature non trouvé!');
        return false;
    }
    
    console.log('✅ Canvas trouvé:', canvas);
    
    // Obtenir le contexte 2D
    const ctx = canvas.getContext('2d');
    
    // Configurer les dimensions du canvas
    function setupCanvas() {
        const rect = canvas.getBoundingClientRect();
        const containerWidth = container ? container.clientWidth : rect.width;
        
        // Largeur responsive (max 500px)
        const displayWidth = Math.min(containerWidth - 30, 500);
        const displayHeight = 180;
        
        // Définir les dimensions internes (haute résolution)
        canvas.width = displayWidth * window.devicePixelRatio;
        canvas.height = displayHeight * window.devicePixelRatio;
        
        // Ajuster le style CSS
        canvas.style.width = displayWidth + 'px';
        canvas.style.height = displayHeight + 'px';
        
        // Mettre à l'échelle le contexte
        ctx.scale(window.devicePixelRatio, window.devicePixelRatio);
        
        // Style de dessin
        ctx.strokeStyle = '#1E293B';
        ctx.lineWidth = 2.5;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        
        console.log(`📐 Canvas configuré: ${displayWidth}x${displayHeight}px`);
    }
    
    // Initialiser le canvas
    setupCanvas();
    
    // Variables de dessin
    let isDrawing = false;
    let lastX = 0;
    let lastY = 0;
    
    // Fonctions de position
    function getMousePos(e) {
        const rect = canvas.getBoundingClientRect();
        return {
            x: e.clientX - rect.left,
            y: e.clientY - rect.top
        };
    }
    
    function getTouchPos(e) {
        const rect = canvas.getBoundingClientRect();
        const touch = e.touches[0] || e.changedTouches[0];
        return {
            x: touch.clientX - rect.left,
            y: touch.clientY - rect.top
        };
    }
    
    // Gestionnaires d'événements Souris
    function handleMouseDown(e) {
        e.preventDefault();
        isDrawing = true;
        const pos = getMousePos(e);
        lastX = pos.x;
        lastY = pos.y;
        
        // Dessiner un point de départ
        ctx.beginPath();
        ctx.arc(lastX, lastY, 3, 0, Math.PI * 2);
        ctx.fillStyle = '#1E293B';
        ctx.fill();
        
        updateStatus('Dessinez votre signature', 'pen');
    }
    
    function handleMouseMove(e) {
        if (!isDrawing) return;
        e.preventDefault();
        
        const pos = getMousePos(e);
        ctx.beginPath();
        ctx.moveTo(lastX, lastY);
        ctx.lineTo(pos.x, pos.y);
        ctx.stroke();
        
        lastX = pos.x;
        lastY = pos.y;
    }
    
    function handleMouseUp(e) {
        e.preventDefault();
        isDrawing = false;
        updateStatus('Signature enregistrée', 'check');
        if (container) {
            container.style.borderColor = '#22c55e';
        }
    }
    
    function handleMouseOut(e) {
        e.preventDefault();
        isDrawing = false;
    }
    
    // Gestionnaires d'événements Tactiles
    function handleTouchStart(e) {
        e.preventDefault();
        e.stopPropagation();
        
        if (e.touches.length === 0) return;
        
        isDrawing = true;
        const pos = getTouchPos(e);
        lastX = pos.x;
        lastY = pos.y;
        
        ctx.beginPath();
        ctx.arc(lastX, lastY, 3, 0, Math.PI * 2);
        ctx.fillStyle = '#1E293B';
        ctx.fill();
        
        updateStatus('Dessinez votre signature', 'pen');
    }
    
    function handleTouchMove(e) {
        if (!isDrawing) return;
        e.preventDefault();
        e.stopPropagation();
        
        if (e.touches.length === 0) return;
        
        const pos = getTouchPos(e);
        ctx.beginPath();
        ctx.moveTo(lastX, lastY);
        ctx.lineTo(pos.x, pos.y);
        ctx.stroke();
        
        lastX = pos.x;
        lastY = pos.y;
    }
    
    function handleTouchEnd(e) {
        e.preventDefault();
        e.stopPropagation();
        isDrawing = false;
        updateStatus('Signature enregistrée', 'check');
        if (container) {
            container.style.borderColor = '#22c55e';
        }
    }
    
    // Mettre à jour le statut visuel
    function updateStatus(message, icon) {
        if (statusEl) {
            const icons = {
                'pen': '<i class="fas fa-pen" style="margin-right: 5px; color: #00B4D8;"></i>',
                'check': '<i class="fas fa-check-circle" style="margin-right: 5px; color: #22c55e;"></i>',
                'info': '<i class="fas fa-info-circle" style="margin-right: 5px;"></i>'
            };
            statusEl.innerHTML = icons[icon] + message;
        }
    }
    
    // Attacher les événements Souris
    canvas.removeEventListener('mousedown', handleMouseDown);
    canvas.removeEventListener('mousemove', handleMouseMove);
    canvas.removeEventListener('mouseup', handleMouseUp);
    canvas.removeEventListener('mouseout', handleMouseOut);
    
    canvas.addEventListener('mousedown', handleMouseDown, { passive: false });
    canvas.addEventListener('mousemove', handleMouseMove, { passive: false });
    canvas.addEventListener('mouseup', handleMouseUp, { passive: false });
    canvas.addEventListener('mouseout', handleMouseOut, { passive: false });
    
    // Attacher les événements Tactiles
    canvas.removeEventListener('touchstart', handleTouchStart);
    canvas.removeEventListener('touchmove', handleTouchMove);
    canvas.removeEventListener('touchend', handleTouchEnd);
    canvas.removeEventListener('touchcancel', handleTouchEnd);
    
    canvas.addEventListener('touchstart', handleTouchStart, { passive: false });
    canvas.addEventListener('touchmove', handleTouchMove, { passive: false });
    canvas.addEventListener('touchend', handleTouchEnd, { passive: false });
    canvas.addEventListener('touchcancel', handleTouchEnd, { passive: false });
    
    // Empêcher le scroll sur le canvas
    canvas.style.touchAction = 'none';
    canvas.style.webkitTouchCallout = 'none';
    
    // Réinitialiser la bordure du container
    if (container) {
        container.style.borderColor = 'var(--border-color)';
    }
    
    // Fonction effacer globale
    window.clearSignature = function() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        
        const sigData = document.getElementById('signature_data');
        if (sigData) sigData.value = '';
        
        if (container) {
            container.style.borderColor = 'var(--border-color)';
        }
        updateStatus('Signez avec la souris ou le doigt', 'info');
        
        const warning = document.getElementById('signature-warning');
        if (warning) warning.style.display = 'none';
        
        console.log('🗑️ Signature effacée');
    };
    
    // Fonction vérifier si canvas vide
    window.checkCanvasBlank = function() {
        try {
            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const data = imageData.data;
            let nonTransparent = 0;
            for (let i = 3; i < data.length; i += 4) {
                if (data[i] > 50) nonTransparent++;
            }
            return nonTransparent < 10;
        } catch (e) {
            return true;
        }
    };
    
    console.log('✅ Pad de signature initialisé avec succès!');
    return true;
}

// =====================================================
// INITIALISATION IMMÉDIATE (pas de DOMContentLoaded)
// =====================================================
// Exécuter immédiatement après le chargement du script
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSignaturePage);
} else {
    // DOM déjà chargé, exécuter avec un léger délai
    setTimeout(initSignaturePage, 50);
}

// Générer automatiquement une référence si vide
const refInput = document.getElementById('reference_colis');
if (refInput) {
    refInput.addEventListener('blur', function() {
        if (!this.value) {
            const now = new Date();
            const ref = 'COLIS' + now.getFullYear().toString().slice(-2) + 
                       (now.getMonth() + 1).toString().padStart(2, '0') + 
                       now.getDate().toString().padStart(2, '0') + 
                       Math.random().toString(36).substr(2, 3).toUpperCase();
            this.value = ref;
        }
    });
}

// =====================================================
// SOUMISSION DU FORMULAIRE
// =====================================================

document.getElementById('creerColisForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    console.log('📤 Soumission du formulaire...');
    
    const canvas = document.getElementById('signature-pad');
    const signatureStatus = document.getElementById('signature-status');
    const signatureWarning = document.getElementById('signature-warning');
    const signatureGroup = document.getElementById('signature-group');
    const signatureContainer = signatureGroup ? signatureGroup.querySelector('.signature-container') : null;
    
    if (!canvas) {
        showNotification('Erreur: Zone de signature non trouvée.', 'error');
        return;
    }
    
    // Vérifier si le canvas est vide
    const isBlank = (typeof checkCanvasBlank === 'function') ? checkCanvasBlank() : true;
    
    if (isBlank) {
        if (signatureContainer) {
            signatureContainer.style.borderColor = '#dc2626';
        }
        if (signatureWarning) {
            signatureWarning.style.display = 'block';
        }
        if (signatureStatus) {
            signatureStatus.innerHTML = '<i class="fas fa-exclamation-triangle" style="margin-right: 5px; color: #dc2626;"></i> Signature requise - Cliquez pour signer';
        }
        if (signatureGroup) {
            signatureGroup.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        
        showNotification('⚠️ La signature est obligatoire pour créer le colis.', 'error');
        return;
    }
    
    // Signature valide
    if (signatureContainer) {
        signatureContainer.style.borderColor = '#22c55e';
    }
    if (signatureWarning) {
        signatureWarning.style.display = 'none';
    }
    if (signatureStatus) {
        signatureStatus.innerHTML = '<i class="fas fa-check-circle" style="margin-right: 5px; color: #22c55e;"></i> Signature enregistrée';
    }
    
    // Capturer la signature
    const signatureData = canvas.toDataURL('image/png');
    document.getElementById('signature_data').value = signatureData;
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Création...';
    submitBtn.disabled = true;
    
    fetch('creer_colis.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': csrfToken
        }
    })
    .then(response => {
        console.log('📥 Réponse reçue, status:', response.status);
        
        if (!response.ok) {
            throw new Error('Erreur réseau: ' + response.status + ' ' + response.statusText);
        }
        
        // Récupérer le texte brut d'abord pour le débogage
        return response.text().then(text => {
            console.log('📥 Réponse texte brute:', text.substring(0, 200) + '...');
            
            // Essayer de parser en JSON
            try {
                return JSON.parse(text);
            } catch (e) {
                // Si le parsing échoue, c'est probablement du HTML (erreur PHP)
                console.error('❌ Erreur parsing JSON:', e);
                console.error('📥 Réponse reçue (premiers 500 caractères):', text.substring(0, 500));
                
                // Afficher un message d'erreur plus informatif
                if (text.includes('syntax error') || text.includes('Parse error')) {
                    throw new Error('Erreur de syntaxe PHP dans le code');
                } else if (text.includes('Fatal error') || text.includes('Warning') || text.includes('Notice')) {
                    throw new Error('Erreur PHP: voir la console pour les détails');
                } else if (text.includes('Access denied') || text.includes('Veuillez vous connecter')) {
                    throw new Error('Session expirée ou non connecté');
                } else {
                    throw new Error('Le serveur a retourné une erreur. Regardez la console pour plus de détails.');
                }
            }
        });
    })
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            
            if (data.pickup_code) {
                setTimeout(() => {
                    showNotification('Code de retrait: ' + data.pickup_code, 'info');
                }, 2000);
            }
            
            setTimeout(() => {
                loadPage(data.redirect, 'Mes Colis');
            }, 2500);
        } else {
            showNotification(data.message, 'error');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    })
    .catch(error => {
        console.error('❌ Erreur complète:', error);
        showNotification('Erreur: ' + error.message, 'error');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
});

// Mettre le focus sur le champ référence
if (refInput) {
    refInput.focus();
}

console.log('%c🚀 Gestion_Colis - Création de Colis SPA avec Signature CORRIGÉ', 'color: #00B4D8; font-size: 16px; font-weight: bold;');
</script>

</div> <!-- Fin #page-content -->
