<?php
/**
 * Cleanup NeuroCheckout WooCommerce connector data on plugin uninstall.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * @param array<int,string> $patterns
 */
function ncwoo_uninstall_delete_like_rows(string $table, string $column, array $patterns): void
{
    global $wpdb;

    if ($patterns === []) {
        return;
    }

    $clauses = [];
    $values = [];
    foreach ($patterns as $pattern) {
        $clauses[] = "{$column} LIKE %s";
        $values[] = $pattern;
    }

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$table} WHERE " . implode(' OR ', $clauses),
            $values
        )
    );
}

function ncwoo_uninstall_table_exists(string $table): bool
{
    global $wpdb;

    $found = $wpdb->get_var(
        $wpdb->prepare(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s LIMIT 1',
            $table
        )
    );

    return is_string($found) && $found === $table;
}

function ncwoo_uninstall_drop_connector_tables(): void
{
    global $wpdb;

    $tablePrefix = $wpdb->prefix . 'ncwoo_';
    $tables = $wpdb->get_col(
        $wpdb->prepare(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE %s',
            $wpdb->esc_like($tablePrefix) . '%'
        )
    );

    foreach ($tables as $table) {
        if (!is_string($table) || strpos($table, $tablePrefix) !== 0) {
            continue;
        }

        $wpdb->query('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
    }
}

function ncwoo_uninstall_cleanup_blog(): void
{
    global $wpdb;

    if (function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook('ncwoo_process_queue');
    }

    ncwoo_uninstall_drop_connector_tables();

    $optionPatterns = [
        $wpdb->esc_like('ncwoo_') . '%',
        $wpdb->esc_like('_transient_ncwoo_') . '%',
        $wpdb->esc_like('_transient_timeout_ncwoo_') . '%',
    ];
    ncwoo_uninstall_delete_like_rows($wpdb->options, 'option_name', $optionPatterns);

    $metaPatterns = [
        $wpdb->esc_like('ncwoo_') . '%',
        $wpdb->esc_like('_ncwoo_') . '%',
    ];
    ncwoo_uninstall_delete_like_rows($wpdb->usermeta, 'meta_key', $metaPatterns);
    ncwoo_uninstall_delete_like_rows($wpdb->postmeta, 'meta_key', $metaPatterns);
    ncwoo_uninstall_delete_like_rows($wpdb->termmeta, 'meta_key', $metaPatterns);
    ncwoo_uninstall_delete_like_rows($wpdb->commentmeta, 'meta_key', $metaPatterns);

    $wcOrdersMetaTable = $wpdb->prefix . 'wc_orders_meta';
    if (ncwoo_uninstall_table_exists($wcOrdersMetaTable)) {
        ncwoo_uninstall_delete_like_rows($wcOrdersMetaTable, 'meta_key', $metaPatterns);
    }

    $actionSchedulerTable = $wpdb->prefix . 'actionscheduler_actions';
    if (ncwoo_uninstall_table_exists($actionSchedulerTable)) {
        ncwoo_uninstall_delete_like_rows($actionSchedulerTable, 'hook', [$wpdb->esc_like('ncwoo_') . '%']);
    }
}

function ncwoo_uninstall_cleanup_network(): void
{
    global $wpdb;

    if (function_exists('is_multisite') && is_multisite()) {
        $blogIds = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");
        foreach ($blogIds as $blogId) {
            switch_to_blog((int) $blogId);
            ncwoo_uninstall_cleanup_blog();
            restore_current_blog();
        }

        if (isset($wpdb->sitemeta)) {
            $siteMetaPatterns = [
                $wpdb->esc_like('ncwoo_') . '%',
                $wpdb->esc_like('_site_transient_ncwoo_') . '%',
                $wpdb->esc_like('_site_transient_timeout_ncwoo_') . '%',
            ];
            ncwoo_uninstall_delete_like_rows($wpdb->sitemeta, 'meta_key', $siteMetaPatterns);
        }
    } else {
        ncwoo_uninstall_cleanup_blog();
    }

    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
}

ncwoo_uninstall_cleanup_network();
