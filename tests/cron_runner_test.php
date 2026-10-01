<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = sys_get_temp_dir() . '/ncwoo-runner-' . bin2hex(random_bytes(8));
mkdir($root . '/plugin/scripts', 0700, true);
$runner = $root . '/plugin/scripts/cron_runner.php';
copy(__DIR__ . '/../neurocheckout-connector/scripts/cron_runner.php', $runner);
$bootstrap = <<<'PHP'
<?php
define('DB_NAME', 'synthetic');
$case = getenv('NCWOO_TEST_CASE');
class NCWooConfig {
    public function get_execution_mode() { return $GLOBALS['case'] === 'auto' ? 'cron_module' : 'cron'; }
    public function get_execution_readiness() { return ['ready' => $GLOBALS['case'] !== 'unready']; }
}
class NCWooSchedulerHealth {
    public static function record_tick() { echo "tick\n"; }
}
class NCWooDB {}
class NCWooHttpClient { public function __construct($config) {} }
class NCWooEventService {
    public function __construct(...$args) {}
    public function process_queue() {
        if ($GLOBALS['case'] === 'exception') { throw new RuntimeException('PRIVATE VALUE'); }
        echo "events\n";
        return ['success' => $GLOBALS['case'] !== 'failed', 'failed_events' => $GLOBALS['case'] === 'failed' ? 1 : 0];
    }
}
class NCWooCustomerJourneyService {
    public function __construct(...$args) {}
    public function process_queue() { echo "journeys\n"; return ['success'=>true,'failed'=>0]; }
}
$wpdb = new class {
    public $prefix = 'wp_';
    public function prepare($sql, ...$args) { return $sql; }
    public function get_var($sql) {
        if (str_contains($sql, 'RELEASE_LOCK')) { echo "released\n"; return 1; }
        return $GLOBALS['case'] === 'locked' ? 0 : 1;
    }
};
PHP;
file_put_contents($root . '/wp-load.php', $bootstrap);
try {
    foreach (['auto', 'unready', 'locked', 'success', 'failed', 'exception'] as $case) {
        putenv('NCWOO_TEST_CASE=' . $case);
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1', $output, $code);
        $text = implode("\n", $output);
        if ($code !== ($case === 'success' ? 0 : 1) || str_contains($text, 'PRIVATE VALUE')) {
            throw new RuntimeException('Runner exit or secrecy failure: ' . $case);
        }
        $shouldRun = in_array($case, ['success', 'failed', 'exception'], true);
        if (str_contains($text, 'tick') !== $shouldRun || str_contains($text, 'released') !== $shouldRun) {
            throw new RuntimeException('Runner gating or lock cleanup failure: ' . $case);
        }
        if ($case === 'success' && (!str_contains($text, 'events') || !str_contains($text, 'journeys'))) {
            throw new RuntimeException('Both queues must run');
        }
    }
    echo "6 isolated server runner scenarios passed.\n";
} finally {
    putenv('NCWOO_TEST_CASE');
    unlink($runner);
    unlink($root . '/wp-load.php');
    rmdir($root . '/plugin/scripts');
    rmdir($root . '/plugin');
    rmdir($root);
}
