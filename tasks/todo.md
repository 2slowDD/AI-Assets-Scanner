# 1.8.1b — Step 2/3/4 admin UI refinements (2026-08-20)

Base: `d5fe638` (1.8.0b), branch `codex/admin-ui-1.8.0b`.
Scope: **presentational only** — no scan-pipeline, rule-generation, results, or credit-accounting changes.
F-CHECK-EFF: **N/A** — UI/admin-render work; the F-* yardstick is scan-pipeline-scoped.

Status: **COMPLETE** — JS 20/20, PHP 950 tests 0 failures, both mutation-verified.

---

## Phase-1 findings (assumptions tested before locking the approach)

- 🟢 **CONFIRMED** — WP current version is **7.1** (`api.wordpress.org/core/version-check/1.7/`
  returns `7.1` as the `upgrade` offer). `Tested up to: 7.0.4` was stale.
- 🟢 **CONFIRMED** — the Step-2 orbit **is** animated and works. Rendered the real markup +
  real CSS in Chromium: `animationName: cuOrbit`, `playState: running`, non-identity
  `transform` matrix, 1 live animation.
- 🟢 **CONFIRMED** — the orbit **froze into a dead ring under `prefers-reduced-motion:
  reduce`**. The old rule set `animation: none` on `.cu-state-orbit`. Emulated it:
  `animationName: none`, `transform: none`, **0 live animations**, identical transform
  across a 600 ms sample.
- 🟢 **CONFIRMED** — the plugin ships **no** `.spinner` CSS; the low-right circle was WP core's
  `.spinner` (a background **GIF**, `float: right`). CSS cannot stop a GIF, so under reduced
  motion the GIF kept spinning while the orbit was frozen — exactly the reported signature.
- ⚠️ **Assumption (unresolved, non-blocking)** — that the operator's machine has reduced motion
  enabled. Not verifiable from here. The shipped fix is correct in both worlds.
- 🟢 **CONFIRMED** — bypass suffixes are always **appended last** in the query string
  (`class-scanner-ajax.php:370-415`), so "first bypass param → end of string" is exactly the
  appended suffix.
- 🟢 **CONFIRMED** — full bypass param-key set (`PluginDetector::OPTIMIZERS` +
  `auto_bypass['code-unloader']`): `nowprocket, perfmattersoff, ao_noptimize, nonitro,
  wpacu_no_load, LSCWP_CTRL, swis_disable, no_optimize, nowpcu`. A bare `?` test would be a
  **false positive** — pinned as a regression test.
- 🟢 **CONFIRMED** — all touched files are **CRLF** and stayed CRLF (wp-compliance R28).

## Tasks

- [x] **1. Version + WP-compat bump.** `1.8.1b` across the three lockstep sites
      (`ai-assets-scanner.php` header `Version`, `CU_SCANNER_VERSION`, `README.md` badge —
      the set `package.json` independently documents). Plus `CU_SCANNER_ASSET_VERSION` →
      `1.8.1b.1`, `SCANNER_JS_VERSION` → `1.0.11.6`, `Tested up to: 7.1`, CHANGELOG entry.

