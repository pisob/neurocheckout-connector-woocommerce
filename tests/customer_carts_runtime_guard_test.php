<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { exit(1); }
require_once __DIR__.'/../neurocheckout-connector/includes/community/WooSourceSnapshotFactory.php';
use NeuroCheckout\WooCommerce\Community\WooSourceSnapshotFactory;
$blocked='';$checks=0;
function has_filter($hook) { global $blocked;return $hook===$blocked?10:false; }
foreach (['get_user_metadata','add_user_metadata','update_user_metadata','delete_user_metadata',
    'woocommerce_persistent_cart_enabled','woocommerce_cart_session_initialize'] as $hook) {
    $blocked=$hook;
    try { WooSourceSnapshotFactory::customerCarts(1,[]);throw new RuntimeException('custom storage accepted'); }
    catch (RuntimeException $error) {
        if ($error->getMessage()!=='customer_carts_unavailable') { throw $error; }
        ++$checks;
    }
}
echo "WooCommerce cart runtime guards: $checks assertions passed\n";
