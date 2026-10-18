<?php
declare(strict_types=1);
namespace NeuroCheckout\WooCommerce\Community;

require_once __DIR__ . '/WooCustomerHistoryProtocol.php';

/** Minimal transient native account-cart evidence, never send authorization. */
final class CustomerCartsProtocol
{
    public static function request(array $input, string $shop): void
    {
        if (($input['operation']??null)!=='customer_carts') { self::fail(); }
        WooCustomerHistoryProtocol::request(array_replace($input,['operation'=>'customer_history']),$shop);
    }
    public static function response($page,array $input,int $scope,string $raw): void
    {
        if (!self::keys($page,['schema','shopId','operation','requestHash','carts']) || $page['schema']!==1
            || $page['shopId']!==$input['shopId'] || $page['operation']!=='customer_carts'
            || $page['requestHash']!==hash('sha256',$raw) || $input['blogId']!==(string)$scope
            || strlen(json_encode($page,JSON_THROW_ON_ERROR))>4096) { self::fail(); }
        self::facts($page['carts'],array_diff_key($input,['schema'=>1,'shopId'=>1,'operation'=>1]));
    }
    public static function facts($value,array $identity): void
    {
        self::request(['schema'=>1,'shopId'=>'bound','operation'=>'customer_carts']+$identity,'bound');
        if (!self::keys($value,['schema','identity','read_at','complete','has_active_cart','marketing_consent'])
            || $value['schema']!=='woocommerce-customer-carts-v1' || $value['complete']!==true
            || !is_bool($value['has_active_cart']) || !self::keys($value['identity'],array_keys($identity))
            || !in_array($value['marketing_consent'],['granted','denied','unknown'],true)
            || !is_string($value['read_at'])
            || !($date=\DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z',$value['read_at'],new \DateTimeZone('UTC')))
            || $date->format('Y-m-d\\TH:i:s\\Z')!==$value['read_at'] || abs(time()-$date->getTimestamp())>15) { self::fail(); }
        foreach ($identity as $key=>$expected) {
            if (!is_string($value['identity'][$key]) || ($key==='customerEmail'
                ? strcasecmp($value['identity'][$key],$expected)!==0 : $value['identity'][$key]!==$expected)) { self::fail(); }
        }
    }
    public static function result(array $identity,bool $active,string $consent): array
    {
        $value=['schema'=>'woocommerce-customer-carts-v1','identity'=>$identity,'read_at'=>gmdate('Y-m-d\\TH:i:s\\Z'),
            'complete'=>true,'has_active_cart'=>$active,'marketing_consent'=>$consent];
        self::facts($value,$identity); return $value;
    }
    private static function keys($value,array $expected): bool
    { if(!is_array($value))return false;$keys=array_keys($value);sort($keys);sort($expected);return $keys===$expected; }
    private static function fail(): void { throw new \RuntimeException('customer_carts_unavailable'); }
}

