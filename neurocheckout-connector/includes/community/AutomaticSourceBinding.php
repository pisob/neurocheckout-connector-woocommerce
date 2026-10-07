<?php

declare(strict_types=1);

namespace NeuroCheckout\WooCommerce\Community;

use RuntimeException;

/** Purpose-limited binding derived from the connector key; no second secret to configure. */
final class AutomaticSourceBinding
{
    private const DOMAIN = "neurocheckout-community-source-v1\0";

    public static function environment(string $endpoint): ?string
    {
        $endpoint = rtrim(trim($endpoint), '/');
        if ($endpoint === 'https://www.neurocheckout.com') { return 'production'; }
        if ($endpoint === 'https://community-api-staging.neurocheckout.com') { return 'staging'; }
        return null;
    }

    public static function secret(string $apiKey, string $shopId): string
    {
        $apiKey = trim($apiKey);
        $shopId = trim($shopId);
        if ($apiKey === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $shopId)) {
            throw new RuntimeException('community_source_binding_invalid');
        }

        return hash_hmac('sha256', self::DOMAIN . $shopId, $apiKey);
    }
}
