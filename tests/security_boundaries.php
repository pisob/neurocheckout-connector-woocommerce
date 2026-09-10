<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', '/synthetic-only/');
require __DIR__ . '/../neurocheckout-connector/includes/class-ncwoo-security.php';
$r = new ReflectionClass('NCWooSecurity');
$skew = $r->getConstant('MAX_TIME_DRIFT');
$ttl = $r->getConstant('NONCE_TTL');
if ($ttl < 2 * $skew + 1) { throw new RuntimeException('Nonce retention does not cover full timestamp window'); }
require __DIR__ . '/../neurocheckout-connector/includes/community/SourcePullProtocol.php';
$class = 'NeuroCheckout\\WooCommerce\\Community\\SourcePullProtocol';
$secret = bin2hex(random_bytes(32));
$time = 1800000000000;
$path = '/wp-json/neurocheckout/v1/communitydata';
$raw = '{"schema":1,"shopId":"synthetic-shop","streamId":null,"cursor":"","limit":8}';
$nonce = bin2hex(random_bytes(16));
$headers = ['content-type'=>'application/json','x-nc-source-time'=>(string)$time,'x-nc-source-nonce'=>$nonce];
$headers['x-nc-source-signature'] = hash_hmac('sha256', implode("\n", ['nc-source-pull-v1','POST',$path,'synthetic-shop',(string)$time,$nonce,hash('sha256',$raw)]),hex2bin($secret));
$used = [];
$consume = static function ($n,$t) use (&$used): bool {
    if ($t < 240) { throw new RuntimeException('Nonce TTL too short'); }
    if(isset($used[$n])) return false;
    $used[$n] = true; return true;
};
$invoke = static function ($enabled=true,$env='staging',$body=null,$now=null) use ($class,$path,$headers,$raw,$secret,$time,$consume) {
    return $class::authenticate('POST',$path,$headers,$body ?? $raw,'synthetic-shop',$secret,$now ?? $time,$consume,$enabled,$env);
};
foreach ([[false,'staging',null,null],[true,'production',null,null],[true,'staging',$raw.'x',null],[true,'staging',null,$time+120001]] as $args) {
    $rejected = false;
    try { $invoke(...$args); } catch (RuntimeException $e) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Unsafe request accepted');
}
if ($invoke()['shopId'] !== 'synthetic-shop') throw new RuntimeException('Valid request rejected');
$rejected = false;
try { $invoke(); } catch (RuntimeException $e) { $rejected = true; }
if (!$rejected) throw new RuntimeException('Replay accepted');
echo "Nonce constants, signed source request, replay, tampering, expiry and disabled modes checked.\n";
