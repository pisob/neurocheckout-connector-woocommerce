<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
// Actual service, synthetic WP/DB collaborators. No network or customer data.
define('ABSPATH', '/synthetic/');
define('ARRAY_A', 'ARRAY_A');
class NCWooConfig {
    public bool $opaque = true;
    public function is_opaque_recovery_links_enabled() { return $this->opaque; }
    public function get_internal_secret() { return str_repeat('fixture-secret-', 3); }
}
class NCWooDB {}
class RecoveryRedirect extends RuntimeException {}
function home_url($path) { return 'https://fixture.example.invalid' . $path; }
function wc_get_cart_url() { return home_url('/cart/'); }
function wp_parse_url($url) { return parse_url($url); }
function wp_json_encode($data) { return json_encode($data); }
function wp_safe_redirect($url) { throw new RecoveryRedirect($url); }
function wp_set_auth_cookie($id, $remember) { $GLOBALS['effects'][] = 'login'; }
function wp_set_current_user($id) { $GLOBALS['effects'][] = 'user'; }
function get_user_by($field, $value) { return false; }
function WC() { return $GLOBALS['wc']; }
$wc = (object) ['session' => null, 'cart' => new class {
    public function empty_cart() { $GLOBALS['effects'][] = 'empty'; }
    public function add_to_cart(...$args) { $GLOBALS['effects'][] = 'add'; return 'item'; }
    public function apply_coupon($code) { $GLOBALS['effects'][] = 'coupon'; }
}];
$wpdb = new class {
    public string $prefix = 'wp_';
    public $insertResult = 1;
    public $claimResult = 1;
    public array $row = [];
    public function insert(...$args) { return $this->insertResult; }
    public function prepare($query, ...$args) {
        if (strpos($query, 'UPDATE') === 0) {
            check(strpos($query, 'used_at IS NULL') !== false, 'unused predicate');
            check(strpos($query, 'expires_at > %s') !== false, 'expiry predicate');
            check(strlen($args[1]) === 64 && $args[0] === $args[2], 'hashed token and consistent UTC boundary');
        }
        return $query;
    }
    public function get_row(...$args) { return strpos($args[0], 'ncwoo_coupon') !== false ? null : $this->row; }
    public function query($query) { $GLOBALS['effects'][] = 'claim'; return $this->claimResult; }
};
require __DIR__ . '/../neurocheckout-connector/includes/class-ncwoo-recovery.php';
$checks = 0;
function check(bool $ok, string $label): void {
    global $checks; ++$checks; if (!$ok) { throw new RuntimeException($label); }
}
$config = new NCWooConfig();
$service = new NCWooRecoveryService($config, new NCWooDB());
foreach ([false, 0] as $failure) {
    $wpdb->insertResult = $failure;
    try { $service->build_cart_recovery_url('fixture-cart', 'fixture@example.invalid'); throw new LogicException('link issued on failed insert'); }
    catch (RuntimeException $e) { check($e->getMessage() === 'recovery_token_persistence_failed', 'failed insert is explicit'); }
}
$wpdb->insertResult = 1;
check(strpos($service->build_cart_recovery_url('fixture-cart','fixture@example.invalid'), 'nc_rt=') !== false, 'persisted token link');
function recover(NCWooRecoveryService $service): string {
    try { $service->handle_recovery_redirect(); } catch (RecoveryRedirect $e) { return $e->getMessage(); }
    throw new RuntimeException('No redirect');
}
$_GET = ['nc_rt' => 'synthetic-token'];
$base = ['customer_id' => 42, 'used_at' => null, 'expires_at' => gmdate('Y-m-d H:i:s', time()+3600),
    'payload_json' => json_encode(['products'=>[['product_id'=>10,'quantity'=>2]]]), 'target_url'=>home_url('/cart/')];
