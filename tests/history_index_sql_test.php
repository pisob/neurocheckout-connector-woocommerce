<?php
declare(strict_types=1);
require_once __DIR__.'/../neurocheckout-connector/includes/community/CustomerHistoryIndex.php';
use NeuroCheckout\WooCommerce\Community\CustomerHistoryIndex as Index;
const PLATFORM='woocommerce';
$socket=$argv[1]??'';
if(!preg_match('#^/tmp/nc-source-mariadb\.[A-Za-z0-9]+/mariadb\.sock$#D',$socket)||!file_exists($socket)){exit(2);}
$dsn='mysql:unix_socket='.$socket.';charset=utf8mb4';$name='nc_index_fixture_'.bin2hex(random_bytes(6));
$admin=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$admin->exec('CREATE DATABASE `'.$name.'`');
$checks=0;
function checkIndex(bool $ok):void {global $checks;++$checks;if(!$ok)throw new RuntimeException('index SQL assertion failed '.$checks);}
try {
    $db=new PDO($dsn.';dbname='.$name,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $schemas=PLATFORM==='prestashop' ? ['shop'=>'id_shop INT PRIMARY KEY,id_shop_group INT,active INT,deleted INT',
        'customer'=>'id_customer INT PRIMARY KEY,id_shop INT,id_shop_group INT,email VARCHAR(254),active INT,deleted INT,is_guest INT',
        'orders'=>'id_order INT PRIMARY KEY,id_customer INT,id_shop INT'] : (PLATFORM==='magento' ? [
        'store'=>'store_id INT PRIMARY KEY,website_id INT,is_active INT','store_website'=>'website_id INT PRIMARY KEY',
        'customer_entity'=>'entity_id INT PRIMARY KEY,website_id INT,email VARCHAR(254),is_active INT,confirmation VARCHAR(32)',
        'sales_order'=>'entity_id INT PRIMARY KEY,customer_id INT,store_id INT,customer_is_guest INT'] : [
        'users'=>'ID INT PRIMARY KEY,user_email VARCHAR(254),user_status INT,spam INT,deleted INT',
        'usermeta'=>'umeta_id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,meta_key VARCHAR(64)',
        'options'=>'option_name VARCHAR(64) PRIMARY KEY,option_value VARCHAR(32)',
        'posts'=>'ID INT PRIMARY KEY,post_type VARCHAR(32)',
        'postmeta'=>'meta_id INT AUTO_INCREMENT PRIMARY KEY,post_id INT,meta_key VARCHAR(64),meta_value VARCHAR(64)',
        'wc_orders'=>'id INT PRIMARY KEY,customer_id INT,type VARCHAR(32)']);
    foreach($schemas as $table=>$schema)$db->exec('CREATE TABLE ix_'.$table.' ('.$schema.') ENGINE=InnoDB');
    if(PLATFORM==='prestashop')$db->exec('INSERT INTO ix_shop VALUES(1,1,1,0),(2,2,1,0)');
    if(PLATFORM==='magento'){$db->exec('INSERT INTO ix_store VALUES(1,1,1),(2,2,1)');$db->exec('INSERT INTO ix_store_website VALUES(1),(2)');}
    if(PLATFORM==='woocommerce')$db->exec("INSERT INTO ix_options VALUES('woocommerce_custom_orders_table_enabled','no')");
    for($id=1;$id<=12;$id++) {
        $scope=$id===12?2:1;
        if(PLATFORM==='prestashop') {
            $db->exec("INSERT INTO ix_customer VALUES($id,$scope,$scope,'private-$id@example.invalid',1,0,0)");
            $db->exec("INSERT INTO ix_orders VALUES($id,$id,$scope)");
        } elseif(PLATFORM==='magento') {
            $db->exec("INSERT INTO ix_customer_entity VALUES($id,$scope,'private-$id@example.invalid',1,NULL)");
            $db->exec("INSERT INTO ix_sales_order VALUES($id,$id,$scope,0)");
        } else {
            $db->exec("INSERT INTO ix_users VALUES($id,'private-$id@example.invalid',0,0,0)");
            $db->exec("INSERT INTO ix_usermeta(user_id,meta_key) VALUES($id,'".($scope===1?'ix_':'foreign_')."capabilities')");
            $db->exec("INSERT INTO ix_posts VALUES($id,'shop_order')");
            $db->exec("INSERT INTO ix_postmeta(post_id,meta_key,meta_value) VALUES($id,'_customer_user','$id')");
            $db->exec("INSERT INTO ix_wc_orders VALUES($id,$id,'shop_order')");
        }
    }
    $input=['schema'=>1,'shopId'=>'fixture','operation'=>'customer_history_index','afterId'=>'0','ceilingId'=>null,'limit'=>8];
    $read=static function($input)use($db){return Index::read($db,PLATFORM,'ix_',1,$input,'ix_users','ix_usermeta',true);};
    $page=$read($input);checkIndex(count($page['customers'])===8&&!$page['complete']&&$page['ceiling_id']==='11'&&$page['next_id']==='8');
    checkIndex(!$db->inTransaction());
    $next=$read(array_replace($input,['afterId'=>'8','ceilingId'=>'11']));
    checkIndex(count($next['customers'])===3&&$next['complete']&&$next['next_id']==='11');
    $empty=$read(array_replace($input,['afterId'=>'11','ceilingId'=>'11']));checkIndex($empty['complete']&&$empty['customers']===[]);
    // A fixed ceiling excludes arrivals until the next weekly pass.
    $bounded=$read(array_replace($input,['ceilingId'=>'5']));checkIndex(count($bounded['customers'])===5&&$bounded['complete']);
    if(PLATFORM==='woocommerce') {
        $db->exec("UPDATE ix_options SET option_value='yes'");checkIndex($read($input)['customers']===$page['customers']);
        $db->exec("DELETE FROM ix_wc_orders WHERE customer_id=1");checkIndex($read($input)['customers'][0]['customerId']==='2');
    }
    $db->beginTransaction();$refused=false;try{$read($input);}catch(Throwable $e){$refused=true;}
    checkIndex($refused&&$db->inTransaction());$db->rollBack();
    $table=PLATFORM==='prestashop'?'orders':(PLATFORM==='magento'?'sales_order':'wc_orders');
    $db->exec('ALTER TABLE ix_'.$table.' ENGINE=MyISAM');$refused=false;try{$read($input);}catch(Throwable $e){$refused=true;}
    checkIndex($refused&&!$db->inTransaction());
    echo PLATFORM." native history index: $checks assertions passed\n";
} finally {$admin->exec('DROP DATABASE `'.$name.'`');}
