<?php

if (!defined('ABSPATH')) {
    exit;
}

final class NCWooCustomerJourneyService
{
    private const TABLE_SUFFIX = 'ncwoo_customer_journey_event';
    private const PUBLIC_TOKEN_HEADER = 'X-Neuro-Journey-Token';
    private const RATE_LIMIT_PREFIX = 'ncwoo_journey_rl_';
    private const CART_RUNTIME_SESSION_KEY = 'ncwoo_runtime_cart_id';
    private const MAX_ATTEMPTS = 8;

    private const EVENT_SUFFIXES = [
        'page_view',
        'product_view',
        'category_view',
        'cart_view',
        'add_to_cart_intent',
        'checkout_started',
        'checkout_step',
        'form_error',
        'performance',
        'exit_intent',
        'cart_snapshot',
        'order_completed',
    ];

    private const BROWSER_EVENT_SUFFIXES = [
        'page_view',
        'product_view',
        'category_view',
        'cart_view',
        'add_to_cart_intent',
        'checkout_started',
        'checkout_step',
        'form_error',
        'performance',
        'exit_intent',
    ];

    private const SENSITIVE_KEY_FRAGMENTS = [
        'authorization',
        'card',
        'cookie',
        'cvc',
        'cvv',
        'email',
        'password',
        'payment_method',
        'payment_token',
        'phone',
        'secret',
        'session_cookie',
        'token',
    ];

    private NCWooConfig $config;
    private NCWooDB $db;
    private NCWooHttpClient $http;
    private bool $tableEnsured = false;

    public function __construct(NCWooConfig $config, NCWooDB $db, NCWooHttpClient $http)
    {
        $this->config = $config;
        $this->db = $db;
        $this->http = $http;
    }

