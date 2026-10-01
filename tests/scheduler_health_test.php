<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', '/synthetic-only/');
require __DIR__ . '/../neurocheckout-connector/includes/class-ncwoo-scheduler-health.php';
function update_option($key, $value, $autoload) {
    if ($key !== NCWooSchedulerHealth::LAST_TICK || !is_int($value) || $autoload !== false) {
        throw new RuntimeException('Invalid heartbeat storage');
    }
    $GLOBALS['tick'] = $value;
}
$now = 1800000000;
$cases = [
    ['cron_module', true, $now + 30, 0, 300, 'disabled'],
    ['cron_module', true, $now + 30, $now - 10, 300, 'running'],
    ['cron_module', false, $now + 30, 0, 300, 'unverified'],
    ['cron_module', false, 0, 0, 300, 'missing'],
    ['cron_module', true, 0, $now - 10, 300, 'missing'],
    ['cron_module', false, $now - 601, 0, 300, 'overdue'],
    ['cron_module', true, $now - 601, $now - 1, 300, 'overdue'],
    ['cron_module', true, $now + 30, $now - 601, 300, 'disabled'],
    ['cron_module', true, $now + 30, $now + 1000, 300, 'disabled'],
    ['cron', true, 0, 0, 300, 'external'],
    ['cron_module', false, $now - 121, 0, 0, 'overdue'],
];
foreach ($cases as [$mode, $disabled, $next, $last, $interval, $expected]) {
    if (NCWooSchedulerHealth::status($mode, $disabled, $next, $last, $interval, $now) !== $expected) {
        throw new RuntimeException('Incorrect scheduler classification: ' . $expected);
    }
}
NCWooSchedulerHealth::record_tick();
if (abs($GLOBALS['tick'] - time()) > 1) { throw new RuntimeException('Heartbeat not recorded'); }
echo "12 scheduler health assertions passed.\n";
