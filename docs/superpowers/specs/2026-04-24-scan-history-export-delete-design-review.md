# D-review: Scan History — Export & Delete All — Design (Round 2)
**Reviewed:** 2026-04-24 · **Spec:** `docs/superpowers/specs/2026-04-24-scan-history-export-delete-design.md` · **Verdict:** ready-to-plan

## 1. Context Scanned
- Files read (Round 2):
  - Revised spec — diffed against Round 1 findings.
- Files read (Round 1, still valid):
  - `includes/class-scan-history.php`, `admin/views/history-page.php`, sibling specs, `tests/` listing.
- Unverifiable assumptions this spec makes — now explicitly consolidated in §Risks → "Assumptions to verify at plan time" block (constructor action-array pattern, per-page enqueue site, `wp_tempnam`+`OVERWRITE` interaction, UTF-8-without-BOM source encoding). Good — they're named and scoped to the planning phase, not the spec.

## 2. Round 1 Follow-up — Every Major/Minor/Nit

### Majors (3) — all resolved
- **M1 — `.prop('disabled', …)` no-op:** Resolved. §UX now pins both actions to `<button type="button">` with a short rationale block (lines 40–53) and the full markup. The disable calls will actually disable.
- **M2 — Unchecked `ZipArchive::open()` return value:** Resolved. §Server design step 5 now requires `=== true` check on both `open()` and `close()`, with explicit fall-through to the CSV path, `@unlink` of the temp file, and `error_log` on non-true returns. Zero-byte ZIP failure mode eliminated.
- **M3 — CSS contradiction ("flex row" vs "no custom CSS"):** Resolved. Inline `style="display:flex;justify-content:flex-end;gap:8px;margin:0 0 12px;"` on the wrapper; Goal §3 updated to allow exactly that.

### Minors (8) — all resolved
- CSV defuse list extended to `\t` and `\r` in both §CSV escaping (line 119) and §Security (line 220). ✓
- `exit;` replaces `wp_die()` after binary `readfile` (line 108) with inline rationale. ✓
- `wp_enqueue_script` dependency array shown explicitly as `['jquery']` (lines 193–200). ✓
- Button labels wrapped via `esc_html_e()` in the markup (lines 47, 50). ✓
- Success admin notice via 30-second single-consume transient (§One-time admin notice on deletion + AC 7 + test assertion). ✓
- Fallback-path test coverage: class-absent, `open()`-failure, temp-file unlink on both success and failure branches (lines 240–243). ✓
- AC 3 restated mechanically — BOM bytes, RFC 4180 via `fputcsv`, defuse-prefix presence. ✓
- Missing-snapshot behavior stated: README `Missing snapshots:` line (line 103) + AC 8 + test (line 242). ✓

### Nits (3) — all resolved
- `@unlink` failure now logs at debug level instead of silently swallowing (line 107). ✓
- UTF-8-without-BOM note for `⚠` round-trip added to the plan-time assumptions block (line 291). ✓
- Unverifiable assumptions relocated into explicit §Risks sub-block (lines 287–291). ✓

### Deliberately not addressed — justification accepted
- **Nonce in GET URL (export).** §Security line 221 now documents the accepted risk with rationale: read-only + idempotent, `manage_options`-gated, 24h nonce TTL, and auto-submit POST form adds DOM complexity exceeding the risk reduction. That's a reasoned acceptance — fine per the rubric.

## 3. Residual findings in the revised spec

### Critical (blocks implementation)
- None.

### Major (should fix before planning)
- None.

### Minor (clarify when convenient)
- **[Inconsistencies] §Security line 223 vs §Server design lines 99/104/107.** Security still describes temp cleanup as `"try { … } finally { @unlink($tmp); }"`. The revised §Server design instead does inline `@unlink` at each branch point (success, open-fail, close-fail). Both can coexist (outer `try/finally` as belt-and-braces around the branch-level unlinks), but two readers could interpret "which is the authoritative pattern" differently. *Why it matters:* trivial, but the implementation plan will have to pick one and the reviewer will re-ask. **Fix:** one sentence saying "branch-level `@unlink` is authoritative; an outer `try/finally` is optional insurance."

### Nits (style / optional)
- **[Testability] §Testing line 237.** The happy-path ZIP file-list assertion `[history.json, history.csv, scans/<id1>.json, scans/<id2>.json, README.txt]` is ordered. `ZipArchive` entry order is deterministic given the spec's add order, but an equivalent implementation that happens to add README before scans/ is behaviorally identical. Consider set-equality phrasing to avoid a test-brittleness trap.
- **[Gaps] README.txt exact format.** Line 103 describes the content ("plugin version, ISO 8601 timestamp, total record count, optional Missing snapshots: line") but doesn't pin the exact string template. AC 8 only asserts the `Missing snapshots:` line. Slack is acceptable — flagging only so the implementer knows precise wording is free to choose.
- **[Gaps] Admin-notice hook gating to history page.** Line 138 says "hook `admin_notices` on the history page hook only" — implementer needs to decide between `get_current_screen()->id` check, `$_GET['page']` check, or registering the hook only inside the page-suffix-guarded enqueue block. Any of those is correct; spec leaves the choice to the plan.

## 4. Findings by Dimension (residual only)

### Gaps
- [Nit] README.txt exact format not pinned.
- [Nit] Admin-notice hook gating mechanism not prescribed.

### Inconsistencies
- [Minor] §Security `try/finally` pattern vs §Server design inline `@unlink` branches.

### Ambiguity
- None.

### Errors
- None.

### Improvements / Simplifications
- None.

### Testability
- [Nit] Happy-path ZIP file-list assertion is ordered where set-equality would suffice.

### Risks / Unknowns
- None residual. The plan-time assumptions block (lines 287–291) properly scopes what's deferred to planning.

### Missing Acceptance Criteria
- None.

## 5. Verdict
**ready-to-plan**

All three Round 1 Majors closed with concrete, spec-level fixes. All eight Minors and all three Nits closed. One residual Minor (Security section's `try/finally` phrasing vs §Server design's inline-branch unlink) and three Nits remain, none of which block handing the spec to `writing-plans`. The spec is now implementable without guesswork: button element type, ZipArchive failure path, CSS approach, temp-file cleanup, test coverage for all fallback branches, and a consumable success signal for users are all pinned. Safe to proceed.
