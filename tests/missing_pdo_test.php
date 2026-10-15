<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require __DIR__ . '/../neurocheckout-connector/includes/community/WooSourceSnapshotFactory.php';
use NeuroCheckout\WooCommerce\Community\WooSourceSnapshotFactory;
if (WooSourceSnapshotFactory::hasPdoMysql()) { throw new RuntimeException('Run with php -n'); }
try {
    WooSourceSnapshotFactory::create(1, []);
    throw new LogicException('Missing dependency accepted');
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'source_dependency_missing_pdo_mysql') { throw $e; }
}
echo "Missing PDO dependency reports explicit diagnostic before native access.\n";
