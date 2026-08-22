# CoderEmbassy Order Guard — Admin UI Build Plan (React, Consolidated)

## Companion to the Order Guard v2 Codex Plan

This is the single, complete admin UI plan. It supersedes and replaces both
the earlier React admin plan and the React hardening addendum — use only
this file for the admin UI.

The admin UI is a standalone React app that reuses the existing CoderEmbassy
shell and talks to the plugin's PHP backend over a custom REST API.

Read together with `coderembassy-order-guard-codex-plan-v2.md`. This
document changes only the admin presentation and its data layer. It does
NOT change any detection logic, data storage, security model, or Store API
behavior — those remain exactly as in v2. The v2 plan's "no React" note
applied to the protection engine, which stays 100% server-side PHP. React
is used ONLY for the admin interface.

---

# A. The Stack (locked)

- **Admin UI:** standalone React app, reusing the existing CoderEmbassy
  shell (branded sidebar, purple hero, stat cards, dark/light toggle,
  version/Pro badge).
- **Build tooling:** `@wordpress/scripts` (wp-scripts). React is
  externalized to WordPress's bundled copy (`wp-element`) — the plugin
  ships NO duplicate React. Same design freedom as a standalone app, same
  bundle size benefit as the native approach.
- **Data layer:** a custom REST API under namespace `ceog/v1`, consumed
  with `@wordpress/api-fetch` (auto-attaches the `X-WP-Nonce` header).
- **State:** local React state / hooks. No `@wordpress/data` store — the
  app's data needs (settings, log, dashboard counts) are too simple to
  justify a Redux-style layer.
- **Protection engine:** unchanged from v2. Pure PHP, hooks and filters.

Why this stack: it reuses the exact shell for a consistent product family
(best UX), externalizing to wp-element keeps the bundle small (performance),
and a small custom REST API consumed via api-fetch is the least-ceremony
way to move data between PHP and React for a plugin this size.

---

# B. Architecture Overview

```text
Browser (wp-admin, one Order Guard page)
  └─ React app mounts into a single <div id="ceog-app">
       ├─ Shell: logo, sidebar (9 items), header, dark toggle
       ├─ Views: Dashboard, Activity Log, Circuit Breakers, Store API,
       │         Lists, Settings, Privacy & Logs, Help, Pro License
       └─ Data via @wordpress/api-fetch  ──►  REST namespace ceog/v1
                                                 │
PHP backend (unchanged v2 engine + new REST controllers)
  ├─ REST: settings, mode, log (paginated), log/delete, dashboard,
  │        lists, block-entity
  ├─ every route: args schema + permission_callback (manage_woocommerce)
  └─ Protection engine: breakers, Store API guard, /batch, honeypot,
       origin rules  (server-side, no React involvement)
```

The React app is a thin presentation + data-fetching layer. All security
decisions and all protection logic stay in PHP. The REST API is the only
bridge, and every route is authenticated.

---

# C. REST API Design (namespace `ceog/v1`)

## Routes

```text
GET  /ceog/v1/settings          -> current ceog_settings (sanitized for display)
POST /ceog/v1/settings          -> validate + save; returns saved state
POST /ceog/v1/mode              -> {mode: monitor|enforce}; the hero switch
GET  /ceog/v1/dashboard         -> cached counts: blocked_today,
                                    suspicious_7d, mode (+ Safe Mode flag),
                                    per-tier breaker status (incl. cooldown
                                    seconds), weekly_series[7], recent_events[5]
GET  /ceog/v1/log               -> paginated log:
                                    ?page=&per_page=&type=&after=&before=&ip_hash=
                                    returns {rows, total, pages}
POST /ceog/v1/log/delete        -> bulk delete by ids
GET  /ceog/v1/lists             -> blocklist + whitelist
POST /ceog/v1/lists             -> validate + save lists
POST /ceog/v1/block-entity      -> {type: email|ip, value}; one-click block
```

## Authentication (do it the simple, correct way)

- Every route's `permission_callback` is ONE shared function — that is the
  entire auth check:

  ```php
  function ceog_rest_can_manage() {
      return current_user_can( 'manage_woocommerce' );
  }
  ```

- Do NOT hand-write nonce verification inside callbacks. WordPress
  validates the `X-WP-Nonce` header as part of logged-in cookie auth, and
  api-fetch sends it automatically (see Section D). Per-callback nonce code
  is redundant and error-prone — omit it.

## Route-level args on every route (first line of defense)

