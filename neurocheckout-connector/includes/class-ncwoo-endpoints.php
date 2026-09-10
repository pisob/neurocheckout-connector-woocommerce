<?php

if (!defined('ABSPATH')) {
    exit;
}

final class NCWooEndpoints
{
    private const DEFAULT_TTL_HOURS = 48;
    private const MAX_TTL_HOURS = 168;
    private const GZIP_DECODE_CHUNK_BYTES = 65536;

    private const MIN_API_KEY_LENGTH = 32;
    private const MAX_API_KEY_LENGTH = 512;
    private const PREVIOUS_KEY_GRACE_SECONDS = 900;

    private const DEFAULT_LOOKBACK_DAYS = 180;
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 250;

    private NCWooConfig $config;
    private NCWooDB $db;
    private NCWooSecurity $security;
    private NCWooRecoveryService $recovery;
    private NCWooEventService $events;

    public function __construct(
        NCWooConfig $config,
        NCWooDB $db,
        NCWooSecurity $security,
        NCWooRecoveryService $recovery,
        NCWooEventService $events
    ) {
        $this->config = $config;
        $this->db = $db;
        $this->security = $security;
        $this->recovery = $recovery;
        $this->events = $events;
    }

    public function register_routes(): void
    {
        register_rest_route('neurocheckout/v1', '/coupon', [
            'methods' => 'POST',
            'callback' => [$this, 'endpoint_coupon'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('neurocheckout/v1', '/cartrestore', [
            'methods' => 'POST',
            'callback' => [$this, 'endpoint_cartrestore'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('neurocheckout/v1', '/apikeysync', [
            'methods' => 'POST',
            'callback' => [$this, 'endpoint_apikeysync'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('neurocheckout/v1', '/apitest', [
            'methods' => 'POST',
            'callback' => [$this, 'endpoint_apitest'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('neurocheckout/v1', '/cron', [
            'methods' => ['GET', 'POST'],
            'callback' => [$this, 'endpoint_cron'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('neurocheckout/v1', '/orders/history', [
            'methods' => 'POST',
            'callback' => [$this, 'endpoint_orderhistory'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('neurocheckout/v1', '/orderhistory', [
            'methods' => 'POST',
            'callback' => [$this, 'endpoint_orderhistory'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('neurocheckout/v1', '/telemetry', [
            'methods' => 'POST',
            'callback' => [$this, 'endpoint_telemetry'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function endpoint_coupon(WP_REST_Request $request): WP_REST_Response
    {
        $security = $this->security->validate_signed_request($request, 'coupon', false);
        if (empty($security['success'])) {
            return $this->security_failure_response($security);
        }

        $decoded = $this->decode_json_payload($request, 2 * 1024 * 1024, 12 * 1024 * 1024);
        if (empty($decoded['success'])) {
            return $this->response(false, (int) ($decoded['status'] ?? 422), (string) ($decoded['error'] ?? 'Invalid JSON payload'));
        }

        $payload = is_array($decoded['payload']) ? $decoded['payload'] : [];

        $requestUid = trim((string) ($payload['request_uid'] ?? ''));
        $decisionId = trim((string) ($payload['decision_id'] ?? ''));
        $actionId = trim((string) ($payload['action_id'] ?? ''));
        $cartId = trim((string) ($payload['cart_id'] ?? ''));
        $customerEmail = strtolower(trim((string) ($payload['customer_email'] ?? '')));
        $discountPercent = min(max((float) ($payload['discount_percent'] ?? 0), 0.0), 100.0);
        $ttlHours = min(max((int) ($payload['ttl_hours'] ?? self::DEFAULT_TTL_HOURS), 1), self::MAX_TTL_HOURS);

        if ($requestUid === '') {
            $requestUid = trim($decisionId . ':' . $actionId);
        }

        if ($requestUid === '' || $cartId === '' || $customerEmail === '') {
            return $this->response(false, 422, 'Missing required fields');
        }

        $existing = $this->get_existing_coupon($requestUid);
        if (is_array($existing)) {
            return $this->response(true, 200, null, $existing);
        }

        $products = $this->normalize_products($payload['products'] ?? []);
        $cartFingerprint = NCWooCartFingerprint::from_products($products);

        if ($discountPercent <= 0.0) {
            $recoveryUrl = $this->recovery->build_cart_recovery_url(
                $cartId,
                $customerEmail,
                '',
                $cartFingerprint,
                (string) ($payload['target_url'] ?? ''),
                ['products' => $products]
            );

            return $this->response(true, 200, null, [
                'coupon_code' => null,
                'discount_percent' => 0,
                'recovery_url' => $recoveryUrl,
                'expires_at' => null,
            ]);
        }

        $couponCode = $this->generate_coupon_code($cartId);
        $expiresTs = time() + ($ttlHours * 3600);
        $expiresAt = gmdate('Y-m-d H:i:s', $expiresTs);

        $couponId = $this->create_woocommerce_coupon(
            $couponCode,
            $discountPercent,
            $customerEmail,
            $expiresTs
        );
        if ($couponId <= 0) {
            return $this->response(false, 500, 'Unable to create coupon');
        }

        $recoveryUrl = $this->recovery->build_cart_recovery_url(
            $cartId,
            $customerEmail,
            $couponCode,
            $cartFingerprint,
            (string) ($payload['target_url'] ?? ''),
            ['products' => $products]
        );

        $this->persist_coupon(
            $requestUid,
            $decisionId,
            $actionId,
            $cartId,
            $customerEmail,
            $couponId,
            $couponCode,
            $discountPercent,
            $cartFingerprint,
            $recoveryUrl,
            $expiresAt
        );

        return $this->response(true, 200, null, [
            'coupon_code' => $couponCode,
            'discount_percent' => round($discountPercent, 2),
            'recovery_url' => $recoveryUrl,
            'expires_at' => $expiresAt,
        ]);
    }

    public function endpoint_cartrestore(WP_REST_Request $request): WP_REST_Response
    {
        $security = $this->security->validate_signed_request($request, 'cartrestore', false);
        if (empty($security['success'])) {
            return $this->security_failure_response($security);
        }

        $decoded = $this->decode_json_payload($request, 2 * 1024 * 1024, 12 * 1024 * 1024);
        if (empty($decoded['success'])) {
            return $this->response(false, (int) ($decoded['status'] ?? 422), (string) ($decoded['error'] ?? 'Invalid JSON payload'));
        }

        $payload = is_array($decoded['payload']) ? $decoded['payload'] : [];

        $cartId = trim((string) ($payload['cart_id'] ?? ''));
        $cartUid = trim((string) ($payload['cart_uid'] ?? ''));
        $customerEmail = strtolower(trim((string) ($payload['customer_email'] ?? '')));
        $customerId = (int) ($payload['customer_id'] ?? 0);
        $targetUrl = trim((string) ($payload['target_url'] ?? ''));
        $products = $this->normalize_products($payload['products'] ?? []);
        $sessionOnly = $this->is_truthy($payload['session_only'] ?? false);

        if ($sessionOnly) {
            if ($customerEmail === '' || $targetUrl === '') {
                return $this->response(false, 422, 'Missing required fields');
            }

            $resolvedUser = $this->resolve_customer($customerId, $customerEmail);
            if (!$resolvedUser) {
                return $this->response(false, 422, 'Customer not found');
            }

            $recoveryUrl = $this->recovery->build_customer_session_recovery_url(
                (int) $resolvedUser->ID,
                $customerEmail,
                $targetUrl
            );

            return $this->response(true, 200, null, [
                'recovery_url' => $recoveryUrl,
                'restored_cart_id' => null,
                'cart_uid' => $cartUid,
                'recreated' => false,
                'products_added' => 0,
                'coupon_preserved' => false,
                'session_only' => true,
            ]);
        }

        if ($customerEmail === '' || !$products) {
            return $this->response(false, 422, 'Missing required fields');
        }

        $couponCode = $this->extract_coupon_from_target_url($targetUrl);
        $couponPreserved = $couponCode !== '' && $this->coupon_exists($couponCode);
        if (!$couponPreserved) {
            $couponCode = '';
        }

        $cartFingerprint = NCWooCartFingerprint::from_products($products);
        $effectiveCartId = $cartId !== '' ? $cartId : ($cartUid !== '' ? $cartUid : 'recovered-' . wp_generate_uuid4());
        $resolvedUser = $this->resolve_customer($customerId, $customerEmail);
        $resolvedCustomerId = $resolvedUser ? (int) $resolvedUser->ID : 0;

        $recoveryUrl = $this->recovery->build_cart_recovery_url(
            $effectiveCartId,
            $customerEmail,
            $couponCode,
            $cartFingerprint,
            $targetUrl,
            ['products' => $products, 'customer_id' => $resolvedCustomerId > 0 ? $resolvedCustomerId : null],
            $resolvedCustomerId > 0 ? $resolvedCustomerId : null
        );

        return $this->response(true, 200, null, [
            'recovery_url' => $recoveryUrl,
            'restored_cart_id' => $effectiveCartId,
            'cart_uid' => $cartUid,
            'recreated' => true,
            'products_added' => count($products),
            'coupon_preserved' => $couponPreserved,
        ]);
    }

    public function endpoint_apikeysync(WP_REST_Request $request): WP_REST_Response
    {
        $security = $this->security->validate_signed_request($request, 'apikeysync', true);
        if (empty($security['success'])) {
            return $this->security_failure_response($security);
        }

        $decoded = $this->decode_json_payload($request, 2 * 1024 * 1024, 12 * 1024 * 1024);
        if (empty($decoded['success'])) {
            return $this->response(false, (int) ($decoded['status'] ?? 422), (string) ($decoded['error'] ?? 'Invalid JSON payload'));
        }

        $payload = is_array($decoded['payload']) ? $decoded['payload'] : [];

        $phase = strtolower(trim((string) ($payload['phase'] ?? 'direct')));
        if (!in_array($phase, ['prepare', 'finalize', 'direct'], true)) {
            $phase = 'direct';
        }

        $newApiKey = preg_replace('/\s+/', '', trim((string) ($payload['new_api_key'] ?? '')));
        if ($newApiKey === '') {
            return $this->response(false, 422, 'Missing new_api_key');
        }

        $length = strlen($newApiKey);
        if ($length < self::MIN_API_KEY_LENGTH || $length > self::MAX_API_KEY_LENGTH) {
            return $this->response(false, 422, 'Invalid new_api_key length');
        }

        $providedHash = trim((string) ($payload['new_api_key_hash'] ?? ''));
        $computedHash = hash('sha256', $newApiKey);
        if ($providedHash !== '' && !hash_equals($providedHash, $computedHash)) {
            return $this->response(false, 422, 'new_api_key_hash mismatch');
        }

        $currentApiKey = $this->config->get_api_key();
        if ($currentApiKey === '') {
            return $this->response(false, 409, 'Connector API key missing');
        }

        if (hash_equals(hash('sha256', $currentApiKey), $computedHash)) {
            $this->clear_pending_rotation_state();
            return $this->response(true, 200, null, [
                'status' => 'already_current',
                'phase' => $phase,
                'api_key_hash_prefix' => substr($computedHash, 0, 10),
            ]);
        }

        $rotationId = trim((string) ($payload['rotation_id'] ?? ($payload['request_uid'] ?? '')));

        if ($phase === 'prepare') {
            $this->config->set(NCWooConfig::OPTION_API_KEY_NEXT, $newApiKey);
            $this->config->set(NCWooConfig::OPTION_API_KEY_ROTATION_ID, $rotationId);

            return $this->response(true, 200, null, [
                'status' => 'prepared',
                'phase' => 'prepare',
                'rotation_id' => $rotationId,
                'api_key_hash_prefix' => substr($computedHash, 0, 10),
            ]);
        }

        if ($phase === 'finalize') {
            $pendingApiKey = $this->config->get_pending_api_key();
            if ($pendingApiKey === '') {
                return $this->response(false, 409, 'Pending rotation not prepared');
            }

            $storedRotationId = trim($this->config->get_string(NCWooConfig::OPTION_API_KEY_ROTATION_ID));
            if ($rotationId === '' || $storedRotationId === '') {
                return $this->response(false, 409, 'Pending rotation context missing');
            }
            if (!hash_equals($storedRotationId, $rotationId)) {
                return $this->response(false, 409, 'Rotation ID mismatch');
            }
            if (!hash_equals(hash('sha256', $pendingApiKey), $computedHash)) {
                return $this->response(false, 409, 'Pending key mismatch');
            }

            $this->store_previous_key_for_grace($currentApiKey, $pendingApiKey);
            $this->config->set(NCWooConfig::OPTION_API_KEY, $pendingApiKey);
            $this->clear_pending_rotation_state();
            $this->refresh_api_test_validation_state_after_sync($pendingApiKey);

            return $this->response(true, 200, null, [
                'status' => 'updated',
                'phase' => 'finalize',
                'rotation_id' => $storedRotationId,
                'api_key_hash_prefix' => substr($computedHash, 0, 10),
            ]);
        }

        $this->store_previous_key_for_grace($currentApiKey, $newApiKey);
        $this->config->set(NCWooConfig::OPTION_API_KEY, $newApiKey);
        $this->clear_pending_rotation_state();
        $this->refresh_api_test_validation_state_after_sync($newApiKey);

        return $this->response(true, 200, null, [
            'status' => 'updated',
            'phase' => 'direct',
            'api_key_hash_prefix' => substr($computedHash, 0, 10),
        ]);
    }

    public function endpoint_apitest(WP_REST_Request $request): WP_REST_Response
    {
        $security = $this->security->validate_signed_request($request, 'apitest', false);
        if (empty($security['success'])) {
            return $this->security_failure_response($security);
        }

        $apiEndpoint = $this->config->get_string(NCWooConfig::OPTION_API_ENDPOINT);
        $apiKey = $this->config->get_api_key();
        $shopExternalId = $this->config->get_shop_external_id();

        $baseConfigReady = ($apiEndpoint !== '' && $apiKey !== '' && $shopExternalId !== '');
        $iaRulesReady = $this->config->is_ia_configuration_ready();
        $iaReady = $baseConfigReady && $iaRulesReady;

        $http = new NCWooHttpClient($this->config);
        $health = $http->health([
            'source' => ['shop_id' => $shopExternalId],
        ]);

        $probe = null;
        $probeOk = true;
        if (!empty($health['success']) && $iaReady) {
            $probe = $http->send_cart_event($this->build_api_test_event_payload($shopExternalId), ['is_api_test' => true]);
            $probeOk = !empty($probe['success']);
        }

        $success = $iaReady && !empty($health['success']) && $probeOk;

        if ($success) {
            $this->config->mark_api_test_validation_success();
        }

        $error = (string) ($health['error'] ?? 'API check failed');
        if (!$baseConfigReady) {
            $error = 'Connector API configuration incomplete';
        } elseif (!$iaRulesReady) {
            $error = 'IA configuration incomplete';
        }

        return $this->response(
            $success,
            $success ? 200 : 422,
            $success ? null : $error,
            [
                'ia_ready' => $iaReady,
                'ia_rules_ready' => $iaRulesReady,
                'base_config_ready' => $baseConfigReady,
                'health' => $health,
                'event_probe' => $probe,
            ]
        );
    }

    public function endpoint_cron(WP_REST_Request $request): WP_REST_Response
    {
        $security = $this->validate_signed_cron_request($request);
        if (empty($security['success'])) {
            return $this->response(false, (int) ($security['status'] ?? 403), (string) ($security['error'] ?? 'Forbidden'));
        }

        $isTestRun = $this->is_truthy($request->get_param('test') ?? false);
        $isDebugForceRun = $this->is_truthy($request->get_param('debug_force') ?? false);

        if ($isTestRun && !$this->config->get_bool(NCWooConfig::OPTION_DEBUG_MODE, false)) {
            return $this->response(false, 403, 'Debug mode disabled');
        }

        if ($isDebugForceRun && !$this->config->get_bool(NCWooConfig::OPTION_DEBUG_ADVANCED, false)) {
            return $this->response(false, 403, 'Advanced debug mode disabled');
        }

        $result = $this->events->process_queue($isTestRun, $isDebugForceRun);
        $status = (int) ($result['status_code'] ?? (!empty($result['success']) ? 200 : 500));
        $error = !empty($result['success']) ? null : (string) ($result['error'] ?? 'Cron execution failed');
        unset($result['status_code'], $result['error']);

        return $this->response(!empty($result['success']), $status, $error, $result);
    }

    public function endpoint_telemetry(WP_REST_Request $request): WP_REST_Response
    {
        $nonce = trim((string) ($request->get_header('X-WP-Nonce') ?: $request->get_param('_wpnonce')));
        if ($nonce === '' || !wp_verify_nonce($nonce, 'wp_rest')) {
            return $this->response(false, 403, 'Invalid telemetry nonce');
        }

        if (!$this->allow_public_telemetry_request()) {
            $response = $this->response(false, 429, 'Too many telemetry events');
            $response->header('Retry-After', '60');
            return $response;
        }

        $decoded = $this->decode_json_payload($request, 256 * 1024, 512 * 1024);
        if (empty($decoded['success'])) {
            return $this->response(false, (int) ($decoded['status'] ?? 422), (string) ($decoded['error'] ?? 'Invalid JSON payload'));
        }

        $payload = is_array($decoded['payload']) ? $decoded['payload'] : [];
        if (!$payload) {
            return $this->response(false, 422, 'Invalid telemetry payload');
        }

        $queued = $this->events->enqueue_public_telemetry($payload);
        if (!$queued) {
            return $this->response(false, 422, 'Unsupported telemetry payload');
        }

        return $this->response(true, 202, null, ['queued' => true]);
    }

    public function endpoint_orderhistory(WP_REST_Request $request): WP_REST_Response
    {
        $security = $this->security->validate_signed_request($request, 'orderhistory', true);
        if (empty($security['success'])) {
            return $this->security_failure_response($security);
        }

        $decoded = $this->decode_json_payload($request, 2 * 1024 * 1024, 12 * 1024 * 1024);
        if (empty($decoded['success'])) {
            return $this->response(false, (int) ($decoded['status'] ?? 422), (string) ($decoded['error'] ?? 'Invalid JSON payload'));
        }

        $payload = is_array($decoded['payload']) ? $decoded['payload'] : [];

        if (!$this->is_requested_shop_matching($payload)) {
            return $this->response(false, 409, 'shop_id mismatch');
        }

        $window = $this->resolve_history_window($payload);
        $limit = $this->resolve_history_limit($payload);
        $mode = $this->resolve_history_mode($payload);

        $cursorRaw = trim((string) ($payload['cursor'] ?? ''));
        $cursor = $cursorRaw !== '' ? $this->decode_cursor($cursorRaw) : null;
        if ($cursorRaw !== '' && !$cursor) {
            return $this->response(false, 422, 'Invalid cursor');
        }

        $rows = $this->fetch_order_rows($window['since_sql'], $window['until_sql'], $limit + 1, $cursor);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        $orders = [];
        foreach ($rows as $row) {
            $orderId = (int) ($row['ID'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }

            $orderPayload = $this->events->build_order_payload_for_sync($orderId);
            if (!$orderPayload) {
                continue;
            }

            if ($mode === 'slim') {
                $orders[] = [
                    'order_id' => $orderPayload['order_id'] ?? null,
                    'cart_id' => $orderPayload['cart_id'] ?? null,
                    'occurred_at' => $orderPayload['occurred_at'] ?? null,
                    'order_total' => $orderPayload['order_total'] ?? null,
                    'currency' => $orderPayload['currency'] ?? null,
                    'customer_email' => $orderPayload['customer_email'] ?? null,
                    'customer' => $orderPayload['customer'] ?? [],
                    'order' => ['items' => $orderPayload['order']['items'] ?? []],
                    'source' => $orderPayload['source'] ?? ['platform' => 'woocommerce'],
                ];
                continue;
            }

            $orders[] = $orderPayload;
        }

        $nextCursor = null;
        if ($hasMore && $rows) {
            $last = $rows[count($rows) - 1];
            $nextCursor = $this->encode_cursor(
                (string) ($last['post_date_gmt'] ?? ''),
                (int) ($last['ID'] ?? 0)
            );
        }

        return $this->response(true, 200, null, [
            'orders' => $orders,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'count' => count($orders),
            'limit' => $limit,
            'mode' => $mode,
            'window' => [
                'since' => $window['since_iso'],
                'until' => $window['until_iso'],
            ],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function decode_json_payload(WP_REST_Request $request, int $maxRawBytes, int $maxDecodedBytes): array
    {
        $rawBody = (string) $request->get_body();
        if (strlen($rawBody) > $maxRawBytes) {
            return ['success' => false, 'status' => 413, 'error' => 'payload_too_large'];
        }

        $decodedBody = $rawBody;
        $encoding = strtolower(trim((string) $request->get_header('Content-Encoding')));
        if ($encoding !== '' && strpos($encoding, 'gzip') !== false) {
            $decodedGzip = $this->decode_gzip_body($rawBody, $maxDecodedBytes);
            if (empty($decodedGzip['success'])) {
                return [
                    'success' => false,
                    'status' => (int) ($decodedGzip['status'] ?? 400),
                    'error' => (string) ($decodedGzip['error'] ?? 'Invalid gzip body'),
                ];
            }

            $decodedBody = (string) ($decodedGzip['body'] ?? '');
        } elseif (strlen($decodedBody) > $maxDecodedBytes) {
            return ['success' => false, 'status' => 413, 'error' => 'payload_too_large'];
        }

        $payload = json_decode($decodedBody, true);
        if (!is_array($payload)) {
            return ['success' => false, 'status' => 422, 'error' => 'Invalid JSON payload'];
        }

        return ['success' => true, 'status' => 200, 'payload' => $payload];
    }

    /**
     * @return array<string,mixed>
     */
    private function decode_gzip_body(string $rawBody, int $maxDecodedBytes): array
    {
        if (
            function_exists('inflate_init')
            && function_exists('inflate_add')
            && defined('ZLIB_ENCODING_GZIP')
            && defined('ZLIB_SYNC_FLUSH')
            && defined('ZLIB_FINISH')
        ) {
            $context = @inflate_init(ZLIB_ENCODING_GZIP);
            if ($context === false) {
                return ['success' => false, 'status' => 400, 'error' => 'Invalid gzip body'];
            }

            $decodedBody = '';
            $rawLength = strlen($rawBody);
            for ($offset = 0; $offset < $rawLength; $offset += self::GZIP_DECODE_CHUNK_BYTES) {
                $chunk = substr($rawBody, $offset, self::GZIP_DECODE_CHUNK_BYTES);
                $decodedChunk = @inflate_add($context, $chunk, ZLIB_SYNC_FLUSH);
                if ($decodedChunk === false) {
                    return ['success' => false, 'status' => 400, 'error' => 'Invalid gzip body'];
                }

                if ($decodedChunk !== '') {
                    $decodedBody .= $decodedChunk;
                    if (strlen($decodedBody) > $maxDecodedBytes) {
                        return ['success' => false, 'status' => 413, 'error' => 'payload_too_large'];
                    }
                }
            }

            $tail = @inflate_add($context, '', ZLIB_FINISH);
            if ($tail === false) {
                return ['success' => false, 'status' => 400, 'error' => 'Invalid gzip body'];
            }
            if ($tail !== '') {
                $decodedBody .= $tail;
                if (strlen($decodedBody) > $maxDecodedBytes) {
                    return ['success' => false, 'status' => 413, 'error' => 'payload_too_large'];
                }
            }

            return ['success' => true, 'status' => 200, 'body' => $decodedBody];
        }

        if (!function_exists('gzdecode')) {
            return ['success' => false, 'status' => 415, 'error' => 'gzip_not_supported'];
        }

        $decodedBody = @gzdecode($rawBody);
        if ($decodedBody === false) {
            return ['success' => false, 'status' => 400, 'error' => 'Invalid gzip body'];
        }
        if (strlen($decodedBody) > $maxDecodedBytes) {
            return ['success' => false, 'status' => 413, 'error' => 'payload_too_large'];
        }

        return ['success' => true, 'status' => 200, 'body' => $decodedBody];
    }

    private function clear_pending_rotation_state(): void
    {
        $this->config->set(NCWooConfig::OPTION_API_KEY_NEXT, '');
        $this->config->set(NCWooConfig::OPTION_API_KEY_ROTATION_ID, '');
    }

    private function store_previous_key_for_grace(string $currentApiKey, string $newApiKey): void
    {
        $current = preg_replace('/\s+/', '', trim($currentApiKey));
        $next = preg_replace('/\s+/', '', trim($newApiKey));

        if ($current === '' || $next === '' || (strlen($current) === strlen($next) && hash_equals($current, $next))) {
            $this->config->set(NCWooConfig::OPTION_API_KEY_PREV, '');
            $this->config->set(NCWooConfig::OPTION_API_KEY_PREV_UNTIL, 0);
            return;
        }

        $this->config->set(NCWooConfig::OPTION_API_KEY_PREV, $current);
        $this->config->set(NCWooConfig::OPTION_API_KEY_PREV_UNTIL, time() + self::PREVIOUS_KEY_GRACE_SECONDS);
    }

    private function refresh_api_test_validation_state_after_sync(string $activeApiKey): void
    {
        $validatedAt = $this->config->get_int(NCWooConfig::OPTION_API_TEST_VALIDATED_AT, 0);
        if ($validatedAt <= 0) {
            if (!$this->config->has_operational_evidence()) {
                $this->config->clear_api_test_validation_state();
                return;
            }
            $this->config->mark_api_test_validation_success($activeApiKey);
            return;
        }

        $fingerprint = $this->config->build_api_test_validation_fingerprint($activeApiKey);
        if ($fingerprint === '') {
            $this->config->clear_api_test_validation_state();
            return;
        }

        $this->config->set(NCWooConfig::OPTION_API_TEST_VALIDATION_FINGERPRINT, $fingerprint);
        $this->config->set(NCWooConfig::OPTION_API_TEST_VALIDATED_AT, $validatedAt);
    }

    /**
     * @return array<string,mixed>
     */
    private function build_api_test_event_payload(string $shopRef): array
    {
        $suffix = bin2hex(random_bytes(6));
        $cartRef = 'api-test-' . $shopRef . '-' . $suffix;
        $shopLocale = $this->resolve_wordpress_locale();
        $languageCode = $this->resolve_language_code($shopLocale);

        return [
            'event_id' => 'test-' . $suffix,
            'event_type' => 'cart.updated',
            'occurred_at' => gmdate('c'),
            'language' => $languageCode,
            'source' => [
                'platform' => 'woocommerce',
                'shop_id' => $shopRef,
                'shop_name' => get_bloginfo('name'),
                'language' => $languageCode,
            ],
            'cart' => [
                'id' => $cartRef,
                'uid' => $cartRef,
                'total' => 99.0,
                'items' => [
                    [
                        'product_id' => 999001,
                        'attribute_id' => 0,
                        'quantity' => 1,
                        'unit_price' => 99.0,
                        'name' => 'NeuroCheckout API Test Item',
                    ],
                ],
            ],
            'customer' => [
                'id' => 'api-test',
                'email' => 'apitest+' . $shopRef . '@neurocheckout.local',
                'first_name' => 'API',
                'last_name' => 'Test',
                'locale' => $shopLocale,
                'language' => $languageCode,
                'is_guest' => true,
            ],
            'context' => [
                'shop_locale' => $shopLocale,
                'shop_language' => $languageCode,
            ],
        ];
    }

    private function resolve_wordpress_locale(): string
    {
        if (function_exists('determine_locale')) {
            $locale = trim((string) determine_locale());
            if ($locale !== '') {
                return $locale;
            }
        }

        if (function_exists('get_locale')) {
            $locale = trim((string) get_locale());
            if ($locale !== '') {
                return $locale;
            }
        }

        return 'en_US';
    }

    private function resolve_language_code(string $locale): string
    {
        $normalized = strtolower(str_replace('_', '-', trim($locale)));
        $language = explode('-', $normalized, 2)[0] ?? '';

        return in_array($language, ['fr', 'en', 'es', 'de', 'it'], true) ? $language : 'en';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function is_requested_shop_matching(array $payload): bool
    {
        $requestedShopId = trim((string) ($payload['shop_id'] ?? ''));
        if ($requestedShopId === '') {
            return true;
        }

        $configured = trim($this->config->get_shop_external_id());
        if ($configured === '') {
            return true;
        }

        return hash_equals($configured, $requestedShopId);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,string>
     */
    private function resolve_history_window(array $payload): array
    {
        $untilTs = $this->parse_timestamp($payload['until'] ?? null);
        if ($untilTs === null) {
            $untilTs = time();
        }

        $sinceTs = $this->parse_timestamp($payload['since'] ?? null);
        if ($sinceTs === null) {
            $sinceTs = $untilTs - (self::DEFAULT_LOOKBACK_DAYS * 86400);
        }

        if ($sinceTs > $untilTs) {
            $tmp = $sinceTs;
            $sinceTs = $untilTs;
            $untilTs = $tmp;
        }

        return [
            'since_sql' => gmdate('Y-m-d H:i:s', $sinceTs),
            'until_sql' => gmdate('Y-m-d H:i:s', $untilTs),
            'since_iso' => gmdate('c', $sinceTs),
            'until_iso' => gmdate('c', $untilTs),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function resolve_history_limit(array $payload): int
    {
        $limit = (int) ($payload['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }

        return min(self::MAX_LIMIT, $limit);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function resolve_history_mode(array $payload): string
    {
        $mode = strtolower(trim((string) ($payload['mode'] ?? 'full')));
        return $mode === 'slim' ? 'slim' : 'full';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetch_order_rows(string $sinceSql, string $untilSql, int $limit, ?array $cursor): array
    {
        if (!function_exists('wc_get_orders')) {
            return [];
        }

        $sinceTs = strtotime($sinceSql . ' UTC') ?: 0;
        $untilTs = strtotime($untilSql . ' UTC') ?: 0;
        if ($sinceTs <= 0 || $untilTs <= 0) {
            return [];
        }

        $cursorDate = is_array($cursor) ? trim((string) ($cursor['d'] ?? '')) : '';
        $cursorId = is_array($cursor) ? (int) ($cursor['id'] ?? 0) : 0;
        $cursorTs = $cursorDate !== '' ? (strtotime($cursorDate . ' UTC') ?: 0) : 0;

        $target = max(1, $limit);
        $batchLimit = min(500, max(50, $target * 2));
        $statuses = $this->resolve_order_history_statuses();
        $rows = [];

        for ($page = 1; $page <= 50 && count($rows) < $target; $page++) {
            $result = wc_get_orders([
                'type' => 'shop_order',
                'status' => $statuses,
                'limit' => $batchLimit,
                'page' => $page,
                'paginate' => true,
                'orderby' => 'date_created',
                'order' => 'ASC',
                'date_created' => $sinceTs . '...' . $untilTs,
                'return' => 'objects',
            ]);

            $orders = [];
            $maxPages = 1;
            if (is_object($result) && isset($result->orders)) {
                $orders = is_array($result->orders) ? $result->orders : [];
                $maxPages = max(1, (int) ($result->max_num_pages ?? 1));
            } elseif (is_array($result)) {
                $orders = $result;
            }

            if (!$orders) {
                break;
            }

            foreach ($orders as $order) {
                if (!$order instanceof WC_Order) {
                    continue;
                }

                $row = $this->order_to_history_row($order);
                if (!$row) {
                    continue;
                }

                $orderTs = strtotime((string) $row['post_date_gmt'] . ' UTC') ?: 0;
                $orderId = (int) ($row['ID'] ?? 0);
                if ($orderTs <= 0 || $orderId <= 0) {
                    continue;
                }

                if ($cursorTs > 0 && ($orderTs < $cursorTs || ($orderTs === $cursorTs && $orderId <= $cursorId))) {
                    continue;
                }

                $rows[] = $row;
            }

            if ($page >= $maxPages) {
                break;
            }
        }

        usort($rows, static function (array $left, array $right): int {
            $leftDate = (string) ($left['post_date_gmt'] ?? '');
            $rightDate = (string) ($right['post_date_gmt'] ?? '');
            if ($leftDate === $rightDate) {
                return ((int) ($left['ID'] ?? 0)) <=> ((int) ($right['ID'] ?? 0));
            }

            return strcmp($leftDate, $rightDate);
        });

        return array_slice($rows, 0, $target);
    }

    /**
     * @return array<int,string>
     */
    private function resolve_order_history_statuses(): array
    {
        return ['processing', 'completed'];
    }

    /**
     * @return array{ID:int,post_date_gmt:string}|null
     */
    private function order_to_history_row(WC_Order $order): ?array
    {
        $orderId = (int) $order->get_id();
        if ($orderId <= 0) {
            return null;
        }

        $status = strtolower(trim((string) $order->get_status()));
        if (!in_array($status, ['processing', 'completed'], true)) {
            return null;
        }

        $created = $order->get_date_created();
        if (!$created instanceof WC_DateTime) {
            return null;
        }

        return [
            'ID' => $orderId,
            'post_date_gmt' => gmdate('Y-m-d H:i:s', $created->getTimestamp()),
        ];
    }

    private function parse_timestamp($value): ?int
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            $ts = (int) $raw;
            return $ts > 0 ? $ts : null;
        }

        $ts = strtotime($raw);
        return $ts === false ? null : $ts;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decode_cursor(string $cursor): ?array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (!is_string($decoded) || $decoded === '') {
            return null;
        }

        $payload = json_decode($decoded, true);
        if (!is_array($payload)) {
            return null;
        }

        $date = trim((string) ($payload['d'] ?? ''));
        $id = (int) ($payload['id'] ?? 0);
        if ($date === '' || $id <= 0) {
            return null;
        }

        return ['d' => $date, 'id' => $id];
    }

    private function encode_cursor(string $date, int $id): string
    {
        $json = wp_json_encode(['d' => $date, 'id' => $id]);
        if (!is_string($json) || $json === '') {
            return '';
        }

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function normalize_products($products): array
    {
        if (!is_array($products)) {
            return [];
        }

        $normalized = [];
        foreach ($products as $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = (int) ($item['product_id'] ?? $item['id_product'] ?? 0);
            $attributeId = (int) ($item['attribute_id'] ?? $item['id_product_attribute'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? $item['cart_quantity'] ?? 0);

            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            $normalized[] = [
                'product_id' => $productId,
                'attribute_id' => max(0, $attributeId),
                'quantity' => $quantity,
            ];
        }

        return $normalized;
    }

    private function is_truthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array<string,mixed>
     */
    private function validate_signed_cron_request(WP_REST_Request $request): array
    {
        $clientIp = $this->resolve_client_ip();
        if (!$this->is_allowed_cron_ip($clientIp)) {
            return ['success' => false, 'status' => 403, 'error' => 'Cron IP not allowed'];
        }

        $token = trim((string) ($request->get_param('token') ?? ''));
        $expectedToken = $this->config->get_string(NCWooConfig::OPTION_CRON_TOKEN);
        if ($token === '' || $expectedToken === '' || !hash_equals($expectedToken, $token)) {
            return ['success' => false, 'status' => 403, 'error' => 'Invalid cron token'];
        }

        $timestamp = (int) ($request->get_param('ts') ?? 0);
        $nonce = trim((string) ($request->get_param('nonce') ?? ''));
        $signature = trim((string) ($request->get_param('sig') ?? ''));
        if ($timestamp <= 0 || $nonce === '' || $signature === '') {
            return ['success' => false, 'status' => 403, 'error' => 'Missing security parameters'];
        }

        if (abs(time() - $timestamp) > 120) {
            return ['success' => false, 'status' => 403, 'error' => 'Expired signature'];
        }

        $apiKeyCandidates = array_values(array_filter([
            $this->config->get_api_key(),
            $this->config->get_valid_previous_api_key(),
        ]));
        if (!$apiKeyCandidates) {
            return ['success' => false, 'status' => 409, 'error' => 'Connector API key missing'];
        }

        $signed = $timestamp . '.' . $nonce . '.' . $token;
        $validSignature = false;
        foreach ($apiKeyCandidates as $candidate) {
            $expectedSignature = hash_hmac('sha256', $signed, (string) $candidate);
            if (hash_equals($expectedSignature, $signature)) {
                $validSignature = true;
                break;
            }
        }

        if (!$validSignature) {
            return ['success' => false, 'status' => 403, 'error' => 'Invalid signature'];
        }

        if (!$this->register_cron_nonce($nonce)) {
            return ['success' => false, 'status' => 409, 'error' => 'Replay detected'];
        }

        return ['success' => true, 'status' => 200, 'client_ip' => $clientIp];
    }

    private function register_cron_nonce(string $nonce): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'ncwoo_nonce';
        $now = time();
        $nonceKey = 'cron-' . hash('sha256', $nonce);

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE expires_at <= %d",
                $now
            )
        );

        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (nonce_key, expires_at) VALUES (%s, %d)",
                $nonceKey,
                $now + 120
            )
        );

        return $inserted === 1;
    }

    private function resolve_client_ip(): string
    {
        return $this->security->resolve_client_ip();
    }

    private function allow_public_telemetry_request(): bool
    {
        $clientIp = $this->resolve_client_ip();
        $key = 'ncwoo_pub_tel_' . hash('sha256', $clientIp);
        $state = get_transient($key);
        $count = is_numeric($state) ? (int) $state : 0;
        if ($count >= 120) {
            return false;
        }

        set_transient($key, $count + 1, 5 * MINUTE_IN_SECONDS);
        return true;
    }

    private function is_allowed_cron_ip(string $clientIp): bool
    {
        $rules = $this->parse_ip_rules($this->config->get_string(NCWooConfig::OPTION_CRON_ALLOWED_IPS));
        if (!$rules) {
            return true;
        }

        return $this->ip_matches_any_rule($clientIp, $rules);
    }

    /**
     * @return array<int,string>
     */
    private function parse_ip_rules(string $rawRules): array
    {
        $parts = preg_split('/[\s,;]+/', trim($rawRules));
        return array_values(array_filter(array_map('trim', is_array($parts) ? $parts : [])));
    }

    /**
     * @param array<int,string> $rules
     */
    private function ip_matches_any_rule(string $clientIp, array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($this->ip_matches_rule($clientIp, $rule)) {
                return true;
            }
        }

        return false;
    }

    private function ip_matches_rule(string $clientIp, string $rule): bool
    {
        if ($clientIp === '' || $rule === '') {
            return false;
        }

        if (strpos($rule, '/') === false) {
            return hash_equals($rule, $clientIp);
        }

        [$subnet, $prefix] = array_pad(explode('/', $rule, 2), 2, null);
        $prefix = is_numeric($prefix) ? (int) $prefix : -1;

        $ipBinary = @inet_pton($clientIp);
        $subnetBinary = @inet_pton((string) $subnet);
        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $maxBits = strlen($ipBinary) * 8;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($subnetBinary, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ipBinary[$fullBytes]) & $mask) === (ord($subnetBinary[$fullBytes]) & $mask);
    }

    private function generate_coupon_code(string $cartId): string
    {
        $base = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $cartId));
        if ($base === '') {
            $base = 'CART';
        }
        if (strlen($base) > 6) {
            $base = substr($base, -6);
        }

        for ($i = 0; $i < 6; $i++) {
            $suffix = strtoupper(bin2hex(random_bytes(3)));
            $code = 'NC-' . $base . '-' . $suffix;
            if (!$this->coupon_exists($code)) {
                return $code;
            }
        }

        throw new RuntimeException('Cannot generate unique coupon code');
    }

    private function coupon_exists(string $couponCode): bool
    {
        if (!function_exists('wc_get_coupon_id_by_code')) {
            return false;
        }

        return ((int) wc_get_coupon_id_by_code($couponCode)) > 0;
    }

    private function create_woocommerce_coupon(string $couponCode, float $discountPercent, string $customerEmail, int $expiresTs): int
    {
        if (!class_exists('WC_Coupon')) {
            return 0;
        }

        $coupon = new WC_Coupon();
        $coupon->set_code($couponCode);
        $coupon->set_discount_type('percent');
        $coupon->set_amount((string) round($discountPercent, 2));
        $coupon->set_individual_use(true);
        $coupon->set_usage_limit(1);
        $coupon->set_usage_limit_per_user(1);

        if (class_exists('WC_DateTime')) {
            $date = new WC_DateTime('@' . $expiresTs);
            $coupon->set_date_expires($date);
        }

        if ($customerEmail !== '' && method_exists($coupon, 'set_email_restrictions')) {
            $coupon->set_email_restrictions([$customerEmail]);
        }

        $couponId = $coupon->save();
        if (!is_int($couponId) || $couponId <= 0) {
            return 0;
        }

        return $couponId;
    }

    private function persist_coupon(
        string $requestUid,
        string $decisionId,
        string $actionId,
        string $cartId,
        string $customerEmail,
        int $ruleId,
        string $couponCode,
        float $discountPercent,
        string $cartFingerprint,
        string $recoveryUrl,
        string $expiresAt
    ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'ncwoo_coupon';
        $wpdb->insert(
            $table,
            [
                'request_uid' => $requestUid,
                'decision_id' => $decisionId,
                'action_id' => $actionId,
                'cart_id' => $cartId,
                'customer_email' => $customerEmail,
                'rule_id' => $ruleId,
                'coupon_code' => $couponCode,
                'discount_percent' => round($discountPercent, 2),
                'cart_fingerprint' => $cartFingerprint,
                'recovery_url' => $recoveryUrl,
                'expires_at' => $expiresAt,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%f', '%s', '%s', '%s']
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function get_existing_coupon(string $requestUid): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'ncwoo_coupon';
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT coupon_code, discount_percent, recovery_url, expires_at
                 FROM {$table}
                 WHERE request_uid = %s
                 LIMIT 1",
                $requestUid
            ),
            ARRAY_A
        );

        if (!is_array($row)) {
            return null;
        }

        return [
            'coupon_code' => (string) ($row['coupon_code'] ?? ''),
            'discount_percent' => (float) ($row['discount_percent'] ?? 0),
            'recovery_url' => (string) ($row['recovery_url'] ?? ''),
            'expires_at' => (string) ($row['expires_at'] ?? ''),
        ];
    }

    private function extract_coupon_from_target_url(string $targetUrl): string
    {
        $targetUrl = trim($targetUrl);
        if ($targetUrl === '') {
            return '';
        }

        $parts = wp_parse_url($targetUrl);
        if (!is_array($parts) || empty($parts['query'])) {
            return '';
        }

        parse_str((string) $parts['query'], $query);
        if (!is_array($query)) {
            return '';
        }

        $candidate = trim((string) ($query['coupon'] ?? ($query['coupon_code'] ?? '')));
        return $candidate;
    }

    private function resolve_customer(int $customerId, string $customerEmail): ?WP_User
    {
        if ($customerId > 0) {
            $user = get_user_by('id', $customerId);
            if ($user instanceof WP_User) {
                if ($customerEmail === '' || strtolower((string) $user->user_email) === strtolower($customerEmail)) {
                    return $user;
                }
            }
        }

        if ($customerEmail !== '') {
            $user = get_user_by('email', $customerEmail);
            if ($user instanceof WP_User) {
                return $user;
            }
        }

        return null;
    }

    private function response(bool $success, int $status, ?string $error = null, ?array $data = null): WP_REST_Response
    {
        $payload = [
            'success' => $success,
            'status' => $status,
            'error' => $error,
            'timestamp' => gmdate('Y-m-d H:i:s'),
        ];
        if (is_array($data)) {
            $payload['data'] = $data;
        }

        return new WP_REST_Response($payload, $status);
    }

    /**
     * @param array<string,mixed> $security
     */
    private function security_failure_response(array $security): WP_REST_Response
    {
        $status = (int) ($security['status'] ?? 403);
        $retryAfter = (int) ($security['retry_after_seconds'] ?? 0);
        $data = $retryAfter > 0 ? ['retry_after_seconds' => $retryAfter] : null;
        $response = $this->response(false, $status, (string) ($security['error'] ?? 'Forbidden'), $data);

        if ($retryAfter > 0) {
            $response->header('Retry-After', (string) $retryAfter);
        }

        return $response;
    }
}
