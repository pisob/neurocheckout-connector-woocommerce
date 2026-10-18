<?php
declare(strict_types=1);
namespace NeuroCheckout\WooCommerce\Community;
require_once __DIR__ . '/NativeHistoryV2.php';

/** Signed native facts only: never interpret status or a date as paid revenue. */
final class WooCustomerHistoryProtocol
{
    public static function request(array $v, string $shop): void
    {
        if (!self::keys($v,['schema','shopId','operation','blogId','customerId','customerEmail'])
            || $v['schema']!==1 || $v['shopId']!==$shop || $v['operation']!=='customer_history') { self::fail(); }
        self::id($v['blogId']); self::id($v['customerId']);
        if (!is_string($v['customerEmail']) || strlen($v['customerEmail'])>254 || !filter_var($v['customerEmail'],FILTER_VALIDATE_EMAIL)) { self::fail(); }
    }
    public static function response($page, array $input, int $scope, string $raw): void
    {
        if (!self::keys($page,['schema','shopId','operation','requestHash','history']) || $page['schema']!==1
            || $page['shopId']!==$input['shopId'] || $page['operation']!=='customer_history'
            || $page['requestHash']!==hash('sha256',$raw) || (string)$scope!==$input['blogId']) { self::fail(); }
        self::history($page['history'],$input);
        if (strlen(json_encode($page,JSON_THROW_ON_ERROR))>65536) { self::fail(); }
    }
    public static function history($h, array $input): void
    {
        $h=NativeHistoryV2::legacy($h,'woocommerce-customer-history-v1');
        if (!self::keys($h,['schema','blog_id','customer_id','customer_email','customer_created_at','read_at','complete',
                'order_storage','order_count','orders','marketing_consent']) || $h['schema']!=='woocommerce-customer-history-v1'
            || $h['complete']!==true || !is_int($h['blog_id']) || (string)$h['blog_id']!==$input['blogId']
            || !is_int($h['customer_id']) || (string)$h['customer_id']!==$input['customerId']
            || !is_string($h['customer_email']) || strcasecmp($h['customer_email'],$input['customerEmail'])!==0
            || !in_array($h['order_storage'],['posts','hpos'],true)
            || !in_array($h['marketing_consent'],['granted','denied','unknown'],true)
            || !is_string($h['read_at']) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$h['read_at'])
            || abs(time()-(strtotime($h['read_at'])?:0))>15 || !self::list($h['orders']) || count($h['orders'])>128
            || $h['order_count']!==count($h['orders'])) { self::fail(); }
        self::date($h['customer_created_at']); $ids=[]; $lineIds=[]; $refundCount=0;
        foreach ($h['orders'] as $o) {
            if (!self::keys($o,['order_id','status','created_at','updated_at','currency','total','date_paid_gmt','items','refunds'])) { self::fail(); }
            self::unique($o['order_id'],$ids);
            if (!is_string($o['status']) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$o['status'])
                || !is_string($o['currency']) || !preg_match('/^[A-Z]{3}$/D',$o['currency'])
                || !self::list($o['refunds'])) { self::fail(); }
            self::date($o['created_at']); self::date($o['updated_at']);
            if ($o['date_paid_gmt']!==null) { self::date($o['date_paid_gmt']); }
            self::decimal($o['total'],false); self::items($o['items'],$lineIds,false,[]);
            $originals=[]; foreach ($o['items'] as $item) { $originals[$item['item_id']]=$item; }
            foreach ($o['refunds'] as $r) {
                if (++$refundCount>128 || !self::keys($r,['refund_id','created_at','amount','payment_refunded','items'])) { self::fail(); }
                self::unique($r['refund_id'],$ids); self::date($r['created_at']); self::decimal($r['amount'],false);
                if ($r['payment_refunded']!==null && !is_bool($r['payment_refunded'])) { self::fail(); }
                self::items($r['items'],$lineIds,true,$originals);
            }
        }
        if (strlen(json_encode($h,JSON_THROW_ON_ERROR))>64000) { self::fail(); }
    }
    private static function items($items, array &$seen, bool $refund, array $originals): void
    {
        if (!self::list($items)) { self::fail(); }
        foreach ($items as $item) {
            if (!self::keys($item,['item_id','product_id','variation_id','quantity','line_total','line_tax','refunded_item_id'])) { self::fail(); }
            self::unique($item['item_id'],$seen); if (count($seen)>512) { self::fail(); }
            self::id($item['product_id'],true); self::id($item['variation_id'],true);
            foreach (['quantity','line_total','line_tax'] as $key) { self::decimal($item[$key],$refund); }
            $parent=$item['refunded_item_id'];
            if (!$refund && $parent!==null) { self::fail(); }
            if ($refund && $parent!==null) {
                self::id($parent);
                if (!isset($originals[$parent]) || $originals[$parent]['product_id']!==$item['product_id']
                    || $originals[$parent]['variation_id']!==$item['variation_id']) { self::fail(); }
            }
        }
    }
    private static function date($v): void
    {
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$v)
            || !($date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$v,new \DateTimeZone('UTC')))
            || $date->format('Y-m-d H:i:s')!==$v || $date->getTimestamp()>time()+15) { self::fail(); }
    }
    private static function decimal($v, bool $negative): void
    {
        if (!is_string($v) || !preg_match('/^'.($negative?'-?':'').'\d{1,26}(?:\.\d{1,8})?$/D',$v)) { self::fail(); }
        // Refund lines are negative native facts, not positive purchases.
        if ($negative && $v[0]!=='-' && preg_match('/[1-9]/',$v)) { self::fail(); }
    }
    private static function id($v, bool $zero=false): void
    { if (!is_string($v) || !preg_match($zero?'/^(?:0|[1-9][0-9]{0,9})$/D':'/^[1-9][0-9]{0,9}$/D',$v)) { self::fail(); } }
    private static function unique($v,array &$seen): void { self::id($v);if(isset($seen[$v]))self::fail();$seen[$v]=true; }
    private static function list($v): bool { return is_array($v)&&($v===[]||array_keys($v)===range(0,count($v)-1)); }
    private static function keys($v,array $expected): bool { if(!is_array($v))return false;$keys=array_keys($v);sort($keys);sort($expected);return $keys===$expected; }
    private static function fail(): void { throw new \RuntimeException('customer_history_unavailable'); }
}
