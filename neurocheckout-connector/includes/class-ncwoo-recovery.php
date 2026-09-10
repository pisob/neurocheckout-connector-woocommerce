<?php

if (!defined('ABSPATH')) {
    exit;
}

final class NCWooRecoveryService
{
    public const LINK_TTL_SECONDS = 604800;

    private NCWooConfig $config;
    private NCWooDB $db;

    public function __construct(NCWooConfig $config, NCWooDB $db)
    {
        $this->config = $config;
        $this->db = $db;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function build_cart_recovery_url(
        string $cartId,
        string $customerEmail,
        string $couponCode = '',
        ?string $cartFingerprint = null,
        ?string $targetUrl = null,
        array $payload = [],
        ?int $customerId = null
    ): string {
        if ($customerId !== null && $customerId > 0 && !isset($payload['customer_id'])) {
            $payload['customer_id'] = $customerId;
        }

        if (!$this->config->is_opaque_recovery_links_enabled()) {
            return $this->build_legacy_cart_recovery_url(
                $cartId,
                $customerEmail,
                $couponCode,
                $cartFingerprint,
                $targetUrl,
                $payload
            );
        }

        $token = $this->issue_token([
            'mode' => 'cart',
            'cart_id' => $cartId,
            'customer_email' => strtolower(trim($customerEmail)),
            'coupon_code' => $couponCode,
            'cart_fingerprint' => trim((string) $cartFingerprint),
            'customer_id' => $customerId !== null ? max(0, $customerId) : null,
            'target_url' => $targetUrl,
            'payload_json' => $payload,
        ]);

        $base = wc_get_cart_url();
        $separator = strpos($base, '?') === false ? '?' : '&';
        return $base . $separator . 'nc_rt=' . rawurlencode($token);
    }

    public function build_customer_session_recovery_url(int $customerId, string $customerEmail, string $targetUrl): string
    {
        if (!$this->config->is_opaque_recovery_links_enabled()) {
            return $this->build_legacy_customer_session_recovery_url($customerId, $customerEmail, $targetUrl);
        }

        $token = $this->issue_token([
            'mode' => 'customer_session',
            'customer_id' => max(0, $customerId),
            'customer_email' => strtolower(trim($customerEmail)),
            'target_url' => $targetUrl,
        ]);

        $base = home_url('/');
        $separator = strpos($base, '?') === false ? '?' : '&';
        return $base . $separator . 'nc_rt=' . rawurlencode($token);
    }

    public function handle_recovery_redirect(): void
    {
        $token = isset($_GET['nc_rt']) ? trim((string) $_GET['nc_rt']) : '';
        $legacy = isset($_GET['nc_legacy']) ? trim((string) $_GET['nc_legacy']) : '';
        $legacyMode = isset($_GET['mode']) ? trim((string) $_GET['mode']) : '';
        if ($token === '') {
            if ($legacy === '' && $legacyMode === '') {
                return;
            }

            if ($legacyMode === 'customer_session') {
                $legacySession = $this->resolve_legacy_customer_session_payload();
                if (!$legacySession) {
                    wp_safe_redirect(home_url('/'));
                    exit;
                }

                $customerId = (int) ($legacySession['customer_id'] ?? 0);
                if ($customerId > 0) {
                    wp_set_auth_cookie($customerId, true);
                    wp_set_current_user($customerId);
                }

                $target = $this->safe_target_url((string) ($legacySession['target_url'] ?? ''));
                wp_safe_redirect($target ?: wc_get_cart_url());
                exit;
            }

            $legacyCart = $this->resolve_legacy_cart_payload();
            if (!$legacyCart) {
                wp_safe_redirect(home_url('/'));
                exit;
            }

            $legacyCartId = trim((string) ($legacyCart['cart_id'] ?? ''));
            if ($legacyCartId !== '') {
                $this->persist_runtime_cart_id($legacyCartId);
            }

            $productsPayload = $this->decode_payload_json($legacyCart['payload_json'] ?? null);
            $legacyCustomerId = is_array($productsPayload) ? (int) ($productsPayload['customer_id'] ?? 0) : 0;
            if ($legacyCustomerId > 0) {
                wp_set_auth_cookie($legacyCustomerId, true);
                wp_set_current_user($legacyCustomerId);
            }
            if (function_exists('WC') && WC()->cart) {
                $this->empty_cart_without_cleared_event();

                $products = $productsPayload['products'] ?? [];
                if (is_array($products)) {
                    foreach ($products as $line) {
                        if (!is_array($line)) {
                            continue;
                        }
                        $productId = (int) ($line['product_id'] ?? 0);
                        $variationId = (int) ($line['attribute_id'] ?? 0);
                        $qty = max(1, (int) ($line['quantity'] ?? 0));
                        if ($productId <= 0) {
                            continue;
                        }
                        WC()->cart->add_to_cart($productId, $qty, max(0, $variationId));
                    }
                }

                $couponCode = trim((string) ($legacyCart['coupon_code'] ?? ''));
                if ($couponCode !== '') {
                    WC()->cart->apply_coupon($couponCode);
                }
            }

            if ($legacyCartId !== '') {
                $this->persist_runtime_cart_id($legacyCartId);
            }

            $target = $this->safe_target_url((string) ($legacyCart['target_url'] ?? ''));
            if (!$target) {
                $target = wc_get_cart_url();
            }

            wp_safe_redirect($target);
            exit;
        }

        $resolved = $this->resolve_usable_token($token);
        if (!$resolved) {
            wp_safe_redirect(home_url('/'));
            exit;
        }

        $mode = (string) ($resolved['mode'] ?? 'cart');
        $payload = $this->decode_payload_json($resolved['payload_json'] ?? null);

        if ($mode === 'customer_session') {
            $customerId = (int) ($resolved['customer_id'] ?? 0);
            if ($customerId > 0) {
                wp_set_auth_cookie($customerId, true);
                wp_set_current_user($customerId);
            }

            $this->consume_token($token);
            $target = $this->safe_target_url((string) ($resolved['target_url'] ?? ''));
            wp_safe_redirect($target ?: wc_get_cart_url());
            exit;
        }

        if (function_exists('WC') && WC()->cart) {
            $resolvedCartId = trim((string) ($resolved['cart_id'] ?? ''));
            if ($resolvedCartId !== '') {
                $this->persist_runtime_cart_id($resolvedCartId);
            }

            $customerId = (int) ($resolved['customer_id'] ?? 0);
            if ($customerId <= 0 && is_array($payload)) {
                $customerId = (int) ($payload['customer_id'] ?? 0);
            }
            if ($customerId > 0) {
                wp_set_auth_cookie($customerId, true);
                wp_set_current_user($customerId);
            }

            $this->empty_cart_without_cleared_event();

            $products = $payload['products'] ?? [];
            if (is_array($products)) {
                foreach ($products as $line) {
                    if (!is_array($line)) {
                        continue;
                    }
                    $productId = (int) ($line['product_id'] ?? 0);
                    $variationId = (int) ($line['attribute_id'] ?? 0);
                    $qty = max(1, (int) ($line['quantity'] ?? 0));
                    if ($productId <= 0) {
                        continue;
                    }
                    WC()->cart->add_to_cart($productId, $qty, max(0, $variationId));
                }
            }

            $couponCode = trim((string) ($resolved['coupon_code'] ?? ''));
            if ($couponCode !== '') {
                WC()->cart->apply_coupon($couponCode);
            }

            if ($resolvedCartId !== '') {
                $this->persist_runtime_cart_id($resolvedCartId);
            }
        }

        $this->consume_token($token);

        $target = $this->safe_target_url(
            (string) ($resolved['target_url'] ?? '')
        );
        if (!$target) {
            $target = wc_get_cart_url();
        }

        wp_safe_redirect($target);
        exit;
    }

    private function empty_cart_without_cleared_event(): void
    {
        $GLOBALS['ncwoo_suppress_cart_cleared_event'] = true;
        try {
            WC()->cart->empty_cart();
        } finally {
            unset($GLOBALS['ncwoo_suppress_cart_cleared_event']);
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function issue_token(array $data): string
    {
        global $wpdb;

        $table = $wpdb->prefix . 'ncwoo_recovery_token';

        $token = $this->generate_token();
        $hash = hash('sha256', $token);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::LINK_TTL_SECONDS);

        $payload = isset($data['payload_json']) && is_array($data['payload_json']) ? $data['payload_json'] : null;

        $wpdb->insert(
            $table,
            [
                'token_hash' => $hash,
                'mode' => (string) ($data['mode'] ?? 'cart'),
                'cart_id' => (string) ($data['cart_id'] ?? ''),
                'customer_email' => (string) ($data['customer_email'] ?? ''),
                'coupon_code' => (string) ($data['coupon_code'] ?? ''),
                'cart_fingerprint' => (string) ($data['cart_fingerprint'] ?? ''),
                'customer_id' => isset($data['customer_id']) ? (int) $data['customer_id'] : null,
                'target_url' => (string) ($data['target_url'] ?? ''),
                'payload_json' => $payload ? wp_json_encode($payload) : null,
                'expires_at' => $expiresAt,
            ],
            [
                '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s',
            ]
        );

        return $token;
    }

    private function generate_token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolve_usable_token(string $token): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'ncwoo_recovery_token';
        $hash = hash('sha256', $token);

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE token_hash = %s LIMIT 1",
                $hash
            ),
            ARRAY_A
        );

