# Changelog

All notable changes to AI Assets Scanner are documented here.

---

## [1.2.0c] — 2026-04-22

### Changed

- Reserve-credits errors now surface the HTTP status code and a 80-char response snippet (e.g. `Could not reserve credits: HTTP 429: rate limited`) instead of the generic "may not have enough credits" message — same pattern as v1.2.0b's submit-job fix. Server `error_log` still receives the untruncated exception detail. Refactored `format_submit_error_detail()` + new `format_reserve_error_detail()` to share a private `truncate_error_detail()` helper.

---

## [1.2.0b] — 2026-04-22

### Fixed

- `CU_SCANNER_VERSION` constant no longer drifts from the plugin header (was stuck at `1.1.5` since commit `ce3f311`).

### Changed

- Scan-submission errors now surface the HTTP status code and a 80-char response snippet (e.g. `Scan submission failed: Railway HTTP 401: no such token`) instead of the generic `Could not submit scan job. Check server error logs.` message. Server `error_log` still receives the untruncated exception detail.

---

## [1.2.0] — 2026-04-20

### BREAKING — mandatory update

This version is required to keep working with wpservice.pro after the 2026-04-20 service deployment. Older plugin versions (1.1.5 and below) will see all scans fail with **401 Unauthorized** from the Railway scanner service — the scanner now requires a scoped, short-lived `job_token` per submission instead of the account `api_key`.

**If you are on 1.1.5 or earlier, update immediately.**

### Changed

- **`RailwayClient::submit_job`** now sends `Bearer <job_token>` in the Authorization header (previously `Bearer <api_key>`). The `job_token` is short-lived (24 h), scoped to a single scan, and never exposes the account-level api_key to the Railway runtime. Throws `\RuntimeException('job_token required for Railway submit')` if called without a token.
- **Cancel dialog** — when you click Cancel on an in-progress scan, the plugin now first fetches your current progress from the scanner and shows a confirmation dialog reading *"Cancelling now will charge you for N pages already scanned. Continue?"* You can still back out; confirming proceeds with the cancel and the partial charge.

### Why this matters (security context)

The scanner runtime previously held each active customer's account `api_key` in memory during a scan. A hypothetical compromise of that runtime would have exposed every in-flight key. With the 2026-04-20 service deployment the account `api_key` never leaves wpservice.pro / your plugin — the scanner runtime only ever sees per-scan `job_tokens`, which expire in 24 h and are scoped to a single job. This is a significant reduction in blast radius on the service side.

---

## [1.1.5] — 2026-04-12

### New features

- **Code Unloader missing warning** — When Code Unloader is not installed or active, a red error notice appears at the top of Step 1 with a direct link to the wordpress.org plugin page.
- **Contact button** — A "Get in touch" button appears in the Discover Pages row, right-aligned, linking to https://wpservice.pro/contact/. Opens in a new tab.
- **Credit balance badge** — After discovering pages, a second badge ("X credits available") appears beside the existing scan-cost badge. Turns red when available credits are fewer than the scan cost.

### Improvements

- **Discover Pages button** — Changed to primary style (blue background, white text) to match the Start Scan button.
- **Security plugin notices** — Removed the "See Settings →" deep-link from Wordfence and Cloudflare warning notices to reduce visual noise.

---

## [1.1.4] — 2026-04-12

### New features

- **Security plugin detection** — Step 1 now detects Wordfence, Wordfence Login Security, and the Cloudflare for WordPress plugin and shows a contextual warning with a "See Settings →" deep-link. Wordfence entries link to the Settings page; the Cloudflare entry links directly to the Cloudflare WAF Bypass section.

---

## [1.1.3] — 2026-04-12

### New features

- **Bot-protection warning notice** — A contextual warning now appears in Step 1 just before the Start Scan button, reminding users to temporarily disable Cloudflare or WordFence bot protection and rate limiting before scanning. Includes a link to the Settings page for users who prefer a permanent bypass.
- **Scanner Secret** — A persistent UUID secret is auto-generated on first use and displayed in Settings (read-only, with a one-click Copy button). This secret is sent as an `x-cu-scanner` HTTP header by the Railway scanner on every page request.
- **Cloudflare WAF bypass instructions** — New section in Settings explains step-by-step how to create a Cloudflare WAF Custom Rule matching the `x-cu-scanner` header, so the scanner bypasses Bot Fight Mode automatically without disabling site-wide protection.
- **WordFence note** — Settings includes guidance for WordFence users: add the Railway server IP to WordFence Allowlisted IPs, or temporarily disable rate limiting before scanning.

