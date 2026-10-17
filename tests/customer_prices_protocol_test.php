<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
use NeuroCheckout\WooCommerce\Community\WooCustomerPriceProtocol as Price;
use NeuroCheckout\WooCommerce\Community\SourcePullProtocol as Protocol;
use NeuroCheckout\WooCommerce\Community\SourcePullGateway as Gateway;
require_once __DIR__.'/../neurocheckout-connector/includes/community/SourcePullProtocol.php';
require_once __DIR__.'/../neurocheckout-connector/includes/community/SourcePullGateway.php';
$checks=0;
function priceCheck($v,$label){global $checks;++$checks;if(!$v)throw new RuntimeException($label);}
function priceReject($fn){try{$fn();}catch(Throwable $e){priceCheck($e->getMessage()==='customer_prices_unavailable','constant error');return;}throw new RuntimeException('unexpected success');}
$input=['schema'=>1,'shopId'=>'synthetic-shop','operation'=>'customer_prices','blogId'=>'1','customerId'=>'47',
 'customerEmail'=>'recipient@example.invalid','productIds'=>['13','14'],'currency'=>'EUR'];
$prices=['schema'=>'woocommerce-customer-prices-v1','blog_id'=>1,'customer_id'=>47,'customer_email'=>'recipient@example.invalid',
 'currency'=>'EUR','quantity'=>1,'price_decimals'=>2,'context_hash'=>str_repeat('a',64),'read_at'=>gmdate('Y-m-d\TH:i:s\Z'),
 'offers'=>[['product_id'=>'13','status'=>'available','currency'=>'EUR','price_excl_tax'=>10.1234,'price_incl_tax'=>12.15,
 'display_mode'=>'excluding_tax','display_price'=>10.12],['product_id'=>'14','status'=>'unavailable']]];
Price::request($input,'synthetic-shop');Price::prices($prices,$input);priceCheck(true,'native rounded offer');
foreach(['blogId'=>'01','customerId'=>47,'currency'=>'usd','customerEmail'=>'bad','productIds'=>['13','13'],'extra'=>1] as $k=>$v){
 $bad=array_replace($input,[$k=>$v]);priceReject(static function()use($bad){Price::request($bad,'synthetic-shop');});
}
foreach(['schema'=>'magento-customer-prices-v1','blog_id'=>2,'customer_id'=>'47','customer_email'=>'other@example.invalid',
 'currency'=>'USD','quantity'=>true,'price_decimals'=>7,'context_hash'=>'bad','read_at'=>'2000-01-01T00:00:00Z','offers'=>[],'address'=>'private'] as $k=>$v){
 $bad=array_replace($prices,[$k=>$v]);priceReject(static function()use($bad,$input){Price::prices($bad,$input);});
}
foreach(['product_id'=>'14','status'=>'okay','price_excl_tax'=>true,'price_incl_tax'=>INF,'display_mode'=>'both','display_price'=>10.13,'currency'=>'USD'] as $k=>$v){
 $bad=$prices;$bad['offers'][0][$k]=$v;priceReject(static function()use($bad,$input){Price::prices($bad,$input);});
}
$directory=sys_get_temp_dir().'/nc-woo-price-protocol-'.bin2hex(random_bytes(8));mkdir($directory,0700);
$config=['enabled'=>true,'environment'=>'production','nativeScope'=>1,'platform'=>'woocommerce','shopId'=>'synthetic-shop','secret'=>bin2hex(random_bytes(32))];
$path='/wp-json/neurocheckout/v1/communitydata';$calls=0;
$sign=static function($raw)use($path,$config){$t=(string)(int)floor(microtime(true)*1000);$n=bin2hex(random_bytes(16));return ['content-type'=>'application/json',
 'x-nc-source-time'=>$t,'x-nc-source-nonce'=>$n,'x-nc-source-signature'=>hash_hmac('sha256',implode("\n",['nc-source-pull-v1','POST',$path,$config['shopId'],$t,$n,hash('sha256',$raw)]),hex2bin($config['secret']))];};
$export=static function($v)use(&$calls,$prices){++$calls;return ['schema'=>1,'shopId'=>$v['shopId'],'operation'=>'customer_prices','requestHash'=>hash('sha256',json_encode($v)),'prices'=>$prices];};
$call=static function($v,$headers=null,$reader=null,$tls=true)use($path,$config,$directory,$sign,$export){$raw=json_encode($v);return Gateway::handle('woocommerce',1,__DIR__,'POST',$path,$headers??$sign($raw),$raw,$tls,$reader??$export,$config,$directory);};
try{
 $headers=$sign(json_encode($input));$r=$call($input,$headers);
 priceCheck($r[0]===200&&$calls===1,'signed scoped prices');
 priceCheck($r[1]['X-NC-Source-Response']===Protocol::responseSignature($config['secret'],$headers['x-nc-source-nonce'],$r[2]),'exact body signed');
 priceCheck($r[1]['Cache-Control']==='no-store, private','no public cache');
 priceCheck($call($input,$headers)[0]===409&&$calls===1,'replay refused before reader');
 priceCheck($call(array_replace($input,['customerId'=>'48']),$headers)[0]===401&&$calls===1,'tampered identity');
 priceCheck($call(array_replace($input,['blogId'=>'2']))[0]===403&&$calls===1,'foreign native shop');
 priceCheck($call(array_replace($input,['shopId'=>'foreign']))[0]===400&&$calls===1,'foreign external shop');
 priceCheck($call($input,null,null,false)[0]===403&&$calls===1,'TLS required');
 foreach(['cookie'=>'x','origin'=>'https://example.invalid','content-encoding'=>'gzip'] as $k=>$v){$bad=$sign(json_encode($input));$bad[$k]=$v;priceCheck($call($input,$bad)[0]===400&&$calls===1,'no browser caller');}
 $bad=static function($v)use($export){$r=$export($v);$r['requestHash']=str_repeat('0',64);return $r;};
 $r=$call($input,null,$bad);priceCheck($r[0]===503&&!isset($r[1]['X-NC-Source-Response']),'invalid reply not signed');
 $boom=static function(){throw new RuntimeException('PRIVATE native details');};priceCheck($call($input,null,$boom)[2]==='{"error":"source_unavailable"}','redacted runtime failure');
}finally{foreach(scandir($directory) as $name)if($name!=='.'&&$name!=='..'){unlink($directory.'/'.$name);}rmdir($directory);}
echo "Woo price protocol: $checks checks passed.\n";
