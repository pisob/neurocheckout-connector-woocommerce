<?php
/**
 * Plugin Name: NeuroCheckout Connector (WooCommerce)
 * Description: Native WooCommerce connector aligned with NeuroCheckout PrestaShop/Magento business contracts.
 * Version: 1.0.3
 * Author: NeuroCheckout
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Requires Plugins: woocommerce
 * Update URI: https://github.com/pisob/neurocheckout-connector-woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

define('NCWOO_CONNECTOR_VERSION', '1.0.3');

require_once __DIR__ . '/includes/class-ncwoo-config.php';
require_once __DIR__ . '/includes/class-ncwoo-db.php';
require_once __DIR__ . '/includes/class-ncwoo-security.php';
require_once __DIR__ . '/includes/class-ncwoo-recovery.php';
require_once __DIR__ . '/includes/class-ncwoo-cart-fingerprint.php';
require_once __DIR__ . '/includes/class-ncwoo-http-client.php';
require_once __DIR__ . '/includes/class-ncwoo-event-service.php';
require_once __DIR__ . '/includes/class-ncwoo-customer-journey.php';
require_once __DIR__ . '/includes/class-ncwoo-endpoints.php';
require_once __DIR__ . '/includes/class-ncwoo-admin.php';
require_once __DIR__ . '/includes/class-ncwoo-community-source.php';

final class NCWooConnector
{
    private const REST_RESPONSE_GZIP_MIN_BYTES = 1024;

    private static ?NCWooConnector $instance = null;

    private NCWooConfig $config;
    private NCWooDB $db;
    private NCWooRecoveryService $recovery;
    private NCWooEventService $events;
    private NCWooCustomerJourneyService $customerJourney;
    private NCWooEndpoints $endpoints;
    private NCWooAdmin $admin;

    public static function instance(): NCWooConnector
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->config = new NCWooConfig();
        $this->db = new NCWooDB();
        $this->recovery = new NCWooRecoveryService($this->config, $this->db);
        $security = new NCWooSecurity($this->config, $this->db);
        $http = new NCWooHttpClient($this->config);
        $this->events = new NCWooEventService($this->config, $this->db, $http);
        $this->customerJourney = new NCWooCustomerJourneyService($this->config, $this->db, $http);
        $this->endpoints = new NCWooEndpoints(
            $this->config,
            $this->db,
            $security,
            $this->recovery,
            $this->events
        );
        $this->admin = new NCWooAdmin($this->config);

        add_action('rest_api_init', [$this->endpoints, 'register_routes']);
        add_action('rest_api_init', [$this->customerJourney, 'register_routes']);
        add_action('rest_api_init', [NCWooCommunitySource::class, 'registerRoutes']);
        add_filter('rest_pre_serve_request', [NCWooCommunitySource::class, 'serveRaw'], 5, 4);
        add_action('template_redirect', [$this->recovery, 'handle_recovery_redirect']);

        add_action('woocommerce_add_to_cart', [$this->events, 'on_cart_mutation'], 20);
        add_action('woocommerce_add_to_cart', [$this->customerJourney, 'on_cart_mutation'], 30);
        add_action('woocommerce_after_cart_item_quantity_update', [$this->events, 'on_cart_mutation'], 20);
        add_action('woocommerce_after_cart_item_quantity_update', [$this->customerJourney, 'on_cart_mutation'], 30);
        add_action('woocommerce_cart_item_removed', [$this->events, 'on_cart_mutation'], 20);
        add_action('woocommerce_cart_item_removed', [$this->customerJourney, 'on_cart_mutation'], 30);
        add_action('woocommerce_cart_emptied', [$this->events, 'on_cart_emptied'], 20);
        add_action('woocommerce_cart_emptied', [$this->customerJourney, 'on_cart_emptied'], 30);
        add_action('woocommerce_checkout_create_order', [$this, 'on_checkout_create_order'], 20, 2);
        add_action('woocommerce_checkout_order_processed', [$this->events, 'on_order_processed'], 20, 1);
        add_action('woocommerce_checkout_order_processed', [$this->customerJourney, 'on_order_processed'], 30, 1);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'on_store_api_checkout_order_processed'], 20, 1);
        add_action('woocommerce_order_status_completed', [$this->events, 'on_order_completed'], 20, 1);
        add_action('woocommerce_order_status_completed', [$this->customerJourney, 'on_order_completed'], 30, 1);
        add_action('woocommerce_payment_failed', [$this->events, 'on_payment_failed'], 20, 1);
        add_action('woocommerce_after_calculate_totals', [$this->events, 'maybe_enqueue_shipping_cost_snapshot'], 50, 1);
        add_action('woocommerce_subscription_renewal_payment_failed', [$this->events, 'on_subscription_renewal_payment_failed'], 20, 2);
        add_action('woocommerce_renewal_payment_failed', [$this->events, 'on_subscription_renewal_payment_failed'], 20, 2);

        add_filter('cron_schedules', [$this, 'register_cron_interval']);
        add_action('init', [$this, 'ensure_cron']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_checkout_telemetry_script']);
        add_action('wp_enqueue_scripts', [$this->customerJourney, 'enqueue_script']);
        add_action('ncwoo_process_queue', [$this->events, 'process_queue'], 10, 0);
        add_action('ncwoo_process_queue', [$this->customerJourney, 'process_queue'], 20, 0);
        add_filter('rest_pre_serve_request', [$this, 'maybe_serve_gzip_rest_response'], 10, 4);
        add_filter(
            'plugin_action_links_' . plugin_basename(__FILE__),
            [$this, 'add_plugin_settings_link']
        );
    }

    public function maybe_serve_gzip_rest_response(bool $served, $result, WP_REST_Request $request, WP_REST_Server $server): bool
    {
        if ($served) {
            return true;
        }

        if (!$this->is_neurocheckout_rest_route($request)) {
            return false;
        }

        if (strtoupper($request->get_method()) === 'HEAD') {
            return false;
        }

        if (!$this->request_accepts_gzip($request) || !function_exists('gzencode')) {
            return false;
        }

        $response = rest_ensure_response($result);
        if (!$response instanceof WP_HTTP_Response) {
            return false;
        }

        $responseHeaders = $response->get_headers();
        $existingEncoding = strtolower(trim((string) ($responseHeaders['Content-Encoding'] ?? $responseHeaders['content-encoding'] ?? '')));
        if ($existingEncoding !== '') {
            return false;
        }

        $data = $server->response_to_data($response, false);
        $jsonPayload = wp_json_encode($data);
        if (!is_string($jsonPayload) || $jsonPayload === '') {
            return false;
        }

        if (strlen($jsonPayload) < self::REST_RESPONSE_GZIP_MIN_BYTES) {
            return false;
        }

        $encodedPayload = @gzencode($jsonPayload, 5);
        if (!is_string($encodedPayload) || $encodedPayload === '') {
            return false;
        }

        $server->send_header('Content-Encoding', 'gzip');
        $server->send_header('Vary', 'Accept-Encoding');
        $server->send_header('Content-Length', (string) strlen($encodedPayload));
        echo $encodedPayload;

        return true;
    }

    public function enqueue_checkout_telemetry_script(): void
    {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        if (function_exists('is_order_received_page') && is_order_received_page()) {
            return;
        }

        wp_enqueue_script(
            'ncwoo-checkout-telemetry',
            plugins_url('assets/checkout-telemetry.js', __FILE__),
            [],
            '1.0.0',
            true
        );
        wp_localize_script(
            'ncwoo-checkout-telemetry',
            'NCWooTelemetry',
            [
                'endpoint' => esc_url_raw(rest_url('neurocheckout/v1/telemetry')),
                'nonce' => wp_create_nonce('wp_rest'),
                'slowRequestMs' => 5000,
            ]
        );
    }

    public function register_cron_interval(array $schedules): array
    {
        $intervalSeconds = $this->get_cron_interval_seconds();
        $scheduleSlug = $this->get_cron_schedule_slug($intervalSeconds);

        $schedules[$scheduleSlug] = [
            'interval' => $intervalSeconds,
            'display' => sprintf('NeuroCheckout Every %d Seconds', $intervalSeconds),
        ];

        return $schedules;
    }

    /**
     * Store API (Checkout Block) does not fire classic checkout hooks.
     * Bridge the order object to our existing order event pipeline.
     */
    public function on_checkout_create_order($order, $data): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }

        $this->events->attach_cart_context_to_order($order);
    }

    /**
     * Store API (Checkout Block) does not fire classic checkout hooks.
     * Bridge the order object to our existing order event pipeline.
     */
    public function on_store_api_checkout_order_processed($order): void
    {
        $orderId = 0;
        if ($order instanceof WC_Order) {
            $orderId = (int) $order->get_id();
            $this->events->attach_cart_context_to_order($order);
        } elseif (is_numeric($order)) {
            $orderId = (int) $order;
        }

        if ($orderId <= 0) {
            return;
        }

        $this->events->on_order_processed($orderId);
        $this->customerJourney->on_order_processed($orderId);
    }

    public function ensure_cron(): void
    {
        $this->config->ensure_defaults();

        if ($this->config->get_execution_mode() !== 'cron_module') {
            $this->unschedule_all_queue_events();
            return;
        }

        $intervalSeconds = $this->get_cron_interval_seconds();
        $desiredSchedule = $this->get_cron_schedule_slug($intervalSeconds);
        $requiresReschedule = false;

        if (function_exists('wp_get_scheduled_event')) {
            $scheduledEvent = wp_get_scheduled_event('ncwoo_process_queue');
            if (!is_object($scheduledEvent)) {
                $requiresReschedule = true;
            } else {
                $currentSchedule = (string) ($scheduledEvent->schedule ?? '');
                $requiresReschedule = ($currentSchedule !== $desiredSchedule);
            }
        } elseif (!wp_next_scheduled('ncwoo_process_queue')) {
            $requiresReschedule = true;
        }

        if ($requiresReschedule) {
            $this->unschedule_all_queue_events();
            wp_schedule_event(time() + 30, $desiredSchedule, 'ncwoo_process_queue');
        }
    }

    public function activate(): void
    {
        $this->db->install();
        $this->config->ensure_defaults();
        $this->ensure_cron();
    }

    public function deactivate(): void
    {
        $this->unschedule_all_queue_events();
    }

    /**
     * @param array<int,string> $links
     * @return array<int,string>
     */
    public function add_plugin_settings_link(array $links): array
    {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            return $links;
        }

        $settingsUrl = admin_url('admin.php?page=ncwoo-connector&tab=general');
        $settingsLink = '<a href="' . esc_url($settingsUrl) . '">' . esc_html__('Settings') . '</a>';
        array_unshift($links, $settingsLink);

        return $links;
    }

    private function get_cron_interval_seconds(): int
    {
        $configured = $this->config->get_int(NCWooConfig::OPTION_CRON_INTERVAL_SECONDS, 300);
        if ($configured < 60) {
            return 300;
        }

        return min(3600, $configured);
    }

    private function get_cron_schedule_slug(int $intervalSeconds): string
    {
        return 'ncwoo_every_' . max(60, $intervalSeconds) . 's';
    }

    private function unschedule_all_queue_events(): void
    {
        while (true) {
            $timestamp = wp_next_scheduled('ncwoo_process_queue');
            if ($timestamp === false) {
                break;
            }

            wp_unschedule_event((int) $timestamp, 'ncwoo_process_queue');
        }
    }

    private function is_neurocheckout_rest_route(WP_REST_Request $request): bool
    {
        $route = $request->get_route();
        return is_string($route) && strpos($route, '/neurocheckout/v1/') === 0;
    }

    private function request_accepts_gzip(WP_REST_Request $request): bool
    {
        $acceptEncoding = strtolower(trim((string) $request->get_header('Accept-Encoding')));
        return $acceptEncoding !== '' && strpos($acceptEncoding, 'gzip') !== false;
    }
}

register_activation_hook(__FILE__, [NCWooConnector::instance(), 'activate']);
register_deactivation_hook(__FILE__, [NCWooConnector::instance(), 'deactivate']);

add_action('plugins_loaded', static function (): void {
    if (!class_exists('WooCommerce')) {
        return;
    }

    NCWooConnector::instance();
});
