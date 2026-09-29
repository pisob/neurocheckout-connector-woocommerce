<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use NeuroCheckout\WooCommerce\Community\WooSourceSnapshot;
use NeuroCheckout\WooCommerce\Community\WooSourceSnapshotFactory;
use NeuroCheckout\WooCommerce\Community\WooSessionProjection;
use NeuroCheckout\WooCommerce\Community\ReconciledSourceExporter;
use NeuroCheckout\WooCommerce\Community\SourcePullGateway;

$base = __DIR__ . '/../includes/community/';
foreach (['BoundedPhpValueReader', 'WooSessionProjection', 'WooSourceSnapshot', 'WooSourceSnapshotFactory',
    'ReconciledSourceExporter', 'SourcePullProtocol', 'SourcePullGateway'] as $name) { require_once $base . $name . '.php'; }
$socket = $argv[1] ?? '';
if (!preg_match('#^/tmp/nc-source-mariadb\.[A-Za-z0-9]+/mariadb\.sock$#D', $socket) || !file_exists($socket)) { exit(2); }
$checks = 0;
function checkWoo(bool $ok, string $message): void {
    global $checks; $checks++;
    if (!$ok) { throw new RuntimeException($message); }
}
function rejectWoo(callable $call, string $message): void {
    try { $call(); } catch (Throwable $error) { checkWoo($error->getMessage() === $message, 'Expected ' . $message . ', received ' . $error->getMessage()); return; }
    throw new RuntimeException('Unexpected acceptance: ' . $message);
}
// Runtime doubles. No WordPress bootstrap or real database configuration.
class wpdb {
    public int $blogid = 2;
    public string $prefix = 'wp_2_';
    public function get_blog_prefix($scope) { return is_multisite() ? 'wp_' . $scope . '_' : 'wp_2_'; }
}
class WP_Hook { public array $callbacks = []; }
$testBlogId = 2; $testMultisite = true;
function get_current_blog_id() { global $testBlogId; return $testBlogId; }
function is_multisite() { global $testMultisite; return $testMultisite; }
$testFilters = [];
function has_filter($name) { global $testFilters; return $testFilters[$name] ?? false; }
class WooTestPDO extends PDO {
    public $onSessionRead = null;
    #[\ReturnTypeWillChange]
    public function prepare($query, $options = []) {
        if ($this->onSessionRead !== null && strpos($query, 'FROM `wp_2_woocommerce_sessions` WHERE') !== false) {
            $callback = $this->onSessionRead; $this->onSessionRead = null; $callback($this);
        }
        return parent::prepare($query, $options);
    }
}
$database = 'nc_woo_fixture_' . bin2hex(random_bytes(6));
$dsn = 'mysql:unix_socket=' . $socket . ';charset=utf8mb4';
$admin = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE `' . $database . '`');
$directory = sys_get_temp_dir() . '/nc-woo-export-' . bin2hex(random_bytes(8)); mkdir($directory, 0700);
$oldConfig = getenv('NC_COMMUNITY_SOURCE_CONFIG');
try {
    $writer = new PDO($dsn . ';dbname=' . $database, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $schemas = [
        'options' => 'option_id BIGINT AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) UNIQUE, option_value LONGTEXT',
        'posts' => "ID BIGINT PRIMARY KEY, post_parent BIGINT DEFAULT 0, post_type VARCHAR(20), post_status VARCHAR(20), post_title TEXT, post_name VARCHAR(200), post_excerpt TEXT, post_modified_gmt DATETIME, post_date_gmt DATETIME",
        'postmeta' => 'meta_id BIGINT AUTO_INCREMENT PRIMARY KEY, post_id BIGINT, meta_key VARCHAR(255), meta_value LONGTEXT',
        'woocommerce_sessions' => 'session_id BIGINT AUTO_INCREMENT PRIMARY KEY, session_key VARCHAR(32) UNIQUE, session_value LONGTEXT, session_expiry BIGINT',
        'term_relationships' => 'object_id BIGINT, term_taxonomy_id BIGINT',
        'term_taxonomy' => 'term_taxonomy_id BIGINT PRIMARY KEY, term_id BIGINT, taxonomy VARCHAR(32)',
        'terms' => 'term_id BIGINT PRIMARY KEY, name VARCHAR(200), slug VARCHAR(200)',
        'wc_orders' => 'id BIGINT PRIMARY KEY, status VARCHAR(20), currency VARCHAR(10), type VARCHAR(20), total_amount DECIMAL(26,8), date_created_gmt DATETIME, date_updated_gmt DATETIME',
        'wc_orders_meta' => 'id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT, meta_key VARCHAR(255), meta_value TEXT',
    ];
    foreach (['wp_2_', 'wp_3_'] as $prefix) {
        foreach ($schemas as $table => $schema) { $writer->exec('CREATE TABLE `' . $prefix . $table . '` (' . $schema . ') ENGINE=InnoDB'); }
        $writer->exec("INSERT INTO {$prefix}options(option_name,option_value) VALUES ('woocommerce_custom_orders_table_enabled','no'),('woocommerce_currency','USD'),('woocommerce_prices_include_tax','no')");
        $writer->exec("INSERT INTO {$prefix}posts VALUES (10,0,'product','publish','Product été','product','Short description',NOW(),NOW()),(11,10,'product_variation','publish','Blue variation','blue','',NOW(),NOW())");
    }
    $writer->exec("INSERT INTO wp_2_postmeta(post_id,meta_key,meta_value) VALUES (10,'_sku','SKU-10'),(10,'_price','83.21000000'),(10,'_stock','9'),(10,'_stock_status','instock'),(10,'private_secret','do-not-export'),(11,'attribute_pa_color','blue')");
    $writer->exec("INSERT INTO wp_2_terms VALUES (1,'Test category','test-category'); INSERT INTO wp_2_term_taxonomy VALUES (1,1,'product_cat'); INSERT INTO wp_2_term_relationships VALUES (10,1)");
    $cartId = 'nc-11111111-1111-4111-8111-111111111111';
    $session = serialize(['ncwoo_runtime_cart_id' => $cartId,
        'cart' => serialize(['private-line' => ['product_id' => 10, 'variation_id' => 11, 'quantity' => 1]]),
        'customer' => serialize(['email' => 'fixture@example.invalid']), 'cart_totals' => serialize(['total' => '83.21'])]);
    $insertSession = $writer->prepare('INSERT INTO wp_2_woocommerce_sessions(session_key,session_value,session_expiry) VALUES (?,?,?)');
    $insertSession->execute(['private-guest-session', $session, time() + 3600]);
    $insertSession->execute(['expired-session', $session, time() - 1]);
    $writer->prepare('INSERT INTO wp_3_woocommerce_sessions(session_key,session_value,session_expiry) VALUES (?,?,?)')->execute(['foreign-session', str_replace('fixture@', 'foreign@', $session), time()+3600]);
    $db = new WooTestPDO($dsn . ';dbname=' . $database, 'root', '');
    $reader = new WooSourceSnapshot($db, 'wp_2_', 2, str_repeat('ab', 32), 'synthetic-shop');
    $projection = new WooSessionProjection(str_repeat('ab', 32), 2, 'synthetic-shop');
    $cartReference = $projection->cartReference($cartId);
    $snapshot = $reader->capture(2);
    checkWoo(count($snapshot) === 3, 'product, variation, active guest; expired omitted');
    checkWoo($snapshot[0]['payload']['native_meta']->_price === '83.21000000', 'stored price precision');
    checkWoo($snapshot[1]['payload']['native_meta']->attribute_pa_color === 'blue', 'variation attributes');
    checkWoo($snapshot[0]['payload']['terms'][0]['slug'] === 'test-category', 'scoped taxonomy');
    checkWoo($snapshot[2]['sourceId'] === $cartReference && $snapshot[2]['payload']['customer']->email === 'fixture@example.invalid', 'opaque guest cart');
    checkWoo($snapshot[2]['payload']['status'] === 'active' && $snapshot[2]['payload']['conversion_status'] === 'no_linked_order', 'native order absence');
    foreach (['private-guest-session', $cartId, 'do-not-export', 'foreign@'] as $sensitive) {
        checkWoo(strpos(json_encode($snapshot), $sensitive) === false, 'private or foreign source excluded');
    }
    rejectWoo(static function () use ($reader) { $reader->capture(3); }, 'source_scope_inconsistent');
    define('WC_VERSION', '10.7.0'); define('WP_CONTENT_DIR', $directory . '/unused-content');
    define('DB_HOST', 'localhost:' . $socket); define('DB_NAME', $database); define('DB_USER', 'root'); define('DB_PASSWORD', '');
    $wpdb = new wpdb(); $wp_filter = [];
    $config = ['enabled' => true, 'environment' => 'staging', 'platform' => 'woocommerce', 'nativeScope' => 2, 'shopId' => 'synthetic-shop', 'secret' => str_repeat('ab', 32)];
    checkWoo(count(WooSourceSnapshotFactory::create(2, $config)->capture(2)) === 3, 'factory isolated runtime connection');
    $testMultisite = false; $testBlogId = 1; $wpdb->blogid = 0;
    $singleConfig = $config; $singleConfig['nativeScope'] = 1;
    checkWoo(count(WooSourceSnapshotFactory::create(1, $singleConfig)->capture(1)) === 3, 'single site permits native wpdb blogid zero with current blog one');
    $testMultisite = true; $testBlogId = 2; $wpdb->blogid = 2;
    foreach (['woocommerce_session_handler', 'woocommerce_data_stores', 'pre_option_woocommerce_custom_orders_table_enabled'] as $filter) {
        $testFilters[$filter] = 10;
        rejectWoo(static function () use ($config) { WooSourceSnapshotFactory::create(2, $config); }, 'source_schema_unavailable');
        unset($testFilters[$filter]);
    }
    $wp_filter['woocommerce_order_data_store'] = new WP_Hook();
    $wp_filter['woocommerce_order_data_store']->callbacks = [10 => [['function' => 'unknown_custom_orders']]];
    rejectWoo(static function () use ($config) { WooSourceSnapshotFactory::create(2, $config); }, 'source_schema_unavailable');
    $wp_filter = [];
    $wpdb->prefix = 'wp_3_';
    rejectWoo(static function () use ($config) { WooSourceSnapshotFactory::create(2, $config); }, 'source_schema_unavailable'); $wpdb->prefix = 'wp_2_';
    foreach (['localhost;dbname=other', 'localhost:0', 'localhost:65536', 'localhost:/tmp/bad;socket'] as $host) {
        rejectWoo(static function () use ($host) { WooSourceSnapshotFactory::connectionParameters($host, 'db', 'user', 'secret'); }, 'source_schema_unavailable');
    }
    $configFile = $directory . '/source.json'; file_put_contents($configFile, json_encode($config)); chmod($configFile, 0600);
    putenv('NC_COMMUNITY_SOURCE_CONFIG=' . $configFile);
    $pages = [];
    $input = ['schema' => 1, 'shopId' => 'synthetic-shop', 'streamId' => null, 'cursor' => '', 'limit' => 8];
    $pull = static function () use (&$input, &$pages, $config): array {
        $path = '/wp-json/neurocheckout/v1/communitydata'; $body = json_encode($input);
        $nonce = bin2hex(random_bytes(16)); $timestamp = (string) (int) floor(microtime(true) * 1000);
        $signature = hash_hmac('sha256', implode("\n", ['nc-source-pull-v1','POST',$path,'synthetic-shop',$timestamp,$nonce,hash('sha256',$body)]), hex2bin($config['secret']));
        [$status, $headers, $response] = SourcePullGateway::handle('woocommerce', 2, __DIR__, 'POST', $path,
            ['content-type'=>'application/json','x-nc-source-time'=>$timestamp,'x-nc-source-nonce'=>$nonce,'x-nc-source-signature'=>$signature], $body, true,
            static function ($request, $scope, $configuration, $directory): array {
                return (new ReconciledSourceExporter($directory, $configuration, static function () use ($scope,$configuration): array {
                    return WooSourceSnapshotFactory::create($scope,$configuration)->capture($scope);
                }))->page($request);
            });
        checkWoo($status === 200, 'authenticated native source response');
        checkWoo(($headers['X-NC-Source-Response'] ?? '') === hash_hmac('sha256', implode("\n", ['nc-source-response-v1',$nonce,hash('sha256',$response)]), hex2bin($config['secret'])), 'raw native page signed');
        $page = json_decode($response,true,512,JSON_THROW_ON_ERROR);
        checkWoo($page['complete'], 'fresh bounded snapshot completed');
        $input['streamId']=$page['streamId']; $input['cursor']=$page['nextCursor']; $pages[]=$response;
        return $page;
    };
    $pull();
    // Compatibility backup is ignored while posts remain authoritative.
    $writer->exec("INSERT INTO wp_2_wc_orders VALUES (100,'wc-checkout-draft','USD','shop_order',999,NOW(),NOW())");
    $writer->prepare("INSERT INTO wp_2_wc_orders_meta(order_id,meta_key,meta_value) VALUES (100,'_ncwoo_cart_id',?)")->execute([$cartId]);
    checkWoo($reader->capture(2)[2]['payload']['status'] === 'active', 'HPOS backup ignored in posts mode');
    $writer->beginTransaction();
    $writer->exec("INSERT INTO wp_2_posts VALUES (100,0,'shop_order','wc-pending','','','',NOW(),NOW())");
    $writer->prepare("INSERT INTO wp_2_postmeta(post_id,meta_key,meta_value) VALUES (100,'_ncwoo_cart_id',?),(100,'_order_currency','USD'),(100,'_order_total','83.21')")->execute([$cartId]);
    $db->onSessionRead = static function (PDO $connection) use ($writer): void {
        $blocked = false;
        try { $connection->exec("UPDATE wp_2_options SET option_value='yes' WHERE option_name='woocommerce_custom_orders_table_enabled'"); } catch (PDOException $error) { $blocked = true; }
        checkWoo($blocked, 'snapshot transaction enforces read only'); $writer->commit();
    };
    checkWoo($reader->capture(2)[2]['payload']['status'] === 'active', 'concurrent commit cannot mix snapshot versions');
    $linked = $reader->capture(2)[2]['payload'];
    checkWoo($linked['status'] === 'order_present' && $linked['conversion_status'] === 'linked_order_not_payment_proof', 'pending order suppresses abandonment without claiming payment');
    checkWoo($linked['orders'][0]['total_amount'] === '83.21' && count($linked['orders']) === 1, 'posts values only, no duplicate backup');
    $writer->exec("DELETE FROM wp_2_woocommerce_sessions WHERE session_key='private-guest-session'");
    $second = $pull();
    checkWoo($second['records'][0]['payload']['session_status'] === 'absent', 'order survives session removal');
    $writer->exec("DELETE FROM wp_2_posts WHERE ID IN (10,11)");
    $third = $pull();
    checkWoo(count($third['records']) === 2 && $third['records'][0]['operation'] === 'delete', 'native product deletion emits tombstones');
    // Switch authoritative storage. Old post order must not double count.
    $writer->exec("UPDATE wp_2_options SET option_value='yes' WHERE option_name='woocommerce_custom_orders_table_enabled'");
    $writer->exec("UPDATE wp_2_wc_orders SET currency='BADVALUE' WHERE id=100");
    rejectWoo(static function () use ($reader) { $reader->capture(2); }, 'source_schema_unavailable');
    $writer->exec("UPDATE wp_2_wc_orders SET currency='USD' WHERE id=100");
    $hpos = $reader->capture(2)[0]['payload'];
    checkWoo($hpos['order_storage'] === 'hpos' && count($hpos['orders']) === 1 && (float)$hpos['orders'][0]['total_amount'] === 999.0, 'HPOS authoritative even with divergent backup');
    checkWoo($hpos['status'] === 'order_present' && $hpos['orders'][0]['status'] === 'wc-checkout-draft', 'Blocks draft distinguished from paid conversion');
    $writer->exec("UPDATE wp_2_wc_orders SET status='wc-completed' WHERE id=100");
    checkWoo($reader->capture(2)[0]['payload']['orders'][0]['status'] === 'wc-completed', 'updated HPOS order observed');
    $writer->prepare("INSERT INTO wp_2_wc_orders_meta(order_id,meta_key,meta_value) VALUES (100,'_ncwoo_cart_id',?)")->execute([$cartId]);
    rejectWoo(static function () use ($reader) { $reader->capture(2); }, 'source_scope_inconsistent');
    $writer->exec('DELETE FROM wp_2_wc_orders_meta WHERE id=2');
    $writer->exec('ALTER TABLE wp_2_wc_orders ENGINE=MyISAM');
    rejectWoo(static function () use ($reader) { $reader->capture(2); }, 'source_snapshot_not_transactional');
    $writer->exec('ALTER TABLE wp_2_wc_orders ENGINE=InnoDB');
    $insertSession->execute(['duplicate-one',$session,time()+3600]); $insertSession->execute(['duplicate-two',$session,time()+3600]);
    rejectWoo(static function () use ($reader) { $reader->capture(2); }, 'source_scope_inconsistent');
    $writer->exec("DELETE FROM wp_2_woocommerce_sessions WHERE session_key IN ('duplicate-one','duplicate-two')");
    $insertSession->execute(['oversize',str_repeat('x',65537),time()+3600]);
    rejectWoo(static function () use ($reader) { $reader->capture(2); }, 'source_session_invalid');
    $writer->exec("DELETE FROM wp_2_woocommerce_sessions WHERE session_key='oversize'");
    $writer->exec("INSERT INTO wp_2_posts VALUES (11,10,'product_variation','publish','','','',NOW(),NOW())");
    rejectWoo(static function () use ($reader) { $reader->capture(2); }, 'source_scope_inconsistent');
    $writer->exec("DELETE FROM wp_2_posts WHERE ID=11");
    $writer->prepare("INSERT INTO wp_2_posts VALUES (10,0,'product','publish',?,'','',NOW(),NOW())")->execute([str_repeat('x',20000)]);
    rejectWoo(static function () use ($reader) { $reader->capture(2); }, 'source_snapshot_capacity');
    $writer->exec("UPDATE wp_2_posts SET post_title='Product' WHERE ID=10");
    $writer->exec("INSERT INTO wp_2_postmeta(post_id,meta_key,meta_value) VALUES (10,'_price','duplicate')");
    rejectWoo(static function () use ($reader) { $reader->capture(2); }, 'source_scope_inconsistent');
    $writer->exec("DELETE FROM wp_2_posts WHERE ID=10");
    $writer->exec("UPDATE wp_2_options SET option_value='unexpected' WHERE option_name='woocommerce_custom_orders_table_enabled'");
    rejectWoo(static function () use ($reader) { $reader->capture(2); }, 'source_schema_unavailable');
    $writer->exec("UPDATE wp_2_options SET option_value='yes' WHERE option_name='woocommerce_custom_orders_table_enabled'");
    $insert = $writer->prepare("INSERT INTO wp_2_posts VALUES (?,0,'product','publish','','','',NOW(),NOW())");
    for ($id=1000;$id<1257;$id++) { $insert->execute([$id]); }
    rejectWoo(static function () use ($reader) { $reader->capture(2); }, 'source_snapshot_capacity');
    if (($argv[2] ?? '') === '--pages-json') {
        echo json_encode(['assertions'=>$checks,'cartReference'=>$cartReference,'pages'=>$pages],JSON_THROW_ON_ERROR);
    } else { echo $checks . " WooCommerce SQL/factory/gateway assertions passed (synthetic schema and runtime doubles).\n"; }
} finally {
    if (isset($writer) && $writer->inTransaction()) { $writer->rollBack(); }
    putenv($oldConfig === false ? 'NC_COMMUNITY_SOURCE_CONFIG' : 'NC_COMMUNITY_SOURCE_CONFIG=' . $oldConfig);
    $admin->exec('DROP DATABASE `' . $database . '`');
    foreach (glob($directory . '/*') as $file) { unlink($file); } rmdir($directory);
}
