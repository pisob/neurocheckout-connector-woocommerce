<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
use NeuroCheckout\WooCommerce\Community\WooCustomerOrderHistory;
use NeuroCheckout\WooCommerce\Community\WooSourceSnapshotFactory;
$base=__DIR__.'/../neurocheckout-connector/includes/community/';
foreach (['BoundedPhpValueReader','WooCustomerHistoryProtocol','WooCustomerOrderHistory','WooSourceSnapshotFactory'] as $name) { require_once $base.$name.'.php'; }
$socket=$argv[1]??'';
if (!preg_match('#^/tmp/nc-source-mariadb\.[A-Za-z0-9]+/mariadb\.sock$#D',$socket) || !file_exists($socket)) { exit(2); }
set_error_handler(static function($severity,$message) { throw new RuntimeException($message); });
$checks=0;
function sqlCheck(bool $ok,string $label): void { global $checks; ++$checks; if (!$ok) { throw new RuntimeException($label); } }
function sqlReject(callable $run,string $label,string $code='customer_history_unavailable'): void {
    try { $run(); } catch (Throwable $e) { sqlCheck($e->getMessage()===$code,$label.' constant error'); return; }
    throw new RuntimeException($label.' unexpectedly accepted');
}
class HistoryTestPDO extends PDO {
    public $onUserRead=null;
    #[\ReturnTypeWillChange]
    public function prepare($query,$options=[]) {
        if ($this->onUserRead!==null && strpos($query,'SELECT ID,user_email,user_registered FROM `wp_users` WHERE')===0) {
            $callback=$this->onUserRead; $this->onUserRead=null; $callback($this);
        }
        return parent::prepare($query,$options);
    }
}
// Native runtime guards are tested with explicit doubles; no WordPress boot.
class wpdb {
    public int $blogid=2; public string $prefix='wp_2_'; public string $base_prefix='wp_';
    public string $users='wp_users'; public string $usermeta='wp_usermeta';
    public function get_blog_prefix($scope) { return 'wp_'.$scope.'_'; }
}
$wpdb=new wpdb(); $wp_filter=[]; $nativeSite=(object)['archived'=>0,'spam'=>0,'deleted'=>0];
function get_current_blog_id() { return 2; }
function is_multisite() { return true; }
function get_site($id) { global $nativeSite; return $nativeSite; }
function has_filter($name) { return false; }
define('WC_VERSION','10.7.0'); define('WP_CONTENT_DIR','/synthetic-history-test-no-wp');
define('DB_HOST','localhost:'.$socket); define('DB_USER','root'); define('DB_PASSWORD','');
$database='nc_woo_history_fixture_'.bin2hex(random_bytes(6)); define('DB_NAME',$database);
$dsn='mysql:unix_socket='.$socket.';charset=utf8mb4';
$admin=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE `'.$database.'`');
try {
    $writer=new PDO($dsn.';dbname='.$database,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $readerDb=new HistoryTestPDO($dsn.';dbname='.$database,'root','');
    $schemas=[
        'options'=>'option_id BIGINT AUTO_INCREMENT PRIMARY KEY,option_name VARCHAR(191) UNIQUE,option_value LONGTEXT',
        'posts'=>'ID BIGINT PRIMARY KEY,post_parent BIGINT DEFAULT 0,post_type VARCHAR(20),post_status VARCHAR(20),post_date_gmt DATETIME,post_modified_gmt DATETIME',
        'postmeta'=>'meta_id BIGINT AUTO_INCREMENT PRIMARY KEY,post_id BIGINT,meta_key VARCHAR(255),meta_value LONGTEXT',
        'wc_orders'=>'id BIGINT PRIMARY KEY,parent_order_id BIGINT DEFAULT 0,customer_id BIGINT DEFAULT 0,type VARCHAR(20),status VARCHAR(20),currency VARCHAR(10),total_amount DECIMAL(26,8),date_created_gmt DATETIME,date_updated_gmt DATETIME',
        'wc_orders_meta'=>'id BIGINT AUTO_INCREMENT PRIMARY KEY,order_id BIGINT,meta_key VARCHAR(255),meta_value TEXT',
        'wc_order_operational_data'=>'id BIGINT AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNIQUE,date_paid_gmt DATETIME',
        'woocommerce_order_items'=>'order_item_id BIGINT PRIMARY KEY,order_id BIGINT,order_item_type VARCHAR(200)',
        'woocommerce_order_itemmeta'=>'meta_id BIGINT AUTO_INCREMENT PRIMARY KEY,order_item_id BIGINT,meta_key VARCHAR(255),meta_value LONGTEXT',
    ];
    $writer->exec('CREATE TABLE wp_users(ID BIGINT PRIMARY KEY,user_email VARCHAR(100),user_registered DATETIME,user_status INT,spam INT DEFAULT 0,deleted INT DEFAULT 0) ENGINE=InnoDB');
    $writer->exec('CREATE TABLE wp_usermeta(umeta_id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id BIGINT,meta_key VARCHAR(255),meta_value LONGTEXT) ENGINE=InnoDB');
    $writer->exec("INSERT INTO wp_users(ID,user_email,user_registered,user_status) VALUES (47,'reader@example.invalid','2019-01-01',0),(48,'other@example.invalid','2019-01-01',0),(49,'empty@example.invalid','2019-01-01',0)");
    $putUser=$writer->prepare('INSERT INTO wp_usermeta(user_id,meta_key,meta_value) VALUES (?,?,?)');
    foreach ([47,48,49] as $id) { $putUser->execute([$id,'wp_2_capabilities',serialize(['customer'=>true])]); }
    $consent=['version'=>'product-recommendations-v1','blog_id'=>2,'customer_id'=>47,'email'=>'reader@example.invalid','state'=>'granted','changed_at'=>time()-30];
    $putUser->execute([47,'_ncwoo_marketing_consent_2',serialize($consent)]);
    foreach (['wp_2_','wp_3_'] as $prefix) {
        foreach ($schemas as $table=>$schema) { $writer->exec('CREATE TABLE `'.$prefix.$table.'` ('.$schema.') ENGINE=InnoDB'); }
        $writer->exec("INSERT INTO {$prefix}options(option_name,option_value) VALUES ('woocommerce_custom_orders_table_enabled','no')");
        $postmeta=$writer->prepare("INSERT INTO {$prefix}postmeta(post_id,meta_key,meta_value) VALUES (?,?,?)");
        foreach ([100=>47,101=>48,102=>0] as $id=>$customer) {
            $writer->exec("INSERT INTO {$prefix}posts VALUES ($id,0,'shop_order','wc-on-hold','2020-01-01','2020-01-02')");
            foreach (['_customer_user'=>(string)$customer,'_billing_email'=>'reader@example.invalid','_order_currency'=>'EUR','_order_total'=>'48.12500000'] as $key=>$value) {
                $postmeta->execute([$id,$key,$value]);
            }
        }
        $writer->exec("INSERT INTO {$prefix}posts VALUES (110,100,'shop_order_refund','wc-completed','2020-01-02','2020-01-02')");
        $postmeta->execute([110,'_refund_amount','24.06250000']);
        $postmeta->execute([110,'_refunded_payment','no']);
        $hmeta=$writer->prepare("INSERT INTO {$prefix}wc_orders_meta(order_id,meta_key,meta_value) VALUES (?,?,?)");
        foreach ([200=>47,201=>48,202=>0] as $id=>$customer) {
            $writer->exec("INSERT INTO {$prefix}wc_orders VALUES ($id,0,$customer,'shop_order','wc-completed','EUR',48.12500000,'2020-01-01','2020-01-02')");
            $writer->exec("INSERT INTO {$prefix}wc_order_operational_data(order_id,date_paid_gmt) VALUES ($id,'2020-01-01 12:00:00')");
            $hmeta->execute([$id,'_billing_email','reader@example.invalid']);
        }
        $writer->exec("INSERT INTO {$prefix}wc_orders VALUES (210,200,47,'shop_order_refund','wc-completed','EUR',-999,'2020-01-02','2020-01-02')");
        $hmeta->execute([210,'_refund_amount','24.06250000']); $hmeta->execute([210,'_refunded_payment','yes']);
        $itemmeta=$writer->prepare("INSERT INTO {$prefix}woocommerce_order_itemmeta(order_item_id,meta_key,meta_value) VALUES (?,?,?)");
        foreach ([1001=>100,1101=>110,2001=>200,2101=>210] as $item=>$id) {
            $refund=in_array($id,[110,210],true);
            $writer->exec("INSERT INTO {$prefix}woocommerce_order_items VALUES ($item,$id,'line_item')");
            $fields=['_product_id'=>'10','_variation_id'=>'11','_qty'=>$refund?'-1':'2',
                '_line_total'=>$refund?'-20.05208333':'40.10416667','_line_tax'=>$refund?'-4.01041667':'8.02083333'];
            if ($refund) { $fields['_refunded_item_id']=(string)($item-100); }
            foreach ($fields as $key=>$value) { $itemmeta->execute([$item,$key,$value]); }
        }
        // Non-product lines are not silently invented as purchased products.
        $writer->exec("INSERT INTO {$prefix}woocommerce_order_items VALUES (1900,100,'shipping'),(2900,200,'fee')");
    }
    $reader=new WooCustomerOrderHistory($readerDb,'wp_2_','wp_users','wp_usermeta',2,true);
    $read=static function() use ($reader): array { return $reader->read(2,47,'reader@example.invalid'); };
    $posts=$read();
    sqlCheck($posts['order_count']===1 && $posts['orders'][0]['order_id']==='100','complete account history excludes guest, different account and HPOS backup');
    sqlCheck($posts['order_storage']==='posts' && $posts['complete']===true,'authoritative posts storage');
    sqlCheck($posts['marketing_consent']==='granted','same-snapshot native scoped consent');
    sqlCheck($posts['orders'][0]['date_paid_gmt']===null && !array_key_exists('total_paid',$posts['orders'][0]),'unpaid order never upgraded into revenue');
    sqlCheck($posts['orders'][0]['total']==='48.12500000','native decimal not rounded');
    sqlCheck(count($posts['orders'][0]['items'])===1 && $posts['orders'][0]['items'][0]['variation_id']==='11','native product variant separate from shipping');
    sqlCheck($posts['orders'][0]['refunds'][0]['amount']==='24.06250000' && $posts['orders'][0]['refunds'][0]['payment_refunded']===false,'manual refund kept distinct from payment refund');
    sqlCheck($posts['orders'][0]['refunds'][0]['items'][0]['refunded_item_id']==='1001','refund linked to original item');
    sqlCheck(!$readerDb->inTransaction(),'successful read closes own transaction');
    $empty=$reader->read(2,49,'empty@example.invalid');
    sqlCheck($empty['orders']===[] && $empty['complete']===true && $empty['marketing_consent']==='unknown','empty native history is proven, not a failed read');
    sqlReject(static function() use ($reader) { $reader->read(3,47,'reader@example.invalid'); },'cross site');
    sqlReject(static function() use ($reader) { $reader->read(2,48,'reader@example.invalid'); },'billing email not account identity');
    sqlReject(static function() use ($reader) { $reader->read(2,0,'reader@example.invalid'); },'guest');
    sqlReject(static function() use ($reader) { $reader->read(2,999,'reader@example.invalid'); },'missing account');
    sqlReject(static function() use ($reader) { $reader->read(2,47,'old@example.invalid'); },'email changed');
    foreach (['spam','deleted','user_status'] as $flag) {
        $writer->exec("UPDATE wp_users SET $flag=1 WHERE ID=47"); sqlReject($read,'inactive account '.$flag);
        $writer->exec("UPDATE wp_users SET $flag=0 WHERE ID=47");
    }
    $roles=$writer->prepare("UPDATE wp_usermeta SET meta_value=? WHERE user_id=47 AND meta_key='wp_2_capabilities'");
    $roles->execute([serialize([])]); sqlReject($read,'no site membership');
    $roles->execute([serialize(['customer'=>true])]);
    $putUser->execute([47,'wp_2_capabilities',serialize(['customer'=>true])]); sqlReject($read,'duplicate membership');
    $writer->exec('DELETE FROM wp_usermeta WHERE umeta_id=LAST_INSERT_ID()');
    $preference=static function(string $value) use ($writer): void {
        $writer->prepare('UPDATE wp_usermeta SET meta_value=? WHERE user_id=? AND meta_key=?')
            ->execute([$value,47,'_ncwoo_marketing_consent_2']);
    };
    foreach (['email'=>'old@example.invalid','blog_id'=>3,'customer_id'=>48,'version'=>'old','changed_at'=>time()+600,'state'=>'unknown'] as $key=>$value) {
        $preference(serialize(array_replace($consent,[$key=>$value])));
        sqlCheck($read()['marketing_consent']==='unknown','consent mismatch '.$key);
    }
    foreach (['not serialized','O:8:"stdClass":0:{}',str_repeat('x',4097)] as $invalid) {
        $preference($invalid); sqlCheck($read()['marketing_consent']==='unknown','unsafe consent remains unknown');
    }
    $preference(serialize(array_replace($consent,['state'=>'denied'])));
    sqlCheck($read()['marketing_consent']==='denied','withdrawal read without cached stale grant');
    $preference(serialize($consent));
    $putUser->execute([47,'_ncwoo_marketing_consent_2',serialize($consent)]);
    sqlCheck($read()['marketing_consent']==='unknown','duplicate consent never grants');
    $writer->exec('DELETE FROM wp_usermeta WHERE umeta_id=LAST_INSERT_ID()');
    $readerDb->beginTransaction(); sqlReject($read,'does not join caller transaction');
    sqlCheck($readerDb->inTransaction(),'caller transaction untouched'); $readerDb->rollBack();
    $readonly=false;
    $readerDb->onUserRead=static function($db) use (&$readonly) {
        try { $db->exec("UPDATE wp_users SET user_email='should-not-write@example.invalid' WHERE ID=47"); }
        catch (PDOException $e) { $readonly=(int)($e->errorInfo[1]??0)===1792; }
    };
    $read(); sqlCheck($readonly,'database enforces READ ONLY');
    $readerDb->onUserRead=static function() use ($writer,$preference,$consent) {
        $writer->exec("UPDATE wp_2_postmeta SET meta_value='99' WHERE post_id=100 AND meta_key='_order_total'");
        $preference(serialize(array_replace($consent,['state'=>'denied'])));
    };
    $consistent=$read();
    sqlCheck($consistent['orders'][0]['total']==='48.12500000' && $consistent['marketing_consent']==='granted','concurrent order/consent updates do not mix snapshots');
    sqlCheck($read()['orders'][0]['total']==='99' && $read()['marketing_consent']==='denied','next read sees committed native updates');
    $writer->exec("UPDATE wp_2_postmeta SET meta_value='48.12500000' WHERE post_id=100 AND meta_key='_order_total'");
    $preference(serialize($consent));
    $ddlBlocked=false;
    $readerDb->onUserRead=static function() use ($writer,&$ddlBlocked) {
        $writer->exec('SET SESSION lock_wait_timeout=1');
        try { $writer->exec('ALTER TABLE wp_users ENGINE=MyISAM'); }
        catch (PDOException $e) { $ddlBlocked=(int)($e->errorInfo[1]??0)===1205; }
    };
    $read(); sqlCheck($ddlBlocked,'metadata lock prevents concurrent engine substitution');
    $writer->exec("INSERT INTO wp_2_postmeta(post_id,meta_key,meta_value) VALUES (100,'_order_total','123')");
    sqlReject($read,'duplicate order fact'); sqlCheck(!$readerDb->inTransaction(),'failed read closes own transaction');
    $writer->exec('DELETE FROM wp_2_postmeta WHERE meta_id=LAST_INSERT_ID()');
    $writer->exec("INSERT INTO wp_2_postmeta(post_id,meta_key,meta_value) VALUES (100,'_date_paid','1577880000')");
    sqlCheck($read()['orders'][0]['date_paid_gmt']==='2020-01-01 12:00:00','native paid timestamp exported as UTC without amount inference');
    $writer->exec("DELETE FROM wp_2_postmeta WHERE post_id=100 AND meta_key='_date_paid'");
    $writer->exec("UPDATE wp_2_options SET option_value='yes' WHERE option_name='woocommerce_custom_orders_table_enabled'");
    $hpos=$read();
    sqlCheck($hpos['order_storage']==='hpos' && $hpos['order_count']===1 && $hpos['orders'][0]['order_id']==='200','HPOS ignores conflicting posts backup');
    sqlCheck($hpos['orders'][0]['date_paid_gmt']==='2020-01-01 12:00:00','native HPOS paid date');
    sqlCheck($hpos['orders'][0]['refunds'][0]['amount']==='24.06250000' && $hpos['orders'][0]['refunds'][0]['payment_refunded']===true,'HPOS uses refund metadata, not negative header total');
    $writer->exec('UPDATE wp_2_wc_order_operational_data SET order_id=999 WHERE order_id=200'); sqlReject($read,'missing HPOS operational row');
    $writer->exec('UPDATE wp_2_wc_order_operational_data SET order_id=200 WHERE order_id=999');
    $writer->exec("UPDATE wp_2_woocommerce_order_itemmeta SET meta_value='999' WHERE order_item_id=2101 AND meta_key='_refunded_item_id'");
    sqlReject($read,'refund cannot reference another purchase');
    $writer->exec("UPDATE wp_2_woocommerce_order_itemmeta SET meta_value='2001' WHERE order_item_id=2101 AND meta_key='_refunded_item_id'");
    $writer->exec("UPDATE wp_2_wc_orders_meta SET meta_value='-24' WHERE order_id=210 AND meta_key='_refund_amount'");
    sqlReject($read,'negative refund amount not silently corrected');
    $writer->exec("UPDATE wp_2_wc_orders_meta SET meta_value='24.06250000' WHERE order_id=210 AND meta_key='_refund_amount'");
    $writer->exec('ALTER TABLE wp_2_wc_orders_meta ENGINE=MyISAM'); sqlReject($read,'nontransactional facts');
    $writer->exec('ALTER TABLE wp_2_wc_orders_meta ENGINE=InnoDB');
    $writer->exec("UPDATE wp_2_options SET option_value='unsupported' WHERE option_name='woocommerce_custom_orders_table_enabled'");
    sqlReject($read,'unknown native store');
    $writer->exec("UPDATE wp_2_options SET option_value='yes' WHERE option_name='woocommerce_custom_orders_table_enabled'");
    $readerDb->onUserRead=static function($db) { $db->query('SELECT SLEEP(2)'); };
    $start=microtime(true); sqlReject($read,'SQL statement deadline');
    sqlCheck(microtime(true)-$start<1.8 && !$readerDb->inTransaction(),'deadline bounded and transaction closed');
    // Capacity is a failed complete read, never a truncated successful audience.
    $writer->beginTransaction();
    for ($id=300;$id<428;$id++) {
        $writer->exec("INSERT INTO wp_2_wc_orders SELECT $id,0,47,type,status,currency,total_amount,date_created_gmt,date_updated_gmt FROM wp_2_wc_orders WHERE id=200");
        $writer->exec("INSERT INTO wp_2_wc_order_operational_data(order_id,date_paid_gmt) VALUES ($id,NULL)");
    }
    $writer->commit(); sqlReject($read,'more than 128 orders');
    $writer->exec('DELETE FROM wp_2_wc_orders WHERE id BETWEEN 300 AND 427');
    $writer->exec('DELETE FROM wp_2_wc_order_operational_data WHERE order_id BETWEEN 300 AND 427');
    $writer->beginTransaction();
    for ($id=300;$id<428;$id++) {
        $writer->exec("INSERT INTO wp_2_wc_orders SELECT $id,parent_order_id,customer_id,type,status,currency,total_amount,date_created_gmt,date_updated_gmt FROM wp_2_wc_orders WHERE id=210");
    }
    $writer->commit(); sqlReject($read,'more than 128 refunds');
    $writer->exec('DELETE FROM wp_2_wc_orders WHERE id BETWEEN 300 AND 427');
    $writer->beginTransaction();
    for ($id=3000;$id<3512;$id++) { $writer->exec("INSERT INTO wp_2_woocommerce_order_items VALUES ($id,200,'line_item')"); }
    $writer->commit(); sqlReject($read,'more than 512 product/refund lines');
    $writer->exec('DELETE FROM wp_2_woocommerce_order_items WHERE order_item_id BETWEEN 3000 AND 3511');
    $configuration=['nativeScope'=>2,'platform'=>'woocommerce','environment'=>'production'];
    sqlCheck(WooSourceSnapshotFactory::customerHistory(2,$configuration)->read(2,47,'reader@example.invalid')['order_count']===1,'trusted factory builds native read-only reader');
    $wpdb->users='foreign_users';
    sqlReject(static function() use ($configuration) { WooSourceSnapshotFactory::customerHistory(2,$configuration); },'custom user table');
    $wpdb->users='wp_users'; $nativeSite->archived=1;
    sqlReject(static function() use ($configuration) { WooSourceSnapshotFactory::customerHistory(2,$configuration); },'archived native site');
    $nativeSite->archived=0;
    sqlCheck($read()['orders'][0]['order_id']==='200','reader reusable after failures');
    sqlCheck($writer->query('SELECT COUNT(*) FROM wp_3_wc_orders')->fetchColumn()==4,'foreign site untouched');
    echo "Woo customer-history disposable SQL: $checks checks passed.\n";
} finally {
    if (isset($writer) && $writer->inTransaction()) { $writer->rollBack(); }
    if (isset($readerDb) && $readerDb->inTransaction()) { $readerDb->rollBack(); }
    // Only this process's newly created random fixture is removed.
    $admin->exec('DROP DATABASE `'.$database.'`');
}
