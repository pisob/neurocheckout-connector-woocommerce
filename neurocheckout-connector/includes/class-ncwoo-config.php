<?php

if (!defined('ABSPATH')) {
    exit;
}

final class NCWooConfig
{
    private const SECRET_PREFIX = 'ncwooenc:v1:';
    private const SECRET_CIPHER = 'aes-256-gcm';
    private const SECRET_IV_LENGTH = 12;
    private const SECRET_TAG_LENGTH = 16;

    public const OPTION_API_ENDPOINT = 'ncwoo_api_endpoint';
    public const OPTION_API_KEY = 'ncwoo_api_key';
    public const OPTION_API_KEY_NEXT = 'ncwoo_api_key_next';
    public const OPTION_API_KEY_PREV = 'ncwoo_api_key_prev';
    public const OPTION_API_KEY_PREV_UNTIL = 'ncwoo_api_key_prev_until';
    public const OPTION_API_KEY_ROTATION_ID = 'ncwoo_api_key_rotation_id';
    public const OPTION_SHOP_EXTERNAL_ID = 'ncwoo_shop_external_id';
    public const OPTION_INTERNAL_SECRET = 'ncwoo_internal_secret';
    public const OPTION_OPAQUE_RECOVERY_LINKS = 'ncwoo_opaque_recovery_links';

    public const OPTION_RECOVERY_ENABLED = 'ncwoo_recovery_enabled';
    public const OPTION_ALLOW_DISCOUNT = 'ncwoo_allow_discount';
    public const OPTION_ALLOW_GUEST = 'ncwoo_allow_guest';
    public const OPTION_MIN_CART_TOTAL = 'ncwoo_min_cart_total';
    public const OPTION_NO_DISCOUNT_MAX = 'ncwoo_no_discount_max';
    public const OPTION_DISCOUNT_5_MIN = 'ncwoo_discount_5_min';
    public const OPTION_DISCOUNT_5_MAX = 'ncwoo_discount_5_max';
    public const OPTION_DISCOUNT_10_MIN = 'ncwoo_discount_10_min';
    public const OPTION_MAX_DISCOUNT_PERCENT = 'ncwoo_max_discount_percent';

    public const OPTION_API_TEST_VALIDATED_AT = 'ncwoo_api_test_validated_at';
    public const OPTION_API_TEST_VALIDATION_FINGERPRINT = 'ncwoo_api_test_validation_fingerprint';

    public const OPTION_EXECUTION_MODE = 'ncwoo_execution_mode';
    public const OPTION_DEBUG_PANEL = 'ncwoo_debug_panel';
    public const OPTION_DEBUG_MODE = 'ncwoo_debug_mode';
    public const OPTION_DEBUG_ADVANCED = 'ncwoo_debug_advanced';
    public const OPTION_LAST_AUTO_RUN = 'ncwoo_last_auto_run';
    public const OPTION_AUTO_HOOK_LAST_CALL = 'ncwoo_auto_hook_last_call';
    public const OPTION_AUTO_HOOK_INTERVAL = 'ncwoo_auto_hook_interval';
    public const OPTION_AUTO_CLI_LAST_KICK = 'ncwoo_auto_cli_last_kick';
    public const OPTION_CRON_INTERVAL_SECONDS = 'ncwoo_cron_interval_seconds';
    public const OPTION_CRON_TOKEN = 'ncwoo_cron_token';
    public const OPTION_CRON_ALLOWED_IPS = 'ncwoo_cron_allowed_ips';
    public const OPTION_TRUSTED_PROXY_IPS = 'ncwoo_trusted_proxy_ips';
    public const OPTION_CRON_ALERT_ENABLED = 'ncwoo_cron_alert_enabled';
    public const OPTION_CRON_BLOCKED_UNTIL = 'ncwoo_cron_blocked_until';
    public const OPTION_CB_STATE = 'ncwoo_cb_state';
    public const OPTION_CB_FAILURE_COUNT = 'ncwoo_cb_failure_count';
    public const OPTION_CB_UPDATED_AT = 'ncwoo_cb_updated_at';
    public const OPTION_CB_FAILURE_THRESHOLD = 'ncwoo_cb_failure_threshold';
    public const OPTION_CB_COOLDOWN_SECONDS = 'ncwoo_cb_cooldown_seconds';
    public const OPTION_EVENT_RETENTION_DAYS = 'ncwoo_event_retention_days';
    public const OPTION_PURGE_BATCH_SIZE = 'ncwoo_purge_batch_size';
    public const OPTION_LAST_PURGE_RUN = 'ncwoo_last_purge_run';