        if (!is_array($row)) {
            return null;
        }

        $usedAt = trim((string) ($row['used_at'] ?? ''));
        if ($usedAt !== '') {
            return null;
        }

        $expiresAtRaw = trim((string) ($row['expires_at'] ?? ''));
        $expiresTs = strtotime($expiresAtRaw);
        if ($expiresTs === false || $expiresTs <= time()) {
            return null;
        }

        return $row;
    }

    private function consume_token(string $token): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'ncwoo_recovery_token';
        $hash = hash('sha256', $token);

        $wpdb->update(
            $table,
            ['used_at' => gmdate('Y-m-d H:i:s')],
            ['token_hash' => $hash],
            ['%s'],
            ['%s']
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function decode_payload_json($raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function safe_target_url(string $targetUrl): ?string
    {
        $targetUrl = trim($targetUrl);
        if ($targetUrl === '') {
            return null;
        }

        $parsedTarget = wp_parse_url($targetUrl);
        if (!is_array($parsedTarget) || empty($parsedTarget['host'])) {
            return null;
        }

        $home = wp_parse_url(home_url('/'));
        if (!is_array($home) || empty($home['host'])) {
            return null;
        }

        if (strtolower((string) $parsedTarget['host']) !== strtolower((string) $home['host'])) {
            return null;
        }

        return $targetUrl;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function build_legacy_cart_recovery_url(
        string $cartId,
        string $customerEmail,
        string $couponCode = '',
        ?string $cartFingerprint = null,
        ?string $targetUrl = null,
        array $payload = []
    ): string {
        $normalizedCartId = trim($cartId);
        $normalizedEmail = strtolower(trim($customerEmail));
        $normalizedCoupon = trim($couponCode);
        $normalizedFingerprint = trim((string) $cartFingerprint);
        $safeTargetUrl = $this->safe_target_url((string) $targetUrl) ?: '';
        $timestamp = time();

        $payloadEncoded = '';
        if (!empty($payload)) {
            $payloadJson = wp_json_encode($payload);
            if (is_string($payloadJson) && $payloadJson !== '' && $payloadJson !== 'null') {
                $payloadEncoded = $this->base64url_encode($payloadJson);
            }
        }

        $signature = $this->sign_legacy_cart_payload(
            $normalizedCartId,
            $normalizedEmail,
            $normalizedCoupon,
            $timestamp,
            $normalizedFingerprint,
            $payloadEncoded,
            $safeTargetUrl
        );

        $params = [
            'nc_legacy' => '1',
            'mode' => 'cart',
            'cart_id' => $normalizedCartId,
            'email' => $normalizedEmail,
            'ts' => (string) $timestamp,
            'sig' => $signature,
        ];

        if ($normalizedCoupon !== '') {
            $params['coupon'] = $normalizedCoupon;
        }
        if ($normalizedFingerprint !== '') {
            $params['fp'] = $normalizedFingerprint;
        }
        if ($payloadEncoded !== '') {
            $params['pl'] = $payloadEncoded;
        }
        if ($safeTargetUrl !== '') {
            $params['u'] = $safeTargetUrl;
        }

        $base = wc_get_cart_url();
        $separator = strpos($base, '?') === false ? '?' : '&';

        return $base . $separator . http_build_query($params);
    }

    private function build_legacy_customer_session_recovery_url(int $customerId, string $customerEmail, string $targetUrl): string
    {
        $normalizedCustomerId = max(0, $customerId);
        $normalizedEmail = strtolower(trim($customerEmail));
        $safeTargetUrl = $this->safe_target_url($targetUrl) ?: '';
        $timestamp = time();

        $signature = $this->sign_legacy_customer_session_payload(
            $normalizedCustomerId,
            $normalizedEmail,
            $timestamp,
            $safeTargetUrl
        );

        $params = [
            'nc_legacy' => '1',
            'mode' => 'customer_session',
            'customer_id' => (string) $normalizedCustomerId,
            'email' => $normalizedEmail,
            'ts' => (string) $timestamp,
            'sig' => $signature,
        ];
        if ($safeTargetUrl !== '') {
            $params['u'] = $safeTargetUrl;
        }

        $base = home_url('/');
        $separator = strpos($base, '?') === false ? '?' : '&';

        return $base . $separator . http_build_query($params);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolve_legacy_cart_payload(): ?array
    {
        $cartId = trim((string) ($_GET['cart_id'] ?? ''));
        $email = strtolower(trim((string) ($_GET['email'] ?? '')));
        $couponCode = trim((string) ($_GET['coupon'] ?? ''));
        $cartFingerprint = trim((string) ($_GET['fp'] ?? ''));
        $payloadEncoded = trim((string) ($_GET['pl'] ?? ''));
        $targetUrl = $this->safe_target_url((string) ($_GET['u'] ?? '')) ?: '';
        $timestamp = (int) ($_GET['ts'] ?? 0);
        $signature = trim((string) ($_GET['sig'] ?? ''));

        if ($cartId === '' || $email === '' || $timestamp <= 0 || $signature === '') {
            return null;
        }
        if (abs(time() - $timestamp) > self::LINK_TTL_SECONDS) {
            return null;
        }

        if (
            !$this->is_valid_legacy_cart_signature(
                $cartId,
                $email,
                $couponCode,
                $timestamp,
                $cartFingerprint,
                $payloadEncoded,
                $targetUrl,
                $signature
            )
        ) {
            return null;
        }

        $payloadJson = '';
        if ($payloadEncoded !== '') {
            $decodedPayload = $this->base64url_decode($payloadEncoded);
            if ($decodedPayload !== '' && is_array(json_decode($decodedPayload, true))) {
                $payloadJson = $decodedPayload;
            }
        }

        return [
            'mode' => 'cart',
            'cart_id' => $cartId,
            'customer_email' => $email,
            'coupon_code' => $couponCode,
            'cart_fingerprint' => $cartFingerprint,
            'target_url' => $targetUrl,
            'payload_json' => $payloadJson,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolve_legacy_customer_session_payload(): ?array
    {
        $customerId = (int) ($_GET['customer_id'] ?? 0);
        $email = strtolower(trim((string) ($_GET['email'] ?? '')));
        $targetUrl = $this->safe_target_url((string) ($_GET['u'] ?? '')) ?: '';
        $timestamp = (int) ($_GET['ts'] ?? 0);
        $signature = trim((string) ($_GET['sig'] ?? ''));

        if ($customerId <= 0 || $email === '' || $timestamp <= 0 || $signature === '') {
            return null;
        }
        if (abs(time() - $timestamp) > self::LINK_TTL_SECONDS) {
            return null;
        }

        if (
            !$this->is_valid_legacy_customer_session_signature(
                $customerId,
                $email,
                $timestamp,
                $targetUrl,
                $signature
            )
        ) {
            return null;
        }

        $user = get_user_by('id', $customerId);
        if (!$user instanceof WP_User) {
            return null;
        }
        if (!hash_equals(strtolower((string) $user->user_email), $email)) {
            return null;
        }

        return [
            'mode' => 'customer_session',
            'customer_id' => $customerId,
            'customer_email' => $email,
            'target_url' => $targetUrl,
        ];
    }

    private function sign_legacy_cart_payload(
        string $cartId,
        string $email,
        string $couponCode,
        int $timestamp,
        string $cartFingerprint,
        string $payloadEncoded,
        string $targetUrl
    ): string {
        $secret = $this->config->get_internal_secret();
        if ($secret === '') {
            return '';
        }

        $payloadHash = hash('sha256', $payloadEncoded);
        $data = implode(
            '|',
            [
                'cart',
                trim($cartId),
                strtolower(trim($email)),
                trim($couponCode),
                (string) $timestamp,
                trim($cartFingerprint),
                $payloadHash,
                trim($targetUrl),
            ]
        );

        return hash_hmac('sha256', $data, $secret);
    }

    private function sign_legacy_customer_session_payload(
        int $customerId,
        string $email,
        int $timestamp,
        string $targetUrl
    ): string {
        $secret = $this->config->get_internal_secret();
        if ($secret === '') {
            return '';
        }

        $data = implode(
            '|',
            [
                'customer_session',
                (string) max(0, $customerId),
                strtolower(trim($email)),
                (string) $timestamp,
                trim($targetUrl),
            ]
        );

        return hash_hmac('sha256', $data, $secret);
    }

    private function is_valid_legacy_cart_signature(
        string $cartId,
        string $email,
        string $couponCode,
        int $timestamp,
        string $cartFingerprint,
        string $payloadEncoded,
        string $targetUrl,
        string $signature
    ): bool {
        $provided = trim($signature);
        if ($provided === '') {
            return false;
        }

        $secret = $this->config->get_internal_secret();
        if ($secret === '') {
            return false;
        }

        $signed = $this->sign_legacy_cart_payload(
            $cartId,
            $email,
            $couponCode,
            $timestamp,
            $cartFingerprint,
            $payloadEncoded,
            $targetUrl
        );
        if ($signed !== '' && hash_equals($signed, $provided)) {
            return true;
        }

        // Compatibility with Presta legacy signatures already in circulation.
        $legacyWithFingerprint = implode(
            ':',
            [
                trim($cartId),
                strtolower(trim($email)),
                trim($couponCode),
                (string) $timestamp,
                trim($cartFingerprint),
            ]
        );
        if (hash_equals(hash_hmac('sha256', $legacyWithFingerprint, $secret), $provided)) {
            return true;
        }

        $legacyWithoutFingerprint = implode(
            ':',
            [
                trim($cartId),
                strtolower(trim($email)),
                trim($couponCode),
                (string) $timestamp,
            ]
        );

        return hash_equals(hash_hmac('sha256', $legacyWithoutFingerprint, $secret), $provided);
    }

    private function is_valid_legacy_customer_session_signature(
        int $customerId,
        string $email,
        int $timestamp,
        string $targetUrl,
        string $signature
    ): bool {
        $provided = trim($signature);
        if ($provided === '') {
            return false;
        }

        $secret = $this->config->get_internal_secret();
        if ($secret === '') {
            return false;
        }

        $signed = $this->sign_legacy_customer_session_payload(
            $customerId,
            $email,
            $timestamp,
            $targetUrl
        );
        if ($signed !== '' && hash_equals($signed, $provided)) {
            return true;
        }

        $legacy = implode(
            ':',
            [
                (string) max(0, $customerId),
                strtolower(trim($email)),
                (string) $timestamp,
            ]
        );

        return hash_equals(hash_hmac('sha256', $legacy, $secret), $provided);
    }

    private function persist_runtime_cart_id(string $cartId): void
    {
        $cartId = trim($cartId);
        if ($cartId === '') {
            return;
        }

        $this->persist_runtime_cart_cookie($cartId);

        if (!function_exists('WC') || !WC()->session || !method_exists(WC()->session, 'set')) {
            return;
        }

        try {
            WC()->session->set('ncwoo_runtime_cart_id', $cartId);
        } catch (Throwable $e) {
            // Best effort only: session restore should never block customer navigation.
        }
    }

    private function persist_runtime_cart_cookie(string $cartId): void
    {
        $cartId = trim($cartId);
        if ($cartId === '') {
            return;
        }

        if (headers_sent()) {
            return;
        }

        $secure = function_exists('is_ssl') ? (bool) is_ssl() : false;
        setcookie(
            'ncwoo_recovery_cart_id',
            $cartId,
            [
                'expires' => time() + 86400,
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
        $_COOKIE['ncwoo_recovery_cart_id'] = $cartId;
    }

    private function base64url_encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function base64url_decode(string $encoded): string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        return is_string($decoded) ? $decoded : '';
    }
}
