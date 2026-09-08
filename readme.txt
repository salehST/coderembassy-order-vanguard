=== CoderEmbassy Order Vanguard ===
Contributors: codersaleh
Tags: woocommerce, security, fraud, card testing, fake orders
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.16
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

API-level WooCommerce protection against card testing, automated fake orders, and abusive checkout traffic.

== Description ==

Order Vanguard protects classic checkout, Checkout Block, Store API cart traffic, and Store API batch requests with monitor-first circuit breakers, request controls, lists, and review signals. The Free plugin has no CAPTCHA keys, subscriptions, remote scoring services, or external data transfers.

For product quantity rules and customer-friendly purchase limits, see [CoderEmbassy Quantity Manager for WooCommerce](https://coderembassy.com/).

== Privacy ==

Order Vanguard stores protection events only in this site's WordPress database. A log row can contain the event time and type, protection mode, request route, reason, order ID, a sanitized user agent, `ip_display`, and a one-way `ip_hash` correlation value.

By default, IPv4 addresses are anonymized by replacing the last octet with zero. IPv6 addresses are reduced to their /64 network. The correlation hash is generated locally with HMAC-SHA256 and the site's WordPress authentication salt. It is used to recognize repeat traffic without storing the original address in the hash. Email counters use the same one-way approach over a lowercased email address; plaintext email addresses are not written to the protection log.

Full IP display storage is optional and disabled by default. Enabling it may store personal data and should be covered by the site's privacy policy and legal basis.

Activity history is retained for seven days and pruned daily in bounded batches. Nothing is sent to CoderEmbassy or any third party. The merchant can choose to remove the plugin's table and options during uninstall.

== Installation ==

1. Install and activate WooCommerce.
2. Upload and activate CoderEmbassy Order Vanguard.
3. Open WooCommerce > Order Vanguard.
4. Keep Monitor mode enabled while reviewing Activity Log events.

== Frequently Asked Questions ==

= Why doesn't my CAPTCHA stop card testing? =

Many attacks send requests directly to WooCommerce Store API endpoints instead of interacting with the visible checkout form. Order Vanguard evaluates those server-side requests, including operations embedded in Store API batch requests. It complements a CAPTCHA rather than replacing one.

= Will this block real customers? =

Order Vanguard starts in Monitor mode, where it records decisions without blocking. Review the Activity Log before switching to Enforce. Whitelists preserve trusted roles, IPs, and payment methods, and `CEOG_SAFE_MODE` immediately suspends every blocking action. Per-IP blocks are intentionally short because mobile, office, and CGNAT networks may represent several customers behind one address.

= Does it work with the Checkout block? =

Yes. Store API request controls, batch inspection, lists, and circuit breakers protect Checkout Block traffic. The Free honeypot remains classic-checkout only. When Pro is active, it adds a separate site-specific Checkout Block honeypot through WooCommerce's Additional Checkout Fields API.

= Does it inspect Store API batch requests? =

Yes. Each embedded operation is normalized and evaluated individually. In Enforce mode, a protected or malformed operation rejects the batch envelope instead of allowing the operation to bypass direct-route controls.

= Is any data sent to external services? =

The Free plugin sends no protection decisions, logs, hashes, settings, or dashboard aggregates to an external service. If the merchant explicitly enables the optional Pro Cloudflare Turnstile integration with their own keys, the shopper's browser connects to Cloudflare and the server validates a single-use token with Cloudflare Siteverify; Order Vanguard does not store that token.

= How should express and wallet payments be configured? =

Add trusted express and wallet gateway keys to the payment-method whitelist. Whitelisted methods bypass lists and circuit breakers, including a global cooldown, because those flows can legitimately omit normal checkout signals. Confirm the exact gateway key in WooCommerce payment settings.

= Does the honeypot protect the Checkout block? =

The Free honeypot protects classic checkout. Order Vanguard Pro adds an optional, site-specific Checkout Block honeypot through WooCommerce's Additional Checkout Fields API (WooCommerce 8.9+), while preserving the same Monitor-first and fail-open safety behavior.

== Screenshots ==

1. Branded protection dashboard with live counts and the Monitor/Enforce control.
2. Server-paginated Activity Log during protected checkout traffic.
3. Per-IP, per-email, and global circuit breaker settings.
4. Protection settings and Safe Mode-aware controls.

== Source Code and Build Process ==

The complete human-readable source code, build configuration, and release tools are publicly maintained at [github.com/salehST/coderembassy-order-vanguard](https://github.com/salehST/coderembassy-order-vanguard).

The generated `build/index.js` and styles are compiled from the files in `src/`. To reproduce the distributed assets, install a current Node.js LTS release and run these commands from the repository root:

`npm ci`
`npm run build`
`npm run make-pot`
`npm run release:audit`
`npm run package:release`

The build uses the WordPress packages listed in `package.json` and [lucide-react](https://github.com/lucide-icons/lucide), an ISC-licensed icon library. Exact dependency versions and source package URLs are recorded in `package-lock.json`.

== Changelog ==

= 1.0.16 =
* Reworded the retention description with neutral, accurate wording.

= 1.0.15 =
* Documented the public human-readable source repository and reproducible build process.
* Documented the bundled Lucide icon source, license, and dependency lockfile.

= 1.0.14 =
* Renamed the public plugin identity to CoderEmbassy Order Vanguard.
* Updated the package slug, bootstrap filename, text domain, and admin URL to `coderembassy-order-vanguard`.
* Preserved existing `CEOG_*` compatibility constants and `ceog_*` settings, tables, hooks, and stored data.

= 1.0.13 =
* Applied the initial distinctive-name update requested by the WordPress Plugins Team.
* Updated the package identity while preserving all existing protection data.
* Hid and route-guarded the Pro License workspace on Free-only installations.

= 1.0.12 =
* Standardized activity-log retention at seven days with daily bounded pruning.
* Added the codersaleh contributor metadata for the WordPress.org submission.

= 1.0.11 =
* Added the GPL license declaration required by WordPress.org review.
* Added the current Tested up to header and escaped a customer-facing blocklist message.

= 1.0.10 =
* Added the separate Pro Alerts navigation route for privacy-safe Webhook and Slack breaker summaries.
* Kept alert delivery disabled by default and the Free protection surface unchanged.

= 1.0.9 =
* Added the responsive Turnstile navigation route for the separate Pro checkout-verification workspace.
* Clarified that external Cloudflare communication occurs only when the merchant explicitly enables optional Pro Turnstile with their own keys.

= 1.0.8 =
* Added a shared site-salted field-name extension contract for the separate Pro Checkout Block honeypot.
* Added Checkout Block field protection status to the responsive Store API Guard environment summary when Pro is active.

= 1.0.7 =
* Added separate Attack Cleanup and Pro License routes to the shared Order Vanguard navigation when Pro is active.
* Added responsive, accessible host components for the Pro license banner and expired-license reminder while keeping licensing logic inside the separate Pro add-on.
* Preserved complete Free workspace access when the Pro license soft lock is active.

= 1.0.6 =
* Added the Pro-only filtered CSV export action to Activity Log.
* Added bounded export batching support without repeated count queries.

= 1.0.5 =
* Added the shared History & Reports navigation route for the separate Pro add-on.
* Made embedded Pro route mounting deterministic on direct page reloads.

= 1.0.4 =
* Added the extension contract used by Pro temporary automatic blocklists.
* Added a dedicated Auto Blocklisting navigation screen and audit event labels when Pro is active.

= 1.0.3 =
* Embedded Attack Cleanup inside the existing Order Vanguard React workspace.
* Keeps desktop navigation and the mobile drawer available while using Pro tools.

= 1.0.2 =
* Consolidated Free and Pro under the single Order Vanguard WordPress menu.
* Shows Attack Cleanup in the Order Vanguard navigation when Pro is active.

= 1.0.1 =
* Added a secure admin bootstrap extension point for the separate Pro add-on.
* Shows active Pro status and links to the Pro workspace when the add-on is available.

= 1.0.0 =
* Added fail-open release safeguards across lists, Store API controls, honeypot rules, circuit breakers, and order-origin review signals.
* Added Safe Mode, idle-storefront, missing-build, translation, CSS-scope, and package integrity release gates.
* Completed responsive mobile navigation, privacy documentation, localization files, and production release packaging.

= 0.10.0 =
* Added cached dashboard counts, seven-day activity bars, recent events, and live breaker countdowns.
* Added visibility-aware polling with failure backoff and contextual attack-cleanup guidance.
* Added privacy-safe breaker-trip email alerts throttled to one message per hour.

= 0.9.0 =
* Added unknown-origin review signals for first-time guest orders from classic and Store API checkout.
* Added HPOS-compatible order notes, metabox metadata, and optional unpaid-order holds.
* Excluded paid orders, returning guests, registered customers, and whitelisted payment methods.

= 0.8.0 =
* Added a site-specific classic checkout honeypot with monitor-first logging.
* Added Enforce-mode blocking with a generic checkout error and fail-open handling.
* Kept the hidden field outside keyboard and screen-reader navigation.

= 0.7.2 =
* Replaced horizontal mobile navigation with an accessible slide-in drawer and overlay.
* Added automatic close after navigation, Escape dismissal, focus handling, and scroll locking.

= 0.7.1 =
* Improved mobile navigation, touch targets, responsive actions, and form-control behavior.

= 0.7.0 =
* Added per-IP, per-email, and global failed-order circuit breakers.
* Added classic, Store API, and batch checkout enforcement with automatic cooldowns.
* Added live breaker status and settings panels, plus Store API order blocklist enforcement.

= 0.6.0 =
* Added Store API cart rate limiting through WooCommerce's native limiter.
* Added strict-session and emergency-checkout enforcement with Safe Mode support.
* Added per-operation Store API batch inspection and malformed-batch handling.

= 0.5.1 =
* Added searchable WordPress role and WooCommerce payment-method selectors.
* Preserved manual payment-method keys for custom and wallet integrations.

= 0.5.0 =
* Added whitelist-first role, IP, and payment-method matching.
* Added email, domain, and IPv4/IPv6 blocklists for classic checkout.
* Added HPOS-compatible order signals and one-click block actions.

= 0.4.0 =
* Added privacy-preserving IP normalization and one-way correlation hashes.
* Added batched seven-day log retention pruning.
* Added paginated Activity Log REST services and admin workspace.