Do not rely on callback validation alone. Every route defines an `args`
schema with `type`, `required`, `enum` where applicable, `validate_callback`,
and `sanitize_callback`. Callback validation remains as the second line.

```php
register_rest_route( 'ceog/v1', '/mode', array(
    'methods'             => 'POST',
    'permission_callback' => 'ceog_rest_can_manage',
    'args'                => array(
        'mode' => array(
            'required'          => true,
            'type'              => 'string',
            'enum'              => array( 'monitor', 'enforce' ),
            'sanitize_callback' => 'sanitize_key',
        ),
    ),
    'callback'            => 'ceog_rest_set_mode',
) );
```

## Return sanitized, type-cast JSON — do NOT HTML-escape REST output

REST responses return validated, sanitized, correctly typed data (ints as
ints, bools as bools, strings sanitized on input). Do NOT run `esc_html()`
on values placed into JSON: React escapes at render time, and pre-escaping
would double-encode and display garbled text like `&amp;`. The boundary
rule is: sanitize on input, type-cast on output, render as text in React
(never `dangerouslySetInnerHTML`).

## Pagination and delete limits (hard)

```text
/log:
  - per_page default 25, max 100
  - page min 1
  - type restricted to the event_type enum
  - after/before validated as YYYY-MM-DD
  - server-side pagination only; never return the whole table
  - reuse the composite indexes from the v2 plan for fast filtered queries

/log/delete:
  - max 100 ids per request
  - absint every id
  - prepared placeholders in the DELETE
```

## Dashboard cache and invalidation

`/dashboard` reads CACHED transient counts, never live heavy COUNT queries
on every load. The weekly series is ONE cached `GROUP BY DATE(event_time)`
query rendered as HTML/CSS bars (no charting library).

Cache freshness uses a SHORT TTL (30–60s) as the primary mechanism, PLUS
explicit busting only on discrete, low-frequency events:

```text
Bust the dashboard cache on:
  - mode change (monitor <-> enforce)
  - a breaker tripping or resetting
  - bulk log deletion
  - a protection-affecting settings save

Do NOT bust the cache on individual log-row inserts. Under a card-testing
flood the log receives thousands of inserts per second; busting per insert
would delete-and-requery thousands of times per second and defeat the cache
exactly when it matters most. The short TTL covers attack-time freshness;
the dashboard polls every 30s anyway, so up-to-60s staleness during an
active attack is acceptable.
```

All log values sanitized/type-cast before returning; treat log data as
attacker-controlled even over REST.

---

# D. Build, Enqueue & React Runtime

## Build tooling and shipped assets

- Build with wp-scripts. Output `build/index.js`, `build/index.css` (if
  generated), and `build/index.asset.php` (dependency array including
  `wp-element`, plus a version hash).
- The release zip MUST include the compiled build assets. Customers must
  never need `npm install` or `npm run build`.
- React externalized to `wp-element`; no CDN assets; no remote fonts; no
  duplicate React bundle.

## Enqueue (only on the Order Guard screen)

- Read `build/index.asset.php` for the dependency array and version, and
  enqueue only when `get_current_screen()` matches the Order Guard page.
- If `build/index.asset.php` or `build/index.js` is missing, show an admin
  notice to administrators and do NOT fatal:

  ```text
  "Order Guard admin assets are missing. Please reinstall the plugin from
  a complete release package."
  ```

- Localize a small bootstrap object:

  ```php
  wp_localize_script( 'ceog-admin', 'ceogBoot', array(
      'restUrl' => esc_url_raw( rest_url( 'ceog/v1/' ) ),
      'nonce'   => wp_create_nonce( 'wp_rest' ),
      'caps'    => array( 'manage' => current_user_can( 'manage_woocommerce' ) ),
      'version' => CEOG_VERSION,
      'isPro'   => false,
  ) );
  ```

- The app mounts into a single `<div id="ceog-app">` printed by the one
  registered admin page.

## api-fetch setup (once, at bootstrap)

Configure middleware a single time when the app boots; it auto-attaches
`X-WP-Nonce` and auto-updates the nonce from response headers:

```js
import apiFetch from '@wordpress/api-fetch';
apiFetch.use( apiFetch.createNonceMiddleware( window.ceogBoot.nonce ) );
apiFetch.use( apiFetch.createRootURLMiddleware( window.ceogBoot.restUrl ) );
```

## Internationalization

- Wrap every visible React string in `@wordpress/i18n`:

  ```js
  import { __, sprintf } from '@wordpress/i18n';
  __( 'Switch to Enforce Mode', 'coderembassy-order-guard' );
  ```

