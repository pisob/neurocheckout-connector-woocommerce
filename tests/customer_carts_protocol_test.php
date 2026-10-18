<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { exit(1); }
$base=__DIR__.'/../neurocheckout-connector/includes/community/';
foreach (['CustomerCartsProtocol','SourcePullProtocol','SourcePullGateway'] as $name) { require_once $base.$name.'.php'; }
use NeuroCheckout\WooCommerce\Community\CustomerCartsProtocol;
use NeuroCheckout\WooCommerce\Community\SourcePullProtocol;
use NeuroCheckout\WooCommerce\Community\SourcePullGateway;
$checks=0;
function checkCart(bool $ok):void {global $checks;++$checks;if(!$ok)throw new RuntimeException('cart protocol assertion failed');}
function refuseCart(callable $fn):void {
    try{$fn();}catch(Throwable $e){checkCart(in_array($e->getMessage(),['customer_carts_unavailable','customer_history_unavailable'],true));return;}
    throw new RuntimeException('unexpected cart acceptance');
}
$identity=['blogId'=>'2','customerId'=>'7','customerEmail'=>'private@example.invalid'];
$input=['schema'=>1,'shopId'=>'synthetic-shop','operation'=>'customer_carts']+$identity;
$carts=CustomerCartsProtocol::result($identity,false,'granted');
CustomerCartsProtocol::request($input,'synthetic-shop');CustomerCartsProtocol::facts($carts,$identity);checkCart(true);
foreach (['schema'=>true,'shopId'=>'foreign','operation'=>'customer_history','blogId'=>'02','customerId'=>7,'customerEmail'=>"private@example.invalid\n",'url'=>'https://foreign.invalid'] as $key=>$value) {
    $bad=array_replace($input,[$key=>$value]);refuseCart(static function()use($bad){CustomerCartsProtocol::request($bad,'synthetic-shop');});
}
foreach (['schema'=>'foreign','complete'=>false,'has_active_cart'=>0,'marketing_consent'=>'yes','read_at'=>'2000-01-01T00:00:00Z','private'=>'PRIVATE'] as $key=>$value) {
    $bad=array_replace($carts,[$key=>$value]);refuseCart(static function()use($bad,$identity){CustomerCartsProtocol::facts($bad,$identity);});
}
foreach (['customerId'=>'8','customerEmail'=>'foreign@example.invalid','blogId'=>'3','url'=>'foreign'] as $key=>$value) {
    $bad=$carts;$bad['identity'][$key]=$value;refuseCart(static function()use($bad,$identity){CustomerCartsProtocol::facts($bad,$identity);});
}
foreach ([false,true] as $active) foreach (['granted','denied','unknown'] as $consent) {
    $v=CustomerCartsProtocol::result($identity,$active,$consent);checkCart($v['has_active_cart']===$active&&$v['marketing_consent']===$consent);
}
$directory=sys_get_temp_dir().'/nc-carts-protocol-'.bin2hex(random_bytes(10));mkdir($directory,0700);
$config=['enabled'=>true,'environment'=>'production','nativeScope'=>2,'platform'=>'woocommerce','secret'=>bin2hex(random_bytes(32)),'shopId'=>'synthetic-shop'];
$path='/wp-json/neurocheckout/v1/communitydata';$calls=0;
$sign=static function($raw)use($config,$path):array {
    $time=(string)(int)floor(microtime(true)*1000);$nonce=bin2hex(random_bytes(16));
    return ['content-type'=>'application/json','x-nc-source-time'=>$time,'x-nc-source-nonce'=>$nonce,
        'x-nc-source-signature'=>hash_hmac('sha256',implode("\n",['nc-source-pull-v1','POST',$path,$config['shopId'],$time,$nonce,hash('sha256',$raw)]),hex2bin($config['secret']))];
};
$export=static function($v)use(&$calls,$carts):array {
    ++$calls;return ['schema'=>1,'shopId'=>$v['shopId'],'operation'=>'customer_carts','requestHash'=>hash('sha256',json_encode($v)),'carts'=>$carts];
};
$invoke=static function($v,$headers=null,$callback=null,$https=true)use($config,$directory,$path,$sign,$export):array {
    $raw=json_encode($v);
    return SourcePullGateway::handle('woocommerce',2,__DIR__,'POST',$path,$headers??$sign($raw),$raw,$https,$callback??$export,$config,$directory);
};
try {
    $headers=$sign(json_encode($input));$response=$invoke($input,$headers);
    checkCart($response[0]===200&&$calls===1);
    checkCart($response[1]['X-NC-Source-Response']===SourcePullProtocol::responseSignature($config['secret'],$headers['x-nc-source-nonce'],$response[2]));
    checkCart($response[1]['Cache-Control']==='no-store, private');
    checkCart($invoke($input,$headers)[0]===409&&$calls===1);
    checkCart($invoke(array_replace($input,['customerId'=>'8']),$headers)[0]===401&&$calls===1);
    checkCart($invoke(array_replace($input,['blogId'=>'3']))[0]===403&&$calls===1);
    checkCart($invoke(array_replace($input,['shopId'=>'foreign']))[0]===400&&$calls===1);
    checkCart($invoke($input,null,null,false)[0]===403&&$calls===1);
    foreach (['requestHash','operation','identity','has_active_cart','complete'] as $change) {
        $bad=static function($v)use($export,$change):array {
            $page=$export($v);
            if($change==='requestHash')$page['requestHash']=str_repeat('e',64);
            elseif($change==='operation')$page['operation']='customer_history';
            elseif($change==='identity')$page['carts']['identity']['customerId']='8';
            elseif($change==='has_active_cart')$page['carts']['has_active_cart']='false';
            else $page['carts']['complete']=false;
            return $page;
        };
        $response=$invoke($input,null,$bad);checkCart($response[0]===503&&strpos($response[2],'PRIVATE')===false);
    }
    echo "woocommerce cart protocol: $checks assertions passed\n";
} finally { foreach(glob($directory.'/*')?:[] as $file){unlink($file);}rmdir($directory); }

