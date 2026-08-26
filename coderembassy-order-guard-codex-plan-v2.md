# CoderEmbassy Order Guard for WooCommerce

## Codex Development Plan and Implementation Prompt — v2 (Revised)

This document is for building a WordPress/WooCommerce plugin one practical step at a time. It supersedes the v1 "Fake Order Guard" plan.

v2 changes, in short: renamed to Order Guard; added Store API /batch inspection; tiered circuit breakers (per-IP, per-email, global); ip_hash column and stronger indexes; CEOG_SAFE_MODE emergency recovery; unknown-origin is flag-only in v1; Store API checkout disable is now an Emergency Lockdown; strict session requirement is an advanced opt-in; native WooCommerce checkout rate limiting is detected, not overridden; lists/whitelist engine moved earlier in the build order; CSV export deferred to v1.1.

Plugin name:

**CoderEmbassy Order Guard for WooCommerce**

Suggested WordPress.org listing title:

**Order Guard for WooCommerce – Anti Card Testing & Fake Checkout Protection**

Core tagline:

> Stop card-testing attacks, bot orders, and fake checkouts at the API level — where the real attacks happen.

Primary goal:

> Build a lightweight, secure, production-ready WooCommerce plugin that blocks automated fake orders and card-testing attacks with zero external services, zero API keys, and zero friction for real customers.

Brand note:

Second plugin in the CoderEmbassy "Guard" family (after Quantity Guard). Same coding standards, security posture, and admin design language. Cross-link the two plugins in each readme.

---

# 1. Product Summary

WooCommerce store owners are hit by automated attacks that:

- POST directly to `/wp-json/wc/store/v1/cart/add-item`, `/wp-json/wc/store/v1/checkout`, and `/wp-json/wc/store/v1/batch`, bypassing the checkout page entirely.
- Test hundreds of stolen card numbers, generating floods of failed orders.
- Bypass reCAPTCHA and hCaptcha, because the bots never load the checkout form.
- Cause payment processors to flag or suspend the merchant account.
- Destroy email deliverability with hundreds of failed-order notifications.
- Cost real money in payment gateway fees per declined attempt.

The consistent fingerprints of these attacks:

