<?php
declare(strict_types=1);
namespace NeuroCheckout\WooCommerce\Community;

use RuntimeException;
use Automattic\WooCommerce\Utilities\NumberUtil;

/** Transient one-unit catalog offers, not checkout totals or permission to send. */
final class WooCustomerProductPrices
{
    private $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static function (): float { return microtime(true); };
    }

    /** Never log/persist recipient data. No login, session swap, save or cart mutation. */
    public function read(int $scope, int $customerId, string $email, array $ids, string $currency): array
    {
        $started = ($this->clock)();
        try {
            if ($scope < 1 || $customerId < 1 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254
                || !preg_match('/^[A-Z]{3}$/D', $currency) || count($ids) < 1 || count($ids) > 16
                || array_keys($ids) !== range(0, count($ids) - 1) || count(array_unique($ids, SORT_REGULAR)) !== count($ids)) {
                self::fail();
            }
            foreach ($ids as $id) {
                if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,9}$/D', $id)) { self::fail(); }
            }
            $this->runtime($scope);
            $context = $this->context($scope, $customerId, $email, $currency);
            $offers = [];
            foreach ($ids as $id) {
                $this->checkTime($started); $this->runtime($scope);
                try {
                    $product = wc_get_product((int) $id);
                    $this->eligible($product, (int) $id);
                    $nativePrice = self::money($product->get_price());
                    // Do not advertise an expired/not-yet-started sale if native
                    // scheduled-sale processing has not updated _price yet.
                    $expected = $product->is_on_sale() ? $product->get_sale_price() : $product->get_regular_price();
                    if ($nativePrice !== self::money($expected)) { self::fail(); }
                    [$excl, $incl] = $this->amounts($product, $nativePrice, $context);
                    $display = $context['display'] === 'incl' ? $incl : $excl;
                    // Match native storefront formatting, including non-two-decimal currencies.
                    $display = self::money(wc_format_decimal($display, $context['decimals']));
                    $offers[] = ['product_id' => $id, 'status' => 'available', 'currency' => $currency,
                        'price_excl_tax' => $excl, 'price_incl_tax' => $incl,
                        'display_mode' => $context['display'] === 'incl' ? 'including_tax' : 'excluding_tax',
                        'display_price' => $display];
                } catch (\Throwable $error) {
                    $offers[] = ['product_id' => $id, 'status' => 'unavailable'];
                }
            }
            $this->checkTime($started); $this->runtime($scope);
            if ($context['fingerprint'] !== $this->context($scope, $customerId, $email, $currency)['fingerprint']) { self::fail(); }
            $this->checkTime($started);
            return ['schema' => 'woocommerce-customer-prices-v1', 'blog_id' => $scope, 'customer_id' => $customerId,
                'customer_email' => $email, 'currency' => $currency, 'quantity' => 1,
                'price_decimals' => $context['decimals'], 'context_hash' => $context['fingerprint'],
                'read_at' => gmdate('Y-m-d\TH:i:s\Z', (int) ($this->clock)()), 'offers' => $offers];
        } catch (\Throwable $error) {
            // Do not expose native exceptions, emails, addresses or product data.
            self::fail();
        }
    }

    private function runtime(int $scope): void
    {
        if (!defined('WC_VERSION') || !preg_match('/^10\.[1-8]\.[0-9]+$/D', WC_VERSION)
            || (int) get_current_blog_id() !== $scope || !did_action('woocommerce_init')
            || !method_exists('WC_Tax', 'get_rates_from_location')
            || (defined('WP_CONTENT_DIR') && file_exists(WP_CONTENT_DIR . '/db.php'))) { self::fail(); }
        // Explicitly support native pricing only. Unknown callbacks may depend
        // on a logged-in user/cart/currency that is absent in this signed read.
        // Never bypass a custom price/tax rule and return the unfiltered price.
        global $wp_filter;
        foreach ($wp_filter as $name => $hook) {
            if (!preg_match('/^(?:woocommerce_(?:product_get_|product_variation_get_|customer_get_|customer_loaded$|'
                . 'product_object_read$|variation_object_read$|product_class$|product_type_query$|(?:product|product-variation|customer)_data_store$|'
                . 'get_price|price_|prices_|tax_|get_tax|matched|find_rates$|base_tax|adjust_non|currency|calc_tax|calc_inclusive_tax|calc_exclusive_tax|'
                . 'is_purchasable$|variation_is_|product_is_|product_tax_class$|get_stock_quantity$|'
                . 'get_regular_price$|get_sale_price$|before_calculate_totals$|after_calculate_totals$|'
                . 'get_held_stock_quantity$|customer_taxable_address$)|wc_get_price_decimals$|wc_round_tax_total$|'
                . '(?:pre_|default_)?option_woocommerce_(?:tax|calc_taxes|price|currency|default_country|store_|'
                . 'stock|manage_stock|hold_stock|hide_out_of_stock)|pre_option$|get_user_metadata$|get_post_metadata$)/', $name)) { continue; }
            foreach ($hook->callbacks as $callbacks) {
                foreach ($callbacks as $callback) {
                    $fn = $callback['function'];
                    // These core bridges are inert unless their old hook is
                    // registered (the old price/stock/tax hooks are rejected above).
                    if (is_array($fn) && is_object($fn[0]) && get_class($fn[0]) === 'WC_Deprecated_Filter_Hooks'
                        && $fn[1] === 'maybe_handle_deprecated_hook') { continue; }
                    // This callback only changes an address for a chosen pickup
                    // method. We deliberately resolve saved billing/shipping/base
                    // address directly, not a browser's cart/session pickup.
                    if ($name === 'woocommerce_customer_taxable_address' && is_array($fn) && is_object($fn[0])
                        && get_class($fn[0]) === 'Automattic\\WooCommerce\\Blocks\\Shipping\\ShippingController'
                        && $fn[1] === 'filter_taxable_address') { continue; }
                    // Existing post-calculation observers do not set a price;
                    // this reader never calculates a cart or invokes them.
                    if ($name === 'woocommerce_after_calculate_totals' && is_array($fn) && is_object($fn[0])
                        && in_array(get_class($fn[0]) . '::' . $fn[1], ['WC_Cart_Session::set_session',
                            'NCWooEventService::maybe_enqueue_shipping_cost_snapshot'], true)) { continue; }
                    self::fail();
                }
            }
        }
    }

    private function context(int $scope, int $customerId, string $email, string $currency): array
    {
        $user = get_user_by('id', $customerId);
        if (!$user || (int) $user->ID !== $customerId || (int) $user->user_status !== 0
            || strcasecmp((string) $user->user_email, $email) !== 0
            || (is_multisite() && (!is_user_member_of_blog($customerId, $scope) || $user->spam || $user->deleted))) { self::fail(); }
        if (is_multisite()) {
            $site = get_site($scope);
            if (!$site || $site->archived || $site->spam || $site->deleted) { self::fail(); }
        }
        $customer = new \WC_Customer($customerId, false);
        if ((int) $customer->get_id() !== $customerId || strcasecmp($customer->get_email(), $email) !== 0
            || $customer->get_data_store()->get_current_class_name() !== 'WC_Customer_Data_Store'
            || get_woocommerce_currency() !== $currency) { self::fail(); }
        $decimals = wc_get_price_decimals();
        $display = get_option('woocommerce_tax_display_shop');
        $basedOn = get_option('woocommerce_tax_based_on');
        $enabled = wc_tax_enabled(); $included = wc_prices_include_tax();
        $round = get_option('woocommerce_tax_round_at_subtotal');
        if (!is_int($decimals) || $decimals < 0 || $decimals > 6 || !in_array($display, ['incl', 'excl'], true)
            || !in_array($basedOn, ['shipping', 'billing', 'base'], true) || !in_array($round, ['yes', 'no'], true)) { self::fail(); }
        $address = []; $baseAddress = [];
        if ($enabled) {
            $baseAddress = [WC()->countries->get_base_country(), WC()->countries->get_base_state(),
                WC()->countries->get_base_postcode(), WC()->countries->get_base_city()];
            $this->address($baseAddress);
            if ($basedOn === 'base') {
                $address = $baseAddress;
            } else {
                foreach (['country', 'state', 'postcode', 'city'] as $field) {
                    $address[] = $customer->{'get_' . $basedOn . '_' . $field}();
                }
            }
            $this->address($address);
        }
        $facts = [$scope, $customerId, strtolower($email), $currency, $decimals, $display, $basedOn,
            $enabled, $included, $round, $address, $baseAddress, $customer->get_is_vat_exempt()];
        return ['customer' => $customer, 'address' => $address, 'decimals' => $decimals, 'display' => $display,
            'enabled' => $enabled, 'included' => $included, 'round' => $round === 'yes',
            'exempt' => $customer->get_is_vat_exempt(), 'fingerprint' => hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR))];
    }

    private function address(array $address): void
    {
        foreach ($address as $part) {
            if (!is_string($part) || strlen($part) > 128 || preg_match('/[\x00-\x1f\x7f]/', $part)) { self::fail(); }
        }
        [$country, $state, $postcode, $city] = $address;
        $countries = WC()->countries->get_countries();
        if (!preg_match('/^[A-Z]{2}$/D', $country) || !isset($countries[$country])) { self::fail(); }
        $fields = WC()->countries->get_address_fields($country, 'billing_');
        foreach (['state' => $state, 'postcode' => $postcode, 'city' => $city] as $field => $value) {
            if (!empty($fields['billing_' . $field]['required']) && trim($value) === '') { self::fail(); }
        }
        $states = WC()->countries->get_states($country);
        if (is_array($states) && $states && !isset($states[$state])) { self::fail(); }
    }

    private function eligible($p, int $id): void
    {
        if (!is_object($p) || !in_array(get_class($p), ['WC_Product_Simple', 'WC_Product_Variation'], true)
            || (int) $p->get_id() !== $id || $p->get_status() !== 'publish' || !$p->is_purchasable()
            || !$p->is_in_stock() || !$p->has_enough_stock(1) || $p->get_stock_status() !== 'instock'
            || !in_array($p->get_data_store()->get_current_class_name(), ['WC_Product_Data_Store_CPT', 'WC_Product_Variation_Data_Store_CPT'], true)
            || (string) get_post_field('post_password', $id) !== '') { self::fail(); }
        $parent = $p;
        if (get_class($p) === 'WC_Product_Variation') {
            $parent = wc_get_product($p->get_parent_id());
            if (!is_object($parent) || get_class($parent) !== 'WC_Product_Variable' || $parent->get_status() !== 'publish'
                || !$p->variation_is_active() || !$p->variation_is_visible()
                || (string) get_post_field('post_password', $parent->get_id()) !== '') { self::fail(); }
            $attributes = $p->get_attributes();
            $required = $parent->get_variation_attributes();
            if (!$attributes || count($attributes) !== count($required)) { self::fail(); }
            foreach ($required as $key => $options) {
                if (!isset($attributes[$key]) || $attributes[$key] === '' || !in_array($attributes[$key], $options, true)) { self::fail(); }
            }
        }
        if (!in_array($parent->get_catalog_visibility(), ['visible', 'catalog'], true)) { self::fail(); }
        if ($p->managing_stock()) {
            $stockProduct = $p->get_stock_managed_by_id() === $p->get_id() ? $p : $parent;
            $stock = $stockProduct->get_stock_quantity(); $held = wc_get_held_stock_quantity($stockProduct);
            if (!is_numeric($stock) || !is_numeric($held) || !is_finite((float) $stock) || !is_finite((float) $held)
                || $held < 0 || $stock - $held < 1) { self::fail(); }
        }
    }

    /** Native tax primitives, matching Woo's incl/excl helpers without their global customer lookup. */
    private function amounts($product, float $price, array $ctx): array
    {
        if (!$ctx['enabled'] || !$product->is_taxable()) { return [$price, $price]; }
        $rates = \WC_Tax::get_rates_from_location($product->get_tax_class(), $ctx['address'], $ctx['customer']);
        $base = \WC_Tax::get_base_tax_rates($product->get_tax_class('unfiltered'));
        if (!is_array($rates) || !is_array($base)) { self::fail(); }
        $sum = static function (array $taxes) use ($ctx): float {
            return array_sum($ctx['round'] ? $taxes : array_map('wc_round_tax_total', $taxes));
        };
        $excl = $price; $incl = $price;
        if (!$ctx['included']) {
            $incl = NumberUtil::round($price + ($ctx['exempt'] ? 0 : $sum(\WC_Tax::calc_tax($price, $rates, false))), $ctx['decimals']);
        } else {
            $baseTaxes = \WC_Tax::calc_tax($price, $base, true);
            $excl = $price - array_sum($baseTaxes);
            if ($ctx['exempt']) {
                $incl = NumberUtil::round($price - $sum($baseTaxes), $ctx['decimals']);
            } elseif ($rates !== $base) {
                $incl = NumberUtil::round($price - $sum($baseTaxes) + $sum(\WC_Tax::calc_tax($excl, $rates, false)), $ctx['decimals']);
            }
        }
        return [self::money($excl), self::money($incl)];
    }

    private function checkTime(float $started): void
    {
        $now = ($this->clock)();
        if ($now < $started || $now - $started >= 3.0) { self::fail(); }
    }

    private static function money($value): float
    {
        if (is_bool($value) || !is_numeric($value) || !is_finite((float) $value) || $value < 0 || $value > 1e9) { self::fail(); }
        return (float) $value;
    }

    private static function fail(): void { throw new RuntimeException('customer_prices_unavailable'); }
}