    /**
     * @return array<string,mixed>
     */
    private function defaults(): array
    {
        $host = wp_parse_url(home_url('/'), PHP_URL_HOST);

        return [
            self::OPTION_API_ENDPOINT => '',
            self::OPTION_API_KEY => '',
            self::OPTION_API_KEY_NEXT => '',
            self::OPTION_API_KEY_PREV => '',
            self::OPTION_API_KEY_PREV_UNTIL => 0,
            self::OPTION_API_KEY_ROTATION_ID => '',
            self::OPTION_SHOP_EXTERNAL_ID => is_string($host) && $host !== '' ? $host : 'woo-shop',
            self::OPTION_INTERNAL_SECRET => $this->generate_secret(),
            self::OPTION_OPAQUE_RECOVERY_LINKS => '1',

            self::OPTION_RECOVERY_ENABLED => '1',
            self::OPTION_ALLOW_DISCOUNT => '0',
            self::OPTION_ALLOW_GUEST => '0',
            self::OPTION_MIN_CART_TOTAL => '',
            self::OPTION_NO_DISCOUNT_MAX => '',
            self::OPTION_DISCOUNT_5_MIN => '',
            self::OPTION_DISCOUNT_5_MAX => '',
            self::OPTION_DISCOUNT_10_MIN => '',
            self::OPTION_MAX_DISCOUNT_PERCENT => '',

            self::OPTION_API_TEST_VALIDATED_AT => 0,
            self::OPTION_API_TEST_VALIDATION_FINGERPRINT => '',

            self::OPTION_EXECUTION_MODE => 'cron_module',
            self::OPTION_DEBUG_PANEL => '0',
            self::OPTION_DEBUG_MODE => '0',
            self::OPTION_DEBUG_ADVANCED => '0',
            self::OPTION_LAST_AUTO_RUN => 0,
            self::OPTION_AUTO_HOOK_LAST_CALL => 0,
            self::OPTION_AUTO_HOOK_INTERVAL => 300,
            self::OPTION_AUTO_CLI_LAST_KICK => 0,
            self::OPTION_CRON_INTERVAL_SECONDS => 300,
            self::OPTION_CRON_TOKEN => $this->generate_secret(),
            self::OPTION_CRON_ALLOWED_IPS => '',
            self::OPTION_TRUSTED_PROXY_IPS => '',
            self::OPTION_CRON_ALERT_ENABLED => '1',
            self::OPTION_CRON_BLOCKED_UNTIL => 0,
            self::OPTION_CB_STATE => 'closed',
            self::OPTION_CB_FAILURE_COUNT => 0,
            self::OPTION_CB_UPDATED_AT => '',
            self::OPTION_CB_FAILURE_THRESHOLD => 5,
            self::OPTION_CB_COOLDOWN_SECONDS => 60,
            self::OPTION_EVENT_RETENTION_DAYS => 30,
            self::OPTION_PURGE_BATCH_SIZE => 500,
            self::OPTION_LAST_PURGE_RUN => 0,
        ];
    }

    public function ensure_defaults(): void
    {
        foreach ($this->defaults() as $key => $value) {
            if (get_option($key, null) === null) {
                $this->set($key, $value);
            }
        }

        $this->migrate_known_secrets();
    }

    public function get_string(string $key, string $default = ''): string
    {
        $raw = get_option($key, $default);
        $value = is_string($raw) ? trim($raw) : trim((string) $raw);

        if ($this->is_secret_option($key)) {
            return $this->get_secret_value($key, $value);
        }

        return $value;
    }

    public function get_int(string $key, int $default = 0): int
    {
        return (int) get_option($key, $default);
    }

    public function get_float(string $key, float $default = 0.0): float
    {
        return (float) get_option($key, $default);
    }