    public function register_routes(): void
    {
        register_rest_route('neurocheckout/v1', '/journey', [
            'methods' => 'POST',
            'callback' => [$this, 'endpoint_journey'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function enqueue_script(): void
    {
        if (!$this->should_load_tracker()) {
            return;
        }

        $context = $this->build_page_context();
        wp_enqueue_script(
            'ncwoo-customer-journey',
            plugins_url('assets/customer-journey-tracker.js', dirname(__DIR__) . '/neurocheckout-connector.php'),
            [],
            $this->module_version(),
            true
        );
        wp_localize_script(
            'ncwoo-customer-journey',
            'NCWooCustomerJourney',
            [
                'endpoint' => esc_url_raw(rest_url('neurocheckout/v1/journey')),
                'token' => $this->public_token(),
                'wpNonce' => wp_create_nonce('wp_rest'),
                'eventPrefix' => 'woocommerce.customer_journey.',
                'maxEventsPerPage' => 18,
                'slowPageMs' => 5000,
                'context' => $context,
            ]
        );
    }

    public function endpoint_journey(WP_REST_Request $request): WP_REST_Response
    {
        if (!$this->is_collection_ready()) {
            return $this->rest_response(false, 409, 'Connector is not ready for journey collection');
        }

        if (!$this->verify_public_token($request)) {
            return $this->rest_response(false, 403, 'Invalid journey token');
        }

        if (!$this->allow_public_request()) {
            $response = $this->rest_response(false, 429, 'Too many journey events');
            $response->header('Retry-After', '60');
            return $response;
        }

        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return $this->rest_response(false, 422, 'Invalid journey payload');
        }
        unset($payload['token'], $payload['_token'], $payload['_wpnonce'], $payload['wp_nonce']);

        $normalized = $this->normalize_browser_payload($payload);
        if ($normalized === null) {
            return $this->rest_response(false, 422, 'Unsupported journey payload');
        }

        $queued = $this->enqueue_payload($normalized);
        if (!$queued) {
            return $this->rest_response(false, 422, 'Unable to queue journey payload');
        }

        return $this->rest_response(true, 202, null, ['queued' => true]);
    }

    public function on_cart_mutation(): void
    {
        try {
            $payload = $this->build_cart_snapshot_payload('cart_mutation');
            if ($payload !== null) {
                $this->enqueue_payload($payload);
            }
        } catch (Throwable $e) {
            $this->safe_log('Customer journey cart snapshot failed: ' . $e->getMessage());
        }
    }

    public function on_cart_emptied(): void
    {
        try {
            $payload = $this->build_cart_snapshot_payload('cart_emptied');
            if ($payload !== null) {
                $this->enqueue_payload($payload);
            }
        } catch (Throwable $e) {
            $this->safe_log('Customer journey cart emptied snapshot failed: ' . $e->getMessage());
        }
    }

    public function on_order_processed($orderId): void
    {
        $this->enqueue_order_completed($orderId, 'checkout_order_processed');
    }

    public function on_order_completed($orderId): void
    {
        $this->enqueue_order_completed($orderId, 'order_status_completed');
    }

    /**
     * @return array<string,mixed>
     */
    public function process_queue($limit = 75, array $requestOptions = []): array
    {
        $startedAt = microtime(true);
        $processed = 0;
        $failed = 0;
        $limit = is_numeric($limit) ? (int) $limit : 75;
        $limit = max(1, min($limit, 250));

        try {
            if (!$this->is_collection_ready()) {
                return [
                    'success' => false,
                    'processed' => 0,
                    'failed' => 0,
                    'reason' => 'connector_not_ready',
                ];
            }

            $this->ensure_queue_table();
            $this->release_stuck_processing_events();

            global $wpdb;
            $table = $this->table_name();
            $now = gmdate('Y-m-d H:i:s');
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table}
                     WHERE status = 'pending'
                        OR (status = 'failed' AND (next_retry_at IS NULL OR next_retry_at <= %s))
                     ORDER BY created_at ASC
                     LIMIT %d",
                    $now,
                    $limit
                ),
                ARRAY_A
            );

            if (!is_array($rows) || !$rows) {
                return [
                    'success' => true,
                    'processed' => 0,
                    'failed' => 0,
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ];
            }

            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }

                $payload = json_decode((string) ($row['payload'] ?? ''), true);
                if (!is_array($payload)) {
                    $this->mark_dead($id, 'invalid_payload');
                    $failed++;
                    continue;
                }

                $attempts = (int) ($row['attempts'] ?? 0);
                $wpdb->update(
                    $table,
                    [
                        'status' => 'processing',
                        'last_attempt_at' => $now,
                    ],
                    ['id' => $id],
                    ['%s', '%s'],
                    ['%d']
                );

                $result = $this->http->send_customer_journey_event(
                    $payload,
                    array_merge($requestOptions, ['order_timeout' => true])
                );

                if (!empty($result['success'])) {
                    $wpdb->update(
                        $table,
                        [
                            'status' => 'sent',
                            'attempts' => $attempts + 1,
                            'next_retry_at' => null,
                            'last_attempt_at' => $now,
                            'last_error' => null,
                            'sent_at' => $now,
                        ],
                        ['id' => $id],
                        ['%s', '%d', '%s', '%s', '%s', '%s'],
                        ['%d']
                    );
                    $processed++;
                    continue;
                }

                $failed++;
                $this->mark_failure(
                    $id,
                    $attempts + 1,
                    (string) ($result['error'] ?? 'send_failed')
                );
            }