### Improvements

- **Realistic desktop User-Agent** — The Railway scanner now identifies itself as a real Windows Chrome browser (`Chrome/124`) on desktop scans instead of the default headless Playwright UA, reducing false-positive bot blocks.

---

## [1.1.2] — 2026-04-11

### Bug fixes

- **Duplicate rows on repeated polls** — `handleStatusUpdate` used `lastPageIndex + idx` to assign row IDs and incremented `lastPageIndex` by `pages.length` after each poll. Because Railway always returns all pages (index 0 to total−1), subsequent polls rendered pages at wrong offsets (rows `total`, `total+1`, ...) instead of updating the existing rows in-place. Fixed by using `idx` directly as the row ID and removing the stale increment.

---

## [1.0.9] — 2026-04-11

### New features

- **Queue status banner** — While a scan is queued on the Railway service, the scanner UI shows a live banner with queue position and estimated wait ("Position X in queue"). The banner hides automatically when the job starts processing.
- **Variable poll interval** — Status polling now uses 10 s intervals when the job is queued and drops to 2 s once it moves to in-progress, balancing responsiveness against server load.
- **Cancelled-timeout state** — If a job times out in the Railway queue (> 3 h wait), the plugin detects the `cancelled_timeout` status, stops polling, and shows a user-friendly message explaining the job expired in the queue.

### Bug fixes

- **Double credit release on cancel** — `cancel_job()` was calling `WpserviceClient::release_credits()` directly after also calling the Railway cancel route (which now owns credit release). The PHP-side release call has been removed to prevent double-release.
- **Initial status set to "queued"** — Scan history records were created with `status = "in_progress"` at submission time. Records now start as `"queued"` and transition when Railway reports the job active.

---

## [1.0.8] — 2026-04-10

### Added

- **Include URLs field** — New "Include URLs (one per line)" textarea in Step 1, above Exclude URLs. Typing URLs here immediately shows the Start Scan button without needing to run Discover Pages.
- **Include-only scan path** — When URLs are entered in Include URLs and Discover Pages is not clicked, Start Scan scans exactly those URLs directly.
- **Include + Discover merge** — When Discover Pages is run after filling Include URLs, the included URLs are merged into the discovered set as a pre-selected "Included" group with its own filter pill and `[included]` badge on each row.
- **Deduplication** — Include URLs already present in discovered pages are not duplicated (normalised comparison: trailing-slash insensitive, case-insensitive).
- **Discover Pages button repositioned** — Moved to the top of Step 1 with a hint: "or fill Include URLs below to scan specific pages". Button is normal width (not full-width).

---

## [1.0.7] — 2026-04-10

### Rebrand

- **Plugin renamed to AI Assets Scanner** — Plugin name, menu title, admin page headers, and all banner HTML updated from "CU Scanner" to "AI Assets Scanner"
- **Main file renamed** — `cu-scanner.php` → `ai-assets-scanner.php`; admin CSS renamed from `cu-scanner-admin.css` → `ai-assets-scanner-admin.css`; all enqueue references updated
- **AI Assets Scanner logo** — New logo image added to all admin page headers; attribution banner updated
- **Buy Credits URL updated** — Link now points to the correct shop anchor on wpservice.pro
- **Admin hook names updated** — WordPress admin hooks updated to match the rebranded plugin slug

### Security

- **API key masking** — The API key field in Settings now displays a masked value (`••••••••`) after saving instead of the raw key, preventing accidental exposure in screenshots or screen shares
- **Keep-key sentinel** — A `keep_api_key` sentinel is sent when submitting the settings form with the masked placeholder, preventing the stored key from being overwritten with the mask string
- **Null guard on API key input** — Added null guard in `settings.js` to prevent a JS error when the API key input is not present on the page

### Documentation

- **README rewritten** — Full rebrand and expansion with feature list, architecture diagram, quick-start guide, and requirements
- **INSTALL.md updated** — Folder name, menu references, and plugin name corrected to match rebrand

