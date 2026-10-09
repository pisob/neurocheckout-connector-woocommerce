<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../includes/community/BoundedPhpValueReader.php';
require_once __DIR__ . '/../includes/community/WooSessionProjection.php';

use NeuroCheckout\WooCommerce\Community\BoundedPhpValueReader;
use NeuroCheckout\WooCommerce\Community\WooSessionProjection;

$checks = 0;
function checkSession(bool $condition, string $name): void
{
    global $checks; $checks++;
    if (!$condition) { throw new RuntimeException($name); }
}
function rejectSession(callable $call, string $name): void
{
    try { $call(); } catch (RuntimeException $error) {
        checkSession(in_array($error->getMessage(), ['source_session_invalid', 'source_snapshot_capacity'], true), $name);
        return;
    }
    throw new RuntimeException('Unexpected acceptance: ' . $name);
}

final class SessionObjectTrap
{
    public static int $woke = 0;
    public function __wakeup(): void { self::$woke++; }
}

// Synthetic data only. Native layout: WC_Session::set maybe-serializes each
// array; WC_Session_Handler::save_data serializes the outer associative array.
$line = ['product_id' => 10, 'variation_id' => 11, 'quantity' => 2,
    'variation' => ['attribute_pa_color' => 'bleu été'],
    'line_subtotal' => '83.21000000', 'line_subtotal_tax' => 0,
    'line_total' => '80.00', 'line_tax' => 0, 'data_hash' => 'private-line-hash',
    'plugin_payment_token' => 'private-payment-token'];
$session = [
    'cart' => serialize(['private-line-key' => $line]),
    'cart_totals' => serialize(['total' => '80.00', 'shipping_total' => '0', 'plugin_secret' => 'private-total']),
    'customer' => serialize(['email' => 'guest@example.invalid', 'first_name' => 'Invité',
        'billing_email' => 'billing@example.invalid', 'phone' => 'private-phone', 'id' => 'private-customer']),
    'ncwoo_runtime_cart_id' => 'nc-11111111-1111-4111-8111-111111111111',
    'store_api_draft_order' => 99,
    'chosen_payment_method' => 'private-provider',
    'plugin_value' => serialize(new SessionObjectTrap()),
];
$now = 1800000000;
$reader = new WooSessionProjection(str_repeat('ab', 32), 2, 'synthetic-shop');
$project = static function (array $value) use ($reader, $now): array {
    return $reader->project('private-session-key', serialize($value), $now + 3600, $now);
};

foreach ([null, false, true, 0, -7, PHP_INT_MAX, PHP_INT_MIN, 1.25, 1.0E20,
    '', 'été', "binary\0string", ['a' => [1, false, null]]] as $value) {
    checkSession(BoundedPhpValueReader::decode(serialize($value)) === $value, 'scalar/array round trip');
}
foreach ([
    '', 'N;trailing', 'b:2;', 'i:01;', 'i:999999999999999999999;', 'd:INF;', 'd:NAN;',
    'd:1e999;', 's:100:"short";', 's:1:"ab";', 'a:1:{i:0;}',
    'a:2:{s:1:"x";i:1;s:1:"x";i:2;}',
    'a:2:{i:1;i:1;s:1:"1";i:2;}', 'a:1:{b:1;i:2;}',
    'a:1:{i:0;R:1;}', 'r:1;', 'C:4:"Trap":0:{}', 'E:8:"Foo:Bar";',
    serialize(new SessionObjectTrap()), 'a:513:{}', serialize(str_repeat('x', 65536)),
] as $invalid) {
    rejectSession(static function () use ($invalid) { BoundedPhpValueReader::decode($invalid); }, 'invalid serialization');
}
$deep = 1;
for ($i = 0; $i < 14; $i++) { $deep = [$deep]; }
rejectSession(static function () use ($deep) { BoundedPhpValueReader::decode(serialize($deep)); }, 'depth bounded');
$manyNodes = array_fill(0, 10, array_fill(0, 250, 0));
rejectSession(static function () use ($manyNodes) { BoundedPhpValueReader::decode(serialize($manyNodes)); }, 'node count bounded');
$recursive = []; $recursive['self'] = &$recursive;
rejectSession(static function () use (&$recursive) { BoundedPhpValueReader::decode(serialize($recursive)); }, 'recursive reference');