            $this->purge_old_events();
        } catch (Throwable $e) {
            $failed++;
            $this->safe_log('Customer journey queue processing failed: ' . $e->getMessage());
        }

        return [
            'success' => $failed === 0,
            'processed' => $processed,
            'failed' => $failed,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    private function enqueue_order_completed($orderId, string $hookName): void
    {
        try {
            $payload = $this->build_order_completed_payload((int) $orderId, $hookName);
            if ($payload !== null) {
                $this->enqueue_payload($payload);
            }
        } catch (Throwable $e) {
            $this->safe_log('Customer journey order snapshot failed: ' . $e->getMessage());
        }
    }

    private function should_load_tracker(): bool
    {
        if (!$this->is_collection_ready()) {
            return false;
        }
        if (is_admin() || wp_doing_ajax()) {
            return false;
        }
        if (function_exists('is_account_page') && is_account_page()) {
            return false;
        }
        if (function_exists('is_order_received_page') && is_order_received_page()) {
            return false;
        }
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received')) {
            return false;
        }
        return true;
    }

    private function is_collection_ready(): bool
    {
        $readiness = $this->config->get_execution_readiness();
        return !empty($readiness['ready']);
    }

    private function public_token(): string
    {
        $secret = $this->config->get_internal_secret();
        if ($secret === '') {
            $secret = wp_salt('auth');
        }

        return hash_hmac(
            'sha256',
            home_url('/') . '|' . $this->config->get_shop_external_id() . '|customer_journey',
            $secret
        );
    }

    private function verify_public_token(WP_REST_Request $request): bool
    {
        $token = trim((string) ($request->get_header(self::PUBLIC_TOKEN_HEADER) ?: $request->get_param('token')));
        if ($token === '') {
            $body = $request->get_json_params();
            if (is_array($body)) {
                $token = trim((string) ($body['token'] ?? $body['_token'] ?? ''));
            }
        }
        return $token !== '' && hash_equals($this->public_token(), $token);
    }

    private function allow_public_request(): bool
    {
        $ip = $this->client_ip();
        $key = self::RATE_LIMIT_PREFIX . md5($ip . '|' . home_url('/'));
        $count = (int) get_transient($key);
        if ($count >= 180) {
            return false;
        }
        set_transient($key, $count + 1, MINUTE_IN_SECONDS);
        return true;
    }

    private function client_ip(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        return preg_replace('/[^a-zA-Z0-9:.\\-]/', '', $ip) ?: 'unknown';
    }

    /**
     * @return array<string,mixed>
     */
    private function build_page_context(): array
    {
        $pageType = 'page';
        if (function_exists('is_product') && is_product()) {
            $pageType = 'product';
        } elseif (function_exists('is_product_category') && is_product_category()) {
            $pageType = 'category';
        } elseif (function_exists('is_cart') && is_cart()) {
            $pageType = 'cart';
        } elseif (function_exists('is_checkout') && is_checkout()) {
            $pageType = 'checkout';
        } elseif (function_exists('is_search') && is_search()) {
            $pageType = 'search';
        } elseif (function_exists('is_front_page') && is_front_page()) {
            $pageType = 'home';
        }

        $context = [
            'page_type' => $pageType,
            'url' => esc_url_raw($this->current_public_url_without_query()),
            'title' => wp_get_document_title(),
            'language' => $this->language_code(),
            'cart_id' => $this->resolve_runtime_cart_id(false),
        ];

        if ($pageType === 'product') {
            $product = $this->current_product();
            if ($product instanceof WC_Product) {
                $context['product'] = $this->product_summary($product);
            }
        }

        if ($pageType === 'category') {
            $term = get_queried_object();
            if ($term && isset($term->term_id)) {
                $context['category'] = [
                    'id' => (string) $term->term_id,
                    'name' => sanitize_text_field((string) ($term->name ?? '')),
                    'slug' => sanitize_title((string) ($term->slug ?? '')),
                ];
            }
        }

        return $context;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    private function normalize_browser_payload(array $payload): ?array
    {
        $eventType = $this->normalize_event_type($payload['event_type'] ?? '', self::BROWSER_EVENT_SUFFIXES);
        if ($eventType === null) {
            return null;
        }

        $eventId = $this->safe_identifier($payload['event_id'] ?? '');
        if ($eventId === '') {
            $eventId = wp_generate_uuid4();
        }

        $occurredAt = $this->safe_occurred_at($payload['occurred_at'] ?? '');
        $journey = $this->safe_map($payload['journey'] ?? [], 4, 40, true);
        $page = $this->safe_map($payload['page'] ?? [], 3, 30, true);
        $event = $this->safe_map($payload['event'] ?? [], 3, 30, true);

        return [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'occurred_at' => $occurredAt,
            'source' => $this->source_context(),
            'customer' => $this->current_customer_identity(),
            'cart' => [
                'id' => $this->safe_identifier($payload['cart_id'] ?? ($journey['cart_id'] ?? '')),
                'uid' => $this->safe_identifier($payload['cart_uid'] ?? ''),
            ],
            'journey' => $journey,
            'page' => $page,
            'event' => $event,
            'context' => [
                'origin' => 'browser',
                'collector' => 'woocommerce_customer_journey_tracker',
                'module_version' => $this->module_version(),
            ],
            'privacy' => [
                'contains_form_values' => false,
                'contains_payment_data' => false,
                'contains_raw_server_logs' => false,
                'contains_payment_provider_logs' => false,
                'browser_payload_redacted' => true,
            ],
        ];
    }

    private function build_cart_snapshot_payload(string $hookName): ?array
    {
        if (!$this->is_collection_ready() || !function_exists('WC') || !WC()->cart) {
            return null;
        }

        $cart = WC()->cart;
        $this->refresh_cart_totals($cart);
        $items = [];
        foreach ($cart->get_cart() as $line) {
            $quantity = (int) ($line['quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }
            $product = isset($line['data']) && $line['data'] instanceof WC_Product ? $line['data'] : null;
            $productId = (int) ($line['product_id'] ?? 0);
            $variationId = (int) ($line['variation_id'] ?? 0);
            if ($productId <= 0 && $product instanceof WC_Product) {
                $productId = (int) $product->get_id();
            }
            if ($productId <= 0) {
                continue;
            }

            $lineTotal = (float) ($line['line_total'] ?? 0) + (float) ($line['line_tax'] ?? 0);
            $items[] = [
                'product_id' => (string) $productId,
                'attribute_id' => $variationId > 0 ? (string) $variationId : null,
                'sku' => $product instanceof WC_Product ? $this->safe_text($product->get_sku(), 120) : null,
                'name' => $product instanceof WC_Product ? $this->safe_text($product->get_name(), 160) : null,
                'quantity' => $quantity,
                'unit_price' => $quantity > 0 ? round($lineTotal / $quantity, 2) : 0.0,
                'line_total' => round($lineTotal, 2),
                'category_name' => $this->product_category_names($productId),
                'product_url' => $product instanceof WC_Product ? esc_url_raw(get_permalink($product->get_id())) : null,
            ];
        }

        if (!$items && $hookName !== 'cart_emptied') {
            return null;
        }

        $cartId = $this->resolve_runtime_cart_id($hookName !== 'cart_emptied');
        if ($cartId === '') {
            $cartId = $this->resolve_session_customer_id_fallback();
        }
        if ($cartId === '') {
            $cartId = 'wc-cart-' . wp_generate_uuid4();
        }

        return [
            'event_id' => wp_generate_uuid4(),
            'event_type' => 'woocommerce.customer_journey.cart_snapshot',
            'occurred_at' => gmdate('c'),
            'source' => $this->source_context(),
            'customer' => $this->current_customer_identity(),
            'cart' => [
                'id' => $cartId,
                'uid' => 'wc_' . hash('sha256', $this->shop_external_id() . '|' . $cartId),
                'total' => $this->cart_total($cart),
                'currency_code' => get_woocommerce_currency(),
                'items' => $items,
                'item_count' => count($items),
                'coupon_codes' => $this->cart_coupon_codes($cart),
                'total_discounts' => $this->cart_discount_total($cart),
            ],
            'journey' => [
                'visitor_id' => $this->resolve_visitor_id(),
                'session_id' => $this->resolve_session_id(),
                'event' => [
                    'name' => 'cart_snapshot',
                    'hook' => $hookName,
                ],
            ],
            'context' => [
                'origin' => 'server',
                'collector' => 'woocommerce_customer_journey_tracker',
                'hook' => $hookName,
                'module_version' => $this->module_version(),
            ],
            'privacy' => [
                'contains_form_values' => false,
                'contains_payment_data' => false,
                'contains_raw_server_logs' => false,
                'contains_payment_provider_logs' => false,
                'raw_email_sent' => false,
            ],
        ];
    }

    private function build_order_completed_payload(int $orderId, string $hookName): ?array
    {
        if (!$this->is_collection_ready() || !function_exists('wc_get_order')) {
            return null;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return null;
        }

        $cartId = trim((string) $order->get_meta('_ncwoo_cart_id'));
        if ($cartId === '') {
            $cartId = $this->resolve_runtime_cart_id(false);
        }
        if ($cartId === '') {
            $cartId = (string) $orderId;
        }

        $items = [];
        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $product = $item->get_product();
            $productId = (int) $item->get_product_id();
            $variationId = (int) $item->get_variation_id();
            $quantity = (int) $item->get_quantity();
            $lineTotal = (float) $item->get_total() + (float) $item->get_total_tax();
            $items[] = [
                'product_id' => $productId > 0 ? (string) $productId : null,
                'attribute_id' => $variationId > 0 ? (string) $variationId : null,
                'sku' => $product instanceof WC_Product ? $this->safe_text($product->get_sku(), 120) : null,
                'name' => $this->safe_text($item->get_name(), 160),
                'quantity' => $quantity,
                'unit_price' => $quantity > 0 ? round($lineTotal / $quantity, 2) : 0.0,
                'line_total' => round($lineTotal, 2),
                'category_name' => $this->product_category_names($productId),
            ];
        }

        $email = trim((string) $order->get_billing_email());
        $discountTotal = round((float) $order->get_discount_total() + (float) $order->get_discount_tax(), 2);
        $couponCodes = array_values(array_filter(array_map('strval', $order->get_coupon_codes())));

        return [
            'event_id' => wp_generate_uuid4(),
            'event_type' => 'woocommerce.customer_journey.order_completed',
            'occurred_at' => gmdate('c'),
            'source' => $this->source_context(),
            'customer' => [
                'id' => $order->get_customer_id() > 0 ? (string) $order->get_customer_id() : null,
                'email_hash' => $this->email_hash($email),
                'masked_email' => $this->mask_email($email),
                'identity_confidence' => $email !== '' || $order->get_customer_id() > 0 ? 'known' : 'partial',
            ],
            'cart' => [
                'id' => $cartId,
                'uid' => 'wc_' . hash('sha256', $this->shop_external_id() . '|' . $cartId),
                'total' => round((float) $order->get_total(), 2),
                'currency_code' => $order->get_currency(),
                'items' => $items,
                'coupon_codes' => $couponCodes,
                'total_discounts' => $discountTotal,
            ],
            'order' => [
                'id' => (string) $orderId,
                'order_number' => (string) $order->get_order_number(),
                'cart_id' => $cartId,
                'cart_uid' => 'wc_' . hash('sha256', $this->shop_external_id() . '|' . $cartId),
                'status' => $order->get_status(),
                'total' => round((float) $order->get_total(), 2),
                'currency_code' => $order->get_currency(),
                'items' => $items,
                'coupon_codes' => $couponCodes,
                'total_discounts' => $discountTotal,
            ],
            'journey' => [
                'visitor_id' => $this->resolve_visitor_id(),
                'session_id' => $this->resolve_session_id(),
                'event' => [
                    'name' => 'order_completed',
                    'hook' => $hookName,
                ],
            ],
            'context' => [
                'origin' => 'server',
                'collector' => 'woocommerce_customer_journey_tracker',
                'hook' => $hookName,
                'module_version' => $this->module_version(),
            ],
            'privacy' => [
                'contains_form_values' => false,
                'contains_payment_data' => false,
                'contains_raw_server_logs' => false,
                'contains_payment_provider_logs' => false,
                'raw_email_sent' => false,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function enqueue_payload(array $payload): bool
    {
        global $wpdb;

        $this->ensure_queue_table();
        $eventType = $this->normalize_event_type($payload['event_type'] ?? '', self::EVENT_SUFFIXES);
        if ($eventType === null) {
            return false;
        }
        $payload['event_type'] = $eventType;
        $payload['occurred_at'] = $this->safe_occurred_at($payload['occurred_at'] ?? '');
        $payload['source'] = array_merge($this->source_context(), is_array($payload['source'] ?? null) ? $payload['source'] : []);

        $eventId = $this->safe_identifier($payload['event_id'] ?? '');
        if ($eventId === '') {
            $eventId = wp_generate_uuid4();
        }
        $payload['event_id'] = $eventId;

        $json = wp_json_encode($payload);
        if (!is_string($json) || $json === '') {
            return false;
        }

        $meta = $this->queue_meta($payload);
        $table = $this->table_name();
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table}
                    (event_id, event_type, visitor_id, session_id, cart_id, customer_ref, event_hash, payload, status, attempts, next_retry_at, last_error)
                 VALUES
                    (%s, %s, %s, %s, %s, %s, %s, %s, 'pending', 0, NULL, NULL)
                 ON DUPLICATE KEY UPDATE
                    event_type = VALUES(event_type),
                    visitor_id = VALUES(visitor_id),
                    session_id = VALUES(session_id),
                    cart_id = VALUES(cart_id),
                    customer_ref = VALUES(customer_ref),
                    event_hash = VALUES(event_hash),
                    payload = IF(status='sent', payload, VALUES(payload)),
                    status = IF(status='sent', status, 'pending'),
                    attempts = IF(status='sent', attempts, 0),
                    next_retry_at = IF(status='sent', next_retry_at, NULL),
                    last_error = IF(status='sent', last_error, NULL)",
                $eventId,
                $eventType,
                $meta['visitor_id'],
                $meta['session_id'],
                $meta['cart_id'],
                $meta['customer_ref'],
                $meta['event_hash'],
                $json
            )
        );

        return $wpdb->last_error === '';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{visitor_id:string|null,session_id:string|null,cart_id:string|null,customer_ref:string|null,event_hash:string}
     */
    private function queue_meta(array $payload): array
    {
        $journey = is_array($payload['journey'] ?? null) ? $payload['journey'] : [];
        $cart = is_array($payload['cart'] ?? null) ? $payload['cart'] : [];
        $customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
        $emailHash = $this->safe_identifier($customer['email_hash'] ?? '');
        $customerId = $this->safe_identifier($customer['id'] ?? '');
        $visitorId = $this->safe_identifier($journey['visitor_id'] ?? '');
        $sessionId = $this->safe_identifier($journey['session_id'] ?? '');
        $cartId = $this->safe_identifier($cart['id'] ?? '');
        $customerRef = null;
        if ($emailHash !== '') {
            $customerRef = 'email_hash:' . substr($emailHash, 0, 16);
        } elseif ($customerId !== '') {
            $customerRef = 'customer:' . substr($customerId, 0, 24);
        } elseif ($visitorId !== '') {
            $customerRef = 'visitor:' . substr($visitorId, 0, 16);
        }

        return [
            'visitor_id' => $visitorId !== '' ? $visitorId : null,
            'session_id' => $sessionId !== '' ? $sessionId : null,
            'cart_id' => $cartId !== '' ? $cartId : null,
            'customer_ref' => $customerRef,
            'event_hash' => hash('sha256', wp_json_encode([
                $payload['event_type'] ?? '',
                $visitorId,
                $sessionId,
                $cartId,
                $customerRef,
                $payload['occurred_at'] ?? '',
            ]) ?: ''),
        ];
    }

    private function ensure_queue_table(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        global $wpdb;
        $table = $this->table_name();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            $this->db->install();
        }
        $this->tableEnsured = true;
    }

    private function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    private function release_stuck_processing_events(): void
    {
        global $wpdb;
        $table = $this->table_name();
        $threshold = gmdate('Y-m-d H:i:s', time() - 300);
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET status = 'pending',
                     next_retry_at = NULL,
                     last_error = 'released_stale_processing'
                 WHERE status = 'processing'
                   AND COALESCE(last_attempt_at, updated_at, created_at) < %s",
                $threshold
            )
        );
    }

    private function mark_dead(int $id, string $reason): void
    {
        global $wpdb;
        $wpdb->update(
            $this->table_name(),
            [
                'status' => 'dead',
                'last_error' => substr($reason, 0, 255),
                'last_attempt_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d']
        );
    }

    private function mark_failure(int $id, int $attempts, string $error): void
    {
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->mark_dead($id, $error);
            return;
        }

        global $wpdb;
        $delay = min(3600, (int) pow(2, min($attempts, 10)) * 60);
        $wpdb->update(
            $this->table_name(),
            [
                'status' => 'failed',
                'attempts' => $attempts,
                'next_retry_at' => gmdate('Y-m-d H:i:s', time() + $delay),
                'last_attempt_at' => gmdate('Y-m-d H:i:s'),
                'last_error' => substr($error, 0, 255),
            ],
            ['id' => $id],
            ['%s', '%d', '%s', '%s', '%s'],
            ['%d']
        );
    }

    private function purge_old_events(): void
    {
        global $wpdb;
        $threshold = gmdate('Y-m-d H:i:s', time() - (7 * DAY_IN_SECONDS));
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table_name()} WHERE status IN ('sent','dead') AND created_at < %s",
                $threshold
            )
        );
    }

    /**
     * @param array<int,string> $allowedSuffixes
     */
    private function normalize_event_type($value, array $allowedSuffixes): ?string
    {
        $eventType = strtolower(trim((string) $value));
        if ($eventType === '') {
            return null;
        }
        $prefix = 'woocommerce.customer_journey.';
        $suffix = strpos($eventType, $prefix) === 0
            ? substr($eventType, strlen($prefix))
            : $eventType;
        $suffix = preg_replace('/[^a-z0-9_\\-]/', '', $suffix);
        if (!is_string($suffix) || !in_array($suffix, $allowedSuffixes, true)) {
            return null;
        }
        return $prefix . $suffix;
    }

    private function source_context(): array
    {
        return [
            'platform' => 'woocommerce',
            'shop_id' => $this->shop_external_id(),
            'shop_name' => get_bloginfo('name'),
            'language' => $this->language_code(),
            'site_url' => esc_url_raw(home_url('/')),
        ];
    }

    private function current_public_url_without_query(): string
    {
        $path = '';
        if (isset($GLOBALS['wp']) && is_object($GLOBALS['wp']) && !empty($GLOBALS['wp']->request)) {
            $path = (string) $GLOBALS['wp']->request;
        }
        return home_url($path !== '' ? '/' . ltrim($path, '/') : '/');
    }

    private function shop_external_id(): string
    {
        $shopId = $this->config->get_shop_external_id();
        if ($shopId !== '') {
            return $shopId;
        }
        return wp_parse_url(home_url('/'), PHP_URL_HOST) ?: 'woo-shop';
    }

    private function module_version(): string
    {
        $data = get_file_data(dirname(__DIR__) . '/neurocheckout-connector.php', ['Version' => 'Version'], 'plugin');
        return is_array($data) && !empty($data['Version']) ? (string) $data['Version'] : '1.0.0';
    }

    private function language_code(): string
    {
        $locale = function_exists('get_locale') ? (string) get_locale() : 'en_US';
        $parts = preg_split('/[_-]/', $locale);
        $language = strtolower((string) ($parts[0] ?? 'en'));
        return preg_match('/^[a-z]{2}$/', $language) ? $language : 'en';
    }

    private function safe_occurred_at($value): string
    {
        $text = trim((string) $value);
        if ($text !== '' && strtotime($text) !== false) {
            return gmdate('c', strtotime($text));
        }
        return gmdate('c');
    }

    private function safe_identifier($value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }
        $text = preg_replace('/[^a-zA-Z0-9:_@.\\-]/', '', $text);
        return is_string($text) ? substr($text, 0, 120) : '';
    }

    private function safe_text($value, int $limit = 300): ?string
    {
        $text = trim(wp_strip_all_tags((string) $value));
        if ($text === '') {
            return null;
        }
        return substr($text, 0, $limit);
    }

    private function safe_map($value, int $depth, int $maxItems, bool $scrubSensitive): array
    {
        if (!is_array($value) || $depth <= 0) {
            return [];
        }

        $output = [];
        $index = 0;
        foreach ($value as $key => $item) {
            if ($index >= $maxItems) {
                $output['_truncated'] = true;
                break;
            }
            $index++;
            $safeKey = substr(preg_replace('/[^a-zA-Z0-9_\\-]/', '', (string) $key) ?: 'item', 0, 80);
            if ($scrubSensitive && $this->is_sensitive_key($safeKey)) {
                continue;
            }
            if (is_array($item)) {
                $output[$safeKey] = $this->safe_map($item, $depth - 1, $maxItems, $scrubSensitive);
            } elseif (is_bool($item) || is_int($item) || is_float($item) || $item === null) {
                $output[$safeKey] = $item;
            } else {
                $output[$safeKey] = $this->safe_text($item, 500);
            }
        }

        return $output;
    }

    private function is_sensitive_key(string $key): bool
    {
        $normalized = strtolower(trim($key));
        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (strpos($normalized, $fragment) !== false) {
                return true;
            }
        }
        return false;
    }

    private function current_product(): ?WC_Product
    {
        global $product;
        if ($product instanceof WC_Product) {
            return $product;
        }
        $postId = get_the_ID();
        if ($postId > 0 && function_exists('wc_get_product')) {
            $resolved = wc_get_product($postId);
            return $resolved instanceof WC_Product ? $resolved : null;
        }
        return null;
    }

    private function product_summary(WC_Product $product): array
    {
        return [
            'id' => (string) $product->get_id(),
            'sku' => $this->safe_text($product->get_sku(), 120),
            'name' => $this->safe_text($product->get_name(), 160),
            'price' => (float) $product->get_price(),
            'category_name' => $this->product_category_names((int) $product->get_id()),
            'url' => esc_url_raw(get_permalink($product->get_id())),
        ];
    }

    private function product_category_names(int $productId): ?string
    {
        if ($productId <= 0) {
            return null;
        }
        $terms = wp_get_post_terms($productId, 'product_cat', ['fields' => 'names']);
        if (!is_array($terms) || !$terms) {
            return null;
        }
        $names = [];
        foreach ($terms as $name) {
            $safe = $this->safe_text($name, 80);
            if ($safe && !in_array($safe, $names, true)) {
                $names[] = $safe;
            }
        }
        return $names ? implode(' > ', array_slice($names, 0, 4)) : null;
    }

    private function refresh_cart_totals($cart): void
    {
        if ($cart && method_exists($cart, 'calculate_totals')) {
            try {
                $cart->calculate_totals();
            } catch (Throwable $e) {
                // Totals are best effort and must never block the store.
            }
        }
    }

    private function cart_total($cart): float
    {
        if (!$cart) {
            return 0.0;
        }
        try {
            return round((float) $cart->get_total('edit'), 2);
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    private function cart_discount_total($cart): float
    {
        if (!$cart) {
            return 0.0;
        }
        try {
            return round((float) $cart->get_discount_total() + (float) $cart->get_discount_tax(), 2);
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    private function cart_coupon_codes($cart): array
    {
        if (!$cart || !method_exists($cart, 'get_applied_coupons')) {
            return [];
        }
        return array_values(array_filter(array_map('strval', $cart->get_applied_coupons())));
    }

    private function resolve_runtime_cart_id(bool $createIfMissing): string
    {
        if (!function_exists('WC') || !WC()->session || !method_exists(WC()->session, 'get')) {
            return '';
        }

        $cartId = trim((string) WC()->session->get(self::CART_RUNTIME_SESSION_KEY));
        if ($cartId !== '') {
            return $cartId;
        }

        if (!$createIfMissing) {
            return '';
        }

        $cartId = 'nc-' . wp_generate_uuid4();
        try {
            WC()->session->set(self::CART_RUNTIME_SESSION_KEY, $cartId);
            return $cartId;
        } catch (Throwable $e) {
            return '';
        }
    }

    private function resolve_session_customer_id_fallback(): string
    {
        if (!function_exists('WC') || !WC()->session || !method_exists(WC()->session, 'get_customer_id')) {
            return '';
        }
        return trim((string) WC()->session->get_customer_id());
    }

    private function resolve_current_customer_email(): string
    {
        $email = '';
        if (function_exists('WC') && WC()->customer && method_exists(WC()->customer, 'get_email')) {
            $email = trim((string) WC()->customer->get_email());
        }
        if ($email === '') {
            $userId = get_current_user_id();
            if ($userId > 0) {
                $user = get_userdata($userId);
                if ($user && !empty($user->user_email)) {
                    $email = trim((string) $user->user_email);
                }
            }
        }
        return $email;
    }

    /**
     * @return array<string,mixed>
     */
    private function current_customer_identity(): array
    {
        $email = $this->resolve_current_customer_email();
        $customerId = (int) get_current_user_id();
        $emailHash = $this->email_hash($email);

        return [
            'id' => $customerId > 0 ? (string) $customerId : null,
            'email_hash' => $emailHash,
            'masked_email' => $this->mask_email($email),
            'cart_customer_id' => $customerId > 0 ? (string) $customerId : null,
            'identity_confidence' => $emailHash !== null || $customerId > 0 ? 'known' : 'anonymous',
        ];
    }

    private function email_hash(string $email): ?string
    {
        $email = strtolower(trim($email));
        if ($email === '' || !is_email($email)) {
            return null;
        }
        return hash('sha256', $email);
    }

    private function mask_email(string $email): ?string
    {
        $email = strtolower(trim($email));
        if ($email === '' || !is_email($email)) {
            return null;
        }
        [$local, $domain] = explode('@', $email, 2);
        $visible = strlen($local) <= 2 ? substr($local, 0, 1) : substr($local, 0, 2);
        return $visible . '***@' . $domain;
    }

    private function resolve_visitor_id(): string
    {
        $cookie = trim((string) ($_COOKIE['ncwoo_journey_visitor'] ?? ''));
        if ($cookie !== '') {
            return $this->safe_identifier($cookie);
        }
        return '';
    }

    private function resolve_session_id(): string
    {
        $cookie = trim((string) ($_COOKIE['ncwoo_journey_session'] ?? ''));
        if ($cookie !== '') {
            return $this->safe_identifier($cookie);
        }
        return '';
    }

    private function rest_response(bool $success, int $status, ?string $error = null, ?array $data = null): WP_REST_Response
    {
        $payload = ['success' => $success];
        if ($error !== null) {
            $payload['error'] = $error;
        }
        if ($data !== null) {
            $payload['data'] = $data;
        }
        return new WP_REST_Response($payload, $status);
    }

    private function safe_log(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[NeuroCheckout Woo] ' . $message);
        }
    }
}
