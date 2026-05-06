# Changelog

All notable changes to AI Assets Scanner are documented here.

---

## [1.2.6] — 2026-05-05

### Feature — Heavy-site / bot-block warning banner (Subsystem D-4)

Adds a dismissable WP admin notice on the scan-results page when one or both devices were blocked from completing the scan (typically Cloudflare challenge, Akamai Bot Manager, Imperva WAF, Rocket-Loader stub, or asymmetric stub responses on the desktop UA while mobile passed cleanly). Pairs with the Railway worker shipping per-device broken-detection (Tier 1 HTTP-level + Tier 2 body-shape signals) and the SaaS plugin shipping the storage + admin Jobs column extension at version 1.2.8.

### Added

- **`AIAS_Broken_Banner` class (`includes/class-broken-banner.php`)** — pure stateless renderer that emits the banner HTML when `pages_blocked.{desktop+mobile} > 0`. Returns empty string when no devices are blocked OR when the banner has been dismissed for the current scan. Reason-aware copy maps the 10 detection enum values to operator-friendly phrases (`tier2_cf_challenge → "Cloudflare challenge"`, `tier2_rocket_loader_stub → "Cloudflare Rocket-Loader stub"`, `tier2_akamai_challenge → "Akamai Bot Manager"`, `tier2_imperva_challenge → "Imperva WAF"`, `tier2_small_body → "asymmetric stub response"`, plus all five Tier-1 HTTP variants).
- **JS-driven banner rendering in `admin/js/scanner.js`** — `renderBrokenBanner()` populates `#cu-banner-area` in the Step 4 results panel after `build_result` completes. Mirrors the PHP class's reason-phrase map. Mobile-rule-shipping is unaffected (banner is informational; does not gate result display or push-to-CU).
- **Per-scan dismissal** — clicking the banner's "Got it — don't show again for this scan" button POSTs to a nonce-protected AJAX endpoint that records the dismissal in `wp_options.aias_dismissed_warnings` keyed by `scan_id`. Each new scan submission via `submit_job()` wipes the option to a fresh empty array (`AIAS_Broken_Banner::on_submit_job()`, called immediately after `$this->check()` so the wipe is gated by nonce + capability per WP Compliance Rules 4 + 11). Bounded O(1) growth — only the most recent scan's dismissal can be stored at any time.

### Changed

- **`build_result` AJAX response shape** (`admin/class-scanner-ajax.php`) extended with `scan_id`, `total_pages`, `pages_blocked: {desktop:int, mobile:int}`, and `blocked_reasons: {reason:int}` map. Derived from each page's `broken_devices` array in the Railway poll-status response. Page-count semantics — a page with both desktop+mobile broken for the same `tier2_cf_challenge` contributes 1 to each device counter and 1 (NOT 2) to the reason counter.
- **Field-name fix in `build_result` per-page loop** — earlier draft read `$page['device']` and `$page['blocked_reason']` (neither field exists on the per-page Railway response). Corrected to iterate `$page['broken_devices']` array and extract `bd['device']` + `bd['reason']` from each entry. Without this fix the banner would never have rendered in production.
- Plugin version bump `1.2.5 → 1.2.6` (cache-bust for `scanner.js`).

### Compliance — wp-compliance pre-code checklist clean

- ABSPATH guard at top of new `class-broken-banner.php` (Rule 21).
- AJAX dismissal handler pairs `check_ajax_referer( 'aias_dismiss_banner' )` with `current_user_can( 'manage_options' )` (Rules 4 + 5 + 11). Nonce alone is not authorization.
- `$_POST['scan_id']` read uses canonical `sanitize_text_field( wp_unslash( $_POST['scan_id'] ?? '' ) )` ordering (Rule 24).
- All rendered strings escaped at output time: `esc_attr` on the `data-scan-id` attribute, `esc_html__` on translated text, `wp_kses_post` on the assembled copy, `esc_html` on the per-reason phrase fallback.
- Dual-autoloader discipline preserved: new class registered in BOTH the main plugin file's `spl_autoload_register` map AND `tests/bootstrap.php` (per the operational rule logged 2026-04-25 after a tests-only update fataled production).

### Tests

- **New `tests/BannerRenderingTest.php`** — 4 PHPUnit cases (4/4 green via local PHPUnit):
  - No banner when both devices have zero blocked pages
  - Desktop-blocked-with-CF-reason banner contains `Desktop scanner blocked on N of M pages`, `Cloudflare`, and the action clause
  - Dismissed banner returns empty HTML when the scan_id is recorded in `wp_options.aias_dismissed_warnings`
  - `submit_job` hook wipes all dismissals (verified via `AIAS_Broken_Banner::on_submit_job()` → `[]` post-condition)

### Internal

