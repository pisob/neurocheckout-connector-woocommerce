<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', '/synthetic-only/');
define('NCWOO_CONNECTOR_VERSION', '1.0.4');
class NCWooConfig {
    public const OPTION_API_ENDPOINT = 'endpoint';
    public function get_string($key) { return 'https://api.example.test'; }
    public function get_api_key() { return 'synthetic-test-key'; }
    public function get_pending_api_key() { return ''; }
    public function get_valid_previous_api_key() { return ''; }
}
function wp_json_encode($value) { return json_encode($value); }
function is_wp_error($value) { return false; }
function wp_remote_retrieve_response_code($value) { return $value['status']; }
function wp_remote_retrieve_body($value) { return $value['body']; }
function wp_remote_post($url, $args) {
    if ($url !== 'https://api.example.test/api/v1/connectors/version-check') {
        throw new RuntimeException('Unexpected endpoint');
    }
    $headers = $args['headers'];
    $expected = hash_hmac('sha256', $headers['X-Neuro-Timestamp'].'.'.$headers['X-Neuro-Nonce'].'.'.$args['body'], 'synthetic-test-key');
    if (!hash_equals($expected, $headers['X-Neuro-Signature'])) {
        throw new RuntimeException('Invalid request signature');
    }
    return $GLOBALS['response'];
}
require __DIR__.'/../neurocheckout-connector/includes/class-ncwoo-http-client.php';
$client = new NCWooHttpClient(new NCWooConfig());
foreach ([
    [200, '{"platform":"woocommerce","installed_version":"1.0.4"}', true],
    [200, '<html>Storefront</html>', false],
    [200, '{}', false],
    [200, '{"platform":"magento","installed_version":"1.0.4"}', false],
    [200, '{"platform":"woocommerce","installed_version":"1.0.3"}', false],
    [401, '{"detail":"Unauthorized"}', false],
    [404, '', false],
    [503, '', false],
] as [$status, $body, $success]) {
    $GLOBALS['response'] = compact('status', 'body');
    $result = $client->health();
    if ($result['success'] !== $success || $result['status'] !== $status) {
        throw new RuntimeException('Unexpected connection test result');
    }
}
echo "8 signed API connection checks passed.\n";