- Enqueue JS translations, or React strings will not be translatable even
  though PHP strings are:

  ```php
  wp_set_script_translations(
      'ceog-admin',
      'coderembassy-order-guard',
      plugin_dir_path( __FILE__ ) . 'languages'
  );
  ```

## CSS scoping

- All admin UI CSS is scoped under a single root class, `.ceog-app`. Never
  style bare global selectors (`button`, `input`, `table`, `body`,
  `.card`, `.notice`, etc.) at the top level. This stops the React UI from
  leaking styles into wp-admin or other plugins, and stops wp-admin styles
  from breaking the app. Reset any bleeding wp-admin styles explicitly
  inside `.ceog-app`.

## Dark mode (v1)

- Store the dark/light preference in browser `localStorage` only. No REST
  route, no user meta in v1. (localStorage in wp-admin is standard and
  fine.) A per-user, cross-device preference endpoint is a Pro nicety.

## REST error handling (friendly, never a blank screen)

- On 401/403: show "Your admin session expired. Please refresh the page
  and try again." Never render a blank or broken view.
- On other failures: show a compact inline error with a retry action.
- This is required from Phase 2 onward — the first data-fetching views
  must handle it, not just the final review.

## Polling discipline (Dashboard)

- Poll `/ceog/v1/dashboard` on an interval (default 30s), but:
  - Pause polling when `document.hidden` is true; resume on visibility.
  - Clean up the interval on component unmount.
  - Apply exponential backoff after repeated REST failures; recover on
    success.
- A manual "Refresh" button is an acceptable simpler fallback for v1 if
  polling is deferred.

---

# E. v1 Sidebar (lean — 9 items)

```text
Dashboard
Activity Log
Circuit Breakers
Store API Guard
Lists            (Blocklist + Whitelist panels)
Settings         (general, Monitor/Enforce, honeypot, order rules)
Privacy & Logs
Help
Pro License
```

One WordPress admin menu item ("Order Guard"); all sub-navigation is
client-side routing inside the React app (no WP submenus per section).
Deferred to Pro: a dedicated "Order Signals" page.

## Section content map

