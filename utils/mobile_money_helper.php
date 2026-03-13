<?php
/**
 * Mobile Money Helper - Orange Money + MTN MoMo
 * Centralise la creation et la verification des paiements mobile money.
 */

class MobileMoneyHelper {
    private PDO $db;
    private array $config;

    public function __construct(PDO $db = null) {
        require_once __DIR__ . '/../config/mobile_money.php';
        $this->config = mobile_money_config();

        if ($db instanceof PDO) {
            $this->db = $db;
        } else {
            require_once __DIR__ . '/../config/database.php';
            $database = new Database();
            $this->db = $database->getConnection();
        }
    }

    public function normalizeProvider(string $provider): string {
        $provider = strtolower(trim($provider));
        if (in_array($provider, ['om', 'orange', 'orange_money', 'orangemoney'], true)) {
            return 'orange';
        }
        if (in_array($provider, ['momo', 'mtn', 'mtn_momo', 'mtnmomo'], true)) {
            return 'mtn';
        }
        return '';
    }

    public function normalizeCameroonMsisdn(?string $phone): ?string {
        if (!$phone) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone);
        if (!$digits) {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 9) {
            return '237' . $digits;
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '237')) {
            return $digits;
        }

        return null;
    }

    public function formatCameroonMsisdn(?string $msisdn): string {
        if (!$msisdn) {
            return '';
        }
        $digits = preg_replace('/\D+/', '', $msisdn);
        if (strlen($digits) === 12 && str_starts_with($digits, '237')) {
            $local = substr($digits, 3);
            return '+237 ' . substr($local, 0, 3) . ' ' . substr($local, 3, 3) . ' ' . substr($local, 6);
        }
        return $msisdn;
    }

    public function createPaymentRequest(array $user, $colisId, string $provider): array {
        $provider = $this->normalizeProvider($provider);
        if ($provider === '') {
            return ['success' => false, 'message' => 'Moyen de paiement invalide.'];
        }

        if (!$this->config['simulate'] && empty($this->config[$provider]['enabled'])) {
            return ['success' => false, 'message' => 'Ce moyen de paiement est indisponible pour le moment.'];
        }

        $items = $this->fetchPendingColis($user['id'], $colisId);
        if (empty($items)) {
            return ['success' => false, 'message' => 'Aucun paiement en attente.'];
        }

        $msisdn = $this->normalizeCameroonMsisdn($user['telephone'] ?? '');
        if (!$msisdn) {
            return ['success' => false, 'message' => 'Numéro de téléphone invalide. Ajoutez un numéro camerounais dans votre profil.'];
        }

        $currency = $this->normalizeCurrency($items[0]['payment_currency'] ?? $this->config['currency']);
        $totalAmount = 0;
        foreach ($items as $item) {
            $totalAmount += (float) $item['payment_amount'];
        }

        $reference = $this->generateReference($provider);

        $metadata = [
            'colis_ids' => array_column($items, 'id'),
            'amount' => $totalAmount,
            'currency' => $currency,
            'provider' => $provider,
            'created_at' => date('c'),
            'simulate' => $this->config['simulate'],
        ];

        $this->storePaymentInit($items, $provider, $reference, $msisdn, $metadata);

        if ($this->config['simulate']) {
            return [
                'success' => true,
                'mode' => 'simulate',
                'reference' => $reference,
                'message' => 'Paiement simulé. Validation immédiate en mode test.',
                'redirect' => $this->getBaseUrl() . '/paiements.php?payment=success&provider=' . $provider . '&reference=' . $reference
            ];
        }

        if ($provider === 'orange') {
            return $this->createOrangePayment($reference, $totalAmount, $currency, $msisdn, $items, $user);
        }

        return $this->createMomoPayment($reference, $totalAmount, $currency, $msisdn, $items, $user);
    }

    public function verifyPayment(string $provider, string $reference): array {
        $provider = $this->normalizeProvider($provider);
        if ($provider === '' || $reference === '') {
            return ['success' => false, 'message' => 'Référence de paiement invalide.'];
        }

        if ($this->config['simulate']) {
            return [
                'success' => true,
                'status' => 'SUCCESSFUL',
                'reference' => $reference,
                'provider' => $provider,
                'transaction_id' => 'SIMULATED-' . $reference
            ];
        }

        if ($provider === 'orange') {
            return $this->verifyOrangePayment($reference);
        }

        return $this->verifyMomoPayment($reference);
    }

    public function applyPaymentStatus(string $reference, string $provider, string $providerStatus, array $context = []): array {
        $provider = $this->normalizeProvider($provider);
        if ($provider === '' || $reference === '') {
            return ['success' => false, 'message' => 'Référence de paiement invalide.'];
        }

        $colisRows = $this->fetchColisByReference($reference, $provider);
        if (empty($colisRows)) {
            return ['success' => false, 'message' => 'Aucun colis associé à cette référence.'];
        }

        $internalStatus = $this->mapStatus($providerStatus);
        if ($internalStatus === null) {
            return ['success' => false, 'message' => 'Statut de paiement inconnu.'];
        }

        $transactionId = $context['transaction_id'] ?? null;
        $raw = $context['raw'] ?? null;
        $reason = $context['reason'] ?? null;

        $updates = [];
        if ($internalStatus === 'paid') {
            $updates[] = "payment_status = 'paid'";
            $updates[] = "paid_at = NOW()";
        } elseif ($internalStatus === 'failed') {
            $updates[] = "payment_status = 'failed'";
        } elseif ($internalStatus === 'cancelled') {
            $updates[] = "payment_status = 'cancelled'";
        }

        $metaUpdate = [
            'provider_status' => $providerStatus,
            'transaction_id' => $transactionId,
            'checked_at' => date('c')
        ];

        if ($raw !== null) {
            $metaUpdate['raw'] = $raw;
        }

        foreach ($colisRows as $row) {
            $currentMeta = [];
            if (!empty($row['payment_metadata'])) {
                $decoded = json_decode($row['payment_metadata'], true);
                if (is_array($decoded)) {
                    $currentMeta = $decoded;
                }
            }

            $mergedMeta = array_merge($currentMeta, $metaUpdate);

            $setParts = [];
            if (!empty($updates)) {
                $setParts = array_merge($setParts, $updates);
            }
            $setParts[] = "payment_metadata = ?";
            $setParts[] = "payment_last_error = ?";

            $sql = "UPDATE colis SET " . implode(', ', $setParts) . " WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                json_encode($mergedMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $internalStatus === 'failed' ? ($reason ?: 'Paiement échoué') : null,
                $row['id']
            ]);

            if (in_array($internalStatus, ['paid', 'failed'], true)) {
                $this->syncPaiementRecord($row, $provider, $internalStatus, $transactionId, $providerStatus);
            }
        }

        return [
            'success' => true,
            'status' => $internalStatus,
            'provider_status' => $providerStatus,
            'colis' => $colisRows
        ];
    }

    private function syncPaiementRecord(array $colisRow, string $provider, string $internalStatus, ?string $transactionId, string $providerStatus): void {
        $colisId = $colisRow['id'] ?? null;
        if (!$colisId) {
            return;
        }

        $userId = $colisRow['expediteur_id'] ?: ($colisRow['destinataire_id'] ?: ($colisRow['utilisateur_id'] ?? null));
        if (!$userId) {
            return;
        }

        $statut = $internalStatus === 'paid' ? 'paye' : 'echec';
        $details = [
            'provider' => $provider,
            'reference' => $colisRow['payment_reference'] ?? null,
            'provider_status' => $providerStatus
        ];
        if ($transactionId) {
            $details['transaction_id'] = $transactionId;
        }

        $stmt = $this->db->prepare("
            SELECT id, statut FROM paiements
            WHERE colis_id = ?
            ORDER BY date_paiement DESC, date_creation DESC
            LIMIT 1
        ");
        $stmt->execute([$colisId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $update = $this->db->prepare("
                UPDATE paiements
                SET montant = ?,
                    devise = ?,
                    mode_paiement = 'mobile',
                    transaction_id = ?,
                    statut = ?,
                    date_paiement = NOW(),
                    details = ?
                WHERE id = ?
            ");
            $update->execute([
                $colisRow['payment_amount'] ?? 0,
                $this->normalizeCurrency($colisRow['payment_currency'] ?? $this->config['currency']),
                $transactionId,
                $statut,
                json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $existing['id']
            ]);
            return;
        }

        $insert = $this->db->prepare("
            INSERT INTO paiements (utilisateur_id, colis_id, montant, devise, mode_paiement, transaction_id, statut, date_paiement, details)
            VALUES (?, ?, ?, ?, 'mobile', ?, ?, NOW(), ?)
        ");
        $insert->execute([
            $userId,
            $colisId,
            $colisRow['payment_amount'] ?? 0,
            $this->normalizeCurrency($colisRow['payment_currency'] ?? $this->config['currency']),
            $transactionId,
            $statut,
            json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);
    }

    public function getReferenceForColis(int $colisId, int $userId): ?array {
        $stmt = $this->db->prepare("
            SELECT id, payment_reference, payment_provider 
            FROM colis 
            WHERE id = ? AND (expediteur_id = ? OR destinataire_id = ?)
            LIMIT 1
        ");
        $stmt->execute([$colisId, $userId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['payment_reference'])) {
            return null;
        }
        return [
            'reference' => $row['payment_reference'],
            'provider' => $row['payment_provider'] ?: ''
        ];
    }

    public function handleCallback(string $provider, array $payload, array $headers = []): array {
        $provider = $this->normalizeProvider($provider);
        if ($provider === '') {
            return ['success' => false, 'message' => 'Fournisseur non reconnu.'];
        }

        $reference = $this->extractReference($payload, $headers);
        if (!$reference) {
            return ['success' => false, 'message' => 'Référence manquante.'];
        }

        $verify = $this->verifyPayment($provider, $reference);
        if (!$verify['success']) {
            return $verify;
        }

        $apply = $this->applyPaymentStatus($reference, $provider, $verify['status'] ?? 'PENDING', $verify);
        if (!$apply['success']) {
            return $apply;
        }

        return [
            'success' => true,
            'reference' => $reference,
            'status' => $apply['status'] ?? 'pending'
        ];
    }

    private function fetchPendingColis(int $userId, $colisId): array {
        if ($colisId === 'all') {
            $stmt = $this->db->prepare("
                SELECT * FROM colis
                WHERE (expediteur_id = ? OR destinataire_id = ?)
                AND payment_status = 'pending'
                AND payment_amount > 0
                ORDER BY date_creation DESC
            ");
            $stmt->execute([$userId, $userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $stmt = $this->db->prepare("
            SELECT * FROM colis
            WHERE id = ?
            AND (expediteur_id = ? OR destinataire_id = ?)
            AND payment_status = 'pending'
            AND payment_amount > 0
            LIMIT 1
        ");
        $stmt->execute([$colisId, $userId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? [$row] : [];
    }

    private function fetchColisByReference(string $reference, string $provider): array {
        $stmt = $this->db->prepare("
            SELECT * FROM colis
            WHERE payment_reference = ? AND payment_provider = ?
        ");
        $stmt->execute([$reference, $provider]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function storePaymentInit(array $items, string $provider, string $reference, string $msisdn, array $metadata): void {
        $ids = array_column($items, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "
            UPDATE colis
            SET payment_provider = ?,
                payment_reference = ?,
                payment_phone = ?,
                payment_metadata = ?,
                payment_last_error = NULL
            WHERE id IN ($placeholders)
        ";
        $stmt = $this->db->prepare($sql);
        $params = array_merge(
            [$provider, $reference, $msisdn, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            $ids
        );
        $stmt->execute($params);
    }

    private function createOrangePayment(string $reference, float $amount, string $currency, string $msisdn, array $items, array $user): array {
        $cfg = $this->config['orange'];

        if (empty($cfg['payment_url']) || empty($cfg['merchant_key'])) {
            return ['success' => false, 'message' => 'Configuration Orange Money incomplète.'];
        }

        $token = $this->getOrangeAccessToken();
        if (!$token) {
            return ['success' => false, 'message' => 'Impossible d\'obtenir un jeton Orange Money.'];
        }

        $returnUrl = $cfg['return_url'] ?: $this->getBaseUrl() . '/paiements.php?payment=success&provider=orange&reference=' . $reference;
        $cancelUrl = $cfg['cancel_url'] ?: $this->getBaseUrl() . '/paiements.php?cancel=true';
        $notifUrl = $cfg['notif_url'] ?: $this->getBaseUrl() . '/api/payment_callback.php?provider=orange';

        $payload = [
            'merchant_key' => $cfg['merchant_key'],
            'currency' => $currency,
            'order_id' => $reference,
            'amount' => (string) $amount,
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
            'notif_url' => $notifUrl,
            'lang' => $cfg['language'] ?: 'fr',
            'reference' => $reference,
        ];

        if (!empty($cfg['payer_field'])) {
            $payload[$cfg['payer_field']] = $msisdn;
        }

        if (!empty($cfg['extra_fields']) && is_array($cfg['extra_fields'])) {
            $payload = array_merge($payload, $cfg['extra_fields']);
        }

        $response = $this->requestJson($cfg['payment_url'], 'POST', [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ], $payload);

        if (!$response['success']) {
            $this->storePaymentError($reference, 'orange', $response['message']);
            return ['success' => false, 'message' => $response['message']];
        }

        $data = $response['data'] ?? [];
        $paymentUrl = $data['payment_url'] ?? $data['paymentUrl'] ?? $data['url'] ?? null;
        $payToken = $data['pay_token'] ?? $data['payToken'] ?? null;
        $notifToken = $data['notif_token'] ?? $data['notifToken'] ?? null;

        $this->storePaymentMeta($reference, 'orange', [
            'pay_token' => $payToken,
            'notif_token' => $notifToken,
            'payment_url' => $paymentUrl
        ]);

        if ($paymentUrl) {
            return [
                'success' => true,
                'reference' => $reference,
                'redirect' => $paymentUrl,
                'message' => 'Redirection vers Orange Money.'
            ];
        }

        return [
            'success' => true,
            'reference' => $reference,
            'message' => 'Demande Orange Money créée. Confirmez sur votre téléphone.'
        ];
    }

    private function verifyOrangePayment(string $reference): array {
        $cfg = $this->config['orange'];
        if (empty($cfg['status_url'])) {
            return ['success' => false, 'message' => 'Statut Orange Money indisponible.'];
        }

        $token = $this->getOrangeAccessToken();
        if (!$token) {
            return ['success' => false, 'message' => 'Impossible d\'obtenir un jeton Orange Money.'];
        }

        $statusUrl = str_replace('{order_id}', $reference, $cfg['status_url']);
        $response = $this->requestJson($statusUrl, 'GET', [
            'Authorization: Bearer ' . $token
        ]);

        if (!$response['success']) {
            return ['success' => false, 'message' => $response['message']];
        }

        $data = $response['data'] ?? [];
        $status = $data['status'] ?? $data['payment_status'] ?? $data['transactionStatus'] ?? 'PENDING';
        $transactionId = $data['transaction_id'] ?? $data['transactionId'] ?? $data['txid'] ?? null;

        return [
            'success' => true,
            'status' => $status,
            'reference' => $reference,
            'provider' => 'orange',
            'transaction_id' => $transactionId,
            'raw' => $data
        ];
    }

    private function createMomoPayment(string $reference, float $amount, string $currency, string $msisdn, array $items, array $user): array {
        $cfg = $this->config['mtn'];
        if (empty($cfg['subscription_key']) || empty($cfg['api_user']) || empty($cfg['api_key'])) {
            return ['success' => false, 'message' => 'Configuration MTN MoMo incomplète.'];
        }

        $token = $this->getMomoAccessToken();
        if (!$token) {
            return ['success' => false, 'message' => 'Impossible d\'obtenir un jeton MTN MoMo.'];
        }

        $payload = [
            'amount' => (string) $amount,
            'currency' => $currency,
            'externalId' => $reference,
            'payer' => [
                'partyIdType' => 'MSISDN',
                'partyId' => $msisdn
            ],
            'payerMessage' => 'Paiement colis',
            'payeeNote' => 'Gestion Colis'
        ];

        $headers = [
            'Authorization: Bearer ' . $token,
            'X-Reference-Id: ' . $reference,
            'X-Target-Environment: ' . $cfg['target_env'],
            'Ocp-Apim-Subscription-Key: ' . $cfg['subscription_key'],
            'Content-Type: application/json'
        ];

        $callbackUrl = $cfg['callback_url'] ?: $this->getBaseUrl() . '/api/payment_callback.php?provider=mtn';
        if (!empty($callbackUrl)) {
            $headers[] = 'X-Callback-Url: ' . $callbackUrl;
        }

        $url = rtrim($cfg['base_url'], '/') . '/collection/v1_0/requesttopay';
        $response = $this->requestJson($url, 'POST', $headers, $payload, [202, 201, 200]);

        if (!$response['success']) {
            $this->storePaymentError($reference, 'mtn', $response['message']);
            return ['success' => false, 'message' => $response['message']];
        }

        return [
            'success' => true,
            'reference' => $reference,
            'message' => 'Demande MTN MoMo envoyée. Confirmez sur votre téléphone.'
        ];
    }

    private function verifyMomoPayment(string $reference): array {
        $cfg = $this->config['mtn'];
        $token = $this->getMomoAccessToken();
        if (!$token) {
            return ['success' => false, 'message' => 'Impossible d\'obtenir un jeton MTN MoMo.'];
        }

        $url = rtrim($cfg['base_url'], '/') . '/collection/v1_0/requesttopay/' . urlencode($reference);
        $response = $this->requestJson($url, 'GET', [
            'Authorization: Bearer ' . $token,
            'X-Target-Environment: ' . $cfg['target_env'],
            'Ocp-Apim-Subscription-Key: ' . $cfg['subscription_key']
        ]);

        if (!$response['success']) {
            return ['success' => false, 'message' => $response['message']];
        }

        $data = $response['data'] ?? [];
        $status = $data['status'] ?? 'PENDING';
        $transactionId = $data['financialTransactionId'] ?? null;

        return [
            'success' => true,
            'status' => $status,
            'reference' => $reference,
            'provider' => 'mtn',
            'transaction_id' => $transactionId,
            'raw' => $data
        ];
    }

    private function mapStatus(string $providerStatus): ?string {
        $status = strtoupper(trim($providerStatus));
        if (in_array($status, ['SUCCESSFUL', 'SUCCESS', 'PAID', 'COMPLETED'], true)) {
            return 'paid';
        }
        if (in_array($status, ['FAILED', 'REJECTED', 'ERROR'], true)) {
            return 'failed';
        }
        if (in_array($status, ['CANCELLED', 'CANCELED', 'EXPIRED'], true)) {
            return 'cancelled';
        }
        if (in_array($status, ['PENDING', 'ONGOING', 'IN_PROGRESS', 'PROCESSING'], true)) {
            return 'pending';
        }
        return null;
    }

    private function normalizeCurrency(string $currency): string {
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            return $this->config['currency'];
        }
        if ($currency === 'FCFA') {
            return 'XAF';
        }
        return $currency;
    }

    private function generateReference(string $provider): string {
        if ($provider === 'mtn') {
            return $this->uuidV4();
        }
        return date('YmdHis') . random_int(1000, 9999);
    }

    private function uuidV4(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function getOrangeAccessToken(): ?string {
        $cfg = $this->config['orange'];
        if (empty($cfg['client_id']) || empty($cfg['client_secret']) || empty($cfg['token_url'])) {
            return null;
        }

        $cached = $this->getCachedToken('om');
        if ($cached) {
            return $cached;
        }

        $headers = [
            'Authorization: Basic ' . base64_encode($cfg['client_id'] . ':' . $cfg['client_secret']),
            'Content-Type: application/x-www-form-urlencoded'
        ];

        $response = $this->requestRaw($cfg['token_url'], 'POST', $headers, http_build_query([
            'grant_type' => 'client_credentials'
        ]));

        if (!$response['success']) {
            return null;
        }

        $data = json_decode($response['body'] ?? '', true);
        $token = $data['access_token'] ?? null;
        $expiresIn = isset($data['expires_in']) ? (int) $data['expires_in'] : 0;

        if ($token) {
            $this->setCachedToken('om', $token, $expiresIn);
        }

        return $token;
    }

    private function getMomoAccessToken(): ?string {
        $cfg = $this->config['mtn'];
        if (empty($cfg['api_user']) || empty($cfg['api_key']) || empty($cfg['base_url']) || empty($cfg['subscription_key'])) {
            return null;
        }

        $cached = $this->getCachedToken('momo');
        if ($cached) {
            return $cached;
        }

        $headers = [
            'Authorization: Basic ' . base64_encode($cfg['api_user'] . ':' . $cfg['api_key']),
            'Ocp-Apim-Subscription-Key: ' . $cfg['subscription_key']
        ];

        $url = rtrim($cfg['base_url'], '/') . '/collection/token/';
        $response = $this->requestRaw($url, 'POST', $headers);

        if (!$response['success']) {
            return null;
        }

        $data = json_decode($response['body'] ?? '', true);
        $token = $data['access_token'] ?? null;
        $expiresIn = isset($data['expires_in']) ? (int) $data['expires_in'] : 0;

        if ($token) {
            $this->setCachedToken('momo', $token, $expiresIn);
        }

        return $token;
    }

    private function getCachedToken(string $prefix): ?string {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        $token = $_SESSION[$prefix . '_access_token'] ?? null;
        $expiresAt = $_SESSION[$prefix . '_access_token_expires'] ?? 0;
        if ($token && time() < $expiresAt) {
            return $token;
        }
        return null;
    }

    private function setCachedToken(string $prefix, string $token, int $expiresIn): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $ttl = $expiresIn > 60 ? $expiresIn - 60 : $expiresIn;
        $_SESSION[$prefix . '_access_token'] = $token;
        $_SESSION[$prefix . '_access_token_expires'] = time() + $ttl;
    }

    private function requestJson(string $url, string $method, array $headers = [], $payload = null, array $successCodes = [200]): array {
        $body = null;
        if ($payload !== null) {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $response = $this->requestRaw($url, $method, $headers, $body);
        if (!$response['success']) {
            return $response;
        }
        if (!in_array($response['status'], $successCodes, true)) {
            return [
                'success' => false,
                'message' => 'Erreur API (HTTP ' . $response['status'] . ').',
                'status' => $response['status']
            ];
        }
        $data = null;
        if (!empty($response['body'])) {
            $decoded = json_decode($response['body'], true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        return [
            'success' => true,
            'status' => $response['status'],
            'data' => $data
        ];
    }

    private function requestRaw(string $url, string $method, array $headers = [], ?string $body = null): array {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            return ['success' => false, 'message' => 'Erreur réseau: ' . $error];
        }

        return [
            'success' => true,
            'status' => $status,
            'body' => $responseBody
        ];
    }

    private function storePaymentError(string $reference, string $provider, string $message): void {
        $stmt = $this->db->prepare("
            UPDATE colis
            SET payment_last_error = ?
            WHERE payment_reference = ? AND payment_provider = ?
        ");
        $stmt->execute([$message, $reference, $provider]);
    }

    private function storePaymentMeta(string $reference, string $provider, array $metaUpdate): void {
        $rows = $this->fetchColisByReference($reference, $provider);
        foreach ($rows as $row) {
            $currentMeta = [];
            if (!empty($row['payment_metadata'])) {
                $decoded = json_decode($row['payment_metadata'], true);
                if (is_array($decoded)) {
                    $currentMeta = $decoded;
                }
            }
            $merged = array_merge($currentMeta, $metaUpdate);
            $stmt = $this->db->prepare("UPDATE colis SET payment_metadata = ? WHERE id = ?");
            $stmt->execute([
                json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $row['id']
            ]);
        }
    }

    private function extractReference(array $payload, array $headers): ?string {
        $candidates = [
            $payload['reference'] ?? null,
            $payload['referenceId'] ?? null,
            $payload['reference_id'] ?? null,
            $payload['externalId'] ?? null,
            $payload['external_id'] ?? null,
            $payload['order_id'] ?? null,
            $payload['orderId'] ?? null,
            $payload['transaction_id'] ?? null,
            $payload['transactionId'] ?? null
        ];

        foreach ($headers as $key => $value) {
            if (strcasecmp($key, 'X-Reference-Id') === 0 && $value) {
                $candidates[] = $value;
            }
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function getBaseUrl(): string {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $protocol . '://' . $host;
    }
}
