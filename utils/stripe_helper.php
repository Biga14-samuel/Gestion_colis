<?php
/**
 * Stripe Helper - Utilitaire pour les paiements Stripe
 */

class StripeHelper {
    private string $apiKey;
    private string $webhookSecret;
    private bool $enabled;
    private bool $simulate;
    private PDO $db;
    
    public function __construct(PDO $db = null) {
        require_once __DIR__ . '/../config/stripe.php';

        $this->apiKey = defined('STRIPE_SECRET_KEY') ? (string) STRIPE_SECRET_KEY : '';
        $this->webhookSecret = defined('STRIPE_WEBHOOK_SECRET') ? (string) STRIPE_WEBHOOK_SECRET : '';
        $this->enabled = defined('STRIPE_ENABLED') ? (bool) STRIPE_ENABLED : false;
        $this->simulate = defined('STRIPE_SIMULATE') ? (bool) STRIPE_SIMULATE : false;

        if ($db instanceof PDO) {
            $this->db = $db;
        } else {
            require_once __DIR__ . '/../config/database.php';
            $database = new Database();
            $this->db = $database->getConnection();
        }
    }

    public function isAvailable(): bool {
        return $this->simulate || ($this->enabled && $this->apiKey !== '');
    }

    public function isEnabled(): bool {
        return $this->enabled && $this->apiKey !== '';
    }

    public function isCurrencySupported(string $currency): bool {
        $currency = strtoupper(trim($currency));
        return in_array($currency, ['EUR', 'USD', 'GBP'], true);
    }

    private function safeErrorMessage(Throwable $e, string $context, string $fallback): string {
        if (function_exists('user_error_message')) {
            return user_error_message($e, $context, $fallback);
        }
        error_log("[$context] " . $e->getMessage());
        return $fallback;
    }
    
    /**
     * Créer une session de paiement Stripe Checkout
     */
    public function createCheckoutSession($user, $colisId) {
        if (!$this->isAvailable()) {
            return ['success' => false, 'message' => 'Paiement carte indisponible.'];
        }
        
        // Récupérer les informations du colis
        if ($colisId === 'all') {
            $stmt = $this->db->prepare("
                SELECT * FROM colis 
                WHERE (expediteur_id = ? OR destinataire_id = ?)
                AND payment_status = 'pending'
                AND payment_amount > 0
            ");
            $stmt->execute([$user['id'], $user['id']]);
            $colisItems = $stmt->fetchAll();
            
            if (empty($colisItems)) {
                return ['success' => false, 'message' => 'Aucun paiement en attente'];
            }
            
            $lineItems = [];
            $totalAmount = 0;
            
            $currency = null;
            foreach ($colisItems as $colis) {
                $itemCurrency = strtoupper(trim((string) ($colis['payment_currency'] ?: 'EUR')));
                if (!$this->isCurrencySupported($itemCurrency)) {
                    return [
                        'success' => false,
                        'message' => "Devise non supportée pour le paiement carte: {$itemCurrency}."
                    ];
                }
                if ($currency === null) {
                    $currency = $itemCurrency;
                } elseif ($currency !== $itemCurrency) {
                    return [
                        'success' => false,
                        'message' => 'Devise multiple détectée. Le paiement carte nécessite une seule devise.'
                    ];
                }

                $lineItems[] = [
                    'price_data' => [
                        'currency' => strtolower($currency),
                        'product_data' => [
                            'name' => 'Colis: ' . ($colis['code_tracking'] ?? 'N/A'),
                            'description' => $colis['description'] ?? 'Frais de livraison'
                        ],
                        'unit_amount' => (int)($colis['payment_amount'] * 100)
                    ],
                    'quantity' => 1
                ];
                $totalAmount += $colis['payment_amount'];
            }
            
            $sessionData = [
                'payment_method_types' => ['card'],
                'line_items' => $lineItems,
                'mode' => 'payment',
                'success_url' => $this->getBaseUrl() . '/paiements.php?success=true&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $this->getBaseUrl() . '/paiements.php?cancel=true',
                'customer_email' => $user['email'],
                'metadata' => [
                    'colis_ids' => implode(',', array_column($colisItems, 'id')),
                    'user_id' => (string) $user['id']
                ]
            ];
            
        } else {
            $stmt = $this->db->prepare("SELECT * FROM colis WHERE id = ?");
            $stmt->execute([$colisId]);
            $colis = $stmt->fetch();
            
            if (!$colis) {
                return ['success' => false, 'message' => 'Colis non trouvé'];
            }

            $currency = strtoupper(trim((string) ($colis['payment_currency'] ?: 'EUR')));
            if (!$this->isCurrencySupported($currency)) {
                return [
                    'success' => false,
                    'message' => "Devise non supportée pour le paiement carte: {$currency}."
                ];
            }
            
            $sessionData = [
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower($currency),
                        'product_data' => [
                            'name' => 'Colis: ' . ($colis['code_tracking'] ?? 'N/A'),
                            'description' => $colis['description'] ?? 'Frais de livraison'
                        ],
                        'unit_amount' => (int)($colis['payment_amount'] * 100)
                    ],
                    'quantity' => 1
                ]],
                'mode' => 'payment',
                'success_url' => $this->getBaseUrl() . '/paiements.php?success=true&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $this->getBaseUrl() . '/paiements.php?cancel=true',
                'customer_email' => $user['email'],
                'metadata' => [
                    'colis_id' => (string) $colisId,
                    'user_id' => (string) $user['id']
                ]
            ];
            
