<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { exit(1); }
$base=__DIR__.'/../neurocheckout-connector/includes/community/';
require_once $base.'BoundedPhpValueReader.php';require_once $base.'WooCustomerOrderHistory.php';
use NeuroCheckout\WooCommerce\Community\WooCustomerOrderHistory;
$socket=$argv[1]??'';
if (!preg_match('#^/tmp/nc-source-mariadb\.[A-Za-z0-9]+/mariadb\.sock$#D',$socket)||!file_exists($socket)) { exit(2); }
$checks=0;
function checkCart(bool $ok):void { global $checks;++$checks;if(!$ok)throw new RuntimeException('cart assertion failed'); }
function refuseCart(callable $fn):void {
    try{$fn();}catch(Throwable $e){checkCart($e->getMessage()==='customer_history_unavailable');return;}
    throw new RuntimeException('unexpected cart acceptance');
}
class CartTestPDO extends PDO {
    public $onCart=null;
    #[\ReturnTypeWillChange]
    public function prepare($query,$options=[]) {
        if ($this->onCart!==null && strpos($query,'SELECT LEFT(meta_value,65537) value FROM')===0) {
            $fn=$this->onCart;$this->onCart=null;$fn($this);
        }
        return parent::prepare($query,$options);
    }
}
$dsn='mysql:unix_socket='.$socket.';charset=utf8mb4';$database='nc_woo_carts_fixture_'.bin2hex(random_bytes(6));
$admin=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$admin->exec('CREATE DATABASE `'.$database.'`');
try {
    $writer=new PDO($dsn.';dbname='.$database,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    foreach ([
        'users'=>'ID INT PRIMARY KEY,user_email VARCHAR(254),user_registered DATETIME,user_status INT,spam INT DEFAULT 0,deleted INT DEFAULT 0',
        'usermeta'=>'umeta_id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,meta_key VARCHAR(255),meta_value LONGTEXT',
        '2_options'=>'option_name VARCHAR(191) UNIQUE,option_value LONGTEXT',
        '2_woocommerce_sessions'=>'session_id INT AUTO_INCREMENT PRIMARY KEY,session_key VARCHAR(32),session_value LONGTEXT,session_expiry BIGINT',
    ] as $name=>$schema) {$writer->exec('CREATE TABLE wp_'.$name.' ('.$schema.') ENGINE=InnoDB');}
    $writer->exec("INSERT INTO wp_users VALUES(7,'private@example.invalid','2020-01-01',0,0,0),(8,'other@example.invalid','2020-01-01',0,0,0)");
    $put=$writer->prepare('INSERT INTO wp_usermeta(user_id,meta_key,meta_value) VALUES(?,?,?)');
    $put->execute([7,'wp_2_capabilities',serialize(['customer'=>true])]);
    $consent=['version'=>'product-recommendations-v1','blog_id'=>2,'customer_id'=>7,'email'=>'private@example.invalid','state'=>'granted','changed_at'=>time()];
    $put->execute([7,'_ncwoo_marketing_consent_2',serialize($consent)]);
    $db=new CartTestPDO($dsn.';dbname='.$database,'root','');$reader=new WooCustomerOrderHistory($db,'wp_2_','wp_users','wp_usermeta',2,true);
    $read=static function()use($reader){return $reader->read(2,7,'private@example.invalid',true);};
    $line=['product_id'=>10,'variation_id'=>0,'quantity'=>1];$cart=['cart'=>['key'=>$line]];
    $putSession=$writer->prepare('INSERT INTO wp_2_woocommerce_sessions(session_key,session_value,session_expiry) VALUES(?,?,?)');
    checkCart($read()['has_active_cart']===false && !$db->inTransaction());
    $put->execute([8,'_woocommerce_persistent_cart_2',serialize($cart)]);
    $put->execute([7,'_woocommerce_persistent_cart_3',serialize($cart)]);
    $putSession->execute(['8',serialize($cart),time()+3600]);checkCart($read()['has_active_cart']===false);
    $put->execute([7,'_woocommerce_persistent_cart_2',serialize($cart)]);checkCart($read()['has_active_cart']===true);
    $writer->exec("DELETE FROM wp_usermeta WHERE user_id=7 AND meta_key='_woocommerce_persistent_cart_2'");
    $putSession->execute(['7',serialize(['cart'=>serialize($cart['cart'])]),time()+3600]);checkCart($read()['has_active_cart']===true);
    $writer->exec("UPDATE wp_2_woocommerce_sessions SET session_expiry=1 WHERE session_key='7'");checkCart($read()['has_active_cart']===false);
    $put->execute([7,'_woocommerce_persistent_cart_2',serialize($cart)]);checkCart($read()['has_active_cart']===true);
    $update=$writer->prepare("UPDATE wp_usermeta SET meta_value=? WHERE user_id=7 AND meta_key='_woocommerce_persistent_cart_2'");
    $update->execute([serialize(['cart'=>[]])]);checkCart($read()['has_active_cart']===false);
    foreach (['O:8:"stdClass":0:{}','a:1:{s:4:"cart";R:1;}','a:0:{}','private malformed',str_repeat('x',65537),
        serialize(['cart'=>[['quantity'=>null]]]),serialize(['cart'=>[['quantity'=>-1]]]),serialize(['cart'=>[['quantity'=>true]]]),
        serialize(['cart'=>array_fill(0,129,$line)])] as $bad) {$update->execute([$bad]);refuseCart($read);}
    $update->execute([serialize(['cart'=>[['quantity'=>0]]])]);checkCart($read()['has_active_cart']===false);
    $put->execute([7,'_woocommerce_persistent_cart_2',serialize(['cart'=>[]])]);refuseCart($read);
    $writer->exec("DELETE FROM wp_usermeta WHERE user_id=7 AND meta_key='_woocommerce_persistent_cart_2'");
    $writer->exec("UPDATE wp_2_woocommerce_sessions SET session_value='bad',session_expiry=UNIX_TIMESTAMP()+3600 WHERE session_key='7'");refuseCart($read);
    $updateSession=$writer->prepare("UPDATE wp_2_woocommerce_sessions SET session_value=? WHERE session_key='7'");
    foreach ([null,false,1,'bad'] as $bad) {
        $updateSession->execute([serialize(['cart'=>$bad])]);refuseCart($read);
    }
    foreach ([[],['cart'=>[]]] as $empty) {
        $updateSession->execute([serialize($empty)]);checkCart($read()['has_active_cart']===false);
    }
    $put->execute([7,'_woocommerce_persistent_cart_2',serialize($cart)]);
    $updateSession->execute([serialize(['cart'=>null])]);refuseCart($read);
    $writer->exec("DELETE FROM wp_usermeta WHERE user_id=7 AND meta_key='_woocommerce_persistent_cart_2'");
    $writer->exec("DELETE FROM wp_2_woocommerce_sessions WHERE session_key='7'");
    foreach (['user_status=1','spam=1','deleted=1',"user_email='foreign@example.invalid'"] as $change) {
        $writer->exec('UPDATE wp_users SET '.$change.' WHERE ID=7');refuseCart($read);
        $writer->exec("UPDATE wp_users SET user_status=0,spam=0,deleted=0,user_email='private@example.invalid' WHERE ID=7");
    }
    $writer->exec("DELETE FROM wp_usermeta WHERE meta_key='wp_2_capabilities'");refuseCart($read);
    $put->execute([7,'wp_2_capabilities',serialize(['customer'=>true])]);
    foreach (['granted','denied'] as $state) {
        $consent['state']=$state;$writer->exec("DELETE FROM wp_usermeta WHERE meta_key='_ncwoo_marketing_consent_2'");
        $put->execute([7,'_ncwoo_marketing_consent_2',serialize($consent)]);checkCart($read()['marketing_consent']===$state);
    }
    $writer->exec("DELETE FROM wp_usermeta WHERE meta_key='_ncwoo_marketing_consent_2'");checkCart($read()['marketing_consent']==='unknown');
    $db->beginTransaction();refuseCart($read);checkCart($db->inTransaction());$db->rollBack();
    $db->onCart=static function($connection) {try{$connection->exec('UPDATE wp_users SET user_status=1');}catch(PDOException $e){checkCart(true);return;}throw new RuntimeException('not read-only');};
    $read();checkCart(!$db->inTransaction());
    $db->onCart=static function()use($put,$cart){$put->execute([7,'_woocommerce_persistent_cart_2',serialize($cart)]);};
    checkCart($read()['has_active_cart']===false);checkCart($read()['has_active_cart']===true);
    $writer->exec('ALTER TABLE wp_2_woocommerce_sessions ENGINE=MyISAM');refuseCart($read);checkCart(!$db->inTransaction());
    $writer->exec('ALTER TABLE wp_2_woocommerce_sessions ENGINE=InnoDB');
    $writer->exec('DROP TABLE wp_2_woocommerce_sessions');refuseCart($read);
    echo "WooCommerce active carts: $checks assertions passed\n";
} finally { $admin->exec('DROP DATABASE `'.$database.'`'); }