- `admin/views/scanner-page.php` — adds a `<div id="cu-banner-area"></div>` placeholder above `#cu-result-summary` so the JS-driven renderer has a stable mount point.
- `admin/class-admin-pages.php` — adds a second `wp_localize_script` call exposing `aiasBannerL10n.nonce` (separate global from the existing `cuScanner` localization) so the dismissal handler can verify nonces without coupling to the main settings object.

### Operator notes

- Banner is live-only — it renders during `build_result` after the scan completes and is not re-shown after navigating away. No SaaS-side scan-history fetch is involved (the plugin polls Railway directly during active scans).
- Banner gracefully degrades: if the Railway worker doesn't yet emit per-page `broken_devices` (older worker versions), the AJAX response carries empty `pages_blocked` / `blocked_reasons` and the banner stays hidden. Behaviour identical to a clean-scan result.

### Production verification — AC-INT corpus 2026-05-06

Banner fired correctly on AC-INT1 (`tier1_http_4xx`) and AC-INT2 (`tier1_http_4xx`); did NOT fire on AC-INT3, AC-INT4, AC-INT5 (baseline), or AC-INT7. Reason-aware copy rendered correctly. Surface 3 (plugin frontend) confirmed working end-to-end. Tracked in internal post-AC-INT followups doc.

---

## [1.2.5] — 2026-05-03

Bug fix release. Closes the F-DEG breach in the rule-emission classifier: Phase A demotions emitted by the Railway scanner (which catch console errors when stripping inline-only handles breaks consumer scripts) were being silently dropped on the plugin side, producing safe rules that re-broke production.

### Fixed

