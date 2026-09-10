<?php

if (!defined('ABSPATH')) {
    exit;
}

final class NCWooDB
{
    public function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $event = $wpdb->prefix . 'ncwoo_event';
        $orderEvent = $wpdb->prefix . 'ncwoo_order_event';
        $nonce = $wpdb->prefix . 'ncwoo_nonce';
        $coupon = $wpdb->prefix . 'ncwoo_coupon';
        $recovery = $wpdb->prefix . 'ncwoo_recovery_token';
        $cronLog = $wpdb->prefix . 'ncwoo_cron_log';
        $securityThrottle = $wpdb->prefix . 'ncwoo_security_rate_limit';
        $telemetryEvent = $wpdb->prefix . 'ncwoo_telemetry_event';
        $customerJourneyEvent = $wpdb->prefix . 'ncwoo_customer_journey_event';

        $sql = [];

        $sql[] = "CREATE TABLE {$event} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            cart_id VARCHAR(80) NOT NULL,
            event_hash VARCHAR(64) NOT NULL,
            payload LONGTEXT NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            next_retry_at DATETIME NULL,
            priority SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_attempt_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unq_cart_id (cart_id),
            KEY idx_status_priority_created (status, priority, created_at),
            KEY idx_status_retry (status, next_retry_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$orderEvent} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id VARCHAR(80) NOT NULL,
            cart_id VARCHAR(80) NOT NULL,
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
            UNIQUE KEY unq_order_id (order_id),
            KEY idx_status_retry_created (status, next_retry_at, created_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$nonce} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            nonce_key VARCHAR(128) NOT NULL,
            expires_at INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unq_nonce (nonce_key),
            KEY idx_expires (expires_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$coupon} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_uid VARCHAR(120) NOT NULL,
            decision_id VARCHAR(64) NOT NULL DEFAULT '',
            action_id VARCHAR(64) NULL,
            cart_id VARCHAR(80) NOT NULL,
            customer_email VARCHAR(255) NOT NULL,
            rule_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            coupon_code VARCHAR(80) NOT NULL,
            discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            cart_fingerprint VARCHAR(64) NULL,
            recovery_url TEXT NULL,
            expires_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unq_request_uid (request_uid),
            KEY idx_decision (decision_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$recovery} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            token_hash VARCHAR(64) NOT NULL,
            mode VARCHAR(32) NOT NULL DEFAULT 'cart',
            cart_id VARCHAR(80) NOT NULL DEFAULT '',
            customer_email VARCHAR(255) NOT NULL DEFAULT '',
            coupon_code VARCHAR(80) NULL,
            cart_fingerprint VARCHAR(64) NULL,
            customer_id BIGINT UNSIGNED NULL,
            target_url TEXT NULL,
            payload_json LONGTEXT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unq_token_hash (token_hash),
            KEY idx_expires (expires_at),
            KEY idx_mode (mode)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$cronLog} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status VARCHAR(16) NOT NULL,
            processed_events INT UNSIGNED NOT NULL DEFAULT 0,
            execution_time_ms INT UNSIGNED NOT NULL DEFAULT 0,
            error_message VARCHAR(255) NULL,
            PRIMARY KEY (id),
            KEY idx_executed (executed_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$securityThrottle} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            shop_key VARCHAR(80) NOT NULL,
            endpoint VARCHAR(32) NOT NULL,
            client_ip VARCHAR(64) NOT NULL,
            attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
            window_started_at DATETIME NOT NULL,
            blocked_until DATETIME NULL,
            last_error VARCHAR(255) NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unq_scope (shop_key, endpoint, client_ip),
            KEY idx_blocked_until (blocked_until)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$telemetryEvent} (
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
        ) {$charset};";

        $sql[] = "CREATE TABLE {$customerJourneyEvent} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id VARCHAR(120) NOT NULL,
            event_type VARCHAR(140) NOT NULL,
            visitor_id VARCHAR(120) NULL,
            session_id VARCHAR(120) NULL,
            cart_id VARCHAR(120) NULL,
            customer_ref VARCHAR(140) NULL,
            event_hash VARCHAR(64) NOT NULL,
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
            UNIQUE KEY unq_event_id (event_id),
            KEY idx_status_retry_created (status, next_retry_at, created_at),
            KEY idx_cart_created (cart_id, created_at),
            KEY idx_customer_ref_created (customer_ref, created_at),
            KEY idx_event_type_created (event_type, created_at)
        ) {$charset};";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }
    }
}
