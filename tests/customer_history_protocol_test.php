<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
use NeuroCheckout\WooCommerce\Community\SourcePullProtocol;
use NeuroCheckout\WooCommerce\Community\SourcePullGateway;
use NeuroCheckout\WooCommerce\Community\WooCustomerHistoryProtocol;
require_once __DIR__.'/../neurocheckout-connector/includes/community/SourcePullProtocol.php';
require_once __DIR__.'/../neurocheckout-connector/includes/community/SourcePullGateway.php';
set_error_handler(static function ($severity,$message) { throw new RuntimeException($message); });
$checks=0;
function historyCheck(bool $ok,string $label): void {
    global $checks; ++$checks; if (!$ok) { throw new RuntimeException($label); }
}
function historyReject(callable $run,string $label): void {
    try { $run(); } catch (Throwable $e) {
        historyCheck($e->getMessage()==='customer_history_unavailable',$label.' constant error'); return;
    }
    throw new RuntimeException($label.' unexpectedly accepted');
}
$input=['schema'=>1,'shopId'=>'synthetic-shop','operation'=>'customer_history','blogId'=>'2',
    'customerId'=>'47','customerEmail'=>'reader@example.invalid'];
$line=['item_id'=>'41','product_id'=>'10','variation_id'=>'11','quantity'=>'2.00000000',
    'line_total'=>'40.00000000','line_tax'=>'8.00000000','refunded_item_id'=>null];
$refundLine=array_replace($line,['item_id'=>'42','quantity'=>'-1','line_total'=>'-20','line_tax'=>'-4','refunded_item_id'=>'41']);
$order=['order_id'=>'31','status'=>'wc-on-hold','created_at'=>'2020-01-01 00:00:00','updated_at'=>'2020-01-02 00:00:00',
    'currency'=>'EUR','total'=>'48.00000000','date_paid_gmt'=>null,'items'=>[$line],
    'refunds'=>[['refund_id'=>'32','created_at'=>'2020-01-02 00:00:00','amount'=>'24','payment_refunded'=>null,'items'=>[$refundLine]]]];
$history=['schema'=>'woocommerce-customer-history-v1','blog_id'=>2,'customer_id'=>47,'customer_email'=>'Reader@example.invalid',
    'customer_created_at'=>'2019-12-01 00:00:00','read_at'=>gmdate('Y-m-d\TH:i:s\Z'),'complete'=>true,
    'order_storage'=>'posts','order_count'=>1,'orders'=>[$order],'marketing_consent'=>'unknown'];
