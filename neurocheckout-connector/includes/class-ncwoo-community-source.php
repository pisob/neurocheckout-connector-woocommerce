<?php

declare(strict_types=1);

use NeuroCheckout\WooCommerce\Community\SourcePullGateway;
use NeuroCheckout\WooCommerce\Community\ReconciledSourceExporter;
use NeuroCheckout\WooCommerce\Community\WooSourceSnapshotFactory;
use NeuroCheckout\WooCommerce\Community\AutomaticSourceBinding;

require_once __DIR__ . '/community/SourcePullProtocol.php';
require_once __DIR__ . '/community/SourcePullGateway.php';
require_once __DIR__ . '/community/BoundedPhpValueReader.php';
require_once __DIR__ . '/community/WooSessionProjection.php';
require_once __DIR__ . '/community/WooSourceSnapshot.php';
require_once __DIR__ . '/community/WooSourceSnapshotFactory.php';
require_once __DIR__ . '/community/ReconciledSourceExporter.php';
require_once __DIR__ . '/community/AutomaticSourceBinding.php';

final class NCWooCommunitySource
{
    private const ROUTE = '/neurocheckout/v1/communitydata';

    public static function registerRoutes(): void
    {
        register_rest_route('neurocheckout/v1', '/communitydata', [
            'methods' => 'POST',
            // Authentication is inside pull(), not WordPress user/cookie auth.
            'permission_callback' => '__return_true',
            'callback' => [self::class, 'pull'],
        ]);
    }

    public static function pull(WP_REST_Request $request): WP_REST_Response
    {
        $scope = (int) get_current_blog_id();
        $automatic = null;
        $stateDirectory = (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH)
            . '/uploads/neurocheckout-private-source/' . $scope;
        try {
            if (class_exists('NCWooConfig')) {
                $config = new NCWooConfig();
            }
            if (isset($config) && rtrim($config->get_string(NCWooConfig::OPTION_API_ENDPOINT), '/') === 'https://community-api-staging.neurocheckout.com'
                && $config->is_api_test_validation_current()) {
                $shopId = $config->get_shop_external_id();
                $automatic = ['enabled' => true, 'environment' => 'staging', 'nativeScope' => $scope,
                    'platform' => 'woocommerce', 'shopId' => $shopId,
                    'secret' => AutomaticSourceBinding::secret($config->get_api_key(), $shopId)];
            }
        } catch (\Throwable $error) {
            // Configuration/decryption failures must never expose a stack trace
            // or silently fall back to a different authentication binding.
            return new WP_REST_Response('{"error":"source_unavailable"}', 503,
                ['Content-Type'=>'application/json', 'Cache-Control'=>'no-store']);
        }
        [$status, $headers, $body] = SourcePullGateway::handle(
            'woocommerce', $scope, ABSPATH, $request->get_method(),
            (string) ($_SERVER['REQUEST_URI'] ?? ''), SourcePullGateway::serverHeaders($_SERVER),
            $request->get_body(), is_ssl(),
            static function ($input, $scope, $configuration, $directory): array {
                return (new ReconciledSourceExporter($directory, $configuration,
                    static function () use ($scope, $configuration): array {
                        return WooSourceSnapshotFactory::create($scope, $configuration)->capture($scope);
                    }))->page($input);
            }, $automatic, $stateDirectory
        );
        return new WP_REST_Response($body, $status, $headers);
    }

    public static function serveRaw(bool $served, $result, WP_REST_Request $request, WP_REST_Server $server): bool
    {
        if ($served || $request->get_route() !== self::ROUTE || !($result instanceof WP_REST_Response)
            || !is_string($result->get_data())) {
            return $served;
        }
        // This filter runs before the legacy gzip filter. Do not JSON-encode the
        // signed JSON string again; the client verifies these exact bytes.
        echo $result->get_data();
        return true;
    }
}
