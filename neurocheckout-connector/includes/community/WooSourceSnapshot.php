<?php
declare(strict_types=1);

namespace NeuroCheckout\WooCommerce\Community;

use PDO;
use RuntimeException;

/** Bounded native staging snapshot. No Woo objects, recalculation or writes. */
final class WooSourceSnapshot
{
    private PDO $db;
    private string $prefix;
    private int $blogId;
    private WooSessionProjection $sessions;
    private float $started = 0;
    private const BASE_TABLES = ['options', 'posts', 'postmeta', 'woocommerce_sessions',
        'term_relationships', 'term_taxonomy', 'terms'];
    private const PRODUCT_META = ['_sku', '_price', '_regular_price', '_sale_price',
        '_sale_price_dates_from', '_sale_price_dates_to', '_stock', '_stock_status',
        '_manage_stock', '_backorders', '_tax_status', '_tax_class', '_weight',
        '_length', '_width', '_height', '_virtual', '_downloadable', '_thumbnail_id'];

    public function __construct(PDO $db, string $prefix, int $blogId, string $secret, string $shopId)
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $prefix) || $blogId < 1
            || $db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') { throw new RuntimeException('source_schema_unavailable'); }
        $this->db = $db; $this->prefix = $prefix; $this->blogId = $blogId;
        $this->sessions = new WooSessionProjection($secret, $blogId, $shopId);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function capture(int $scope): array
    {
        if ($scope !== $this->blogId) { throw new RuntimeException('source_scope_inconsistent'); }
        if ($this->db->inTransaction()) { throw new RuntimeException('source_unavailable'); }
        $this->started = microtime(true);
        $version = (string) $this->db->getAttribute(PDO::ATTR_SERVER_VERSION);
        $this->db->exec(stripos($version, 'mariadb') !== false
            ? 'SET SESSION max_statement_time=1' : 'SET SESSION MAX_EXECUTION_TIME=1000');
        $this->checkEngines(self::BASE_TABLES);
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            $options = $this->options();
            $hpos = ($options['woocommerce_custom_orders_table_enabled'] ?? 'no') === 'yes';
            if ($hpos) { $this->checkEngines(['wc_orders', 'wc_orders_meta']); }
            $products = $this->products($options);
            // Use one time boundary for the whole snapshot, not one per row.
            $now = time();
            $nativeSessions = $this->rows('SELECT session_key, LEFT(session_value,65537) AS session_value, session_expiry FROM '
                . $this->table('woocommerce_sessions') . ' WHERE session_expiry>? ORDER BY session_id', [$now], 256);
            $carts = [];
            foreach ($nativeSessions as $native) {
                $record = $this->sessions->project((string) $native['session_key'], (string) $native['session_value'], (int) $native['session_expiry'], $now);
                if ($record === null || isset($carts[$record['sourceId']])) { throw new RuntimeException('source_scope_inconsistent'); }
                $record['payload']['source_schema'] = 'woocommerce-native-v1';
                $record['payload']['session_status'] = 'present';
                $record['payload']['store_currency_hint'] = $options['woocommerce_currency'];
                $record['payload']['conversion_status'] = 'no_linked_order';
                $record['payload']['orders'] = [];
                $record['payload']['order_storage'] = $hpos ? 'hpos' : 'posts';
                $carts[$record['sourceId']] = $record;
            }
            // Read ONLY the authoritative order store, never its backup copy.
            // Includes linked orders whose session was cleared after checkout.
            $orders = $this->orders($hpos);
            $seenOrders = [];
            foreach ($orders as $native) {
                if (($native['currency'] !== null && !preg_match('/^[A-Z]{3}$/D', (string) $native['currency']))
                    || ($native['total_amount'] !== null && !preg_match('/^-?[0-9]{1,26}(?:\.[0-9]{1,8})?$/D', (string) $native['total_amount']))) {
                    throw new RuntimeException('source_schema_unavailable');
                }
                $id = (string) $native['id'];
                if (isset($seenOrders[$id])) { throw new RuntimeException('source_scope_inconsistent'); }
                $seenOrders[$id] = true;
                $reference = $this->sessions->cartReference((string) $native['cart_id']);
                unset($native['cart_id']); // Never export session/runtime identifiers.
                if (!isset($carts[$reference])) {
                    $carts[$reference] = ['kind' => 'cart', 'sourceId' => $reference, 'payload' => [
                        'source_schema' => 'woocommerce-native-v1', 'session_status' => 'absent',
                        'items' => [], 'customer' => (object) [], 'stored_totals' => (object) [],
                        'currency_status' => 'not_exported', 'store_currency_hint' => $options['woocommerce_currency'],
                        'orders' => [], 'order_storage' => $hpos ? 'hpos' : 'posts',
                    ]];
                }
                // A linked draft/cancelled/refunded order also suppresses an
                // automatic abandonment inference. Payment/revenue is NOT proven.
                $carts[$reference]['payload']['status'] = 'order_present';
                $carts[$reference]['payload']['conversion_status'] = 'linked_order_not_payment_proof';
                $carts[$reference]['payload']['orders'][] = $native;
                if (count($carts[$reference]['payload']['orders']) > 32) { throw new RuntimeException('source_snapshot_capacity'); }
            }
            if (count($products) + count($carts) > 256) { throw new RuntimeException('source_snapshot_capacity'); }
            ksort($carts);
            $result = array_merge($products, array_values($carts));
            foreach ($result as $record) {
                if (strlen(json_encode($record['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 16384) {
                    throw new RuntimeException('source_snapshot_capacity');
                }
            }
            $this->checkTime();
            $this->db->rollBack();
            return $result;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $error;
        }
    }

    private function options(): array
    {
        $rows = $this->rows('SELECT option_name, LEFT(option_value,129) AS option_value FROM ' . $this->table('options')
            . " WHERE option_name IN ('woocommerce_custom_orders_table_enabled','woocommerce_currency','woocommerce_prices_include_tax') ORDER BY option_name", [], 3);
        $options = [];
        foreach ($rows as $row) {
            if (array_key_exists($row['option_name'], $options)) { throw new RuntimeException('source_schema_unavailable'); }
            $options[$row['option_name']] = $row['option_value'];
        }
        if (!preg_match('/^[A-Z]{3}$/D', $options['woocommerce_currency'] ?? '')
            || !in_array($options['woocommerce_custom_orders_table_enabled'] ?? 'no', ['yes', 'no'], true)
            || !in_array($options['woocommerce_prices_include_tax'] ?? '', ['yes', 'no'], true)) {
            throw new RuntimeException('source_schema_unavailable');
        }
        return $options;
    }

    private function products(array $options): array
    {
        $rows = $this->rows('SELECT ID, post_parent, post_type, post_status, LEFT(post_title,16385) AS post_title, post_name,
            LEFT(post_excerpt,16385) AS post_excerpt, post_modified_gmt FROM ' . $this->table('posts')
            . " WHERE post_type IN ('product','product_variation') AND post_status NOT IN ('trash','auto-draft') ORDER BY ID", [], 256);
        $ids = []; foreach ($rows as $row) { $ids[(string) $row['ID']] = $row['post_type']; }
        $result = [];
        foreach ($rows as $row) {
            if ($row['post_type'] === 'product_variation' && ($ids[(string) $row['post_parent']] ?? '') !== 'product') {
                throw new RuntimeException('source_scope_inconsistent');
            }
            $meta = $this->rows('SELECT meta_key, LEFT(meta_value,16385) AS meta_value FROM ' . $this->table('postmeta')
                . ' WHERE post_id=? AND (meta_key IN (' . implode(',', array_fill(0, count(self::PRODUCT_META), '?'))
                . ") OR LEFT(meta_key,10)='attribute_') ORDER BY meta_id", array_merge([$row['ID']], self::PRODUCT_META), 128);
            $values = [];
            foreach ($meta as $entry) {
                if (array_key_exists($entry['meta_key'], $values)) { throw new RuntimeException('source_scope_inconsistent'); }
                $values[$entry['meta_key']] = $entry['meta_value'];
            }
            ksort($values);
            $row['native_meta'] = (object) $values;
            $row['terms'] = $this->rows('SELECT tt.taxonomy, t.term_id, t.name, t.slug FROM ' . $this->table('term_relationships')
                . ' tr JOIN ' . $this->table('term_taxonomy') . ' tt ON tt.term_taxonomy_id=tr.term_taxonomy_id JOIN '
                . $this->table('terms') . " t ON t.term_id=tt.term_id WHERE tr.object_id=? AND (tt.taxonomy IN ('product_cat','product_type','product_visibility')
                OR LEFT(tt.taxonomy,3)='pa_') ORDER BY tt.taxonomy,t.term_id", [$row['ID']], 128);
            $row['source_schema'] = 'woocommerce-native-v1';
            $row['store_currency_hint'] = $options['woocommerce_currency'];
            $row['prices_include_tax'] = $options['woocommerce_prices_include_tax'];
            $row['pricing_status'] = 'stored_values_not_recalculated';
            $result[] = ['kind' => 'product', 'sourceId' => (string) $row['ID'], 'payload' => $row];
        }
        return $result;
    }

    private function orders(bool $hpos): array
    {
        if ($hpos) {
            return $this->rows('SELECT o.id, o.status, o.currency, o.total_amount, o.date_created_gmt, o.date_updated_gmt,
                LEFT(m.meta_value,257) AS cart_id FROM ' . $this->table('wc_orders') . ' o JOIN '
                . $this->table('wc_orders_meta') . " m ON m.order_id=o.id WHERE o.type='shop_order' AND m.meta_key='_ncwoo_cart_id'
                AND m.meta_value<>'' ORDER BY o.id,m.id", [], 256);
        }
        $orders = $this->rows('SELECT p.ID AS id, p.post_status AS status, p.post_date_gmt AS date_created_gmt,
            p.post_modified_gmt AS date_updated_gmt, LEFT(m.meta_value,257) AS cart_id FROM ' . $this->table('posts') . ' p JOIN '
            . $this->table('postmeta') . " m ON m.post_id=p.ID WHERE p.post_type='shop_order' AND m.meta_key='_ncwoo_cart_id'
            AND m.meta_value<>'' ORDER BY p.ID,m.meta_id", [], 256);
        foreach ($orders as &$order) {
            $meta = $this->rows('SELECT meta_key, LEFT(meta_value,129) AS meta_value FROM ' . $this->table('postmeta')
                . " WHERE post_id=? AND meta_key IN ('_order_currency','_order_total') ORDER BY meta_id", [$order['id']], 2);
            $values = [];
            foreach ($meta as $entry) {
                if (array_key_exists($entry['meta_key'], $values)) { throw new RuntimeException('source_scope_inconsistent'); }
                $values[$entry['meta_key']] = $entry['meta_value'];
            }
            $order['currency'] = $values['_order_currency'] ?? null;
            $order['total_amount'] = $values['_order_total'] ?? null;
        }
        unset($order);
        return $orders;
    }

    private function checkEngines(array $tables): void
    {
        $names = array_map(function ($table) { return $this->prefix . $table; }, $tables);
        $rows = $this->rows('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('
            . implode(',', array_fill(0, count($names), '?')) . ')', $names, count($names));
        if (count($rows) !== count($tables)) { throw new RuntimeException('source_schema_unavailable'); }
        foreach ($rows as $row) {
            if (strcasecmp((string) $row['ENGINE'], 'InnoDB') !== 0) { throw new RuntimeException('source_snapshot_not_transactional'); }
        }
    }

    private function table(string $name): string { return '`' . $this->prefix . $name . '`'; }
    private function checkTime(): void
    {
        if (microtime(true) - $this->started > 5) { throw new RuntimeException('source_snapshot_timeout'); }
    }
    private function rows(string $sql, array $parameters, int $limit): array
    {
        $this->checkTime();
        $statement = $this->db->prepare($sql . ' LIMIT ' . ($limit + 1));
        $statement->execute($parameters); $rows = $statement->fetchAll();
        if (count($rows) > $limit) { throw new RuntimeException('source_snapshot_capacity'); }
        return $rows;
    }
}
