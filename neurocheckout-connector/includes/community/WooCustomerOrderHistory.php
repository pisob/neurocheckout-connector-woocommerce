<?php
declare(strict_types=1);
namespace NeuroCheckout\WooCommerce\Community;

use PDO;
use RuntimeException;

require_once __DIR__ . '/CustomerCartsProtocol.php';

/** Complete bounded account facts, never a sample of surviving cart sessions. */
final class WooCustomerOrderHistory
{
    private PDO $db;
    private string $prefix;
    private string $users;
    private string $usermeta;
    private int $scope;
    private bool $multisite;
    private float $started = 0;

    public function __construct(PDO $db, string $prefix, string $users, string $usermeta, int $scope, bool $multisite)
    {
        foreach ([$prefix,$users,$usermeta] as $name) {
            if (!preg_match('/^[A-Za-z0-9_]+$/D',$name)) { self::fail(); }
        }
        if ($scope<1 || $db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') { self::fail(); }
        $this->db=$db; $this->prefix=$prefix; $this->users=$users; $this->usermeta=$usermeta;
        $this->scope=$scope; $this->multisite=$multisite;
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
    }

    public function read(int $scope, int $customer, string $email, bool $activeCarts=false): array
    {
        $owned=false;
        try {
            if ($scope!==$this->scope || $customer<1 || strlen($email)>254
                || !filter_var($email,FILTER_VALIDATE_EMAIL) || $this->db->inTransaction()) { self::fail(); }
            $this->started=microtime(true);
            $this->db->exec(stripos((string)$this->db->getAttribute(PDO::ATTR_SERVER_VERSION),'mariadb')!==false
                ? 'SET SESSION max_statement_time=1' : 'SET SESSION MAX_EXECUTION_TIME=1000');
            if ($activeCarts) { $this->db->exec('SET SESSION lock_wait_timeout=1'); }
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->db->exec('SET TRANSACTION READ ONLY');
            // PDO must own the transaction (also on PHP 7.4). The first SELECT
            // below establishes the repeatable-read view used by every fact.
            $this->db->beginTransaction(); $owned=true;
            $this->engines([$this->users,$this->usermeta,$this->prefix.'options']);
            $options=$this->rows('SELECT option_value FROM '.$this->table('options')
                ." WHERE option_name='woocommerce_custom_orders_table_enabled'",[],1);
            $storage=$options[0]['option_value']??'no';
            if (!in_array($storage,['yes','no'],true)) { self::fail(); }
            $hpos=$storage==='yes';
            $tables=$hpos ? ['wc_orders','wc_orders_meta','wc_order_operational_data'] : ['posts','postmeta'];
            $tables=array_merge($tables,['woocommerce_order_items','woocommerce_order_itemmeta']);
            $this->engines(array_map(function($name){return $this->prefix.$name;},$activeCarts ? ['woocommerce_sessions'] : $tables));
            $people=$this->rows('SELECT ID,user_email,user_registered FROM `'.$this->users.'` WHERE ID=? AND user_status=0'
                .($this->multisite?' AND spam=0 AND deleted=0':''),[$customer],1);
            if (count($people)!==1 || strcasecmp($people[0]['user_email'],$email)!==0) { self::fail(); }
            $person=$people[0];
            if ($this->multisite) {
                $membership=$this->userMeta($customer,$this->prefix.'capabilities');
                $roles=count($membership)===1 && strlen($membership[0]['meta_value'])<=4096
                    ? BoundedPhpValueReader::decode($membership[0]['meta_value']) : null;
                if (!is_array($roles) || !in_array(true,$roles,true)) { self::fail(); }
            }
            if ($activeCarts) {
                $active=$this->hasActiveCart($customer);
                $result=CustomerCartsProtocol::result(['blogId'=>(string)$scope,'customerId'=>(string)$customer,
                    'customerEmail'=>$person['user_email']],$active,$this->consent($customer,$person['user_email']));
                $this->checkTime(); return $result;
            }
            $orders=$this->orders($hpos,$customer);
            $ids=array_column($orders,'order_id');
            $refunds=$ids ? $this->refunds($hpos,$ids) : [];
            $allIds=array_merge($ids,array_column($refunds,'refund_id'));
            if (count($allIds)!==count(array_unique($allIds))) { self::fail(); }
            $items=$allIds ? $this->items($allIds) : [];
            foreach ($orders as &$order) {
                $order['items']=$items[$order['order_id']]??[];
                $order['refunds']=[];
                foreach ($refunds as $refund) {
                    if ($refund['parent_order_id']!==$order['order_id']) { continue; }
                    $refund['items']=$items[$refund['refund_id']]??[];
                    unset($refund['parent_order_id']); $order['refunds'][]=$refund;
                }
            }
            unset($order);
            $result=['schema'=>'woocommerce-customer-history-v1','blog_id'=>$scope,'customer_id'=>$customer,
                'customer_email'=>$person['user_email'],'customer_created_at'=>$person['user_registered'],
                'read_at'=>gmdate('Y-m-d\TH:i:s\Z'),'complete'=>true,'order_storage'=>$hpos?'hpos':'posts',
                'order_count'=>count($orders),'orders'=>$orders,'marketing_consent'=>$this->consent($customer,$person['user_email'])];
            WooCustomerHistoryProtocol::history($result,['blogId'=>(string)$scope,'customerId'=>(string)$customer,'customerEmail'=>$email]);
            $this->checkTime(); return $result;
        } catch (\Throwable $error) { self::fail(); }
        finally {
            if ($owned) {
                try { if ($this->db->inTransaction()) { $this->db->rollBack(); } }
                catch (\Throwable $error) { self::fail(); }
            }
        }
    }

    private function hasActiveCart(int $customer): bool
    {
        // Inspect native persisted carts only. Never initialize WC()->cart or a
        // shopper session, and never execute serialized objects or metadata hooks.
        $saved=$this->rows('SELECT LEFT(meta_value,65537) value FROM `'.$this->usermeta.'`'
            .' WHERE user_id=? AND meta_key=?',[$customer,'_woocommerce_persistent_cart_'.$this->scope],1);
        $sessions=$this->rows('SELECT LEFT(session_value,65537) value,session_expiry FROM '.$this->table('woocommerce_sessions')
            .' WHERE session_key=?',[(string)$customer],1);
        $active=false;
        if ($saved) {
            $value=BoundedPhpValueReader::decode($saved[0]['value']);
            if (!is_array($value) || array_keys($value)!==['cart']) { self::fail(); }
            $active=$this->nonemptyCart($value['cart']);
        }
        if ($sessions) {
            $expiry=(string)$sessions[0]['session_expiry'];
            if (!preg_match('/^[1-9][0-9]{0,11}$/D',$expiry) || (int)$expiry>253402300799) { self::fail(); }
            if ((int)$expiry>time()) {
                $value=BoundedPhpValueReader::decode($sessions[0]['value']);
                if (!is_array($value)) { self::fail(); }
                $cart=array_key_exists('cart',$value) ? $value['cart'] : [];
                if (is_string($cart)) { $cart=BoundedPhpValueReader::decode($cart); }
                // Do not short-circuit validation of one source because the
                // other already contains a cart.
                $sessionActive=$this->nonemptyCart($cart);
                $active=$active || $sessionActive;
            }
        }
        return $active;
    }
    private function nonemptyCart($cart): bool
    {
        if (!is_array($cart) || count($cart)>128) { self::fail(); }
        $active=false;
        foreach ($cart as $line) {
            if (!is_array($line) || !isset($line['quantity']) || is_bool($line['quantity'])
                || (!is_string($line['quantity']) && !is_int($line['quantity']) && !is_float($line['quantity']))) { self::fail(); }
            $quantity=(string)$line['quantity'];
            if (!preg_match('/^\d{1,10}(?:\.\d{1,8})?$/D',$quantity)) { self::fail(); }
            $active=$active || (float)$quantity>0;
        }
        return $active;
    }

    private function orders(bool $hpos, int $customer): array
    {
        if ($hpos) {
            $rows=$this->rows('SELECT CAST(o.id AS CHAR) order_id,o.status,o.date_created_gmt created_at,o.date_updated_gmt updated_at,'
                .'o.currency,CAST(o.total_amount AS CHAR) total,d.date_paid_gmt,CAST(d.order_id AS CHAR) operational_order_id FROM '.$this->table('wc_orders').' o LEFT JOIN '
                .$this->table('wc_order_operational_data')." d ON d.order_id=o.id WHERE o.type='shop_order' AND o.customer_id=? ORDER BY o.id",[$customer],128);
            foreach ($rows as &$row) {
                if ($row['operational_order_id']!==$row['order_id']) { self::fail(); }
                unset($row['operational_order_id']);
            }
            unset($row); return $rows;
        }
        // EXISTS does not multiply rows; the metadata reader below still rejects duplicates.
        $rows=$this->rows('SELECT CAST(p.ID AS CHAR) order_id,p.post_status status,p.post_date_gmt created_at,p.post_modified_gmt updated_at FROM '
            .$this->table('posts')." p WHERE p.post_type='shop_order' AND EXISTS (SELECT 1 FROM ".$this->table('postmeta')
            ." m WHERE m.post_id=p.ID AND m.meta_key='_customer_user' AND m.meta_value=?) ORDER BY p.ID",[(string)$customer],128);
        $meta=$this->metadata('postmeta','post_id',array_column($rows,'order_id'),['_customer_user','_order_currency','_order_total','_date_paid']);
        foreach ($rows as &$row) {
            $m=$meta[$row['order_id']]??[];
            if (($m['_customer_user']??null)!==(string)$customer) { self::fail(); }
            $row['currency']=$m['_order_currency']??null; $row['total']=$m['_order_total']??null;
            $paid=$m['_date_paid']??'';
            if ($paid!=='' && $paid!=='0' && !preg_match('/^[1-9][0-9]{0,9}$/D',$paid)) { self::fail(); }
            // Missing dates are unknown, never proof of no payment or zero paid amount.
            $row['date_paid_gmt']=$paid===''||$paid==='0' ? null : gmdate('Y-m-d H:i:s',(int)$paid);
        }
        unset($row); return $rows;
    }

    private function refunds(bool $hpos, array $ids): array
    {
        $in=implode(',',array_fill(0,count($ids),'?'));
        $rows=$hpos
            ? $this->rows('SELECT CAST(id AS CHAR) refund_id,CAST(parent_order_id AS CHAR) parent_order_id,date_created_gmt created_at FROM '
                .$this->table('wc_orders')." WHERE type='shop_order_refund' AND parent_order_id IN ($in) ORDER BY id",$ids,128)
            : $this->rows('SELECT CAST(ID AS CHAR) refund_id,CAST(post_parent AS CHAR) parent_order_id,post_date_gmt created_at FROM '
                .$this->table('posts')." WHERE post_type='shop_order_refund' AND post_parent IN ($in) ORDER BY ID",$ids,128);
        $meta=$this->metadata($hpos?'wc_orders_meta':'postmeta',$hpos?'order_id':'post_id',array_column($rows,'refund_id'),['_refund_amount','_refunded_payment']);
        foreach ($rows as &$row) {
            $m=$meta[$row['refund_id']]??[]; $row['amount']=$m['_refund_amount']??null;
            $flag=$m['_refunded_payment']??null;
            if (!in_array($flag,[null,'','0','1','no','yes'],true)) { self::fail(); }
            $row['payment_refunded']=$flag===null ? null : in_array($flag,['1','yes'],true);
        }
        unset($row); return $rows;
    }

    private function items(array $ids): array
    {
        $rows=$this->rows('SELECT CAST(order_item_id AS CHAR) item_id,CAST(order_id AS CHAR) order_id FROM '
            .$this->table('woocommerce_order_items')." WHERE order_item_type='line_item' AND order_id IN ("
            .implode(',',array_fill(0,count($ids),'?')).') ORDER BY order_id,order_item_id',$ids,512);
        $meta=$this->metadata('woocommerce_order_itemmeta','order_item_id',array_column($rows,'item_id'),
            ['_product_id','_variation_id','_qty','_line_total','_line_tax','_refunded_item_id']);
        $result=[];
        foreach ($rows as $row) {
            $m=$meta[$row['item_id']]??[];
            $result[$row['order_id']][]=['item_id'=>$row['item_id'],'product_id'=>$m['_product_id']??null,
                'variation_id'=>$m['_variation_id']??null,'quantity'=>$m['_qty']??null,
                'line_total'=>$m['_line_total']??null,'line_tax'=>$m['_line_tax']??null,
                'refunded_item_id'=>$m['_refunded_item_id']??null];
        }
        return $result;
    }

    private function consent(int $customer, string $email): string
    {
        try {
            $rows=$this->userMeta($customer,'_ncwoo_marketing_consent_'.$this->scope);
            if (count($rows)!==1 || strlen($rows[0]['meta_value'])>4096) { return 'unknown'; }
            $v=count($rows)===1 ? BoundedPhpValueReader::decode($rows[0]['meta_value']) : null;
            if (!is_array($v) || ($v['version']??null)!=='product-recommendations-v1'
                || ($v['blog_id']??null)!==$this->scope || ($v['customer_id']??null)!==$customer
                || ($v['email']??null)!==strtolower($email) || !in_array($v['state']??null,['granted','denied'],true)
                || !is_int($v['changed_at']??null) || $v['changed_at']<1 || $v['changed_at']>time()+60) { return 'unknown'; }
            return $v['state'];
        } catch (\Throwable $error) { return 'unknown'; }
    }
    private function userMeta(int $customer, string $key): array
    {
        return $this->rows('SELECT LEFT(meta_value,4097) meta_value FROM `'.$this->usermeta.'` WHERE user_id=? AND meta_key=? ORDER BY umeta_id',[$customer,$key],2);
    }
    private function metadata(string $table, string $column, array $ids, array $keys): array
    {
        if (!$ids) { return []; }
        $rows=$this->rows('SELECT '.$column.' owner_id,meta_key,LEFT(meta_value,129) meta_value FROM '.$this->table($table)
            .' WHERE '.$column.' IN ('.implode(',',array_fill(0,count($ids),'?')).') AND meta_key IN ('
            .implode(',',array_fill(0,count($keys),'?')).')',array_merge($ids,$keys),count($ids)*count($keys));
        $values=[];
        foreach ($rows as $row) {
            if (array_key_exists($row['meta_key'],$values[$row['owner_id']]??[])) { self::fail(); }
            $values[$row['owner_id']][$row['meta_key']]=$row['meta_value'];
        }
        return $values;
    }
    private function engines(array $names): void
    {
        // Acquire native metadata locks inside our transaction before checking
        // engines. A concurrent ALTER must not swap a verified table to MyISAM
        // between verification and its first data read. No row contents needed.
        foreach ($names as $name) { $this->rows('SELECT 1 FROM `'.$name.'` WHERE 1=0',[],0); }
        $rows=$this->rows('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('
            .implode(',',array_fill(0,count($names),'?')).')',$names,count($names));
        if (count($rows)!==count($names)) { self::fail(); }
        foreach ($rows as $r) { if (strcasecmp((string)$r['ENGINE'],'InnoDB')!==0) { self::fail(); } }
    }
    private function rows(string $sql, array $parameters, int $limit): array
    {
        $this->checkTime(); $q=$this->db->prepare($sql.' LIMIT '.($limit+1)); $q->execute($parameters); $rows=$q->fetchAll();
        if (count($rows)>$limit) { self::fail(); } $this->checkTime(); return $rows;
    }
    private function table(string $name): string { return '`'.$this->prefix.$name.'`'; }
    private function checkTime(): void { if (microtime(true)-$this->started>3) { self::fail(); } }
    private static function fail(): void { throw new RuntimeException('customer_history_unavailable'); }
}