WooCustomerHistoryProtocol::request($input,'synthetic-shop');
WooCustomerHistoryProtocol::history($history,$input);
historyCheck(true,'complete native facts accepted without paid inference');
$v2=$history;$v2['schema']='woocommerce-customer-history-v2';
foreach([null,'0','7.12500000'] as $discount){$v2['orders'][0]['discount_total']=$discount;WooCustomerHistoryProtocol::history($v2,$input);historyCheck(true,'v2 discount preserved');}
foreach([true,2,'-1','1e2',str_repeat('9',27)] as $discount){$bad=$v2;$bad['orders'][0]['discount_total']=$discount;historyReject(static function()use($bad,$input){WooCustomerHistoryProtocol::history($bad,$input);},'invalid v2 discount');}
$bad=$v2;unset($bad['orders'][0]['discount_total']);historyReject(static function()use($bad,$input){WooCustomerHistoryProtocol::history($bad,$input);},'missing v2 discount');
foreach (['schema'=>2,'shopId'=>'foreign-shop','operation'=>'other','blogId'=>'02','customerId'=>47,
    'customerEmail'=>'bad\nemail@example.invalid','extra'=>true] as $key=>$value) {
    $bad=array_replace($input,[$key=>$value]);
    historyReject(static function() use ($bad) { WooCustomerHistoryProtocol::request($bad,'synthetic-shop'); },'request '.$key);
}
foreach (['schema'=>'magento-customer-history-v1','blog_id'=>3,'customer_id'=>48,'customer_email'=>'foreign@example.invalid',
    'customer_created_at'=>'2020-02-30 00:00:00','read_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-60),'complete'=>false,
    'order_storage'=>'custom','order_count'=>0,'marketing_consent'=>true,'extra'=>'private'] as $key=>$value) {
    $bad=array_replace($history,[$key=>$value]);
    historyReject(static function() use ($bad,$input) { WooCustomerHistoryProtocol::history($bad,$input); },'history '.$key);
}
foreach (['order_id'=>'0','status'=>'','created_at'=>'0000-00-00 00:00:00','updated_at'=>'2020-02-30 00:00:00',
    'currency'=>'euro','total'=>48.0,'date_paid_gmt'=>'9999-01-01 00:00:00','extra'=>1] as $key=>$value) {
    $bad=$history; $bad['orders'][0][$key]=$value;
    historyReject(static function() use ($bad,$input) { WooCustomerHistoryProtocol::history($bad,$input); },'order '.$key);
}
foreach (['refund_id'=>'31','created_at'=>'invalid','amount'=>'-24','payment_refunded'=>'yes','extra'=>1] as $key=>$value) {
    $bad=$history; $bad['orders'][0]['refunds'][0][$key]=$value;
    historyReject(static function() use ($bad,$input) { WooCustomerHistoryProtocol::history($bad,$input); },'refund '.$key);
}
foreach (['item_id'=>'41','product_id'=>'99','variation_id'=>'0','quantity'=>'1','line_total'=>'20',
    'line_tax'=>'4','refunded_item_id'=>'999','extra'=>1] as $key=>$value) {
    $bad=$history; $bad['orders'][0]['refunds'][0]['items'][0][$key]=$value;
    historyReject(static function() use ($bad,$input) { WooCustomerHistoryProtocol::history($bad,$input); },'refund line '.$key);
}
foreach (['quantity'=>'-1','line_total'=>'1e2','line_tax'=>'NaN','refunded_item_id'=>'41'] as $key=>$value) {
    $bad=$history; $bad['orders'][0]['items'][0][$key]=$value;
    historyReject(static function() use ($bad,$input) { WooCustomerHistoryProtocol::history($bad,$input); },'purchase line '.$key);
}
$good=$history; $good['orders'][0]['items'][0]['product_id']='0';
$good['orders'][0]['refunds']=[]; WooCustomerHistoryProtocol::history($good,$input);
historyCheck(true,'deleted product stays native zero, not an invented product');
$empty=array_replace($history,['orders'=>[],'order_count'=>0]); WooCustomerHistoryProtocol::history($empty,$input);
historyCheck(true,'verified empty account history is distinct from failure');
$bad=$empty; $bad['orders']=array_fill(0,129,$order); $bad['order_count']=129;
historyReject(static function() use ($bad,$input) { WooCustomerHistoryProtocol::history($bad,$input); },'order capacity');
$bad=$history; $bad['orders'][0]['refunds']=array_fill(0,129,$order['refunds'][0]);
historyReject(static function() use ($bad,$input) { WooCustomerHistoryProtocol::history($bad,$input); },'refund capacity');
$bad=$history; $bad['orders'][0]['refunds']=[]; $bad['orders'][0]['items']=[];
for ($i=1;$i<=513;$i++) { $bad['orders'][0]['items'][]=array_replace($line,['item_id'=>(string)$i]); }
historyReject(static function() use ($bad,$input) { WooCustomerHistoryProtocol::history($bad,$input); },'item capacity');
$bad['orders'][0]['items']=array_slice($bad['orders'][0]['items'],0,512);
historyReject(static function() use ($bad,$input) { WooCustomerHistoryProtocol::history($bad,$input); },'byte capacity');

// Exercise the real signed gateway and its durable nonce/rate guard, without WP/SQL.
$directory=sys_get_temp_dir().'/nc-woo-history-protocol-'.bin2hex(random_bytes(8)); mkdir($directory,0700);
$config=['enabled'=>true,'environment'=>'production','nativeScope'=>2,'platform'=>'woocommerce',
    'secret'=>bin2hex(random_bytes(32)),'shopId'=>'synthetic-shop'];
