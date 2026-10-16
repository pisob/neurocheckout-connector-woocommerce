<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
// Actual recovery and order services; synthetic native sessions only.
define('ABSPATH', '/synthetic/');
define('ARRAY_A', 'ARRAY_A');
class NCWooConfig {
    public function get_internal_secret() { return str_repeat('server-private-fixture', 3); }
    public function get_shop_external_id() { return 'fixture-shop'; }
}
class NCWooDB {}
class WC_Order {
    public array $meta = [];
    public int $saves = 0;
    public function get_meta($key) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function save() { $this->saves++; }
}
class IdentityRedirect extends RuntimeException {}
function get_current_blog_id() { return 1; }
function home_url($path) { return 'https://fixture.invalid' . $path; }
function wc_get_cart_url() { return home_url('/cart/'); }
function wp_parse_url($url) { return parse_url($url); }
function wp_safe_redirect($url) { throw new IdentityRedirect($url); }
function wp_set_auth_cookie(...$args) {}
function wp_set_current_user(...$args) {}
function get_user_by(...$args) { return false; }
function WC() { return $GLOBALS['wc']; }
$wc = (object) ['session' => new class {
    public array $values = [];
    public function set($key, $value) { $this->values[$key] = $value; }
    public function get($key) { return $this->values[$key] ?? ''; }
}, 'cart' => new class {
    public function empty_cart() {}
    public function add_to_cart(...$args) { return 'item'; }
}];
$wpdb = new class {
    public string $prefix = 'wp_';
    public array $row = [];
    public int $claim = 1;
    public function prepare($sql, ...$args) { return $sql; }
    public function get_row(...$args) { return $this->row; }
    public function query(...$args) { return $this->claim; }
};
require __DIR__ . '/../neurocheckout-connector/includes/class-ncwoo-recovery.php';
require __DIR__ . '/../neurocheckout-connector/includes/class-ncwoo-event-service.php';
use NeuroCheckout\WooCommerce\Community\WooRecoveryIdentity;
$checks = 0;
function identityCheck(bool $ok, string $message): void {
    global $checks; $checks++; if (!$ok) { throw new RuntimeException($message); }
}
$config = new NCWooConfig();
$recovery = new NCWooRecoveryService($config, new NCWooDB());
$identity = WooRecoveryIdentity::fromConfig($config);
$reference = 'wc-cart-' . str_repeat('a', 64);
$wpdb->row = ['mode'=>'cart', 'cart_id'=>$reference, 'customer_id'=>42, 'used_at'=>null,
    'expires_at'=>gmdate('Y-m-d H:i:s', time()+3600), 'payload_json'=>'{"products":[{"product_id":13,"quantity":2}]}',
    'target_url'=>wc_get_cart_url()];
$_GET = ['nc_rt'=>'synthetic-token'];
$run = static function () use ($recovery): void {
    try { $recovery->handle_recovery_redirect(); } catch (IdentityRedirect $e) { return; }
    throw new RuntimeException('Expected redirect');
};
$wpdb->claim = 0; $run();
identityCheck($wc->session->values === [], 'failed claim cannot issue evidence');
$wpdb->claim = 1; $run();
$proof = $wc->session->get(WooRecoveryIdentity::SESSION_KEY);
identityCheck($wc->session->get('ncwoo_runtime_cart_id') === $reference && $identity->verifies($reference, $proof), 'successful native recovery issues server evidence');
identityCheck(!isset($_COOKIE[WooRecoveryIdentity::SESSION_KEY]), 'proof never stored in client cookies');
$class = new ReflectionClass(NCWooEventService::class);
$events = $class->newInstanceWithoutConstructor();
$property = $class->getProperty('config'); $property->setAccessible(true); $property->setValue($events, $config);
$order = new WC_Order(); $events->attach_cart_context_to_order($order);
identityCheck($order->meta['_ncwoo_cart_id'] === $reference && $order->meta[WooRecoveryIdentity::ORDER_KEY] === $proof, 'proof and original ID persisted with the native order');
$clear = $class->getMethod('clear_runtime_cart_id'); $clear->setAccessible(true); $clear->invoke($events);
identityCheck($wc->session->get('ncwoo_runtime_cart_id') === '' && $wc->session->get(WooRecoveryIdentity::SESSION_KEY) === '', 'completed cart clears both session fields');
$events->attach_cart_context_to_order($order);
identityCheck($order->saves === 1 && $order->meta[WooRecoveryIdentity::ORDER_KEY] === $proof, 'Blocks duplicate processed hook preserves original proof');
$_COOKIE['ncwoo_recovery_cart_id'] = $reference;
$wc->session->set(WooRecoveryIdentity::SESSION_KEY, $proof);
$resolve = $class->getMethod('resolve_runtime_cart_id'); $resolve->setAccessible(true); $resolve->invoke($events, false);
identityCheck($wc->session->get(WooRecoveryIdentity::SESSION_KEY) === '', 'cookie fallback cannot inherit trusted evidence');
$wc->session->set(WooRecoveryIdentity::SESSION_KEY, 'v1:' . str_repeat('b', 64));
$untrusted = new WC_Order(); $events->attach_cart_context_to_order($untrusted);
identityCheck(!isset($untrusted->meta[WooRecoveryIdentity::ORDER_KEY]), 'invalid evidence never copied to order');
echo $checks . " native recovery/order identity checks passed (synthetic, no database or network).\n";