---

## [1.0.6] — 2026-04-07

### Improvements

- **Versioned groups retain their rules** — Previously, bumping old scanner groups (e.g. "CU Scanner — Safe" → "CU Scanner — Safe v1") also deleted all rules from those groups and from any prior versioned copies. This was a workaround for a table-wide UNIQUE constraint in Code Unloader. Now that Code Unloader's `wp_cu_rules` UNIQUE key includes `group_id`, every group keeps its full rule set after renaming. History groups are fully intact and browsable.
- **Ungrouped rules captured in snapshot** — Rules that exist outside any group in Code Unloader (always active, no enable/disable) are now included in the "Previously active rules" snapshot taken before each push.

### Bug fixes

- **Ungrouped rules not deactivated after push** — After a successful push, ungrouped rules remained active because they have no group to disable. They are now deleted at commit time (they are already preserved in the snapshot group).

---

## [1.0.5] — 2026-04-05

### New features

- **Push versioning** — When pushing scanner results to Code Unloader, existing "CU Scanner — Safe" and "CU Scanner — Aggressive" groups are now renamed to versioned copies ("CU Scanner — Safe v1", "v2", etc.) and disabled before fresh groups are created. Previous versions are preserved indefinitely and never deleted.
- **Safe group active by default** — After a push, only the new "CU Scanner — Safe" group is enabled. "CU Scanner — Aggressive" is saved but disabled — enable it manually when you're ready.
- **Previously active rules backup** — All rules that were active before a push are copied to a new disabled "Previously active rules [date]" group as a full safety snapshot.

### Bug fixes

- **SnapshotManager duplicate-key crash** — Previous buggy 0/0 pushes could leave the same rule in both scanner groups. On the next push, `snapshot()` would hit a DB UNIQUE constraint when copying both copies into the snapshot group, aborting the push with 0 rules added. Duplicate entries are now skipped silently during snapshot.
- **Version bump rollback** — If creating fresh scanner groups fails after old groups were already renamed, the renamed groups are now restored to their original names and re-enabled.

---

## [1.0.4] — 2026-04-04

### Bug fixes

- **Railway payload format** — `submit_job()` now sends `pages` as an array of `{url, bypass_token}` objects instead of a flat `urls` string array, matching what the Railway worker expects.
- **Railway base URL** — Plugin was sending `https://wpservice.pro/wp-json` as the `wpservice_url` field; Railway then appended `/wp-json/...` creating a double path. Added `CU_SCANNER_WPSERVICE_BASE` constant (bare `https://wpservice.pro`) used exclusively for the Railway callback field.
- **Credits lost on job submission failure** — When `submit_job()` failed before writing job state, `handle_failure()` had no transient to read so it exited early without releasing reserved credits. `handle_failure()` now falls back to the `cu_scanner_pending_token_` transient as a safety net.
- **Credits lost on PHP fatal** — Added `release_credits()` call directly in the `submit_job()` catch block so credits are always released if the submission throws before the job store is written.
- **Uncaught fetch rejections** — Added `.catch()` handlers to the `reserve_job` and `submit_job` fetch chains in `scanner.js` so network failures trigger the failure flow instead of an unhandled promise rejection.
- **Step 4 state lost on navigation** — After completing a scan, navigating away from the CU Scanner page and returning reset the UI to Step 1. Step 4 result data (job ID, safe/aggressive counts, push eligibility) is now saved to `localStorage` on completion and restored on next page load. Clicking "Run Another Scan" clears the saved state.

### Improvements

