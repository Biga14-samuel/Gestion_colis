<?php
/**
 * Module Paiements - Mobile Money (Orange Money & MTN MoMo) + Stripe (optionnel)
 * Gestion des paiements pour les frais de livraison et autres services
 */

require_once __DIR__ . '/utils/session.php';
SessionManager::start();
require_once 'config/database.php';
require_once 'config/mobile_money.php';
require_once 'config/stripe.php';
require_once 'utils/mobile_money_helper.php';
require_once 'utils/stripe_helper.php';

$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'] ?? 0;
$message = '';
$messageType = '';

// Vérifier la connexion
if (!$user_id) {
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="access-denied">Accès refusé. Veuillez vous connecter.</div>';
    exit;
}

// Récupérer les informations de l'utilisateur
$stmt = $db->prepare("SELECT * FROM utilisateurs WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$paymentHelper = new MobileMoneyHelper($db);
$stripeHelper = new StripeHelper($db);
$mmConfig = mobile_money_config();
$stripeAvailable = $stripeHelper->isAvailable();
$userPhoneRaw = $user['telephone'] ?? '';
$userPhoneMsisdn = $paymentHelper->normalizeCameroonMsisdn($userPhoneRaw);
$userPhoneDisplay = $paymentHelper->formatCameroonMsisdn($userPhoneMsisdn ?: $userPhoneRaw);
$phoneValid = $userPhoneMsisdn !== null;

// Récupérer les paiements en attente
$stmt = $db->prepare("
    SELECT c.*, u.prenom, u.nom
    FROM colis c
    LEFT JOIN utilisateurs u ON COALESCE(c.expediteur_id, c.utilisateur_id) = u.id
    WHERE (COALESCE(c.expediteur_id, c.utilisateur_id) = ? OR c.destinataire_id = ?)
    AND c.payment_status = 'pending'
    AND c.payment_amount > 0
    ORDER BY c.date_creation DESC
    LIMIT 20
");
$stmt->execute([$user_id, $user_id]);
$pending_payments = $stmt->fetchAll();

// Récupérer l'historique des paiements
$stmt = $db->prepare("
    SELECT c.*, u.prenom, u.nom
    FROM colis c
    LEFT JOIN utilisateurs u ON COALESCE(c.expediteur_id, c.utilisateur_id) = u.id
    WHERE (COALESCE(c.expediteur_id, c.utilisateur_id) = ? OR c.destinataire_id = ?)
    AND c.payment_status IN ('paid', 'failed', 'refunded')
    ORDER BY c.paid_at DESC
    LIMIT 20
");
$stmt->execute([$user_id, $user_id]);
$payment_history = $stmt->fetchAll();

$stripeSupported = $stripeAvailable;
$stripeUnsupportedCurrencies = [];
if ($stripeAvailable && !empty($pending_payments)) {
    foreach ($pending_payments as $payment) {
        $currency = $payment['payment_currency'] ?? 'XAF';
        if (!$stripeHelper->isCurrencySupported((string) $currency)) {
            $stripeSupported = false;
            $stripeUnsupportedCurrencies[strtoupper((string) $currency)] = true;
        }
    }
}

$infoMessages = [];
if (!empty($mmConfig['simulate'])) {
    $infoMessages[] = [
        'type' => 'warning',
        'text' => 'Mode simulation Mobile Money actif. Les paiements sont validés en mode test.'
    ];
}
if (empty($mmConfig['orange']['enabled']) || empty($mmConfig['mtn']['enabled'])) {
    $infoMessages[] = [
        'type' => 'info',
        'text' => 'Orange Money / MTN MoMo nécessitent des identifiants API réels (voir variables OM_* et MOMO_*).'
    ];
}
if ($stripeAvailable && !$stripeSupported) {
    $currencies = implode(', ', array_keys($stripeUnsupportedCurrencies));
    $infoMessages[] = [
        'type' => 'info',
        'text' => "Paiement carte indisponible pour les devises: {$currencies}."
    ];
}

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_stripe_payment') {
        $colis_id = $_POST['colis_id'] ?? 0;
        if (!$stripeAvailable) {
            $message = 'Paiement carte indisponible.';
            $messageType = 'error';
        } else {
            try {
                $result = $stripeHelper->createCheckoutSession($user, $colis_id);
                if (!empty($result['success'])) {
                    if (!empty($result['url'])) {
                        header('Location: ' . $result['url']);
                        exit;
                    }
                    $message = $result['message'] ?? 'Session Stripe créée.';
                    $messageType = 'success';
                } else {
                    $message = $result['message'] ?? 'Impossible de créer le paiement carte.';
                    $messageType = 'error';
                }
            } catch (Exception $e) {
                $message = user_error_message($e, 'paiements.stripe.create', 'Erreur lors de la création du paiement carte.');
                $messageType = 'error';
            }
        }
    }
    
    if ($action === 'create_mobile_payment') {
        $colis_id = $_POST['colis_id'] ?? 0;
        $provider = $_POST['provider'] ?? '';
        
        try {
            $result = $paymentHelper->createPaymentRequest($user, $colis_id, $provider);
            
            if ($result['success']) {
                if (!empty($result['redirect'])) {
                    header('Location: ' . $result['redirect']);
                    exit;
                }

                $message = $result['message'] ?? 'Demande de paiement envoyée.';
                $messageType = 'success';
            } else {
                $message = 'Erreur: ' . ($result['message'] ?? 'Impossible de créer le paiement.');
                $messageType = 'error';
            }
        } catch (Exception $e) {
            $message = user_error_message($e, 'paiements.create', 'Erreur lors de la création du paiement.');
            $messageType = 'error';
        }
    }

    if ($action === 'check_payment') {
        $colis_id = (int) ($_POST['colis_id'] ?? 0);
        $reference = trim($_POST['payment_reference'] ?? '');
        $provider = $_POST['provider'] ?? '';

        if (!$reference && $colis_id > 0) {
            $refData = $paymentHelper->getReferenceForColis($colis_id, $user_id);
            if ($refData) {
                $reference = $refData['reference'];
                $provider = $provider ?: $refData['provider'];
            }
        }

        if (!$reference || !$provider) {
            $message = 'Référence de paiement introuvable.';
            $messageType = 'error';
        } else {
            try {
                $verify = $paymentHelper->verifyPayment($provider, $reference);
                if (!$verify['success']) {
                    $message = $verify['message'] ?? 'Paiement non confirmé.';
                    $messageType = 'error';
                } else {
                    $apply = $paymentHelper->applyPaymentStatus($reference, $provider, $verify['status'] ?? 'PENDING', $verify);
                    if (!$apply['success']) {
                        $message = $apply['message'] ?? 'Impossible de mettre à jour le paiement.';
                        $messageType = 'error';
                    } else {
                        if ($apply['status'] === 'paid') {
                            $amount = 0;
                            $currency = 'XAF';
                            if (!empty($apply['colis'])) {
                                $amount = array_sum(array_column($apply['colis'], 'payment_amount'));
                                $currency = $apply['colis'][0]['payment_currency'] ?? 'XAF';
                            }
                            $stmt = $db->prepare("
                                INSERT INTO notifications (utilisateur_id, type, titre, message, priorite, date_envoi)
                                VALUES (?, 'paiement', 'Paiement confirmé', ?, 'normal', NOW())
                            ");
                            $stmt->execute([$user_id, 'Votre paiement de ' . formatAmount($amount, $currency) . ' a été confirmé.']);

                            $message = 'Paiement confirmé. Merci !';
                            $messageType = 'success';
                        } elseif ($apply['status'] === 'pending') {
                            $message = 'Paiement en attente. Veuillez réessayer dans quelques instants.';
                            $messageType = 'info';
                        } else {
                            $message = 'Paiement échoué ou annulé.';
                            $messageType = 'error';
                        }
                    }
                }
            } catch (Exception $e) {
                $message = user_error_message($e, 'paiements.verify', 'Erreur lors de la vérification du paiement.');
                $messageType = 'error';
            }
        }
    }
    
    if ($action === 'cancel_payment') {
        $colis_id = $_POST['colis_id'] ?? 0;
        
        $stmt = $db->prepare("
            UPDATE colis 
            SET payment_status = 'cancelled',
                payment_reference = NULL,
                payment_provider = NULL
            WHERE id = ? AND COALESCE(expediteur_id, utilisateur_id) = ?
        ");
        $stmt->execute([$colis_id, $user_id]);
        
        $message = 'Paiement annulé.';
        $messageType = 'info';
    }
}

$stripeHandled = false;
if (!empty($_GET['session_id'])) {
    $sessionId = trim((string) $_GET['session_id']);
    if ($sessionId !== '' && $stripeAvailable) {
        try {
            $verify = $stripeHelper->verifyPayment($sessionId);
            if (!$verify['success']) {
                $message = $verify['message'] ?? 'Paiement carte non confirmé.';
                $messageType = 'error';
            } else {
                $apply = $stripeHelper->applyStripePayment($verify['colis_ids'] ?? [], $sessionId, $user_id, $verify);
                if (!$apply['success']) {
                    $message = $apply['message'] ?? 'Impossible de valider le paiement carte.';
                    $messageType = 'error';
                } else {
                    $message = 'Paiement carte confirmé. Merci !';
                    $messageType = 'success';
                }
            }
            $stripeHandled = true;
        } catch (Exception $e) {
            $message = user_error_message($e, 'paiements.stripe.verify', 'Erreur lors de la vérification du paiement carte.');
            $messageType = 'error';
            $stripeHandled = true;
        }
    }
}

// Gérer le retour de paiement (Orange Money / MTN MoMo)
if (
    !$stripeHandled &&
    (
        (isset($_GET['payment']) && $_GET['payment'] === 'success') ||
        (isset($_GET['success']) && $_GET['success'] === 'true')
    )
) {
    $provider = $_GET['provider'] ?? '';
    $reference = $_GET['reference'] ?? ($_GET['order_id'] ?? ($_GET['transaction_id'] ?? ''));

    if ($provider && $reference) {
        try {
            $verify = $paymentHelper->verifyPayment($provider, $reference);
            if ($verify['success']) {
                $apply = $paymentHelper->applyPaymentStatus($reference, $provider, $verify['status'] ?? 'PENDING', $verify);
                if ($apply['success'] && $apply['status'] === 'paid') {
                    $amount = 0;
                    $currency = 'XAF';
                    if (!empty($apply['colis'])) {
                        $amount = array_sum(array_column($apply['colis'], 'payment_amount'));
                        $currency = $apply['colis'][0]['payment_currency'] ?? 'XAF';
                    }
                    $stmt = $db->prepare("
                        INSERT INTO notifications (utilisateur_id, type, titre, message, priorite, date_envoi)
                        VALUES (?, 'paiement', 'Paiement confirmé', ?, 'normal', NOW())
                    ");
                    $stmt->execute([$user_id, 'Votre paiement de ' . formatAmount($amount, $currency) . ' a été confirmé.']);

                    $message = 'Paiement réussi ! Votre colis va être traité.';
                    $messageType = 'success';
                } elseif ($apply['success'] && $apply['status'] === 'pending') {
                    $message = 'Paiement en attente de confirmation.';
                    $messageType = 'info';
                } else {
                    $message = 'Paiement non confirmé.';
                    $messageType = 'error';
                }
            } else {
                $message = $verify['message'] ?? 'Paiement non confirmé.';
                $messageType = 'error';
            }
        } catch (Exception $e) {
            $message = user_error_message($e, 'paiements.verify', 'Erreur lors de la vérification du paiement.');
            $messageType = 'error';
        }
    }
}

if (isset($_GET['cancel'])) {
    $message = 'Paiement annulé. Vous pouvez réessayer plus tard.';
    $messageType = 'info';
}

// Formater le montant
function formatAmount($amount, $currency = 'XAF') {
    $label = strtoupper(trim((string) $currency));
    if ($label === '' || $label === 'XAF') {
        $label = 'FCFA';
    }
    return number_format($amount, 2, ',', ' ') . ' ' . $label;
}

// Obtenir le label du statut de paiement
$paymentStatusLabels = [
    'pending' => 'En attente',
    'paid' => 'Payé',
    'failed' => 'Échoué',
    'refunded' => 'Remboursé',
    'cancelled' => 'Annulé'
];

$paymentStatusColors = [
    'pending' => 'warning',
    'paid' => 'success',
    'failed' => 'danger',
    'refunded' => 'info',
    'cancelled' => 'secondary'
];
?>

<div id="page-content">

<div class="page-container">
    <div class="page-header">
        <div class="header-left">
            <button class="btn btn-back" onclick="loadPage('dashboard.php', 'Dashboard')">
                <i class="fas fa-arrow-left"></i>
            </button>
            <h1>
                <i class="fas fa-credit-card" style="color: #00B4D8;"></i> 
                Paiements
            </h1>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?>">
            <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'exclamation-triangle' : 'info-circle') ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php foreach ($infoMessages as $info): ?>
        <div class="alert alert-<?= htmlspecialchars($info['type']) ?>">
            <i class="fas fa-<?= $info['type'] === 'warning' ? 'exclamation-triangle' : 'info-circle' ?>"></i>
            <?= htmlspecialchars($info['text']) ?>
        </div>
    <?php endforeach; ?>

    <?php if (!$phoneValid): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i>
            Ajoutez un numéro de téléphone camerounais valide pour utiliser Orange Money ou MTN MoMo.
            <a href="#" onclick="loadPage('settings.php', 'Paramètres')">Mettre à jour</a>
        </div>
    <?php else: ?>
        <div class="alert alert-info">
            <i class="fas fa-mobile-alt"></i>
            Numéro utilisé pour le paiement: <?= htmlspecialchars($userPhoneDisplay ?: $userPhoneRaw) ?>
        </div>
    <?php endif; ?>

    <!-- Solde et methods de paiement -->
    <div class="payment-overview">
        <div class="payment-balance">
            <div class="balance-card">
                <div class="balance-icon">
                    <i class="fas fa-wallet"></i>
                </div>
                <div class="balance-info">
                    <span class="balance-label">Solde à payer</span>
                    <span class="balance-amount">
                        <?php 
                        $totalPending = array_sum(array_column($pending_payments, 'payment_amount'));
                        echo formatAmount($totalPending);
                        ?>
                    </span>
                </div>
            </div>
        </div>
        
        <div class="payment-methods-card">
            <h4><i class="fas fa-mobile-alt"></i> Moyens de paiement</h4>
            <div class="methods-list">
                <div class="method-item active">
                    <i class="fas fa-mobile-alt"></i>
                    <span>Orange Money</span>
                </div>
                <div class="method-item">
                    <i class="fas fa-signal"></i>
                    <span>MTN MoMo</span>
                </div>
                <?php if ($stripeAvailable): ?>
                    <div class="method-item <?= $stripeSupported ? '' : 'disabled' ?>">
                        <i class="fas fa-credit-card"></i>
                        <span>Carte (Stripe)</span>
                    </div>
                <?php endif; ?>
                <div class="method-item">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Espèces (au livreur)</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Paiements en attente -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-clock"></i> Paiements en attente</h3>
            <span class="badge badge-warning"><?= count($pending_payments) ?></span>
        </div>
        <div class="card-body">
            <?php if (empty($pending_payments)): ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle fa-3x"></i>
                    <h3>Aucun paiement en attente</h3>
                    <p>Tous vos frais ont été réglés.</p>
                </div>
            <?php else: ?>
                <div class="payments-list">
                    <?php foreach ($pending_payments as $payment): ?>
                        <div class="payment-item">
                            <div class="payment-info">
                                <div class="payment-tracking">
                                    <span class="tracking-label">Colis</span>
                                    <span class="tracking-code"><?= htmlspecialchars($payment['code_tracking'] ?? 'N/A') ?></span>
                                </div>
                                <div class="payment-details">
                                    <span class="detail-item">
                                        <i class="fas fa-box"></i>
                                        <?= htmlspecialchars($payment['description'] ?? 'Colis') ?>
                                    </span>
                                    <span class="detail-item">
                                        <i class="fas fa-calendar"></i>
                                        <?= date('d/m/Y', strtotime($payment['date_creation'])) ?>
                                    </span>
                                    <?php if (!empty($payment['payment_provider'])): ?>
                                        <span class="detail-item">
                                            <?php
                                                $providerLabel = 'MTN MoMo';
                                                $providerIcon = 'mobile-alt';
                                                if ($payment['payment_provider'] === 'orange') {
                                                    $providerLabel = 'Orange Money';
                                                    $providerIcon = 'mobile-alt';
                                                } elseif ($payment['payment_provider'] === 'stripe') {
                                                    $providerLabel = 'Carte (Stripe)';
                                                    $providerIcon = 'credit-card';
                                                }
                                            ?>
                                            <i class="fas fa-<?= $providerIcon ?>"></i>
                                            <?= $providerLabel ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($payment['payment_reference'])): ?>
                                        <span class="detail-item">
                                            <i class="fas fa-hashtag"></i>
                                            <?= htmlspecialchars(substr($payment['payment_reference'], 0, 16)) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="payment-amount">
                                <span class="amount"><?= formatAmount($payment['payment_amount'], $payment['payment_currency']) ?></span>
                                <span class="badge badge-<?= $paymentStatusColors[$payment['payment_status']] ?>">
                                    <?= $paymentStatusLabels[$payment['payment_status']] ?>
                                </span>
                            </div>
                            <div class="payment-actions">
                                <form method="POST">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="create_mobile_payment">
                                    <input type="hidden" name="provider" value="orange">
                                    <input type="hidden" name="colis_id" value="<?= $payment['id'] ?>">
                                    <button type="submit" class="btn btn-primary" <?= $phoneValid ? '' : 'disabled' ?>>
                                        <i class="fas fa-mobile-alt"></i>
                                        Orange Money
                                    </button>
                                </form>
                                <form method="POST" style="display: inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="create_mobile_payment">
                                    <input type="hidden" name="provider" value="mtn">
                                    <input type="hidden" name="colis_id" value="<?= $payment['id'] ?>">
                                    <button type="submit" class="btn btn-primary" <?= $phoneValid ? '' : 'disabled' ?>>
                                        <i class="fas fa-signal"></i>
                                        MTN MoMo
                                    </button>
                                </form>
                                <?php if ($stripeAvailable): ?>
                                    <form method="POST" style="display: inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="create_stripe_payment">
                                        <input type="hidden" name="colis_id" value="<?= $payment['id'] ?>">
                                        <button type="submit" class="btn btn-primary" <?= $stripeSupported ? '' : 'disabled' ?>>
                                            <i class="fas fa-credit-card"></i>
                                            Carte
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if (!empty($payment['payment_reference'])): ?>
                                    <form method="POST" style="display: inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="check_payment">
                                        <input type="hidden" name="provider" value="<?= htmlspecialchars($payment['payment_provider'] ?? '') ?>">
                                        <input type="hidden" name="payment_reference" value="<?= htmlspecialchars($payment['payment_reference']) ?>">
                                        <input type="hidden" name="colis_id" value="<?= $payment['id'] ?>">
                                        <button type="submit" class="btn btn-secondary" title="Vérifier le paiement">
                                            <i class="fas fa-sync-alt"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST" style="display: inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="cancel_payment">
                                    <input type="hidden" name="colis_id" value="<?= $payment['id'] ?>">
                                    <button type="submit" class="btn btn-secondary" 
                                            onclick="return confirm('Annuler ce paiement ?')">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if (count($pending_payments) > 1): ?>
                    <div class="payment-total">
                        <span>Total à payer:</span>
                        <span class="total-amount"><?= formatAmount($totalPending) ?></span>
                        <form method="POST" class="mt-2">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="create_mobile_payment">
                            <input type="hidden" name="colis_id" value="all">
                            <button type="submit" class="btn btn-primary btn-lg" name="provider" value="orange" <?= $phoneValid ? '' : 'disabled' ?>>
                                <i class="fas fa-mobile-alt"></i>
                                Tout payer via Orange Money
                            </button>
                            <button type="submit" class="btn btn-primary btn-lg" name="provider" value="mtn" <?= $phoneValid ? '' : 'disabled' ?>>
                                <i class="fas fa-signal"></i>
                                Tout payer via MTN MoMo
                            </button>
                        </form>
                        <?php if ($stripeAvailable): ?>
                            <form method="POST" class="mt-2">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="create_stripe_payment">
                                <input type="hidden" name="colis_id" value="all">
                                <button type="submit" class="btn btn-primary btn-lg" <?= $stripeSupported ? '' : 'disabled' ?>>
                                    <i class="fas fa-credit-card"></i>
                                    Tout payer par carte
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Historique des paiements -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-history"></i> Historique des paiements</h3>
        </div>
        <div class="card-body">
            <?php if (empty($payment_history)): ?>
                <div class="empty-state small">
                    <i class="fas fa-receipt fa-2x"></i>
                    <p>Aucun paiement effectué</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Colis</th>
                                <th>Montant</th>
                                <th>Statut</th>
                                <th>Reçu</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payment_history as $payment): ?>
                                <tr>
                                    <td><?= $payment['paid_at'] ? date('d/m/Y H:i', strtotime($payment['paid_at'])) : '-' ?></td>
                                    <td><?= htmlspecialchars($payment['description'] ?? 'Paiement') ?></td>
                                    <td>
                                        <code><?= htmlspecialchars(substr($payment['code_tracking'] ?? 'N/A', 0, 12)) ?></code>
                                    </td>
                                    <td class="amount-cell"><?= formatAmount($payment['payment_amount'], $payment['payment_currency']) ?></td>
                                    <td>
                                        <span class="badge badge-<?= $paymentStatusColors[$payment['payment_status']] ?>">
                                            <?= $paymentStatusLabels[$payment['payment_status']] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($payment['payment_status'] === 'paid'): ?>
                                            <button class="btn btn-sm btn-secondary" 
                                                    onclick="downloadReceipt(<?= $payment['id'] ?>)">
                                                <i class="fas fa-file-pdf"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Informations de sécurité -->
    <div class="card mt-4">
        <div class="card-header">
            <h3><i class="fas fa-shield-alt"></i> Sécurité des paiements</h3>
        </div>
        <div class="card-body">
            <div class="security-info">
                <div class="security-item">
                    <i class="fas fa-lock"></i>
                    <div>
                        <h4>Paiements sécurisés</h4>
                        <p>Les paiements sont traités via Orange Money ou MTN MoMo, avec validation sur votre téléphone.</p>
                    </div>
                </div>
                <div class="security-item">
                    <i class="fas fa-user-secret"></i>
                    <div>
                        <h4>Données protégées</h4>
                        <p>Aucune donnée bancaire n'est stockée sur nos serveurs. Seuls les identifiants de transaction sont conservés.</p>
                    </div>
                </div>
                <div class="security-item">
                    <i class="fas fa-undo"></i>
                    <div>
                        <h4>Remboursement</h4>
                        <p>Contactez le support pour toute demande de remboursement.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function downloadReceipt(paymentId) {
    window.open(`receipt.php?payment_id=${paymentId}`, '_blank');
}

console.log('%c🚀 Gestion_Colis - Paiements SPA', 'color: #00B4D8; font-size: 16px; font-weight: bold;');
</script>

<style>
.payment-overview {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.balance-card {
    background: linear-gradient(135deg, rgba(0, 180, 216, 0.1), rgba(0, 168, 255, 0.1));
    border: 1px solid rgba(0, 180, 216, 0.3);
    border-radius: 16px;
    padding: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1.5rem;
}

.balance-icon {
    width: 64px;
    height: 64px;
    background: rgba(0, 180, 216, 0.2);
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: #00B4D8;
}

.balance-label {
    display: block;
    color: var(--text-secondary);
    font-size: 0.9rem;
    margin-bottom: 0.25rem;
}

.balance-amount {
    display: block;
    font-size: 2rem;
    font-weight: 700;
    color: var(--text-primary);
}

.payment-methods-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 1.5rem;
}

.payment-methods-card h4 {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 1rem;
    color: var(--text-primary);
}

.methods-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.method-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem;
    background: rgba(0, 0, 0, 0.02);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    color: var(--text-secondary);
}

