(function () {
    'use strict';

    var config = window.NCWooTelemetry || {};
    if (!config.endpoint || !config.nonce) {
        return;
    }

    var sentKeys = {};
    var slowRequestMs = parseInt(config.slowRequestMs, 10);
    if (!slowRequestMs || slowRequestMs < 1000) {
        slowRequestMs = 5000;
    }

    function uuid() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }

        var template = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx';
        return template.replace(/[xy]/g, function (char) {
            var random = Math.random() * 16 | 0;
            var value = char === 'x' ? random : (random & 0x3 | 0x8);
            return value.toString(16);
        });
    }

    function visible(element) {
        if (!element) {
            return false;
        }
        var rect = element.getBoundingClientRect();
        return !!(rect.width || rect.height || element.getClientRects().length);
    }

    function countVisible(selector) {
        var nodes = document.querySelectorAll(selector);
        var count = 0;
        nodes.forEach(function (node) {
            if (visible(node)) {
                count += 1;
            }
        });
        return count;
    }

    function classifyUrl(value) {
        var url = String(value || '').toLowerCase();
        if (url.indexOf('wc-ajax=checkout') !== -1) {
            return 'wc_ajax_checkout';
        }
        if (url.indexOf('/wc/store/') !== -1 && url.indexOf('checkout') !== -1) {
            return 'store_api_checkout';
        }
        if (url.indexOf('checkout') !== -1) {
            return 'checkout_related';
        }
        return '';
    }

    function isCheckoutRequest(entry) {
        return !!classifyUrl(entry && entry.name);
    }

    function compactText(value, maxLength) {
        var text = String(value || '').replace(/\s+/g, ' ').trim();
        if (!text) {
            return null;
        }
        text = text
            .replace(/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/g, '[email]')
            .replace(/https?:\/\/[^\s"'<>]+/gi, '[url]')
            .replace(/\b(?:sk|pk|rk|whsec|secret|token|api[_-]?key)[_-]?[A-Za-z0-9]{12,}\b/gi, '[secret]');
        return text.slice(0, maxLength || 180);
    }

    function sendTelemetry(eventType, metrics, context, key) {
        var dedupeKey = eventType + ':' + (key || '');
        if (key && sentKeys[dedupeKey]) {
            return;
        }
        if (key) {
            sentKeys[dedupeKey] = true;
        }

        var payload = {
            event_id: uuid(),
            event_type: eventType,
            occurred_at: new Date().toISOString(),
            metrics: metrics || {},
            context: context || {},
            privacy: {
                contains_raw_server_logs: false,
                contains_payment_provider_logs: false,
                contains_customer_pii: false
            }
        };
        var body = JSON.stringify(payload);

        if (navigator.sendBeacon && body.length < 60000) {
            var beaconUrl = config.endpoint + (config.endpoint.indexOf('?') === -1 ? '?' : '&') + '_wpnonce=' + encodeURIComponent(config.nonce);
            var blob = new Blob([body], { type: 'application/json' });
            navigator.sendBeacon(beaconUrl, blob);
            return;
        }

        if (window.fetch) {
            window.fetch(config.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce
                },
                body: body
            }).catch(function () {});
        }
    }

    function collectFrictionSnapshot() {
        var requiredFields = countVisible('form.checkout [required], .wc-block-checkout [required], form.checkout .validate-required input, form.checkout .validate-required select, form.checkout .validate-required textarea');
        var paymentMethods = countVisible('input[name="payment_method"], .wc-block-components-radio-control__input[name*="payment"]');
        var shippingMethods = countVisible('input[name^="shipping_method"], .wc-block-components-shipping-rates-control input[type="radio"]');
        var couponFields = countVisible('form.checkout_coupon, .woocommerce-form-coupon, .wc-block-components-totals-coupon');
        var accountFields = countVisible('#createaccount, .create-account, input[name="createaccount"]');
        var termsFields = countVisible('input[name="terms"], .woocommerce-terms-and-conditions-checkbox-text');

        sendTelemetry(
            'woocommerce.checkout.friction_snapshot',
            {
                required_field_count: requiredFields,
                payment_method_count: paymentMethods,
                shipping_method_count: shippingMethods,
                coupon_field_present: couponFields > 0,
                account_creation_present: accountFields > 0,
                terms_required: termsFields > 0
            },
            {
                checkout_phase: 'form_snapshot',
                checkout_layout: document.querySelector('.wc-block-checkout') ? 'blocks' : 'classic'
            },
            'friction:' + requiredFields + ':' + paymentMethods + ':' + shippingMethods + ':' + couponFields + ':' + accountFields + ':' + termsFields
        );
    }

    function collectPagePerformance() {
        var navigation = null;
        if (window.performance && typeof window.performance.getEntriesByType === 'function') {
            var entries = window.performance.getEntriesByType('navigation');
            navigation = entries && entries.length ? entries[0] : null;
        }

        var duration = navigation ? navigation.duration : 0;
        var ttfb = navigation ? navigation.responseStart : 0;
        sendTelemetry(
            'woocommerce.checkout.performance',
            {
                duration_ms: Math.round(duration || 0),
                ttfb_ms: Math.round(ttfb || 0),
                required_field_count: countVisible('form.checkout [required], .wc-block-checkout [required], form.checkout .validate-required input, form.checkout .validate-required select, form.checkout .validate-required textarea')
            },
            {
                checkout_phase: 'page_load',
                checkout_layout: document.querySelector('.wc-block-checkout') ? 'blocks' : 'classic'
            },
            'page_load:' + Math.round((duration || 0) / 1000)
        );
    }

    function collectRequestAnomalies() {
        if (!window.performance || typeof window.performance.getEntriesByType !== 'function') {
            return;
        }

        var resources = window.performance.getEntriesByType('resource') || [];
        resources.forEach(function (entry) {
            if (!isCheckoutRequest(entry)) {
                return;
            }
            var duration = Math.round(entry.duration || 0);
            if (duration < slowRequestMs) {
                return;
            }

            var routeClass = classifyUrl(entry.name);
            sendTelemetry(
                'woocommerce.checkout.request_anomaly',
                {
                    duration_ms: duration,
                    slow_threshold_ms: slowRequestMs,
                    transfer_size: Math.round(entry.transferSize || 0)
                },
                {
                    route_class: routeClass,
                    checkout_phase: 'request'
                },
                'request:' + routeClass + ':' + duration
            );
        });
    }

    window.addEventListener('error', function (event) {
        sendTelemetry(
            'woocommerce.checkout.js_error',
            {
                error_count: 1,
                line: event.lineno || null,
                column: event.colno || null
            },
            {
                message: compactText(event.message, 180),
                source_class: classifyUrl(event.filename) || 'browser_script',
                checkout_phase: 'browser_error'
            }
        );
    });

    window.addEventListener('unhandledrejection', function (event) {
        var reason = event && event.reason ? (event.reason.message || event.reason) : '';
        sendTelemetry(
            'woocommerce.checkout.js_error',
            {
                error_count: 1,
                unhandled_rejection: true
            },
            {
                message: compactText(reason, 180),
                source_class: 'browser_promise',
                checkout_phase: 'browser_error'
            }
        );
    });

    function collectAll() {
        collectFrictionSnapshot();
        collectPagePerformance();
        collectRequestAnomalies();
    }

    if (document.readyState === 'complete') {
        window.setTimeout(collectAll, 800);
    } else {
        window.addEventListener('load', function () {
            window.setTimeout(collectAll, 800);
        });
    }

    if (window.jQuery && window.jQuery(document.body)) {
        window.jQuery(document.body).on('updated_checkout checkout_error', function (_event) {
            window.setTimeout(function () {
                collectFrictionSnapshot();
                collectRequestAnomalies();
            }, 300);
        });
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            collectRequestAnomalies();
        }
    });
})();
