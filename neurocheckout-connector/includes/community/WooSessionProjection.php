<?php
declare(strict_types=1);

namespace NeuroCheckout\WooCommerce\Community;

use RuntimeException;

/**
 * Pure projection of one persisted native session. No DB, hooks or networking.
 * NOT an abandoned-cart decision or proof of conversion. A native consistent
 * snapshot must still reconcile orders, expired/removed sessions and scopes.
 */
final class WooSessionProjection
{
    private string $referenceKey;
    private const TOTALS = ['subtotal', 'subtotal_tax', 'shipping_total', 'shipping_tax',
        'discount_total', 'discount_tax', 'cart_contents_total', 'cart_contents_tax',
        'fee_total', 'fee_tax', 'total', 'total_tax'];
    private const CONTACT = ['email', 'first_name', 'last_name', 'billing_email',
        'billing_first_name', 'billing_last_name'];

    public function __construct(string $secret, int $blogId, string $shopId)
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $secret) || $blogId < 1
            || !preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $shopId)) { self::fail(); }
        $this->referenceKey = hash_hkdf('sha256', hex2bin($secret), 32,
            'nc-woo-cart-reference-v1', json_encode([$blogId, $shopId], JSON_THROW_ON_ERROR));
    }

    /**
     * Use the same function for native _ncwoo_cart_id order metadata. Neither
     * that value nor session_key must cross the source boundary in clear text.
     */
    public function cartReference(string $nativeId): string
    {
        if ($nativeId === '' || strlen($nativeId) > 256 || preg_match('/[^\x21-\x7e]/', $nativeId)) { self::fail(); }
        return 'wc-cart-' . hash_hmac('sha256', $nativeId, $this->referenceKey);
    }

    /** null means expired, NOT converted. Caller must reconcile its tombstone. */
    public function project(string $sessionKey, string $serialized, int $expiresAt, int $now): ?array
    {
        if ($now < 1 || $expiresAt < 1 || $expiresAt > 253402300799) { self::fail(); }
        if ($expiresAt <= $now) { return null; }
        $session = BoundedPhpValueReader::decode($serialized);
        if (!is_array($session)) { self::fail(); }
        // WC_Session stores each array as a serialized string inside the outer
        // serialized session. Decode exactly this documented layer, not
        // arbitrary recursively serialized strings from plugins or customers.
        $cart = $this->arrayField($session, 'cart');
        $totals = $this->arrayField($session, 'cart_totals');
        $customer = $this->arrayField($session, 'customer');
        if (count($cart) > 128) { self::fail(); }
        $runtimeId = $session['ncwoo_runtime_cart_id'] ?? '';
        if (!is_string($runtimeId)) { self::fail(); }
        $reference = $this->cartReference($runtimeId !== '' ? $runtimeId : $sessionKey);
        $items = [];
        foreach ($cart as $line) {
            if (!is_array($line)) { self::fail(); }
            $item = [
                'product_id' => $this->id($line['product_id'] ?? null, false),
                'variation_id' => $this->id($line['variation_id'] ?? 0, true),
                'quantity' => $this->decimal($line['quantity'] ?? null, true),
            ];
            foreach (['line_subtotal', 'line_subtotal_tax', 'line_total', 'line_tax'] as $field) {
                if (array_key_exists($field, $line)) { $item[$field] = $this->decimal($line[$field], false); }
            }
            $variation = $line['variation'] ?? [];
            if (!is_array($variation) || count($variation) > 32) { self::fail(); }
            $attributes = [];
            foreach ($variation as $name => $value) {
                if (!is_string($name) || !preg_match('/^attribute_[A-Za-z0-9_%.-]{1,120}$/D', $name)) { self::fail(); }
                $attributes[$name] = $this->text($value, 512);
            }
            ksort($attributes);
            $item['variation'] = (object) $attributes;
            $items[] = $item;
        }
        $safeTotals = [];
        foreach (self::TOTALS as $field) {
            if (array_key_exists($field, $totals)) { $safeTotals[$field] = $this->decimal($totals[$field], false); }
        }
        $safeContact = [];
        foreach (self::CONTACT as $field) {
            if (array_key_exists($field, $customer)) { $safeContact[$field] = $this->text($customer[$field], 320); }
        }
        $payload = [
            'source_schema' => 'woocommerce-session-v1',
            'status' => $items ? 'active' : 'empty',
            'conversion_status' => 'not_checked',
            'currency_status' => 'not_exported',
            'session_expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expiresAt),
            'items' => $items,
            'stored_totals' => (object) $safeTotals,
            'customer' => (object) $safeContact,
        ];
        if (strlen(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 16384) {
            throw new RuntimeException('source_snapshot_capacity');
        }
        return ['kind' => 'cart', 'sourceId' => $reference, 'payload' => $payload];
    }

    private function arrayField(array $session, string $key): array
    {
        $value = $session[$key] ?? [];
        if (is_string($value)) { $value = BoundedPhpValueReader::decode($value); }
        if (!is_array($value)) { self::fail(); }
        return $value;
    }

    private function id($value, bool $zero): string
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^(?:0|[1-9][0-9]{0,18})$/D', (string) $value)
            || (!$zero && (string) $value === '0')) { self::fail(); }
        return (string) $value;
    }

    private function decimal($value, bool $positive): string
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) { self::fail(); }
        $text = (string) $value;
        if (strlen($text) > 40 || !preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $text)
            || !is_finite((float) $text) || ($positive && (float) $text <= 0)) { self::fail(); }
        return $text; // Preserve stored decimal precision; never recalculate.
    }

    private function text($value, int $maximum): string
    {
        if (!is_string($value) || strlen($value) > $maximum || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $value)) { self::fail(); }
        return $value;
    }

    private static function fail(): void { throw new RuntimeException('source_session_invalid'); }
}
