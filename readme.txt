=== CoderEmbassy Order Vanguard ===
Contributors: codersaleh
Tags: woocommerce, fraud prevention, card testing, fake orders, checkout security
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Monitor and block abusive WooCommerce checkout and Store API traffic with privacy-first local controls.

== Description ==

CoderEmbassy Order Vanguard helps WooCommerce stores respond to card testing, bot orders, and repeated fake-checkout attempts.

Protection starts in Monitor mode so store owners can review activity before enabling blocking. The plugin includes:

* Per-IP, per-email, and store-wide circuit breakers.
* Store API cart mutation rate limiting and batch inspection.
* Email, email-domain, and IP blocklists with whitelist precedence.
* Classic-checkout honeypot protection.
* Unknown-origin order review signals.
* A paginated, filterable local Activity Log.
* Selectable 7, 30, or 90-day activity-log retention with daily batched pruning.
* Emergency Store API checkout controls with compatibility safeguards.
* HPOS and WooCommerce Checkout Block compatibility declarations.

Protection data stays in this WordPress site's database. No external service is required.

== Installation ==

1. Upload the `coderembassy-order-vanguard` folder to `/wp-content/plugins/`, or install the ZIP from Plugins > Add New > Upload Plugin.
2. Activate CoderEmbassy Order Vanguard.
3. Open WooCommerce > Order Vanguard.
4. Review the Activity Log while Monitor mode is active.
5. Configure the circuit breakers, lists, privacy settings, and retention period.
6. Switch to Enforce mode only after the recorded activity matches your store traffic.

== Frequently Asked Questions ==

= Does the plugin block traffic immediately after activation? =

No. It starts in Monitor mode. This records protection decisions without blocking checkout traffic.

= Does it support WooCommerce Checkout Block? =

Yes. Store API request controls, batch inspection, lists, circuit breakers, and order review signals support Checkout Block traffic. The hidden honeypot field is used on classic checkout.

= How long are activity records retained? =

Choose 7, 30, or 90 days under Privacy & Logs. A daily background task removes older rows in bounded batches.

= Does it send customer or attacker data to an external service? =

No. Protection decisions, settings, hashes, and Activity Log rows remain on the WordPress site.

= How are IP addresses handled? =

Repeat-attacker correlation uses a one-way, site-salted HMAC hash. Displayed addresses are anonymized by default. Full IP display is optional and should only be enabled when the site's privacy policy and legal basis cover it.

= What happens if the plugin encounters an internal error? =

Checkout-facing protection paths use a fail-open boundary: the error is logged when possible and the customer request is allowed to continue. Emergency Safe Mode can also force monitoring behavior.

= Can plugin data be removed on uninstall? =

Yes. Enable Delete all data on uninstall under Privacy & Logs before uninstalling the plugin.

== Privacy ==

Order Vanguard stores protection events locally in a custom WordPress database table. Event rows can contain an anonymized or administrator-enabled full IP display value, a one-way site-salted IP hash, request route, event reason, order ID, and bounded technical metadata. Plain-text customer email addresses are excluded from log metadata. Administrators can delete selected rows, select a 7, 30, or 90-day retention period, and choose whether uninstall removes all plugin data.

== Source Code and Build ==

Human-readable admin source and build configuration are included in the plugin package under `src/`, `package.json`, and `package-lock.json`.

The public development repository is:
https://github.com/salehST/coderembassy-order-vanguard

To rebuild the distributed admin assets:

1. Run `npm install` from the plugin root.
2. Run `npm run build`.

The build uses the WordPress packages listed in `package.json` and lucide-react, an ISC-licensed icon library. Exact dependency versions and source package URLs are recorded in `package-lock.json`.

== Changelog ==

= 1.1.0 =

* Added unrestricted 7, 30, and 90-day Activity Log retention choices.
* Updated daily pruning to honor the selected retention period.
* Bundled human-readable React source and reproducible build configuration.
* Simplified the dashboard and navigation to the plugin's complete standalone feature set.

= 1.0.0 =

* Initial release with monitor-first WooCommerce checkout protection, circuit breakers, lists, Store API controls, and privacy-first local logging.
