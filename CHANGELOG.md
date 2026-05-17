# Changelog

All notable changes to AI Assets Scanner are documented here.

---

## [1.4.0] — 2026-05-17

### Added — Optimizer Fingerprint Broadening (T1 + T2 + T3 bundled)

**Diagnostic trigger:** scanning `flyingpress.com` (real FlyingPress-cached site) returned `outcome: 'no_clue'` despite a clear `<!-- Powered by FlyingPress … Cached at 1778932465 -->` marker as the last line of the response. Root cause was three compounding gaps in the target-stack probe ([`includes/scanner/class-plugin-detector.php`](includes/scanner/class-plugin-detector.php)):

1. `target_headers` empty for FlyingPress, despite the plugin emitting `x-flying-press-cache: HIT` + `x-flying-press-source: Web Server` on every cached page
2. `target_body_markers` list contained `'Optimized by FlyingPress'` (legacy) but the current plugin emits `'Powered by FlyingPress'`
3. Pass-2's 8KB-tail-only fallback left a dead zone between bytes 32,768 and `(body_len − 8192)` — the existing `/wp-content/plugins/flying-press/` marker sits at byte 125,954 on flyingpress.com and was invisible to both passes

Spec: [`docs/product-docs/04-development/2026-05-17-optimizer-fingerprint-broadening-design.md`](../docs/product-docs/04-development/2026-05-17-optimizer-fingerprint-broadening-design.md) (rev 2 + d-review verdict `ready-to-plan`). Plan: [`…-implementation-plan.md`](../docs/product-docs/04-development/2026-05-17-optimizer-fingerprint-broadening-implementation-plan.md) (20 TDD tasks, subagent-driven-development with spec + code-quality reviews per task).

### Tier 1 — Header pattern audit (9 plugins gain patterns, 1 phantom removed)

Updated `OPTIMIZERS::target_headers` based on plugin-source-grep (10 open-source plugins) + live-probe (5 plugin-author sites) + community-documented headers (paid plugins):