foreach (['customer_session','cart'] as $mode) {
    $wpdb->row = $base + ['mode'=>$mode];
    foreach ([0, false] as $failedClaim) {
        $wpdb->claimResult = $failedClaim; $effects = [];
        check(recover($service) === home_url('/'), 'failed claim safe redirect');
        check($effects === ['claim'], 'failed claim has no login/cart/session side effects');
    }
    $wpdb->claimResult = 1; $effects = [];
    check(recover($service) === wc_get_cart_url(), 'valid recovery redirect');
    check($effects === ($mode === 'cart' ? ['claim','login','user','empty','add'] : ['claim','login','user']), 'claim precedes every side effect');
    foreach (['used_at'=>'2020-01-01 00:00:00', 'expires_at'=>gmdate('Y-m-d H:i:s', time()-10)] as $field=>$value) {
        $wpdb->row = array_merge($base, ['mode'=>$mode, $field=>$value]); $effects = [];
        recover($service); check($effects === [], 'used/expired rejected before effects');
    }
}
$timezone = date_default_timezone_get(); date_default_timezone_set('Pacific/Auckland');
$wpdb->row = $base + ['mode'=>'customer_session']; $effects = [];
check(recover($service) === wc_get_cart_url(), 'UTC expiry independent of PHP timezone');
date_default_timezone_set($timezone);
$method = new ReflectionMethod($service, 'is_valid_legacy_cart_signature'); $method->setAccessible(true);
$ts = time(); $oldSig = hash_hmac('sha256', 'cart:fixture@example.invalid::'.$ts, $config->get_internal_secret());
check($method->invoke($service, 'cart','fixture@example.invalid','',$ts,'','','',$oldSig), 'unchanged older signature still accepted');
foreach ([['fp','',''], ['',base64_encode('{"customer_id":1}'),''], ['','','https://fixture.example.invalid/changed']] as $extra) {
    check(!$method->invoke($service, 'cart','fixture@example.invalid','',$ts,...array_merge($extra,[$oldSig])), 'unsigned legacy additions refused');
}
$config->opaque = false;
$url = $service->build_cart_recovery_url('fixture-cart','fixture@example.invalid','','',home_url('/cart/'),['products'=>[['product_id'=>10,'quantity'=>1]]]);
parse_str(parse_url($url, PHP_URL_QUERY), $_GET); $effects = [];
check(recover($service) === wc_get_cart_url() && in_array('add', $effects, true) && !in_array('claim', $effects, true), 'current signed legacy cart preserved');
// Exercise actual REST endpoint wrappers: a storage failure is an explicit
// retryable response, never an HTTP success containing a broken recovery URL.
class NCWooSecurity { public function validate_signed_request(...$args) { return ['success'=>true]; } }
class NCWooEventService {}
class WP_User {}
class WP_REST_Request {
    private array $body;
    public function __construct(array $body) {$this->body=$body;}
    public function get_body() {return json_encode($this->body);}
    public function get_header($name) {return '';}
}
class WP_REST_Response {
    public array $data; public int $status;
    public function __construct(array $data, int $status) {$this->data=$data; $this->status=$status;}
}
require __DIR__ . '/../neurocheckout-connector/includes/class-ncwoo-cart-fingerprint.php';
require __DIR__ . '/../neurocheckout-connector/includes/class-ncwoo-endpoints.php';
$config->opaque=true; $wpdb->insertResult=false;
$endpoints = new NCWooEndpoints($config,new NCWooDB(),new NCWooSecurity(),$service,new NCWooEventService());
$request = new WP_REST_Request(['request_uid'=>'fixture-request','cart_id'=>'fixture-cart',
    'customer_email'=>'fixture@example.invalid','products'=>[['product_id'=>10,'quantity'=>1]]]);
foreach (['endpoint_cartrestore','endpoint_coupon'] as $method) {
    $result=$endpoints->$method($request);
    check($result->status===503 && $result->data['success']===false && $result->data['error']==='recovery_token_persistence_failed', 'REST storage failure explicit');
    check(!isset($result->data['data']['recovery_url']), 'no broken link returned by REST');
}
echo "$checks recovery safety assertions passed.\n";
