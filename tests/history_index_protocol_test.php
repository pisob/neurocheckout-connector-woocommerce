<?php
declare(strict_types=1);
require_once __DIR__.'/../neurocheckout-connector/includes/community/CustomerHistoryIndex.php';
require_once __DIR__.'/../neurocheckout-connector/includes/community/SourcePullProtocol.php';
require_once __DIR__.'/../neurocheckout-connector/includes/community/SourcePullGateway.php';
use NeuroCheckout\WooCommerce\Community\CustomerHistoryIndex as Index;
use NeuroCheckout\WooCommerce\Community\SourcePullProtocol as Protocol;
use NeuroCheckout\WooCommerce\Community\SourcePullGateway as Gateway;
const PLATFORM='woocommerce';
const ROUTE='/wp-json/neurocheckout/v1/communitydata';
$checks=0;
function checkIndex(bool $ok):void {global $checks;++$checks;if(!$ok)throw new RuntimeException('index assertion failed '.$checks);}
function refusedIndex(callable $fn):void {try{$fn();}catch(Throwable $e){checkIndex(true);return;}throw new RuntimeException('unexpected index acceptance');}
$input=['schema'=>1,'shopId'=>'fixture','operation'=>'customer_history_index','afterId'=>'0','ceilingId'=>null,'limit'=>8];
Index::request($input,'fixture');
foreach(['afterId'=>'-1','ceilingId'=>7,'limit'=>9,'shopId'=>'foreign','extra'=>true,'schema'=>true] as $k=>$v) {
    refusedIndex(static function()use($input,$k,$v){Index::request(array_replace($input,[$k=>$v]),'fixture');});
}
$scope=PLATFORM==='magento'?['storeId'=>'1','websiteId'=>'1']:(PLATFORM==='woocommerce'?['blogId'=>'1']:['nativeShopId'=>'1']);
$index=['read_at'=>gmdate('Y-m-d\TH:i:s\Z'),'after_id'=>'0','ceiling_id'=>'1','next_id'=>'1','complete'=>true,
    'customers'=>[$scope+['customerId'=>'1','customerEmail'=>'private@example.invalid']]];
$page=['schema'=>1,'shopId'=>'fixture','operation'=>'customer_history_index','requestHash'=>hash('sha256',json_encode($input)),'index'=>$index];
Index::response($page,$input,json_encode($input),PLATFORM,1);
foreach(['next_id'=>'0','ceiling_id'=>'0','complete'=>false,'read_at'=>'2000-01-01T00:00:00Z','customers'=>array_merge($index['customers'],$index['customers'])] as $k=>$v) {
    refusedIndex(static function()use($page,$input,$k,$v){$p=$page;$p['index'][$k]=$v;Index::response($p,$input,json_encode($input),PLATFORM,1);});
}
$dir=sys_get_temp_dir().'/nc-index-protocol-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$config=['enabled'=>true,'environment'=>'production','nativeScope'=>1,'platform'=>PLATFORM,'secret'=>bin2hex(random_bytes(32)),'shopId'=>'fixture'];
$raw=json_encode($input);$time=(string)(int)floor(microtime(true)*1000);$nonce=bin2hex(random_bytes(16));
$headers=['content-type'=>'application/json','x-nc-source-time'=>$time,'x-nc-source-nonce'=>$nonce,
    'x-nc-source-signature'=>hash_hmac('sha256',implode("\n",['nc-source-pull-v1','POST',ROUTE,'fixture',$time,$nonce,hash('sha256',$raw)]),hex2bin($config['secret']))];
try {
    $export=static function()use($page){return $page;};
    $result=Gateway::handle(PLATFORM,1,__DIR__,'POST',ROUTE,$headers,$raw,true,$export,$config,$dir);
    checkIndex($result[0]===200);
    checkIndex($result[1]['X-NC-Source-Response']===Protocol::responseSignature($config['secret'],$nonce,$result[2]));
    checkIndex(Gateway::handle(PLATFORM,1,__DIR__,'POST',ROUTE,$headers,$raw,true,$export,$config,$dir)[0]===409);
    checkIndex(Gateway::handle(PLATFORM,1,__DIR__,'POST',ROUTE,$headers,$raw.' ',true,$export,$config,$dir)[0]===401);
} finally {foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
echo PLATFORM." index protocol: $checks assertions passed\n";