            $totalAmount = $colis['payment_amount'];
        }
        
        // En mode test/sans clé API valide
        if ($this->simulate) {
            // Simuler une redirection réussie
            $mockSessionId = 'cs_test_' . uniqid();
            
            // Stocker les données pour la vérification
            $_SESSION['mock_payment'] = [
                'session_id' => $mockSessionId,
                'user_id' => $user['id'],
                'colis_id' => $colisId,
                'colis_ids' => isset($colisItems) ? array_column($colisItems, 'id') : null,
                'amount' => $totalAmount
            ];
            
            return [
                'success' => true,
                'url' => $this->getBaseUrl() . '/paiements.php?success=true&session_id=' . $mockSessionId
            ];
        }

        if (!$this->isEnabled()) {
            return ['success' => false, 'message' => 'Clé API Stripe non configurée'];
        }
        
        try {
            // Utiliser cURL pour appeler l'API Stripe
            $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/x-www-form-urlencoded'
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($sessionData));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if ($httpCode !== 200) {
                error_log('Stripe API Error: ' . $response);
                return ['success' => false, 'message' => 'Erreur Stripe: ' . json_decode($response)->error->message ?? 'Erreur inconnue'];
            }
            
            $result = json_decode($response, true);
            
            return [
                'success' => true,
                'session_id' => $result['id'],
                'url' => $result['url']
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $this->safeErrorMessage($e, 'stripe.checkout', 'Erreur lors du paiement carte.')
            ];
        }
    }
    
    /**
     * Vérifier et confirmer un paiement
     */
    public function verifyPayment($sessionId) {
        // Vérifier les paiements mock en mode test
        if (isset($_SESSION['mock_payment']) && $_SESSION['mock_payment']['session_id'] === $sessionId) {
            $mock = $_SESSION['mock_payment'];
            unset($_SESSION['mock_payment']);
            
            // Récupérer le montant
            $colisId = $mock['colis_id'];
            if ($colisId !== 'all') {
                $stmt = $this->db->prepare("SELECT payment_amount, payment_currency FROM colis WHERE id = ?");
                $stmt->execute([$colisId]);
                $colis = $stmt->fetch();
                $amount = $colis ? $colis['payment_amount'] : 0;
            } else {
                $amount = $mock['amount'];
            }
            
            return [
                'success' => true,
                'session_id' => $sessionId,
                'colis_ids' => $mock['colis_ids'] ?? ($colisId !== 'all' ? [$colisId] : []),
                'amount' => $amount,
                'currency' => 'EUR'
            ];
        }
        
        if (!$this->isEnabled()) {
            return ['success' => false, 'message' => 'Clé API Stripe non configurée'];
        }
        
        try {
            $ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . $sessionId);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->apiKey
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
            $response = curl_exec($ch);
            $result = json_decode($response, true);
            
            if (!isset($result['payment_status']) || $result['payment_status'] !== 'paid') {
                return ['success' => false, 'message' => 'Paiement non confirmé'];
            }
            
            $metadata = $result['metadata'] ?? [];
            $colisIds = [];
            if (!empty($metadata['colis_ids'])) {
                $colisIds = array_values(array_filter(array_map('intval', explode(',', (string) $metadata['colis_ids']))));
            } elseif (!empty($metadata['colis_id'])) {
                $colisIds = [(int) $metadata['colis_id']];
            }
            
            return [
                'success' => true,
                'session_id' => $sessionId,
                'colis_ids' => $colisIds,
                'amount' => $result['amount_total'] / 100,
                'currency' => strtoupper($result['currency'])
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $this->safeErrorMessage($e, 'stripe.verify', 'Erreur lors de la vérification du paiement.')
            ];
        }
    }
    
    /**
     * Gérer les webhooks Stripe
     */
    public function handleWebhook($payload, $signature) {
        if ($this->simulate) {
            return ['success' => true, 'event' => 'simulated'];
        }

        if ($this->webhookSecret === '') {
            return ['success' => false, 'message' => 'Webhook Stripe non configuré'];
        }

        try {
            $signature = (string) $signature;
            $payload = (string) $payload;

            $timestamp = null;
            $signatures = [];
            foreach (explode(',', $signature) as $part) {
                $part = trim($part);
                if (str_starts_with($part, 't=')) {
                    $timestamp = (int) substr($part, 2);
                } elseif (str_starts_with($part, 'v1=')) {
                    $signatures[] = substr($part, 3);
                }
            }

            if (!$timestamp || empty($signatures)) {
                return ['success' => false, 'message' => 'Signature Stripe invalide'];
            }

            if (abs(time() - $timestamp) > 300) {
                return ['success' => false, 'message' => 'Signature Stripe expirée'];
            }

            $signedPayload = $timestamp . '.' . $payload;
            $expected = hash_hmac('sha256', $signedPayload, $this->webhookSecret);

            $valid = false;
            foreach ($signatures as $sig) {
                if (hash_equals($expected, $sig)) {
                    $valid = true;
                    break;
                }
            }

            if (!$valid) {
                return ['success' => false, 'message' => 'Signature Stripe invalide'];
            }

            $event = json_decode($payload, true);
            $eventType = is_array($event) ? ($event['type'] ?? 'unknown') : 'unknown';

            return ['success' => true, 'event' => $eventType, 'data' => $event];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $this->safeErrorMessage($e, 'stripe.webhook', 'Erreur lors de la vérification du webhook.')
            ];
        }
    }
    
    /**
     * Rembourser un paiement
     */
    public function refundPayment($paymentIntentId, $amount = null) {
        if ($this->apiKey === 'sk_test_xxxxx') {
            return ['success' => true, 'message' => 'Remboursement simulé'];
        }
        
        // Implémentation du remboursement
        return ['success' => false, 'message' => 'À implémenter'];
    }
    
    /**
     * Obtenir l'URL de base du site
     */
    private function getBaseUrl() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        return $protocol . '://' . $host;
    }
}
