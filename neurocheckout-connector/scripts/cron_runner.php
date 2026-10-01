#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$directory = __DIR__;
$wpLoad = '';
for ($i = 0; $i < 10; $i++) {
    if (is_file($directory . '/wp-load.php')) {
        $wpLoad = $directory . '/wp-load.php';
        break;
    }
    $parent = dirname($directory);
    if ($parent === $directory) { break; }
    $directory = $parent;
}
if ($wpLoad === '') {
    fwrite(STDERR, "Unable to find wp-load.php\n");
    exit(1);
}
// Server mode runs locally, without a public loopback or credentials in a URL.
require_once $wpLoad;
if (!class_exists('NCWooConfig') || !class_exists('NCWooSchedulerHealth')) {
    fwrite(STDERR, "The NeuroCheckout connector must be active and up to date.\n");
    exit(1);
}
$config = new NCWooConfig();
if ($config->get_execution_mode() !== 'cron') {
    fwrite(STDERR, "Select server execution mode before using this runner.\n");
    exit(1);
}
$readiness = $config->get_execution_readiness();
if (empty($readiness['ready'])) {
    fwrite(STDERR, "Complete connector setup and Test API before synchronization.\n");
    exit(1);
}
global $wpdb;
$lock = 'ncwoo_server_' . md5(DB_NAME . ':' . $wpdb->prefix);
if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) {
    fwrite(STDERR, "A connector runner is already active.\n");
    exit(1);
}
$exitCode = 1;
try {
    $db = new NCWooDB();
    $http = new NCWooHttpClient($config);
    NCWooSchedulerHealth::record_tick();
    $events = (new NCWooEventService($config, $db, $http))->process_queue();
    $journeys = (new NCWooCustomerJourneyService($config, $db, $http))->process_queue();
    $ok = !empty($events['success']) && !empty($journeys['success'])
        && empty($events['failed_events']) && empty($journeys['failed']);
    echo $ok ? "Synchronization cycle completed.\n" : "Synchronization incomplete; check connector Monitoring.\n";
    $exitCode = $ok ? 0 : 1;
} catch (Throwable $error) {
    fwrite(STDERR, "Synchronization failed; check connector Monitoring.\n");
} finally {
    $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
}
exit($exitCode);