$path='/shop/wp-json/neurocheckout/v1/communitydata'; $calls=0;
$sign=static function ($raw,$uri) use ($config): array {
    $time=(string)(int)floor(microtime(true)*1000); $nonce=bin2hex(random_bytes(16));
    return ['content-type'=>'application/json','x-nc-source-time'=>$time,'x-nc-source-nonce'=>$nonce,
        'x-nc-source-signature'=>hash_hmac('sha256',implode("\n",['nc-source-pull-v1','POST',$uri,$config['shopId'],$time,$nonce,hash('sha256',$raw)]),hex2bin($config['secret']))];
};
$export=static function($v) use (&$calls,$history): array {
    ++$calls; return ['schema'=>1,'shopId'=>$v['shopId'],'operation'=>'customer_history',
        'requestHash'=>hash('sha256',json_encode($v)), 'history'=>$history];
};
$invoke=static function($v,$headers=null,$callback=null,$uri=null,$https=true) use ($config,$directory,$path,$sign,$export): array {
    $raw=json_encode($v); $uri=$uri??$path;
    return SourcePullGateway::handle('woocommerce',2,__DIR__,'POST',$uri,$headers??$sign($raw,$uri),$raw,$https,
        $callback??$export,$config,$directory);
};
try {
    $raw=json_encode($input); $headers=$sign($raw,$path);
    $response=$invoke($input,$headers);
    historyCheck($response[0]===200 && $calls===1,'signed history accepted');
    historyCheck($response[1]['X-NC-Source-Response']===SourcePullProtocol::responseSignature($config['secret'],$headers['x-nc-source-nonce'],$response[2]),'exact response bytes signed');
    historyCheck($response[1]['Cache-Control']==='no-store, private','sensitive history not cacheable');
    historyCheck($invoke($input,$headers)[0]===409 && $calls===1,'replay refused before export');
    $tampered=$input; $tampered['customerId']='48';
    historyCheck($invoke($tampered,$headers)[0]===401 && $calls===1,'identity tampering refused before export');
    $foreign=$input; $foreign['blogId']='3';
    historyCheck($invoke($foreign)[0]===403 && $calls===1,'native scope cannot be selected by request');
    $foreign=$input; $foreign['shopId']='another-shop';
    historyCheck($invoke($foreign)[0]===400 && $calls===1,'external shop bound');
    historyCheck($invoke($input,null,null,null,false)[0]===403 && $calls===1,'TLS required');
    historyCheck($invoke($input,null,null,'/neurocheckout/community/pull')[0]===403 && $calls===1,'wrong platform refused');
    foreach (['cookie'=>'session=value','origin'=>'https://example.invalid','content-encoding'=>'gzip'] as $key=>$value) {
        $forbidden=$sign($raw,$path); $forbidden[$key]=$value;
        historyCheck($invoke($input,$forbidden)[0]===400 && $calls===1,'browser/encoding header rejected');
    }
    $wrong=static function($v) use ($export): array { $r=$export($v); $r['requestHash']=str_repeat('0',64); return $r; };
    $failed=$invoke($input,null,$wrong);
    historyCheck($failed[0]===503 && !isset($failed[1]['X-NC-Source-Response']) && strpos($failed[2],'reader@')===false,'invalid response never signed/leaked');
    $boom=static function() { throw new RuntimeException('PRIVATE connection/identity details'); };
    historyCheck($invoke($input,null,$boom)[2]==='{"error":"source_unavailable"}','internal errors are redacted');
    // Existing catalog/cart page protocol remains usable alongside the new operation.
    $page=['schema'=>1,'shopId'=>'synthetic-shop','streamId'=>null,'cursor'=>'','limit'=>8];
    $catalog=static function($v): array { return ['schema'=>1,'shopId'=>$v['shopId'],'streamId'=>str_repeat('a',32),
        'cursor'=>'','nextCursor'=>str_repeat('b',64),'complete'=>true,'generatedAt'=>gmdate('Y-m-d\TH:i:s\Z'),'records'=>[]]; };
    historyCheck($invoke($page,null,$catalog)[0]===200,'legacy page path unchanged');
} finally {
    foreach (glob($directory.'/*') as $file) { if (is_file($file)) { unlink($file); } }
    rmdir($directory);
}
echo "Woo customer-history protocol: $checks checks passed.\n";
