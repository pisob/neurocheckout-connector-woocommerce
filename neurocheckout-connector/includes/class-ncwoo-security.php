<?php

if (!defined('ABSPATH')) {
    exit;
}

final class NCWooSecurity
{
    private const MAX_TIME_DRIFT = 120;
    // Cover the entire accepted past/future timestamp window.
    private const NONCE_TTL = (2 * self::MAX_TIME_DRIFT) + 1;
    private const THROTTLE_WINDOW_SECONDS = 300;
    private const THROTTLE_MAX_FAILURES = 10;
    private const THROTTLE_BLOCK_SECONDS = 600;

    private NCWooConfig $config;
    private NCWooDB $db;

    public function __construct(NCWooConfig $config, NCWooDB $db)
    {
        $this->config = $config;
        $this->db = $db;
    }

    /**
     * @return array<string,mixed>
     */
    public function validate_signed_request(WP_REST_Request $request, string $nonceNamespace, bool $allowPreviousKey = false): array
    {
        $shopKey = $this->resolve_shop_key();
        $endpoint = $this->normalize_endpoint($nonceNamespace);
        $clientIp = $this->resolve_client_ip();
        $retryAfterSeconds = $this->get_retry_after_seconds($shopKey, $endpoint, $clientIp);
        if ($retryAfterSeconds > 0) {
            return $this->fail('Too many invalid requests', 429, $retryAfterSeconds);
        }

        $timestamp = (int) $request->get_header('X-Neuro-Timestamp');
        $nonce = trim((string) $request->get_header('X-Neuro-Nonce'));
        $signature = trim((string) $request->get_header('X-Neuro-Signature'));

        if ($timestamp <= 0 || $nonce === '' || $signature === '') {
            return $this->fail_and_track($shopKey, $endpoint, $clientIp, 'Missing security headers', 403);
        }

        if (abs(time() - $timestamp) > self::MAX_TIME_DRIFT) {
            return $this->fail_and_track($shopKey, $endpoint, $clientIp, 'Signature expired', 403);
        }

        $currentApiKey = $this->normalize_key($this->config->get_api_key());
        if ($currentApiKey === '') {
            return $this->fail('Connector API key missing', 409);
        }

        $validKeys = [$currentApiKey];
        if ($allowPreviousKey) {
            $previousKey = $this->normalize_key($this->config->get_valid_previous_api_key());
            if ($previousKey !== '' && !in_array($previousKey, $validKeys, true)) {
                $validKeys[] = $previousKey;
            }
        }

        $providedApiKey = $this->normalize_key((string) $request->get_header('X-API-Key'));
        $providedApiHash = trim((string) $request->get_header('X-Neuro-ApiKeyHash'));

        $rawBody = (string) $request->get_body();
        $authenticated = false;
        $authMode = null;
        $usedSecret = null;

        if ($providedApiKey !== '') {
            foreach ($validKeys as $candidateKey) {
                if ($candidateKey === '' || strlen($candidateKey) !== strlen($providedApiKey)) {
                    continue;
                }
                if (!hash_equals($candidateKey, $providedApiKey)) {
                    continue;
                }

                $expected = hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $rawBody, $candidateKey);
                if (!hash_equals($expected, $signature)) {
                    return $this->fail_and_track($shopKey, $endpoint, $clientIp, 'Invalid signature', 403);
                }

                $authenticated = true;
                $authMode = 'api_key';
                $usedSecret = $candidateKey;
                break;
            }

            if (!$authenticated) {
                return $this->fail_and_track($shopKey, $endpoint, $clientIp, 'Invalid API key', 403);
            }
        }

        if (!$authenticated) {
            foreach ($validKeys as $candidateKey) {
                $candidateHash = hash('sha256', $candidateKey);
                if ($providedApiHash === '' || !hash_equals($candidateHash, $providedApiHash)) {
                    continue;
                }

                $expected = hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $rawBody, $candidateHash);
                if (!hash_equals($expected, $signature)) {
                    return $this->fail_and_track($shopKey, $endpoint, $clientIp, 'Invalid signature', 403);
                }

                $authenticated = true;
                $authMode = 'api_key_hash';
                $usedSecret = $candidateHash;
                break;
            }

