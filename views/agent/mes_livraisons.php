<?php
// Verification de la connexion et du role agent
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['agent', 'admin'])) {
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="access-denied">Accès refusé. Vous devez être agent ou administrateur pour accéder à cette page.</div>';
    exit;
}

require_once '../../config/database.php';

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'];
$message = '';
$messageType = '';

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        switch ($action) {
            case 'update_status':
                $livraisonId = (int)($_POST['livraison_id'] ?? 0);
                $colisId = (int)($_POST['colis_id'] ?? 0);
                $newStatus = $_POST['statut'] ?? '';
                $commentaire = trim($_POST['commentaire'] ?? '');
                
                // Verifier que la livraison appartient bien a cet agent (sauf pour admin)
                if ($userRole !== 'admin') {
                    $stmt = $pdo->prepare("SELECT l.id FROM livraisons l WHERE l.id = ? AND l.agent_id = (SELECT id FROM agents WHERE utilisateur_id = ?)");
                    $stmt->execute([$livraisonId, $userId]);
                    if (!$stmt->fetch()) {
                        $message = 'Vous n\'êtes pas autorisé à modifier cette livraison.';
                        $messageType = 'error';
                        break;
                    }
                }
                
                if ($livraisonId <= 0 || $colisId <= 0 || empty($newStatus)) {
                    $message = 'Données invalides.';
                    $messageType = 'error';
                } else {
                    $pdo->beginTransaction();
                    
                    // Récupérer les informations du colis avant mise à jour
                    $stmt = $pdo->prepare("SELECT c.*, i.localisation, i.code_box FROM colis c LEFT JOIN ibox i ON c.ibox_id = i.id WHERE c.id = ?");
                    $stmt->execute([$colisId]);
                    $colisInfo = $stmt->fetch();
                    
                    // Mettre a jour le statut de la livraison
                    $stmt = $pdo->prepare("UPDATE livraisons SET statut = ? WHERE id = ?");
                    $stmt->execute([$newStatus, $livraisonId]);
                    
                    // Mettre a jour le statut du colis en fonction du statut de la livraison
                    $colisStatus = match($newStatus) {
                        'terminee' => 'livre',
                        'annulee' => 'retourne',
                        'en_cours' => 'en_livraison',
                        default => 'en_attente'
                    };
                    $stmt = $pdo->prepare("UPDATE colis SET statut = ?, date_mise_a_jour = NOW() WHERE id = ?");
                    $stmt->execute([$colisStatus, $colisId]);
                    
                    // Si la livraison est terminee, enregistrer la date et gérer la livraison iBox
                    if ($newStatus === 'terminee') {
                        $stmt = $pdo->prepare("UPDATE livraisons SET date_fin = NOW() WHERE id = ?");
                        $stmt->execute([$livraisonId]);
                        $stmt = $pdo->prepare("UPDATE colis SET date_livraison = NOW() WHERE id = ?");
                        $stmt->execute([$colisId]);
                        
                        // Si le colis est destiné à une iBox, générer le code de retrait et notifier l'utilisateur
                        if (!empty($colisInfo['ibox_id'])) {
                            // Générer le code de retrait
                            require_once '../../utils/pickup_code_service.php';
                            $pickupService = new PickupCodeService();
                            $pickupResult = $pickupService->generateCode($colisId, 'pin', false);
                            
                            // Mettre à jour le statut de l'iBox à "recu" (contenant un colis prêt au retrait)
                            $stmt = $pdo->prepare("UPDATE ibox SET statut = 'recu' WHERE id = ?");
                            $stmt->execute([$colisInfo['ibox_id']]);
                        }
                    }
                    
                    // Si la livraison commence, enregistrer la date de debut
                    if ($newStatus === 'en_cours') {
                        $stmt = $pdo->prepare("UPDATE livraisons SET date_debut = NOW() WHERE id = ?");
                        $stmt->execute([$livraisonId]);
                    }
                    
                    // Ajouter une notification à l'utilisateur
                    $stmt = $pdo->prepare("SELECT utilisateur_id FROM colis WHERE id = ?");
                    $stmt->execute([$colisId]);
                    $colisOwner = $stmt->fetch();
                    if ($colisOwner) {
                        // Personnaliser le message selon le type de livraison
                        if ($newStatus === 'terminee' && !empty($colisInfo['ibox_id'])) {
                            $notifMessage = "Votre colis a été déposé dans l'iBox " . $colisInfo['code_box'] . " (" . $colisInfo['localisation'] . "). Un code de retrait vous a été envoyé.";
                        } elseif ($newStatus === 'terminee') {
                            $notifMessage = 'Votre colis a été livré avec succès !';
                        } elseif ($newStatus === 'en_cours') {
                            $notifMessage = 'Votre colis est en cours de livraison.';
                        } elseif ($newStatus === 'annulee') {
                            $notifMessage = 'La livraison de votre colis a été annulée.';
                        } else {
                            $notifMessage = 'Le statut de votre colis a été mis à jour.';
                        }
                        
                        $stmt = $pdo->prepare("INSERT INTO notifications (utilisateur_id, type, titre, message, priorite, date_envoi) VALUES (?, 'livraison', 'Mise à jour livraison', ?, 'high', NOW())");
                        $stmt->execute([$colisOwner['utilisateur_id'], $notifMessage]);
                    }
                    
                    $pdo->commit();
                    $message = 'Statut mis à jour avec succès.';
                    $messageType = 'success';
                }
                break;
                
            case 'scan_livraison':
                $trackingNumber = trim($_POST['tracking_number'] ?? '');
                
                if (empty($trackingNumber)) {
                    $message = 'Veuillez entrer un numéro de suivi.';
                    $messageType = 'error';
                } else {
                    $agentId = 0;
                    if ($userRole !== 'admin') {
                        $stmt = $pdo->prepare("SELECT id FROM agents WHERE utilisateur_id = ?");
                        $stmt->execute([$userId]);
                        $agentData = $stmt->fetch();
                        $agentId = $agentData['id'] ?? 0;
                    }
                    
                    $stmt = $pdo->prepare("
                        SELECT c.id, c.statut, c.reference_colis, c.description, l.id as livraison_id
                        FROM colis c
                        JOIN livraisons l ON c.id = l.colis_id
                        WHERE c.reference_colis = ? AND (l.statut IN ('assignee', 'en_cours') OR ? = 1)
                    ");
                    $stmt->execute([$trackingNumber, $userRole === 'admin' ? 1 : 0]);
                    $colis = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$colis) {
                        $message = 'Colis non trouvé ou non attribué à vous.';
                        $messageType = 'error';
                    } else {
                        echo "<script>openDeliveryDetails({$colis['id']}, {$colis['livraison_id']});</script>";
                        $colisData = $colis;
                    }
                }
                break;
        }
    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = user_error_message($e, 'agent_livraisons.action', 'Erreur de base de données.');
        $messageType = 'error';
    }
}

