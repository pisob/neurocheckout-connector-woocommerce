<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/neurocheckout-connector/includes/community/AutomaticSourceBinding.php';
$class = 'NeuroCheckout\\WooCommerce\\Community\\AutomaticSourceBinding';
$expected = hash_hmac('sha256', "neurocheckout-community-source-v1\0fixture-shop", 'fixture-key');
foreach ([['fixture-key', 'fixture-shop'], [' fixture-key ', ' fixture-shop ']] as $input) {
    if ($class::secret(...$input) !== $expected) throw new RuntimeException('Binding mismatch');
}
if ($class::secret('fixture-key', 'another-shop') === $expected) throw new RuntimeException('Missing shop isolation');
foreach ([['', 'fixture-shop'], ['key', '../shop'], ['key', ''], ['key', str_repeat('x',129)]] as $input) {
    try { $class::secret(...$input); } catch (RuntimeException $error) { continue; }
    throw new RuntimeException('Invalid binding accepted');
}
echo "7 automatic binding assertions passed.\n";
