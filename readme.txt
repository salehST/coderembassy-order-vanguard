=== CoderEmbassy Order Guard ===
Contributors: codersaleh
Tags: woocommerce, security, fraud, card testing, fake orders
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

API-level WooCommerce protection against card testing, automated fake orders, and abusive checkout traffic.

== Description ==

Order Guard protects classic checkout, Checkout Block, Store API cart traffic, and Store API batch requests with monitor-first circuit breakers, request controls, lists, and review signals. The Free plugin has no CAPTCHA keys, subscriptions, remote scoring services, or external data transfers.

For product quantity rules and customer-friendly purchase limits, see [CoderEmbassy Quantity Manager for WooCommerce](https://coderembassy.com/).

== Privacy ==

Order Guard stores protection events only in this site's WordPress database. A log row can contain the event time and type, protection mode, request route, reason, order ID, a sanitized user agent, `ip_display`, and a one-way `ip_hash` correlation value.

By default, IPv4 addresses are anonymized by replacing the last octet with zero. IPv6 addresses are reduced to their /64 network. The correlation hash is generated locally with HMAC-SHA256 and the site's WordPress authentication salt. It is used to recognize repeat traffic without storing the original address in the hash. Email counters use the same one-way approach over a lowercased email address; plaintext email addresses are not written to the protection log.

Full IP display storage is optional and disabled by default. Enabling it may store personal data and should be covered by the site's privacy policy and legal basis.

Free log history is retained for seven days and pruned daily in bounded batches. Nothing is sent to CoderEmbassy or any third party. The merchant can choose to remove the plugin's table and options during uninstall.

== Installation ==

1. Install and activate WooCommerce.
2. Upload and activate CoderEmbassy Order Guard for WooCommerce.
3. Open WooCommerce > Order Guard.
4. Keep Monitor mode enabled while reviewing Activity Log events.

== Frequently Asked Questions ==

= Why doesn't my CAPTCHA stop card testing? =

Many attacks send requests directly to WooCommerce Store API endpoints instead of interacting with the visible checkout form. Order Guard evaluates those server-side requests, including operations embedded in Store API batch requests. It complements a CAPTCHA rather than replacing one.

= Will this block real customers? =

Order Guard starts in Monitor mode, where it records decisions without blocking. Review the Activity Log before switching to Enforce. Whitelists preserve trusted roles, IPs, and payment methods, and `CEOG_SAFE_MODE` immediately suspends every blocking action. Per-IP blocks are intentionally short because mobile, office, and CGNAT networks may represent several customers behind one address.

= Does it work with the Checkout block? =

Yes. Store API request controls, batch inspection, lists, and circuit breakers protect Checkout Block traffic. The Free honeypot remains classic-checkout only. When Pro is active, it adds a separate site-specific Checkout Block honeypot through WooCommerce's Additional Checkout Fields API.

= Does it inspect Store API batch requests? =

Yes. Each embedded operation is normalized and evaluated individually. In Enforce mode, a protected or malformed operation rejects the batch envelope instead of allowing the operation to bypass direct-route controls.

= Is any data sent to external services? =

The Free plugin sends no protection decisions, logs, hashes, settings, or dashboard aggregates to an external service. If the merchant explicitly enables the optional Pro Cloudflare Turnstile integration with their own keys, the shopper's browser connects to Cloudflare and the server validates a single-use token with Cloudflare Siteverify; Order Guard does not store that token.

= How should express and wallet payments be configured? =

Add trusted express and wallet gateway keys to the payment-method whitelist. Whitelisted methods bypass lists and circuit breakers, including a global cooldown, because those flows can legitimately omit normal checkout signals. Confirm the exact gateway key in WooCommerce payment settings.

= Does the honeypot protect the Checkout block? =

The Free honeypot protects classic checkout. Order Guard Pro adds an optional, site-specific Checkout Block honeypot through WooCommerce's Additional Checkout Fields API (WooCommerce 8.9+), while preserving the same Monitor-first and fail-open safety behavior.

== Screenshots ==

1. Branded protection dashboard with live counts and the Monitor/Enforce control.
2. Server-paginated Activity Log during protected checkout traffic.
3. Per-IP, per-email, and global circuit breaker settings.
4. Protection settings and Safe Mode-aware controls.

== Changelog ==

= 1.0.0 =
* Initial release.