- **CuJsonBuilder exports Code Unloader-compatible format** — The downloaded JSON and Push to CU button previously created rules that never fired. Three root causes fixed:
  1. Field renamed `handle` → `asset_handle` (Code Unloader's DB column name)
  2. Asset type mapped at build time: `style` → `css`, `script` → `js` (DB ENUM only accepts `css`/`js`)
  3. URL patterns are now full normalized URLs (`https://site.com/blog`) matching Code Unloader's `PatternMatcher::normalize_url()` output — path-only patterns (`/blog/`) never matched
  - `match_type: exact` and `source_label: CU Scanner` added to every rule
  - RulePusher updated to pass fields through directly (no more local translation)
- **Credit balance widget** — Settings page credit balance redesigned with a styled gold card, large bold number, `credits` label, low-balance red state (< 10 credits), and a loading indicator during refresh.
- **CuJsonBuilder format version** bumped to `1.4.1` to match the targeted Code Unloader version.

---

## [1.0.3] — 2026-04-03

### Security

- **ABSPATH guards** added to `class-plugin.php`, `class-admin-pages.php`, `class-scanner-ajax.php`, `class-settings-ajax.php`, `class-bypass-manager.php` — prevents direct PHP file execution outside WordPress.
- **`wp_unslash()` added** to all `$_POST` and `$_GET` reads in `class-scanner-ajax.php`, `class-settings-ajax.php`, and `class-bypass-manager.php`.
- **`gmdate()` replaces `date()`** in `class-snapshot-manager.php` — timestamps are now timezone-safe regardless of server locale.
- **`wp_parse_url()` replaces `parse_url()`** in `class-cu-json-builder.php` — uses WordPress's safe URL parsing wrapper.

---

## [1.0.2] — 2026-04-03

### New features

- **Domain locking (client side)** — `WpserviceClient` now computes the site's hostname via `wp_parse_url(get_home_url(), PHP_URL_HOST)` and sends it as `domain` on every request to wpservice.pro (`/auth`, `/jobs/reserve`, `/credits`, `/credits/release`). No call sites change — domain extraction is centralised in a private `domain()` helper.

### Other

- **Version display** — Plugin version (`vX.X.X`) shown in the header of all admin pages (Scanner, Settings, Scan History).
- **Version constant fix** — `CU_SCANNER_VERSION` constant kept in sync with plugin header.

---

## [1.0.1] — 2026-04-03

### Bug fixes

- **API key placeholder** — Settings page placeholder corrected from `sk-...` to `cusk_...` to match the actual key format.
- **Credit balance display** — Fixed `auth['credits']` key mismatch (server returns `balance`); balance now shows immediately after saving settings without a page refresh.
- **Reserve endpoint contract** — Updated `reserve_job()` to send `page_count` and receive the server-generated `job_token`; removed the client-side job token parameter that no longer exists.

---

## [1.1.0] — 2026-03-22

### Dashboard redesign

- **Admin menu icon** — Replaced the generic magnifying glass icon with a custom sonar/radar SVG icon
- **Constrained width layout** — All plugin pages are now capped at 920 px on wide screens
- **Dark accent header** — Every page shows a dark navy gradient header with the CU Scanner logo, page label, and (on the scanner page) four step progress pips
- **Grouped URL list** — Discovered URLs are now bucketed into Pages, Posts, and Other groups, each with a dark colour-coded header row
- **Per-URL checkboxes** — Each URL row has a checkbox; deselected rows are visually struck through. Only checked URLs are submitted to the scanner and counted against credits
- **Group-level checkboxes** — Check/uncheck an entire group at once; the group checkbox shows an indeterminate state when the group is partially selected
- **Filter pills** — All / Pages / Posts / Other pills filter the visible groups without affecting selection state; Select All / Deselect All act on the currently visible groups only
- **Sonar animation** — A radar sweep animation plays while URL discovery is in progress
- **Live credit badge** — Shows the number of credits the current scan will use, updated in real time as URLs are deselected
- **Compact bypass notices** — Auto-bypass plugin notices are now a single-line banner instead of a full WP notice block; text reads "[Plugin Name] — temporary bypass applied."
- **Security** — HTML-escaped all server-supplied values rendered via `innerHTML`

---

## [1.0.0] — 2026-03-20

### Initial release

- Plugin scaffold with autoloader and WordPress hooks
- wpservice.pro API client (authentication, credit balance, job reservation)
- Railway API client (job submission, status polling, cancellation)
- Settings page — API key, HTTP Basic Auth (stored encrypted), credit balance
- Page discovery — sitemap parser with WP_Query fallback
- Optimization plugin detector — auto-bypass, soft-block, and soft-warn categories
- Bypass manager — injects bypass tokens into scanned page requests
- 4-step scan workflow — Discover → Reserve → Scan → Results
- CU JSON builder — generates safe and aggressive unload rules from scan results
- Rule pusher — pushes generated rules directly to Code Unloader
- Scan history — stores the last 10 scans with download links
- Full PHPUnit test suite (48 tests)
