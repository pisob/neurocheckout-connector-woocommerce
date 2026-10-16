<?php
declare(strict_types=1);
namespace NeuroCheckout\WooCommerce\Community;

/** Exact bounded request/response contract, distinct from global catalog prices. */
final class WooCustomerPriceProtocol
{
    public static function request(array $v, string $shop): void
    {
        if (!self::keys($v,['schema','shopId','operation','blogId','customerId','customerEmail','productIds','currency'])
            || $v['schema']!==1 || $v['shopId']!==$shop || $v['operation']!=='customer_prices'
            || !self::id($v['blogId']) || !self::id($v['customerId']) || !self::email($v['customerEmail'])
            || !is_string($v['currency']) || !preg_match('/^[A-Z]{3}$/D',$v['currency'])
            || !is_array($v['productIds']) || count($v['productIds'])<1 || count($v['productIds'])>16
            || array_keys($v['productIds'])!==range(0,count($v['productIds'])-1)) { self::fail(); }
        $seen=[];foreach($v['productIds'] as $id){if(!self::id($id)||isset($seen[$id]))self::fail();$seen[$id]=true;}
    }
    public static function response($page, array $input, int $scope, string $raw): void
    {
        if (!self::keys($page,['schema','shopId','operation','requestHash','prices']) || $page['schema']!==1
            || $page['shopId']!==$input['shopId'] || $page['operation']!=='customer_prices'
            || $page['requestHash']!==hash('sha256',$raw) || (string)$scope!==$input['blogId']) { self::fail(); }
        self::prices($page['prices'],$input);
        if(strlen(json_encode($page,JSON_THROW_ON_ERROR))>16384)self::fail();
    }
    public static function prices($p, array $input): void
    {
        if (!self::keys($p,['schema','blog_id','customer_id','customer_email','currency','quantity','price_decimals','context_hash','read_at','offers'])
            || $p['schema']!=='woocommerce-customer-prices-v1' || !is_int($p['blog_id']) || (string)$p['blog_id']!==$input['blogId']
            || !is_int($p['customer_id']) || (string)$p['customer_id']!==$input['customerId']
            || !self::email($p['customer_email']) || strcasecmp($p['customer_email'],$input['customerEmail'])!==0
            || $p['currency']!==$input['currency'] || $p['quantity']!==1 || !is_int($p['price_decimals'])
            || $p['price_decimals']<0 || $p['price_decimals']>6 || !is_string($p['context_hash'])
            || !preg_match('/^[a-f0-9]{64}$/D',$p['context_hash']) || !is_string($p['read_at'])
            || !($date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$p['read_at'],new \DateTimeZone('UTC')))
            || $date->format('Y-m-d\TH:i:s\Z')!==$p['read_at'] || abs(time()-$date->getTimestamp())>15
            || !is_array($p['offers']) || array_keys($p['offers'])!==array_keys($input['productIds'])) { self::fail(); }
        foreach ($p['offers'] as $i=>$offer) {
            if(!is_array($offer)||($offer['product_id']??null)!==$input['productIds'][$i])self::fail();
            if(($offer['status']??null)==='unavailable'){
                if(!self::keys($offer,['product_id','status']))self::fail();continue;
            }
            if(!self::keys($offer,['product_id','status','currency','price_excl_tax','price_incl_tax','display_mode','display_price'])
                ||$offer['status']!=='available'||$offer['currency']!==$input['currency']
                ||!in_array($offer['display_mode'],['including_tax','excluding_tax'],true))self::fail();
            foreach(['price_excl_tax','price_incl_tax','display_price'] as $key){
                $v=$offer[$key];if((!is_int($v)&&!is_float($v))||!is_finite((float)$v)||$v<0||$v>1e9)self::fail();
            }
            $raw=$offer[$offer['display_mode']==='including_tax'?'price_incl_tax':'price_excl_tax'];
            if(abs($offer['display_price']-(float)number_format($raw,$p['price_decimals'],'.',''))>1e-8)self::fail();
        }
    }
    private static function email($v): bool {return is_string($v)&&strlen($v)<=254&&filter_var($v,FILTER_VALIDATE_EMAIL)!==false;}
    private static function id($v): bool {return is_string($v)&&preg_match('/^[1-9][0-9]{0,9}$/D',$v)===1;}
    private static function keys($v,array $expected): bool {if(!is_array($v))return false;$keys=array_keys($v);sort($keys);sort($expected);return $keys===$expected;}
    private static function fail(): void {throw new \RuntimeException('customer_prices_unavailable');}
}