- A very high volume of failed orders in a short window.
- Attacks arriving from one IP (lazy bots) or distributed across many rotating IPs (botnets). Both must be handled.
- Order attribution "Origin: Unknown" (no referrer, no client-side attribution data).
- No prior browsing session (the bot's first-ever request is a cart mutation).
- Cheap or single-product carts, guest checkout, disposable-looking emails.

Order Guard detects and blocks these patterns server-side, before the payment gateway is ever called.

---

# 2. Unique Selling Point

The unique angle:

> API-level protection with tiered failed-order circuit breakers — no captcha, no SaaS, no API keys, invisible to real customers.

Main differentiators:

1. **Tiered circuit breakers.** Per-IP and per-email breakers neutralize single-source attacks with a tiny blast radius. The global breaker is the primary defense against distributed botnet attacks: when failed orders flood in from many IPs, checkout pauses briefly and reopens by itself. A real customer who typos a card is never blocked.
2. **Store API hardening, including /batch.** Protection where the attacks actually arrive — including operations hidden inside batch requests that other tools never inspect.
3. **Unknown-origin flagging.** Orders carrying the "Origin: Unknown" fingerprint are flagged for review with a clear order note. A review signal, not a fraud verdict.
4. **Monitor mode first.** Every protection ships in log-only mode by default. The store owner watches the activity log, sees exactly what would have been blocked, then flips to enforce. No surprise false positives.
5. **Safe Mode recovery.** One wp-config constant instantly forces monitor mode across the whole plugin. Support-friendly, panic-proof.
6. **Zero external dependencies.** No captcha keys, no fraud-scoring subscription, no data sent anywhere. Privacy-friendly by design, with HMAC-hashed IPs for repeat-attacker detection without storing personal data by default.

What Order Guard is NOT:

- Not a fraud-scoring service (no MaxMind/minFraud in the free version).
- Not a captcha plugin.
- Not a general firewall or malware scanner.
- It blocks automated fake-order attacks; it does not judge whether a human-placed order is fraudulent.

Threat-model note for all copy and design decisions:

Card-testing attacks are usually distributed across many IPs. Per-IP controls alone will not stop them. Never market per-IP blocking as the main protection; the global breaker and API hardening are the load-bearing layers.

---

# 3. MVP Scope

Build only the MVP first.

## Include in Version 1

- WooCommerce dependency check.
- Global enable/disable setting.
- Monitor mode vs Enforce mode (global, default: Monitor).
- CEOG_SAFE_MODE emergency constant.
- Activity log (one custom table, ip_display + ip_hash) with fixed seven-day Free retention and daily pruning.
- Lists engine: blocklist (emails, email domains, IPs) and whitelist (user roles, IPs, payment methods).
- Store API rate limiting toggle (wraps the WooCommerce built-in) with native-setting detection.
- Store API /batch inspection applying all rules to embedded operations.
- Tiered failed-order circuit breakers: per-IP, per-email (hashed), global.
- Strict Session Requirement for Store API cart mutations (Advanced, off by default).
- Emergency Store API Checkout Lockdown (Advanced/Emergency, off by default, guarded).
- Unknown-origin order flagging (flag only — no blocking in v1).
- Honeypot field on the classic checkout.
- One-click "block this email / IP" from the order edit screen.
- Email alert when any breaker trips (throttled).
- Dashboard widget: blocked/flagged today and 7 days, breaker status.
- HPOS compatibility declaration; blocks compatibility declaration.
- Translation-ready strings.
- WordPress.org-style readme with a privacy section.

## Defer to Version 1.1

- Nothing currently. (CSV export moved to Pro; per-gateway rules moved to
  the Pro list. See Section 4.)

## Do Not Include in Version 1 (Pro candidates)

- Captcha or Turnstile integration.
- MaxMind / minFraud / any external scoring API.
- Country, ASN, or VPN blocking.
- Disposable-email API lookups (a small bundled static domain list is acceptable).
- Block Checkout protection layer via the Additional Checkout Fields API (WooCommerce 8.9+).
- Machine learning or "AI fraud detection" claims — never, in any version.
- React dashboard, licensing system, multisite features, Slack/webhook alerts, charts.

---

# 4. Free and Pro Strategy

Positioning line for all copy:

> Free stops the attack. Pro cleans up after it, automates your defenses, and reports on everything.

## Free Version

The free version must genuinely stop volume attacks on its own. This
category rewards trust; a crippled free version kills the reviews that
drive installs. NEVER cap in free: the blocking itself, the number of
blocklist/whitelist entries, breaker functionality, or anything that makes
free protection feel rigged. Trust is the product.

Free: everything in the v1 MVP scope above, with ONE deliberate lever:

- **Activity log retention is fixed at 7 days in free.** Seven days fully
  supports the monitor-first workflow (watch 24–48h, then enforce).
  History, forensics, and reporting are Pro. Retention limits are an
  accepted freemium lever; crippled blocking is not.

## Pro Version

Pro sells to the store owner's situation the moment after free earns their
trust: free stopped the attack, and now they are staring at the mess it
left and worrying about the next one. Hero features first:

1. **Attack cleanup (hero #1).** Bulk and scheduled auto-deletion of
   failed/junk orders left behind by attacks: clean by status, date range,
   and Order Guard signals; keep reports and email deliverability sane.
   Felt immediately after every attack; recurring value.
2. **Auto-blocklisting (hero #2).** Repeat attackers are added to the
   blocklist automatically with expiring bans (configurable thresholds and
   durations). Free = manual control; Pro = works while you sleep.
3. **History and reporting.** 30/90-day log retention, CSV export,
   scheduled email digest reports.
4. **Advanced alerts.** Webhook + Slack alerts.
5. Block Checkout protection layer (Additional Checkout Fields API,
   WooCommerce 8.9.0+).
6. Cloudflare Turnstile integration (classic + block checkout).
7. Country and ASN rules; disposable/role email detection with maintained
   lists; MaxMind minFraud integration (user's own key).
8. Multisite/network blocklist sync, WP-CLI commands, per-gateway rules.

## Pricing guidance

- Pro at roughly $49–69/year (undercuts the ~$99/yr incumbent while still
  reading as a serious product), plus a monthly option (~$9–15/month) for
  under-attack panic buyers.
- Year-one success metric is installs, reviews, and Guard-family brand —
  not revenue. Expect 0.5–3% free→Pro conversion; revenue compounds with
  the install base in years two and three. Plan a Guard-family bundle
  (Quantity Guard Pro + Order Guard Pro) once both exist.

## Contextual upgrade moment (UI requirement)

The dashboard must surface Pro contextually and honestly — never as a nag:

- After an enforced attack: "Order Guard blocked N attempts. M failed/junk
  orders remain — Pro can clean these up automatically." Shown on the
  dashboard as a dismissible card; dismissed state persists.
- No nag banners on unrelated screens, no interrupting modals, no fake
  urgency. The upgrade prompt appears only when the pain it solves is
  visible in the data.

## Wedge micro-plugin

Ship "Flag Unknown-Origin Orders" as a separate tiny free plugin (one setting, one rule) that captures niche keyword traffic and recommends Order Guard. Decide after Order Guard v1 ships.

---

# 5. Technical Requirements

## Plugin Type

WordPress plugin for WooCommerce.

## Coding Style

Same standards as Quantity Guard:

- PHP, WordPress coding standards, WooCommerce hooks and filters.
- Object-oriented, uniquely prefixed classes and functions.
- No framework, no Composer dependency unless absolutely necessary.
- Must pass the Plugin Check plugin with no errors.

## Prefix

```php
ceog
```

Examples:

```php
CEOG_Plugin
CEOG_Breakers
ceog_get_settings()
```

## Compatibility

- Classic (shortcode) cart and checkout.
- Cart and Checkout Blocks and the Store API, including /batch. Store API coverage IS the product.
- HPOS: the plugin reads and updates orders, so it MUST use CRUD (`wc_get_orders`, `$order->get_meta()`, `$order->update_status()`) and never query posts/postmeta for orders directly.

Declare compatibility in the main file:

```php
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
} );
```

Compatibility guardrails:

- Never break express/wallet payments (Apple Pay, Google Pay, PayPal buttons). These legitimately arrive without a normal checkout page flow; the payment-method whitelist must cover them and the docs must say so.
- Never break headless stores or custom mobile-app checkouts by default. Strict Session Requirement and Emergency Lockdown are opt-in, buried under Advanced, with explicit warnings naming headless and mobile-app flows.
- Respect WooCommerce's own protections. The Store API already requires a Nonce Token or Cart Token for cart/checkout POSTs, and WooCommerce ships its own optional checkout rate limiting. Order Guard detects what is already active, displays it, and extends it — it never silently overrides or duplicates it.
- Auto-detect block checkout: if the checkout page `has_block( 'woocommerce/checkout' )`, refuse to enable Emergency Lockdown and explain why.
- Caching-friendly: no output on cached pages may vary per visitor; all protection logic runs on non-cacheable requests (REST, checkout POST).

Do not edit WooCommerce core files.

---

# 6. Security and Privacy Requirements

All general rules from the Quantity Guard plan apply (ABSPATH guard in every file, no eval/unserialize on untrusted data, capability checks with `manage_woocommerce`, nonces on every admin form, sanitize on input, escape on output).

Additional rules specific to this plugin:

## IP address handling

- Default source: `$_SERVER['REMOTE_ADDR']` only.
- A "Trusted proxy" setting (off by default) lets the admin choose a header: CF-Connecting-IP or X-Forwarded-For (first hop). Never trust these headers unless the setting is on — they are attacker-controlled otherwise.
- Validate every candidate IP with `filter_var( $ip, FILTER_VALIDATE_IP )` before use.
- IPv6 granularity: all per-IP counting, blocking, and hashing operates on the /64 prefix, never the full /128 address. Attackers rotate freely inside their /64; blocking a single address is useless. IPv4 operates on the full address.

## Dual IP storage: ip_display and ip_hash

- `ip_display`: anonymized form for humans (IPv4 last octet zeroed, IPv6 truncated to /64) via `ceog_anonymize_ip()`. Full IP stored here only when the admin explicitly enables full-IP logging, with a personal-data warning.
- `ip_hash`: `hash_hmac( 'sha256', $normalized_ip, wp_salt( 'auth' ) )` where `$normalized_ip` is the IPv4 address or the IPv6 /64 prefix. Used for repeat-attacker detection, per-IP breaker keys, and log correlation. Pseudonymous by design.
- Emails used in breaker counters are never stored in plaintext: counter keys use `hash_hmac( 'sha256', strtolower( $email ), wp_salt( 'auth' ) )`.

## Privacy (GDPR-relevant, this plugin logs visitor data)

- Log retention: fixed at 7 days in free (see Section 4); Pro unlocks
  30/90-day options. Daily WP-Cron prune either way. Build the setting as
  a filterable value so Pro extends it without schema changes.
- readme.txt must include a privacy section describing exactly what is logged, where it is stored (locally only), that hashes are one-way, and that nothing is sent to external services.
- `uninstall.php` drops the log table and deletes options only if `delete_data_on_uninstall` is enabled.

## Fail-open principle

If any Order Guard check throws or the log table is missing, checkout must PROCEED and the error must go to `WooCommerce > Status > Logs` via `wc_get_logger()`. A protection plugin that fatals a store's checkout is worse than the attack. Wrap every enforcement path so that plugin failure can never block legitimate orders.

## Emergency Recovery: CEOG_SAFE_MODE

Support the constant:

```php
define( 'CEOG_SAFE_MODE', true );
```

When CEOG_SAFE_MODE is true:

- Force Monitor Mode globally, regardless of saved settings.
- Disable ALL blocking actions: breakers, lists, honeypot, session requirement, Emergency Lockdown.
- Keep logging active if the log table is available.
- Keep all admin pages accessible.
- Log the Safe Mode state once per day.
- Show a persistent admin notice: "Order Guard Safe Mode is active — protection is monitoring only."

Every enforcement decision must pass through a single gate function (`ceog_is_enforcing()`) that checks, in order: SAFE_MODE constant → global enabled → mode setting. No blocking code path may bypass this gate.

## Log injection

Sanitize user agent, email, and route strings before storing (strip control characters, cap length) and escape on output in the log screen. The activity log renders attacker-controlled data; treat every column as hostile.

---

# 7. Plugin Folder Structure

```text
coderembassy-order-guard/
|-- coderembassy-order-guard.php
|-- uninstall.php
|-- readme.txt
|-- includes/
|   |-- class-ceog-plugin.php
|   |-- class-ceog-activator.php
|   |-- class-ceog-settings.php
|   |-- class-ceog-logger.php
|   |-- class-ceog-ip.php
|   |-- class-ceog-lists.php          // blocklist + whitelist engine
|   |-- class-ceog-store-api-guard.php // rate limit, routes, /batch inspector
|   |-- class-ceog-breakers.php       // per-IP, per-email, global
|   |-- class-ceog-origin-rules.php
|   |-- class-ceog-honeypot.php
|   |-- class-ceog-alerts.php
|   `-- class-ceog-dashboard.php
|-- admin/
|   |-- css/admin.css
|   `-- js/admin.js
`-- languages/
    `-- coderembassy-order-guard.pot
```

No public-facing CSS/JS in the MVP. The honeypot uses inline attributes; everything else is server-side.

---

# 8. Data Storage Plan

## Global Settings Option

One option: `ceog_settings`.

```php
array(
    'enabled'                  => 'yes',
    'mode'                     => 'monitor', // monitor | enforce
    // Circuit breakers (tiered)
    'breaker_ip_enabled'       => 'yes',
    'breaker_ip_threshold'     => 5,      // failed orders per IP (/64 for IPv6)
    'breaker_ip_window'        => 300,    // seconds
    'breaker_ip_block'         => 600,    // seconds blocked
    'breaker_email_enabled'    => 'yes',
    'breaker_email_threshold'  => 3,
    'breaker_email_window'     => 600,
    'breaker_email_block'      => 900,
    'breaker_global_enabled'   => 'yes',
    'breaker_global_threshold' => 20,
    'breaker_global_window'    => 300,
    'breaker_global_cooldown'  => 120,
    // Store API
    'rate_limit_enabled'       => 'yes',
    'rate_limit_limit'         => 25,     // requests
    'rate_limit_seconds'       => 10,
    'strict_session'           => 'no',   // Advanced
    'emergency_lockdown'       => 'no',   // Advanced / Emergency
    // Origin rule (v1: flag only)
    'unknown_origin_action'    => 'flag', // off | flag
    'unknown_origin_onhold'    => 'no',   // also move unpaid order to on-hold
    // Honeypot
    'honeypot_enabled'         => 'yes',
    // Lists
    'blocklist_emails'         => array(),
    'blocklist_email_domains'  => array(),
    'blocklist_ips'            => array(),
    'whitelist_ips'            => array(),
    'whitelist_roles'          => array( 'administrator', 'shop_manager' ),
    'whitelist_payment_methods'=> array(),
    // Logging & privacy
    'log_full_ip'              => 'no',
    'trusted_proxy'            => 'none', // none | cloudflare | xff
    'log_retention_days'       => 7,  // fixed seven-day Free cutoff; Pro owns a separate 30/90 pruner
    // Alerts
    'alert_email_enabled'      => 'yes',
    'alert_email'              => '',     // empty = admin_email
    'delete_data_on_uninstall' => 'no',
)
```

## Log Table

This plugin justifies exactly one custom table. An option or postmeta cannot handle attack-volume writes, and dashboard counts and filters must stay fast under attack load.

Table: `{$wpdb->prefix}ceog_log`

```sql
id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
event_time    DATETIME NOT NULL
event_type    VARCHAR(32) NOT NULL     -- breaker_ip_trip, breaker_email_trip,
                                       -- breaker_global_trip, blocked_add_item,
                                       -- blocked_checkout, blocked_batch_op,
                                       -- flagged_order, honeypot_hit,
                                       -- blocklist_hit, monitor_would_block
mode          VARCHAR(10) NOT NULL     -- monitor | enforce
ip_display    VARCHAR(64) NOT NULL     -- anonymized (or full if enabled)
ip_hash       CHAR(64) NOT NULL DEFAULT ''
route         VARCHAR(191) DEFAULT ''
order_id      BIGINT UNSIGNED DEFAULT 0
reason        VARCHAR(191) DEFAULT ''
meta          LONGTEXT                 -- JSON: user agent, email domain, etc.
KEY event_time (event_time)
KEY event_type_time (event_type, event_time)
KEY ip_hash_time (ip_hash, event_time)
KEY order_id (order_id)
KEY mode_time (mode, event_time)
```

Create with `dbDelta()` on activation. All queries through `$wpdb->prepare()`. Free daily cron `ceog_prune_log` deletes rows older than seven days; Pro uses its own hook for 30/90-day retention. Both use LIMIT 1000 per pass so pruning never locks the table under load.

## Breaker state

Transients only:

- `ceog_brk_ip_{hash}` — per-IP sliding window (array of timestamps) and block-until timestamp.
- `ceog_brk_em_{hash}` — per-email equivalent.
- `ceog_global_fails` — global sliding window of failed-order timestamps.
- `ceog_breaker_until` — UNIX timestamp while the global breaker is tripped.
- `ceog_alert_throttle` — prevents more than one alert email per hour.

---

# 9. Protection Layers and Detection Logic

Every layer follows the same pattern:

```text
1. Resolve request context (IP → /64-normalized + hashed, route, user,
   session, payment method).
2. Gate: ceog_is_enforcing() — SAFE_MODE forces monitor everywhere.
3. Check whitelist first. Whitelisted requests bypass everything.
4. Evaluate the rule.
5. If violated:
   - Monitor mode: write log row (mode=monitor, event_type=monitor_would_block
     or the specific type), ALLOW the request.
   - Enforce mode: write log row, BLOCK with the friendly message.
```

## Layer 1: Tiered circuit breakers

Counting: hook `woocommerce_order_status_failed`. For each failed order, record a timestamp in three sliding windows: the order's IP hash, the order's billing-email hash, and the global counter.

Tier behavior:

- **Per-IP breaker** (first line, smallest blast radius): when an IP hash accumulates `breaker_ip_threshold` failures inside `breaker_ip_window`, that IP hash is blocked from checkout for `breaker_ip_block` seconds. Neutralizes single-source attacks without affecting anyone else. Keep block durations short: CGNAT and mobile networks put many real customers behind one IP.
- **Per-email breaker**: same pattern keyed on the hashed billing email. Catches bots that rotate IPs but reuse emails.
- **Global breaker** (primary defense against distributed attacks): when `breaker_global_threshold` failures occur inside `breaker_global_window` across ALL sources, checkout pauses store-wide for `breaker_global_cooldown` seconds, then reopens automatically. Distributed card-testing rotates IPs and emails, so the per-key breakers may never trip — the global breaker is what actually stops a botnet flood. A 2-minute pause with whitelisted payment methods still working is a trivial cost against a card-testing attack.

Blocking while any applicable breaker is tripped, in BOTH flows:

- Classic: `woocommerce_checkout_process` → `wc_add_notice( $msg, 'error' )`.
- Store API: intercept `POST /wc/store/v1/checkout` (and checkout operations inside /batch) via `rest_request_before_callbacks` → `WP_Error( 'ceog_breaker', $msg, array( 'status' => 503 ) )`.

Friendly message (translation-ready): "Checkout is temporarily paused due to unusual activity. Please try again in a couple of minutes."

Auto-reset by timestamp comparison; no cron needed. Whitelisted roles/IPs/payment methods can always check out.

## Layer 2: Store API hardening

- **Rate limiting:** when enabled, filter `woocommerce_store_api_rate_limit_options` to return enabled=true with the configured limit/seconds and proxy support matching the trusted-proxy setting. This wraps WooCommerce's built-in limiter (POST requests only) rather than reinventing it.
- **Native-setting coexistence:** detect whether WooCommerce's own checkout rate limiting is already enabled. If it is, display its status on the Order Guard settings page and do not override or duplicate it. Order Guard's rate limiting covers cart mutation volume; WooCommerce's covers place-order attempts. Show both statuses side by side so the store owner sees the whole picture.
- **Strict Session Requirement (Advanced, off by default):** for `POST` requests to `/wc/store/v1/cart/*` (including embedded batch operations), require an existing WooCommerce session cookie. To keep real visitors working, hook `woocommerce_init` and, on normal front-end page loads, call `WC()->session->set_customer_session_cookie( true )` so every human gets a session before their first cart action. Absence of a session is a signal, not proof: the settings description must warn, verbatim, "This may break headless, custom, mobile-app, and some express checkout flows. Use Monitor Mode first." Monitor mode logs `monitor_would_block`; enforce blocks with 403.
- **Emergency Store API Checkout Lockdown (Advanced/Emergency, off by default):** returns 404 for `POST /wc/store/v1/checkout` (and checkout operations inside /batch). Guard rails: refuse to enable when the checkout page uses the Checkout block; permanent red admin warning while enabled; explicitly documented as an emergency switch for classic-checkout stores under active attack, never part of normal protection or onboarding; never suggested by defaults.

## Layer 3: Store API /batch inspection

The Store API supports `POST /wc/store/v1/batch`, which wraps multiple embedded operations. Route-matching alone would let bots smuggle cart and checkout operations through /batch.

Requirements:

- In `rest_request_before_callbacks`, when the route is `/wc/store/v1/batch` with method POST, parse the embedded `requests` array.
- For each embedded operation, extract `path` and `method`, and apply the SAME monitor/enforce rules as if the request hit that endpoint directly: strict session (cart mutations), breaker state (checkout operations), Emergency Lockdown (checkout operations), blocklists where applicable.
- Rate-limit accounting: count each embedded operation, not the batch envelope, toward Order Guard's volume logging — otherwise a bot packs its entire attack into single batch requests and pays for one. (WooCommerce's own limiter counts the envelope; log the discrepancy so the store owner can see batch abuse in the activity log as `blocked_batch_op` / monitor equivalents.)
- If any embedded operation is blocked in enforce mode, reject the whole batch request with the corresponding WP_Error; partial-batch surgery is error-prone and unnecessary.
- Malformed batch bodies (non-array, missing paths) are logged and, in enforce mode, rejected with a 400.

## Layer 4: Unknown-origin rule (v1: flag only)

Unknown Origin is a review signal, not a fraud verdict. In v1 it flags orders; it must not block checkout by itself. Legitimate causes include privacy browsers, blocked tracking scripts, attribution failures, and wallet/express flows.

- Evaluate at order creation: hook `woocommerce_checkout_order_processed` (classic) and `woocommerce_store_api_checkout_order_processed` (blocks/API).
- Read the order attribution meta (`_wc_order_attribution_source_type`). If missing or `unknown` AND the customer is a guest with no completed orders:
  - Add an order note: "Order Guard: unknown origin — review before fulfilment."
  - Record the signal in order meta for the metabox.
  - If `unknown_origin_onhold` is enabled AND the order is not paid, move it to on-hold.
- Never apply this rule to whitelisted payment methods (wallet/express payments often lack attribution legitimately).
- No cancel, no block, no touching paid orders. Blocking based on origin is a Pro-tier decision for later, combined with stronger signals.

## Layer 5: Classic checkout honeypot

- Output a text input via `woocommerce_after_order_notes`, visually hidden with inline `style="position:absolute;left:-9999px"` and `aria-hidden="true" tabindex="-1" autocomplete="off"`. Field name randomized per site from a stored salt.
- In `woocommerce_checkout_process`: if the field is non-empty → log `honeypot_hit` → monitor allows, enforce blocks with a generic error.
- Accessibility rule: `aria-hidden` and `tabindex="-1"` are mandatory so screen readers and keyboard users never encounter it. Verify with a screen reader before finishing the phase.
- Block checkout cannot host this field in v1. The Pro roadmap item is a broader "Block Checkout protection layer" built on the WooCommerce Additional Checkout Fields API (`woocommerce_register_additional_checkout_field()` after `woocommerce_init`, WooCommerce 8.9.0+). Say so in the FAQ.

## Layer 6: Blocklists

- Checked in `woocommerce_after_checkout_validation` (classic) and the Store API order-processed hook: billing email exact match, email domain match, IP match (/64-aware for IPv6).
- Admin can add an email/IP to the blocklist with one click from the order edit screen (metabox button, nonce + capability protected).

---

# 10. Admin UX

## Settings Page

Location: `WooCommerce -> Order Guard`.

Sections:

1. Status — big Monitor/Enforce switch, Safe Mode indicator, breaker statuses, counts.
2. Circuit Breakers — per-IP, per-email, global: thresholds, windows, durations, each with a one-line plain-English false-positive note.
3. Store API — rate limit (with native WooCommerce rate-limit status displayed), /batch inspection status (always on, informational).
4. Order Rules — unknown-origin flag toggle + on-hold option, honeypot toggle.
5. Lists — blocklist and whitelist editors (textarea, one entry per line).
6. Privacy & Logs — IP anonymization, trusted proxy, retention.
7. Alerts — email toggle, recipient.
8. Advanced — Strict Session Requirement and Emergency Store API Checkout Lockdown, each behind an "I understand the risks" style confirmation, with the exact warning texts specified in section 9.

Every risky toggle gets a one-line plain-English description of the false-positive risk.

## Activity Log screen

- `WP_List_Table` under the settings page: time, type, mode, ip_display, route, reason, order link.
- Filter by event type and date; a "same attacker" filter by ip_hash when clicking a row. Bulk delete. (CSV export is v1.1.)

## Dashboard widget

- Blocked/flagged today and last 7 days, breaker statuses, last event time. Numbers link to the filtered log. Counts served from prepared queries behind a short transient cache.

## Order edit metabox

- Shows Order Guard signals for the order (origin, session presence at checkout, list matches, breaker context) and "Block this email / IP" buttons (nonce + capability protected). HPOS-compatible screen registration.

---

# 11. Hook Targets

```php
// Orders and checkout
woocommerce_order_status_failed
woocommerce_checkout_process
woocommerce_after_checkout_validation
woocommerce_checkout_order_processed
woocommerce_store_api_checkout_order_processed
woocommerce_after_order_notes

// Store API / REST
rest_request_before_callbacks        // routes + /batch inspector
woocommerce_store_api_rate_limit_options
woocommerce_init                     // session cookie for real visitors

// Admin
admin_menu, admin_init, admin_enqueue_scripts
add_meta_boxes (order screen, HPOS-compatible screen id)

// Cron
ceog_prune_log (custom daily event)

// Compatibility
before_woocommerce_init (FeaturesUtil declarations)
```

---

# 12. Build Phases

Build one phase at a time. Do not move on until the current phase works. Lists come early because every later layer consults them.

## Prompt for Phase 1: Foundation and Compatibility

```text
Implement Phase 1: Foundation and Compatibility.

Requirements:
- Main plugin file with header, ABSPATH guards everywhere.
- WooCommerce dependency check with admin notice; no logic runs without WooCommerce.
- CEOG_Plugin singleton wiring classes via hooks.
- CEOG_Activator: create the ceog_log table (schema from the Data Storage
  Plan, including ip_display, ip_hash, and all five keys) with dbDelta,
  schedule the daily ceog_prune_log event, store a db_version option.
- Deactivation clears the cron event only. uninstall.php drops the table
  and options only when delete_data_on_uninstall is enabled.
- Declare HPOS and cart_checkout_blocks compatibility via FeaturesUtil.
- Fail-open wrapper utility: ceog_safe( callable ) catching Throwable,
  logging to wc_get_logger() channel 'order-guard', returning a safe default.
- ceog_is_enforcing() gate function: returns false when CEOG_SAFE_MODE is
  defined true, when the plugin is disabled, or when mode is monitor.
  Every future blocking path must call this gate.
- CEOG_SAFE_MODE admin notice when active.

Security:
- No raw SQL outside schema creation and prepared log queries.
- Capability checks on any admin-facing output.

After implementation:
- Explain how to verify table creation, indexes, cron scheduling, and the
  Safe Mode notice.
- Stop and wait for Phase 2.
```

## Prompt for Phase 2: Settings and Safe Mode Surface

```text
Implement Phase 2: Settings and Safe Mode Surface.

Requirements:
- CEOG_Settings class, single ceog_settings option, defaults exactly as in
  the Data Storage Plan.
- Settings page under WooCommerce -> Order Guard with the eight sections.
- Monitor/Enforce switch rendered prominently; Safe Mode indicator shown
  when the constant is active (settings remain visible but a banner
  explains enforcement is suspended).
- Advanced section: Strict Session Requirement and Emergency Lockdown with
  confirmation interactions and the exact warning texts from the plan.
- Refuse to enable emergency_lockdown when the checkout page uses the
  woocommerce/checkout block; show an explanatory inline warning.
- Detect WooCommerce's native checkout rate limiting and display its
  status read-only in the Store API section.
- Sanitize every field on save: absint with sane min/max clamps for
  numerics, sanitize_email, validated IPs, yes/no normalization, arrays
  unslashed then per-value sanitized.
- Nonce + manage_woocommerce capability on save.

After implementation:
- Explain how to test saving, clamping, the block-checkout guard, and the
  native rate-limit detection.
- Stop and wait for Phase 3.
```

## Prompt for Phase 3: Logger, IP Privacy, and ip_hash

```text
Implement Phase 3: Logger, IP Privacy, and ip_hash.

Requirements:
- CEOG_IP: resolve client IP per trusted-proxy setting; validate with
  filter_var; normalize IPv6 to its /64 prefix for all counting, hashing,
  and matching; anonymize for display (IPv4 last octet zeroed, IPv6 /64);
  ceog_ip_hash() using hash_hmac sha256 with wp_salt('auth') over the
  normalized IP; ceog_email_hash() equivalent for lowercased emails.
- CEOG_Logger: insert rows with $wpdb->prepare; populate ip_display per
  the log_full_ip setting and ip_hash always; sanitize and cap all string
  fields; JSON-encode meta.
- Free daily prune honoring the fixed seven-day cutoff, deleting in batches of 1000.
- Activity Log WP_List_Table screen with type/date filters, ip_hash
  "same attacker" filter, bulk delete. No CSV export in v1.
- Escape every rendered value; treat all logged data as hostile.

After implementation:
- Explain how to test logging, IPv6 /64 normalization, hashing stability,
  pruning, and anonymization.
- Stop and wait for Phase 4.
```

## Prompt for Phase 4: Lists and Whitelist Engine

```text
Implement Phase 4: Lists and Whitelist Engine.

Requirements:
- CEOG_Lists class exposing two shared services used by every later layer:
  ceog_is_whitelisted( $context ) checking user roles, IPs (/64-aware),
  and payment method; and blocklist matching for billing email, email
  domain, and IP.
- Blocklist enforcement in woocommerce_after_checkout_validation (classic)
  and prepared for the Store API order-processed hook (wired fully in
  Phase 6): monitor logs blocklist_hit and allows; enforce blocks with a
  generic error through the ceog_is_enforcing() gate.
- Order edit metabox (HPOS-compatible) showing Order Guard signals plus
  one-click "Block this email / IP" actions with nonce + capability
  checks.

After implementation:
- Explain how to test whitelist precedence and blocklist matching in both
  modes.
- Stop and wait for Phase 5.
```

## Prompt for Phase 5: Store API Guard — Rate Limiting, Routes, and /batch

```text
Implement Phase 5: Store API Guard.

Requirements:
- CEOG_Store_API_Guard class.
- Rate limiting: filter woocommerce_store_api_rate_limit_options from
  settings, including proxy support flag; never override WooCommerce's
  native checkout rate limiting (display-only coexistence).
- Route interception in rest_request_before_callbacks with precise
  matching for /wc/store/v1/cart/* POST and /wc/store/v1/checkout POST.
- /batch inspector: for POST /wc/store/v1/batch, parse the embedded
  requests array; apply the same rules per embedded path+method; log
  each embedded violation (blocked_batch_op or monitor equivalent);
  reject the whole batch on any enforced violation; reject malformed
  batch bodies with 400 in enforce mode.
- Strict Session Requirement (when enabled): ensure session cookies for
  real page views on woocommerce_init; block or log sessionless cart
  mutations per mode, including inside /batch.
- Emergency Lockdown (when enabled): 404 for Store API checkout,
  including inside /batch.
- Whitelist check before every rule; every block through the
  ceog_is_enforcing() gate and the fail-open wrapper.
- Never touch GET requests or non-Store-API routes.

After implementation:
- Explain how to test with curl: direct add-item, direct checkout, and
  batch-wrapped equivalents, with and without session cookies, in both
  modes.
- Stop and wait for Phase 6.
```

## Prompt for Phase 6: Tiered Circuit Breakers

```text
Implement Phase 6: Tiered Circuit Breakers.

Requirements:
- CEOG_Breakers class with three sliding-window counters fed by
  woocommerce_order_status_failed: per-IP (ip_hash of /64-normalized IP),
  per-email (email hash), and global.
- Trip logic and auto-reset by timestamp comparison per tier, using the
  transient keys and defaults from the Data Storage Plan.
- Blocking while tripped in both classic checkout (wc_add_notice error)
  and Store API checkout including /batch (WP_Error 503), respecting
  whitelists and the enforcement gate:
  - Per-IP trip blocks only requests matching that ip_hash.
  - Per-email trip blocks only checkouts using that email hash.
  - Global trip pauses all non-whitelisted checkout.
- Wire the Store API order-processed blocklist check from Phase 4 fully.
- Friendly translation-ready pause message.
- Log breaker_ip_trip / breaker_email_trip / breaker_global_trip and each
  blocked attempt; monitor mode logs and allows.

After implementation:
- Explain how to simulate single-source and distributed failed-order
  patterns on staging to trip each tier independently.
- Stop and wait for Phase 7.
```

## Prompt for Phase 7: Classic Checkout Honeypot

```text
Implement Phase 7: Classic Checkout Honeypot.

Requirements:
- CEOG_Honeypot: hidden field on classic checkout via
  woocommerce_after_order_notes, randomized name from a stored salt,
  aria-hidden="true" tabindex="-1" autocomplete="off", inline offscreen
  style. Validation in woocommerce_checkout_process through the
  enforcement gate.
- Generic error message on hit; never reveal the mechanism.
- Monitor mode logs honeypot_hit without blocking.
- FAQ note: the Block Checkout protection layer (Additional Checkout
  Fields API, WooCommerce 8.9+) is a planned Pro feature.

Accessibility:
- The field must be unreachable by keyboard and invisible to screen
  readers. Verify with a screen reader before finishing.

After implementation:
- Explain how to test with a bot-like POST including the field.
- Stop and wait for Phase 8.
```

## Prompt for Phase 8: Unknown-Origin Flagging

```text
Implement Phase 8: Unknown-Origin Flagging.

Requirements:
- CEOG_Origin_Rules: evaluate attribution on both order-processed hooks
  (classic and Store API); guests without completed orders only; never
  for whitelisted payment methods.
- Flag only: order note, order meta signal for the metabox, optional
  on-hold for unpaid orders when unknown_origin_onhold is enabled.
- No blocking, no cancelling, no touching paid orders — regardless of
  Enforce mode. Document in code comments that origin is a review
  signal, not a fraud verdict.
- Log flagged_order events.

After implementation:
- Explain how to test flagging via a raw Store API order with no
  attribution and via a normal browser order (which must NOT be flagged).
- Stop and wait for Phase 9.
```

## Prompt for Phase 9: Alerts and Dashboard

```text
Implement Phase 9: Alerts and Dashboard.

Requirements:
- CEOG_Alerts: wp_mail on any breaker trip, identifying which tier
  tripped, throttled to one email per hour via transient, recipient from
  settings or admin_email.
- CEOG_Dashboard: dashboard widget with today/7-day blocked+flagged
  counts, per-tier breaker status, links to the filtered activity log.
  Efficient prepared COUNT queries using the composite indexes, behind a
  short transient cache.

After implementation:
- Explain how to test alert throttling and widget counts under load.
- Stop and wait for Phase 10.
```

## Prompt for Phase 10: Safety Review and Release Preparation

```text
Implement Phase 10: Safety Review and Release Preparation.

Safety audit:
- Simulate a missing log table, a corrupted settings option, and a thrown
  exception inside each layer: checkout must proceed in all cases with the
  error in the WooCommerce logger (fail-open verified).
- Verify CEOG_SAFE_MODE forces monitor behavior across every layer,
  including Emergency Lockdown and Strict Session Requirement.
- Verify express/wallet flows are untouched when whitelisted, including
  while the global breaker is tripped.
- Verify nothing varies per visitor on cacheable page output.
- Verify zero added queries on idle front-end page views (protection runs
  only on checkout/REST requests, except the lightweight session cookie
  hook).

Release preparation:
- readme.txt: description leads with card-testing/fake-order protection
  at the API level; FAQ answers "Why doesn't my captcha stop these?",
  "Will this block real customers?" (monitor-first + whitelists + Safe
  Mode), "Does it work with the Checkout block?", "Does it inspect batch
  requests?" (Yes), "Is any data sent to external services?" (No), and
  the express-payments whitelist note; full privacy section covering
  ip_display, ip_hash, retention, and local-only storage.
- Screenshots: activity log during an attack, the Monitor/Enforce switch,
  the tiered breaker settings, the dashboard widget.
- All strings translation-ready; generate the .pot file.
- Full security review pass identical to Quantity Guard Phase 10, plus
  the Plugin Check plugin with zero errors.
- Cross-link Quantity Guard in the readme.

After implementation:
- Summarize the final plugin, the testing checklist results, and the Pro
  candidates.
```

---

# 13. Testing Checklist

## General

- Activates/deactivates cleanly; no warnings with WP_DEBUG.
- Log table created with all five indexes; cron scheduled; batched prune works.
- Plugin Check passes with no errors.

## Attack simulation (staging only)

- Script 10 rapid POSTs to /wc/store/v1/cart/add-item without a session:
  monitor logs all; enforce (strict session on) blocks all.
- Repeat the same operations wrapped in POST /wc/store/v1/batch: identical
  outcomes, logged as batch operations. THIS IS THE KEY BYPASS TEST.
- Trip the per-IP breaker from one source: only that source is blocked;
  a second IP checks out normally.
- Trip the per-email breaker with rotating IPs and one email.
- Trip the global breaker with distributed failures (rotate IPs and
  emails): checkout pauses in BOTH classic and block checkout, reopens
  after cooldown, one alert email sent naming the tier.
- Submit classic checkout with the honeypot filled: blocked in enforce,
  logged in monitor.
- Place an order via raw Store API with no attribution: order flagged
  with the Order Guard note (and on-hold when that option is enabled);
  checkout is NOT blocked.
- Blocklisted email/domain/IP cannot complete checkout in enforce mode.
- Emergency Lockdown returns 404 for Store API checkout, direct and
  batch-wrapped; the option cannot be enabled while the Checkout block
  is in use.

## False-positive safety

- Real browser guest checkout: never blocked with default settings.
- Customer with a declined card retries twice: never blocked (below every
  threshold).
- Whitelisted wallet payment completes while the global breaker is tripped.
- Two "customers" behind one IP (CGNAT simulation): per-IP block from one
  does expire on schedule; document the shared-IP tradeoff in the FAQ.
- IPv6 attacker rotating within one /64: counted as ONE source (breaker
  trips); two different /64s are counted separately.
- Screen reader (NVDA or VoiceOver) never announces the honeypot; Tab
  never reaches it.
- With the log table manually dropped, checkout still completes (fail-open).
- define( 'CEOG_SAFE_MODE', true ): every blocking behavior stops, the
  admin notice appears, logging continues.

## Privacy

- Default logs contain anonymized ip_display and populated ip_hash only.
- Full-IP logging appears only after explicitly enabling it.
- Retention prune removes old rows in batches.
- Uninstall with delete_data enabled removes table and options.

---

# 14. Final Notes for Codex

Build cleanly. The priorities, in order:

1. Never block a real customer. Monitor-first, whitelists, flag-only
   origin rule, Safe Mode, fail-open.
2. Actually stop volume attacks at the API level — including /batch, and
   including distributed attacks (the global breaker is the load-bearing
   layer, not a fallback).
3. Make the activity log tell a clear story the store owner trusts.
4. Zero external dependencies and honest privacy (hashes, anonymization,
   retention, local-only).
5. Easy Pro expansion.

Never market this as "fraud detection AI" or guarantee protection. It is
an attack-pattern blocker, and the copy must stay honest — that honesty
is part of the CoderEmbassy Guard brand.

---

# 15. AI Features (Pro Roadmap — NOT in v1)

This section is a roadmap, not a v1 build target. Do NOT implement any of
it during the 11 core phases. It is recorded here so the boundaries are
locked before anyone builds on top of the plugin. AI belongs in a later
Pro release (target Pro 1.1 or 2.0), after the deterministic core has
shipped and earned reviews, and after there are real attack logs to design
the prompts against.

## The one non-negotiable rule: AI explains, rules block

AI must NEVER make or influence a blocking decision. The entire protection
path stays deterministic PHP — breakers, rules, thresholds, whitelists.
Reasons, all of which are load-bearing:

- Latency: an LLM call adds seconds to a checkout path that must be instant.
- Non-determinism: "the AI blocked your real customer" is exactly the
  false-positive failure the monitor-first design exists to prevent.
- Dependency: an LLM in the blocking path breaks the zero-SaaS and
  fail-open guarantees.

So the division is absolute: rules decide, AI narrates. This is also a
positioning strength against competitors who market "AI fraud detection":
"We don't let an AI guess about your customers. We let AI explain what the
rules already caught."

## AI features, in priority order

1. **AI Attack Report (hero).** After a breaker trips or on demand,
   summarize anonymized attack aggregates into a plain-English incident
   report for a non-technical store owner: what happened, when, how big,
   what Order Guard did, and recommended next steps (from a fixed action
   list). Chains naturally into the Pro cleanup feature.
2. **"Explain this order" button.** On a flagged order's metabox, turn the
   order's signals (unknown origin, no session, first-time guest, velocity
   context) into two human sentences plus a fulfill/review recommendation.
3. **Threshold tuning suggestions.** After a week of monitor-mode data,
   suggest breaker thresholds fitted to the store's real traffic. Try
   deterministic statistics FIRST (cheaper, more defensible); add AI
   narration on top only if it demonstrably helps.
4. **AI help assistant.** Answers setup questions from bundled docs.
   Lowest priority.

## Architecture: BYOK, OpenAI-compatible, Groq as the suggested free default

- **Bring Your Own Key (BYOK), Pro-only, opt-in, off by default.** The
  free plugin's "nothing leaves your site, no API keys" promise is a core
  differentiator and must never be diluted. AI is a Pro feature the user
  explicitly enables with their own key.
- **One OpenAI-compatible code path.** Use the OpenAI-compatible chat
  completions interface with a swappable base URL, so a provider dropdown
  (Groq / OpenAI / Anthropic-compatible / OpenRouter / custom) is a single
  integration, not several. Groq's endpoint is
  `https://api.groq.com/openai/v1`.
- **Groq suggested as the free default.** Groq's forever-free, no-credit-
  card tier (roughly 30 req/min, ~1,000 req/day per model) is permanently
  sufficient for this workload, because reports are low-frequency (a
  handful per day even on an attacked store). UI copy: "Get a free API key
  at console.groq.com — no credit card required." Users who prefer their
  existing OpenAI/Anthropic keys just switch the dropdown.
- **No hardcoded model names.** Open-model providers retire models without
  warning. The model is a user-selectable setting with a sensible default;
  on a model-not-found / 404, show a clear admin notice ("Your selected AI
  model is no longer available — pick another in settings"), never fail
  silently.
- **Graceful rate-limit and error handling.** On 429, respect the
  retry-after header and retry, or queue the report. Reports are never
  time-critical; an AI hiccup must never touch checkout, blocking, or any
  other plugin function. All AI calls run outside the protection path,
  wrapped in the fail-open utility.

## Data and privacy rules (hard)

- **Send only anonymized aggregates.** Event counts, timelines, hashed-IP
  source counts, email-domain patterns — the same data already in the
  activity log. NEVER send customer PII (names, addresses, full emails,
  order contents, full IPs) to any AI provider by default.
- **Disclose plainly in the UI**, especially because some free tiers may
  use submitted data to improve their models: "Free-tier AI providers may
  use submitted data for training. Order Guard sends only anonymized
  attack statistics, never customer data." Verify each provider's current
  data-use terms before shipping and keep this text accurate.
- **Prompt guardrails.** Instruct the model to use only the numbers
  provided, never invent statistics, and say so when data is insufficient;
  run at low temperature for consistent output.

## Naming discipline

Call these features what they are: "AI attack reports" and "AI order
explanations." Never "AI fraud detection" or "AI protection" — that is the
dishonest-claims trap the rest of this plan forbids, and it invites exactly
the scrutiny that hit overlay/automated-compliance vendors elsewhere.

## Draft report-generation prompt template (starting point)

```text
System:
You are a security incident summarizer for a WooCommerce store's admin.
Write a short, calm, plain-English report for a NON-TECHNICAL store owner.
Use ONLY the numbers in the provided data. Never invent figures. If the
data is insufficient for a claim, say so. Do not speculate about the
attacker's identity. End with 2-4 concrete recommendations chosen ONLY
from the ALLOWED_ACTIONS list. Keep it under 180 words. Neutral tone, no
alarmism, no emojis.

User:
DATA (anonymized aggregates, no customer PII):
{
  "window": "2:14-3:40 AM, 2026-07-11",
  "event_total": 214,
  "blocked_total": 214,
  "breaker_tripped": "global",
  "distinct_ip_sources": 40,
  "top_email_domains": [{"domain":"...","count":...}, ...],
  "failed_orders_remaining": 1847,
  "mode": "enforce"
}
ALLOWED_ACTIONS:
- Run the Pro attack cleanup to remove leftover failed/junk orders.
- Review circuit breaker thresholds in Settings.
- Enable auto-blocklisting for repeat sources (Pro).
- Keep monitoring the Activity Log for renewed activity.

Write the report now.
```

Refine this against real logs once the core plugin has collected them.
The prompt does the heavy lifting; the model choice is secondary because
this task is structured-data summarization — the easiest, most reliable
job in the LLM repertoire.
