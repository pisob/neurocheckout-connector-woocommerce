<?php
declare(strict_types=1);
namespace NeuroCheckout\WooCommerce\Community;

/** Additive discount evidence; legacy validators retain every other constraint. */
final class NativeHistoryV2
{
    public static function legacy($history, string $schema)
    {
        if (!is_array($history) || ($history['schema'] ?? null)!==str_replace('-v1','-v2',$schema)) { return $history; }
        if (!isset($history['orders']) || !is_array($history['orders']) || count($history['orders'])>128
            || strlen(json_encode($history,JSON_THROW_ON_ERROR))>64000) { self::fail(); }
        $history['schema']=$schema;
        foreach ($history['orders'] as &$order) {
            if (!is_array($order) || !array_key_exists('discount_total',$order)) { self::fail(); }
            $value=$order['discount_total'];
            if ($value!==null && (!is_string($value) || !preg_match('/^[0-9]{1,26}(?:\\.[0-9]{1,8})?$/D',$value))) { self::fail(); }
            unset($order['discount_total']);
        }
        unset($order);
        return $history;
    }
    private static function fail(): void { throw new \RuntimeException('customer_history_unavailable'); }
}

