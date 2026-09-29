<?php
declare(strict_types=1);

namespace NeuroCheckout\WooCommerce\Community;

use PDO;
use RuntimeException;

/** Runtime inspected only after source HMAC authentication. */
final class WooSourceSnapshotFactory
{
    public static function create(int $scope, array $configuration): WooSourceSnapshot
    {
        global $wpdb, $wp_filter;
        if (($configuration['nativeScope'] ?? null) !== $scope || ($configuration['platform'] ?? null) !== 'woocommerce'
            || ($configuration['environment'] ?? null) !== 'staging'
            || !defined('WC_VERSION') || !self::supportsVersion(WC_VERSION)
            || !defined('WP_CONTENT_DIR') || file_exists(WP_CONTENT_DIR . '/db.php')
            || !is_object($wpdb) || get_class($wpdb) !== 'wpdb'
            || $scope !== (int) get_current_blog_id()
            || (is_multisite() ? $scope !== (int) $wpdb->blogid : $scope !== 1)
            || $wpdb->prefix !== $wpdb->get_blog_prefix($scope)
            || (defined('MYSQL_CLIENT_FLAGS') && MYSQL_CLIENT_FLAGS !== 0)) {
            throw new RuntimeException('source_schema_unavailable');
        }
        // Default native tables only. Do not silently bypass custom storage,
        // routing or option filters when opening the separate PDO connection.
        foreach (['pre_option',
            'pre_option_woocommerce_custom_orders_table_enabled', 'option_woocommerce_custom_orders_table_enabled'] as $hook) {
            if (has_filter($hook) !== false) { throw new RuntimeException('source_schema_unavailable'); }
        }
        // Core callbacks do not introduce custom cart/order storage: Store API
        // sessions use the same native table; admin callbacks add report and
        // fulfillment stores only. Unknown third-party handlers remain refused.
        $nativeHooks = [
            'woocommerce_session_handler' => [
                'Automattic\\WooCommerce\\StoreApi\\Authentication::maybe_use_store_api_session_handler',
            ],
            'woocommerce_data_stores' => [
                'Automattic\\WooCommerce\\Admin\\API\\Init::add_data_stores',
                'Automattic\\WooCommerce\\Admin\\Features\\Fulfillments\\FulfillmentsController::register_data_stores',
            ],
        ];
        foreach ($nativeHooks as $name => $allowed) {
            if (has_filter($name) === false) { continue; }
            $hook = $wp_filter[$name] ?? null;
            if (!($hook instanceof \WP_Hook)) { throw new RuntimeException('source_schema_unavailable'); }
            foreach ($hook->callbacks as $callbacks) {
                foreach ($callbacks as $callback) {
                    $fn = $callback['function'];
                    if (!is_array($fn) || count($fn) !== 2 || !is_string($fn[1])) {
                        throw new RuntimeException('source_schema_unavailable');
                    }
                    $class = is_object($fn[0]) ? get_class($fn[0]) : $fn[0];
                    if (!is_string($class) || !in_array($class . '::' . $fn[1], $allowed, true)) {
                        throw new RuntimeException('source_schema_unavailable');
                    }
                }
            }
        }
        $hook = $wp_filter['woocommerce_order_data_store'] ?? null;
        if ($hook !== null) {
            if (!($hook instanceof \WP_Hook)) { throw new RuntimeException('source_schema_unavailable'); }
            foreach ($hook->callbacks as $callbacks) {
                foreach ($callbacks as $callback) {
                    $fn = $callback['function'];
                    if (!is_array($fn) || count($fn) !== 2 || !is_object($fn[0])
                        || get_class($fn[0]) !== 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController'
                        || $fn[1] !== 'get_orders_data_store') { throw new RuntimeException('source_schema_unavailable'); }
                }
            }
        }
        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $constant) {
            if (!defined($constant)) { throw new RuntimeException('source_schema_unavailable'); }
        }
        [$dsn, $user, $password] = self::connectionParameters(DB_HOST, DB_NAME, DB_USER, DB_PASSWORD);
        $connection = new PDO($dsn, $user, $password, [PDO::ATTR_TIMEOUT => 2,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false, PDO::ATTR_PERSISTENT => false]);
        return new WooSourceSnapshot($connection, $wpdb->prefix, $scope, $configuration['secret'], $configuration['shopId']);
    }

    /** Explicit supported native-storage version range. */
    public static function supportsVersion(string $version): bool
    {
        // Explicit native-storage range. Schema, custom stores and DB transport
        // are still checked independently; unknown future majors fail closed.
        return preg_match('/^10\.[1-8]\.[0-9]+$/D', $version) === 1;
    }

    /** Pure validation. Never log the return value (contains native credentials). */
    public static function connectionParameters(string $host, string $database, string $user, string $password): array
    {
        if (!preg_match('/^[A-Za-z0-9_$-]+$/D', $database) || $user === ''
            || strpos($user, "\0") !== false || strpos($password, "\0") !== false) {
            throw new RuntimeException('source_schema_unavailable');
        }
        if (preg_match('#^localhost:(/[A-Za-z0-9_./-]+)$#D', $host, $parts)) {
            $target = 'unix_socket=' . $parts[1];
        } elseif (preg_match('/^([A-Za-z0-9.-]+)(?::([0-9]{1,5}))?$/D', $host, $parts)) {
            $port = $parts[2] ?? '3306';
            if ((int) $port < 1 || (int) $port > 65535) { throw new RuntimeException('source_schema_unavailable'); }
            $target = 'host=' . $parts[1] . ';port=' . $port;
        } else { throw new RuntimeException('source_schema_unavailable'); }
        return ['mysql:' . $target . ';dbname=' . $database . ';charset=utf8mb4', $user, $password];
    }
}
