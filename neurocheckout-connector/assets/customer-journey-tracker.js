(function () {
  "use strict";

  var cfg = window.NCWooCustomerJourney || {};
  if (!cfg.endpoint || !cfg.token || !cfg.eventPrefix) {
    return;
  }

  var maxEvents = Number(cfg.maxEventsPerPage || 18);
  var sentCount = 0;
  var pageContext = cfg.context || {};
  var visitorKey = "ncwoo_journey_visitor";
  var sessionKey = "ncwoo_journey_session";
  var queueKey = "ncwoo_journey_queue_v1";
  var sentKeys = {};
  var supportBeacon = !!(navigator && navigator.sendBeacon);

  function uuid() {
    if (window.crypto && typeof window.crypto.randomUUID === "function") {
      return window.crypto.randomUUID();
    }
    return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, function (c) {
      var r = Math.random() * 16 | 0;
      var v = c === "x" ? r : (r & 0x3 | 0x8);
      return v.toString(16);
    });
  }

  function storageGet(storage, key) {
    try {
      return storage.getItem(key) || "";
    } catch (e) {
      return "";
    }
  }

  function storageSet(storage, key, value) {
    try {
      storage.setItem(key, value);
    } catch (e) {
      // Storage can be disabled. Cookies keep the server-side correlation working.
    }
  }

  function setCookie(name, value, maxAge) {
    var secure = window.location.protocol === "https:" ? "; Secure" : "";
    document.cookie = name + "=" + encodeURIComponent(value) + "; Path=/; SameSite=Lax; Max-Age=" + maxAge + secure;
  }

  function getOrCreateId(storage, key, maxAge) {
    var value = storageGet(storage, key);
    if (!value) {
      value = "wc_" + uuid().replace(/-/g, "");
      storageSet(storage, key, value);
    }
    setCookie(key, value, maxAge);
    return value;
  }

  var visitorId = getOrCreateId(window.localStorage, visitorKey, 31536000);
  var sessionId = getOrCreateId(window.sessionStorage, sessionKey, 1800);

  function nowIso() {
    return new Date().toISOString();
  }

  function cleanText(value, limit) {
    var text = String(value || "").replace(/\s+/g, " ").trim();
    return text ? text.slice(0, limit || 300) : "";
  }

  function pagePayload() {
    return {
      type: cleanText(pageContext.page_type || "page", 80),
      url: window.location.origin + window.location.pathname,
      title: cleanText(document.title || pageContext.title || "", 180),
      referrer: cleanText(document.referrer || "", 500)
    };
  }

  function basePayload(suffix, extra) {
    var payload = extra || {};
    payload.event_id = payload.event_id || uuid();
    payload.event_type = cfg.eventPrefix + suffix;
    payload.occurred_at = payload.occurred_at || nowIso();
    payload.token = cfg.token;
    if (cfg.wpNonce) {
      payload._wpnonce = cfg.wpNonce;
    }
    payload.cart_id = cleanText(pageContext.cart_id || "", 120);
    payload.journey = payload.journey || {};
    payload.journey.visitor_id = visitorId;
    payload.journey.session_id = sessionId;
    payload.journey.cart_id = cleanText(pageContext.cart_id || "", 120);
    payload.page = payload.page || pagePayload();
    return payload;
  }

  function queueRead() {
    try {
      var raw = window.localStorage.getItem(queueKey);
      var parsed = raw ? JSON.parse(raw) : [];
      return Array.isArray(parsed) ? parsed.slice(0, 40) : [];
    } catch (e) {
      return [];
    }
  }

  function queueWrite(items) {
    try {
      window.localStorage.setItem(queueKey, JSON.stringify(items.slice(0, 40)));
    } catch (e) {
      // Best effort only.
    }
  }

  function queuePush(payload) {
    var items = queueRead();
    items.push(payload);
    queueWrite(items);
  }

  function post(payload, useBeacon) {
    var body = JSON.stringify(payload);
    if (useBeacon && supportBeacon) {
      try {
        if (navigator.sendBeacon(cfg.endpoint, new Blob([body], { type: "application/json" }))) {
          return Promise.resolve(true);
        }
      } catch (e) {
        // Fall through to fetch.
      }
    }

    var headers = {
      "Content-Type": "application/json",
      "X-Neuro-Journey-Token": cfg.token
    };
    if (cfg.wpNonce) {
      headers["X-WP-Nonce"] = cfg.wpNonce;
    }

    return fetch(cfg.endpoint, {
      method: "POST",
      credentials: "same-origin",
      keepalive: !!useBeacon,
      headers: headers,
      body: body
    }).then(function (response) {
      return response.ok;
    }).catch(function () {
      return false;
    });
  }

  function flushQueue() {
    var items = queueRead();
    if (!items.length) {
      return;
    }
    queueWrite([]);
    items.reduce(function (chain, item) {
      return chain.then(function () {
        return post(item, false).then(function (ok) {
          if (!ok) {
            queuePush(item);
          }
        });
      });
    }, Promise.resolve());
  }

  function send(suffix, extra, options) {
    if (sentCount >= maxEvents) {
      return;
    }
    var eventKey = suffix + ":" + cleanText((extra && extra.event && extra.event.name) || "", 80);
    if (options && options.once && sentKeys[eventKey]) {
      return;
    }
    sentKeys[eventKey] = true;
    sentCount += 1;

    var payload = basePayload(suffix, extra || {});
    post(payload, !!(options && options.beacon)).then(function (ok) {
      if (!ok) {
        queuePush(payload);
      }
    });
  }

  function sendInitialSignals() {
    var pageType = cleanText(pageContext.page_type || "page", 80);
    send("page_view", {
      journey: {
        event: { name: "page_view" }
      }
    }, { once: true });

    if (pageType === "product" && pageContext.product) {
      send("product_view", {
        journey: {
          event: { name: "product_view" },
          product: pageContext.product
        }
      }, { once: true });
    }

    if (pageType === "category" && pageContext.category) {
      send("category_view", {
        journey: {
          event: { name: "category_view" },
          category: pageContext.category
        }
      }, { once: true });
    }

    if (pageType === "cart") {
      send("cart_view", {
        journey: {
          event: { name: "cart_view" }
        }
      }, { once: true });
    }

    if (pageType === "checkout") {
      send("checkout_started", {
        journey: {
          event: { name: "checkout_started" }
        }
      }, { once: true });
    }
  }

  function bindAddToCartIntent() {
    document.addEventListener("click", function (event) {
      var target = event.target && event.target.closest
        ? event.target.closest("button[name='add-to-cart'], .single_add_to_cart_button, .ajax_add_to_cart, a.add_to_cart_button, form.cart button[type='submit']")
        : null;
      if (!target) {
        return;
      }
      send("add_to_cart_intent", {
        journey: {
          event: {
            name: "add_to_cart_intent",
            target: cleanText(target.getAttribute("name") || target.className || target.tagName, 120)
          },
          product: pageContext.product || {}
        }
      });
    }, true);

    document.addEventListener("submit", function (event) {
      var form = event.target;
      if (!form || !form.matches || !form.matches("form.cart")) {
        return;
      }
      send("add_to_cart_intent", {
        journey: {
          event: { name: "add_to_cart_form_submit" },
          product: pageContext.product || {}
        }
      });
    }, true);
  }

  function bindCheckoutSignals() {
    var checkoutForm = document.querySelector("form.checkout, form.wc-block-components-form");
    if (!checkoutForm) {
      return;
    }

    var sentChange = false;
    checkoutForm.addEventListener("change", function (event) {
      if (sentChange) {
        return;
      }
      sentChange = true;
      var target = event.target || {};
      send("checkout_step", {
        journey: {
          event: {
            name: "checkout_field_interaction",
            field_type: cleanText(target.type || target.tagName || "field", 60)
          }
        }
      });
    }, true);

    checkoutForm.addEventListener("submit", function () {
      send("checkout_step", {
        journey: {
          event: { name: "checkout_submit" }
        }
      }, { beacon: true });
    }, true);
  }

  function sendVisibleFormErrors() {
    var errors = document.querySelectorAll(".woocommerce-error, .wc-block-components-notice-banner.is-error, .woocommerce-invalid");
    if (!errors.length) {
      return;
    }
    send("form_error", {
      journey: {
        event: {
          name: "visible_checkout_error",
          error_count: errors.length
        }
      }
    }, { once: true });
  }

  function observeErrors() {
    sendVisibleFormErrors();
    if (!window.MutationObserver) {
      return;
    }
    var timer = null;
    var observer = new MutationObserver(function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(sendVisibleFormErrors, 250);
    });
    observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ["class"] });
  }

  function sendPerformance() {
    if (!window.performance || !performance.getEntriesByType) {
      return;
    }
    var nav = performance.getEntriesByType("navigation")[0];
    if (!nav) {
      return;
    }
    var duration = Math.round(nav.loadEventEnd || nav.duration || 0);
    if (!duration || duration < Number(cfg.slowPageMs || 5000)) {
      return;
    }
    send("performance", {
      journey: {
        event: {
          name: "slow_page",
          duration_ms: duration,
          page_type: cleanText(pageContext.page_type || "page", 80)
        }
      }
    }, { once: true });
  }

  function bindExitIntent() {
    var sent = false;
    function emit() {
      if (sent) {
        return;
      }
      sent = true;
      send("exit_intent", {
        journey: {
          event: {
            name: "page_exit",
            page_type: cleanText(pageContext.page_type || "page", 80)
          }
        }
      }, { once: true, beacon: true });
    }

    document.addEventListener("visibilitychange", function () {
      if (document.visibilityState === "hidden") {
        emit();
      }
    });
    window.addEventListener("pagehide", emit);
  }

  flushQueue();
  sendInitialSignals();
  bindAddToCartIntent();
  bindCheckoutSignals();
  observeErrors();
  bindExitIntent();

  if (document.readyState === "complete") {
    sendPerformance();
  } else {
    window.addEventListener("load", function () {
      window.setTimeout(sendPerformance, 0);
    });
  }
})();
