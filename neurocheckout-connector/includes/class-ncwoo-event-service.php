<?php

if (!defined('ABSPATH')) {
    exit;
}

final class NCWooEventService
{
    private const MAX_RETRIES = 8;
    private const CART_SNAPSHOT_VERSION = 1;
    private const CART_RUNTIME_SESSION_KEY = 'ncwoo_runtime_cart_id';
    private const CART_RECOVERY_COOKIE_KEY = 'ncwoo_recovery_cart_id';
    private const TELEMETRY_EVENT_TYPES = [
        'woocommerce.checkout.performance',
        'woocommerce.checkout.js_error',
        'woocommerce.checkout.request_anomaly',
        'woocommerce.checkout.friction_snapshot',
        'woocommerce.checkout.shipping_cost_snapshot',
        'woocommerce.payment.failed',
        'woocommerce.subscription.renewal_failed',
        'woocommerce.connector.runtime_error',
    ];

    private NCWooConfig $config;
    private NCWooDB $db;
    private NCWooHttpClient $http;

    public function __construct(NCWooConfig $config, NCWooDB $db, NCWooHttpClient $http)
    {
        $this->config = $config;
        $this->db = $db;
        $this->http = $http;
    }

    public function on_cart_mutation(): void
    {
        $payload = $this->build_cart_event_snapshot('cart.updated', null);
        if ($payload === null) {
            if (function_exists('WC') && WC()->cart && WC()->cart->is_empty()) {
                $this->on_cart_emptied();
            }
            return;
        }

        $this->upsert_cart_event($payload);
    }

    public function on_cart_emptied(): void
    {
        if (!empty($GLOBALS['ncwoo_suppress_cart_cleared_event']) || $this->is_customer_logout_request()) {
            return;
        }

        $payload = $this->build_cart_event_snapshot('cart.cleared', 'cart_cleared');
        if ($payload === null) {
            return;
        }

        $this->upsert_cart_event($payload);
        $this->clear_runtime_cart_id();
    }

    private function is_customer_logout_request(): bool
    {
        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if ($requestUri !== '' && strpos($requestUri, 'customer-logout') !== false) {
            return true;
        }

        $action = isset($_GET['customer-logout']) ? (string) wp_unslash($_GET['customer-logout']) : '';
        return $action !== '';
    }

    public function on_order_processed(int $orderId): void
    {
        $this->dispatch_order_event($orderId);
        $this->clear_runtime_cart_id();
    }

    public function on_order_completed(int $orderId): void
    {
        $this->dispatch_order_event($orderId);
        $this->clear_runtime_cart_id();
    }