$record = $project($session);
checkSession($record['payload']['status'] === 'active' && $record['kind'] === 'cart', 'guest cart projected');
checkSession($record['payload']['conversion_status'] === 'not_checked', 'no conversion inference from draft order');
checkSession($record['payload']['currency_status'] === 'not_exported', 'no invented currency');
checkSession($record['payload']['items'][0]['line_subtotal'] === '83.21000000', 'decimal precision preserved');
checkSession($record['payload']['customer']->email === 'guest@example.invalid', 'allowed guest contact');
checkSession($record['payload']['items'][0]['variation']->attribute_pa_color === 'bleu été', 'UTF-8 variation');
checkSession($record['sourceId'] === $reader->cartReference($session['ncwoo_runtime_cart_id']), 'order metadata can share opaque reference');
checkSession($record['sourceId'] === $project($session)['sourceId'], 'stable reference');
foreach (['private-session-key', 'private-line-key', 'private-line-hash', 'private-payment-token',
    'private-total', 'private-phone', 'private-customer', 'private-provider', 'store_api_draft_order',
    $session['ncwoo_runtime_cart_id'], 'SessionObjectTrap'] as $secret) {
    checkSession(strpos(json_encode($record), $secret) === false, 'non-allowlisted value not exported');
}
checkSession(SessionObjectTrap::$woke === 0, 'no object instantiated');
$loggedIn = $reader->project('42', serialize($session), $now + 3600, $now);
checkSession($loggedIn['sourceId'] === $record['sourceId'], 'guest login migration retains runtime cart identity');
checkSession($loggedIn['payload']['customer_is_guest'] === false, 'native user session is registered');
checkSession($record['payload']['customer_is_guest'] === true, 'email does not prove registration');
foreach (['0', '0042', 't_42', str_repeat('1', 32), 'guest-key', '-1', '1.0'] as $guestKey) {
    $guestRecord = $reader->project($guestKey, serialize($session), $now + 3600, $now);
    checkSession($guestRecord['payload']['customer_is_guest'] === true, 'guest or ambiguous key stays guest');
}
$fallback = $session; unset($fallback['ncwoo_runtime_cart_id']);
checkSession($project($fallback)['sourceId'] === $reader->cartReference('private-session-key'), 'legacy session fallback is opaque');
checkSession($project($fallback)['sourceId'] !== $record['sourceId'], 'different native identities distinct');
foreach ([new WooSessionProjection(str_repeat('ab', 32), 3, 'synthetic-shop'),
    new WooSessionProjection(str_repeat('ab', 32), 2, 'other-shop'),
    new WooSessionProjection(str_repeat('cd', 32), 2, 'synthetic-shop')] as $other) {
    checkSession($other->cartReference('private-session-key') !== $reader->cartReference('private-session-key'), 'scope/secret domain separation');
}
checkSession($reader->project('private-session-key', serialize($session), $now, $now) === null, 'expired is omitted not converted');
checkSession($reader->project('private-session-key', serialize($session), $now - 1, $now) === null, 'past session omitted');
checkSession($project([])['payload']['status'] === 'empty', 'missing cart is empty');
checkSession(json_encode($project([])['payload']['customer']) === '{}', 'empty object shape retained');
$nativeArrays = $session; $nativeArrays['cart'] = ['key' => $line];
checkSession(json_encode($project($nativeArrays)['payload']['items']) === json_encode($record['payload']['items']), 'already-array field supported');

foreach ([
    ['cart' => serialize(serialize(['x' => $line]))],
    ['cart' => serialize(new SessionObjectTrap())],
    ['cart' => 'not-serialized'],
    ['cart' => serialize([['product_id' => 0, 'quantity' => 1]])],
    ['cart' => serialize([array_merge($line, ['quantity' => 0])])],
    ['cart' => serialize([array_merge($line, ['quantity' => '1e3'])])],
    ['cart' => serialize([array_merge($line, ['product_id' => true])])],
    ['cart' => serialize([array_merge($line, ['variation' => ['token' => 'private']])])],
    ['cart' => serialize(array_fill(0, 129, ['product_id' => 1, 'quantity' => 1]))],
    ['customer' => serialize(['email' => "bad\0@example.invalid"])],
    ['customer' => serialize(['first_name' => "\xff"])],
    ['customer' => serialize(['email' => str_repeat('x', 321)])],
    ['ncwoo_runtime_cart_id' => ['unexpected-array']],
    ['ncwoo_runtime_cart_id' => "bad\nidentifier"],
] as $invalid) {
    rejectSession(static function () use ($project, $session, $invalid) { $project(array_merge($session, $invalid)); }, 'invalid projection refused');
}
rejectSession(static function () use ($reader, $session, $now) {
    $reader->project('private-session-key', serialize($session), 0, $now);
}, 'invalid expiry');
rejectSession(static function () { new WooSessionProjection('bad-secret', 2, 'synthetic-shop'); }, 'invalid key');
rejectSession(static function () use ($reader) { $reader->cartReference(str_repeat('x', 257)); }, 'reference bound');
$oversize = $session;
$oversize['cart'] = serialize(array_fill(0, 60, array_merge($line,
    ['variation' => ['attribute_pa_color' => str_repeat('x', 300)]])));
rejectSession(static function () use ($project, $oversize) { $project($oversize); }, 'bounded final payload');
checkSession(SessionObjectTrap::$woke === 0, 'invalid selected object never instantiated');

echo $checks . " WooCommerce session reader/projection assertions passed (synthetic, no DB or hooks).\n";