- [x] **2. Step 4 — copy control on the Scan ID.** Text moved into its own child span so the
      button survives the write; inline SVG (matches this file's convention). Clipboard API
      with an `execCommand` fallback for non-secure origins, transient confirm state,
      `aria-label` + SR `role="status"` announcement. Button hidden when scanId is empty.

- [x] **3. Step 3 — truthful optimizer-bypass status.** Driven from the real scan URLs, keyed
      on the confirmed param set. Latches to *Applied* on first sighting; holds a neutral
      *Checking…* until every page has a worker-echoed URL; only then reports
      *Not applied (N/A)* with a neutral icon and muted styling.

- [x] **4. Step 3 — bypass suffix rendered lighter than the URL.** Split at the first bypass
      param, tail wrapped in `.cu-live-bypass-suffix`. Both halves escaped through the
      existing `esc()`. Measured: suffix `rgb(101,115,134)` vs URL `rgb(23,34,56)` — lighter,
      and still 4.83:1 on white (above WCAG AA 4.5:1).

- [x] **5. Step 2 — orbit + spinner.** WP `.spinner` removed. The reduced-motion rule no longer
      freezes the orbit: it evens out the border and swaps rotation for an opacity pulse, so
      reduced-motion users keep an activity cue now that the spinner is gone.

- [x] **6. Verification.** See Review.

## Follow-ups discovered during this task

- `admin/css/ai-assets-scanner-admin.css` now carries **three** generations of rules
  (pre-1.8.0b from ~line 1019, v1.8.0b from ~line 1600, v1.8.1b appended at the end), with
  `.cu-state-orbit` still defined only in the oldest block. Worth a dedup pass.
- The reduced-motion block still covers only `.cu-pip.is-active` and `.cu-state-orbit`, while
  `.cu-radar-sweep` / `cu-radar-flare` / `cu-live-pulse` keep animating. Inconsistent
  accessibility posture — candidate for a follow-up sweep.
- `CU_SCANNER_ASSET_VERSION` and `SCANNER_JS_VERSION` are hand-maintained; the fingerprint
  guards catch drift only *after* the fact. Candidate for deriving them from a build step.
- **`tests/` is in `.gitignore`.** Existing test files are tracked (committed before that
  rule), but the new `tests/js/step3-bypass-status.test.js` is ignored and needs an explicit
  `git add -f` to be committed. Operator decision — not forced here.
- `CU_BYPASS_PARAM_KEYS` in `scanner.js` duplicates the `bypass_query` values in
  `class-plugin-detector.php`. No guard keeps them in lockstep; a PHP test asserting the JS
  array matches `OPTIMIZERS` would close that drift surface.

## Review

**What shipped.** Five changes across `ai-assets-scanner.php`, `README.md`, `CHANGELOG.md`,
`admin/views/scanner-page.php`, `admin/js/scanner.js`, `admin/css/ai-assets-scanner-admin.css`,
plus test updates in `tests/JsCacheBustDriftTest.php`, `tests/VersionLockstepTest.php`,
`tests/js/r3-stage-c-harness.js` and a new `tests/js/step3-bypass-status.test.js`.

**Verification performed.**
- `php -l` clean on both touched PHP files; `node --check` clean on `scanner.js`.
- Rendered the **real** markup + **real** CSS + **real** JS source slices in Chromium.
  Bypass-detection matrix 8/8, including the two negatives that matter (`?utm_source=…`
  not matched; wrong-case and near-miss keys not matched).
- Clipboard round-trip through the shipped handler: `navigator.clipboard.readText()`
  returned the scan ID.
- Reduced-motion before/after: `animation: none` + 0 live animations → `cuOrbitPulse` +
  1 live animation with opacity measurably changing (1 → 0.72).
- XSS probe through `rowHtml`: hostile URL escaped on **both** halves of the split.
- **Mutation-tested** both new guards: making any query string count as a bypass, and
  removing the latch, each turned the suite red on the intended assertion; restoring
  returned it green. The guards are not decorative.
- Full suites: **JS 20/20 pass**, **PHP 950 tests / 2342 assertions / 0 failures**.
  The 5 skipped + 2 risky (`MenuBadgeTest`) are pre-existing and untouched by this work.
- CRLF verified byte-level on all ten changed files.

**One process note worth keeping.** The first version bump was applied with `sed -i`, which
silently converted both files from CRLF to LF — a wp-compliance R28 violation that git's
autocrlf hid from `git diff`, and that a `grep -c $'\r'` check falsely reported as clean
(empty-pattern match). Caught by a raw `od -c` / `tr -cd` byte count. Files were restored and
re-edited with a byte-preserving tool. **Do not use `sed -i` on this repo's CRLF files.**

**Guards that earned their keep.** Three PHP tests failed on the version bump and each named
a required step that had not been done yet: the admin-asset fingerprint row, the
`SCANNER_JS_VERSION` banner bump plus its row, and the version-lockstep pin. The release
ritual is genuinely enforced rather than documented.
