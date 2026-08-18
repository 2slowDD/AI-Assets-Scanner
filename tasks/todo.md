# 1.7.99b release train — 3-FU AAS release train (2026-08-18)

Base: `b7fb7d6` (1.7.98b). Baselines green: JS 18/18, PHP 923/2241 0F.
Order ratified by operator + Phase-1 assumption audit (all load-bearing claims 🟢, one 🟡 bounded).

## Tasks

- [x] **1. FU-NOOPT-NOTE-CONFLATION** — DONE `4414561`. (📧 promised) — make the ET-candidate note
  unmistakable vs the four plain noopt notes. ET note → amber action badge (background +
  left border + ⏳ prefix, not-italic); plain notes → muted gray italic. TDD: update the
  verbatim pins in `tests/js/kept-protection-chip.test.js` FIRST (red), then
  `admin/js/scanner.js:2757` + `admin/css/ai-assets-scanner-admin.css:940-941` (green).
  Check every other test pinning noopt markup before editing (grep `cu-noopt`).
- [x] **2. FU-KEPT-BADGE-HOVER-INFO** — DONE `5b6689f`. — per-row tooltip naming that row's kept assets.
  Producer-side: `build_pages()` ships a precomputed `kept_breakdown` ([label, n] rows,
  composite-unit, same dedup as `count_kept_composites()` so Σn == kept_count BY
  CONSTRUCTION — pin that invariant in a PHP test). Client: title attr via the file's
  own escaped-interpolation pattern (verify `cuEscHtml` escapes `"` first) or delegated
  listener + `.title` property if it doesn't. R19: worker strings NEVER concatenated raw.
- [x] **3. FU-WPFC-DETECTION-HIT-ONLY-MARKER** — DONE `f38007f`. — add `/wp-content/cache/wpfc-minified/`
  to the wpfc `target_body_markers` (`class-plugin-detector.php:235`). Read the
  PAGE_CACHE_PLUGINS Pass-1/Pass-2 interplay first; extend detector tests (HIT + MISS
  shape). Premise 🟢 confirmed live (hrefs at byte 1041 < 32768; N=4).
- [x] **4. Release close-out** — DONE (this commit). — version lockstep 1.7.99b THREE places (header +
  CU_SCANNER_VERSION + README badge, `b` suffix), SCANNER_JS_VERSION bump, NEW
  JsCacheBustDriftTest fingerprint row (never rewrite), CHANGELOG, full suites, then
  AAS-update packaging. P9 public-push gate + operator YES before any push.

## WP Compliance notes (28 rules active, applied in controller)
- Worker payload = untrusted (Rule 1): `kept_known_assets`/`kept_breakdown` defensive-
  validated at producer, `?? null` convention (key is a CONDITIONAL SPREAD worker-side —
  absent when empty, 🟢 page-analyzer.js:2794).
- No worker string reaches an HTML sink unescaped (Rules 3/15, ruling R19).
- No SQL, no new input surface, no caps/nonce changes in scope.
- Line endings: uniform CRLF on touched files (Rule 28).

## Constraints carried
- Tests in this fresh worktree only; `git add -f` for tests/ if ignored.
- ⚠️ repo is PUBLIC — content-safety sweep before push; no push without operator YES.
- CF-host notice (FU-CF-HOST-INTEGRATION-NOTICE) deliberately NOT in this train —
  needs operator copy decisions first.

## Follow-ups discovered during this task
- 🟡 WPFC MISS-case generality: the WPFC probe site served cached HIT even query-busted; if a
  true-MISS page lacks wpfc-minified links, detection falls back to today's behavior
  (no regression). Recorded, not blocking.
- Ledger body L44 still says the Superseded history section is "(this file)" — operator
  call pending from the trim.

## Review (2026-08-18)

Train complete, 4 commits on this train branch (base `b7fb7d6`). Suites: PHP 941
tests / 2262 assertions / 0 failures (5 skipped, 2 risky — pre-existing), JS 18/18.
TDD red→green on every FU; kept-breakdown dedup mutation-proven (3 red, reverted).
Version lockstep 1.7.99b (header + const + badge), SCANNER_JS_VERSION 1.0.10.32,
both fingerprint rows ADDED (never rewritten), CHANGELOG entry written.
NOT pushed, NOT packaged — P9 gate + AAS-update packaging await operator YES.
