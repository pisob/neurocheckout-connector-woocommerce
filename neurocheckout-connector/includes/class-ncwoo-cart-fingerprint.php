<?php

if (!defined('ABSPATH')) {
    exit;
}

final class NCWooCartFingerprint
{
    /**
     * @param array<int,array<string,mixed>> $products
     */
    public static function from_products(array $products): string
    {
        $lines = [];

        foreach ($products as $product) {
            $productId = (int) ($product['product_id'] ?? 0);
            $attributeId = (int) ($product['attribute_id'] ?? 0);
            $reference = strtolower(trim((string) ($product['reference'] ?? '')));
            $quantity = (int) ($product['quantity'] ?? 0);
            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            $lines[] = implode('|', [
                'pid:' . $productId,
                'attr:' . max(0, $attributeId),
                'ref:' . $reference,
                'qty:' . $quantity,
            ]);
        }

        sort($lines);

        return hash('sha256', implode("\n", $lines));
    }

    public static function from_current_wc_cart(): string
    {
        if (!function_exists('WC') || !WC()->cart) {
            return hash('sha256', '');
        }

        $products = [];
        foreach (WC()->cart->get_cart() as $line) {
            $products[] = [
                'product_id' => (int) ($line['product_id'] ?? 0),
                'attribute_id' => (int) ($line['variation_id'] ?? 0),
                'reference' => '',
                'quantity' => (int) ($line['quantity'] ?? 0),
            ];
        }

        return self::from_products($products);
    }
}
