<?php
// Pure regression: no WordPress database, network, real customer or mail.
define('ABSPATH', __DIR__);
final class WC_Order {
    public $created;
    public $meta;
    public $writes = 0;
    public function __construct($created, $meta = '') { $this->created = $created; $this->meta = $meta; }
    public function get_date_created() { return $this->created ? new DateTimeImmutable('@' . $this->created) : null; }
    public function get_meta($key) { return $this->meta; }
    public function update_meta_data($key, $value) { $this->meta = $value; }
    public function save_meta_data() { $this->writes++; }
}
function wc_get_order($id) { return $GLOBALS['fixture_order']; }
require_once __DIR__ . '/../includes/class-ncwoo-event-service.php';
$class = new ReflectionClass('NCWooEventService');
$service = $class->newInstanceWithoutConstructor();
$read = $class->getMethod('order_event_time'); $read->setAccessible(true);
$record = $class->getMethod('record_checkout_time'); $record->setAccessible(true);
function checkTime($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$created = time() - 7200;
$order = new WC_Order($created);
$GLOBALS['fixture_order'] = $order;
checkTime($read->invoke($service, $order)['source'] === 'order_created_fallback', 'Legacy creation remains explicitly a fallback');
$start = time(); $record->invoke($service, 1);
$placed = $read->invoke($service, $order);
checkTime($placed['source'] === 'checkout_processed' && strtotime($placed['at']) >= $start, 'Draft time is not placement time');
$record->invoke($service, 1);
checkTime($order->writes === 1 && $read->invoke($service, $order) === $placed, 'Repeated checkout does not move the timestamp');
foreach (['0', '-1', '1e12', [], true, (string) (time() + 3600), (string) ($created - 1)] as $invalid) {
    checkTime($read->invoke($service, new WC_Order($created, $invalid))['source'] === 'order_created_fallback', 'Invalid placement evidence refused');
}
checkTime($read->invoke($service, new WC_Order(null))['source'] === 'observed_fallback', 'Missing creation not falsely labelled placement');
$linked = new WC_Order($created, 'original-cart');
// The stub deliberately has no session functions: an existing link must not
// consult a cleared/different cart or mutate metadata on a repeated hook.
$service->attach_cart_context_to_order($linked);
checkTime($linked->meta === 'original-cart' && $linked->writes === 0, 'Existing attribution link is immutable');
echo "PASS: checkout draft timing, stable repeated hooks, explicit legacy fallbacks and invalid metadata.\n";