// Recuperation des livraisons de l'agent
try {
    $pdo = new PDO(DB_DSN, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Pour admin, voir tous les colis. Pour agent, voir uniquement les siens
    if ($userRole === 'admin') {
        $stmt = $pdo->query("
            SELECT c.*, l.id as livraison_id, l.statut as livraison_statut, l.date_assignation,
                   ua.nom as agent_nom, ua.prenom as agent_prenom,
                   (SELECT COUNT(*) FROM historique_colis h WHERE h.colis_id = c.id) as nb_etapes
            FROM colis c
            LEFT JOIN livraisons l ON c.id = l.colis_id
            LEFT JOIN agents a ON l.agent_id = a.id
            LEFT JOIN utilisateurs ua ON a.utilisateur_id = ua.id
            ORDER BY c.date_creation DESC
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT c.*, l.id as livraison_id, l.statut as livraison_statut, l.date_assignation,
                   ua.nom as agent_nom, ua.prenom as agent_prenom,
                   (SELECT COUNT(*) FROM historique_colis h WHERE h.colis_id = c.id) as nb_etapes
            FROM colis c
            JOIN livraisons l ON c.id = l.colis_id
            JOIN agents a ON l.agent_id = a.id
            JOIN utilisateurs ua ON a.utilisateur_id = ua.id
            WHERE a.utilisateur_id = ?
            ORDER BY c.date_creation DESC
        ");
        $stmt->execute([$userId]);
    }
    
    $livraisons = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recuperer les statistiques
    if ($userRole === 'admin') {
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN l.statut = 'terminee' THEN 1 ELSE 0 END) as livres,
                SUM(CASE WHEN l.statut IN ('en_cours', 'assignee') THEN 1 ELSE 0 END) as en_cours,
                SUM(CASE WHEN l.statut = 'annulee' THEN 1 ELSE 0 END) as annules
            FROM livraisons l
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN l.statut = 'terminee' THEN 1 ELSE 0 END) as livres,
                SUM(CASE WHEN l.statut IN ('en_cours', 'assignee') THEN 1 ELSE 0 END) as en_cours,
                SUM(CASE WHEN l.statut = 'annulee' THEN 1 ELSE 0 END) as annules
            FROM livraisons l
            JOIN agents a ON l.agent_id = a.id
            WHERE a.utilisateur_id = ?
        ");
        $stmt->execute([$userId]);
    }
    
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $livraisons = [];
    $stats = ['total' => 0, 'livres' => 0, 'en_cours' => 0, 'annules' => 0];
    $message = user_error_message($e, 'agent_livraisons.fetch', 'Erreur lors de la récupération des données.');
    $messageType = 'error';
}

