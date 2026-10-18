<?php
declare(strict_types=1);
namespace NeuroCheckout\WooCommerce\Community;

use PDO;
use RuntimeException;

/** Bounded native account discovery, including orders with no surviving cart. */
final class CustomerHistoryIndex
{
    public static function request(array $input, string $shopId): void
    {
        $keys=array_keys($input); sort($keys);
        if ($keys!==['afterId','ceilingId','limit','operation','schema','shopId'] || $input['schema']!==1
            || $input['operation']!=='customer_history_index' || $input['shopId']!==$shopId || $input['limit']!==8
            || !self::id($input['afterId']) || ($input['ceilingId']===null ? $input['afterId']!=='0'
                : (!self::id($input['ceilingId']) || (int)$input['ceilingId']<(int)$input['afterId']))) {
            throw new RuntimeException('source_invalid');
        }
    }

    private static function id($value): bool
    { return is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',$value)===1; }

    public static function response(array $page, array $input, string $raw, string $platform, int $scope): void
    {
        self::request($input,$input['shopId']);
        $keys=array_keys($page); sort($keys);
        $index=$page['index']??null;
        if ($keys!==['index','operation','requestHash','schema','shopId'] || $page['schema']!==1
            || $page['operation']!==$input['operation'] || $page['shopId']!==$input['shopId']
            || $page['requestHash']!==hash('sha256',$raw) || !is_array($index)) { self::fail(); }
        $keys=array_keys($index); sort($keys);
        if ($keys!==['after_id','ceiling_id','complete','customers','next_id','read_at']
            || $index['after_id']!==$input['afterId'] || !self::id($index['ceiling_id'])
            || !self::id($index['next_id']) || (int)$index['ceiling_id']<(int)$index['next_id']
            || ($input['ceilingId']!==null && $index['ceiling_id']!==$input['ceilingId'])
            || !is_bool($index['complete']) || !is_array($index['customers']) || count($index['customers'])>8
            || !is_string($index['read_at']) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$index['read_at'])
            || abs(time()-(int)strtotime($index['read_at']))>15) { self::fail(); }
        $previous=$input['afterId'];
        foreach ($index['customers'] as $person) {
            $expected=$platform==='magento' ? ['customerEmail','customerId','storeId','websiteId']
                : ($platform==='prestashop' ? ['customerEmail','customerId','nativeShopId'] : ['blogId','customerEmail','customerId']);
            if (!is_array($person)) { self::fail(); }
            $keys=array_keys($person); sort($keys);
            $scopeKey=$platform==='magento'?'storeId':($platform==='prestashop'?'nativeShopId':'blogId');
            if ($keys!==$expected || !self::id($person['customerId']) || (int)$person['customerId']<=(int)$previous
                || (int)$person['customerId']>(int)$index['ceiling_id'] || $person[$scopeKey] !== (string)$scope
                || ($platform==='magento' && (!self::id($person['websiteId']) || $person['websiteId']==='0'))
                || !is_string($person['customerEmail']) || strlen($person['customerEmail'])>254
                || !filter_var($person['customerEmail'],FILTER_VALIDATE_EMAIL)) { self::fail(); }
            $previous=$person['customerId'];
        }
        if ($previous!==$index['next_id'] || (!$index['complete'] && count($index['customers'])!==8)) { self::fail(); }
    }

    private static function fail(): void { throw new RuntimeException('customer_history_unavailable'); }