    /**
     * @param mixed $order
     */
    public function attach_cart_context_to_order($order): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }

        $cartId = $this->resolve_runtime_cart_id(false);
        if ($cartId === '') {
            $cartId = $this->resolve_session_customer_id_fallback();
        }
        if ($cartId === '') {
            return;
        }

        try {
            $order->update_meta_data('_ncwoo_cart_id', $cartId);
            $order->save();
        } catch (Throwable $e) {
            // Best effort only: do not block checkout flow.
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function process_queue(bool $isCronTest = false, bool $isDebugForceRun = false): array
    {
        $started = microtime(true);
        $processed = 0;
        $failed = 0;

        if (!$this->acquire_queue_lock()) {
            return [
                'success' => false,
                'status_code' => 429,
                'error' => 'cron_already_running',
                'processed_events' => 0,
                'execution_time_ms' => 0,
            ];
        }

        try {
            $readiness = $this->config->get_execution_readiness();
            if (empty($readiness['ready'])) {
                $elapsedMs = (int) round((microtime(true) - $started) * 1000);
                $reason = (string) ($readiness['reason'] ?? 'execution_not_ready');
                $this->log_cron_run('error', 0, $elapsedMs, 'reason=' . $reason);

                return [
                    'success' => false,
                    'status_code' => 422,
                    'error' => $reason,
                    'processed_events' => 0,
                    'execution_time_ms' => $elapsedMs,
                    'test_mode' => $isCronTest,
                    'debug_force' => $isDebugForceRun,
                ];
            }

            if (!$this->is_circuit_available()) {
                $elapsedMs = (int) round((microtime(true) - $started) * 1000);
                $this->log_cron_run('blocked', 0, $elapsedMs, 'reason=circuit_breaker_open');

                return [
                    'success' => false,
                    'status_code' => 423,
                    'error' => 'circuit_breaker_open',
                    'processed_events' => 0,
                    'execution_time_ms' => $elapsedMs,
                    'test_mode' => $isCronTest,
                    'debug_force' => $isDebugForceRun,
                ];
            }

            $requestOptions = $isCronTest ? ['is_cron_test' => true] : [];
            $this->release_stuck_processing_events();
            $processed += $this->process_cart_queue_batch(100, $failed, $requestOptions);
            $processed += $this->process_order_queue_batch(30, $failed, $requestOptions);
            $processed += $this->process_telemetry_queue_batch(60, $requestOptions);

            $elapsedMs = (int) round((microtime(true) - $started) * 1000);
            $this->log_cron_run($failed > 0 ? 'error' : 'success', $processed, $elapsedMs, $failed > 0 ? 'failed_events=' . $failed : null);

            if (($isCronTest || $isDebugForceRun) && $processed <= 0 && $failed <= 0) {
                return [
                    'success' => false,
                    'status_code' => 422,
                    'error' => 'no_pending_events',
                    'processed_events' => 0,
                    'execution_time_ms' => $elapsedMs,
                    'test_mode' => $isCronTest,
                    'debug_force' => $isDebugForceRun,
                ];
            }

            return [
                'success' => $failed <= 0,
                'status_code' => $failed > 0 ? 500 : 200,
                'error' => $failed > 0 ? 'failed_events=' . $failed : '',
                'processed_events' => $processed,
                'failed_events' => $failed,
                'execution_time_ms' => $elapsedMs,
                'test_mode' => $isCronTest,
                'debug_force' => $isDebugForceRun,
            ];
        } catch (Throwable $e) {
            $elapsedMs = (int) round((microtime(true) - $started) * 1000);
            $this->log_cron_run('error', $processed, $elapsedMs, $e->getMessage());

            return [
                'success' => false,
                'status_code' => 500,
                'error' => $e->getMessage(),
                'processed_events' => $processed,
                'failed_events' => $failed,
                'execution_time_ms' => $elapsedMs,
                'test_mode' => $isCronTest,
                'debug_force' => $isDebugForceRun,
            ];
        } finally {
            $this->release_queue_lock();
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public function build_order_payload_for_sync(int $orderId): ?array
    {
        return $this->build_order_event_payload($orderId);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function enqueue_public_telemetry(array $payload): bool
    {
        return $this->enqueue_telemetry_event($payload, 'browser');
    }

    public function on_payment_failed($orderId): void
    {
        $orderId = is_numeric($orderId) ? (int) $orderId : 0;
        if ($orderId <= 0 || !function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }

        $eventKey = 'payment_failed:' . $orderId . ':' . gmdate('YmdHi');
        $this->enqueue_telemetry_event(
            $this->build_telemetry_payload(
                'woocommerce.payment.failed',
                [
                    'order_total' => round((float) $order->get_total(), 2),
                    'payment_method_present' => trim((string) $order->get_payment_method()) !== '',
                ],
                [
                    'order_id' => (string) $orderId,
                    'order_status' => $order->get_status(),
                    'payment_method' => trim((string) $order->get_payment_method()) ?: null,
                    'checkout_phase' => 'payment',
                ],
                $eventKey
            ),
            'server'
        );
    }

    /**
     * @param mixed $subscription
     * @param mixed $lastOrder
     */
    public function on_subscription_renewal_payment_failed($subscription, $lastOrder = null): void
    {
        $subscriptionId = is_object($subscription) && method_exists($subscription, 'get_id')
            ? (int) $subscription->get_id()
            : (is_numeric($subscription) ? (int) $subscription : 0);
        $orderId = is_object($lastOrder) && method_exists($lastOrder, 'get_id')
            ? (int) $lastOrder->get_id()
            : (is_numeric($lastOrder) ? (int) $lastOrder : 0);

        $total = 0.0;
        $paymentMethod = null;
        if (is_object($lastOrder) && method_exists($lastOrder, 'get_total')) {
            $total = (float) $lastOrder->get_total();
        }
        if (is_object($lastOrder) && method_exists($lastOrder, 'get_payment_method')) {
            $paymentMethod = trim((string) $lastOrder->get_payment_method()) ?: null;
        }
        if ($paymentMethod === null && is_object($subscription) && method_exists($subscription, 'get_payment_method')) {
            $paymentMethod = trim((string) $subscription->get_payment_method()) ?: null;
        }

        $eventKey = 'subscription_renewal_failed:' . $subscriptionId . ':' . $orderId . ':' . gmdate('YmdHi');
        $this->enqueue_telemetry_event(
            $this->build_telemetry_payload(
                'woocommerce.subscription.renewal_failed',
                [
                    'renewal_order_total' => round(max(0.0, $total), 2),
                    'payment_method_present' => $paymentMethod !== null,
                ],
                [
                    'subscription_id' => $subscriptionId > 0 ? (string) $subscriptionId : null,
                    'renewal_order_id' => $orderId > 0 ? (string) $orderId : null,
                    'payment_method' => $paymentMethod,
                    'checkout_phase' => 'subscription_renewal',
                ],
                $eventKey
            ),
            'server'
        );
    }

    /**
     * @param mixed $cart
     */
    public function maybe_enqueue_shipping_cost_snapshot($cart): void
    {
        static $lastSnapshotKey = '';

        if (!is_object($cart)) {
            return;
        }

        $subtotal = 0.0;
        if (method_exists($cart, 'get_cart_contents_total')) {
            $subtotal += $this->to_float($cart->get_cart_contents_total());
        }
        if (method_exists($cart, 'get_cart_contents_tax')) {
            $subtotal += $this->to_float($cart->get_cart_contents_tax());
        }

        $shippingTotal = 0.0;
        if (method_exists($cart, 'get_shipping_total')) {
            $shippingTotal += $this->to_float($cart->get_shipping_total());
        }
        if (method_exists($cart, 'get_shipping_tax')) {
            $shippingTotal += $this->to_float($cart->get_shipping_tax());
        }

        if ($subtotal <= 0.0 || $shippingTotal <= 0.0) {
            return;
        }

        $cartId = $this->resolve_runtime_cart_id(false);
        $bucket = (string) intdiv(time(), 300);
        $snapshotKey = hash(
            'sha256',
            implode('|', [$this->config->get_shop_external_id(), $cartId, round($subtotal, 2), round($shippingTotal, 2), $bucket])
        );
        if ($snapshotKey === $lastSnapshotKey) {
            return;
        }
        $lastSnapshotKey = $snapshotKey;

        $this->enqueue_telemetry_event(
            $this->build_telemetry_payload(
                'woocommerce.checkout.shipping_cost_snapshot',
                [
                    'cart_subtotal' => round($subtotal, 2),
                    'shipping_total' => round($shippingTotal, 2),
                    'shipping_ratio' => round($shippingTotal / max($subtotal, 0.01), 4),
                    'required_field_count' => $this->count_required_checkout_fields(),
                    'payment_method_count' => $this->count_available_payment_methods(),
                    'shipping_method_count' => $this->count_available_shipping_methods(),
                ],
                [
                    'cart_id_hash' => $cartId !== '' ? hash('sha256', $cartId) : null,
                    'checkout_phase' => 'shipping',
                ],
                'shipping_cost:' . $snapshotKey
            ),
            'server'
        );
    }

    /**
     * @param int $failedCount by-ref
     */
    private function process_cart_queue_batch(int $limit, int &$failedCount, array $requestOptions = []): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'ncwoo_event';
        $now = gmdate('Y-m-d H:i:s');

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE status = 'pending'
                    OR (status = 'failed' AND (next_retry_at IS NULL OR next_retry_at <= %s))
                 ORDER BY priority DESC, created_at ASC
                 LIMIT %d",
                $now,
                max(1, $limit)
            ),
            ARRAY_A
        );

        if (!is_array($rows) || !$rows) {
            return 0;
        }

        $processed = 0;
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $payload = json_decode((string) ($row['payload'] ?? ''), true);
            if (!is_array($payload)) {
                $this->mark_cart_event_dead($id, 'invalid_payload');
                $failedCount++;
                continue;
            }

            if ($this->is_minimal_cart_snapshot($payload)) {
                $expandedPayload = $this->build_cart_event_payload_from_snapshot($payload);
                if ($expandedPayload === null) {
                    $this->mark_cart_event_dead($id, 'snapshot_hydration_failed');
                    $failedCount++;
                    continue;
                }

                $payload = $expandedPayload;
                $this->refresh_cart_event_payload($id, $payload);
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

            $result = $this->http->send_cart_event($payload, $requestOptions);
            if (!empty($result['success'])) {
                $this->record_circuit_success();
                $eventType = strtolower(trim((string) ($payload['event_type'] ?? '')));
                $wpdb->update(
                    $table,
                    [
                        'status' => $eventType === 'cart.cleared' ? 'cleared' : 'sent',
                        'attempts' => $attempts + 1,
                        'next_retry_at' => null,
                    ],
                    ['id' => $id],
                    ['%s', '%d', '%s'],
                    ['%d']
                );
                $processed++;
                continue;
            }

            $failedCount++;
            $this->record_circuit_failure();
            $this->mark_cart_event_failure($id, $attempts + 1);
            if (!$this->is_circuit_available()) {
                break;
            }
        }

        $this->purge_old_cart_events();

        return $processed;
    }

    /**
     * @param int $failedCount by-ref
     */
    private function process_order_queue_batch(int $limit, int &$failedCount, array $requestOptions = []): int
    {
        global $wpdb;

        $this->ensure_order_last_attempt_column();

        $table = $wpdb->prefix . 'ncwoo_order_event';
        $now = gmdate('Y-m-d H:i:s');

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE status = 'pending'
                    OR (status = 'failed' AND (next_retry_at IS NULL OR next_retry_at <= %s))
                 ORDER BY created_at ASC
                 LIMIT %d",
                $now,
                max(1, $limit)
            ),
            ARRAY_A
        );

        if (!is_array($rows) || !$rows) {
            return 0;
        }

        $processed = 0;
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $payload = json_decode((string) ($row['payload'] ?? ''), true);
            if (!is_array($payload)) {
                $this->mark_order_event_dead($id, 'invalid_payload');
                $failedCount++;
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

            $result = $this->http->send_order_event($payload, array_merge($requestOptions, ['order_timeout' => true]));
            if (!empty($result['success'])) {
                $this->record_circuit_success();
                $wpdb->update(
                    $table,
                    [
                        'status' => 'sent',
                        'attempts' => $attempts + 1,
                        'sent_at' => $now,
                        'next_retry_at' => null,
                        'last_error' => null,
                        'last_attempt_at' => $now,
                    ],
                    ['id' => $id],
                    ['%s', '%d', '%s', '%s', '%s', '%s'],
                    ['%d']
                );
                $processed++;
                continue;
            }

            $failedCount++;
            $this->record_circuit_failure();
            $this->mark_order_event_failure($id, $attempts + 1, (string) ($result['error'] ?? 'send_failed'));
            if (!$this->is_circuit_available()) {
                break;
            }
        }

        return $processed;
    }

    private function process_telemetry_queue_batch(int $limit, array $requestOptions = []): int
    {
        global $wpdb;

        $this->ensure_telemetry_queue_table();

        $table = $wpdb->prefix . 'ncwoo_telemetry_event';
        $now = gmdate('Y-m-d H:i:s');

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE status = 'pending'
                    OR (status = 'failed' AND (next_retry_at IS NULL OR next_retry_at <= %s))
                 ORDER BY created_at ASC
                 LIMIT %d",
                $now,
                max(1, $limit)
            ),
            ARRAY_A
        );

        if (!is_array($rows) || !$rows) {
            return 0;
        }

        $processed = 0;
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $payload = json_decode((string) ($row['payload'] ?? ''), true);
            if (!is_array($payload)) {
                $this->mark_telemetry_event_dead($id, 'invalid_payload');
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

            $result = $this->http->send_telemetry_event(
                $payload,
                array_merge($requestOptions, ['order_timeout' => true])
            );
            if (!empty($result['success'])) {
                $wpdb->update(
                    $table,
                    [
                        'status' => 'sent',
                        'attempts' => $attempts + 1,
                        'sent_at' => $now,
                        'next_retry_at' => null,
                        'last_error' => null,
                        'last_attempt_at' => $now,
                    ],
                    ['id' => $id],
                    ['%s', '%d', '%s', '%s', '%s', '%s'],
                    ['%d']
                );
                $processed++;
                continue;
            }

            $this->mark_telemetry_event_failure(
                $id,
                $attempts + 1,
                (string) ($result['error'] ?? 'send_failed')
            );
        }

        $this->purge_old_telemetry_events();

        return $processed;
    }

    private function purge_old_cart_events(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ncwoo_event';
        $threshold = gmdate('Y-m-d H:i:s', time() - (7 * 86400));
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE status IN ('sent','cleared') AND created_at < %s",
                $threshold
            )
        );
    }

    private function purge_old_telemetry_events(): void
    {
        global $wpdb;

        $this->ensure_telemetry_queue_table();

        $table = $wpdb->prefix . 'ncwoo_telemetry_event';
        $threshold = gmdate('Y-m-d H:i:s', time() - (7 * 86400));
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE status IN ('sent','dead') AND created_at < %s",
                $threshold
            )
        );
    }

    private function release_stuck_processing_events(): int
    {
        global $wpdb;

        $this->ensure_order_last_attempt_column();

        $threshold = gmdate('Y-m-d H:i:s', time() - ($this->stale_processing_minutes() * 60));
        $released = 0;

        $cartTable = $wpdb->prefix . 'ncwoo_event';
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$cartTable}
                 SET status = 'pending',
                     next_retry_at = NULL
                 WHERE status = 'processing'
                   AND (last_attempt_at IS NULL OR last_attempt_at < %s)",
                $threshold
            )
        );
        $released += (int) $wpdb->rows_affected;

        $orderTable = $wpdb->prefix . 'ncwoo_order_event';
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$orderTable}
                 SET status = 'pending',
                     next_retry_at = NULL,
                     last_error = 'released_stale_processing'
                 WHERE status = 'processing'
                   AND COALESCE(last_attempt_at, updated_at, created_at) < %s",
                $threshold
            )
        );
        $released += (int) $wpdb->rows_affected;

        $this->ensure_telemetry_queue_table();
        $telemetryTable = $wpdb->prefix . 'ncwoo_telemetry_event';
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$telemetryTable}
                 SET status = 'pending',
                     next_retry_at = NULL,
                     last_error = 'released_stale_processing'
                 WHERE status = 'processing'
                   AND COALESCE(last_attempt_at, updated_at, created_at) < %s",
                $threshold
            )
        );
        $released += (int) $wpdb->rows_affected;

        return $released;
    }

    private function ensure_order_last_attempt_column(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'ncwoo_order_event';
        $column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'last_attempt_at'));
        if (is_string($column) && $column !== '') {
            return;
        }

        $wpdb->query("ALTER TABLE {$table} ADD COLUMN last_attempt_at DATETIME NULL AFTER next_retry_at");
    }

    private function stale_processing_minutes(): int
    {
        $configured = (int) get_option('ncwoo_stale_processing_minutes', 3);
        return max(1, min(60, $configured));
    }

    private function mark_cart_event_dead(int $id, string $reason): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ncwoo_event';
        $wpdb->update(
            $table,
            [
                'status' => 'dead',
                'next_retry_at' => null,
                'last_attempt_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function refresh_cart_event_payload(int $id, array $payload): void
    {
        global $wpdb;

        $json = wp_json_encode($payload);
        if (!is_string($json) || $json === '') {
            return;
        }

        $table = $wpdb->prefix . 'ncwoo_event';
        $wpdb->update(
            $table,
            [
                'event_hash' => hash('sha256', $json),
                'payload' => $json,
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );
    }

    private function mark_cart_event_failure(int $id, int $attempts): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ncwoo_event';

        if ($attempts >= self::MAX_RETRIES) {
            $wpdb->update(
                $table,
                [
                    'status' => 'dead',
                    'attempts' => $attempts,
                    'next_retry_at' => null,
                    'last_attempt_at' => gmdate('Y-m-d H:i:s'),
                ],
                ['id' => $id],
                ['%s', '%d', '%s', '%s'],
                ['%d']
            );
            return;
        }

        $delaySeconds = (int) min(3600, pow(2, max(1, $attempts)) * 15);
        $nextRetry = gmdate('Y-m-d H:i:s', time() + $delaySeconds);

        $wpdb->update(
            $table,
            [
                'status' => 'failed',
                'attempts' => $attempts,
                'next_retry_at' => $nextRetry,
                'last_attempt_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $id],
            ['%s', '%d', '%s', '%s'],
            ['%d']
        );
    }

    private function mark_order_event_dead(int $id, string $reason): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ncwoo_order_event';
        $wpdb->update(
            $table,
            [
                'status' => 'dead',
                'next_retry_at' => null,
                'last_error' => $reason,
                'last_attempt_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );
    }

    private function mark_order_event_failure(int $id, int $attempts, string $error): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ncwoo_order_event';

        if ($attempts >= self::MAX_RETRIES) {
            $wpdb->update(
                $table,
                [
                    'status' => 'dead',
                    'attempts' => $attempts,
                    'next_retry_at' => null,
                    'last_error' => $error,
                    'last_attempt_at' => gmdate('Y-m-d H:i:s'),
                ],
                ['id' => $id],
                ['%s', '%d', '%s', '%s', '%s'],
                ['%d']
            );
            return;
        }

        $delaySeconds = (int) min(3600, pow(2, max(1, $attempts)) * 15);
        $nextRetry = gmdate('Y-m-d H:i:s', time() + $delaySeconds);

        $wpdb->update(
            $table,
            [
                'status' => 'failed',
                'attempts' => $attempts,
                'next_retry_at' => $nextRetry,
                'last_error' => $error,
                'last_attempt_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $id],
            ['%s', '%d', '%s', '%s', '%s'],
            ['%d']
        );
    }

    private function mark_telemetry_event_dead(int $id, string $reason): void
    {
        global $wpdb;

        $this->ensure_telemetry_queue_table();

        $table = $wpdb->prefix . 'ncwoo_telemetry_event';
        $wpdb->update(
            $table,
            [
                'status' => 'dead',
                'next_retry_at' => null,
                'last_error' => substr($reason, 0, 255),
                'last_attempt_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );
    }

    private function mark_telemetry_event_failure(int $id, int $attempts, string $error): void
    {
        global $wpdb;

        $this->ensure_telemetry_queue_table();

        $table = $wpdb->prefix . 'ncwoo_telemetry_event';
        $sanitizedError = substr($error !== '' ? $error : 'send_failed', 0, 255);

        if ($attempts >= self::MAX_RETRIES) {
            $wpdb->update(
                $table,
                [
                    'status' => 'dead',
                    'attempts' => $attempts,
                    'next_retry_at' => null,
                    'last_error' => $sanitizedError,
                    'last_attempt_at' => gmdate('Y-m-d H:i:s'),
                ],
                ['id' => $id],
                ['%s', '%d', '%s', '%s', '%s'],
                ['%d']
            );
            return;
        }

        $delaySeconds = (int) min(3600, pow(2, max(1, $attempts)) * 15);
        $nextRetry = gmdate('Y-m-d H:i:s', time() + $delaySeconds);

        $wpdb->update(
            $table,
            [
                'status' => 'failed',
                'attempts' => $attempts,
                'next_retry_at' => $nextRetry,
                'last_error' => $sanitizedError,
                'last_attempt_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $id],
            ['%s', '%d', '%s', '%s', '%s'],
            ['%d']
        );
    }

    private function dispatch_order_event(int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }

        $payload = $this->build_order_event_payload($orderId);
        if ($payload === null) {
            return;
        }

        $result = $this->http->send_order_event($payload, ['order_timeout' => true]);
        if (!empty($result['success'])) {
            return;
        }

        $this->upsert_order_event($payload, (string) ($result['error'] ?? 'send_failed'));
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function upsert_cart_event(array $payload): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'ncwoo_event';
        $cartId = (string) ($payload['cart']['id'] ?? '');
        if ($cartId === '') {
            return;
        }

        $eventType = strtolower(trim((string) ($payload['event_type'] ?? '')));
        $queueCartId = $cartId;

        if ($eventType === 'cart.cleared') {
            $pendingUpdatedId = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id
                     FROM {$table}
                     WHERE cart_id = %s
                       AND status IN ('pending', 'failed', 'processing')
                       AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.event_type')) = 'cart.updated'
                     ORDER BY id DESC
                     LIMIT 1",
                    $cartId
                )
            );

            if ($pendingUpdatedId > 0) {
                $eventId = trim((string) ($payload['event_id'] ?? ''));
                if ($eventId === '') {
                    $eventId = wp_generate_uuid4();
                }

                $eventIdCompact = preg_replace('/[^a-zA-Z0-9]/', '', $eventId);
                if (!is_string($eventIdCompact) || $eventIdCompact === '') {
                    $eventIdCompact = bin2hex(random_bytes(6));
                }

                // Keep queue key under VARCHAR(80) while preserving base cart id.
                $queueCartId = substr($cartId, 0, 40) . ':clr:' . substr($eventIdCompact, 0, 24);
            }
        }

        $json = wp_json_encode($payload);
        if (!is_string($json) || $json === '') {
            return;
        }

        $eventHash = hash('sha256', $json);

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table}
                    (cart_id, event_hash, payload, status, attempts, next_retry_at, priority)
                 VALUES
                    (%s, %s, %s, 'pending', 0, NULL, %d)
                 ON DUPLICATE KEY UPDATE
                    event_hash = VALUES(event_hash),
                    payload = VALUES(payload),
                    status = 'pending',
                    attempts = 0,
                    next_retry_at = NULL",
                $queueCartId,
                $eventHash,
                $json,
                (int) ($eventType === 'cart.updated' ? 5 : 0)
            )
        );
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function upsert_order_event(array $payload, string $lastError): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'ncwoo_order_event';
        $orderId = trim((string) ($payload['order_id'] ?? ''));
        $cartId = trim((string) ($payload['cart_id'] ?? ''));
        if ($orderId === '') {
            return;
        }

        $json = wp_json_encode($payload);
        if (!is_string($json) || $json === '') {
            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table}
                    (order_id, cart_id, payload, status, attempts, next_retry_at, last_error)
                 VALUES
                    (%s, %s, %s, 'pending', 0, NULL, %s)
                 ON DUPLICATE KEY UPDATE
                    payload = VALUES(payload),
                    status = IF(status='sent','sent','pending'),
                    last_error = VALUES(last_error)",
                $orderId,
                $cartId,
                $json,
                $lastError
            )
        );
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function enqueue_telemetry_event(array $payload, string $origin): bool
    {
        global $wpdb;

        $this->ensure_telemetry_queue_table();

        $normalized = $this->normalize_telemetry_payload($payload, $origin);
        if ($normalized === null) {
            return false;
        }

        $meta = is_array($normalized['meta'] ?? null) ? $normalized['meta'] : [];
        $eventKey = trim((string) ($meta['local_event_key'] ?? $normalized['event_id'] ?? ''));
        if ($eventKey === '') {
            $eventKey = wp_generate_uuid4();
        }
        $eventKey = preg_replace('/[^a-zA-Z0-9:_\\-]/', '', $eventKey);
        if (!is_string($eventKey) || $eventKey === '') {
            $eventKey = wp_generate_uuid4();
        }
        $eventKey = substr($eventKey, 0, 120);

        $json = wp_json_encode($normalized);
        if (!is_string($json) || $json === '') {
            return false;
        }

        $table = $wpdb->prefix . 'ncwoo_telemetry_event';
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table}
                    (event_key, event_type, payload, status, attempts, next_retry_at, last_error)
                 VALUES
                    (%s, %s, %s, 'pending', 0, NULL, NULL)
                 ON DUPLICATE KEY UPDATE
                    event_type = VALUES(event_type),
                    payload = IF(status='sent', payload, VALUES(payload)),
                    status = IF(status='sent', status, 'pending'),
                    attempts = IF(status='sent', attempts, 0),
                    next_retry_at = IF(status='sent', next_retry_at, NULL),
                    last_error = IF(status='sent', last_error, NULL)",
                $eventKey,
                (string) $normalized['event_type'],
                $json
            )
        );

        return $wpdb->last_error === '';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    private function normalize_telemetry_payload(array $payload, string $origin): ?array
    {
        $eventType = strtolower(trim((string) ($payload['event_type'] ?? '')));
        if (!in_array($eventType, self::TELEMETRY_EVENT_TYPES, true)) {
            return null;
        }

        $eventId = trim((string) ($payload['event_id'] ?? ''));
        if ($eventId === '') {
            $eventId = wp_generate_uuid4();
        }

        $occurredAt = trim((string) ($payload['occurred_at'] ?? ''));
        if ($occurredAt === '' || strtotime($occurredAt) === false) {
            $occurredAt = gmdate('c');
        }

        $shopExternalId = $this->config->get_shop_external_id();
        if ($shopExternalId === '') {
            $shopExternalId = wp_parse_url(home_url('/'), PHP_URL_HOST) ?: 'woo-shop';
        }

        $shopLocale = $this->resolve_wordpress_locale();
        $languageCode = $this->resolve_language_code($shopLocale);
        $metrics = $this->compact_telemetry_value($payload['metrics'] ?? []);
        $context = $this->compact_telemetry_value($payload['context'] ?? []);
        $meta = $this->compact_telemetry_value($payload['meta'] ?? []);
        $privacy = $this->compact_telemetry_value($payload['privacy'] ?? []);

        if (!is_array($metrics)) {
            $metrics = [];
        }
        if (!is_array($context)) {
            $context = [];
        }
        if (!is_array($meta)) {
            $meta = [];
        }
        if (!is_array($privacy)) {
            $privacy = [];
        }

        $meta['origin'] = $origin;
        $meta['connector_version'] = defined('NCWOO_CONNECTOR_VERSION') ? NCWOO_CONNECTOR_VERSION : '1.0.6';
        $privacy['contains_raw_server_logs'] = false;
        $privacy['contains_payment_provider_logs'] = false;
        $privacy['contains_customer_pii'] = false;

        return [
            'event_id' => substr($eventId, 0, 120),
            'event_type' => $eventType,
            'occurred_at' => gmdate('c', (int) strtotime($occurredAt)),
            'language' => $languageCode,
            'source' => [
                'platform' => 'woocommerce',
                'shop_id' => (string) $shopExternalId,
                'shop_name' => get_bloginfo('name'),
                'language' => $languageCode,
            ],
            'metrics' => $metrics,
            'context' => array_merge(
                [
                    'shop_locale' => $shopLocale,
                    'shop_language' => $languageCode,
                    'shop_timezone' => function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC',
                    'currency_code' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD',
                ],
                $context
            ),
            'privacy' => $privacy,
            'meta' => $meta,
        ];
    }

    /**
     * @param array<string,mixed> $metrics
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function build_telemetry_payload(
        string $eventType,
        array $metrics,
        array $context,
        ?string $localEventKey = null
    ): array {
        $payload = [
            'event_id' => wp_generate_uuid4(),
            'event_type' => $eventType,
            'occurred_at' => gmdate('c'),
            'metrics' => $metrics,
            'context' => $context,
            'privacy' => [
                'contains_raw_server_logs' => false,
                'contains_payment_provider_logs' => false,
                'contains_customer_pii' => false,
            ],
            'meta' => [],
        ];

        if ($localEventKey !== null && trim($localEventKey) !== '') {
            $payload['meta']['local_event_key'] = $localEventKey;
        }

        return $payload;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function compact_telemetry_value($value, int $depth = 4)
    {
        if ($depth <= 0) {
            return '[truncated]';
        }
        if (is_array($value)) {
            $result = [];
            $count = 0;
            foreach ($value as $key => $item) {
                if ($count >= 30) {
                    $result['_truncated'] = true;
                    break;
                }
                $safeKeyRaw = preg_replace('/[^a-zA-Z0-9_:\\-]/', '_', (string) $key);
                $safeKey = is_string($safeKeyRaw) ? substr($safeKeyRaw, 0, 80) : '';
                if ($safeKey === '') {
                    $safeKey = 'field_' . $count;
                }
                $result[$safeKey] = $this->compact_telemetry_value($item, $depth - 1);
                $count++;
            }
            return $result;
        }
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }

        $text = trim(wp_strip_all_tags((string) $value));
        return $this->scrub_telemetry_text($text, 500);
    }

    private function scrub_telemetry_text(string $text, int $maxLength): string
    {
        $patterns = [
            '/[\\w.+\\-]+@[\\w\\-]+(?:\\.[\\w\\-]+)+/',
            '/https?:\\/\\/[^\\s"\\\'<>]+/i',
            '/\\b(?:sk|pk|rk|whsec|secret|token|api[_-]?key)[_-]?[A-Za-z0-9]{12,}\\b/i',
        ];
        $replacements = ['[email]', '[url]', '[secret]'];
        $scrubbed = preg_replace($patterns, $replacements, $text);
        if (!is_string($scrubbed)) {
            $scrubbed = '';
        }

        return substr($scrubbed, 0, max(1, $maxLength));
    }

    private function count_required_checkout_fields(): int
    {
        if (!function_exists('WC') || !WC()->checkout()) {
            return 0;
        }

        try {
            $fields = WC()->checkout()->get_checkout_fields();
        } catch (Throwable $e) {
            return 0;
        }

        if (!is_array($fields)) {
            return 0;
        }

        $count = 0;
        foreach ($fields as $group) {
            if (!is_array($group)) {
                continue;
            }
            foreach ($group as $field) {
                if (is_array($field) && !empty($field['required'])) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function count_available_payment_methods(): int
    {
        if (!function_exists('WC') || !WC()->payment_gateways()) {
            return 0;
        }

        try {
            $methods = WC()->payment_gateways()->get_available_payment_gateways();
        } catch (Throwable $e) {
            return 0;
        }

        return is_array($methods) ? count($methods) : 0;
    }

    private function count_available_shipping_methods(): int
    {
        if (!function_exists('WC') || !WC()->shipping()) {
            return 0;
        }

        try {
            $packages = WC()->shipping()->get_packages();
        } catch (Throwable $e) {
            return 0;
        }

        if (!is_array($packages)) {
            return 0;
        }

        $count = 0;
        foreach ($packages as $package) {
            $rates = is_array($package) && is_array($package['rates'] ?? null)
                ? $package['rates']
                : [];
            $count += count($rates);
        }

        return $count;
    }

    private function ensure_telemetry_queue_table(): void
    {
        global $wpdb;

        static $ensured = false;
        if ($ensured) {
            return;
        }

        $table = $wpdb->prefix . 'ncwoo_telemetry_event';
        $charset = $wpdb->get_charset_collate();
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_key VARCHAR(120) NOT NULL,
                event_type VARCHAR(120) NOT NULL,
                payload LONGTEXT NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                next_retry_at DATETIME NULL,
                last_attempt_at DATETIME NULL,
                last_error VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                sent_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY unq_event_key (event_key),
                KEY idx_status_retry_created (status, next_retry_at, created_at),
                KEY idx_event_type_created (event_type, created_at)
            ) {$charset}"
        );

        $ensured = true;
    }

    private function log_cron_run(string $status, int $processedEvents, int $executionTimeMs, ?string $errorMessage): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'ncwoo_cron_log';
        $wpdb->insert(
            $table,
            [
                'status' => $status,
                'processed_events' => max(0, $processedEvents),
                'execution_time_ms' => max(0, $executionTimeMs),
                'error_message' => $errorMessage,
            ],
            ['%s', '%d', '%d', '%s']
        );
    }

    private function acquire_queue_lock(): bool
    {
        global $wpdb;

        $locked = $wpdb->get_var("SELECT GET_LOCK('ncwoo_process_queue', 0)");
        return (int) $locked === 1;
    }

    private function release_queue_lock(): void
    {
        global $wpdb;

        $wpdb->query("SELECT RELEASE_LOCK('ncwoo_process_queue')");
    }

    private function is_circuit_available(): bool
    {
        $blockedUntil = $this->config->get_int(NCWooConfig::OPTION_CRON_BLOCKED_UNTIL, 0);
        if ($blockedUntil > time()) {
            return false;
        }

        $state = strtolower($this->config->get_string(NCWooConfig::OPTION_CB_STATE, 'closed'));
        if ($state === 'open' && $blockedUntil > 0 && $blockedUntil <= time()) {
            $this->set_circuit_state('half_open', $this->config->get_int(NCWooConfig::OPTION_CB_FAILURE_COUNT, 0), 0);
        }

        return true;
    }

    private function record_circuit_success(): void
    {
        $this->set_circuit_state('closed', 0, 0);
    }

    private function record_circuit_failure(): void
    {
        $threshold = max(1, $this->config->get_int(NCWooConfig::OPTION_CB_FAILURE_THRESHOLD, 5));
        $cooldownSeconds = max(1, $this->config->get_int(NCWooConfig::OPTION_CB_COOLDOWN_SECONDS, 60));
        $state = strtolower($this->config->get_string(NCWooConfig::OPTION_CB_STATE, 'closed'));
        $failures = $this->config->get_int(NCWooConfig::OPTION_CB_FAILURE_COUNT, 0) + 1;

        if ($state === 'half_open') {
            $failures = $threshold;
        }

        if ($failures >= $threshold) {
            $this->set_circuit_state('open', $failures, time() + $cooldownSeconds);
            return;
        }

        $this->set_circuit_state('closed', $failures, 0);
    }

    private function set_circuit_state(string $state, int $failureCount, int $blockedUntil): void
    {
        $normalized = strtolower(trim($state));
        if (!in_array($normalized, ['closed', 'open', 'half_open'], true)) {
            $normalized = 'closed';
        }

        $this->config->set(NCWooConfig::OPTION_CB_STATE, $normalized);
        $this->config->set(NCWooConfig::OPTION_CB_FAILURE_COUNT, max(0, $failureCount));
        $this->config->set(NCWooConfig::OPTION_CRON_BLOCKED_UNTIL, max(0, $blockedUntil));
        $this->config->set(NCWooConfig::OPTION_CB_UPDATED_AT, gmdate('Y-m-d H:i:s'));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function build_cart_event_snapshot(string $eventType, ?string $clearReason): ?array
    {
        if (!function_exists('WC') || !WC()->cart) {
            return null;
        }

        $cart = WC()->cart;
        $items = [];
        $itemsTotal = 0.0;

        if ($eventType !== 'cart.cleared') {
            $this->refresh_cart_totals($cart);

            foreach ($cart->get_cart() as $line) {
                $quantity = (int) ($line['quantity'] ?? 0);
                $lineProductId = (int) ($line['product_id'] ?? 0);
                $lineVariationId = (int) ($line['variation_id'] ?? 0);
                $product = isset($line['data']) && is_object($line['data']) ? $line['data'] : null;

                $productObjectId = $product && method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
                $productParentId = $product && method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;
                $productId = $productParentId > 0
                    ? $productParentId
                    : ($productObjectId > 0 ? $productObjectId : $lineProductId);
                $variationId = $lineVariationId > 0
                    ? $lineVariationId
                    : ($productParentId > 0 && $productObjectId > 0 ? $productObjectId : 0);

                if ($productId <= 0 || $quantity <= 0) {
                    continue;
                }

                $lineTotal = $this->resolve_snapshot_line_total($line, $product, $quantity);
                $unitPrice = $quantity > 0 ? round($lineTotal / $quantity, 2) : 0.0;

                $items[] = [
                    'product_id' => $productId,
                    'attribute_id' => $variationId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => round($lineTotal, 2),
                    'variation' => is_array($line['variation'] ?? null) ? $line['variation'] : [],
                ];
                $itemsTotal += $lineTotal;
            }

            if (!$items) {
                return null;
            }
        }

        $customer = function_exists('WC') ? WC()->customer : null;
        $customerEmail = $customer && method_exists($customer, 'get_email')
            ? trim((string) $customer->get_email())
            : '';
        $customerId = get_current_user_id();
        if ($customerEmail === '' && $customerId > 0) {
            $wpUser = get_userdata($customerId);
            if ($wpUser && !empty($wpUser->user_email)) {
                $customerEmail = trim((string) $wpUser->user_email);
            }
        }
        $phone = $customer && method_exists($customer, 'get_billing_phone')
            ? trim((string) $customer->get_billing_phone())
            : '';

        $cartId = $this->resolve_runtime_cart_id($eventType === 'cart.updated');
        if ($cartId === '') {
            $cartId = $this->resolve_session_customer_id_fallback();
        }
        if ($cartId === '') {
            $cartId = trim((string) $cart->get_cart_hash());
        }
        if ($cartId === '') {
            $cartId = 'wc-cart-' . wp_generate_uuid4();
        }

        $shopExternalId = $this->config->get_shop_external_id();
        if ($shopExternalId === '') {
            $shopExternalId = wp_parse_url(home_url('/'), PHP_URL_HOST) ?: 'woo-shop';
        }

        $shopLocale = $this->resolve_wordpress_locale();
        $languageCode = $this->resolve_language_code($shopLocale);

        $payload = [
            'event_id' => wp_generate_uuid4(),
            'event_type' => $eventType,
            'occurred_at' => gmdate('c'),
            'language' => $languageCode,
            'source' => [
                'platform' => 'woocommerce',
                'shop_id' => (string) $shopExternalId,
                'shop_name' => get_bloginfo('name'),
                'language' => $languageCode,
            ],
            'cart' => [
                'id' => (string) $cartId,
                'uid' => hash('sha256', implode('|', [$shopExternalId, $cartId, strtolower($customerEmail), gmdate('Y-m-d')])),
                'total' => $this->resolve_cart_total($cart, $itemsTotal, $eventType),
                'items' => $items,
            ],
            'customer' => [
                'id' => $customerId > 0 ? (string) $customerId : null,
                'email' => $customerEmail !== '' ? $customerEmail : null,
                'first_name' => $customer && method_exists($customer, 'get_billing_first_name') ? $customer->get_billing_first_name() : null,
                'last_name' => $customer && method_exists($customer, 'get_billing_last_name') ? $customer->get_billing_last_name() : null,
                'locale' => $shopLocale,
                'language' => $languageCode,
                'is_guest' => $customerId <= 0,
                'phone' => $phone !== '' ? $phone : null,
            ],
            'context' => [
                'shop_timezone' => function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC',
                'shop_local_hour' => (int) current_time('G'),
                'currency_precision' => 2,
                'shop_locale' => $shopLocale,
                'shop_language' => $languageCode,
                'currency_code' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD',
            ],
            'rules' => $this->build_cart_rules_payload(),
            'meta' => [
                'session' => $this->build_session_meta(),
                'snapshot_minimal' => true,
                'snapshot_version' => self::CART_SNAPSHOT_VERSION,
            ],
        ];

        if ($eventType === 'cart.cleared') {
            $payload['meta']['clear_reason'] = $clearReason ?: 'cart_cleared';
            $payload['cart']['total'] = 0.0;
            $payload['cart']['items'] = [];
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function is_minimal_cart_snapshot(array $payload): bool
    {
        $meta = $payload['meta'] ?? [];
        return is_array($meta) && !empty($meta['snapshot_minimal']);
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>|null
     */
    private function build_cart_event_payload_from_snapshot(array $snapshot): ?array
    {
        $eventType = strtolower(trim((string) ($snapshot['event_type'] ?? 'cart.updated')));
        if ($eventType === 'cart.cleared') {
            $snapshot['cart']['total'] = 0.0;
            $snapshot['cart']['items'] = [];
            unset($snapshot['meta']['snapshot_minimal'], $snapshot['meta']['snapshot_version']);
            return $snapshot;
        }

        $cart = is_array($snapshot['cart'] ?? null) ? $snapshot['cart'] : [];
        $rawItems = is_array($cart['items'] ?? null) ? $cart['items'] : [];
        if (!$rawItems) {
            return null;
        }

        $items = [];
        $itemsTotal = 0.0;
        $productMetaCache = [];

        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }

            $quantity = max(1, (int) ($rawItem['quantity'] ?? 0));
            $productId = (int) ($rawItem['product_id'] ?? 0);
            $variationId = (int) ($rawItem['attribute_id'] ?? 0);
            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            $product = function_exists('wc_get_product') ? wc_get_product($variationId > 0 ? $variationId : $productId) : null;
            $lineTotal = $this->to_float($rawItem['line_total'] ?? 0);
            $unitPrice = $this->to_float($rawItem['unit_price'] ?? 0);
            if ($lineTotal <= 0 && $unitPrice > 0) {
                $lineTotal = $unitPrice * $quantity;
            }
            if ($unitPrice <= 0 && $lineTotal > 0) {
                $unitPrice = $lineTotal / $quantity;
            }

            $imageUrl = $this->resolve_product_image_url($product, $productId, $variationId);
            $productUrl = $this->resolve_product_url($product, $productId);
            $productName = $product && method_exists($product, 'get_name') ? trim((string) $product->get_name()) : '';
            if ($productName === '') {
                $productName = get_the_title($productId) ?: '';
            }

            $meta = $this->resolve_product_catalog_metadata($productId, $variationId, $productMetaCache);
            $stockState = $this->resolve_stock_state($product);
            $variantLabel = $this->resolve_variant_label($rawItem, $product);
            $sku = $product && method_exists($product, 'get_sku') ? trim((string) $product->get_sku()) : '';

            $items[] = [
                'product_id' => $productId,
                'attribute_id' => $variationId,
                'name' => $productName,
                'category_path' => $meta['category_path'],
                'category_name' => $meta['category_path'],
                'brand_name' => $meta['brand_name'],
                'variant_label' => $variantLabel,
                'sku' => $sku !== '' ? $sku : null,
                'quantity' => $quantity,
                'unit_price' => round(max(0.0, $unitPrice), 2),
                'line_total' => round(max(0.0, $lineTotal), 2),
                'availability' => $stockState['availability'],
                'in_stock' => $stockState['in_stock'],
                'stock' => $stockState['stock'],
                'product_url' => is_string($productUrl) ? $productUrl : null,
                'image_url' => is_string($imageUrl) ? $imageUrl : null,
            ];
            $itemsTotal += max(0.0, $lineTotal);
        }

        if (!$items) {
            return null;
        }

        $snapshotCartTotal = $this->to_float($cart['total'] ?? 0);
        $snapshot['cart']['items'] = $items;
        $snapshot['cart']['total'] = round(max($snapshotCartTotal, $itemsTotal), 2);
        unset($snapshot['meta']['snapshot_minimal'], $snapshot['meta']['snapshot_version']);

        return $snapshot;
    }

    /**
     * @param array<string,mixed> $line
     * @param mixed $product
     */
    private function resolve_snapshot_line_total(array $line, $product, int $quantity): float
    {
        $candidates = [
            $this->to_float($line['line_total'] ?? 0) + $this->to_float($line['line_tax'] ?? 0),
            $this->to_float($line['line_subtotal'] ?? 0) + $this->to_float($line['line_subtotal_tax'] ?? 0),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate > 0) {
                return round($candidate, 2);
            }
        }

        if ($product && method_exists($product, 'get_price')) {
            $price = $this->to_float($product->get_price());
            if ($price > 0) {
                return round($price * max(1, $quantity), 2);
            }
        }

        return 0.0;
    }

    /**
     * @param mixed $cart
     */
    private function refresh_cart_totals($cart): void
    {
        static $isCalculating = false;

        if ($isCalculating || !$cart || !method_exists($cart, 'calculate_totals')) {
            return;
        }

        $isCalculating = true;
        try {
            $cart->calculate_totals();
        } catch (Throwable $e) {
            // Keep capture resilient; item-level totals remain a safe fallback.
        } finally {
            $isCalculating = false;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function build_cart_rules_payload(): array
    {
        return [
            'recovery_enabled' => $this->config->get_bool(NCWooConfig::OPTION_RECOVERY_ENABLED, true),
            'allow_discount' => $this->config->get_bool(NCWooConfig::OPTION_ALLOW_DISCOUNT, false),
            'min_cart_total' => $this->config->get_float(NCWooConfig::OPTION_MIN_CART_TOTAL, 0.0),
            'allow_guest' => $this->config->get_bool(NCWooConfig::OPTION_ALLOW_GUEST, false),
            'no_discount_max' => $this->config->get_float(NCWooConfig::OPTION_NO_DISCOUNT_MAX, 0.0),
            'discount_5_min' => $this->config->get_float(NCWooConfig::OPTION_DISCOUNT_5_MIN, 0.0),
            'discount_5_max' => $this->config->get_float(NCWooConfig::OPTION_DISCOUNT_5_MAX, 0.0),
            'discount_10_min' => $this->config->get_float(NCWooConfig::OPTION_DISCOUNT_10_MIN, 0.0),
            'max_discount_percent' => $this->config->get_float(NCWooConfig::OPTION_MAX_DISCOUNT_PERCENT, 0.0),
        ];
    }


    /**
     * @param mixed $cart
     */
    private function resolve_cart_total($cart, float $itemsTotal, string $eventType): float
    {
        if ($eventType === 'cart.cleared') {
            return 0.0;
        }

        $cartTotal = 0.0;
        if ($cart && method_exists($cart, 'get_total')) {
            $cartTotal = $this->to_float($cart->get_total('edit'));
        }

        if ($cartTotal <= 0 && $cart && method_exists($cart, 'get_cart_contents_total')) {
            $cartTotal = $this->to_float($cart->get_cart_contents_total()) + $this->to_float($cart->get_cart_contents_tax());
        }

        if ($cartTotal <= 0 && $itemsTotal > 0) {
            $cartTotal = $itemsTotal;
        }

        return round(max(0.0, $cartTotal), 2);
    }

    /**
     * @param array<int,array{category_path:?string,brand_name:?string}> $cache
     * @return array{category_path:?string,brand_name:?string}
     */
    private function resolve_product_catalog_metadata(int $productId, int $variationId, array &$cache): array
    {
        $lookupId = $productId;
        if ($variationId > 0) {
            $parentId = (int) wp_get_post_parent_id($variationId);
            if ($parentId > 0) {
                $lookupId = $parentId;
            }
        }

        if ($lookupId <= 0) {
            return [
                'category_path' => null,
                'brand_name' => null,
            ];
        }

        if (isset($cache[$lookupId])) {
            return $cache[$lookupId];
        }

        $categoryPath = null;
        try {
            $terms = wp_get_post_terms($lookupId, 'product_cat', ['fields' => 'names']);
            if (is_array($terms) && !empty($terms)) {
                $normalized = array_values(array_filter(array_map('trim', $terms), static function (string $name): bool {
                    return $name !== '';
                }));
                if (!empty($normalized)) {
                    $categoryPath = $normalized[0];
                }
            }
        } catch (Throwable $e) {
            $categoryPath = null;
        }

        $brandName = $this->resolve_first_term_name(
            $lookupId,
            ['product_brand', 'pwb-brand', 'yith_product_brand', 'brand', 'pa_brand']
        );

        $cache[$lookupId] = [
            'category_path' => $categoryPath,
            'brand_name' => $brandName,
        ];

        return $cache[$lookupId];
    }

    /**
     * @param array<int,string> $taxonomies
     */
    private function resolve_first_term_name(int $postId, array $taxonomies): ?string
    {
        if ($postId <= 0) {
            return null;
        }

        foreach ($taxonomies as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }

            try {
                $terms = wp_get_post_terms($postId, $taxonomy, ['fields' => 'names']);
            } catch (Throwable $e) {
                $terms = [];
            }

            if (!is_array($terms) || !$terms) {
                continue;
            }

            foreach ($terms as $termName) {
                $normalized = trim((string) $termName);
                if ($normalized !== '') {
                    return $normalized;
                }
            }
        }

        return null;
    }

    /**
     * @param mixed $product
     * @return array{availability:string,in_stock:?bool,stock:?int}
     */
    private function resolve_stock_state($product): array
    {
        $inStock = null;
        $stock = null;

        if ($product && method_exists($product, 'is_in_stock')) {
            try {
                $inStock = (bool) $product->is_in_stock();
            } catch (Throwable $e) {
                $inStock = null;
            }
        }

        if ($product && method_exists($product, 'get_stock_quantity')) {
            try {
                $qty = $product->get_stock_quantity();
                if ($qty !== null) {
                    $stock = (int) $qty;
                }
            } catch (Throwable $e) {
                $stock = null;
            }
        }

        $availability = 'unknown';
        if ($inStock === true) {
            $availability = 'in_stock';
        } elseif ($inStock === false) {
            $availability = 'out_of_stock';
        }

        return [
            'availability' => $availability,
            'in_stock' => $inStock,
            'stock' => $stock,
        ];
    }

    /**
     * @param array<string,mixed> $line
     * @param mixed $product
     */
    private function resolve_variant_label(array $line, $product): ?string
    {
        $parts = [];
        $variation = $line['variation'] ?? null;
        if (is_array($variation)) {
            foreach ($variation as $attributeKey => $attributeValue) {
                if (is_array($attributeValue)) {
                    $attributeValue = reset($attributeValue);
                }
                $value = trim((string) $attributeValue);
                if ($value === '') {
                    continue;
                }
                $label = trim((string) preg_replace('/^attribute_/', '', (string) $attributeKey));
                $label = str_replace('_', ' ', str_replace('pa_', '', $label));
                $parts[] = ($label !== '' ? ucfirst($label) . ': ' : '') . $value;
            }
        }

        if (!$parts && $product && method_exists($product, 'get_variation_attributes')) {
            try {
                $attrs = $product->get_variation_attributes();
                if (is_array($attrs)) {
                    foreach ($attrs as $attributeKey => $attributeValue) {
                        if (is_array($attributeValue)) {
                            $attributeValue = reset($attributeValue);
                        }
                        $value = trim((string) $attributeValue);
                        if ($value === '') {
                            continue;
                        }
                        $label = trim((string) preg_replace('/^attribute_/', '', (string) $attributeKey));
                        $label = str_replace('_', ' ', str_replace('pa_', '', $label));
                        $parts[] = ($label !== '' ? ucfirst($label) . ': ' : '') . $value;
                    }
                }
            } catch (Throwable $e) {
            }
        }

        if (!$parts) {
            return null;
        }

        return implode(' | ', $parts);
    }

    /**
     * @param mixed $value
     */
    private function to_float($value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return 0.0;
        }

        if (function_exists('wc_format_decimal')) {
            $normalized = wc_format_decimal($raw, wc_get_price_decimals(), false);
            if (is_numeric($normalized)) {
                return (float) $normalized;
            }
        }

        $fallback = preg_replace('/[^0-9\.\-]/', '', str_replace(',', '.', $raw));
        return is_numeric($fallback) ? (float) $fallback : 0.0;
    }

    /**
     * @return array{source_page:?string,referrer:?string,user_agent:?string}
     */
    private function build_session_meta(): array
    {
        $sourcePage = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])
            ? trim((string) $_SERVER['REQUEST_URI'])
            : '';
        $referrer = isset($_SERVER['HTTP_REFERER']) && is_string($_SERVER['HTTP_REFERER'])
            ? trim((string) $_SERVER['HTTP_REFERER'])
            : '';
        $userAgent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])
            ? trim((string) $_SERVER['HTTP_USER_AGENT'])
            : '';

        if (strlen($sourcePage) > 1024) {
            $sourcePage = substr($sourcePage, 0, 1024);
        }
        if (strlen($referrer) > 1024) {
            $referrer = substr($referrer, 0, 1024);
        }
        if (strlen($userAgent) > 1024) {
            $userAgent = substr($userAgent, 0, 1024);
        }

        return [
            'source_page' => $sourcePage !== '' ? $sourcePage : null,
            'referrer' => $referrer !== '' ? $referrer : null,
            'user_agent' => $userAgent !== '' ? $userAgent : null,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function build_order_event_payload(int $orderId): ?array
    {
        if (!function_exists('wc_get_order')) {
            return null;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return null;
        }

        $shopExternalId = $this->config->get_shop_external_id();
        if ($shopExternalId === '') {
            $shopExternalId = wp_parse_url(home_url('/'), PHP_URL_HOST) ?: 'woo-shop';
        }

        $cartId = trim((string) $order->get_meta('_ncwoo_cart_id'));
        if ($cartId === '') {
            $cartId = $this->resolve_runtime_cart_id(false);
        }
        if ($cartId === '') {
            $cartId = $this->resolve_session_customer_id_fallback();
        }
        if ($cartId === '') {
            $cartId = trim((string) $order->get_meta('_cart_hash'));
        }
        if ($cartId === '' && method_exists($order, 'get_cart_hash')) {
            $cartId = trim((string) $order->get_cart_hash());
        }
        if ($cartId === '') {
            $cartId = (string) $orderId;
        }

        $items = [];
        $productMetaCache = [];
        $lineNumber = 0;
        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $quantity = (int) $item->get_quantity();
            if ($quantity <= 0) {
                continue;
            }

            $lineTotal = (float) $item->get_total() + (float) $item->get_total_tax();
            $unitPrice = $quantity > 0 ? round($lineTotal / $quantity, 2) : 0.0;
            $product = $item->get_product();
            $productId = (int) $item->get_product_id();
            $variationId = (int) $item->get_variation_id();
            $meta = $this->resolve_product_catalog_metadata($productId, $variationId, $productMetaCache);
            $productUrl = $this->resolve_product_url($product, $productId);
            $imageUrl = $this->resolve_product_image_url($product, $productId, $variationId);
            $variantLabel = $this->resolve_order_variant_label($item, $product);
            $stockState = $this->resolve_stock_state($product);
            $sku = $product && method_exists($product, 'get_sku') ? trim((string) $product->get_sku()) : '';
            $productName = trim((string) $item->get_name());
            $lineNumber++;

            $items[] = [
                'product_id' => $productId > 0 ? (string) $productId : null,
                'attribute_id' => $variationId > 0 ? (string) $variationId : null,
                'reference' => $sku !== '' ? $sku : null,
                'sku' => $sku !== '' ? $sku : null,
                'name' => $productName !== '' ? $productName : ($productId > 0 ? 'product_' . $productId : null),
                'variant_label' => $variantLabel,
                'category_path' => $meta['category_path'],
                'category_name' => $meta['category_path'],
                'brand_name' => $meta['brand_name'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => round($lineTotal, 2),
                'currency' => $order->get_currency(),
                'currency_code' => $order->get_currency(),
                'availability' => $stockState['availability'],
                'in_stock' => $stockState['in_stock'],
                'stock' => $stockState['stock'],
                'product_url' => $productUrl,
                'image_url' => $imageUrl,
                'product_snapshot' => [
                    'product_name' => $productName !== '' ? $productName : null,
                    'reference' => $sku !== '' ? $sku : null,
                    'product_url' => $productUrl,
                    'image_url' => $imageUrl,
                    'category_path' => $meta['category_path'],
                    'brand_name' => $meta['brand_name'],
                ],
                'line_number' => $lineNumber,
            ];
        }

        $couponCodes = array_values(array_filter(array_map('strval', $order->get_coupon_codes())));
        $primaryCoupon = $couponCodes ? $couponCodes[0] : '';
        $discountAmount = abs((float) $order->get_discount_total() + (float) $order->get_discount_tax());
        $subtotal = (float) $order->get_subtotal();
        $discountPercent = ($subtotal > 0 && $discountAmount > 0)
            ? round(($discountAmount / $subtotal) * 100, 2)
            : 0.0;

        $created = $order->get_date_created();
        $occurredAt = $created ? gmdate('c', (int) $created->getTimestamp()) : gmdate('c');
        $visibleOrderNumber = trim((string) $order->get_order_number());
        if ($visibleOrderNumber === '') {
            $visibleOrderNumber = (string) $orderId;
        }

        $shopLocale = $this->resolve_wordpress_locale();
        $languageCode = $this->resolve_language_code($shopLocale);

        return [
            'event_id' => hash('sha256', implode('|', [$shopExternalId, (string) $orderId, $cartId])),
            'event_type' => 'order.completed',
            'occurred_at' => $occurredAt,
            'language' => $languageCode,
            'order_id' => (string) $orderId,
            'technical_order_id' => (string) $orderId,
            'external_order_id' => $visibleOrderNumber,
            'customer_visible_order_id' => $visibleOrderNumber,
            'order_number' => $visibleOrderNumber,
            'cart_id' => $cartId,
            'order_total' => round((float) $order->get_total(), 2),
            'customer_email' => $order->get_billing_email() ?: null,
            'currency' => $order->get_currency(),
            'total_discounts' => $discountAmount,
            'used_coupon_codes' => $couponCodes,
            'neuro_coupon_used' => str_starts_with(strtoupper($primaryCoupon), 'NC-'),
            'neuro_coupon_code' => str_starts_with(strtoupper($primaryCoupon), 'NC-') ? $primaryCoupon : null,
            'neuro_discount_percent' => $discountPercent,
            'neuro_discount_amount' => $discountAmount,
            'customer' => [
                'id' => $order->get_customer_id() > 0 ? (string) $order->get_customer_id() : null,
                'email' => $order->get_billing_email() ?: null,
                'is_guest' => $order->get_customer_id() <= 0,
                'first_name' => $order->get_billing_first_name() ?: null,
                'last_name' => $order->get_billing_last_name() ?: null,
                'phone' => $order->get_billing_phone() ?: null,
                'locale' => $shopLocale,
                'language' => $languageCode,
                'address' => $this->build_order_address_payload($order),
            ],
            'order' => [
                'id' => (string) $orderId,
                'number' => $visibleOrderNumber,
                'status' => $order->get_status(),
                'items' => $items,
            ],
            'source' => [
                'platform' => 'woocommerce',
                'shop_id' => (string) $shopExternalId,
                'shop_name' => get_bloginfo('name'),
                'language' => $languageCode,
            ],
            'context' => [
                'shop_locale' => $shopLocale,
                'shop_language' => $languageCode,
                'currency_code' => $order->get_currency(),
                'currency_precision' => 2,
                'shop_timezone' => function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC',
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
     * @param mixed $product
     */
    private function resolve_product_url($product, int $productId): ?string
    {
        if ($product && method_exists($product, 'get_permalink')) {
            try {
                $url = $product->get_permalink();
                if (is_string($url) && $url !== '') {
                    return $this->normalize_public_url($url);
                }
            } catch (Throwable $e) {
            }
        }

        if ($productId > 0) {
            $url = get_permalink($productId);
            if (is_string($url) && $url !== '') {
                return $this->normalize_public_url($url);
            }
        }

        return null;
    }

    /**
     * @param mixed $product
     */
    private function resolve_product_image_url($product, int $productId, int $variationId): ?string
    {
        $imageId = 0;
        if ($product && method_exists($product, 'get_image_id')) {
            try {
                $imageId = (int) $product->get_image_id();
            } catch (Throwable $e) {
                $imageId = 0;
            }
        }

        if ($imageId <= 0 && $variationId > 0) {
            $parentId = (int) wp_get_post_parent_id($variationId);
            if ($parentId > 0 && function_exists('wc_get_product')) {
                $parent = wc_get_product($parentId);
                if ($parent && method_exists($parent, 'get_image_id')) {
                    $imageId = (int) $parent->get_image_id();
                }
            }
        }

        if ($imageId <= 0 && $productId > 0 && function_exists('wc_get_product')) {
            $parent = wc_get_product($productId);
            if ($parent && method_exists($parent, 'get_image_id')) {
                $imageId = (int) $parent->get_image_id();
            }
        }

        if ($imageId <= 0) {
            return null;
        }

        $url = wp_get_attachment_url($imageId);
        return is_string($url) && $url !== ''
            ? $this->normalize_public_url($url)
            : null;
    }

    /**
     * @param mixed $product
     */
    private function resolve_order_variant_label(WC_Order_Item_Product $item, $product): ?string
    {
        if ($product && function_exists('wc_get_formatted_variation')) {
            try {
                $formatted = wc_get_formatted_variation($product, true, false, true);
                $label = trim(wp_strip_all_tags((string) $formatted));
                if ($label !== '') {
                    return $label;
                }
            } catch (Throwable $e) {
            }
        }

        $parts = [];
        try {
            $metaData = $item->get_formatted_meta_data('');
            if (is_array($metaData)) {
                foreach ($metaData as $meta) {
                    $key = trim(wp_strip_all_tags((string) ($meta->display_key ?? '')));
                    $value = trim(wp_strip_all_tags((string) ($meta->display_value ?? '')));
                    if ($key === '' || $value === '') {
                        continue;
                    }
                    $parts[] = $key . ': ' . $value;
                }
            }
        } catch (Throwable $e) {
            $parts = [];
        }

        return $parts ? implode(' | ', $parts) : null;
    }

    private function build_order_address_payload(WC_Order $order): ?array
    {
        $useShipping = trim((string) $order->get_shipping_address_1()) !== ''
            || trim((string) $order->get_shipping_city()) !== '';

        $prefix = $useShipping ? 'shipping' : 'billing';
        $company = $prefix === 'shipping' ? $order->get_shipping_company() : $order->get_billing_company();
        $address1 = $prefix === 'shipping' ? $order->get_shipping_address_1() : $order->get_billing_address_1();
        $address2 = $prefix === 'shipping' ? $order->get_shipping_address_2() : $order->get_billing_address_2();
        $postcode = $prefix === 'shipping' ? $order->get_shipping_postcode() : $order->get_billing_postcode();
        $city = $prefix === 'shipping' ? $order->get_shipping_city() : $order->get_billing_city();
        $country = $prefix === 'shipping' ? $order->get_shipping_country() : $order->get_billing_country();
        $phone = $order->get_billing_phone();

        if ($useShipping && method_exists($order, 'get_shipping_phone')) {
            $shippingPhone = trim((string) $order->get_shipping_phone());
            if ($shippingPhone !== '') {
                $phone = $shippingPhone;
            }
        }

        $payload = [
            'company' => trim((string) $company) !== '' ? trim((string) $company) : null,
            'address1' => trim((string) $address1) !== '' ? trim((string) $address1) : null,
            'address2' => trim((string) $address2) !== '' ? trim((string) $address2) : null,
            'postcode' => trim((string) $postcode) !== '' ? trim((string) $postcode) : null,
            'city' => trim((string) $city) !== '' ? trim((string) $city) : null,
            'country_code' => trim((string) $country) !== '' ? strtoupper(trim((string) $country)) : null,
            'phone' => trim((string) $phone) !== '' ? trim((string) $phone) : null,
        ];

        foreach ($payload as $value) {
            if ($value !== null) {
                return $payload;
            }
        }

        return null;
    }

    private function resolve_runtime_cart_id(bool $createIfMissing): string
    {
        if (!function_exists('WC') || !WC()->session) {
            return '';
        }

        $session = WC()->session;
        if (!method_exists($session, 'get')) {
            return '';
        }

        $cartId = trim((string) $session->get(self::CART_RUNTIME_SESSION_KEY));
        if ($cartId !== '') {
            return $cartId;
        }

        $cookieCartId = $this->resolve_recovery_cookie_cart_id();
        if ($cookieCartId !== '') {
            if (method_exists($session, 'set')) {
                try {
                    $session->set(self::CART_RUNTIME_SESSION_KEY, $cookieCartId);
                } catch (Throwable $e) {
                    // Keep cookie fallback even if session write fails.
                }
            }
            return $cookieCartId;
        }

        if (!$createIfMissing) {
            return '';
        }

        $cartId = $this->generate_runtime_cart_id();
        if ($cartId === '') {
            return '';
        }

        if (method_exists($session, 'set')) {
            try {
                $session->set(self::CART_RUNTIME_SESSION_KEY, $cartId);
            } catch (Throwable $e) {
                return '';
            }
        }

        return $cartId;
    }

    private function generate_runtime_cart_id(): string
    {
        $uuid = trim((string) wp_generate_uuid4());
        if ($uuid === '') {
            return '';
        }

        return 'nc-' . $uuid;
    }

    private function resolve_session_customer_id_fallback(): string
    {
        if (!function_exists('WC') || !WC()->session || !method_exists(WC()->session, 'get_customer_id')) {
            return '';
        }

        return trim((string) WC()->session->get_customer_id());
    }

    private function clear_runtime_cart_id(): void
    {
        if (!function_exists('WC') || !WC()->session || !method_exists(WC()->session, 'set')) {
            return;
        }

        try {
            WC()->session->set(self::CART_RUNTIME_SESSION_KEY, '');
        } catch (Throwable $e) {
            // Best effort cleanup.
        }

        if (!headers_sent()) {
            $secure = function_exists('is_ssl') ? (bool) is_ssl() : false;
            setcookie(
                self::CART_RECOVERY_COOKIE_KEY,
                '',
                [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'secure' => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]
            );
        }
        unset($_COOKIE[self::CART_RECOVERY_COOKIE_KEY]);
    }

    private function resolve_recovery_cookie_cart_id(): string
    {
        $raw = $_COOKIE[self::CART_RECOVERY_COOKIE_KEY] ?? '';
        if (!is_string($raw)) {
            return '';
        }

        $cartId = trim($raw);
        if ($cartId === '') {
            return '';
        }

        if (strlen($cartId) > 120) {
            return '';
        }

        if (!preg_match('/^[A-Za-z0-9:_\\-]+$/', $cartId)) {
            return '';
        }

        return $cartId;
    }

    private function normalize_public_url($url): ?string
    {
        $candidate = trim((string) $url);
        if ($candidate === '' || strtolower($candidate) === 'null') {
            return null;
        }

        if (strpos($candidate, 'data:') === 0) {
            return $candidate;
        }

        $baseUrl = $this->resolve_public_site_base_url();
        $origin = $this->extract_url_origin($baseUrl);

        if (strpos($candidate, '//') === 0) {
            $baseScheme = wp_parse_url($baseUrl, PHP_URL_SCHEME);
            $scheme = is_string($baseScheme) && $baseScheme !== '' ? $baseScheme : 'https';
            $candidate = $scheme . ':' . $candidate;
        }

        if (preg_match('#^https?://#i', $candidate) === 1) {
            $currentOrigin = $this->extract_url_origin($candidate);
            $host = wp_parse_url($candidate, PHP_URL_HOST);
            if ($origin !== '' && $currentOrigin !== $origin && $this->should_rewrite_url_host($host)) {
                $rewritten = $this->rewrite_url_origin($candidate, $origin);
                if ($rewritten !== null) {
                    return $rewritten;
                }
            }

            return $candidate;
        }

        if ($baseUrl === '') {
            return $candidate;
        }

        if (strpos($candidate, '/') === 0) {
            if ($origin === '') {
                return $candidate;
            }

            return $origin . $candidate;
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($candidate, '/');
    }

    private function resolve_public_site_base_url(): string
    {
        $candidates = [];
        foreach (['home_url', 'site_url'] as $fn) {
            if (function_exists($fn)) {
                try {
                    $value = $fn('/');
                } catch (Throwable $e) {
                    $value = '';
                }

                if (is_string($value) && trim($value) !== '') {
                    $candidates[] = trim($value);
                }
            }
        }

        foreach ($candidates as $candidate) {
            if ($this->extract_url_origin($candidate) !== '') {
                return $candidate;
            }
        }

        return $candidates[0] ?? '';
    }

    private function extract_url_origin(?string $url): string
    {
        $candidate = trim((string) $url);
        if ($candidate === '') {
            return '';
        }

        $parts = wp_parse_url($candidate);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = trim((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));
        if ($scheme === '' || $host === '') {
            return '';
        }

        $origin = $scheme . '://' . $host;
        if (isset($parts['port']) && (int) $parts['port'] > 0) {
            $origin .= ':' . (int) $parts['port'];
        }

        return $origin;
    }

    private function should_rewrite_url_host($host): bool
    {
        $normalizedHost = strtolower(trim((string) $host));
        if ($normalizedHost === '') {
            return true;
        }

        if (in_array($normalizedHost, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        if (
            strpos($normalizedHost, '.') === false
            || str_ends_with($normalizedHost, '.local')
            || str_ends_with($normalizedHost, '.test')
            || str_ends_with($normalizedHost, '.internal')
        ) {
            return true;
        }

        return false;
    }

    private function rewrite_url_origin(string $url, string $origin): ?string
    {
        $parts = wp_parse_url($url);
        $originParts = wp_parse_url($origin);
        if (!is_array($parts) || !is_array($originParts)) {
            return null;
        }

        $scheme = trim((string) ($originParts['scheme'] ?? ''));
        $host = trim((string) ($originParts['host'] ?? ''));
        if ($scheme === '' || $host === '') {
            return null;
        }

        $rebuilt = $scheme . '://' . $host;
        if (isset($originParts['port']) && (int) $originParts['port'] > 0) {
            $rebuilt .= ':' . (int) $originParts['port'];
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path !== '') {
            $rebuilt .= $path;
        }

        if (isset($parts['query']) && $parts['query'] !== '') {
            $rebuilt .= '?' . $parts['query'];
        }

        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $rebuilt;
    }
}