.method-item.active {
    border-color: #00B4D8;
    background: rgba(0, 180, 216, 0.05);
    color: #00B4D8;
}

.method-item.disabled {
    opacity: 0.55;
    border-style: dashed;
}

.method-item i {
    font-size: 1.2rem;
    width: 24px;
}

.payments-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.payment-item {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    padding: 1rem;
    background: rgba(0, 0, 0, 0.02);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    flex-wrap: wrap;
}

.payment-info {
    flex: 1;
    min-width: 200px;
}

.payment-tracking {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
}

.tracking-label {
    font-size: 0.75rem;
    color: var(--text-muted);
}

.tracking-code {
    font-family: 'Courier New', monospace;
    font-weight: 600;
    color: #00B4D8;
}

.payment-details {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
    font-size: 0.85rem;
    color: var(--text-secondary);
}

.detail-item {
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.detail-item i {
    color: #00B4D8;
}

.payment-amount {
    text-align: right;
}

.amount {
    display: block;
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 0.25rem;
}

.payment-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.payment-total {
    margin-top: 2rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--border-color);
    text-align: right;
}

.payment-total span {
    color: var(--text-secondary);
    font-size: 1rem;
}

.total-amount {
    display: block;
    font-size: 2rem;
    font-weight: 700;
    color: #00B4D8;
    margin-top: 0.5rem;
}

.amount-cell {
    font-weight: 600;
    color: #00B4D8;
}

.security-info {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
}

.security-item {
    display: flex;
    gap: 1rem;
    padding: 1rem;
    background: rgba(0, 0, 0, 0.02);
    border-radius: 8px;
    border: 1px solid var(--border-color);
}

.security-item i {
    font-size: 1.5rem;
    color: #00B4D8;
    flex-shrink: 0;
}

.security-item h4 {
    color: var(--text-primary);
    margin: 0 0 0.5rem 0;
    font-size: 1rem;
}

.security-item p {
    color: var(--text-secondary);
    font-size: 0.9rem;
    margin: 0;
}

@media (max-width: 768px) {
    .payment-item {
        flex-direction: column;
        align-items: stretch;
    }
    
    .payment-amount {
        text-align: left;
    }
    
    .payment-actions {
        justify-content: flex-end;
    }
}
</style>

</div> <!-- Fin #page-content -->