    public static function read(PDO $db, string $platform, string $prefix, int $scope, array $input,
        ?string $users=null, ?string $usermeta=null, bool $multisite=false): array
    {
        self::request($input,$input['shopId']);
        if ($scope<1 || !in_array($platform,['prestashop','woocommerce','magento'],true) || $db->inTransaction()) { self::fail(); }
        foreach ([$prefix,$users??'users',$usermeta??'usermeta'] as $name) {
            if (!preg_match('/^[A-Za-z0-9_]*$/D',$name)) { self::fail(); }
        }
        $table=static function(string $name) use($prefix): string {return '`'.$prefix.$name.'`';};
        $started=microtime(true); $owned=false;
        $query=static function(string $sql,array $args=[]) use($db,$started): array {
            if (microtime(true)-$started>3) { self::fail(); }
            $s=$db->prepare($sql); $s->execute($args); $rows=$s->fetchAll(PDO::FETCH_ASSOC);
            if (microtime(true)-$started>3) { self::fail(); }
            return $rows;
        };
        $engines=static function(array $names) use($query): void {
            $rows=$query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('
                .implode(',',array_fill(0,count($names),'?')).')',$names);
            if(count($rows)!==count($names)) { self::fail(); }
            foreach($rows as $row) { if(strcasecmp($row['ENGINE'],'InnoDB')!==0) { self::fail(); } }
        };
        try {
            $db->exec(stripos((string)$db->getAttribute(PDO::ATTR_SERVER_VERSION),'mariadb')!==false
                ? 'SET SESSION max_statement_time=1' : 'SET SESSION MAX_EXECUTION_TIME=1000');
            $db->exec('SET SESSION lock_wait_timeout=1');
            $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $db->exec('SET TRANSACTION READ ONLY'); $db->beginTransaction(); $owned=true;
            $scopeFields=[];
            if ($platform==='prestashop') {
                $engines([$prefix.'customer',$prefix.'orders',$prefix.'shop']);
                $shops=$query('SELECT id_shop_group FROM '.$table('shop').' WHERE id_shop=? AND active=1 AND deleted=0',[$scope]);
                if(count($shops)!==1) { self::fail(); }
                $from=$table('customer').' c'; $id='c.id_customer'; $email='c.email';
                $where='c.id_shop=? AND c.id_shop_group=? AND c.active=1 AND c.deleted=0 AND c.is_guest=0'
                    .' AND EXISTS (SELECT 1 FROM '.$table('orders').' o WHERE o.id_customer=c.id_customer AND o.id_shop=?)';
                $args=[$scope,$shops[0]['id_shop_group'],$scope]; $scopeFields=['nativeShopId'=>(string)$scope];
            } elseif ($platform==='magento') {
                $engines([$prefix.'customer_entity',$prefix.'sales_order',$prefix.'store',$prefix.'store_website']);
                $shops=$query('SELECT s.website_id FROM '.$table('store').' s JOIN '.$table('store_website')
                    .' w ON w.website_id=s.website_id WHERE s.store_id=? AND s.is_active=1',[$scope]);
                if(count($shops)!==1) { self::fail(); }
                $website=(string)$shops[0]['website_id'];
                $from=$table('customer_entity').' c'; $id='c.entity_id'; $email='c.email';
                $where="c.website_id=? AND c.is_active=1 AND (c.confirmation IS NULL OR c.confirmation='')"
                    .' AND EXISTS (SELECT 1 FROM '.$table('sales_order').' o WHERE o.customer_id=c.entity_id AND o.store_id=? AND o.customer_is_guest=0)';
                $args=[$website,$scope]; $scopeFields=['storeId'=>(string)$scope,'websiteId'=>$website];
            } else {
                if ($users===null || $usermeta===null) { self::fail(); }
                $engines([$users,$usermeta,$prefix.'options']);
                $options=$query('SELECT option_value FROM '.$table('options')." WHERE option_name='woocommerce_custom_orders_table_enabled' LIMIT 2");
                if(count($options)>1) { self::fail(); }
                $storage=$options[0]['option_value']??'no';
                if(!in_array($storage,['yes','no'],true)) { self::fail(); }
                $engines($storage==='yes'?[$prefix.'wc_orders']:[$prefix.'posts',$prefix.'postmeta']);
                $from='`'.$users.'` c'; $id='c.ID'; $email='c.user_email'; $args=[];
                $where='c.user_status=0'.($multisite?' AND c.spam=0 AND c.deleted=0':'');
                if($multisite) {
                    $where.=' AND EXISTS (SELECT 1 FROM `'.$usermeta.'` m WHERE m.user_id=c.ID AND m.meta_key=?)';
                    $args[]=$prefix.'capabilities';
                }
                $where.=$storage==='yes' ? ' AND EXISTS (SELECT 1 FROM '.$table('wc_orders')." o WHERE o.customer_id=c.ID AND o.type='shop_order')"
                    : ' AND EXISTS (SELECT 1 FROM '.$table('posts').' o JOIN '.$table('postmeta')." m ON m.post_id=o.ID AND m.meta_key='_customer_user'"
                        ." WHERE o.post_type='shop_order' AND m.meta_value=CAST(c.ID AS CHAR))";
                $scopeFields=['blogId'=>(string)$scope];
            }
            $ceiling=$input['ceilingId'];
            if($ceiling===null) { $ceiling=(string)($query('SELECT MAX('.$id.') ceiling_id FROM '.$from.' WHERE '.$where,$args)[0]['ceiling_id']??'0'); }
            $rows=$query('SELECT '.$id.' customer_id,'.$email.' customer_email FROM '.$from.' WHERE '.$where
                .' AND '.$id.'>? AND '.$id.'<=? ORDER BY '.$id.' LIMIT 9',array_merge($args,[$input['afterId'],$ceiling]));
            $complete=count($rows)<=8; $people=[]; $next=$input['afterId'];
            foreach(array_slice($rows,0,8) as $row) {
                $next=(string)$row['customer_id'];
                $people[]=$scopeFields+['customerId'=>$next,'customerEmail'=>$row['customer_email']];
            }
            return ['read_at'=>gmdate('Y-m-d\TH:i:s\Z'),'after_id'=>$input['afterId'],'ceiling_id'=>$ceiling,
                'next_id'=>$next,'complete'=>$complete,'customers'=>$people];
        } catch (\Throwable $error) { self::fail(); }
        finally { if($owned && $db->inTransaction()) { $db->rollBack(); } }
    }
}