// Recuperer les details d'un colis specifique si demande
$colisDetails = null;
if (isset($_GET['colis_id'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT c.*,
                   u.nom as agent_nom, u.prenom as agent_prenom,
                   exp.nom as expediteur_nom, exp.adresse as expediteur_adresse,
                   dest.nom as destinataire_nom, dest.adresse as destinataire_adresse,
                   l.id as livraison_id, l.statut as livraison_statut
            FROM colis c
            LEFT JOIN utilisateurs exp ON c.expediteur_id = exp.id
            LEFT JOIN utilisateurs dest ON c.destinataire_id = dest.id
            LEFT JOIN livraisons l ON c.id = l.colis_id
            LEFT JOIN utilisateurs u ON l.agent_id = u.id
            WHERE c.id = ?
        ");
        $stmt->execute([(int)$_GET['colis_id']]);
        $colisDetails = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($colisDetails) {
            $stmt = $pdo->prepare("
                SELECT h.*, u.prenom, u.nom
                FROM historique_colis h
                LEFT JOIN utilisateurs u ON h.utilisateur_id = u.id
                WHERE h.colis_id = ?
                ORDER BY h.date_action DESC
            ");
            $stmt->execute([(int)$_GET['colis_id']]);
            $colisDetails['historique'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        $colisDetails = null;
    }
}
?>

<!-- Contenu pour le système SPA -->
<div id="page-content">

<div class="page-container">
    <div class="page-header">
        <h1><i class="fas fa-truck-loading"></i> Mes Livraisons</h1>
        <div class="header-actions">
            <div class="quick-scan">
                <input type="text" id="scanInput" placeholder="Scanner un colis (numéro de suivi)..." onkeypress="handleScanKeypress(event)">
                <button class="btn btn-primary" onclick="scanColis()">
                    <i class="fas fa-qrcode"></i> Scanner
                </button>
            </div>
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
                <i class="fas fa-box"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?= $stats['total'] ?? 0 ?></span>
                <span class="stat-label">Total Colis</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-success">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?= $stats['livres'] ?? 0 ?></span>
                <span class="stat-label">Livrés</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-warning">
                <i class="fas fa-truck"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?= $stats['en_cours'] ?? 0 ?></span>
                <span class="stat-label">En Cours</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-danger">
                <i class="fas fa-times-circle"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?= $stats['annules'] ?? 0 ?></span>
                <span class="stat-label">Annulés</span>
            </div>
        </div>
    </div>

    <!-- Filtres -->
    <div class="filters-bar">
        <div class="filter-group">
            <label>Statut:</label>
            <select id="filterStatut" onchange="filterLivraisons()">
                <option value="all">Tous</option>
                <option value="preparation">En préparation</option>
                <option value="en_livraison">En livraison</option>
                <option value="livre">Livré</option>
                <option value="annule">Annulé</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Date:</label>
            <input type="date" id="filterDate" onchange="filterLivraisons()">
        </div>
        <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" id="searchLivraisons" placeholder="Rechercher..." onkeyup="filterLivraisons()">
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-list"></i> Liste des Livraisons</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="data-table" id="livraisonsTable">
                    <thead>
                        <tr>
                            <th>N° Suivi</th>
                            <th>Destinataire</th>
                            <th>Adresse</th>
                            <th>Date Creation</th>
                            <th>Statut</th>
                            <th>Progression</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($livraisons)): ?>
                            <tr>
                                <td colspan="7" class="text-center">Aucune livraison trouvée.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($livraisons as $livraison): ?>
                                <tr data-statut="<?= $livraison['statut'] ?>" 
                                    data-date="<?= date('Y-m-d', strtotime($livraison['date_creation'])) ?>"
                                    data-tracking="<?= strtolower($livraison['reference_colis'] ?? '') ?>"
                                    data-livraison-id="<?= $livraison['livraison_id'] ?? 0 ?>"
                                    data-search="<?= strtolower(($livraison['nom_destinataire'] ?? $livraison['description'] ?? '') . ' ' . ($livraison['adresse_livraison'] ?? '') . ' ' . ($livraison['reference_colis'] ?? '')) ?>">
                                    <td>
                                        <span class="tracking-number"><?= htmlspecialchars($livraison['reference_colis'] ?? $livraison['code_tracking'] ?? 'N/A') ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($livraison['nom_destinataire'] ?? $livraison['description'] ?? 'Non spécifié') ?></td>
                                    <td>
                                        <span class="address-text" title="<?= htmlspecialchars($livraison['adresse_livraison'] ?? '') ?>">
                                            <?= htmlspecialchars(substr($livraison['adresse_livraison'] ?? 'Adresse non spécifiée', 0, 40)) ?>
                                            <?= strlen($livraison['adresse_livraison'] ?? '') > 40 ? '...' : '' ?>
                                        </span>
                                    </td>
                                    <td><?= date('d/m/Y H:i', strtotime($livraison['date_creation'])) ?></td>
                                    <td>
                                        <?php
                                        $statusClasses = [
                                            'preparation' => 'info',
                                            'en_livraison' => 'warning',
                                            'livre' => 'success',
                                            'annule' => 'danger',
                                            'retour' => 'secondary'
                                        ];
                                        $statusLabels = [
                                            'preparation' => 'En préparation',
                                            'en_livraison' => 'En livraison',
                                            'livre' => 'Livré',
                                            'annule' => 'Annulé',
                                            'retour' => 'En retour'
                                        ];
                                        ?>
                                        <span class="badge badge-<?= $statusClasses[$livraison['statut']] ?? 'secondary' ?>">
                                            <?= htmlspecialchars($statusLabels[$livraison['statut']] ?? ucfirst($livraison['statut'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="progress-bar">
                                            <?php
                                            $progress = 0;
                                            if ($livraison['statut'] === 'preparation') $progress = 25;
                                            elseif ($livraison['statut'] === 'en_livraison') $progress = 75;
                                            elseif ($livraison['statut'] === 'livre') $progress = 100;
                                            elseif ($livraison['statut'] === 'annule') $progress = 0;
                                            ?>
                                            <div class="progress-fill" style="width: <?= $progress ?>%"></div>
                                        </div>
                                    </td>
                                    <td class="actions">
                                        <button class="btn-icon btn-view" onclick="openDeliveryDetails(<?= $livraison['id'] ?>)" title="Voir les détails">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if (in_array($livraison['statut'], ['preparation', 'en_livraison'])): ?>
                                            <button class="btn-icon btn-update" onclick="openStatusModal(<?= $livraison['id'] ?>, '<?= htmlspecialchars($livraison['numero_suivi']) ?>, <?= $livraison['livraison_id'] ?? 0 ?>)" title="Mettre à jour le statut">
                                                <i class="fas fa-edit"></i>
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

<!-- Modal de mise a jour du statut -->
<div id="statusModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-truck"></i> Mettre à jour le statut</h2>
            <button class="modal-close" onclick="closeModal('statusModal')">&times;</button>
        </div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="colis_id" id="statusColisId" value="">
            <input type="hidden" name="livraison_id" id="statusLivraisonId" value="">
            
            <div class="modal-body">
                <div class="info-row">
                    <strong>Colis:</strong> <span id="statusColisNumber"></span>
                </div>
                
                <div class="form-group">
                    <label for="newStatut">Nouveau statut <span class="required">*</span></label>
                    <select id="newStatut" name="statut" required onchange="updateStatusOptions()">
                        <option value="">Sélectionnez un statut</option>
                        <option value="en_livraison">En livraison</option>
                        <option value="livre">Livré</option>
                        <option value="annule">Annulé</option>
                    </select>
                </div>
                
                <div class="form-group" id="deliveryCodeGroup" style="display: none;">
                    <label for="deliveryCode">Code de livraison</label>
                    <input type="text" id="deliveryCode" name="delivery_code" placeholder="Code_recu_du_client">
                    <small class="form-hint">Le code fourni par le client pour valider la livraison</small>
                </div>
                
                <div class="form-group">
                    <label for="commentaire">Commentaire</label>
                    <textarea id="commentaire" name="commentaire" rows="3" placeholder="Ajouter un commentaire (optionnel)..."></textarea>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('statusModal')">
                    <i class="fas fa-times"></i> Annuler
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Mettre à jour
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal des details du colis -->
<div id="deliveryDetailsModal" class="modal">
    <div class="modal-content modal-xl">
        <div class="modal-header">
            <h2><i class="fas fa-box-open"></i> Détails du Colis</h2>
            <button class="modal-close" onclick="closeModal('deliveryDetailsModal')">&times;</button>
        </div>
        <div class="modal-body" id="deliveryDetailsContent">
            <?php if ($colisDetails): ?>
                <div class="delivery-details">
                    <div class="detail-row">
                        <div class="detail-card">
                            <h4>Informations du Colis</h4>
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <span class="label">Numéro de suivi</span>
                                    <span class="value tracking-number"><?= htmlspecialchars($colisDetails['numero_suivi']) ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="label">Statut</span>
                                    <span class="value">
                                        <span class="badge badge-<?= $statusClasses[$colisDetails['statut']] ?? 'secondary' ?>">
                                            <?= htmlspecialchars($statusLabels[$colisDetails['statut']] ?? ucfirst($colisDetails['statut'])) ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="detail-item">
                                    <span class="label">Date de création</span>
                                    <span class="value"><?= date('d/m/Y H:i', strtotime($colisDetails['date_creation'])) ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="label">Poids</span>
                                    <span class="value"><?= htmlspecialchars($colisDetails['poids'] ?? 'N/A') ?> kg</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="detail-card">
                            <h4>Destinataire</h4>
                            <div class="detail-grid">
                                <div class="detail-item full-width">
                                    <span class="label">Nom</span>
                                    <span class="value"><?= htmlspecialchars($colisDetails['nom_destinataire']) ?></span>
                                </div>
                                <div class="detail-item full-width">
                                    <span class="label">Adresse</span>
                                    <span class="value"><?= htmlspecialchars($colisDetails['adresse_livraison']) ?></span>
                                </div>
                                <div class="detail-item full-width">
                                    <span class="label">Téléphone</span>
                                    <span class="value"><?= htmlspecialchars($colisDetails['telephone_destinataire'] ?? 'Non fourni') ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="detail-card">
                        <h4>Historique des Actions</h4>
                        <div class="timeline">
                            <?php if (!empty($colisDetails['historique'])): ?>
                                <?php foreach ($colisDetails['historique'] as $etape): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-marker"></div>
                                        <div class="timeline-content">
                                            <div class="timeline-header">
                                                <span class="timeline-title">
                                                    <?= htmlspecialchars($statusLabels[$etape['nouveau_statut']] ?? ucfirst($etape['nouveau_statut'])) ?>
                                                </span>
                                                <span class="timeline-date">
                                                    <?= date('d/m/Y H:i', strtotime($etape['date_action'])) ?>
                                                </span>
                                            </div>
                                            <?php if ($etape['commentaire']): ?>
                                                <p class="timeline-text"><?= htmlspecialchars($etape['commentaire']) ?></p>
                                            <?php endif; ?>
                                            <span class="timeline-user">
                                                Par: <?= htmlspecialchars($etape['prenom'] . ' ' . $etape['nom']) ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-center text-muted">Aucun historique disponible.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="text-center text-muted">
                    <i class="fas fa-info-circle fa-3x"></i>
                    <p class="mt-3">Sélectionnez un colis pour voir ses détails.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($colisDetails && in_array($colisDetails['statut'], ['preparation', 'en_livraison'])): ?>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('deliveryDetailsModal'); openStatusModal(<?= $colisDetails['id'] ?>, '<?= htmlspecialchars($colisDetails['numero_suivi']) ?>, <?= $colisDetails['livraison_id'] ?? 0 ?>)">
                    <i class="fas fa-edit"></i> Mettre à jour le statut
                </button>
                <button type="button" class="btn btn-primary" onclick="closeModal('deliveryDetailsModal')">
                    <i class="fas fa-times"></i> Fermer
                </button>
            </div>
        <?php else: ?>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="closeModal('deliveryDetailsModal')">
                    <i class="fas fa-times"></i> Fermer
                </button>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Afficher le modal des details si un colis a ete selectionne
    <?php if ($colisDetails): ?>
    document.getElementById('deliveryDetailsModal').classList.add('active');
    <?php endif; ?>
});

function handleScanKeypress(event) {
    if (event.key === 'Enter') {
        scanColis();
    }
}

function scanColis() {
    const trackingNumber = document.getElementById('scanInput').value.trim();
    if (trackingNumber) {
        // Rechercher le colis dans la table
        const rows = document.querySelectorAll('#livraisonsTable tbody tr');
        for (const row of rows) {
            if (row.dataset.search && row.dataset.search.includes(trackingNumber.toLowerCase())) {
                const colisId = row.querySelector('.btn-view').onclick.toString().match(/\d+/)[0];
                openDeliveryDetails(parseInt(colisId));
                document.getElementById('scanInput').value = '';
                return;
            }
        }
        alert('Colis non trouvé : ' + trackingNumber);
    }
}

function filterLivraisons() {
    const searchTerm = document.getElementById('searchLivraisons').value.toLowerCase();
    const statutFilter = document.getElementById('filterStatut').value;
    const dateFilter = document.getElementById('filterDate').value;
    
    const rows = document.querySelectorAll('#livraisonsTable tbody tr');
    
    rows.forEach(row => {
        let show = true;
        
        // Filtre par recherche
        if (searchTerm && !row.dataset.search.includes(searchTerm)) {
            show = false;
        }
        
        // Filtre par statut
        if (statutFilter !== 'all' && row.dataset.statut !== statutFilter) {
            show = false;
        }
        
        // Filtre par date
        if (dateFilter && row.dataset.date !== dateFilter) {
            show = false;
        }
        
        row.style.display = show ? '' : 'none';
    });
}

function openStatusModal(colisId, trackingNumber, livraisonId = 0) {
    document.getElementById('statusColisId').value = colisId;
    document.getElementById('statusLivraisonId').value = livraisonId;
    document.getElementById('statusColisNumber').textContent = trackingNumber;
    document.getElementById('newStatut').value = '';
    document.getElementById('deliveryCodeGroup').style.display = 'none';
    document.getElementById('commentaire').value = '';
    closeModal('deliveryDetailsModal');
    openModal('statusModal');
}

function updateStatusOptions() {
    const newStatut = document.getElementById('newStatut').value;
    const codeGroup = document.getElementById('deliveryCodeGroup');
    
    if (newStatut === 'livre') {
        codeGroup.style.display = 'block';
    } else {
        codeGroup.style.display = 'none';
    }
}

function openDeliveryDetails(colisId) {
    // Charger la page de détails du colis
    loadPage(`views/client/modifier_colis.php?id=${colisId}`, 'Détails Colis');
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


</div>
<!-- Fin #page-content -->
