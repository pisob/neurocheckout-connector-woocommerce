<?php

if (!defined('ABSPATH')) {
    exit;
}

final class NCWooAdmin
{
    private const PAGE_SLUG = 'ncwoo-connector';
    private const GZIP_DECODE_CHUNK_BYTES = 65536;

    private const TAB_GENERAL = 'general';
    private const TAB_IA = 'ia';
    private const TAB_EXECUTION = 'execution';
    private const TAB_MONITORING = 'monitoring';

    private NCWooConfig $config;

    public function __construct(NCWooConfig $config)
    {
        $this->config = $config;

        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_post_ncwoo_save_settings', [$this, 'handle_save_settings']);
        add_action('admin_post_ncwoo_test_api', [$this, 'handle_test_api']);
        add_action('admin_post_ncwoo_test_cron', [$this, 'handle_test_cron']);
        add_action('admin_post_ncwoo_force_cron', [$this, 'handle_force_cron']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('admin_init', [$this, 'maybe_refresh_connector_update']);
        add_action('admin_notices', [$this, 'render_connector_update_notice']);
    }

    public function maybe_refresh_connector_update(): void
    {
        if (!current_user_can('update_plugins')) {
            return;
        }
        $checkedAt = (int) get_option('ncwoo_connector_update_checked_at', 0);
        if ($checkedAt > 0 && (time() - $checkedAt) < DAY_IN_SECONDS) {
            return;
        }
        update_option('ncwoo_connector_update_checked_at', time(), false);
        $result = (new NCWooHttpClient($this->config))->check_connector_version();
        if (empty($result['success']) || !is_string($result['body'] ?? null)) {
            return;
        }
        $payload = json_decode($result['body'], true);
        if (!is_array($payload) || ($payload['platform'] ?? '') !== 'woocommerce') {
            return;
        }
        $url = esc_url_raw((string) ($payload['release_url'] ?? ''));
        $officialPrefix = 'https://github.com/pisob/neurocheckout-connector-woocommerce/releases';
        if (strpos($url, $officialPrefix) !== 0) {
            return;
        }
        update_option('ncwoo_connector_update_status', sanitize_key((string) ($payload['status'] ?? 'current')), false);
        update_option('ncwoo_connector_latest_version', sanitize_text_field((string) ($payload['latest_version'] ?? NCWOO_CONNECTOR_VERSION)), false);
        update_option('ncwoo_connector_release_url', $url, false);
    }

    public function render_connector_update_notice(): void
    {
        if (!current_user_can('update_plugins')) {
            return;
        }
        $status = (string) get_option('ncwoo_connector_update_status', 'current');
        if (!in_array($status, ['available', 'required', 'blocked'], true)) {
            return;
        }
        $latest = (string) get_option('ncwoo_connector_latest_version', '');
        $url = (string) get_option('ncwoo_connector_release_url', '');
        $class = $status === 'available' ? 'notice notice-warning' : 'notice notice-error';
        echo '<div class="' . esc_attr($class) . '"><p>';
        echo esc_html(sprintf('NeuroCheckout Connector %s is available. Back up your store and upload the official ZIP over the installed plugin. Do not uninstall it; configuration and data will be preserved.', $latest));
        echo ' <a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">Download official update</a>';
        echo '</p></div>';
    }

    public function register_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            'NeuroCheckout Connector',
            'NeuroCheckout',
            'manage_woocommerce',
            self::PAGE_SLUG,
            [$this, 'render_settings_page']
        );
    }

    public function handle_save_settings(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }

        check_admin_referer('ncwoo_save_settings');

        $tab = $this->resolve_tab($this->posted_text('tab', self::TAB_GENERAL));

        try {
            $this->config->ensure_defaults();
            $this->persist_configuration_from_request($tab);
            $this->redirect_with_notice($tab, 'success', 'Configuration saved.');
        } catch (InvalidArgumentException $e) {
            $this->redirect_with_notice($tab, 'error', $e->getMessage());
        } catch (Throwable $e) {
            $this->redirect_with_notice($tab, 'error', 'Unable to save configuration.');
        }
    }

    public function handle_test_api(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }

        check_admin_referer('ncwoo_test_api');

        $tab = self::TAB_GENERAL;

        try {
            $this->config->ensure_defaults();
            $result = $this->run_api_test();

            if (!empty($result['success'])) {
                $this->config->mark_api_test_validation_success();
                $this->redirect_with_notice($tab, 'success', 'API test successful (HTTP ' . (int) ($result['status'] ?? 200) . ').');
            }

            $error = trim((string) ($result['error'] ?? 'API test failed'));
            $status = (int) ($result['status'] ?? 0);
            $suffix = $status > 0 ? ' (HTTP ' . $status . ')' : '';

            $this->redirect_with_notice($tab, 'error', $error . $suffix);
        } catch (Throwable $e) {
            $this->redirect_with_notice($tab, 'error', 'API test error: ' . $e->getMessage());
        }
    }

    public function handle_test_cron(): void
    {
        $this->handle_cron_execution_request(
            'ncwoo_test_cron',
            true,
            false,
            NCWooConfig::OPTION_DEBUG_MODE,
            'debug_mode_disabled'
        );
    }

    public function handle_force_cron(): void
    {
        $this->handle_cron_execution_request(
            'ncwoo_force_cron',
            false,
            true,
            NCWooConfig::OPTION_DEBUG_ADVANCED,
            'advanced_debug_mode_disabled'
        );
    }

    public function register_rest_routes(): void
    {
        register_rest_route(
            'neurocheckout/v1',
            '/support/case',
            [
                'methods' => 'POST',
                'callback' => [$this, 'handle_support_case_route'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            'neurocheckout/v1',
            '/support/ticket',
            [
                'methods' => 'POST',
                'callback' => [$this, 'handle_support_case_route'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * @return WP_REST_Response
     */
    public function handle_support_case_route(WP_REST_Request $request)
    {
        $this->config->ensure_defaults();

        $security = new NCWooSecurity($this->config, new NCWooDB());
        $auth = $security->validate_signed_request($request, 'support-case', true);
        if (empty($auth['success'])) {
            $status = (int) ($auth['status'] ?? 403);
            $error = (string) ($auth['error'] ?? 'Unauthorized');
            return $this->support_rest_response(false, $status, $error);
        }

        $decoded = $this->decode_support_json_payload($request, 2 * 1024 * 1024, 12 * 1024 * 1024);
        if (empty($decoded['success'])) {
            return $this->support_rest_response(
                false,
                (int) ($decoded['status'] ?? 422),
                (string) ($decoded['error'] ?? 'Invalid JSON payload')
            );
        }
        $payload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [];

        $normalized = $this->normalize_support_case_payload($payload);
        if (is_wp_error($normalized)) {
            $errorData = $normalized->get_error_data();
            $status = (int) (is_array($errorData) ? ($errorData['status'] ?? 0) : 0);
            if ($status <= 0) {
                $status = 422;
            }
            return $this->support_rest_response(false, $status, $normalized->get_error_message());
        }

        $http = new NCWooHttpClient($this->config);
        $result = $http->send_support_case_event($normalized);
        if (empty($result['success'])) {
            $status = (int) ($result['status'] ?? 0);
            if ($status <= 0) {
                $status = 502;
            }

            $error = (string) ($result['error'] ?? 'support_forward_failed');
            $data = [
                'forwarded' => false,
                'backend_response' => $this->decode_backend_response((string) ($result['body'] ?? '')),
            ];

            return $this->support_rest_response(false, $status, $error, $data);
        }

        $status = (int) ($result['status'] ?? 202);
        if ($status <= 0) {
            $status = 202;
        }

        $data = [
            'forwarded' => true,
            'event_id' => (string) ($normalized['event_id'] ?? ''),
            'event_type' => (string) ($normalized['event_type'] ?? ''),
            'backend_response' => $this->decode_backend_response((string) ($result['body'] ?? '')),
        ];

        return $this->support_rest_response(true, $status, '', $data);
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }

        $this->config->ensure_defaults();

        $tab = $this->resolve_tab(isset($_GET['tab']) ? (string) wp_unslash($_GET['tab']) : self::TAB_GENERAL);
        $notice = $this->read_notice();

        $apiEndpoint = $this->config->get_string(NCWooConfig::OPTION_API_ENDPOINT);
        $shopExternalId = $this->config->get_shop_external_id();
        $apiKeyConfigured = $this->config->get_api_key() !== '';
        $iaReady = $this->config->is_ia_configuration_ready();
        $apiGateRequired = !$this->is_api_test_validation_current();
        $validatedAt = $this->config->get_int(NCWooConfig::OPTION_API_TEST_VALIDATED_AT, 0);
        $validatedAtDisplay = $validatedAt > 0 ? gmdate('Y-m-d H:i:s', $validatedAt) . ' UTC' : '';

        $executionMode = $this->config->get_execution_mode();
        $debugMode = $this->config->get_bool(NCWooConfig::OPTION_DEBUG_MODE, false);
        $debugAdvanced = $this->config->get_bool(NCWooConfig::OPTION_DEBUG_ADVANCED, false);
        $debugPanel = $this->config->get_bool(NCWooConfig::OPTION_DEBUG_PANEL, false) || $debugMode || $debugAdvanced;

        $cronIntervalSeconds = max(60, $this->config->get_int(NCWooConfig::OPTION_CRON_INTERVAL_SECONDS, 300));
        $cronIntervalMinutes = (int) ceil($cronIntervalSeconds / 60);
        $cronRunnerPath = trailingslashit(dirname(__DIR__)) . 'scripts/cron_runner.php';
        $cronRunnerCommand = sprintf(
            '*/%d * * * * php %s >/dev/null 2>&1',
            $cronIntervalMinutes,
            escapeshellarg($cronRunnerPath)
        );

        $generalTabUrl = $this->page_url(self::TAB_GENERAL);
        $iaTabUrl = $this->page_url(self::TAB_IA);
        $executionTabUrl = $this->page_url(self::TAB_EXECUTION);
        $monitoringTabUrl = $this->page_url(self::TAB_MONITORING);

        $i18n = $this->build_admin_i18n_bundle();
        $jsI18n = wp_json_encode($i18n);
        if (!is_string($jsI18n) || $jsI18n === '') {
            $jsI18n = '{}';
        }

        $pageUrl = $this->page_url($tab);
        $monitoring = $this->get_monitoring_snapshot();
        $brandMarkUrl = plugins_url('assets/connector-mark.svg', dirname(__DIR__) . '/neurocheckout-connector.php');
        ?>
        <div class="wrap" id="ncwoo-admin-root" data-ia-tab-url="<?php echo esc_url($iaTabUrl); ?>">
            <style>
                #ncwoo-admin-root .ncwoo-header {
                    display: flex;
                    align-items: center;
                    gap: 14px;
                    margin: 10px 0 16px;
                }
                #ncwoo-admin-root .ncwoo-header-mark {
                    width: 52px;
                    height: 52px;
                    border-radius: 16px;
                    box-shadow: 0 12px 28px rgba(17, 49, 78, 0.14);
                    flex: 0 0 auto;
                }
                #ncwoo-admin-root .ncwoo-header-copy {
                    min-width: 0;
                }
                #ncwoo-admin-root .ncwoo-header-copy h1 {
                    margin: 0;
                    line-height: 1.1;
                }
                #ncwoo-admin-root .ncwoo-header-kicker {
                    display: inline-flex;
                    margin-bottom: 6px;
                    font-size: 11px;
                    font-weight: 700;
                    letter-spacing: 0.14em;
                    text-transform: uppercase;
                    color: #2b7498;
                }
                #ncwoo-admin-root .ncwoo-header-subtitle {
                    margin: 4px 0 0;
                    color: #5b6784;
                }
                #ncwoo-debug-exclusive-warning {
                    display: none;
                    margin-top: 10px;
                    max-width: 920px;
                }
                #ncwoo-debug-options-panel {
                    display: none;
                    max-width: 960px;
                    margin-top: 12px;
                    padding: 14px;
                    border: 1px solid #d4dcea;
                    border-radius: 8px;
                    background: #f8fafc;
                }
                #ncwoo-debug-options-panel label {
                    display: block;
                    margin: 0 0 8px;
                    font-weight: 600;
                }
                #ncwoo-debug-options-panel .description {
                    margin: -4px 0 12px 24px;
                }
                #ncwoo-server-cron-panel {
                    display: none;
                    max-width: 960px;
                    margin: 14px 0 0;
                    padding: 14px 16px;
                    border-left: 4px solid #2271b1;
                    background: #f0f6fc;
                    color: #1d2327;
                }
                #ncwoo-server-cron-panel code {
                    display: block;
                    margin: 10px 0;
                    padding: 10px 12px;
                    white-space: pre-wrap;
                    word-break: break-word;
                    background: #ffffff;
                    border: 1px solid #c3d9ed;
                }
                .ncwoo-debug-dependent {
                    display: none;
                }
                #ncwoo-force-confirm-modal,
                #ncwoo-ia-onboarding-popup {
                    position: fixed;
                    inset: 0;
                    z-index: 10000;
                    display: none;
                    align-items: center;
                    justify-content: center;
                    background: rgba(17, 25, 40, 0.55);
                    padding: 16px;
                }
                #ncwoo-force-confirm-card,
                #ncwoo-ia-onboarding-card {
                    width: 100%;
                    max-width: 560px;
                    background: #ffffff;
                    border: 1px solid #d1d9e2;
                    border-radius: 10px;
                    box-shadow: 0 22px 48px rgba(15, 23, 42, 0.28);
                    padding: 18px;
                }
                #ncwoo-ia-onboarding-card h3 {
                    margin-top: 0;
                    margin-bottom: 8px;
                }
                #ncwoo-ia-onboarding-card ul {
                    margin: 10px 0 16px 20px;
                }
                #ncwoo-ia-onboarding-actions {
                    display: flex;
                    gap: 8px;
                    flex-wrap: wrap;
                }
            </style>

            <div class="ncwoo-header">
                <img class="ncwoo-header-mark" src="<?php echo esc_url($brandMarkUrl); ?>" alt="NeuroCheckout">
                <div class="ncwoo-header-copy">
                    <span class="ncwoo-header-kicker">NeuroCheckout Connector</span>
                    <h1>NeuroCheckout Connector</h1>
                    <p class="ncwoo-header-subtitle"><?php echo esc_html($i18n['header_subtitle']); ?></p>
                </div>
            </div>

            <?php if (is_array($notice)) : ?>
                <div class="notice notice-<?php echo esc_attr((string) $notice['type']); ?> is-dismissible">
                    <p><?php echo esc_html((string) $notice['message']); ?></p>
                </div>
            <?php endif; ?>

            <h2 class="nav-tab-wrapper">
                <a id="ncwoo-tab-general" href="<?php echo esc_url($generalTabUrl); ?>" class="nav-tab <?php echo $tab === self::TAB_GENERAL ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($i18n['tab_general']); ?></a>
                <a id="ncwoo-tab-ia" href="<?php echo esc_url($iaTabUrl); ?>" class="nav-tab <?php echo $tab === self::TAB_IA ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($i18n['tab_ia']); ?></a>
                <a id="ncwoo-tab-execution" href="<?php echo esc_url($executionTabUrl); ?>" class="nav-tab <?php echo $tab === self::TAB_EXECUTION ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($i18n['tab_execution']); ?></a>
                <a id="ncwoo-tab-monitoring" href="<?php echo esc_url($monitoringTabUrl); ?>" class="nav-tab <?php echo $tab === self::TAB_MONITORING ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($i18n['tab_monitoring']); ?></a>
            </h2>

            <?php if ($tab === self::TAB_GENERAL) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="ncwoo_save_settings">
                    <input type="hidden" name="tab" value="<?php echo esc_attr(self::TAB_GENERAL); ?>">
                    <?php wp_nonce_field('ncwoo_save_settings'); ?>

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="ncwoo_api_endpoint"><?php echo esc_html($i18n['api_endpoint_label']); ?></label></th>
                                <td>
                                    <input type="url" id="ncwoo_api_endpoint" name="ncwoo_api_endpoint" class="regular-text" value="<?php echo esc_attr($apiEndpoint); ?>" placeholder="https://api.neurocheckout.ai">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="ncwoo_api_key"><?php echo esc_html($i18n['api_key_label']); ?></label></th>
                                <td>
                                    <input type="password" id="ncwoo_api_key" name="ncwoo_api_key" class="regular-text" value="" autocomplete="off" placeholder="<?php echo $apiKeyConfigured ? esc_attr($i18n['api_key_configured_placeholder']) : ''; ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="ncwoo_shop_external_id"><?php echo esc_html($i18n['shop_external_id_label']); ?></label></th>
                                <td>
                                    <input type="text" id="ncwoo_shop_external_id" name="ncwoo_shop_external_id" class="regular-text" value="<?php echo esc_attr($shopExternalId); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html($i18n['opaque_recovery_links_label']); ?></th>
                                <td>
                                    <label>
                                        <input type="hidden" name="ncwoo_opaque_recovery_links" value="0">
                                        <input type="checkbox" name="ncwoo_opaque_recovery_links" value="1" <?php checked($this->config->is_opaque_recovery_links_enabled()); ?>>
                                        <?php echo esc_html($i18n['opaque_recovery_links_help']); ?>
                                    </label>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div id="ncwoo-ia-required-notice" class="notice <?php echo $iaReady ? 'notice-success' : 'notice-warning'; ?> inline">
                        <p>
                            <?php echo esc_html($iaReady ? $i18n['ia_setup_ready_notice'] : $i18n['ia_setup_required_notice']); ?>
                        </p>
                    </div>

                    <div id="ncwoo-api-test-gate-notice" class="notice <?php echo $apiGateRequired ? 'notice-warning' : 'notice-success'; ?> inline">
                        <p>
                            <?php if ($apiGateRequired) : ?>
                                <?php echo esc_html($i18n['api_test_gate_required_notice']); ?>
                            <?php else : ?>
                                <?php echo esc_html($i18n['api_test_gate_ready_notice']); ?>
                                <?php if ($validatedAtDisplay !== '') : ?>
                                    <br>
                                    <small>
                                        <?php echo esc_html($i18n['api_test_gate_validated_at_label']); ?>:
                                        <?php echo esc_html($validatedAtDisplay); ?>
                                    </small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </p>
                    </div>

                    <?php submit_button($i18n['save_general_button']); ?>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                    <input type="hidden" name="action" value="ncwoo_test_api">
                    <?php wp_nonce_field('ncwoo_test_api'); ?>
                    <button
                        type="submit"
                        id="ncwoo-test-api-button"
                        class="button button-secondary"
                        <?php disabled(!$iaReady); ?>
                        <?php if (!$iaReady) : ?>
                            title="<?php echo esc_attr($i18n['ia_setup_required_popup']); ?>"
                        <?php endif; ?>
                    >
                        <?php echo esc_html($i18n['test_api_button']); ?>
                    </button>
                </form>
            <?php endif; ?>

            <?php if ($tab === self::TAB_IA) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="ncwoo_save_settings">
                    <input type="hidden" name="tab" value="<?php echo esc_attr(self::TAB_IA); ?>">
                    <?php wp_nonce_field('ncwoo_save_settings'); ?>

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php echo esc_html($i18n['recovery_enabled_label']); ?></th>
                                <td>
                                    <input type="hidden" name="ncwoo_recovery_enabled" value="1">
                                    <label>
                                        <input type="checkbox" checked disabled>
                                        <?php echo esc_html($i18n['recovery_forced_label']); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html($i18n['allow_discount_label']); ?></th>
                                <td>
                                    <input type="hidden" name="ncwoo_allow_discount" value="0">
                                    <label>
                                        <input type="checkbox" name="ncwoo_allow_discount" value="1" <?php checked($this->config->get_bool(NCWooConfig::OPTION_ALLOW_DISCOUNT, false)); ?>>
                                        <?php echo esc_html($i18n['allow_discount_help']); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="ncwoo_min_cart_total"><?php echo esc_html($i18n['min_cart_total_label']); ?></label></th>
                                <td>
                                    <input type="text" id="ncwoo_min_cart_total" name="ncwoo_min_cart_total" class="regular-text" value="<?php echo esc_attr($this->config->get_string(NCWooConfig::OPTION_MIN_CART_TOTAL)); ?>" required>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html($i18n['allow_guest_label']); ?></th>
                                <td>
                                    <input type="hidden" name="ncwoo_allow_guest" value="0">
                                    <label>
                                        <input type="checkbox" name="ncwoo_allow_guest" value="1" <?php checked($this->config->get_bool(NCWooConfig::OPTION_ALLOW_GUEST, false)); ?>>
                                        <?php echo esc_html($i18n['allow_guest_help']); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="ncwoo_no_discount_max"><?php echo esc_html($i18n['no_discount_max_label']); ?></label></th>
                                <td>
                                    <input type="text" id="ncwoo_no_discount_max" name="ncwoo_no_discount_max" class="regular-text" value="<?php echo esc_attr($this->config->get_string(NCWooConfig::OPTION_NO_DISCOUNT_MAX)); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="ncwoo_discount_5_min"><?php echo esc_html($i18n['discount_5_min_label']); ?></label></th>
                                <td>
                                    <input type="text" id="ncwoo_discount_5_min" name="ncwoo_discount_5_min" class="regular-text" value="<?php echo esc_attr($this->config->get_string(NCWooConfig::OPTION_DISCOUNT_5_MIN)); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="ncwoo_discount_5_max"><?php echo esc_html($i18n['discount_5_max_label']); ?></label></th>
                                <td>
                                    <input type="text" id="ncwoo_discount_5_max" name="ncwoo_discount_5_max" class="regular-text" value="<?php echo esc_attr($this->config->get_string(NCWooConfig::OPTION_DISCOUNT_5_MAX)); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="ncwoo_discount_10_min"><?php echo esc_html($i18n['discount_10_min_label']); ?></label></th>
                                <td>
                                    <input type="text" id="ncwoo_discount_10_min" name="ncwoo_discount_10_min" class="regular-text" value="<?php echo esc_attr($this->config->get_string(NCWooConfig::OPTION_DISCOUNT_10_MIN)); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="ncwoo_max_discount_percent"><?php echo esc_html($i18n['max_discount_percent_label']); ?></label></th>
                                <td>
                                    <input type="text" id="ncwoo_max_discount_percent" name="ncwoo_max_discount_percent" class="regular-text" value="<?php echo esc_attr($this->config->get_string(NCWooConfig::OPTION_MAX_DISCOUNT_PERCENT)); ?>" required>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <?php submit_button($i18n['save_ia_button']); ?>
                </form>
            <?php endif; ?>

            <?php if ($tab === self::TAB_EXECUTION) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="ncwoo_save_settings">
                    <input type="hidden" name="tab" value="<?php echo esc_attr(self::TAB_EXECUTION); ?>">
                    <?php wp_nonce_field('ncwoo_save_settings'); ?>

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php echo esc_html($i18n['execution_mode_title']); ?></th>
                                <td>
                                    <label style="display:block;margin-bottom:8px;">
                                        <input type="radio" name="ncwoo_execution_mode" value="cron_module" <?php checked($executionMode, 'cron_module'); ?> data-ncwoo-execution-mode>
                                        <?php echo esc_html($i18n['auto_sync_mode_label']); ?>
                                    </label>
                                    <label style="display:block;">
                                        <input type="radio" name="ncwoo_execution_mode" value="cron" <?php checked($executionMode, 'cron'); ?> data-ncwoo-execution-mode>
                                        <?php echo esc_html($i18n['server_sync_mode_label']); ?>
                                    </label>
                                    <div id="ncwoo-server-cron-panel">
                                        <strong><?php echo esc_html($i18n['server_cron_setup_title']); ?></strong>
                                        <p class="description"><?php echo esc_html($i18n['server_cron_setup_help']); ?></p>
                                        <code><?php echo esc_html($cronRunnerCommand); ?></code>
                                        <p class="description"><?php echo esc_html($i18n['server_cron_setup_note']); ?></p>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html($i18n['debug_panel_title']); ?></th>
                                <td>
                                    <input type="hidden" name="ncwoo_debug_panel" value="0">
                                    <label>
                                        <input type="checkbox" id="ncwoo-debug-panel-checkbox" name="ncwoo_debug_panel" value="1" <?php checked($debugPanel); ?>>
                                        <?php echo esc_html($i18n['debug_panel_label']); ?>
                                    </label>
                                    <p class="description"><?php echo esc_html($i18n['debug_panel_help']); ?></p>

                                    <div id="ncwoo-debug-options-panel" class="ncwoo-debug-dependent">
                                        <input type="hidden" name="ncwoo_debug_mode" value="0">
                                        <label>
                                            <input type="checkbox" id="ncwoo-debug-mode-checkbox" name="ncwoo_debug_mode" value="1" <?php checked($debugMode); ?>>
                                            <?php echo esc_html($i18n['debug_mode_label']); ?>
                                        </label>
                                        <p class="description"><?php echo esc_html($i18n['debug_mode_help']); ?></p>

                                        <input type="hidden" name="ncwoo_debug_advanced" value="0">
                                        <label>
                                            <input type="checkbox" id="ncwoo-debug-advanced-checkbox" name="ncwoo_debug_advanced" value="1" <?php checked($debugAdvanced); ?>>
                                            <?php echo esc_html($i18n['debug_advanced_label']); ?>
                                        </label>
                                        <p class="description"><?php echo esc_html($i18n['debug_advanced_help']); ?></p>

                                        <div id="ncwoo-debug-exclusive-warning" class="notice notice-warning inline">
                                            <p></p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="ncwoo_cron_allowed_ips"><?php echo esc_html($i18n['sync_allowed_ips_label']); ?></label></th>
                                <td>
                                    <input type="text" id="ncwoo_cron_allowed_ips" name="ncwoo_cron_allowed_ips" class="regular-text" value="<?php echo esc_attr($this->config->get_string(NCWooConfig::OPTION_CRON_ALLOWED_IPS)); ?>" placeholder="203.0.113.10, 10.0.0.0/24">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="ncwoo_trusted_proxy_ips"><?php echo esc_html($i18n['trusted_proxy_ips_label']); ?></label></th>
                                <td>
                                    <input type="text" id="ncwoo_trusted_proxy_ips" name="ncwoo_trusted_proxy_ips" class="regular-text" value="<?php echo esc_attr($this->config->get_string(NCWooConfig::OPTION_TRUSTED_PROXY_IPS)); ?>" placeholder="173.245.48.0/20, 103.21.244.0/22">
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <?php submit_button($i18n['save_execution_button']); ?>
                </form>

                <?php if ($debugMode || $debugAdvanced) : ?>
                    <hr>

                    <?php if ($debugMode) : ?>
                        <h3><?php echo esc_html($i18n['test_cron_title']); ?></h3>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:10px;">
                            <input type="hidden" name="action" value="ncwoo_test_cron">
                            <?php wp_nonce_field('ncwoo_test_cron'); ?>
                            <button type="submit" class="button button-secondary" id="ncwoo-test-cron-button">
                                <?php echo esc_html($i18n['test_cron_button']); ?>
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($debugAdvanced) : ?>
                        <h3><?php echo esc_html($i18n['force_execution_title']); ?></h3>
                        <button type="button" class="button button-secondary" id="ncwoo-force-cron-open-modal">
                            <?php echo esc_html($i18n['force_execution_button']); ?>
                        </button>

                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ncwoo-force-cron-form" style="display:none;">
                            <input type="hidden" name="action" value="ncwoo_force_cron">
                            <?php wp_nonce_field('ncwoo_force_cron'); ?>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($tab === self::TAB_MONITORING) : ?>
                <?php
                $healthScore = (int) ($monitoring['health_score'] ?? 0);
                $healthStatus = strtoupper((string) ($monitoring['health_status'] ?? 'unknown'));
                $healthStatusDisplay = strtoupper((string) ($i18n['health_status_' . strtolower($healthStatus)] ?? $healthStatus));
                $healthColor = '#0a7a31';
                if ($healthScore < 50) {
                    $healthColor = '#b42318';
                } elseif ($healthScore < 80) {
                    $healthColor = '#9a6700';
                }

                $backlog = (int) ($monitoring['backlog'] ?? 0);
                $pendingCount = (int) ($monitoring['pending_count'] ?? 0);
                $processingCount = (int) ($monitoring['processing_count'] ?? 0);
                $staleProcessingCount = (int) ($monitoring['stale_processing_count'] ?? 0);
                $errorRate = (float) ($monitoring['error_rate'] ?? 0.0);
                $avgLatency = (int) ($monitoring['avg_latency_ms'] ?? 0);
                $breakerState = (string) ($monitoring['circuit_breaker_state'] ?? 'closed');
                $breakerStateDisplay = strtoupper((string) ($i18n['breaker_state_' . $breakerState] ?? str_replace('_', ' ', $breakerState)));
                $breakerOpenSeconds = (int) ($monitoring['circuit_open_seconds'] ?? 0);
                $recentLogs = is_array($monitoring['recent_logs'] ?? null) ? $monitoring['recent_logs'] : [];
                $recentCoupons = is_array($monitoring['recent_coupons'] ?? null) ? $monitoring['recent_coupons'] : [];
                $recentRecovery = is_array($monitoring['recent_recovery_tokens'] ?? null) ? $monitoring['recent_recovery_tokens'] : [];
                ?>

                <h2><?php echo esc_html($i18n['system_health_title']); ?></h2>
                <p>
                    <span style="display:inline-block;padding:6px 10px;border-radius:999px;background:<?php echo esc_attr($healthColor); ?>;color:#fff;font-weight:600;">
                        <?php echo esc_html($healthStatusDisplay . ' - ' . $healthScore . '/100'); ?>
                    </span>
                </p>
                <div style="width:100%;max-width:980px;background:#eef2f7;border-radius:8px;overflow:hidden;height:14px;margin-bottom:16px;">
                    <div style="width:<?php echo esc_attr((string) max(0, min(100, $healthScore))); ?>%;background:<?php echo esc_attr($healthColor); ?>;height:14px;"></div>
                </div>

                <table class="widefat striped" style="max-width:980px;">
                    <thead>
                        <tr>
                            <th><?php echo esc_html($i18n['queue_backlog_header']); ?></th>
                            <th><?php echo esc_html($i18n['pending_header']); ?></th>
                            <th><?php echo esc_html($i18n['processing_header']); ?></th>
                            <th><?php echo esc_html($i18n['stale_processing_header']); ?></th>
                            <th><?php echo esc_html($i18n['error_rate_recent_header']); ?></th>
                            <th><?php echo esc_html($i18n['average_latency_header']); ?></th>
                            <th><?php echo esc_html($i18n['circuit_breaker_header']); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?php echo esc_html((string) $backlog); ?></td>
                            <td><?php echo esc_html((string) $pendingCount); ?></td>
                            <td><?php echo esc_html((string) $processingCount); ?></td>
                            <td><?php echo esc_html((string) $staleProcessingCount); ?></td>
                            <td><?php echo esc_html(number_format($errorRate, 2, '.', '')); ?>%</td>
                            <td><?php echo esc_html((string) $avgLatency); ?> ms</td>
                            <td>
                                <?php echo esc_html($breakerStateDisplay); ?>
                                <?php if ($breakerState === 'open' && $breakerOpenSeconds > 0) : ?>
                                    (<?php echo esc_html(sprintf($i18n['seconds_remaining_suffix'], $breakerOpenSeconds)); ?>)
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h3 style="margin-top:26px;"><?php echo esc_html($i18n['recent_sync_runs_title']); ?></h3>
                <?php if ($recentLogs) : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php echo esc_html($i18n['executed_at_header']); ?></th>
                                <th><?php echo esc_html($i18n['status_header']); ?></th>
                                <th><?php echo esc_html($i18n['processed_events_header']); ?></th>
                                <th><?php echo esc_html($i18n['latency_ms_header']); ?></th>
                                <th><?php echo esc_html($i18n['message_header']); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentLogs as $row) : ?>
                                <tr>
                                    <td><?php echo esc_html((string) ($row['executed_at'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($row['status'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ((int) ($row['processed_events'] ?? 0))); ?></td>
                                    <td><?php echo esc_html((string) ((int) ($row['execution_time_ms'] ?? 0))); ?></td>
                                    <td><?php echo esc_html((string) ($row['error_message'] ?? '-')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p><?php echo esc_html($i18n['no_sync_runs_notice']); ?></p>
                <?php endif; ?>

                <h3 style="margin-top:26px;"><?php echo esc_html($i18n['recent_coupons_title']); ?></h3>
                <?php if ($recentCoupons) : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php echo esc_html($i18n['created_at_header']); ?></th>
                                <th><?php echo esc_html($i18n['email_header']); ?></th>
                                <th><?php echo esc_html($i18n['cart_header']); ?></th>
                                <th><?php echo esc_html($i18n['coupon_header']); ?></th>
                                <th><?php echo esc_html($i18n['discount_percent_header']); ?></th>
                                <th><?php echo esc_html($i18n['expires_at_header']); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentCoupons as $row) : ?>
                                <tr>
                                    <td><?php echo esc_html((string) ($row['created_at'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($row['customer_email'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($row['cart_id'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($row['coupon_code'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($row['discount_percent'] ?? '0')); ?></td>
                                    <td><?php echo esc_html((string) ($row['expires_at'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p><?php echo esc_html($i18n['no_recent_coupons']); ?></p>
                <?php endif; ?>

                <h3 style="margin-top:26px;"><?php echo esc_html($i18n['recent_recovery_links_title']); ?></h3>
                <?php if ($recentRecovery) : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php echo esc_html($i18n['created_at_header']); ?></th>
                                <th><?php echo esc_html($i18n['email_header']); ?></th>
                                <th><?php echo esc_html($i18n['cart_header']); ?></th>
                                <th><?php echo esc_html($i18n['coupon_header']); ?></th>
                                <th><?php echo esc_html($i18n['status_header']); ?></th>
                                <th><?php echo esc_html($i18n['expires_at_header']); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentRecovery as $row) : ?>
                                <tr>
                                    <td><?php echo esc_html((string) ($row['created_at'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($row['customer_email'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($row['cart_id'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($row['coupon_code'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($row['status'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($row['expires_at'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p><?php echo esc_html($i18n['no_recent_recovery_links']); ?></p>
                <?php endif; ?>
            <?php endif; ?>

            <div id="ncwoo-force-confirm-modal" role="dialog" aria-modal="true" aria-hidden="true">
                <div id="ncwoo-force-confirm-card">
                    <h3 style="margin-top:0;"><?php echo esc_html($i18n['confirmation_required_title']); ?></h3>
                    <p><?php echo esc_html($i18n['confirm_force_execution_body']); ?></p>
                    <p style="margin-bottom:0;">
                        <button type="button" class="button button-primary" id="ncwoo-force-confirm-accept">
                            <?php echo esc_html($i18n['confirm_force_execution_accept']); ?>
                        </button>
                        <button type="button" class="button" id="ncwoo-force-confirm-cancel" style="margin-left:8px;">
                            <?php echo esc_html($i18n['cancel_button']); ?>
                        </button>
                    </p>
                </div>
            </div>

            <div id="ncwoo-ia-onboarding-popup" role="dialog" aria-modal="true" aria-hidden="true">
                <div id="ncwoo-ia-onboarding-card">
                    <p style="margin:0 0 6px;"><strong><?php echo esc_html($i18n['ia_onboarding_popup_badge']); ?></strong></p>
                    <h3><?php echo esc_html($i18n['ia_onboarding_popup_title']); ?></h3>
                    <p><?php echo esc_html($i18n['ia_onboarding_popup_body']); ?></p>
                    <ul>
                        <li><?php echo esc_html($i18n['min_cart_total_label']); ?></li>
                        <li><?php echo esc_html($i18n['max_discount_percent_label']); ?></li>
                    </ul>
                    <div id="ncwoo-ia-onboarding-actions">
                        <button type="button" class="button button-primary" id="ncwoo-ia-onboarding-go">
                            <?php echo esc_html($i18n['ia_onboarding_popup_go_to_ia']); ?>
                        </button>
                        <button type="button" class="button" id="ncwoo-ia-onboarding-close">
                            <?php echo esc_html($i18n['ia_onboarding_popup_later']); ?>
                        </button>
                    </div>
                </div>
            </div>

            <p><a href="<?php echo esc_url($pageUrl); ?>"><?php echo esc_html($i18n['refresh_link']); ?></a></p>
        </div>
        <script>
            (function () {
                var i18n = <?php echo $jsI18n; ?>;
                var iaReadyFromServer = <?php echo $iaReady ? 'true' : 'false'; ?>;
                var currentTab = <?php echo wp_json_encode($tab); ?>;
                var root = document.getElementById('ncwoo-admin-root');

                function t(key, fallback) {
                    if (i18n && Object.prototype.hasOwnProperty.call(i18n, key)) {
                        return String(i18n[key] || '');
                    }
                    return fallback || key;
                }

                function getSessionFlag(key) {
                    try {
                        return window.sessionStorage && window.sessionStorage.getItem(key) === '1';
                    } catch (e) {
                        return false;
                    }
                }

                function setSessionFlag(key) {
                    try {
                        if (window.sessionStorage) {
                            window.sessionStorage.setItem(key, '1');
                        }
                    } catch (e) {
                        // Ignore storage failures in restricted browser contexts.
                    }
                }

                function clearSessionFlag(key) {
                    try {
                        if (window.sessionStorage) {
                            window.sessionStorage.removeItem(key);
                        }
                    } catch (e) {
                        // Ignore storage failures in restricted browser contexts.
                    }
                }

                function parseNumericValue(input) {
                    if (!input) {
                        return null;
                    }
                    var raw = String(input.value || '').trim().replace(',', '.');
                    if (raw === '') {
                        return null;
                    }
                    var value = Number(raw);
                    return Number.isFinite(value) ? value : null;
                }

                function isIaConfigurationComplete() {
                    var minCartInput = document.getElementById('ncwoo_min_cart_total');
                    var maxDiscountInput = document.getElementById('ncwoo_max_discount_percent');
                    var recoveryInput = document.querySelector('[name="ncwoo_recovery_enabled"]');

                    if (!minCartInput || !maxDiscountInput) {
                        return Boolean(iaReadyFromServer);
                    }

                    var minCart = parseNumericValue(minCartInput);
                    var maxDiscount = parseNumericValue(maxDiscountInput);
                    var hasRecovery = !recoveryInput || String(recoveryInput.value || '0') === '1';
                    var hasMinCart = minCart !== null && minCart >= 0;
                    var hasMaxDiscount = maxDiscount !== null && maxDiscount >= 0 && maxDiscount <= 100;

                    return hasRecovery && hasMinCart && hasMaxDiscount;
                }

                function refreshIaRequirementNotice() {
                    var notice = document.getElementById('ncwoo-ia-required-notice');
                    if (!notice) {
                        return;
                    }

                    var ready = isIaConfigurationComplete();
                    notice.className = 'notice inline ' + (ready ? 'notice-success' : 'notice-warning');

                    var paragraph = notice.querySelector('p');
                    if (paragraph) {
                        paragraph.textContent = ready
                            ? t('ia_setup_ready_notice', 'IA setup complete.')
                            : t('ia_setup_required_notice', 'Complete IA settings before testing API.');
                    }
                }

                function syncApiTestButtonState() {
                    var button = document.getElementById('ncwoo-test-api-button');
                    if (!button) {
                        return;
                    }

                    var ready = isIaConfigurationComplete();
                    button.disabled = !ready;
                    button.setAttribute('aria-disabled', ready ? 'false' : 'true');
                    if (!ready) {
                        button.title = t('ia_setup_required_popup', 'Complete IA settings before continuing.');
                    } else {
                        button.removeAttribute('title');
                    }
                }

                function goToIaTab() {
                    if (!root || !root.dataset || !root.dataset.iaTabUrl) {
                        return;
                    }
                    window.location.href = root.dataset.iaTabUrl;
                }

                function bindApiTestGuard() {
                    var button = document.getElementById('ncwoo-test-api-button');
                    if (!button) {
                        return;
                    }

                    button.addEventListener('click', function (event) {
                        if (isIaConfigurationComplete()) {
                            return;
                        }
                        event.preventDefault();
                        var message = t('ia_setup_required_popup', 'Complete IA settings before continuing.');
                        if (typeof window.alert === 'function') {
                            window.alert(message);
                        }
                        goToIaTab();
                    });
                }

                function bindIaWatchers() {
                    var watched = {
                        ncwoo_recovery_enabled: true,
                        ncwoo_min_cart_total: true,
                        ncwoo_max_discount_percent: true
                    };

                    var refresh = function (event) {
                        var target = event && event.target;
                        var name = target && target.name ? String(target.name) : '';
                        if (!watched[name]) {
                            return;
                        }
                        refreshIaRequirementNotice();
                        syncApiTestButtonState();
                        evaluateIaOnboardingPopup(false);
                    };

                    document.addEventListener('input', refresh, true);
                    document.addEventListener('change', refresh, true);
                }

                function bindExecutionModePanel() {
                    var radios = document.querySelectorAll('[data-ncwoo-execution-mode]');
                    var panel = document.getElementById('ncwoo-server-cron-panel');
                    if (!radios.length || !panel) {
                        return;
                    }

                    function syncPanel() {
                        var selected = document.querySelector('[data-ncwoo-execution-mode]:checked');
                        panel.style.display = selected && selected.value === 'cron' ? 'block' : 'none';
                    }

                    Array.prototype.forEach.call(radios, function (radio) {
                        radio.addEventListener('change', syncPanel);
                    });
                    syncPanel();
                }

                function bindDebugModeVisibility() {
                    var debugPanelCheckbox = document.getElementById('ncwoo-debug-panel-checkbox');
                    var debugModeCheckbox = document.getElementById('ncwoo-debug-mode-checkbox');
                    var debugAdvancedCheckbox = document.getElementById('ncwoo-debug-advanced-checkbox');
                    var dependentRows = document.querySelectorAll('.ncwoo-debug-dependent');
                    var warningBox = document.getElementById('ncwoo-debug-exclusive-warning');
                    var warningTimer = null;

                    if (!debugPanelCheckbox || !dependentRows.length) {
                        return;
                    }

                    function setWarning(message) {
                        var paragraph = warningBox ? warningBox.querySelector('p') : null;
                        if (warningTimer) {
                            clearTimeout(warningTimer);
                            warningTimer = null;
                        }

                        if (!warningBox || !paragraph) {
                            return;
                        }

                        if (!message) {
                            warningBox.style.display = 'none';
                            paragraph.textContent = '';
                            return;
                        }

                        warningBox.style.display = 'block';
                        paragraph.textContent = message;
                        warningTimer = setTimeout(function () {
                            warningBox.style.display = 'none';
                            paragraph.textContent = '';
                        }, 4500);
                    }

                    function syncDebugRows() {
                        var show = debugPanelCheckbox.checked;
                        Array.prototype.forEach.call(dependentRows, function (row) {
                            row.style.display = show ? 'block' : 'none';
                        });
                        if (!show) {
                            if (debugModeCheckbox) {
                                debugModeCheckbox.checked = false;
                            }
                            if (debugAdvancedCheckbox) {
                                debugAdvancedCheckbox.checked = false;
                            }
                            setWarning('');
                        }
                    }

                    function enforceExclusive(changed, other, message) {
                        if (!changed || !other) {
                            return;
                        }
                        if (changed.checked && other.checked) {
                            changed.checked = false;
                            setWarning(message);
                            return;
                        }
                        setWarning('');
                    }

                    if (debugModeCheckbox && debugAdvancedCheckbox && debugModeCheckbox.checked && debugAdvancedCheckbox.checked) {
                        debugAdvancedCheckbox.checked = false;
                    }

                    debugPanelCheckbox.addEventListener('change', syncDebugRows);
                    if (debugModeCheckbox) {
                        debugModeCheckbox.addEventListener('change', function () {
                            enforceExclusive(
                                debugModeCheckbox,
                                debugAdvancedCheckbox,
                                t('warning_debug_advanced_active', 'Forced synchronization mode is active. Disable it first to enable synchronization test mode.')
                            );
                        });
                    }
                    if (debugAdvancedCheckbox) {
                        debugAdvancedCheckbox.addEventListener('change', function () {
                            enforceExclusive(
                                debugAdvancedCheckbox,
                                debugModeCheckbox,
                                t('warning_debug_mode_active', 'Synchronization test mode is active. Disable it first to enable forced synchronization.')
                            );
                        });
                    }
                    syncDebugRows();
                }

                function bindForceExecutionModal() {
                    var openButton = document.getElementById('ncwoo-force-cron-open-modal');
                    var modal = document.getElementById('ncwoo-force-confirm-modal');
                    var cancelButton = document.getElementById('ncwoo-force-confirm-cancel');
                    var acceptButton = document.getElementById('ncwoo-force-confirm-accept');
                    var form = document.getElementById('ncwoo-force-cron-form');

                    if (!openButton || !modal || !form) {
                        return;
                    }

                    function hideModal() {
                        modal.style.display = 'none';
                        modal.setAttribute('aria-hidden', 'true');
                    }

                    function showModal() {
                        modal.style.display = 'flex';
                        modal.setAttribute('aria-hidden', 'false');
                    }

                    openButton.addEventListener('click', showModal);

                    if (cancelButton) {
                        cancelButton.addEventListener('click', hideModal);
                    }
                    if (acceptButton) {
                        acceptButton.addEventListener('click', function () {
                            hideModal();
                            form.submit();
                        });
                    }
                    modal.addEventListener('click', function (event) {
                        if (event.target === modal) {
                            hideModal();
                        }
                    });
                }

                function evaluateIaOnboardingPopup(allowShow) {
                    var popup = document.getElementById('ncwoo-ia-onboarding-popup');
                    if (!popup) {
                        return;
                    }

                    if (isIaConfigurationComplete()) {
                        popup.style.display = 'none';
                        popup.setAttribute('aria-hidden', 'true');
                        return;
                    }

                    var suppressedForThisLoad = getSessionFlag('ncwoo_ia_popup_skip_once');
                    if (suppressedForThisLoad) {
                        clearSessionFlag('ncwoo_ia_popup_skip_once');
                        return;
                    }

                    if (!allowShow) {
                        return;
                    }

                    if (currentTab === 'general' || currentTab === 'execution' || currentTab === 'ia') {
                        popup.style.display = 'flex';
                        popup.setAttribute('aria-hidden', 'false');
                    }
                }

                function bindIaOnboardingPopup() {
                    var popup = document.getElementById('ncwoo-ia-onboarding-popup');
                    var goButton = document.getElementById('ncwoo-ia-onboarding-go');
                    var closeButton = document.getElementById('ncwoo-ia-onboarding-close');

                    if (!popup) {
                        return;
                    }

                    function hidePopup() {
                        popup.style.display = 'none';
                        popup.setAttribute('aria-hidden', 'true');
                    }

                    if (goButton) {
                        goButton.addEventListener('click', function () {
                            setSessionFlag('ncwoo_ia_popup_skip_once');
                            hidePopup();
                            goToIaTab();
                        });
                    }

                    if (closeButton) {
                        closeButton.addEventListener('click', function () {
                            hidePopup();
                        });
                    }

                    popup.addEventListener('click', function (event) {
                        if (event.target === popup) {
                            hidePopup();
                        }
                    });
                }

                bindApiTestGuard();
                bindIaWatchers();
                bindExecutionModePanel();
                bindDebugModeVisibility();
                bindForceExecutionModal();
                bindIaOnboardingPopup();

                refreshIaRequirementNotice();
                syncApiTestButtonState();
                setTimeout(function () {
                    evaluateIaOnboardingPopup(true);
                }, 0);
            })();
        </script>
        <?php
    }

    private function handle_cron_execution_request(
        string $nonceAction,
        bool $isCronTest,
        bool $isDebugForceRun,
        string $requiredDebugOption,
        string $disabledErrorKey
    ): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }

        check_admin_referer($nonceAction);

        try {
            $this->config->ensure_defaults();
            if (!$this->config->get_bool($requiredDebugOption, false)) {
                $this->redirect_with_notice(self::TAB_EXECUTION, 'warning', $this->i18n($disabledErrorKey));
            }

            $events = new NCWooEventService(
                $this->config,
                new NCWooDB(),
                new NCWooHttpClient($this->config)
            );

            $result = $events->process_queue($isCronTest, $isDebugForceRun);
            $notice = $this->build_cron_execution_notice($result, $isCronTest, $isDebugForceRun);
            $this->redirect_with_notice(self::TAB_EXECUTION, $notice['type'], $notice['message']);
        } catch (Throwable $e) {
            $message = sprintf(
                $this->i18n('cron_execution_failed'),
                sanitize_text_field($e->getMessage())
            );
            $this->redirect_with_notice(self::TAB_EXECUTION, 'error', $message);
        }
    }

    /**
     * @param array<string,mixed> $result
     * @return array{type:string,message:string}
     */
    private function build_cron_execution_notice(array $result, bool $isCronTest, bool $isDebugForceRun): array
    {
        $processed = max(0, (int) ($result['processed_events'] ?? 0));
        $durationMs = max(0, (int) ($result['execution_time_ms'] ?? 0));
        $error = trim((string) ($result['error'] ?? ''));

        if (!empty($result['success'])) {
            $templateKey = $isDebugForceRun ? 'force_execution_success' : 'test_cron_success';
            return [
                'type' => 'success',
                'message' => sprintf($this->i18n($templateKey), $processed, $durationMs),
            ];
        }

        if ($error === 'no_pending_events') {
            return [
                'type' => 'warning',
                'message' => $this->i18n('cron_no_pending_events_notice'),
            ];
        }

        if ($error === 'cron_already_running') {
            return [
                'type' => 'warning',
                'message' => $this->i18n('cron_already_running'),
            ];
        }

        if (strpos($error, 'failed_events=') === 0) {
            return [
                'type' => 'error',
                'message' => sprintf($this->i18n('cron_failed_events_notice'), $error),
            ];
        }

        $translatedError = $this->translate_runtime_error_key($error);
        $templateKey = $isCronTest ? 'test_cron_failed' : 'force_execution_failed';

        return [
            'type' => 'error',
            'message' => sprintf($this->i18n($templateKey), $translatedError !== '' ? $translatedError : $this->i18n('internal_error')),
        ];
    }

    private function translate_runtime_error_key(string $error): string
    {
        $error = strtolower(trim($error));
        if ($error === '') {
            return '';
        }

        $mapping = [
            'missing_api_configuration' => 'api_configuration_incomplete',
            'missing_ia_configuration' => 'ia_configuration_incomplete',
            'api_test_gate_not_validated' => 'api_test_required_before_activation',
            'no_pending_events' => 'no_pending_events',
            'cron_already_running' => 'cron_already_running',
            'circuit_breaker_open' => 'circuit_breaker_open',
            'debug_mode_disabled' => 'debug_mode_disabled',
            'advanced_debug_mode_disabled' => 'advanced_debug_mode_disabled',
        ];

        $key = $mapping[$error] ?? $error;
        if (preg_match('/^[a-z0-9_]+$/', $key) === 1) {
            return $this->i18n($key);
        }

        return $error;
    }

    private function persist_configuration_from_request(string $tab): void
    {
        if ($tab === self::TAB_GENERAL) {
            $this->persist_general_configuration();
            return;
        }

        if ($tab === self::TAB_IA) {
            $this->persist_ia_configuration();
            return;
        }

        $this->persist_execution_configuration();
    }

    private function persist_general_configuration(): void
    {
        $previousApiEndpoint = $this->config->get_string(NCWooConfig::OPTION_API_ENDPOINT);
        $previousApiKey = $this->config->get_api_key();
        $previousShopExternalId = $this->config->get_shop_external_id();

        $endpoint = $this->posted_text('ncwoo_api_endpoint', '');
        if ($endpoint !== '') {
            $endpoint = esc_url_raw($endpoint);
            if ($endpoint === '' || !filter_var($endpoint, FILTER_VALIDATE_URL)) {
                throw new InvalidArgumentException('Invalid API endpoint URL.');
            }
            $endpoint = rtrim($endpoint, '/');
        }

        $shopExternalId = $this->posted_text('ncwoo_shop_external_id', '');
        if ($shopExternalId === '') {
            throw new InvalidArgumentException('Shop External ID is required.');
        }

        $apiKey = preg_replace('/\s+/', '', $this->posted_text('ncwoo_api_key', ''));
        $opaqueRecoveryLinks = $this->posted_bool('ncwoo_opaque_recovery_links');

        $this->config->set(NCWooConfig::OPTION_API_ENDPOINT, $endpoint);
        $this->config->set(NCWooConfig::OPTION_SHOP_EXTERNAL_ID, $shopExternalId);
        $this->config->set(NCWooConfig::OPTION_OPAQUE_RECOVERY_LINKS, $opaqueRecoveryLinks ? '1' : '0');

        if ($apiKey !== '') {
            $this->config->set(NCWooConfig::OPTION_API_KEY, $apiKey);
        }

        $currentApiEndpoint = $this->config->get_string(NCWooConfig::OPTION_API_ENDPOINT);
        $currentApiKey = $this->config->get_api_key();
        $currentShopExternalId = $this->config->get_shop_external_id();

        $apiSettingsChanged = (
            $previousApiEndpoint !== $currentApiEndpoint
            || $previousApiKey !== $currentApiKey
            || $previousShopExternalId !== $currentShopExternalId
        );

        if ($apiSettingsChanged) {
            $this->config->clear_api_test_validation_state();
            $this->config->set(NCWooConfig::OPTION_API_KEY_NEXT, '');
            $this->config->set(NCWooConfig::OPTION_API_KEY_ROTATION_ID, '');
        }
    }

    private function persist_ia_configuration(): void
    {
        $minCartTotal = $this->normalize_decimal_string(
            $this->posted_text('ncwoo_min_cart_total', ''),
            false,
            0.0,
            null,
            'Min Cart Total is required and must be >= 0.'
        );

        $maxDiscountPercent = $this->normalize_decimal_string(
            $this->posted_text('ncwoo_max_discount_percent', ''),
            false,
            0.0,
            100.0,
            'Max Discount Percent is required and must be between 0 and 100.'
        );

        $noDiscountMax = $this->normalize_decimal_string(
            $this->posted_text('ncwoo_no_discount_max', ''),
            true,
            0.0,
            null,
            'No Discount Max must be >= 0.'
        );

        $discount5Min = $this->normalize_decimal_string(
            $this->posted_text('ncwoo_discount_5_min', ''),
            true,
            0.0,
            null,
            'Discount 5% Min must be >= 0.'
        );

        $discount5Max = $this->normalize_decimal_string(
            $this->posted_text('ncwoo_discount_5_max', ''),
            true,
            0.0,
            null,
            'Discount 5% Max must be >= 0.'
        );

        $discount10Min = $this->normalize_decimal_string(
            $this->posted_text('ncwoo_discount_10_min', ''),
            true,
            0.0,
            null,
            'Discount 10% Min must be >= 0.'
        );

        $this->config->set(NCWooConfig::OPTION_RECOVERY_ENABLED, '1');
        $this->config->set(NCWooConfig::OPTION_ALLOW_DISCOUNT, $this->posted_bool('ncwoo_allow_discount') ? '1' : '0');
        $this->config->set(NCWooConfig::OPTION_ALLOW_GUEST, $this->posted_bool('ncwoo_allow_guest') ? '1' : '0');

        $this->config->set(NCWooConfig::OPTION_MIN_CART_TOTAL, $minCartTotal);
        $this->config->set(NCWooConfig::OPTION_NO_DISCOUNT_MAX, $noDiscountMax);
        $this->config->set(NCWooConfig::OPTION_DISCOUNT_5_MIN, $discount5Min);
        $this->config->set(NCWooConfig::OPTION_DISCOUNT_5_MAX, $discount5Max);
        $this->config->set(NCWooConfig::OPTION_DISCOUNT_10_MIN, $discount10Min);
        $this->config->set(NCWooConfig::OPTION_MAX_DISCOUNT_PERCENT, $maxDiscountPercent);
    }

    private function persist_execution_configuration(): void
    {
        $executionMode = $this->posted_text('ncwoo_execution_mode', 'cron_module');
        if (!in_array($executionMode, ['cron_module', 'cron'], true)) {
            $executionMode = 'cron_module';
        }

        $debugPanel = $this->posted_bool('ncwoo_debug_panel');
        $debugMode = $debugPanel && $this->posted_bool('ncwoo_debug_mode');
        $debugAdvanced = $debugPanel && $this->posted_bool('ncwoo_debug_advanced');

        if ($debugMode && $debugAdvanced) {
            $debugAdvanced = false;
        }

        $currentCronIntervalSeconds = max(60, $this->config->get_int(NCWooConfig::OPTION_CRON_INTERVAL_SECONDS, 300));
        $currentCronIntervalMinutes = (int) ceil($currentCronIntervalSeconds / 60);
        $cronIntervalMinutes = $this->posted_int('ncwoo_cron_interval_minutes', $currentCronIntervalMinutes);
        $cronIntervalMinutes = max(1, min(60, $cronIntervalMinutes));

        $autoHookInterval = $this->posted_int(
            'ncwoo_auto_hook_interval',
            $this->config->get_int(NCWooConfig::OPTION_AUTO_HOOK_INTERVAL, 300)
        );
        $autoHookInterval = max(10, min(3600, $autoHookInterval));

        $cronAllowedIps = $this->sanitize_ip_list($this->posted_text('ncwoo_cron_allowed_ips', ''));
        $trustedProxyIps = $this->sanitize_ip_list($this->posted_text('ncwoo_trusted_proxy_ips', ''));

        $this->config->set(NCWooConfig::OPTION_EXECUTION_MODE, $executionMode);
        $this->config->set(NCWooConfig::OPTION_DEBUG_PANEL, $debugPanel ? '1' : '0');
        $this->config->set(NCWooConfig::OPTION_DEBUG_MODE, $debugMode ? '1' : '0');
        $this->config->set(NCWooConfig::OPTION_DEBUG_ADVANCED, $debugAdvanced ? '1' : '0');
        $this->config->set(NCWooConfig::OPTION_CRON_INTERVAL_SECONDS, $cronIntervalMinutes * 60);
        $this->config->set(NCWooConfig::OPTION_AUTO_HOOK_INTERVAL, $autoHookInterval);
        $this->config->set(NCWooConfig::OPTION_CRON_ALLOWED_IPS, $cronAllowedIps);
        $this->config->set(NCWooConfig::OPTION_TRUSTED_PROXY_IPS, $trustedProxyIps);
    }

    /**
     * @return array{success:bool,status:int,error:string,health:array<string,mixed>,event_probe:array<string,mixed>|null}
     */
    private function run_api_test(): array
    {
        $apiEndpoint = $this->config->get_string(NCWooConfig::OPTION_API_ENDPOINT);
        $apiKey = $this->config->get_api_key();
        $shopExternalId = $this->config->get_shop_external_id();

        $iaReady = $this->config->is_ia_configuration_ready();
        $baseConfigReady = ($apiEndpoint !== '' && $apiKey !== '' && $shopExternalId !== '');

        $http = new NCWooHttpClient($this->config);
        $health = $http->health([
            'source' => ['shop_id' => $shopExternalId],
        ]);

        $probe = null;
        $probeOk = true;

        if (!empty($health['success']) && $baseConfigReady && $iaReady) {
            $probe = $http->send_cart_event($this->build_api_test_event_payload($shopExternalId), ['is_api_test' => true]);
            $probeOk = !empty($probe['success']);
        }

        $success = $baseConfigReady && $iaReady && !empty($health['success']) && $probeOk;

        if ($success) {
            return [
                'success' => true,
                'status' => 200,
                'error' => '',
                'health' => $health,
                'event_probe' => $probe,
            ];
        }

        if (!$baseConfigReady) {
            return [
                'success' => false,
                'status' => 422,
                'error' => 'General API configuration incomplete.',
                'health' => $health,
                'event_probe' => $probe,
            ];
        }

        if (!$iaReady) {
            return [
                'success' => false,
                'status' => 422,
                'error' => 'IA configuration incomplete (required fields missing/invalid).',
                'health' => $health,
                'event_probe' => $probe,
            ];
        }

        return [
            'success' => false,
            'status' => (int) ($health['status'] ?? 422),
            'error' => (string) ($health['error'] ?? 'API check failed'),
            'health' => $health,
            'event_probe' => $probe,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function build_api_test_event_payload(string $shopRef): array
    {
        $suffix = bin2hex(random_bytes(6));
        $cartRef = 'api-test-' . $shopRef . '-' . $suffix;

        return [
            'event_id' => 'test-' . $suffix,
            'event_type' => 'cart.updated',
            'occurred_at' => gmdate('c'),
            'source' => [
                'platform' => 'woocommerce',
                'shop_id' => $shopRef,
                'shop_name' => get_bloginfo('name'),
            ],
            'cart' => [
                'id' => $cartRef,
                'uid' => $cartRef,
                'total' => 99.0,
                'items' => [
                    [
                        'product_id' => 999001,
                        'attribute_id' => 0,
                        'quantity' => 1,
                        'unit_price' => 99.0,
                        'name' => 'NeuroCheckout API Test Item',
                    ],
                ],
            ],
            'customer' => [
                'id' => 'api-test',
                'email' => 'apitest+' . $shopRef . '@neurocheckout.local',
                'first_name' => 'API',
                'last_name' => 'Test',
                'locale' => function_exists('get_locale') ? (string) get_locale() : 'en_US',
                'is_guest' => true,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|WP_Error
     */
    private function normalize_support_case_payload(array $payload)
    {
        $support = is_array($payload['support'] ?? null) ? $payload['support'] : [];
        $customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
        $order = is_array($payload['order'] ?? null) ? $payload['order'] : [];
        $source = is_array($payload['source'] ?? null) ? $payload['source'] : [];
        $message = is_array($payload['message'] ?? null) ? $payload['message'] : [];

        $messageText = $this->first_non_empty_text([
            $support['message_text'] ?? null,
            $payload['message_text'] ?? null,
            $message['text'] ?? null,
            $payload['message'] ?? null,
            $payload['body'] ?? null,
        ]);
        if ($messageText === '') {
            return new WP_Error('ncwoo_support_invalid_payload', 'message_text is required', ['status' => 422]);
        }

        $caseId = $this->first_non_empty_text([
            $support['case_id'] ?? null,
            $payload['case_id'] ?? null,
            $payload['ticket_id'] ?? null,
        ]);
        $eventType = strtolower($this->first_non_empty_text([
            $payload['event_type'] ?? null,
            $support['event_type'] ?? null,
        ]));
        if ($eventType === '') {
            $eventType = $caseId !== '' ? 'support.case_updated' : 'support.case_opened';
        }
        if (!in_array($eventType, ['support.case_opened', 'support.case_updated'], true)) {
            return new WP_Error('ncwoo_support_invalid_payload', 'Unsupported support event_type', ['status' => 422]);
        }

        $externalCaseId = $this->first_non_empty_text([
            $support['external_case_id'] ?? null,
            $payload['external_case_id'] ?? null,
            $payload['ticket_reference'] ?? null,
        ]);
        if ($externalCaseId === '' && $caseId !== '') {
            $externalCaseId = $caseId;
        }

        $externalOrderId = $this->first_non_empty_text([
            $support['external_order_id'] ?? null,
            $payload['external_order_id'] ?? null,
            $payload['order_id'] ?? null,
            $order['id'] ?? null,
            $order['reference'] ?? null,
            $order['increment_id'] ?? null,
        ]);

        $externalCustomerId = $this->first_non_empty_text([
            $support['external_customer_id'] ?? null,
            $payload['external_customer_id'] ?? null,
            $payload['customer_id'] ?? null,
            $customer['id'] ?? null,
        ]);

        $customerEmail = $this->first_non_empty_text([
            $support['customer_email'] ?? null,
            $payload['customer_email'] ?? null,
            $customer['email'] ?? null,
        ]);

        $channel = strtolower($this->first_non_empty_text([
            $support['channel'] ?? null,
            $payload['channel'] ?? null,
            $source['channel'] ?? null,
        ]));
        if ($channel === '') {
            $channel = 'api';
        }

        $priorityHint = strtolower($this->first_non_empty_text([
            $support['priority_hint'] ?? null,
            $payload['priority_hint'] ?? null,
            $payload['priority'] ?? null,
        ]));
        if ($priorityHint === '') {
            $priorityHint = 'normal';
        }

        $shopRef = $this->config->get_shop_external_id();
        if ($shopRef === '') {
            $shopRef = 'woo-shop';
        }

        $eventId = $this->first_non_empty_text([
            $payload['event_id'] ?? null,
            $support['event_id'] ?? null,
        ]);
        if ($eventId === '') {
            $eventId = 'woo-support-' . wp_generate_uuid4();
        }

        $occurredAt = $this->first_non_empty_text([
            $payload['occurred_at'] ?? null,
            $support['occurred_at'] ?? null,
        ]);
        if ($occurredAt === '') {
            $occurredAt = gmdate('c');
        }

        $metadata = [];
        if (is_array($payload['metadata'] ?? null)) {
            $metadata = $payload['metadata'];
        }
        if (is_array($support['metadata'] ?? null)) {
            $metadata = array_merge($metadata, $support['metadata']);
        }

        $source = array_merge(
            $source,
            [
                'platform' => 'woocommerce',
                'shop_id' => $shopRef,
                'shop_name' => get_bloginfo('name'),
            ]
        );

        $normalized = [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'occurred_at' => $occurredAt,
            'source' => $source,
            'support' => [
                'message_text' => $messageText,
                'channel' => $channel,
                'priority_hint' => $priorityHint,
            ],
            'message_text' => $messageText,
            'channel' => $channel,
            'priority_hint' => $priorityHint,
            'customer' => $customer,
            'order' => $order,
            'metadata' => $metadata,
        ];

        if ($caseId !== '') {
            $normalized['case_id'] = $caseId;
            $normalized['support']['case_id'] = $caseId;
        }
        if ($externalCaseId !== '') {
            $normalized['external_case_id'] = $externalCaseId;
            $normalized['support']['external_case_id'] = $externalCaseId;
        }
        if ($externalOrderId !== '') {
            $normalized['external_order_id'] = $externalOrderId;
            $normalized['support']['external_order_id'] = $externalOrderId;
            if (!isset($normalized['order']['id'])) {
                $normalized['order']['id'] = $externalOrderId;
            }
        }
        if ($externalCustomerId !== '') {
            $normalized['external_customer_id'] = $externalCustomerId;
            $normalized['support']['external_customer_id'] = $externalCustomerId;
            if (!isset($normalized['customer']['id'])) {
                $normalized['customer']['id'] = $externalCustomerId;
            }
        }
        if ($customerEmail !== '') {
            $normalized['customer_email'] = $customerEmail;
            $normalized['support']['customer_email'] = $customerEmail;
            if (!isset($normalized['customer']['email'])) {
                $normalized['customer']['email'] = $customerEmail;
            }
        }

        return $normalized;
    }

    private function first_non_empty_text(array $values): string
    {
        foreach ($values as $value) {
            if (is_scalar($value)) {
                $candidate = trim((string) $value);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return '';
    }

    /**
     * @return array<string,mixed>|string
     */
    private function decode_backend_response(string $body)
    {
        $body = trim($body);
        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return $body;
    }

    /**
     * @param array<string,mixed> $data
     * @return WP_REST_Response
     */
    private function support_rest_response(bool $success, int $status, string $error = '', array $data = [])
    {
        $status = max(100, min(599, $status));
        if ($success) {
            $error = '';
        }

        return new WP_REST_Response(
            [
                'success' => $success,
                'status' => $status,
                'error' => $error,
                'timestamp' => gmdate('c'),
                'data' => $data,
            ],
            $status
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function decode_support_json_payload(WP_REST_Request $request, int $maxRawBytes, int $maxDecodedBytes): array
    {
        $rawBody = (string) $request->get_body();
        if (strlen($rawBody) > $maxRawBytes) {
            return ['success' => false, 'status' => 413, 'error' => 'payload_too_large'];
        }

        $decodedBody = $rawBody;
        $encoding = strtolower(trim((string) $request->get_header('Content-Encoding')));
        if ($encoding !== '' && strpos($encoding, 'gzip') !== false) {
            $decodedGzip = $this->decode_support_gzip_body($rawBody, $maxDecodedBytes);
            if (empty($decodedGzip['success'])) {
                return [
                    'success' => false,
                    'status' => (int) ($decodedGzip['status'] ?? 400),
                    'error' => (string) ($decodedGzip['error'] ?? 'Invalid gzip body'),
                ];
            }

            $decodedBody = (string) ($decodedGzip['body'] ?? '');
        } elseif (strlen($decodedBody) > $maxDecodedBytes) {
            return ['success' => false, 'status' => 413, 'error' => 'payload_too_large'];
        }

        $payload = json_decode($decodedBody, true);
        if (!is_array($payload)) {
            return ['success' => false, 'status' => 422, 'error' => 'Invalid JSON payload'];
        }

        return ['success' => true, 'status' => 200, 'payload' => $payload];
    }

    /**
     * @return array<string,mixed>
     */
    private function decode_support_gzip_body(string $rawBody, int $maxDecodedBytes): array
    {
        if (
            function_exists('inflate_init')
            && function_exists('inflate_add')
            && defined('ZLIB_ENCODING_GZIP')
            && defined('ZLIB_SYNC_FLUSH')
            && defined('ZLIB_FINISH')
        ) {
            $context = @inflate_init(ZLIB_ENCODING_GZIP);
            if ($context === false) {
                return ['success' => false, 'status' => 400, 'error' => 'Invalid gzip body'];
            }

            $decodedBody = '';
            $rawLength = strlen($rawBody);
            for ($offset = 0; $offset < $rawLength; $offset += self::GZIP_DECODE_CHUNK_BYTES) {
                $chunk = substr($rawBody, $offset, self::GZIP_DECODE_CHUNK_BYTES);
                $decodedChunk = @inflate_add($context, $chunk, ZLIB_SYNC_FLUSH);
                if ($decodedChunk === false) {
                    return ['success' => false, 'status' => 400, 'error' => 'Invalid gzip body'];
                }

                if ($decodedChunk !== '') {
                    $decodedBody .= $decodedChunk;
                    if (strlen($decodedBody) > $maxDecodedBytes) {
                        return ['success' => false, 'status' => 413, 'error' => 'payload_too_large'];
                    }
                }
            }

            $tail = @inflate_add($context, '', ZLIB_FINISH);
            if ($tail === false) {
                return ['success' => false, 'status' => 400, 'error' => 'Invalid gzip body'];
            }
            if ($tail !== '') {
                $decodedBody .= $tail;
                if (strlen($decodedBody) > $maxDecodedBytes) {
                    return ['success' => false, 'status' => 413, 'error' => 'payload_too_large'];
                }
            }

            return ['success' => true, 'status' => 200, 'body' => $decodedBody];
        }

        if (!function_exists('gzdecode')) {
            return ['success' => false, 'status' => 415, 'error' => 'gzip_not_supported'];
        }

        $decodedBody = @gzdecode($rawBody);
        if ($decodedBody === false) {
            return ['success' => false, 'status' => 400, 'error' => 'Invalid gzip body'];
        }
        if (strlen($decodedBody) > $maxDecodedBytes) {
            return ['success' => false, 'status' => 413, 'error' => 'payload_too_large'];
        }

        return ['success' => true, 'status' => 200, 'body' => $decodedBody];
    }

    private function is_api_test_validation_current(): bool
    {
        $validatedAt = $this->config->get_int(NCWooConfig::OPTION_API_TEST_VALIDATED_AT, 0);
        if ($validatedAt <= 0) {
            return false;
        }

        $storedFingerprint = $this->config->get_string(NCWooConfig::OPTION_API_TEST_VALIDATION_FINGERPRINT, '');
        if ($storedFingerprint === '') {
            return false;
        }

        $currentFingerprint = $this->config->build_api_test_validation_fingerprint();
        if ($currentFingerprint === '') {
            return false;
        }

        return hash_equals($storedFingerprint, $currentFingerprint);
    }

    /**
     * @return array<string,mixed>
     */
    private function get_monitoring_snapshot(): array
    {
        global $wpdb;

        $eventTable = $wpdb->prefix . 'ncwoo_event';
        $orderEventTable = $wpdb->prefix . 'ncwoo_order_event';
        $couponTable = $wpdb->prefix . 'ncwoo_coupon';
        $recoveryTable = $wpdb->prefix . 'ncwoo_recovery_token';
        $cronLogTable = $wpdb->prefix . 'ncwoo_cron_log';

        $pendingCart = $this->count_rows_by_status($eventTable, ['pending', 'failed']);
        $pendingOrder = $this->count_rows_by_status($orderEventTable, ['pending', 'failed']);
        $processingCart = $this->count_rows_by_status($eventTable, ['processing']);
        $processingOrder = $this->count_rows_by_status($orderEventTable, ['processing']);
        $processingCount = $processingCart + $processingOrder;
        $staleStats = $this->count_stale_processing_rows($eventTable, $orderEventTable);
        $backlog = $pendingCart + $pendingOrder + $processingCount;

        $recentLogs = $this->fetch_recent_cron_logs($cronLogTable, 12);
        $errorRate = $this->compute_error_rate($recentLogs);
        $avgLatency = 0;
        if ($recentLogs) {
            $latencySum = 0;
            foreach ($recentLogs as $row) {
                $latencySum += (int) ($row['execution_time_ms'] ?? 0);
            }
            $avgLatency = (int) round($latencySum / count($recentLogs));
        }

        $blockedUntil = $this->config->get_int(NCWooConfig::OPTION_CRON_BLOCKED_UNTIL, 0);
        $circuitBreakerState = $blockedUntil > time() ? 'open' : 'closed';
        $circuitOpenSeconds = $blockedUntil > time() ? ($blockedUntil - time()) : 0;

        $healthScore = $this->compute_health_score(
            $backlog,
            $processingCount,
            (int) ($staleStats['stale_processing_count'] ?? 0),
            (int) ($staleStats['oldest_processing_age_seconds'] ?? 0),
            $errorRate,
            $avgLatency,
            $circuitBreakerState === 'open'
        );
        $healthStatus = $this->resolve_health_status($healthScore, $circuitBreakerState);

        return [
            'health_score' => $healthScore,
            'health_status' => $healthStatus,
            'backlog' => $backlog,
            'pending_count' => $pendingCart + $pendingOrder,
            'processing_count' => $processingCount,
            'stale_processing_count' => (int) ($staleStats['stale_processing_count'] ?? 0),
            'oldest_processing_age_seconds' => (int) ($staleStats['oldest_processing_age_seconds'] ?? 0),
            'stale_processing_threshold_seconds' => $this->stale_processing_threshold_seconds(),
            'error_rate' => $errorRate,
            'avg_latency_ms' => $avgLatency,
            'circuit_breaker_state' => $circuitBreakerState,
            'circuit_open_seconds' => max(0, $circuitOpenSeconds),
            'recent_logs' => $recentLogs,
            'recent_coupons' => $this->fetch_recent_coupons($couponTable, 10),
            'recent_recovery_tokens' => $this->fetch_recent_recovery_tokens($recoveryTable, 10),
        ];
    }

    private function table_exists(string $tableName): bool
    {
        global $wpdb;

        $prepared = $wpdb->prepare('SHOW TABLES LIKE %s', $tableName);
        if (!is_string($prepared) || $prepared === '') {
            return false;
        }

        $found = $wpdb->get_var($prepared);
        return is_string($found) && $found !== '';
    }

    /**
     * @param array<int,string> $statuses
     */
    private function count_rows_by_status(string $tableName, array $statuses): int
    {
        global $wpdb;

        if (!$statuses || !$this->table_exists($tableName)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $query = "SELECT COUNT(*) FROM {$tableName} WHERE status IN ({$placeholders})";
        $prepared = $wpdb->prepare($query, ...$statuses);
        if (!is_string($prepared) || $prepared === '') {
            return 0;
        }

        return (int) $wpdb->get_var($prepared);
    }

    /**
     * @return array<string,int>
     */
    private function count_stale_processing_rows(string $eventTable, string $orderEventTable): array
    {
        global $wpdb;

        $thresholdSeconds = $this->stale_processing_threshold_seconds();
        $threshold = gmdate('Y-m-d H:i:s', time() - $thresholdSeconds);
        $stale = 0;
        $oldestAge = 0;

        if ($this->table_exists($eventTable)) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT last_attempt_at, created_at
                     FROM {$eventTable}
                     WHERE status = 'processing'
                       AND (last_attempt_at IS NULL OR last_attempt_at < %s)",
                    $threshold
                ),
                ARRAY_A
            );
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $stale++;
                    $oldestAge = max($oldestAge, $this->processing_age_seconds($row['last_attempt_at'] ?? null, $row['created_at'] ?? null));
                }
            }
        }

        if ($this->table_exists($orderEventTable)) {
            $hasLastAttempt = $this->column_exists($orderEventTable, 'last_attempt_at');
            $attemptExpr = $hasLastAttempt ? 'COALESCE(last_attempt_at, updated_at, created_at)' : 'COALESCE(updated_at, created_at)';
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT {$attemptExpr} AS attempted_at, created_at
                     FROM {$orderEventTable}
                     WHERE status = 'processing'
                       AND {$attemptExpr} < %s",
                    $threshold
                ),
                ARRAY_A
            );
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $stale++;
                    $oldestAge = max($oldestAge, $this->processing_age_seconds($row['attempted_at'] ?? null, $row['created_at'] ?? null));
                }
            }
        }

        return [
            'stale_processing_count' => $stale,
            'oldest_processing_age_seconds' => $oldestAge,
        ];
    }

    private function processing_age_seconds($attemptedAt, $createdAt): int
    {
        $ts = is_string($attemptedAt) && $attemptedAt !== '' ? strtotime($attemptedAt) : false;
        if ($ts === false || $ts <= 0) {
            $ts = is_string($createdAt) && $createdAt !== '' ? strtotime($createdAt) : false;
        }
        if ($ts === false || $ts <= 0) {
            return $this->stale_processing_threshold_seconds() + 1;
        }

        return max(0, time() - (int) $ts);
    }

    private function stale_processing_threshold_seconds(): int
    {
        $configured = (int) get_option('ncwoo_stale_processing_minutes', 3);
        return max(1, min(60, $configured)) * 60;
    }

    private function column_exists(string $tableName, string $columnName): bool
    {
        global $wpdb;

        if (!$this->table_exists($tableName)) {
            return false;
        }

        $found = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$tableName} LIKE %s", $columnName));
        return is_string($found) && $found !== '';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetch_recent_cron_logs(string $tableName, int $limit): array
    {
        global $wpdb;

        if (!$this->table_exists($tableName)) {
            return [];
        }

        $prepared = $wpdb->prepare(
            "SELECT executed_at, status, processed_events, execution_time_ms, error_message
             FROM {$tableName}
             ORDER BY executed_at DESC
             LIMIT %d",
            max(1, $limit)
        );
        if (!is_string($prepared) || $prepared === '') {
            return [];
        }

        $rows = $wpdb->get_results($prepared, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetch_recent_coupons(string $tableName, int $limit): array
    {
        global $wpdb;

        if (!$this->table_exists($tableName)) {
            return [];
        }

        $prepared = $wpdb->prepare(
            "SELECT created_at, customer_email, cart_id, coupon_code, discount_percent, expires_at
             FROM {$tableName}
             ORDER BY created_at DESC
             LIMIT %d",
            max(1, $limit)
        );
        if (!is_string($prepared) || $prepared === '') {
            return [];
        }

        $rows = $wpdb->get_results($prepared, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetch_recent_recovery_tokens(string $tableName, int $limit): array
    {
        global $wpdb;

        if (!$this->table_exists($tableName)) {
            return [];
        }

        $prepared = $wpdb->prepare(
            "SELECT created_at, customer_email, cart_id, coupon_code, expires_at, used_at
             FROM {$tableName}
             ORDER BY created_at DESC
             LIMIT %d",
            max(1, $limit)
        );
        if (!is_string($prepared) || $prepared === '') {
            return [];
        }

        $rows = $wpdb->get_results($prepared, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $nowTs = time();
        foreach ($rows as &$row) {
            $usedAt = trim((string) ($row['used_at'] ?? ''));
            $expiresAt = trim((string) ($row['expires_at'] ?? ''));

            if ($usedAt !== '') {
                $row['status'] = 'used';
                continue;
            }

            $expiresTs = $expiresAt !== '' ? strtotime($expiresAt) : false;
            if ($expiresTs !== false && $expiresTs <= $nowTs) {
                $row['status'] = 'expired';
                continue;
            }

            $row['status'] = 'ready';
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $recentLogs
     */
    private function compute_error_rate(array $recentLogs): float
    {
        if (!$recentLogs) {
            return 0.0;
        }

        $errors = 0;
        foreach ($recentLogs as $row) {
            $status = strtolower(trim((string) ($row['status'] ?? '')));
            if ($status !== 'success') {
                $errors++;
            }
        }

        return round(($errors * 100.0) / count($recentLogs), 2);
    }

    private function compute_health_score(
        int $backlog,
        int $processingCount,
        int $staleProcessingCount,
        int $oldestProcessingAgeSeconds,
        float $errorRate,
        int $avgLatencyMs,
        bool $circuitOpen
    ): int
    {
        $score = 100;

        $score -= min(40, (int) floor(max(0, $backlog) / 2));
        if ($processingCount > 0) {
            $score -= 10;
        }
        if ($staleProcessingCount > 0) {
            $score -= 45;
        }
        if ($oldestProcessingAgeSeconds > ($this->stale_processing_threshold_seconds() * 2)) {
            $score -= 20;
        }
        $score -= min(30, (int) round(max(0.0, $errorRate) * 0.5));

        if ($avgLatencyMs > 10000) {
            $score -= 10;
        }
        if ($avgLatencyMs > 30000) {
            $score -= 20;
        }
        if ($avgLatencyMs > 120000) {
            $score -= 20;
        }

        if ($circuitOpen) {
            $score -= 20;
        }

        return max(0, min(100, $score));
    }

    private function resolve_health_status(int $healthScore, string $circuitBreakerState): string
    {
        if ($circuitBreakerState === 'open') {
            return 'critical';
        }

        if ($healthScore >= 80) {
            return 'healthy';
        }
        if ($healthScore >= 55) {
            return 'warning';
        }

        return 'critical';
    }

    /**
     * @return array<string,string>
     */
    private function build_admin_i18n_bundle(): array
    {
        $locale = $this->resolve_admin_locale();
        $catalog = $this->admin_i18n_catalog();
        $fallback = $catalog['en'];
        $selected = $catalog[$locale] ?? [];

        /** @var array<string,string> $bundle */
        $bundle = array_merge($fallback, $selected);
        return $bundle;
    }

    private function i18n(string $key): string
    {
        $bundle = $this->build_admin_i18n_bundle();
        return array_key_exists($key, $bundle) ? (string) $bundle[$key] : $key;
    }

    private function resolve_admin_locale(): string
    {
        $locale = function_exists('determine_locale')
            ? (string) determine_locale()
            : (string) get_locale();

        $language = strtolower(substr($locale, 0, 2));
        if (!in_array($language, ['fr', 'en', 'es'], true)) {
            return 'en';
        }

        return $language;
    }

    /**
     * @return array<string,array<string,string>>
     */
    private function admin_i18n_catalog(): array
    {
        return [
            'en' => [
                'header_subtitle' => 'AI agents and connector operations in one WooCommerce workspace.',
                'tab_general' => 'General',
                'tab_ia' => 'AI',
                'tab_execution' => 'Execution',
                'tab_monitoring' => 'Monitoring',
                'api_endpoint_label' => 'API endpoint',
                'api_key_label' => 'API key',
                'api_key_configured_placeholder' => 'Configured - leave blank to keep current value',
                'shop_external_id_label' => 'Shop external ID',
                'opaque_recovery_links_label' => 'Opaque recovery links',
                'opaque_recovery_links_help' => 'Enable opaque/single-use recovery tokens',
                'save_general_button' => 'Save general configuration',
                'recovery_enabled_label' => 'Recovery enabled',
                'recovery_forced_label' => 'Forced ON (business requirement)',
                'allow_discount_label' => 'Allow discount',
                'allow_discount_help' => 'Allow coupon discount generation',
                'allow_guest_label' => 'Allow guest',
                'allow_guest_help' => 'Allow guest customers in recovery logic',
                'no_discount_max_label' => 'No discount max',
                'discount_5_min_label' => 'Discount 5% min',
                'discount_5_max_label' => 'Discount 5% max',
                'discount_10_min_label' => 'Discount 10% min',
                'save_ia_button' => 'Save AI configuration',
                'trusted_proxy_ips_label' => 'Trusted proxy IPs',
                'ia_setup_ready_notice' => 'IA configuration is complete.',
                'ia_setup_required_notice' => 'Before testing API or sending events, complete required IA settings (Minimum cart amount and Maximum AI discount).',
                'ia_setup_required_popup' => 'Please complete required IA settings before continuing or testing API.',
                'api_test_gate_required_notice' => 'Action required: click "Test API" successfully to activate synchronization and event processing.',
                'api_test_gate_ready_notice' => 'API test validated. Synchronization and event processing are authorized.',
                'api_test_gate_validated_at_label' => 'Last validation',
                'test_api_button' => 'Test API',
                'execution_mode_title' => 'Execution mode',
                'auto_sync_mode_label' => 'Auto mode (WP synchronization module)',
                'server_sync_mode_label' => 'Server synchronization mode',
                'server_cron_setup_title' => 'Server synchronization setup',
                'server_cron_setup_help' => 'Add this line with crontab -e on the server hosting WordPress. The runner generates the secure synchronization signature at each execution.',
                'server_cron_setup_note' => 'Recommended for production when you want execution independent from visitor traffic. Keep the WordPress scheduler enabled only if you stay in Auto mode.',
                'debug_panel_title' => 'Debug mode',
                'debug_panel_label' => 'Debug mode',
                'debug_panel_help' => 'Shows technical test and forced synchronization options.',
                'debug_mode_label' => 'Enable synchronization test mode',
                'debug_mode_help' => 'Displays and authorizes the manual action: test synchronization.',
                'debug_advanced_label' => 'Enable forced synchronization mode',
                'debug_advanced_help' => 'Reserved for technical support. Can trigger real sends immediately.',
                'warning_debug_advanced_active' => 'Forced synchronization mode is active. Disable it first to enable synchronization test mode.',
                'warning_debug_mode_active' => 'Synchronization test mode is active. Disable it first to enable forced synchronization.',
                'sync_allowed_ips_label' => 'Synchronization allowed IPs',
                'test_cron_title' => 'Test synchronization',
                'test_cron_button' => 'Test synchronization',
                'force_execution_title' => 'Forced synchronization',
                'force_execution_button' => 'Force synchronization',
                'confirmation_required_title' => 'Confirmation required',
                'confirm_force_execution_body' => 'Run synchronization immediately with real data?',
                'confirm_force_execution_accept' => 'Yes, force synchronization',
                'cancel_button' => 'Cancel',
                'ia_onboarding_popup_badge' => 'Action required',
                'ia_onboarding_popup_title' => 'Complete IA settings first',
                'ia_onboarding_popup_body' => 'Before using Test API and event processing, complete required IA fields: Minimum cart amount and Maximum AI discount.',
                'ia_onboarding_popup_go_to_ia' => 'Go to IA tab',
                'ia_onboarding_popup_later' => 'Later',
                'min_cart_total_label' => 'Minimum cart amount',
                'max_discount_percent_label' => 'Maximum AI discount (%)',
                'debug_mode_disabled' => 'Synchronization test mode disabled',
                'advanced_debug_mode_disabled' => 'Forced synchronization mode disabled',
                'api_configuration_incomplete' => 'Incomplete API configuration',
                'ia_configuration_incomplete' => 'IA configuration incomplete',
                'api_test_required_before_activation' => 'Click "Test API" successfully before running synchronization or sending events.',
                'no_pending_events' => 'No pending events',
                'circuit_breaker_open' => 'Circuit breaker open',
                'cron_already_running' => 'Synchronization already running',
                'internal_error' => 'Internal error',
                'test_cron_success' => 'Synchronization test completed successfully. Processed events: %1$d. Duration: %2$d ms.',
                'force_execution_success' => 'Forced synchronization completed successfully. Processed events: %1$d. Duration: %2$d ms.',
                'test_cron_failed' => 'Synchronization test failed: %1$s',
                'force_execution_failed' => 'Forced synchronization failed: %1$s',
                'cron_execution_failed' => 'Synchronization failed: %1$s',
                'cron_no_pending_events_notice' => 'No pending events available to process right now.',
                'cron_failed_events_notice' => 'Synchronization returned errors: %1$s',
                'save_execution_button' => 'Save',
                'recent_sync_runs_title' => 'Recent synchronization runs',
                'no_sync_runs_notice' => 'No synchronization runs recorded yet.',
                'system_health_title' => 'System health',
                'health_status_healthy' => 'healthy',
                'health_status_warning' => 'warning',
                'health_status_critical' => 'critical',
                'health_status_unknown' => 'unknown',
                'breaker_state_open' => 'open',
                'breaker_state_half_open' => 'half open',
                'breaker_state_closed' => 'closed',
                'queue_backlog_header' => 'Queue backlog',
                'pending_header' => 'Pending',
                'processing_header' => 'Processing',
                'stale_processing_header' => 'Stale processing',
                'error_rate_recent_header' => 'Error rate (recent)',
                'average_latency_header' => 'Average latency',
                'circuit_breaker_header' => 'Circuit breaker',
                'seconds_remaining_suffix' => '%ds remaining',
                'executed_at_header' => 'Executed at',
                'status_header' => 'Status',
                'processed_events_header' => 'Processed events',
                'latency_ms_header' => 'Latency (ms)',
                'message_header' => 'Message',
                'recent_coupons_title' => 'Recent NeuroCheckout coupons',
                'created_at_header' => 'Created at',
                'email_header' => 'Email',
                'cart_header' => 'Cart',
                'coupon_header' => 'Coupon',
                'discount_percent_header' => 'Discount (%)',
                'expires_at_header' => 'Expires at',
                'no_recent_coupons' => 'No recent NeuroCheckout coupons.',
                'recent_recovery_links_title' => 'Recent recovery links',
                'no_recent_recovery_links' => 'No recent recovery links.',
                'refresh_link' => 'Refresh',
            ],
            'fr' => [
                'header_subtitle' => 'Agents IA et operations du connecteur dans un seul espace WooCommerce.',
                'tab_general' => 'General',
                'tab_ia' => 'IA',
                'tab_execution' => 'Execution',
                'tab_monitoring' => 'Monitoring',
                'api_endpoint_label' => 'API endpoint',
                'api_key_label' => 'Cle API',
                'api_key_configured_placeholder' => 'Valeur deja configuree - laissez vide pour conserver la valeur actuelle',
                'shop_external_id_label' => 'Identifiant boutique externe',
                'opaque_recovery_links_label' => 'Recovery links opaques',
                'opaque_recovery_links_help' => 'Activer les tokens de recovery opaques et single-use',
                'save_general_button' => 'Enregistrer la configuration generale',
                'recovery_enabled_label' => 'Relance activee',
                'recovery_forced_label' => 'Force ON (exigence metier)',
                'allow_discount_label' => 'Autoriser les remises',
                'allow_discount_help' => 'Autoriser la generation de coupons de reduction',
                'allow_guest_label' => 'Autoriser les invites',
                'allow_guest_help' => 'Autoriser les clients invites dans la logique de relance',
                'no_discount_max_label' => 'Sans remise jusqu a',
                'discount_5_min_label' => 'Remise 5% min',
                'discount_5_max_label' => 'Remise 5% max',
                'discount_10_min_label' => 'Remise 10% min',
                'save_ia_button' => 'Enregistrer la configuration IA',
                'trusted_proxy_ips_label' => 'Reverse proxies de confiance',
                'ia_setup_ready_notice' => 'La configuration IA est complete.',
                'ia_setup_required_notice' => 'Avant de tester l API ou d envoyer des evenements, completez les parametres obligatoires IA (Montant minimum du panier et Reduction maximale IA (%)).',
                'ia_setup_required_popup' => 'Veuillez completer les parametres obligatoires dans l onglet IA avant de continuer ou de tester l API.',
                'api_test_gate_required_notice' => 'Action requise : cliquez sur "Tester l API" avec succes pour activer la synchronisation et le traitement des evenements.',
                'api_test_gate_ready_notice' => 'Test API valide. La synchronisation et le traitement des evenements sont autorises.',
                'api_test_gate_validated_at_label' => 'Derniere validation',
                'test_api_button' => 'Tester l API',
                'execution_mode_title' => 'Mode d execution',
                'auto_sync_mode_label' => 'Mode auto (module de synchronisation WP)',
                'server_sync_mode_label' => 'Mode synchronisation serveur',
                'server_cron_setup_title' => 'Configuration de la synchronisation serveur',
                'server_cron_setup_help' => 'Ajoutez cette ligne avec crontab -e sur le serveur qui heberge WordPress. Le runner genere la signature securisee a chaque execution.',
                'server_cron_setup_note' => 'Recommande en production pour une execution independante du trafic visiteur. Gardez le planificateur WordPress uniquement si vous restez en mode Auto.',
                'debug_panel_title' => 'Mode debug',
                'debug_panel_label' => 'Mode debug',
                'debug_panel_help' => 'Affiche les options techniques de test et de synchronisation forcee.',
                'debug_mode_label' => 'Activer le mode test de synchronisation',
                'debug_mode_help' => 'Affiche et autorise l action manuelle : tester la synchronisation.',
                'debug_advanced_label' => 'Activer le mode de synchronisation forcee',
                'debug_advanced_help' => 'Reserve au support technique. Peut declencher immediatement des envois reels.',
                'warning_debug_advanced_active' => 'Le mode de synchronisation forcee est actif. Desactivez-le d abord pour activer le test de synchronisation.',
                'warning_debug_mode_active' => 'Le mode test de synchronisation est actif. Desactivez-le d abord pour activer la synchronisation forcee.',
                'sync_allowed_ips_label' => 'IP autorisees pour la synchronisation',
                'test_cron_title' => 'Tester la synchronisation',
                'test_cron_button' => 'Tester la synchronisation',
                'force_execution_title' => 'Synchronisation forcee',
                'force_execution_button' => 'Forcer la synchronisation',
                'confirmation_required_title' => 'Confirmation requise',
                'confirm_force_execution_body' => 'Lancer immediatement la synchronisation avec les donnees reelles ?',
                'confirm_force_execution_accept' => 'Oui, forcer la synchronisation',
                'cancel_button' => 'Annuler',
                'ia_onboarding_popup_badge' => 'Action requise',
                'ia_onboarding_popup_title' => 'Completez d abord les parametres IA',
                'ia_onboarding_popup_body' => 'Avant d utiliser Tester l API et le traitement des evenements, completez les champs obligatoires dans l onglet IA : Montant minimum du panier et Reduction maximale IA (%).',
                'ia_onboarding_popup_go_to_ia' => 'Aller a l onglet IA',
                'ia_onboarding_popup_later' => 'Plus tard',
                'min_cart_total_label' => 'Montant minimum du panier',
                'max_discount_percent_label' => 'Reduction maximale IA (%)',
                'debug_mode_disabled' => 'Mode test de synchronisation desactive',
                'advanced_debug_mode_disabled' => 'Mode de synchronisation forcee desactive',
                'api_configuration_incomplete' => 'Configuration API incomplete',
                'ia_configuration_incomplete' => 'Configuration IA incomplete',
                'api_test_required_before_activation' => 'Cliquez sur "Tester l API" avec succes avant d executer la synchronisation ou d envoyer des evenements.',
                'no_pending_events' => 'Aucun evenement en attente',
                'circuit_breaker_open' => 'Circuit breaker ouvert',
                'cron_already_running' => 'Synchronisation deja en cours',
                'internal_error' => 'Erreur interne',
                'test_cron_success' => 'Test de synchronisation termine avec succes. Evenements traites : %1$d. Duree : %2$d ms.',
                'force_execution_success' => 'Synchronisation forcee terminee avec succes. Evenements traites : %1$d. Duree : %2$d ms.',
                'test_cron_failed' => 'Echec du test de synchronisation : %1$s',
                'force_execution_failed' => 'Echec de la synchronisation forcee : %1$s',
                'cron_execution_failed' => 'Echec de la synchronisation : %1$s',
                'cron_no_pending_events_notice' => 'Aucun evenement en attente a traiter pour le moment.',
                'cron_failed_events_notice' => 'La synchronisation a retourne des erreurs : %1$s',
                'save_execution_button' => 'Enregistrer',
                'recent_sync_runs_title' => 'Dernieres synchronisations',
                'no_sync_runs_notice' => 'Aucune synchronisation enregistree pour le moment.',
                'system_health_title' => 'Sante du systeme',
                'health_status_healthy' => 'sain',
                'health_status_warning' => 'degrade',
                'health_status_critical' => 'critique',
                'health_status_unknown' => 'inconnu',
                'breaker_state_open' => 'ouvert',
                'breaker_state_half_open' => 'semi-ouvert',
                'breaker_state_closed' => 'ferme',
                'queue_backlog_header' => 'Backlog',
                'pending_header' => 'En attente',
                'processing_header' => 'En traitement',
                'stale_processing_header' => 'Traitement bloque',
                'error_rate_recent_header' => 'Taux d erreur recent',
                'average_latency_header' => 'Latence moyenne',
                'circuit_breaker_header' => 'Circuit breaker',
                'seconds_remaining_suffix' => '%ds restantes',
                'executed_at_header' => 'Execute a',
                'status_header' => 'Statut',
                'processed_events_header' => 'Evenements traites',
                'latency_ms_header' => 'Latence (ms)',
                'message_header' => 'Message',
                'recent_coupons_title' => 'Derniers coupons NeuroCheckout',
                'created_at_header' => 'Cree le',
                'email_header' => 'Email',
                'cart_header' => 'Panier',
                'coupon_header' => 'Coupon',
                'discount_percent_header' => 'Remise (%)',
                'expires_at_header' => 'Expire le',
                'no_recent_coupons' => 'Aucun coupon NeuroCheckout recent.',
                'recent_recovery_links_title' => 'Derniers recovery links',
                'no_recent_recovery_links' => 'Aucun recovery link recent.',
                'refresh_link' => 'Rafraichir',
            ],
            'es' => [
                'ia_setup_ready_notice' => 'La configuracion de IA esta completa.',
                'ia_setup_required_notice' => 'Antes de probar la API o enviar eventos, completa los ajustes obligatorios de IA.',
                'ia_setup_required_popup' => 'Completa los ajustes obligatorios de IA antes de continuar o probar la API.',
                'api_test_gate_required_notice' => 'Accion requerida: pulsa "Probar API" con exito para activar cron y el procesamiento de eventos.',
                'api_test_gate_ready_notice' => 'Prueba de API validada. Cron y procesamiento de eventos autorizados.',
                'api_test_gate_validated_at_label' => 'Ultima validacion',
                'test_api_button' => 'Probar API',
                'execution_mode_title' => 'Modo de ejecucion',
                'auto_sync_mode_label' => 'Modo auto (modulo de sincronizacion WP)',
                'server_sync_mode_label' => 'Modo sincronizacion de servidor',
                'server_cron_setup_title' => 'Configuracion del cron del servidor',
                'server_cron_setup_help' => 'Anade esta linea con crontab -e en el servidor que aloja WordPress. El runner genera la firma segura en cada ejecucion.',
                'server_cron_setup_note' => 'Recomendado en produccion para una ejecucion independiente del trafico de visitantes. Mantenga WP-Cron solo si usa el modo Auto.',
                'debug_panel_title' => 'Modo debug',
                'debug_panel_label' => 'Modo debug',
                'debug_panel_help' => 'Muestra opciones tecnicas de prueba y sincronizacion forzada.',
                'debug_mode_label' => 'Activar modo debug cron',
                'debug_mode_help' => 'Muestra y autoriza la accion manual: probar cron.',
                'debug_advanced_label' => 'Activar modo debug avanzado (forzar ejecucion)',
                'debug_advanced_help' => 'Reservado para soporte tecnico. Puede lanzar envios reales.',
                'warning_debug_advanced_active' => 'El modo debug avanzado esta activo. Desactivalo antes de activar el modo debug cron.',
                'warning_debug_mode_active' => 'El modo debug cron esta activo. Desactivalo antes de activar el modo debug avanzado.',
                'sync_allowed_ips_label' => 'IPs permitidas para la sincronizacion',
                'test_cron_title' => 'Probar cron',
                'test_cron_button' => 'Probar cron',
                'force_execution_title' => 'Forzar ejecucion',
                'force_execution_button' => 'Forzar ejecucion',
                'confirmation_required_title' => 'Confirmacion requerida',
                'confirm_force_execution_body' => 'Ejecutar cron ahora mismo con datos reales?',
                'confirm_force_execution_accept' => 'Si, forzar ejecucion',
                'cancel_button' => 'Cancelar',
                'ia_onboarding_popup_badge' => 'Accion requerida',
                'ia_onboarding_popup_title' => 'Completa primero los ajustes de IA',
                'ia_onboarding_popup_body' => 'Antes de usar Probar API y el procesamiento de eventos, completa los campos obligatorios de IA.',
                'ia_onboarding_popup_go_to_ia' => 'Ir a la pestana IA',
                'ia_onboarding_popup_later' => 'Mas tarde',
                'min_cart_total_label' => 'Importe minimo del carrito',
                'max_discount_percent_label' => 'Descuento maximo de IA (%)',
                'debug_mode_disabled' => 'Modo debug desactivado',
                'advanced_debug_mode_disabled' => 'Modo debug avanzado desactivado',
                'api_configuration_incomplete' => 'Configuracion API incompleta',
                'ia_configuration_incomplete' => 'Configuracion IA incompleta',
                'api_test_required_before_activation' => 'Pulsa "Probar API" con exito antes de ejecutar cron o enviar eventos.',
                'no_pending_events' => 'No hay eventos pendientes',
                'circuit_breaker_open' => 'Circuit breaker abierto',
                'cron_already_running' => 'Cron ya en ejecucion',
                'internal_error' => 'Error interno',
                'test_cron_success' => 'Prueba cron completada con exito. Eventos procesados: %1$d. Duracion: %2$d ms.',
                'force_execution_success' => 'Ejecucion forzada completada con exito. Eventos procesados: %1$d. Duracion: %2$d ms.',
                'test_cron_failed' => 'Fallo en prueba cron: %1$s',
                'force_execution_failed' => 'Fallo en ejecucion forzada: %1$s',
                'cron_execution_failed' => 'Fallo de ejecucion cron: %1$s',
                'cron_no_pending_events_notice' => 'No hay eventos pendientes para procesar ahora.',
                'cron_failed_events_notice' => 'La ejecucion cron devolvio errores: %1$s',
            ],
        ];
    }

    /**
     * @return array{type:string,message:string}|null
     */
    private function read_notice(): ?array
    {
        $type = isset($_GET['ncwoo_notice']) ? sanitize_key((string) wp_unslash($_GET['ncwoo_notice'])) : '';
        $message = isset($_GET['ncwoo_message']) ? sanitize_text_field((string) wp_unslash($_GET['ncwoo_message'])) : '';

        if ($type === '' || $message === '') {
            return null;
        }

        if (!in_array($type, ['success', 'error', 'warning', 'info'], true)) {
            $type = 'info';
        }

        return [
            'type' => $type,
            'message' => $message,
        ];
    }

    private function page_url(string $tab): string
    {
        return add_query_arg(
            [
                'page' => self::PAGE_SLUG,
                'tab' => $this->resolve_tab($tab),
            ],
            admin_url('admin.php')
        );
    }

    private function redirect_with_notice(string $tab, string $type, string $message): void
    {
        $message = trim($message);
        if ($message === '') {
            $message = 'Operation completed.';
        }

        $url = add_query_arg(
            [
                'page' => self::PAGE_SLUG,
                'tab' => $this->resolve_tab($tab),
                'ncwoo_notice' => $type,
                'ncwoo_message' => $message,
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($url);
        exit;
    }

    private function resolve_tab(string $tab): string
    {
        $tab = strtolower(trim($tab));
        if (!in_array($tab, [self::TAB_GENERAL, self::TAB_IA, self::TAB_EXECUTION, self::TAB_MONITORING], true)) {
            return self::TAB_GENERAL;
        }

        return $tab;
    }

    private function posted_text(string $key, string $default = ''): string
    {
        if (!isset($_POST[$key])) {
            return $default;
        }

        return trim(sanitize_text_field((string) wp_unslash($_POST[$key])));
    }

    private function posted_int(string $key, int $default = 0): int
    {
        if (!isset($_POST[$key])) {
            return $default;
        }

        return (int) wp_unslash($_POST[$key]);
    }

    private function posted_bool(string $key): bool
    {
        if (!isset($_POST[$key])) {
            return false;
        }

        $value = strtolower(trim((string) wp_unslash($_POST[$key])));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private function sanitize_ip_list(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $normalized = preg_replace('/\s+/', ' ', $raw);
        if (!is_string($normalized)) {
            return '';
        }

        $normalized = preg_replace('/[^0-9a-fA-F\.,:\/\s-]/', '', $normalized);
        if (!is_string($normalized)) {
            return '';
        }

        return trim($normalized);
    }

    private function normalize_decimal_string(
        string $raw,
        bool $allowEmpty,
        ?float $min,
        ?float $max,
        string $errorMessage
    ): string {
        $candidate = str_replace(',', '.', trim($raw));
        if ($candidate === '') {
            if ($allowEmpty) {
                return '';
            }
            throw new InvalidArgumentException($errorMessage);
        }

        if (!is_numeric($candidate)) {
            throw new InvalidArgumentException($errorMessage);
        }

        $numeric = (float) $candidate;
        if ($min !== null && $numeric < $min) {
            throw new InvalidArgumentException($errorMessage);
        }
        if ($max !== null && $numeric > $max) {
            throw new InvalidArgumentException($errorMessage);
        }

        // Keep normalized decimal string format for cross-platform parity.
        return rtrim(rtrim(number_format($numeric, 4, '.', ''), '0'), '.');
    }
}
