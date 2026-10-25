<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(2); }
define('ABSPATH', '/fixture/');
define('AUTH_KEY', 'synthetic-wordpress-auth-key');
$options = []; $site = 1; $writeFailure = false;
function get_option($name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function update_option($name, $value, $autoload = false) { $GLOBALS['options'][$name] = $value; return true; }
function add_option($name, $value, $deprecated = '', $autoload = false) {
    if ($GLOBALS['writeFailure'] || array_key_exists($name, $GLOBALS['options'])) { return false; }
    $GLOBALS['options'][$name] = $value; return true;
}
function get_current_blog_id() { return $GLOBALS['site']; }
require __DIR__ . '/../neurocheckout-connector/includes/class-ncwoo-config.php';
require __DIR__ . '/../neurocheckout-connector/includes/community/ReconciledSourceExporter.php';
use NeuroCheckout\WooCommerce\Community\AutomaticSourceBinding;
use NeuroCheckout\WooCommerce\Community\ReconciledSourceExporter;
function check($ok, $why) { if (!$ok) { throw new RuntimeException($why); } }
$config = new NCWooConfig();
$config->set(NCWooConfig::OPTION_API_ENDPOINT, 'https://www.neurocheckout.com');
$config->set(NCWooConfig::OPTION_SHOP_EXTERNAL_ID, 'fixture-shop');
$config->set(NCWooConfig::OPTION_API_KEY, 'old-fixture-key');
require_once __DIR__ . '/../neurocheckout-connector/includes/community/AutomaticSourceBinding.php';
$original = AutomaticSourceBinding::secret('old-fixture-key', 'fixture-shop');
$directory = sys_get_temp_dir() . '/nc-rotation-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$binding = ['environment'=>'production','platform'=>'woocommerce','nativeScope'=>1,'shopId'=>'fixture-shop','secret'=>$original];
$snapshot = [['kind'=>'cart','sourceId'=>'fixture-cart','payload'=>['quantity'=>1]]];
$read = static function () use (&$snapshot) { return $snapshot; };
$input = ['schema'=>1,'shopId'=>'fixture-shop','streamId'=>null,'cursor'=>'','limit'=>8];
try {
    // Legacy state exists before installing the fix or initializing a storage key.
    $first = (new ReconciledSourceExporter($directory, $binding, $read))->page($input);
    $input['streamId'] = $first['streamId']; $input['cursor'] = $first['nextCursor'];
    $file = glob($directory . '/*.bin')[0]; $before = file_get_contents($file);
    $config->set(NCWooConfig::OPTION_API_KEY_NEXT, 'prepared-fixture-key');
    check($config->get_api_key() === 'old-fixture-key', 'prepare must not replace current auth');
    foreach (['prepared-fixture-key', 'manually-pasted-fixture-key'] as $newKey) {
        $config->set(NCWooConfig::OPTION_API_KEY, $newKey);
        check($config->get_api_key() === $newKey, 'transport key changed');
        check(AutomaticSourceBinding::secret($newKey, 'fixture-shop') !== $original, 'old auth must change');
        $binding['secret'] = $config->get_source_storage_secret('production', 'fixture-shop');
        check($binding['secret'] === $original, 'storage key must survive rotation');
        check(file_get_contents($file) === $before, 'rotation must not rewrite snapshot or cursor');
    }
    $snapshot[0]['payload']['quantity'] = 2;
    $page = (new ReconciledSourceExporter($directory, $binding, $read))->page($input);
    check($page['streamId'] === $first['streamId'], 'stream preserved');
    check($page['records'][0]['revision'] === 2 && $page['records'][0]['payload']['quantity'] === 2, 'revision and cart changes continue');
    check(strpos(serialize($options), $original) === false, 'storage secret encrypted at rest');
    check($config->get_source_storage_secret('staging', 'fixture-shop') !== $original, 'environment isolated');
    check($config->get_source_storage_secret('production', 'other-shop') !== $original, 'shop isolated');
    $site = 2;
    $secondSite = $config->get_source_storage_secret('production', 'fixture-shop');
    check(count(array_filter(array_keys($options), static function ($key) { return strpos($key, 'ncwoo_source_storage_') === 0; })) === 4, 'site has separate option');
    $site = 1;
    $name = 'ncwoo_source_storage_' . hash('sha256', json_encode(['production',1,'fixture-shop']));
    $good = $options[$name]; $options[$name] = 'corrupt';
    try { $config->set(NCWooConfig::OPTION_API_KEY, 'must-not-be-saved'); throw new LogicException('corruption accepted'); }
    catch (RuntimeException $e) { check($e->getMessage() === 'source_storage_key_unavailable', 'explicit failure'); }
    check($config->get_api_key() === 'manually-pasted-fixture-key', 'bad storage preserves current API key');
    unset($options[$name]); $writeFailure = true;
    try { $config->set(NCWooConfig::OPTION_API_KEY, 'must-not-be-saved'); throw new LogicException('failed persistence accepted'); }
    catch (RuntimeException $e) { check($e->getMessage() === 'source_storage_key_unavailable', 'failed write closes'); }
    check($config->get_api_key() === 'manually-pasted-fixture-key', 'failed write preserves current API key');
    echo "PASS: legacy snapshot survives automatic/manual rotations, cursors and revisions; scoped encrypted storage and fail-closed writes.\n";
} finally {
    foreach (glob($directory . '/*') as $file) { unlink($file); }
    rmdir($directory);
}