- **FlyingPress**: added `x-flying-press-cache`, `x-flying-press-source` (was empty)
- **Hummingbird**: added `hummingbird-cache` (was empty; source: WPMU DEV's `Hummingbird-Cache: Served` PHP emission)
- **Swift Performance**: added `swift3: ` (trailing-space-anchored — DO NOT auto-trim), `x-cache-status: identical/changed/not-modified` (was empty)
- **WP Rocket**: added `x-rocket-nginx-bypass` (kept existing `x-wp-rocket-cache`)
- **NitroPack**: added `x-nitro-cache-from`, `x-nitro-rev` (kept existing `x-nitro-cache`)
- **LiteSpeed Cache**: added `x-litespeed-cache-control` (kept existing `x-litespeed-cache`)
- **W3 Total Cache**: added `x-w3tc-cdn`, `x-powered-by: w3 total cache` (kept existing `x-w3tc-cached-by`, `x-w3tc-page-cache`)
- **Breeze**: added `x-breeze-cache-write`, `x-breeze-cache`, `x-breeze-circuit-breaker` (kept existing `x-cache-handler: breeze`)
- **SG Optimizer**: added `sg-f-cache` (kept existing `x-powered-by: siteground`)

Removed the unverified `x-cache: wpfc-` pattern from WP Fastest Cache — no PHP `header()` emission found in the plugin source; the existing body marker `'WP Fastest Cache file was created'` covers detection.

### Tier 2 — Body marker regex with context-scoping

New optional `target_body_pattern` field on every OPTIMIZERS entry (single PCRE; case-insensitive `/i`) provides fallback detection when literal `target_body_markers` miss due to plugin output drift. The 14 starter regexes use word boundaries + permissive separators `[- _]?` and avoid catastrophic-backtracking constructs (linear-time guarantee tested at AC-T2-5 lint via 100KB adversarial input — 14/14 patterns complete in <100ms each).

New helper `extract_non_text_zones( string $html ): string` strips visible body text before regex application. Preserved zones:
- Entire `<head>` content (title, meta, link, script)
- All HTML comments (entire document)
- All `<script>` / `<style>` / `<noscript>` block contents
- Attribute values from the whitelist: `class`, `id`, `src`, `href`, `data-*`, `rel`, `type`, `name`, `content` (last two added per d-review Mi3 for OG/meta-generator coverage)

Style attributes are deliberately excluded — inline CSS commonly carries unrelated `url(...)` references that would false-positive against `target_body_pattern`.

**`extract_non_text_zones` is hoisted once per probe** before the OPTIMIZERS-scan loop (load-bearing per d-review M3). Without the hoist, the helper would run 14× per probe (~280 ms zone-extraction worst case on 2MB bodies); the hoist cuts that to ~10-30 ms — a ~14× reduction. AC-T2-6 spy test enforces `$extract_call_count <= 1` per `single_probe_attempt` to prevent regression.

False-positive corpus (AC-T2-2): 14 synthetic HTML fixtures with plugin names in visible body text (review/comparison articles); each fixture's `target_body_pattern` must NOT match against the stripped scoped output. All 14 pass — visible body text is correctly excluded.

### Tier 3 — Pass-2 widening (8KB tail → full body)

Dropped the `$scan_tail_only` parameter on `body_match()` and `single_probe_attempt()`. Pass 2 now scans the **entire body** up to the existing 2MB `limit_response_size` cap (already enforced in `wp_remote_get` args). The 95KB dead zone on the canonical flyingpress.com body (~133KB total) is closed; the plugin-directory script tag at byte 125,954 is now visible to Pass 2.

| Body size | Pre-1.4.0 dead zone | Post-1.4.0 dead zone |
|---|---|---|
| 133 KB (flyingpress.com) | 95.5 KB blind | 0 KB blind |
| 500 KB | 467 KB blind (93 %) | 0 KB blind |
| 1 MB | 1008 KB blind (96 %) | 0 KB blind |
| 2 MB+ | bounded by `limit_response_size` cap | unchanged |

CPU cost analysis (spec §6.4.3): combined Pass-2 new path (literal scan + zone extraction + 14× regex) is ~70-150 ms worst-case on a 2MB body; ~20-60 ms typical on 200-500 KB pages. HTTP fetch latency (~100-500 ms typical) still dominates total probe time. Perf budget reconciled to **p50 ≤30 ms, p95 ≤100 ms** added probe latency (AC-OVERALL-4).

### Validation

19 acceptance criteria implemented (AC-T1-1..3, AC-T2-1..6, AC-T3-1..4, AC-OVERALL-1..6):

- **AC-T2-5 perf bench**: ≤50 ms p95 on 2 MB body — PASS (observed <30 ms p95 on dev hardware)
- **AC-T2-6 hoist preservation**: `extract_non_text_zones` invoked exactly 1× per `single_probe_attempt` — PASS
- **AC-T1-1 + AC-T3-4 production-mirror**: FlyingPress detected end-to-end via `probe_target_stack` — PASS via header path AND body fallback
- **AC-T2-2 FP corpus**: 14 visible-text fixtures — none match
- **Regex backtracking lint**: 14 patterns each <100 ms on 100 KB of `'a'`
- **PHPUnit regression**: `PluginDetectorTargetProbeTest` 128/128 PASS, 243 assertions; `ProbeTargetStackEndpointTest` 4/4 PASS (endpoint contract unchanged)

### Operator post-deploy validation

- **AC-T1-1 manual verification**: probe `https://flyingpress.com/` via WP Admin → CU Scanner → Run Scan. Expect: scan-complete view shows FlyingPress detection (header path: `x-flying-press-cache` HIT); no `no_clue` banner.
- **AC-OVERALL-4 latency observation**: across the next 5+ external-URL probes (operator-initiated), `probe_duration_ms` (in the AJAX response) should stay within `p50 ≤ baseline+30ms`, `p95 ≤ baseline+100ms`. Pre-1.4.0 baseline was typically 100-500 ms; post-1.4.0 expected typically 130-600 ms (HTTP fetch dominates; the new in-PHP scan work adds ~20-60 ms typical).
- **7-day monitoring window**: watch the `cu_scanner_probe_target_stack` AJAX outcome distribution. `outcome: detected` rate should rise (Tier 1+2+3 cumulative F-MISS recovery). `outcome: no_clue` rate should fall. `outcome: probe_failed` rate should remain unchanged.

### Migration / backward compatibility

All changes additive:
- `target_body_pattern` is OPTIONAL on OPTIMIZERS entries — pre-1.4.0 callers (or future plugins added without this field) behave identically to today.
- `target_body_markers` literals unchanged (still primary signal; new regex is fallback OR'ed via `body_match($body, $b_pat, $use_range) || body_match_pattern($scoped_body, $entry['target_body_pattern'] ?? null)`).
- `body_match()` and `single_probe_attempt()` signature changes are internal (private static); no public API touched.
- `probe_target_stack()` return shape, AJAX endpoint contract, 24h cache key (`cu_scanner_target_stack_<md5>`), and TTL all unchanged.

### Rollback path

`target_body_pattern` is optional; an emergency rollback can NULL all entries' pattern fields via a single config edit without a code revert. The `body_match` signature change (drop `scan_tail_only`) is irreversible without code revert, but the Pass-2 full-body behavior is strictly more permissive than the prior 8KB-tail, so rollback is unlikely to be needed.

### Files

- `includes/scanner/class-plugin-detector.php` — OPTIMIZERS updates (T1 + T2), new `extract_non_text_zones()` + `body_match_pattern()` helpers, `body_match()` signature change (T3), loop hoist in `single_probe_attempt()`, `__test_*` seam additions
- `tests/PluginDetectorTargetProbeTest.php` — 71 new tests (helper coverage, T1 fixtures, T2 fixtures, FP corpus, backtracking lint, AC-T3 integration, AC-T1-1/T3-4 end-to-end, AC-T2-5 perf bench, AC-T2-6 hoist spy)
- `ai-assets-scanner.php` — version bump 1.3.7 → 1.4.0
- `CHANGELOG.md` — this entry

---

## [1.3.7] — 2026-05-17 PM late

### Fixed — FU-NEW-X-A: Subsystem D-4 banner silent disappearance on hard-error external scans

**Bug (F-DEG-adjacent — observability regression):** for external scans that errored hard at the URL level (pre-probe correctly flagged the 4xx; operator clicked "Continue with scan"; Railway worker recorded `pages_completed:0, pages_error:1, pages_blocked_*:1, blocked_reasons:{tier1_http_4xx:1}`), the AAS scan-complete view showed only the ordinary "Scan complete. 0 safe rules, 0 aggressive rules generated." message — without the yellow-triangle ⚠ broken-banner that operators relied on pre-FU-NEW-2 to recognize "this scan didn't produce useful rules because the site errored." Operator reported regression 2026-05-17 PM after re-running a lubd.com scan post-T3d-SFTP.

**Root cause:** the post-scan banner pipeline (`class-scanner-ajax.php::build_result()`) walks the Railway per-page `broken_devices` array to compute `pages_blocked` + `blocked_reasons`. For some scan-error paths — notably `analyzePage`'s outer `catch` at [`src/analysis/page-analyzer.js:893-897`](../../CU%20Scanner%20Railway/cu-scanner-railway-master/cu-scanner-railway-master/src/analysis/page-analyzer.js#L893) which returns `{url, status:'error', assets:[]}` without `broken_devices`, plus certain pre-runPass failures — the page result lands at AAS with `status='error'` but no `broken_devices` field. The walk then yields zero pages_blocked, the JS-side `renderBrokenBanner()` returns early per its zero-check at scanner.js:1181, and the user sees only the rule-count summary.

**Fix:** add a defensive fallback to `class-scanner-ajax.php::build_result()` — after the `broken_devices` walk, if `pages_blocked.desktop === 0 && pages_blocked.mobile === 0` BUT one or more pages have `status === 'error'`, count each errored page as blocked-on-both-devices with synthetic reason `scan_errored` (counted in `blocked_reasons`). The JS-side `phraseMap` gets a `scan_errored: 'scan errored'` entry; the `reasonCategory()` function maps `scan_errored → 'error'` so the action_clause copy reads "Your server returned an error or didn't respond..." — same copy operators see for `tier1_http_4xx/5xx/transport_error`.

**Behavior after fix:**

| Scan outcome | `pages_blocked` source | Banner reason phrase | action_clause category |
|---|---|---|---|
| `broken_devices` populated (e.g., Phase A symbol_match demote on a 4xx site) | from broken_devices walk (unchanged) | `tier1_http_4xx` → "site denial (4xx)" | 'error' → "server error" copy |
| `status='error'` but no `broken_devices` (hard pre-runPass fail) | **fallback: 1 page → desktop+1, mobile+1, reason=`scan_errored`** | `scan_errored` → "scan errored" | 'error' → "server error" copy |
| `status='done'` everywhere | walk yields 0, fallback skips | no banner | n/a |

**Files:** `admin/class-scanner-ajax.php` (~12 LOC fallback block at L594), `admin/js/scanner.js` (+2 LOC phraseMap + reasonCategory), `ai-assets-scanner.php` (version bump 1.3.6 → 1.3.7), `CHANGELOG.md`. F-DEG-neutral on the rule-pipeline (no scan behavior changed). F-CHECK-EFF + (restores the user-visible "this scan errored" signal that pre-FU-NEW-2 displayed).

---

## [1.3.6] — 2026-05-17

### Fixed — T3d: JS/PHP banner `action_clause` divergence

**Bug (UX cosmetic, F-DEG-neutral):** the broken-scan banner has two render paths — server-side via `class-broken-banner.php::action_clause()` (history view, REST responses) and client-side via `admin/js/scanner.js::renderBrokenBanner()` (live scan result on the Running tab). The PHP path correctly mapped `tier1_http_4xx`, `tier1_http_5xx`, `tier1_transport_error` to the `'error'` category ("Your server returned an error or didn't respond...") and `tier1_http_rate_limit` to `'rate'` ("Your server rate-limited the scanner..."), with everything else falling back to `'bot'` ("Your bot protection denied the scanner..."). The JS path was hardcoded to ALWAYS emit the `'bot'` copy regardless of reason — so the same scan could surface inconsistent guidance depending on which UI path the user happened to see first.

**Reproduced:** scan against a deterministic 404 fixture (banner-test-404 path) showed "Your bot protection denied the scanner..." in the live Running-tab banner, then "Your server returned an error or didn't respond..." in the History-tab banner for the same scan_id. Two different messages, same reason, same scan. Functionally fine; semantically inconsistent.

**Root cause:** `admin/js/scanner.js::renderBrokenBanner()` at L1206 (pre-fix) hardcoded the action string to the 'bot' copy — no per-category mapping was implemented on the JS side; the PHP-side `reason_category()` lookup was never mirrored. Spec'd as a Minor follow-up from FU-NEW-4/5 work-track 2026-05-16; closed 2026-05-17 PM.

**Fix:** mirror PHP's `reason_category()` lookup in JS. Add `reasonCategory(reason)` function returning `'rate' | 'error' | 'bot'`; map the per-scan `reasons` keys; if all categories collapse to a single non-bot category, use that category's copy verbatim from PHP; otherwise fall back to 'bot' (matches PHP's `count($categories) === 1` gate at `class-broken-banner.php:137`).

**Behavior after fix:**

| Reason set | JS-side action_clause | PHP-side action_clause | Match? |
|---|---|---|---|
| `{tier1_http_4xx: N}` (only) | "Your server returned an error or didn't respond..." | (same) | ✅ |
| `{tier1_http_rate_limit: N}` (only) | "Your server rate-limited the scanner..." | (same) | ✅ |
| `{tier2_cf_challenge: N}` (only) | "Your bot protection denied the scanner..." | (same) | ✅ |
| Mixed `{tier1_http_4xx: 1, tier2_cf_challenge: 1}` | "Your bot protection denied the scanner..." (fallback) | (same — `count($categories) !== 1` ⇒ fallback) | ✅ |

**Files:** `admin/js/scanner.js` (~15 LOC added), `ai-assets-scanner.php` (version bump 1.3.5 → 1.3.6), `CHANGELOG.md`. Pure UX-text change; F-DEG-neutral; F-CHECK-EFF + (eliminates two-paths-of-truth on a user-facing message).

---

## [1.3.5] — 2026-05-16

### Fixed — FU-NEW-9: operator-site bypass keys leaking onto external scan URLs

**Bug (F-DEG):** when scanning external URLs, the operator's wpservice.pro plugin auto-bypass keys (`nowprocket` for WP Rocket, `nowpcu` for Code Unloader, `perfmattersoff` for Perfmatters, etc.) were being appended to ALL scan URLs — including external targets — alongside the target-detected suffixes. Example: scanning `https://bestdiagnostics.net/` (LiteSpeed external) shipped as `https://bestdiagnostics.net/?nowprocket&nowpcu&LSCWP_CTRL=before_optm` — the `nowprocket&nowpcu` are leaked operator-site keys that don't belong on an external target's request.

**Reproduced 2026-05-16 PM:** operator scanned bestdiagnostics.net (LiteSpeed) after 1.3.4 deploy and observed the polluted URL in worker logs. Probe response was clean (`suggested_bypass_per_url: { "https://bestdiagnostics.net/": ["LSCWP_CTRL=before_optm"] }` — ONLY LiteSpeed key, no operator-site contamination); pollution happened on the AAS side at submit_job assembly. Same pattern verified on prior lubd.com scans (`?nowprocket&nowpcu` present on URLs despite the host running neither WP Rocket nor Code Unloader — these were operator-site keys leaking through).

**Root cause:** `admin/class-scanner-ajax.php:154-159` builds `$bypass_params` from `$detected['auto_bypass']` (detected via `PluginDetector::detect()` against the LOCAL WP install — wpservice.pro's own plugins). Then the `$build_scan_url` closure at L221 unconditionally calls `add_query_arg( $bypass_params, $sanitized )` on every URL — INCLUDING external ones. The intent comment at L164 ("External URLs use target-detected suffixes; internal URLs use $host_bypass") was implemented for the FU-NEW-2 `$host_bypass` / `$target_bypass_per_url` path only — the legacy `$bypass_params` (auto_bypass) path predates FU-NEW-2 and was never made host-aware.

**Fix:** make `$bypass_params` application host-aware inside `$build_scan_url`. Extract `$home_host = wp_parse_url( home_url(), PHP_URL_HOST )` once at the submit_job entry. Inside the closure, parse each URL's host and only call `add_query_arg( $bypass_params, $sanitized )` when the URL's host matches `$home_host` (case-insensitive, via `strcasecmp`). External URLs receive ONLY the probe-derived `$bypass_suffixes` (which may be empty if probe returned `no_clue` / `probe_failed` — graceful no-bypass behavior).

**Behavior after fix:**

| URL type | `$bypass_params` (operator-site) | `$bypass_suffixes` (target-probe) | Final example |
|---|---|---|---|
| Internal (same-host as `home_url()`) | ✅ applied | ✅ applied | `wpservice.pro/page?nowprocket&nowpcu&cu_scan_token=…` (unchanged) |
| External `class_a_clean` (LiteSpeed) | ❌ skipped | ✅ `LSCWP_CTRL=before_optm` | `bestdiagnostics.net/?LSCWP_CTRL=before_optm&cu_scan_token=…` (clean) |
| External `class_a_clean` (WP Rocket on a DIFFERENT site) | ❌ skipped | ✅ `nowprocket` (from probe) | `that-site.com/?nowprocket&cu_scan_token=…` (clean — comes from THEIR detection, not ours) |
| External `no_clue` / `probe_failed` | ❌ skipped | empty | `lubd.com/?cu_scan_token=…` (just the token; graceful no-bypass) |

**Why this didn't surface in FU-NEW-2 AC validation:** FU-NEW-2's ACs focused on the per-URL `$target_bypass_per_url` (suggested_bypass_per_url) plumbing, which IS correctly host-aware. The legacy `$bypass_params` (auto_bypass) path predates FU-NEW-2 and wasn't covered by FU-NEW-2's test surface. The pollution was only operator-visible once they inspected actual scan URLs in the Railway worker log.

- **Version bump** `1.3.4 → 1.3.5`.
- **Internal `SCANNER_JS_VERSION` unchanged** at `1.0.10.12` (server-side PHP fix only; scanner.js not modified).
- **wp-compliance:** P10 invoked pre-edit. All 27 rules N/A or pass (pure logical filter; no new input read, no new output, no SQL, no security surface).

Refs:
- Operator-reported bug 2026-05-16 PM during AC validation of bestdiagnostics.net (LiteSpeed) scan after 1.3.4 deploy. Verbatim operator framing: "`?nowprocket` and `nopwcu` are again transplates from my website, that website should have only Lightspeed cache related `LSCWP_CTRL=before_optm` suffix. External URLs with detected stacks should ran ONLY detected stack suffix (if is it suffix frienldy category), not my website + their."
- Related: FU-NEW-2 spec (rev 2) `docs/superpowers/specs/2026-05-15-fu-new-2-target-stack-bypass-routing-design.md` — fixed the `$host_bypass` / `$target_bypass_per_url` half of the bypass-routing intent; this commit completes the second half (the legacy `$bypass_params` path).

---

## [1.3.4] — 2026-05-16

### Fixed — pre-probe external-URL safety gate restoration

**Gap (pre-FU-NEW-2 regression surfaced 2026-05-16 PM):** the original external-URL `confirm()` dialog ("This is an external URL — continue?") was removed in FU-NEW-2 (1.2.9) and replaced by the probe-driven outcome modal. The new modal correctly gates non-`class_a_clean` outcomes, but on uniform `class_a_clean` / `A_star` outcomes the modal is suppressed for "silent proceed" — leaving NO operator confirmation before the scan starts on suffix-friendly external sites (LiteSpeed, WP Rocket, Perfmatters, FlyingPress hosts, etc.).

**Reproduced 2026-05-16 PM:** operator entered `getkush.cc` (LiteSpeed-class suffix bypass) → Start Scan → probe AJAX fired → no modal shown → scan started + credits reserved with no operator click.

**Fix:** added a pre-probe safety gate inside the `if (externalUrls.length > 0) {` block in `admin/js/scanner.js` (~L858, before the inline "Detecting target stack…" spinner shows). Shows `window.confirm(...)` listing the unique external hosts + the URL count before any probe AJAX fires. Cancel = clean abort (return). Continue = proceed to probe + outcome-specific modal (existing FU-NEW-2 behavior preserved end-to-end for non-`class_a_clean` outcomes).

**Why BEFORE the probe, not after:** the probe is itself an HTTP request from wpservice.pro to the external site. Operator-stated requirement: ask the external-website question BEFORE starting the stack-probe check, so the operator can abort without any external network calls (and without wpservice.pro server-side load).

**Why the silent-proceed-on-`class_a_clean` detection modal-skip is preserved:** intentional and orthogonal. The silent-proceed concerns the DETECTION RESULT ("Detected LiteSpeed — proceeding with bypass") which is unwanted UX noise per operator directive. The pre-probe gate concerns generic external-scan consent — a separate concern that operator wants. Both rules now coexist: pre-probe `confirm()` covers consent; post-probe modal (for non-`class_a_clean`) covers detection-result transparency; class_a_clean silent-proceed (after pre-probe consent) covers the high-confidence happy path.

- **Version bump** `1.3.3 → 1.3.4`.
- **Internal `SCANNER_JS_VERSION`** bumped `1.0.10.11 → 1.0.10.12`.

Refs:
- Operator directive verbatim 2026-05-16 PM: "ASk the external website question BEFORE starting the stack probe check" (surfaced during FU-NEW-7 AC validation closure).
- Memory: `~/.claude/projects/d--AI-ChatGPT/memory/feedback_silent_proceed_suffix_friendly_correct.md` updated to clarify scope (rule applies to detection-result observability toast, NOT to the external-URL safety gate restored in this version).

---

## [1.3.3] — 2026-05-16

### Fixed — FU-NEW-7: end-of-body cache marker detection (Two-pass probe)

**Gap:** the target-stack probe's existing 32KB scan cap (via `Range: bytes=0-32767` request header AND `substr( $body, 0, BODY_SCAN_MAX_BYTES )` inside `body_match()` / `is_wordpress_target()`) prevented detection of 9 of 14 OPTIMIZERS table plugins whose identifying HTML comment is injected AFTER `</html>` — beyond 32KB on typical 100KB-1MB WP pages.

**Affected plugins (now detectable):** WP Rocket, LiteSpeed, WP Fastest Cache, W3 Total Cache, **Breeze**, Cache Enabler, Swift Performance, FlyingPress, SG Optimizer. Header-based detection (`x-wp-rocket-cache`, `x-cache-handler: breeze`, etc.) was the only working signal for these plugins; when the CDN strips headers (Kinsta strips `x-cache-handler: breeze` per observed pinadventures.com behavior), the probe returned `no_clue` even on clear cache-plugin-protected sites.

**Fix:** added Pass 2 to `probe_target_stack()` at the wrapper level. Pass 1 (existing ranged 32KB + head-area scan) is unchanged. Pass 2 fires when Pass 1 returns `inconclusive` AND `reason === null` (no-markers case — NOT HTTP-error / transport-error inconclusives). Pass 2 re-probes each URL with `use_range=false` (full body, capped at 2MB via `'limit_response_size'`) + `scan_tail_only=true` (last 8KB scan via new `body_match()` parameter) to recover end-of-body markers.

**Why last-8KB instead of full-body substring scan:** end-of-body cache markers live in HTML comments after `</html>`. Scanning the full body would expand the false-positive surface (article text mentioning cache plugin names — e.g., wptavern.com blog posts about WP Rocket — would match the bare marker strings). Last-8KB scan matches actual signal location, bounds CPU, and narrows FP surface.

**Trade-off / known limitation:** `is_wordpress_target()` deliberately remains head-only. WP sites with `<meta name="generator">` beyond byte 32768 (rare; long head injections) ship as `non_wordpress` and are not re-probed in v1.

**Performance:**
- Pass 1 detects (head-area marker / header): 1-2 fetches, unchanged.
- Pass 2 detects: 3 fetches (URL1 ranged, URL2 ranged, URL1 full ~100KB-2MB).
- All 4 attempts (worst case, truly-no-cache-plugin target): 4 fetches, +2-6s latency.
- 24h transient cache absorbs repeat probes on the same host.

**Coverage:** 10 new PHPUnit tests in `tests/PluginDetectorTargetProbeTest.php` — 2 helper tests (T-N7-A `body_match()` tail-only mode; T-N7-B `single_probe_attempt()` parameter passthrough) and 8 integration tests (T-N7-1 header-detect fast-path; T-N7-2 Breeze tail-detect on pinadventures-class fixture; T-N7-3 all-4-inconclusive worst-case; T-N7-4 HTTP-4xx exclusion; T-N7-5 definitive `non_wordpress` exclusion; T-N7-6 false-positive control with article-body cache plugin name; T-N7-7 SSRF gate; T-N7-8 24h cache hit short-circuit).

- **Version bump** `1.3.2 → 1.3.3`.
- **Internal `SCANNER_JS_VERSION` unchanged** at `1.0.10.11` (scanner.js not modified — server-side PHP refactor only).

Refs:
- Spec: `docs/superpowers/specs/2026-05-16-fu-new-7-two-pass-probe-design.md` (rev 2.1)
- D-reviews: `…-design-review.md` (rev 1, needs-revision 3C/4M/5m/3n) + `…-design-review-r2.md` (rev 2, ready-to-plan 0C/0M/2m/2n)
- Plan: `docs/superpowers/plans/2026-05-16-fu-new-7-two-pass-probe-plan.md`
- Spawned during FU-NEW-4/5 AC validation 2026-05-16 PM after pinadventures.com (Breeze) returned `no_clue` from the probe.

---

## [1.3.2] — 2026-05-16

### Fixed — FU-NEW-6 rev 2: include-only-mode re-trigger gap (1.3.1 hotfix was insufficient)

**Bug (still present after 1.3.1):** the L825-832 include-only-path block in `admin/js/scanner.js` populates `selectedUrls` from the textarea, but its guard `if (discoveredUrls.length === 0)` only fires on the FIRST Start Scan click — because L829 sets `discoveredUrls = includeList` after that point. On the SECOND+ click within the same page session, neither the L825 block NOR the 1.3.1 defensive re-read at L846 (same guard) fired, so `selectedUrls` retained the prior scan's URLs.

**Reproduced 2026-05-16 PM:** operator did fresh page load → typed `pinadventures.com` → Start Scan (probe sent pinadventures.com ✓) → Cancel modal → cleared textarea → typed `wptavern.com` → Start Scan again → probe AJAX payload showed `urls[0]=pinadventures.com, urls[1]=wptavern.com` even though textarea contained ONLY `wptavern.com` (confirmed via `console.log(JSON.stringify(document.getElementById('cu-included-urls').value))`).

**Fix:** widen the include-only-mode detection. Instead of `discoveredUrls.length === 0`, use `(discoveredUrls.length === 0) || (groupedUrls.included !== undefined)`. The `groupedUrls.included` field is set uniquely by L830 of the include-only path (NOT set by the Discover Pages flow at L541, which sets `groupedUrls = res.data.groups`). This makes the L825 block re-fire on every Start Scan click in include-only mode while leaving Discover Pages mode untouched.

The 1.3.1 redundant defensive fix at the post-L832 site is now removed (the L825 block handles it correctly).

**Impact severity (same as 1.3.1):** F-DEG-critical — silent wrong-target scanning, wrong-host attribution in `cu_scanner_events`, credits spent on unintended scans.

- **Version bump** `1.3.1 → 1.3.2`.
- **Internal `SCANNER_JS_VERSION`** bumped `1.0.10.10 → 1.0.10.11`.

Refs:
- Diagnosis: operator DevTools Network + Console capture 2026-05-16 PM (textarea content `"wptavern.com"`, AJAX payload had pinadventures + wptavern → root cause at `admin/js/scanner.js:825` pre-existing guard semantics).
- Supersedes the 1.3.1 fix at the same L846 site (now removed in this version).

---

## [1.3.1] — 2026-05-16

### Fixed — FU-NEW-6: selectedUrls state-leak across scan attempts

**Bug:** When the user changed the include-URLs textarea content between scan attempts within a single page session, scanner.js's `selectedUrls` could retain the previous attempt's URLs. The probe AJAX (`cu_scanner_probe_target_stack`) + the submit_job AJAX (`cu_scanner_submit_job`) both read from `selectedUrls`, so a stale URL would be sent server-side — Railway would silently scan the WRONG target while the user thought they were scanning the URL they typed.

**Reproduced 2026-05-16 PM:** operator entered `https://pinadventures.com/` in the textarea, but DevTools Network capture showed the probe AJAX sending `urls[0]=wptavern.com` (the previous test target). The modal correctly displayed wptavern.com results (the server probed the URL it received); the bug was upstream in JS state.

**Fix:** added a 3-line defensive re-read at `admin/js/scanner.js:836` — before deriving `externalUrls`, re-call `getIncludedUrls()` to pull fresh URLs from the textarea when in direct-URL mode (`discoveredUrls.length === 0`). Makes the user-visible textarea the single source of truth at scan-trigger time. Discover Pages mode keeps its existing include/exclude filter logic unchanged.

**Impact severity:** F-DEG-critical pre-fix (silent wrong-target scanning + wrong-host attribution in `cu_scanner_events` telemetry). Defensive re-read closes the symptom without changing the L505 input-handler architecture (which can be reviewed later as a separate followup if needed).

- **Version bump** `1.3.0 → 1.3.1` (cache-bust for `scanner.js`).
- **Internal `SCANNER_JS_VERSION`** in `admin/js/scanner.js` bumped `1.0.10.9 → 1.0.10.10` (matches the file's own change-tracking).

Refs:
- Diagnosis: operator DevTools Network + Console capture 2026-05-16 PM (probe AJAX payload showed wptavern.com despite textarea reading pinadventures.com).
- Master tasks: `master-tasks.md` — FU-NEW-6 work-track.

---

## [1.3.0] — 2026-05-16

### Cache-bust release — no code changes

Plugin version bumped `1.2.9 → 1.3.0` to force browser cache-bust on enqueued JS/CSS files (`?ver=1.3.0` query parameter) and provide a clean deploy signal for FU-NEW-4 AC-A validation on wpservice.pro. Internal `SCANNER_JS_VERSION` in `admin/js/scanner.js` remains at `1.0.10.9` (the JS file itself is unchanged).

**No functional changes.** Banner pipeline (`renderBrokenBanner` at `admin/js/scanner.js:1143`), target-stack probe (FU-NEW-2 Phase 6), and submit_job payload contract all carry through unchanged from 1.2.9.

Operator deploy procedure: SFTP `ai-assets-scanner.php` (only file with version-string changes) + `CHANGELOG.md` to wpservice.pro plugin directory. Verify WP Admin → Plugins page shows version `1.3.0`. Hard-refresh any open admin pages.

Refs:
- Plan: `docs/superpowers/plans/2026-05-16-fu-new-4-fu-new-5-plan.md`
- Spec: `docs/superpowers/specs/2026-05-16-fu-new-4-fu-new-5-design.md`
- Work-track: FU-NEW-4 + FU-NEW-5 (bundled) — `master-tasks.md` L51

---

## [1.2.9] — 2026-05-15

### Added — FU-NEW-2: Target-stack-aware bypass-suffix routing for external-URL scans

When scanning an external URL (host differs from the WP install hosting the plugin), the plugin now probes the target server-side via `wp_remote_get` to detect its actual optimizer/cache stack BEFORE scan-credit reservation, then constructs per-URL `bypass_suffixes` from the target's detected class A/A_star plugins instead of leaking the host's bypass keys onto unrelated targets.

Background: prior behavior pushed the AAS-host's class-A bypass keys (e.g., `?nowprocket&perfmattersoff` from a WP-Rocket+Perfmatters host) onto every scan URL regardless of target. On a target running a different cache plugin (e.g., Breeze), the foreign query params bust the target's cache key → un-cached HTML cascade → Phase B `page.goto` timeout. This was discovered during FU-NEW-1 investigation as a test-artifact rather than a production-customer failure (production customers run AAS from their own site, where detection works correctly).

#### Probe mechanism

- New AJAX endpoint `cu_scanner_probe_target_stack` (registered alongside existing `cu_scanner_submit_job`). `manage_options` + nonce-gated.
- Per-host single probe per scan + 24h transient cache (`cu_scanner_target_stack_<md5(scheme://host:port)>`).
- 2-attempt fallback: probe URL #1; if inconclusive, probe URL #2 from same host (next selectedUrl) or root `/`.
- `GET` with `Range: bytes=0-32767` + `User-Agent: CU-Scanner-Probe/1.0 (target-stack-detection)`. 32KB body cap (CPU-bounded regardless of `Range` honor).
- Scheme allowlist: `http://` + `https://` only. `file://`, `javascript:`, etc. rejected with `probe_failed: invalid_scheme` — `wp_remote_get` never called.
- WP_Error / 5xx / 403 / 429 → `probe_failed` with sanitized `reason` (IPs + server-internal paths `/home|var|usr|srv|etc|opt|root|tmp/` redacted; 120-char cap).
- Response field whitelist enforced via `strip_to_whitelist` — never returns raw HTTP body, raw headers, IPs, cookies, or stack traces to the admin JS.

#### Detection table (14 optimizers + WordPress detection)

`OPTIMIZERS` table in `class-plugin-detector.php` extended with `target_headers` + `target_body_markers` sub-keys on every entry. Detection scans HTTP response headers + first 32KB of HTML body (case-insensitive substring). Multi-stack detection allowed.

- **Class A** (have bypass_query): WP Rocket (`nowprocket`), Perfmatters (`perfmattersoff`), Autoptimize (`ao_noptimize=1`), NitroPack (`nonitro`), Asset CleanUp (`wpacu_no_load`)
- **Class A_star**: LiteSpeed Cache (`LSCWP_CTRL=before_optm`)
- **Class A — FlyingPress reclassified** (was class C): `no_optimize` query param per FlyingPress changelog v2.3.0 (15 Oct 2020). Strategy class `class-flying-press-bypass.php` + `FlyingPressBypassTest.php` + StrategyFactory match arm DELETED (P8 YAGNI; git history preserves). FlyingPress no longer triggers Class C consent modal on operator-local FlyingPress installs; URL gets `?no_optimize` instead of plugin-side pause/resume orchestration.
- **Class B** (no bypass query — QS-naive cache plugins): WP Fastest Cache, W3 Total Cache, Breeze, Cache Enabler, Swift Performance
- **Class B/C (runtime-resolved)**: Hummingbird
- **Class C** (plugin-side disable): SiteGround Optimizer (FlyingPress moved out; SiteGround is now the only class C remaining)
- **WordPress detection**: `<meta name="generator" content="WordPress">`, `wp-content/`, `wp-includes/`, `wp-json/` paths, `x-pingback` header

#### Outcome classifier (§5.4 decision tree)

Precedence: `probe_failed` > `non_wordpress` > optimizer classification. Body markers without WP context are NOT trusted (regex may match unrelated customer content; spec §5.4 trust-WP-first rule). Six outcomes:

- `class_a_clean` — ≥1 class A/A_star detected, no class B/C → silent proceed; class A bypass keys applied
- `class_bc_only` — only class B/C detected → blocking warning naming the stack + bot-protection-disable suggestion; empty bypass_suffixes
- `hybrid_a_plus_bc` — ≥1 class A/A_star + ≥1 class B/C → blocking warning naming both groups + informed-consent on cache-key conflict; class A bypass keys applied
- `no_clue` — WP confirmed, no optimizer signal → blocking warning + bot-protection-disable suggestion; empty bypass_suffixes
- `non_wordpress` — no WP signals → blocking warning naming the unknown-target case; empty bypass_suffixes
- `probe_failed` — WP_Error / 5xx / 403 / 429 / timeout → blocking warning naming reason + bot-protection-disable suggestion

#### JS dialog flow (`admin/js/scanner.js`)

Existing simple `confirm()` external-URL gate at L656-661 REPLACED with: probe trigger → inline spinner ("Detecting target stack...") → `showProbeOutcomeDialog` outcome dispatcher. Multi-host scans render either uniform single dialog (when all hosts share outcome) or per-host accordion (mixed outcomes). Cancel during probe via `AbortController` (best-effort; degrades to "wait for response" on browsers without it). On confirm, `cu_scanner_submit_job` POST now includes new top-level fields `target_bypass_per_url` (per-URL bypass map from probe) + `target_stack_summary` (per-host telemetry blob).

The existing class C consent flow (for SiteGround Optimizer hosts) is preserved — `class_c_consent_required` retry path now also forwards the new payload fields so the contract is consistent across both paths.

#### `cu_scanner_submit_job` payload changes (per-URL `bypass_suffixes` per §4.2 rule)

For each URL in the scan submission:
- **Internal URL** (same host as WP install hosting AAS) → uses host-detected `build_bypass_suffixes($detector_typed)` array (today's behavior; no regression on existing same-site scan workflows)
- **External URL** → uses target-detected suffixes from the probe's `suggested_bypass_per_url[url]` map. Missing entries default to **empty `[]`** (NOT host-leaked, per operator's explicit directive) AND fire `do_action( 'cu_scanner_target_bypass_missing', [ 'url', 'host' ] )` plugin-local hook for operator-side debugging visibility (no SaaS forwarding — telemetry gap deferred as FU-NEW-5).

#### Compliance — wp-compliance review (P10) pre-push pass

wp-compliance review on the full FU-NEW-2 work-track:
- **Rule 25 fix shipped (commit `b64dbee`)**: `$_POST['target_bypass_per_url']` is a structured multi-level map; the outer `(array) wp_unslash()` left inner-level scalars unsanitized. Now walks the structure, validates URL keys via `esc_url_raw`, restricts suffix values to `[A-Za-z0-9_=.\-]+` (the legal bypass-suffix character class produced by `OPTIMIZERS`). Anything outside the allowlist is dropped silently.
- **Rule 13 (SSRF) — accept-as-risk**: scheme allowlist enforced (no `file://`, `javascript:`, etc.); private-IP / cloud-metadata-endpoint blocklist intentionally default-off — operator may legitimately scan staging URLs on private IPs. Admin-only access (`manage_options`) is the documented trust boundary (spec §6.1.1).
- Rules 1, 2-3, 4-5, 10, 11-12, 14-18, 20-22, 23, 26 all pass.

### Files

- `includes/scanner/class-plugin-detector.php` — OPTIMIZERS table extended; `probe_target_stack` + `single_probe_attempt` + `header_match` + `body_match` + `classify_outcome` + `sanitize_reason` + `is_wordpress_target` + `BODY_SCAN_MAX_BYTES` + `ALLOWED_SCHEMES` constants; FlyingPress entry reclassed C → A; FlyingPress dropped from `SOFT_WARN`; orphaned `detect_typed()` PHPDoc relocated to its correct file position.
- `includes/scanner/class-strategy-factory.php` — `'flying_press' => new FlyingPressBypass()` match arm DELETED + `use FlyingPressBypass` import removed.
- `includes/scanner/strategies/class-flying-press-bypass.php` — DELETED (78 LOC).
- `admin/class-scanner-ajax.php` — new `probe_target_stack` AJAX handler + helpers (`group_urls_by_host`, `root_url_for`, `strip_to_whitelist`, `is_uniform_outcome`, `any_outcome_matches`, `build_pages_array`, `capture_target_stack_summary`); existing `submit_job` rewired for per-URL bypass + new payload fields; wp-compliance Rule 25 per-value sanitization on `target_bypass_per_url`.
- `admin/js/scanner.js` — `confirm()` external-URL gate replaced with probe-first flow + `showProbeOutcomeDialog` + multi-host rendering + abort-during-probe UX + class C consent retry path updated to forward new fields. Cache-bust `SCANNER_JS_VERSION` `1.0.10.8 → 1.0.10.9`.
- `admin/css/ai-assets-scanner-admin.css` — `.cu-probe-outcome-dialog` styling mirrors existing `.cu-consent-dialog` pattern; `.cu-probe-spinner` icon styles.
- `tests/PluginDetectorTest.php` — +2 tests (table-shape enforcement, FlyingPress reclass shape assertion).
- `tests/PluginDetectorTargetProbeTest.php` — NEW file, 24 tests (4 matcher + 7 classifier + 11 probe + 2 absorbed-from-review).
- `tests/ProbeTargetStackEndpointTest.php` — NEW file, 4 tests (auth, nonce, scheme rejection, response-field whitelist).
- `tests/SubmitJobPayloadTest.php` — NEW file, 5 tests (per-URL bypass split, defensive fallback, target_bypass_missing event, target_stack_summary capture, empty-input handling).
- `tests/StrategyFactoryTest.php` + `tests/OptimizerBypassOrchestratorTest.php` + `tests/RestPreflightTest.php` + `tests/MultiOptimizerCompositionTest.php` + `tests/OptimizerStateTest.php` + `tests/PluginDetectorBypassSuffixesTest.php` + `tests/PluginDetectorTypedTest.php` — fixtures updated for FlyingPress class A; `FlyingPressBypassTest.php` DELETED.
- `ai-assets-scanner.php` — header version `1.2.8 → 1.2.9` + `CU_SCANNER_VERSION` constant.
- `README.md` — features bullet added; version badge `1.2.8 → 1.2.9`.
- `CHANGELOG.md` — this entry.

### Cross-repo dependencies

- Requires SaaS plugin `1.2.13+` (defensive sanitization for `scan.target_stack_summary` event at `/service/event`).
- Worker (cu-scanner-railway) UNCHANGED — per-URL `bypass_suffixes` is already on the wire at `page-analyzer.js:26-33 buildScanUrl` and `worker.js:165` per-page destructure.

### Out of scope (explicit decisions)

- **FU-NEW-5: Railway forwarder for `target_stack_summary` telemetry** — operator chose to minimize inbound HTTP load on wpservice.pro web host; the telemetry data flows plugin → Railway in the existing submit_job payload but is NOT currently forwarded to SaaS `/service/event` for persistent storage. SaaS receiver is prepared (defensive sanitization shipped) for whenever a future Railway-forwarder task lands. Tracked at master-tasks.md ledger row L44b.

### Test coverage delta

- Pre-FU-NEW-2 baseline: 224 tests / 15 errors / 4 failures (pre-existing SnapshotManager/Railway/Rule infra unrelated to this work).
- Post-FU-NEW-2: 261 tests / 15 errors / 0 failures. **+37 tests, all FlyingPress-class-related failures resolved.** Zero regressions; baseline errors unchanged.

---

## [1.2.8] — 2026-05-08

### Changed — Per-reason action clause in upstream-denied banner

`AIAS_Broken_Banner::reason_copy()` previously rendered a single hardcoded action clause for every blocked reason: *"Your bot protection denied the scanner. ... temporarily disable bot protection during scans."* This was misleading for `tier1_http_rate_limit` (HTTP 429 — a rate limit, not a bot challenge) and `tier1_http_4xx`/`tier1_http_5xx`/`tier1_transport_error` (server-side failures, not bot blocks). Operators reading the banner were nudged to disable bot protection when the actual remediation was different.

Added `reason_category()` + `action_clause()` private static methods that map each blocked reason to one of three remediation categories:

- **`rate`** (`tier1_http_rate_limit`) → "Your server rate-limited the scanner. ... Wait a few minutes between scans, or temporarily raise rate limits during scans."
- **`error`** (`tier1_http_4xx`, `tier1_http_5xx`, `tier1_transport_error`) → "Your server returned an error or didn't respond. ... Try again later, or check site health."
- **`bot`** (default — `tier1_zero_bytes`, all `tier2_*`, unknown) → existing "Your bot protection denied the scanner. ... temporarily disable bot protection during scans." copy preserved.

When all reasons in a single scan map to the same category, that category's clause renders. When reasons span multiple categories (e.g. some pages 429, others CF challenge), the banner falls back to the generic `bot` clause to avoid misleading single-cause guidance.

3 new tests in `tests/BannerRenderingTest.php` (rate-limit alone, server-error alone, mixed-reasons fallback). All 7 banner tests + 12 assertions green; full suite baseline unchanged (15 pre-existing `SnapshotManagerTest` errors are unrelated and predate this change).

No JS/CSS changes; no cache-bust required. Plugin Version bump `1.2.7 → 1.2.8` only.

---

## [1.2.7] — 2026-05-07

### Fix — Local scan history reflects admin-kill terminal state (FU-7)

When a SaaS administrator kills an in-flight scan from the SaaS Jobs > Running tab, the SaaS-side row is finalized as `admin_kill` (FU-2 / FU-6 ship 2026-05-06) and the plugin's polling loop already stops animating + shows a banner. But the plugin's *local* `ScanHistory` (wp_options) record was never updated — so the AAS Scan History tab kept showing the killed scan as `in_progress` / `queued` indefinitely, even after page reload.

### Added

- **New AJAX action `cu_scanner_handle_killed`** in `admin/class-scanner-ajax.php`. Mirrors the existing `cancel_job` handler minus the Railway `/cancel` call (Railway already knows about the kill — that's how the plugin learned `status === 'killed'` in the first place). Updates the local `ScanHistory` record to `status='cancelled'` with `credits_used=0` (admin_kill is non-charging), clears the bypass-tokens transient, deletes the active-job transient. Cap + nonce verified via `$this->check()` (Rules 4 + 5 + 11).

### Changed

- **`scanner.js` `handleStatusUpdate` killed branch** now fires `post('cu_scanner_handle_killed')` before showing the banner. Fire-and-forget: UI state is the user-visible signal regardless of whether the AJAX completes. Banner copy clarified to `"Your scan was cancelled by an administrator"` so the user understands it wasn't their click.
- Cache-bust: `SCANNER_JS_VERSION` `1.0.10.7 → 1.0.10.8` + plugin Version `1.2.6 → 1.2.7`.

### Out of scope (explicit decision)

- **Worker mid-page abort (FU-8 — DROPPED).** SaaS Kill currently does NOT stop in-flight Playwright work mid-page on Railway; the worker keeps running until the natural 180s page-timeout. Per operator decision 2026-05-07: this is acceptable since admin-kill is rare and the worker dies within 180s + does not advance to next pages. AbortController plumbing through `processSinglePage` → page-analyzer → verifier deferred indefinitely.

### Compliance — wp-compliance pre-code checklist clean

- Cap + nonce paired via existing `$this->check()` helper (Rules 4 + 5 + 11).
- ABSPATH guard inherited via `class-scanner-ajax.php` namespace (Rule 21).
- No new SQL — uses existing `ScanHistory::update_status()` + `BypassManager::delete_all_tokens()` (Rule 6 vacuously clean).
- No user input flows into the new handler (state read from a server-side transient keyed by user_id from `get_current_user_id()`).

### Cross-repo dependencies

- Requires SaaS plugin `1.2.10+` (FU-2 + FU-6 deployed) so SaaS-Kill click actually reaches Railway via the `/jobs/admin-kill-by-token-hash` push and Railway flips `meta.status` to `'killed'` on the next plugin poll. Without that the plugin will never see `status === 'killed'` and this handler won't fire.
- Requires Railway `0904fae+` (FU-6 endpoint live).

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