            if (!$authenticated) {
                return $this->fail_and_track($shopKey, $endpoint, $clientIp, 'Invalid API hash', 403);
            }
        }

        if (!$this->register_nonce($nonceNamespace . '-' . $nonce, self::NONCE_TTL)) {
            return $this->fail_and_track($shopKey, $endpoint, $clientIp, 'Replay detected', 409);
        }

        $this->clear_throttle($shopKey, $endpoint, $clientIp);

        return [
            'success' => true,
            'status' => 200,
            'auth_mode' => $authMode,
            'signature_secret' => $usedSecret,
            'client_ip' => $clientIp,
        ];
    }

    public function resolve_client_ip(): string
    {
        $remoteAddr = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $fallback = $remoteAddr !== '' ? $remoteAddr : 'unknown';
        $trustedProxyRules = $this->parse_ip_rules($this->config->get_string(NCWooConfig::OPTION_TRUSTED_PROXY_IPS));

        if ($remoteAddr === '' || !$this->ip_matches_any_rule($remoteAddr, $trustedProxyRules)) {
            return $fallback;
        }

        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $header) {
            $candidate = trim((string) ($_SERVER[$header] ?? ''));
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $forwardedFor = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwardedFor !== '') {
            foreach (explode(',', $forwardedFor) as $part) {
                $candidate = trim($part);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return $fallback;
    }

    /**
     * @return array<int,string>
     */
    public function parse_ip_rules(string $rawRules): array
    {
        $parts = preg_split('/[\s,;]+/', trim($rawRules));
        return array_values(array_filter(array_map('trim', is_array($parts) ? $parts : [])));
    }

    /**
     * @param array<int,string> $rules
     */
    public function ip_matches_any_rule(string $clientIp, array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($this->ip_matches_rule($clientIp, $rule)) {
                return true;
            }
        }

        return false;
    }

    public function ip_matches_rule(string $clientIp, string $rule): bool
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

    private function register_nonce(string $nonceKey, int $ttlSeconds): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'ncwoo_nonce';
        $now = time();

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
                $now + max(1, $ttlSeconds)
            )
        );

        return $inserted === 1;
    }

    private function normalize_key(string $value): string
    {
        return preg_replace('/\s+/', '', trim($value));
    }

    private function normalize_endpoint(string $nonceNamespace): string
    {
        $endpoint = strtolower(trim($nonceNamespace));
        $endpoint = preg_replace('/[^a-z0-9_-]+/', '-', $endpoint) ?: 'unknown';

        return substr($endpoint, 0, 32);
    }

    private function resolve_shop_key(): string
    {
        $shopKey = trim($this->config->get_shop_external_id());
        if ($shopKey === '') {
            $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
            $shopKey = is_string($host) && $host !== '' ? $host : 'woo-shop';
        }

        $shopKey = preg_replace('/[^a-zA-Z0-9_.:-]+/', '-', $shopKey) ?: 'woo-shop';
        return substr($shopKey, 0, 80);
    }

    private function ensure_throttle_table(): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $wpdb->prefix . 'ncwoo_security_rate_limit';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
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
        ) {$charset};");

        $ready = true;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function get_throttle_row(string $shopKey, string $endpoint, string $clientIp): ?array
    {
        global $wpdb;
        $this->ensure_throttle_table();

        $table = $wpdb->prefix . 'ncwoo_security_rate_limit';
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, attempt_count, window_started_at, blocked_until
                 FROM {$table}
                 WHERE shop_key = %s AND endpoint = %s AND client_ip = %s
                 LIMIT 1",
                substr($shopKey, 0, 80),
                substr($endpoint, 0, 32),
                substr($clientIp, 0, 64)
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    private function get_retry_after_seconds(string $shopKey, string $endpoint, string $clientIp): int
    {
        $row = $this->get_throttle_row($shopKey, $endpoint, $clientIp);
        if (!is_array($row)) {
            return 0;
        }

        $blockedUntil = trim((string) ($row['blocked_until'] ?? ''));
        if ($blockedUntil === '') {
            return 0;
        }

        $blockedUntilTs = strtotime($blockedUntil . ' UTC') ?: strtotime($blockedUntil) ?: 0;
        return $blockedUntilTs > time() ? max(1, $blockedUntilTs - time()) : 0;
    }

    private function record_failure(string $shopKey, string $endpoint, string $clientIp, string $reason): int
    {
        global $wpdb;
        $this->ensure_throttle_table();

        $table = $wpdb->prefix . 'ncwoo_security_rate_limit';
        $nowTs = time();
        $now = gmdate('Y-m-d H:i:s', $nowTs);
        $windowResetThreshold = gmdate('Y-m-d H:i:s', $nowTs - self::THROTTLE_WINDOW_SECONDS);
        $row = $this->get_throttle_row($shopKey, $endpoint, $clientIp);

        $attemptCount = 1;
        $windowStartedAt = $now;
        $blockedUntil = null;

        if (is_array($row)) {
            $currentBlockedUntil = trim((string) ($row['blocked_until'] ?? ''));
            $currentBlockedUntilTs = $currentBlockedUntil !== ''
                ? (strtotime($currentBlockedUntil . ' UTC') ?: strtotime($currentBlockedUntil) ?: 0)
                : 0;
            if ($currentBlockedUntilTs > $nowTs) {
                return max(1, $currentBlockedUntilTs - $nowTs);
            }

            $storedWindowStartedAt = trim((string) ($row['window_started_at'] ?? ''));
            $windowStillOpen = $storedWindowStartedAt !== '' && $storedWindowStartedAt >= $windowResetThreshold;
            $attemptCount = $windowStillOpen ? ((int) ($row['attempt_count'] ?? 0) + 1) : 1;
            $windowStartedAt = $windowStillOpen && $storedWindowStartedAt !== '' ? $storedWindowStartedAt : $now;
        }

        if ($attemptCount > self::THROTTLE_MAX_FAILURES) {
            $blockedUntil = gmdate('Y-m-d H:i:s', $nowTs + self::THROTTLE_BLOCK_SECONDS);
        }

        $data = [
            'shop_key' => substr($shopKey, 0, 80),
            'endpoint' => substr($endpoint, 0, 32),
            'client_ip' => substr($clientIp, 0, 64),
            'attempt_count' => max(1, $attemptCount),
            'window_started_at' => $windowStartedAt,
            'blocked_until' => $blockedUntil,
            'last_error' => $reason !== '' ? substr($reason, 0, 255) : null,
            'updated_at' => $now,
        ];

        if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
            $wpdb->update(
                $table,
                $data,
                ['id' => (int) $row['id']],
                ['%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s'],
                ['%d']
            );
        } else {
            $wpdb->insert(
                $table,
                $data,
                ['%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
            );
        }

        if ($blockedUntil === null) {
            return 0;
        }

        $blockedUntilTs = strtotime($blockedUntil . ' UTC') ?: 0;
        return $blockedUntilTs > $nowTs ? max(1, $blockedUntilTs - $nowTs) : 0;
    }

    private function clear_throttle(string $shopKey, string $endpoint, string $clientIp): void
    {
        global $wpdb;
        $this->ensure_throttle_table();

        $table = $wpdb->prefix . 'ncwoo_security_rate_limit';
        $wpdb->delete(
            $table,
            [
                'shop_key' => substr($shopKey, 0, 80),
                'endpoint' => substr($endpoint, 0, 32),
                'client_ip' => substr($clientIp, 0, 64),
            ],
            ['%s', '%s', '%s']
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function fail(string $message, int $status, ?int $retryAfterSeconds = null): array
    {
        $payload = [
            'success' => false,
            'status' => $status,
            'error' => $message,
        ];

        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            $payload['retry_after_seconds'] = $retryAfterSeconds;
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function fail_and_track(string $shopKey, string $endpoint, string $clientIp, string $message, int $status): array
    {
        $retryAfterSeconds = $this->record_failure($shopKey, $endpoint, $clientIp, $message);
        if ($retryAfterSeconds > 0) {
            return $this->fail('Too many invalid requests', 429, $retryAfterSeconds);
        }

        return $this->fail($message, $status);
    }
}
