<?php

declare(strict_types=1);

namespace NeuroCheckout\WooCommerce\Community;

use RuntimeException;

/** Dedicated source-pull authentication, separate from the legacy Cloud API. */
final class SourcePullProtocol
{
    public const MAX_REQUEST_BYTES = 2048;
    public const MAX_RESPONSE_BYTES = 1048576;
    public const MAX_RECORDS = 8;

    /**
     * The adapter MUST supply the trusted shop context, a dedicated secret and
     * an atomic, persistent nonce consumer. No legacy API-key authentication.
     * Header names must be lower case, with duplicates rejected by the adapter.
     */
    public static function authenticate(
        string $method,
        string $path,
        array $headers,
        string $raw,
        string $shopId,
        string $secret,
        int $nowMs,
        callable $consumeNonce,
        bool $enabled,
        string $environment
    ): array {
        if (!$enabled || $environment !== 'staging') {
            throw new RuntimeException('source_disabled');
        }
        self::secret($secret);
        if ($method !== 'POST' || self::platformForPath($path) === null
            || isset($headers['origin']) || isset($headers['cookie']) || isset($headers['content-encoding'])
            || explode(';', (string) ($headers['content-type'] ?? ''))[0] !== 'application/json'
            || strlen($raw) > self::MAX_REQUEST_BYTES
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $shopId)) {
            throw new RuntimeException('source_invalid');
        }
        $time = $headers['x-nc-source-time'] ?? '';
        $nonce = $headers['x-nc-source-nonce'] ?? '';
        $signature = $headers['x-nc-source-signature'] ?? '';
        if (!is_string($time) || !preg_match('/^[0-9]{13}$/D', $time) || abs($nowMs - (int) $time) > 120000
            || !is_string($nonce) || !preg_match('/^[a-f0-9]{32}$/D', $nonce)
            || !is_string($signature) || !preg_match('/^[a-f0-9]{64}$/D', $signature)) {
            throw new RuntimeException('source_unauthorized');
        }
        $canonical = implode("\n", ['nc-source-pull-v1', 'POST', $path, $shopId, $time, $nonce, hash('sha256', $raw)]);
        if (!hash_equals(hash_hmac('sha256', $canonical, hex2bin($secret)), $signature)) {
            throw new RuntimeException('source_unauthorized');
        }
        $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($input) || !self::keys($input, ['schema', 'shopId', 'streamId', 'cursor', 'limit'])
            || $input['schema'] !== 1 || $input['shopId'] !== $shopId || $input['limit'] !== self::MAX_RECORDS
            || !is_string($input['cursor']) || !preg_match('/^(?:[a-f0-9]{64})?$/D', $input['cursor'])
            || ($input['streamId'] !== null && (!is_string($input['streamId']) || !preg_match('/^[a-f0-9]{32}$/D', $input['streamId'])))
            || (($input['cursor'] === '') !== ($input['streamId'] === null))) {
            throw new RuntimeException('source_invalid');
        }
        // Keep the nonce for at least 240 seconds: the accepted timestamp can be
        // 120 seconds in the future, then remain valid for another 120 seconds.
        if (!$consumeNonce('community-source-' . $nonce, 300)) {
            throw new RuntimeException('source_replay');
        }
        return $input;
    }

    public static function responseSignature(string $secret, string $nonce, string $raw): string
    {
        self::secret($secret);
        if (!preg_match('/^[a-f0-9]{32}$/D', $nonce) || strlen($raw) > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('source_invalid');
        }
        return hash_hmac('sha256', implode("\n", ['nc-source-response-v1', $nonce, hash('sha256', $raw)]), hex2bin($secret));
    }

    public static function platformForPath(string $path): ?string
    {
        foreach ([
            'prestashop' => 'module/neurocheckoutconnector/communitydata',
            'magento' => 'neurocheckout/community/pull',
            'woocommerce' => 'wp-json/neurocheckout/v1/communitydata',
        ] as $platform => $suffix) {
            if (preg_match('#^/(?:[A-Za-z0-9_-]+/)*' . $suffix . '$#D', $path)) {
                return $platform;
            }
        }
        return null;
    }

    private static function secret(string $secret): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $secret)) {
            throw new RuntimeException('source_secret_invalid');
        }
    }

    private static function keys(array $input, array $expected): bool
    {
        $keys = array_keys($input);
        sort($keys);
        sort($expected);
        return $keys === $expected;
    }
}