    public function get_bool(string $key, bool $default = false): bool
    {
        $raw = get_option($key, $default ? '1' : '0');
        $normalized = strtolower(trim((string) $raw));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param string|int|float $value
     */
    public function set(string $key, $value): void
    {
        if ($this->is_secret_option($key)) {
            $this->set_secret_value($key, trim((string) $value));
            return;
        }

        update_option($key, $value, false);
    }

    public function get_api_key(): string
    {
        return preg_replace('/\s+/', '', $this->get_string(self::OPTION_API_KEY));
    }

    public function get_pending_api_key(): string
    {
        return preg_replace('/\s+/', '', $this->get_string(self::OPTION_API_KEY_NEXT));
    }

    public function get_previous_api_key(): string
    {
        return preg_replace('/\s+/', '', $this->get_string(self::OPTION_API_KEY_PREV));
    }

    public function get_valid_previous_api_key(): string
    {
        $previous = $this->get_previous_api_key();
        if ($previous === '') {
            return '';
        }

        $validUntil = $this->get_int(self::OPTION_API_KEY_PREV_UNTIL, 0);
        if ($validUntil <= time()) {
            $this->set(self::OPTION_API_KEY_PREV, '');
            $this->set(self::OPTION_API_KEY_PREV_UNTIL, 0);
            return '';
        }

        return $previous;
    }

    public function get_shop_external_id(): string
    {
        return $this->get_string(self::OPTION_SHOP_EXTERNAL_ID);
    }

    public function get_internal_secret(): string
    {
        return $this->get_string(self::OPTION_INTERNAL_SECRET);
    }

    public function get_execution_mode(): string
    {
        $mode = strtolower($this->get_string(self::OPTION_EXECUTION_MODE, 'cron_module'));
        if ($mode === 'auto') {
            $mode = 'cron_module';
        }

        if (!in_array($mode, ['cron_module', 'cron'], true)) {
            return 'cron_module';
        }

        return $mode;
    }

    public function is_opaque_recovery_links_enabled(): bool
    {
        return $this->get_bool(self::OPTION_OPAQUE_RECOVERY_LINKS, true);
    }

    public function is_ia_configuration_ready(): bool
    {
        if (!$this->get_bool(self::OPTION_RECOVERY_ENABLED, true)) {
            $this->set(self::OPTION_RECOVERY_ENABLED, '1');
        }
        if (!$this->get_bool(self::OPTION_RECOVERY_ENABLED, true)) {
            return false;
        }

        $rawMinCartTotal = $this->get_string(self::OPTION_MIN_CART_TOTAL, '');
        if ($rawMinCartTotal === '' || !is_numeric($rawMinCartTotal)) {
            return false;
        }
        if ((float) $rawMinCartTotal < 0.0) {
            return false;
        }

        $rawMaxDiscount = $this->get_string(self::OPTION_MAX_DISCOUNT_PERCENT, '');
        if ($rawMaxDiscount === '' || !is_numeric($rawMaxDiscount)) {
            return false;
        }

        $maxDiscount = (float) $rawMaxDiscount;
        if ($maxDiscount < 0.0 || $maxDiscount > 100.0) {
            return false;
        }

        return true;
    }

    public function clear_api_test_validation_state(): void
    {
        $this->set(self::OPTION_API_TEST_VALIDATED_AT, 0);
        $this->set(self::OPTION_API_TEST_VALIDATION_FINGERPRINT, '');
    }

    public function build_api_test_validation_fingerprint(?string $apiKeyOverride = null): string
    {
        $endpoint = $this->get_string(self::OPTION_API_ENDPOINT);
        $apiKey = $apiKeyOverride !== null
            ? preg_replace('/\s+/', '', trim($apiKeyOverride))
            : $this->get_api_key();
        $shopExternalId = $this->get_shop_external_id();

        if ($endpoint === '' || $apiKey === '' || $shopExternalId === '') {
            return '';
        }

        return hash('sha256', implode('|', [$endpoint, $apiKey, $shopExternalId]));
    }

    public function mark_api_test_validation_success(?string $apiKeyOverride = null): void
    {
        $fingerprint = $this->build_api_test_validation_fingerprint($apiKeyOverride);
        if ($fingerprint === '') {
            $this->clear_api_test_validation_state();
            return;
        }

        $this->set(self::OPTION_API_TEST_VALIDATION_FINGERPRINT, $fingerprint);
        $this->set(self::OPTION_API_TEST_VALIDATED_AT, time());
    }

    public function has_operational_evidence(): bool
    {
        global $wpdb;

        $eventsTable = $wpdb->prefix . 'ncwoo_event';
        $cronTable = $wpdb->prefix . 'ncwoo_cron_log';

        $sentCount = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$eventsTable} WHERE status IN (%s,%s)",
                'sent',
                'cleared'
            )
        );
        if ($sentCount > 0) {
            return true;
        }

        $cronCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$cronTable}");
        return $cronCount > 0;
    }

    public function is_api_test_validation_current(): bool
    {
        $validatedAt = $this->get_int(self::OPTION_API_TEST_VALIDATED_AT, 0);
        if ($validatedAt <= 0) {
            return false;
        }

        $storedFingerprint = $this->get_string(self::OPTION_API_TEST_VALIDATION_FINGERPRINT, '');
        if ($storedFingerprint === '') {
            return false;
        }

        $currentFingerprint = $this->build_api_test_validation_fingerprint();
        if ($currentFingerprint === '') {
            return false;
        }

        return hash_equals($storedFingerprint, $currentFingerprint);
    }

    /**
     * @return array{ready:bool,reason:string}
     */
    public function get_execution_readiness(): array
    {
        $apiEndpoint = $this->get_string(self::OPTION_API_ENDPOINT);
        $apiKey = $this->get_api_key();
        $shopExternalId = $this->get_shop_external_id();

        if ($apiEndpoint === '' || $apiKey === '' || $shopExternalId === '') {
            return ['ready' => false, 'reason' => 'missing_api_configuration'];
        }

        if (!$this->is_ia_configuration_ready()) {
            return ['ready' => false, 'reason' => 'missing_ia_configuration'];
        }

        if (!$this->is_api_test_validation_current()) {
            return ['ready' => false, 'reason' => 'api_test_gate_not_validated'];
        }

        return ['ready' => true, 'reason' => 'ready'];
    }

    private function generate_secret(): string
    {
        try {
            return bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            return hash('sha256', wp_generate_uuid4() . '|' . time());
        }
    }

    /**
     * @return array<int,string>
     */
    private function secret_options(): array
    {
        return [
            self::OPTION_API_KEY,
            self::OPTION_API_KEY_NEXT,
            self::OPTION_API_KEY_PREV,
            self::OPTION_INTERNAL_SECRET,
            self::OPTION_CRON_TOKEN,
        ];
    }

    private function is_secret_option(string $key): bool
    {
        return in_array($key, $this->secret_options(), true);
    }

    private function get_secret_value(string $key, string $rawValue): string
    {
        if ($rawValue === '') {
            return '';
        }

        if (strpos($rawValue, self::SECRET_PREFIX) === 0) {
            $decrypted = $this->decrypt_secret(substr($rawValue, strlen(self::SECRET_PREFIX)));
            return $decrypted !== null ? trim($decrypted) : '';
        }

        // Lazy migration from plaintext wp_options used by previous connector builds.
        $this->set_secret_value($key, $rawValue);
        return $rawValue;
    }

    private function set_secret_value(string $key, string $value): void
    {
        if ($value === '') {
            update_option($key, '', false);
            return;
        }

        if (strpos($value, self::SECRET_PREFIX) === 0) {
            update_option($key, $value, false);
            return;
        }

        $encrypted = $this->encrypt_secret($value);
        update_option($key, $encrypted !== null ? self::SECRET_PREFIX . $encrypted : $value, false);
    }

    private function migrate_known_secrets(): void
    {
        foreach ($this->secret_options() as $key) {
            $this->get_string($key, '');
        }
    }

    private function encryption_key(): string
    {
        $material = implode('|', [
            defined('AUTH_KEY') ? (string) AUTH_KEY : '',
            defined('SECURE_AUTH_KEY') ? (string) SECURE_AUTH_KEY : '',
            defined('LOGGED_IN_KEY') ? (string) LOGGED_IN_KEY : '',
            defined('NONCE_KEY') ? (string) NONCE_KEY : '',
            defined('DB_NAME') ? (string) DB_NAME : '',
            function_exists('wp_salt') ? wp_salt('auth') : '',
            'neurocheckout-connector-woocommerce',
            'secret-config-v1',
        ]);

        return hash('sha256', $material, true);
    }

    private function encrypt_secret(string $value): ?string
    {
        if (!function_exists('openssl_encrypt')) {
            return null;
        }

        try {
            $iv = random_bytes(self::SECRET_IV_LENGTH);
            $tag = '';
            $ciphertext = openssl_encrypt(
                $value,
                self::SECRET_CIPHER,
                $this->encryption_key(),
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                '',
                self::SECRET_TAG_LENGTH
            );

            if (!is_string($ciphertext) || !is_string($tag) || $tag === '') {
                return null;
            }

            return base64_encode($iv . $tag . $ciphertext);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function decrypt_secret(string $payload): ?string
    {
        if (!function_exists('openssl_decrypt')) {
            return null;
        }

        try {
            $binary = base64_decode($payload, true);
            if (!is_string($binary) || strlen($binary) <= (self::SECRET_IV_LENGTH + self::SECRET_TAG_LENGTH)) {
                return null;
            }

            $iv = substr($binary, 0, self::SECRET_IV_LENGTH);
            $tag = substr($binary, self::SECRET_IV_LENGTH, self::SECRET_TAG_LENGTH);
            $ciphertext = substr($binary, self::SECRET_IV_LENGTH + self::SECRET_TAG_LENGTH);

            $decrypted = openssl_decrypt(
                $ciphertext,
                self::SECRET_CIPHER,
                $this->encryption_key(),
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            return is_string($decrypted) ? $decrypted : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