- **Dashboard:** hero card (title, tagline, FREE/PRO badge, "View Activity
  Log" + Monitor/Enforce controls, "Protection Active" state when
  enforcing); four stat cards (Blocked Today, Suspicious 7d, Protection
  Mode, Circuit Breaker — the last flips to "Checkout paused — countdown"
  when tripped); monitor-first quick-start card; "Threat activity this
  week" bar chart; "Recent protection events" (5 latest rows); and a
  contextual, dismissible Pro card shown ONLY after an enforced attack
  ("Order Guard blocked N attempts. M failed/junk orders remain — Pro can
  clean these up automatically."). Dismissed state persists; no nag
  banners on other screens (see v2 plan, Section 4).
- **Activity Log:** React table fed by the paginated `/log` endpoint;
  filters for type, date range, and "same attacker" by ip_hash;
  pagination; bulk delete. No CSV export in v1.
- **Circuit Breakers:** three panels (per-IP, per-email, global) with live
  status; global labeled the primary defense against distributed attacks;
  each field with its false-positive note.
- **Store API Guard:** rate-limit settings; read-only native-limiter
  status (coexistence, never override); /batch-inspection-active
  indicator; Advanced area (Strict Session Requirement, Emergency Lockdown)
  with warning boxes and confirmation; Emergency Lockdown disabled +
  explained when the Checkout block is detected.
- **Lists:** two panels (Blocklist, Whitelist), one entry per line,
  validated on save.
- **Settings:** global enable, the prominent Monitor/Enforce switch with
  confirmation, honeypot toggle, unknown-origin flag + on-hold options.
- **Privacy & Logs:** IP anonymization (with full-IP warning), trusted
  proxy, retention (fixed 7 days in free, shown with a quiet "Pro extends
  to 30/90 days" note), delete-data-on-uninstall.
- **Help:** monitor-first guidance, the "why captcha doesn't stop these"
  explainer, express-payments whitelist note, cross-link to Quantity Guard.
- **Pro License:** v1 placeholder listing planned Pro features.

---

# F. Merged Master Phase Sequence

```text
Phase 1   Foundation & Compatibility            (v2 P1, unchanged)
Phase 2   REST API + React Shell mount          (NEW)
Phase 3   Settings + Safe Mode (React + REST)   (v2 P2, re-platformed)
Phase 4   Logger + IP + Activity Log (React)    (v2 P3 + paginated REST log)
Phase 5   Lists engine + Lists view (React)     (v2 P4)
Phase 6   Store API Guard + view (React)        (v2 P5)
Phase 7   Circuit Breakers + view (React)       (v2 P6)
Phase 8   Honeypot (+ Settings toggle)          (v2 P7)
Phase 9   Unknown-origin flagging               (v2 P8)
Phase 10  Dashboard assembly + Alerts (React)   (v2 P9, full dashboard)
Phase 11  Safety review + release prep          (v2 P10 + React/REST pass)
```

Build the shell early (Phase 2) so every later phase renders a React view
into it; assemble the live dashboard last (Phase 10) because its numbers
depend on data the earlier layers produce. Each feature phase also adds the
REST endpoint(s) its view needs. The Activity Log is a React component fed
by a paginated endpoint, not a `WP_List_Table`.

---

# G. Codex Phase Prompts (changed phases only)

For any functional requirement not repeated here, follow the corresponding
v2 phase prompt unchanged.

## Phase 2: REST API + React Shell (NEW)

```text
Implement Phase 2: REST API foundation + React admin shell.

Goal: the branded CoderEmbassy React shell mounted in wp-admin, talking to
a minimal authenticated REST API. No protection logic in this phase.

Backend:
- Register ONE WordPress admin menu item "Order Guard" that prints a single
  <div id="ceog-app"></div>.
- Register REST namespace ceog/v1. Shared permission_callback:
  current_user_can('manage_woocommerce'). Do NOT add per-callback nonce
  verification.
- Every route defines an args schema (type, required, enum,
  validate_callback, sanitize_callback).
- Implement GET/POST /ceog/v1/settings and POST /ceog/v1/mode first.
- REST responses return sanitized, type-cast data; do NOT HTML-escape JSON.
- Enqueue the built bundle ONLY on the Order Guard screen, reading
  build/index.asset.php for deps (must include wp-element) and version. If
  build assets are missing, show an admin notice and do not fatal. Localize
  ceogBoot (restUrl, nonce, caps, version, isPro).

Frontend (React via wp-scripts, externalized to wp-element):
- Reuse the existing CoderEmbassy shell: logo, sidebar (9 v1 items), header
  with dark/light toggle and current user, content slot.
- Client-side routing between the 9 sections; default Dashboard.
- Empty-but-styled placeholder components for all 9 sections.
- Configure @wordpress/api-fetch nonce + root-URL middleware once at boot
  from window.ceogBoot.
- Render all values as text; never use dangerouslySetInnerHTML.
- Scope ALL CSS under .ceog-app; no global selectors.
- Use @wordpress/i18n for every string; call wp_set_script_translations.
- Store dark mode in localStorage (no REST route in v1).
- Handle REST 401/403 with a friendly "session expired, refresh" message;
  never a blank screen.
- Accessibility: keyboard-navigable sidebar and controls, visible focus
  states, dark-mode toggle has aria-label and aria-pressed.

After implementation:
- Explain how to build the bundle, verify routing across all 9 sections,
  confirm the settings GET/POST round-trip and mode switch, confirm dark
  mode persists, confirm the bundle loads only on the Order Guard screen,
  and confirm the missing-assets notice.
- Stop and wait for Phase 3.
```

## Phase 3: Settings + Safe Mode (React + REST)

```text
Implement Phase 3: Settings + Safe Mode, as a React view over the REST API.

Follow the v2 "Settings and Safe Mode Surface" prompt for all functional
and validation requirements (defaults, clamps, sanitization, block-checkout
guard), with these platform changes:
- Render Settings as a React component reading/writing /ceog/v1/settings.
- The Monitor/Enforce switch is the prominent hero control, backed by
  POST /ceog/v1/mode, with a confirmation before enabling Enforce ("We
  recommend reviewing the Activity Log first").
- Advanced area (Strict Session Requirement, Emergency Lockdown) with
  warning boxes and an "I understand" confirmation; exact warning texts
  from the v2 plan; Emergency Lockdown disabled + explained when the
  Checkout block is detected (backend reports this in /settings).
- Safe Mode banner shown in-app when CEOG_SAFE_MODE is active.
- Validation/sanitization happen server-side in the POST handler and in the
  route args; the React form is not trusted.
- Friendly REST 401/403 handling present here too.

After implementation:
- Explain how to test the settings round-trip, clamps, the Enforce
  confirmation, the block-checkout guard, and the Safe Mode banner.
- Stop and wait for Phase 4.
```

## Phase 4: Logger + IP + Activity Log (React, paginated REST)

```text
Implement Phase 4: Logger, IP privacy, ip_hash, and the Activity Log view.

Follow the v2 "Logger, IP Privacy, and ip_hash" prompt for ALL backend
requirements (schema, IPv6 /64 handling, hashing, batched pruning), with
these platform changes:
- GET /ceog/v1/log with server-side pagination and filtering:
  per_page default 25 / max 100; page min 1; type restricted to the
  event_type enum; after/before validated YYYY-MM-DD; returns
  {rows, total, pages}. Never return the whole table.
- POST /ceog/v1/log/delete: max 100 ids; absint every id; prepared
  placeholders.
- Build the Activity Log as a React table fed by /ceog/v1/log: columns
  time, type, mode, ip_display, route, reason, order link; filters for
  type, date range, and ip_hash "same attacker"; pagination; bulk delete.
- REST returns sanitized, type-cast rows (not HTML-escaped); React renders
  every cell as text (no dangerouslySetInnerHTML).

After implementation:
- Explain how to test paginated fetches, each filter, the ip_hash filter,
  bulk delete, the hard limits, and that large logs never load fully into
  the browser.
- Stop and wait for Phase 5.
```

## Phases 5–7: Lists / Store API Guard / Circuit Breakers

```text
For each phase, follow the corresponding v2 prompt (Lists and Whitelist
Engine; Store API Guard; Tiered Circuit Breakers) for ALL functional,
security, and Store API behavior unchanged. Platform additions only:

- Add the REST endpoints each view needs, each with an args schema and the
  shared manage_woocommerce permission_callback:
  - Lists: GET/POST /ceog/v1/lists; POST /ceog/v1/block-entity.
  - Store API Guard: expose settings + native-limiter status + /batch flag.
  - Circuit Breakers: expose live per-tier status (via /dashboard or a
    small GET) for Ready/Active/Cooling Down display.
- Render each as a React view:
  - Lists: two panels (Blocklist, Whitelist).
  - Store API: rate-limit settings, read-only native-limiter status,
    /batch indicator, Advanced warning area.
  - Circuit Breakers: three panels (per-IP, per-email, global); global
    labeled the primary defense against distributed attacks; each field
    with its false-positive note.
- REST returns sanitized/type-cast data; friendly error handling; all CSS
  scoped under .ceog-app; all strings via @wordpress/i18n.

Stop and wait between each phase as in the v2 plan.
```

## Phases 8–9: Honeypot / Unknown-origin

```text
Follow the v2 "Classic Checkout Honeypot" and "Unknown-Origin Flagging"
prompts unchanged (both server-side). UI touch-points only:
- Honeypot toggle and unknown-origin flag/on-hold options appear in the
  React Settings view (already wired through /ceog/v1/settings).
- Flagged events appear in the Activity Log and on the order metabox
  (from v2). No separate Order Signals page in v1.
```

## Phase 10: Dashboard Assembly + Alerts (React)

```text
Implement Phase 10: Dashboard assembly + Alerts.

This is where the populated dashboard comes together, because it aggregates
data produced by all earlier phases.

Backend:
- GET /ceog/v1/dashboard returning CACHED counts: blocked_today,
  suspicious_7d, mode (+ Safe Mode flag), per-tier breaker status (incl.
  cooldown seconds remaining), weekly_series[7] from ONE cached
  GROUP BY DATE(event_time) query, recent_events[5].
- Cache: short TTL (30-60s) PLUS event-based busting on mode change,
  breaker trip/reset, bulk log delete, and protection-setting saves. Do
  NOT bust on individual log-row inserts.
- CEOG_Alerts per the v2 "Alerts and Dashboard" prompt (throttled email on
  any breaker trip, naming the tier).

Frontend (React Dashboard view):
- Hero card (title, tagline, FREE/PRO badge, "View Activity Log" +
  Monitor/Enforce controls, "Protection Active" when enforcing).
- Four stat cards (Blocked Today, Suspicious 7d, Protection Mode, Circuit
  Breaker with the "Checkout paused — countdown" state).
- Monitor-first quick-start card.
- "Threat activity this week": HTML/CSS bars from weekly_series; no charting
  library.
- "Recent protection events": the 5 rows from the API.
- Contextual Pro card: rendered only when the API reports an enforced
  attack with remaining failed/junk orders ("Order Guard blocked N
  attempts. M failed/junk orders remain — Pro can clean these up
  automatically."). Dismissible; dismissed state persists (user meta or
  localStorage); never shown on other screens; no modals, no fake urgency.
- Poll /dashboard every 30s, pausing when document.hidden, cleaning up on
  unmount, backing off after repeated failures.

After implementation:
- Explain how to test the stat cards, the tripped-breaker countdown, the
  weekly chart, the polling pause/backoff, cache invalidation triggers, and
  alert throttling.
- Stop and wait for Phase 11.
```

## Phase 11: Safety Review + Release Prep (React/REST pass)

```text
Implement Phase 11: Safety review and release preparation.

Follow the v2 "Safety Review and Release Preparation" prompt in full, and
add a React/REST security and quality pass:
- Every ceog/v1 route has an args schema and the shared manage_woocommerce
  permission_callback, with no redundant per-callback nonce code and no
  public routes.
- REST returns sanitized/type-cast (not HTML-escaped) data; no React path
  uses dangerouslySetInnerHTML.
- The React bundle loads ONLY on the Order Guard screen, ships no duplicate
  React (externalized to wp-element), and uses no CDN/remote assets.
- /log and /log/delete enforce their hard limits; server-side pagination
  confirmed (large logs never fully loaded client-side).
- Dashboard cache invalidation triggers correctly; polling pauses on hidden
  tabs and backs off on failure.
- All CSS scoped under .ceog-app; nothing leaks into wp-admin.
- React strings internationalized; wp_set_script_translations called.
- Missing-build-assets admin notice works and never fatals.
- Keyboard navigation, focus states, labeled dark-mode toggle
  (accessibility pass).
- App degrades gracefully in Safe Mode and on REST errors (friendly failure
  UI, never a blank screen).
- readme screenshots: branded React dashboard, Activity Log, tiered breaker
  view, Monitor/Enforce confirmation.

After implementation:
- Summarize the final plugin, the testing checklist results, and the Pro
  candidates.
```

---

# H. Admin UI Security & Accessibility Checklist

Security (all mandatory):

```text
- Every ceog/v1 route: args schema + shared permission_callback
  (current_user_can('manage_woocommerce')). No per-callback nonce code.
  No public routes.
- api-fetch nonce middleware configured once at boot; X-WP-Nonce sent
  automatically.
- Server-side validation/sanitization on every POST (route args + callback);
  React input untrusted.
- REST returns sanitized/type-cast data (NOT HTML-escaped); React renders
  as text; never dangerouslySetInnerHTML.
- Activity Log data treated as attacker-controlled end to end.
- Server-side pagination on /log (default 25, max 100); bulk delete max 100
  ids, absint, prepared placeholders.
- Dashboard cache: short TTL + event-based busting; never bust per insert.
- React bundle enqueued only on the Order Guard screen; externalized to
  wp-element; no CDN; no remote fonts; no duplicate React.
- Compiled build assets shipped in the release zip; missing-assets admin
  notice instead of a fatal.
- All CSS scoped under .ceog-app; no global selectors.
- React strings via @wordpress/i18n; wp_set_script_translations called.
- Dark-mode preference in localStorage, isolated from protection settings.
```

Accessibility (brand-consistency requirement):

```text
- Full keyboard navigation of sidebar and controls.
- Visible focus states.
- Dark-mode toggle has aria-label and aria-pressed.
- Sufficient contrast in light and dark themes.
- Warnings conveyed by text/icon, not color alone.
- Friendly, accessible error state when a REST call fails (401/403 and
  general failures).
```

---

# I. Summary

Reuse the existing CoderEmbassy React shell as a standalone app built with
wp-scripts (externalized to wp-element for a small bundle), mounted on one
Order Guard admin page, talking to a custom ceog/v1 REST API via api-fetch.
Every route has an args schema and a single manage_woocommerce permission
callback; REST returns sanitized, type-cast data that React renders as text.
Build the shell early (Phase 2 with the first endpoints), render each
feature into a React view as its phase lands, use a server-side paginated
endpoint for the Activity Log, and assemble the live dashboard last
(Phase 10) once the protection layers generate data — with the dashboard
cache held by a short TTL plus event-based busting, never busted per log
insert. Ship compiled assets, scope CSS under .ceog-app, internationalize
React strings, and handle REST errors gracefully. No detection logic, data
model, or Store API behavior from the v2 plan changes — only the admin
presentation and its REST data layer.
```