- **`CuJsonBuilder::classify()` now reads the per-device `bucket` field emitted by Railway as the authoritative classification signal.** 
- **Bucket value is whitelist-validated.** Only `'absent' | 'aggressive' | 'needed'` are trusted. Unknown / missing values fall through to the legacy `{loaded, coverage}` derivation as a defense-in-depth safety net (same behavior as pre-1.2.5, so older Railway versions that don't yet emit `bucket` continue to work).

### Internal

- 6 new PHPUnit cases in `tests/CuJsonBuilderTest.php` covering the bucket-passthrough contract: Phase-A-rescued handle does NOT emit a safe rule; Phase-B-rescued aggressive offender does NOT emit any rule; aggressive bucket passes through; absent-on-both-devices still emits Safe (existing behavior preserved); unknown bucket value falls back to legacy; missing bucket field falls back to legacy. 19/19 CuJsonBuilder tests green.

---

## [1.2.4] — 2026-04-30

Security release. Replaces the AES-256-CBC HTTP-auth blob encryption with `sodium_crypto_secretbox` (XSalsa20-Poly1305 AEAD). Existing stored credentials remain valid and migrate transparently on first read.

### Security

- **`Settings::get_http_auth()` / `set_http_auth()` switched to authenticated encryption.** The previous AES-256-CBC primitive had no MAC — an attacker with `wp_options` write access could flip ciphertext bits to manipulate the decrypted plaintext (textbook CBC malleability). New format uses `sodium_crypto_secretbox` (libsodium XSalsa20-Poly1305 AEAD), where any ciphertext or nonce tampering yields a clean decrypt failure rather than silent corruption. Storage prefix `v2:` distinguishes the new format from legacy blobs.
- **Lazy migration on read.** First `get_http_auth()` call on an existing AES-CBC blob decrypts with the legacy code path, then re-encrypts with `v2:` AEAD before returning. Migration is best-effort and idempotent — failures fall through to the decoded value, retried next read.
- **Graceful fallback when libsodium is missing.** PHP 7.2+ ships libsodium, but on some Windows / shared-host PHP builds it can be disabled. `set_http_auth()` detects availability at runtime: sodium present → v2 AEAD; sodium absent → legacy AES-CBC (same as 1.2.3, no regression). No customer-visible error in either case. The lazy migration runs only on hosts where sodium is loaded.

### Internal

- New private helpers in `Settings`: `encrypt_http_auth()`, `encrypt_http_auth_v2()`, `decrypt_http_auth_v2()`, `encrypt_http_auth_legacy()`, `decrypt_http_auth_legacy()`, `derive_http_auth_key_v2()`, `derive_http_auth_key_legacy()`, `sodium_available()`. Public surface (`set_http_auth` / `get_http_auth` / `clear_http_auth`) is unchanged.
- `wp_json_encode` replaces `json_encode` in the encrypt path for WP idiom consistency.

---

## [1.2.3] — 2026-04-30

Security release. Bundles four hardening items from a D-security audit pass plus the four already-shipped items from earlier on the same day. No behavioural changes for end-users; existing API keys, encrypted HTTP-auth blobs, scanner secrets, and active bypass tokens continue to work.

### Security

- **Attribute-safe `esc()` in `admin/js/scanner.js`.** The DOM-roundtrip helper only escaped `&`, `<`, `>` — interpolating it into a quoted attribute (`data-url="${esc(url)}"`) would not have escaped `"` or `'`. Replaced with explicit five-char escape (`& < > " '`). Also wrapped the previously-raw `${type}` interpolations in three `header.innerHTML` / `row.innerHTML` template literals with `esc(...)` for defence-in-depth (post-type slug values are already `sanitize_key`-bounded server-side, so this is belt-and-braces).
- **`Settings::get_scanner_secret()` switched to `bin2hex( random_bytes( 16 ) )`.** Previous generator was `wp_generate_uuid4()` which is `mt_rand`-derived. Existing stored UUID4 secrets are honoured untouched; only first-run generation on installs without a stored secret picks up the new format.
- **`Settings::get_http_auth()` corrupted-option guard.** A stored option without the expected `iv:ciphertext` separator now returns `null` instead of triggering an undefined-index notice.
- **Removed misleading `composer.lock` entry from `.gitignore`.** The lockfile has been tracked since the initial scaffold commit (`4e3dec1`) — the gitignore line was dead and incorrectly suggested the lockfile was excluded. Removed for consistency. No change in tracking behaviour.

### Earlier today (also 1.2.3)

- **9 namespaced class files now ship `defined( 'ABSPATH' ) || exit;`** (Plugin Check Rule 21). Includes both API clients (`class-railway-client.php`, `class-wpservice-client.php`), `class-settings.php`, `class-scan-history.php`, and the five `includes/scanner/class-*.php` files that previously relied on the autoloader for direct-access protection.
- **`download_json` Content-Disposition filename now whitelists `[A-Za-z0-9._-]`.** `sanitize_text_field` strips CR/LF (no header injection) but did not strip `"` — an admin-authenticated request could break the quoted filename. Mirrors the defensive pattern already used in `build_zip()`.
- **New `uninstall.php`.** Removes every `cu_scanner_*` option (plaintext API key, encrypted HTTP-auth blob, scanner secret, active bypass tokens, scan history, per-job snapshots) plus plugin-prefixed transients. Guarded by `WP_UNINSTALL_PLUGIN` + `delete_plugins` capability + `esc_like` on `LIKE` patterns.
- **Railway URL host allowlist.** 

---

## [1.2.2] — 2026-04-30

Cache-bust release. Pairs with Code Unloader 1.4.6's Bug 2 fix.

### Fixed

- **Scanner push did not refresh an open Code Unloader admin Rules tab in the same browser.** The BroadcastChannel emit code was added to `admin/js/scanner.js` in commit `d919945` (1.4.6 Phase 2 in the CU bundle), but `CU_SCANNER_VERSION` stayed at 1.2.1, so browsers continued serving the cached `scanner.js?ver=1.2.1` without the new emit. Bumping `CU_SCANNER_VERSION` 1.2.1 → 1.2.2 forces every browser to re-fetch `scanner.js`. After this bump, pushing rules from Step 4 emits a `cu.rule.changed` BroadcastChannel message; CU's `wireCrossTabSync` listener (since CU 1.4.4) debounces and refreshes the Rules tab in place. Same-browser-same-origin only, with a `localStorage` write/remove fallback for browsers without `BroadcastChannel`.

### Internal

- No code changes in 1.2.2 versus 1.2.1 — the emit code is already in `admin/js/scanner.js` from commit `d919945`. This release only bumps the version constant + plugin-header `Version:` to invalidate the browser cache.
- Code Unloader plugin stays at 1.4.6; only the Scanner side ships a version bump in this release.

---

## [1.2.1] — 2026-04-28

### Changed

- **License clarified to "Proprietary source-available".** Plugin header `License:` field updated, copyright block expanded to spell out the explicit allow/disallow surface (copy/install/use unmodified = OK; modify/fork/sublicense/resell/rebrand/redistribute/remove-checks/derivative = requires written permission from Ermada / WPservice.pro). Matches the `## License` block now in `README.md`.
- **Plugin header `Text Domain` aligned to slug.** `Text Domain: cu-scanner` → `Text Domain: AI-Assets-Scanner`. All `__()` / `_e()` / `esc_html__()` / `esc_html_e()` calls updated in step.
- **README.md** — added shields.io badge row (CI / Claude Code skill / Codex skill / License / Version), added top-level **Prerequisites** section linking Code Unloader, PHP 8.0+, WordPress 6.2+, and reworked the **How it works** diagram into a four-component flow that ends in `Code Unloader (unloads)`.

### Fixed (Plugin Check)

- **`WordPress.WP.I18n.TextDomainMismatch`** (5 errors across `admin/class-admin-pages.php`, `admin/views/history-page.php`, `includes/admin/class-optimizer-state-notices.php`) — text-domain literals replaced.
- **`WordPress.Security.EscapeOutput.OutputNotEscaped`** in `includes/admin/class-optimizer-state-notices.php` — `printf()` of a pre-built `$message` string refactored to inline the `sprintf( esc_html__(), esc_html() )` call so escaping is visible to the static sniff.
- **`plugin_header_invalid_license`** — license string upgraded to descriptive `Proprietary source-available`. (Plugin Check still flags this as non-GPL; that warning is accepted — this plugin is not destined for the WordPress.org repo.)

### Suppressed (false positives, justified inline)

- **`WordPress.Security.EscapeOutput.ExceptionNotEscaped`** in `includes/scanner/class-optimizer-bypass-orchestrator.php` (lines 82, 84) and `includes/scanner/class-strategy-factory.php` (line 17) — exception messages composed for `throw`, never echoed; sniff does not trace `throw` boundaries.
- **`WordPress.Security.NonceVerification.Recommended` / `.Missing`** in `includes/scanner/class-bypass-handler.php` and `admin/class-scanner-ajax.php` — read sites collapsed onto one line so the existing `phpcs:ignore` directives cover the line where the sniff actually fires (per skill Rule 20 placement playbook).
- **`WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound`** for `cu_scanner_scan_complete` and **`NonPrefixedFunctionFound`** .

---

## [1.2.0f] — 2026-04-24

### Added

- **Scan History — Export to ZIP.** New toolbar button on the Scan History admin page. Downloads a ZIP containing `history.json`, `history.csv` (UTF-8 BOM, RFC 4180, formula-injection defuse for `=+-@\t\r`), `README.txt`, and one `scans/<job_id>.json` per completed scan with a stored snapshot. Missing snapshots are listed under a `Missing snapshots:` line in `README.txt`. Falls back to a standalone `.csv` download on hosts without `ZipArchive` or when `ZipArchive::open()`/`close()` fail (`Content-Type: text/csv; charset=utf-8`). Job IDs are defensively sanitized via `preg_replace('/[^A-Za-z0-9._-]/', '', ...)` before concatenation into archive member names.
- **Scan History — Delete all history.** New toolbar button, warns the user to export first via `window.confirm()`, then wipes `cu_scanner_history` and every `cu_scanner_json_<job_id>` option. Success rendered via a single-consume transient (`cu_scanner_history_deleted_notice`, 30 s TTL) as a dismissible `notice-success` on the next page load. New helper `ScanHistory::delete_all(): int` owns the cleanup.

Both handlers gate on `cu_scanner_nonce` + `manage_options`. New AJAX actions: `cu_scanner_export_history` (GET-nonce), `cu_scanner_delete_history` (POST-nonce). New JS file `admin/js/history.js` (enqueued per-page) handles button clicks.

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

Older plugin versions (1.1.5 and below) will see all scans fail with **401 Unauthorized** from the Railway scanner service — the scanner now requires a scoped, short-lived `job_token` per submission instead of the account `api_key`.

**If you are on 1.1.5 or earlier, update immediately.**

### Changed

- **`RailwayClient::submit_job`** now sends `Bearer <job_token>` in the Authorization header (previously `Bearer <api_key>`). The `job_token` is short-lived (24 h), scoped to a single scan, and never exposes the account-level api_key to the Railway runtime. Throws `\RuntimeException('job_token required for Railway submit')` if called without a token.
- **Cancel dialog** — when you click Cancel on an in-progress scan, the plugin now first fetches your current progress from the scanner and shows a confirmation dialog reading *"Cancelling now will charge you for N pages already scanned. Continue?"* You can still back out; confirming proceeds with the cancel and the partial charge.

### Why this matters (security context)

The scanner runtime previously held each active customer's account `api_key` in memory during a scan. A hypothetical compromise of that runtime would have exposed every in-flight key. With the 2026-04-20 service deployment the account `api_key` never leaves your plugin — the scanner runtime only ever sees per-scan `job_tokens`, which expire in 24 h and are scoped to a single job. This is a significant reduction in blast radius on the service side.

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
- **Railway base URL** — Plugin was sending `https://***/wp-json` as the `url` field; Railway then appended `/wp-json/...` creating a double path. Added `CU_SCANNER_WPSERVICE_BASE` constant (bare `https://***`) used exclusively for the Railway callback field.
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

- **Domain locking (client side)** — `WpserviceClient` now computes the site's hostname via `wp_parse_url(get_home_url(), PHP_URL_HOST)` and sends it as `domain` on every request to wpservice (`/auth`, `/jobs/reserve`, `/credits`, `/credits/release`). No call sites change — domain extraction is centralised in a private `domain()` helper.

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
- wpservice API client (authentication, credit balance, job reservation)
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
