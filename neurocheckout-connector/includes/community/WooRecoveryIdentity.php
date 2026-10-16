<?php
declare(strict_types=1);

namespace NeuroCheckout\WooCommerce\Community;

use RuntimeException;

/** Server-only evidence: never use the source-pull/API secret known to clients. */
final class WooRecoveryIdentity
{
    public const SESSION_KEY = 'ncwoo_recovered_cart_proof';
    public const ORDER_KEY = '_ncwoo_recovered_cart_proof';
    private string $key;

    public function __construct(string $internalSecret, int $blogId, string $shopId)
    {
        if (strlen($internalSecret) < 32 || $blogId < 1
            || !preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $shopId)) {
            throw new RuntimeException('source_session_invalid');
        }
        $this->key = hash_hkdf('sha256', $internalSecret, 32, 'nc-woo-restored-reference-v1',
            json_encode([$blogId, $shopId], JSON_THROW_ON_ERROR));
    }

    public static function fromConfig(\NCWooConfig $config): self
    {
        return new self($config->get_internal_secret(), (int) get_current_blog_id(), $config->get_shop_external_id());
    }

    /** Called only after a native token claim or a validated legacy signature. */
    public function issue(string $reference): string
    {
        if (!preg_match('/^wc-cart-[a-f0-9]{64}$/D', $reference)) {
            throw new RuntimeException('source_session_invalid');
        }
        return 'v1:' . hash_hmac('sha256', $reference, $this->key);
    }

    public function verifies(string $reference, string $proof): bool
    {
        return preg_match('/^wc-cart-[a-f0-9]{64}$/D', $reference) === 1
            && preg_match('/^v1:[a-f0-9]{64}$/D', $proof) === 1
            && hash_equals($this->issue($reference), $proof);
    }
}
