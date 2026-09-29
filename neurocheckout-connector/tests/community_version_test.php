<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/community/WooSourceSnapshotFactory.php';
use NeuroCheckout\WooCommerce\Community\WooSourceSnapshotFactory;
foreach (['10.1.2', '10.7.0', '10.8.1'] as $version) {
    if (!WooSourceSnapshotFactory::supportsVersion($version)) { throw new RuntimeException('Supported version rejected'); }
}
foreach (['10.0.9', '10.9.0', '11.0.0', '10.7.0-beta', '10.7.0suffix', "10.7.0\n"] as $version) {
    if (WooSourceSnapshotFactory::supportsVersion($version)) { throw new RuntimeException('Unknown version accepted'); }
}
echo "9 native version guard assertions passed.\n";
