# FU-AAS-CACHE-STACK-NOTICE-MISSING — todo

**Date:** 2026-06-10 · **Plugin:** AI Assets Scanner · **Branch target:** 2slowDD/AI-Assets-Scanner `main`

> (Prior content of this file — the v1.2.0f Scan-History export/delete plan — was a completed task,
> shipped long ago. Replaced with the active task per the P7 tasks/todo.md workflow.)

## Root cause (🟢 CONFIRMED)
The "which cache stack" message is rendered only by `showProbeOutcomeDialog()` (`admin/js/scanner.js:295`),
gated at `scanner.js:990` by `if (probeResult.data.warning_needed)`. Server sets `warning_needed=true` only when
some host's outcome ≠ `class_a_clean` (`admin/class-scanner-ajax.php:1223-1227`). A cleanly-detected stack
(WP Rocket / FlyingPress / LiteSpeed → `class_a_clean` → suffix applied) makes `warning_needed=false`, so the
dialog is deliberately skipped ("Silent proceed on uniform class_a_clean"). Detection improving (probe Accept-header
+ honest 4xx classification + redirect resolution, shipped after 2026-06-01) moved the operator's test site OUT of
the warning branch into the silent branch → notice "vanished". The render code never regressed.

Operator confirmed (2026-06-10): the notice used to appear as the blocking dialog; fix = passive inline notice.

## Plan
- [ ] Add static empty container `#cu-target-stack-notice` to step-3 (`admin/views/scanner-page.php`).
- [ ] Add `renderTargetStackNotice(probeData)` in `scanner.js` — reuse `buildUniformMessage`/`buildPerHostList`
      (already `esc()`-escaped), render as `notice notice-info inline` with a "Target site detection" header.
- [ ] Wire it into the `else` of the `warning_needed` gate (the silent `class_a_clean` path) at `scanner.js:990`.
- [ ] Clear `#cu-target-stack-notice` at scan-start so prior-scan content never lingers into a warning-path scan.
- [ ] Cache-bust: bump `Version:` + `CU_SCANNER_VERSION` 1.7.28b → 1.7.29b (`ai-assets-scanner.php`).
- [ ] Verify: `node --check scanner.js`; run PHP test suite (no PHP behavior change — confirm green baseline).
- [ ] Doc-debt: CHANGELOG + README entry (P9 pre-push).
- [ ] HOLD before push (P9 YES gate + verify remote branch).

## WP compliance (P10 — applied)
- Rule 3 (escape output): notice content via `outcomeMessage()`→`listDetected()`→`esc()` (HTML-escapes & < > " ').
  No new unescaped output. Static div is literal HTML.
- Rule 21 (ABSPATH): no new PHP files; template guard present.

## Constraints
- DO NOT touch detection/fingerprint/suffix logic (provably works).
- AAS beta versioning: 'b' suffix + patch bump. Operator deploys via SFTP (never assume deployed).
- AC = operator confirms the notice surfaces again on an external-URL pre-scan after SFTP deploy.

## Follow-ups discovered during this task
- Verified NOT same root as FU-AAS-NOTICE-PLACEMENT: that one was WP hoisting non-`.inline` dynamic
  notices (fixed in 1.7.28b via the `inline` class). This one is the `warning_needed` gate suppressing
  the dialog for clean detections — a different mechanism. No bundling.

## Review
**Status:** implemented + verified locally; HELD before push (awaiting operator SFTP-deploy + AC validation).

Changes (all landed, verified via `git diff`):
- `admin/views/scanner-page.php:137` — static `#cu-target-stack-notice` container in step-3.
- `admin/js/scanner.js:398` — `renderTargetStackNotice()` (reuses esc()-escaped builders).
- `admin/js/scanner.js:1019` — wired into the `else` of the `warning_needed` gate (silent class_a_clean path).
- `admin/js/scanner.js:929` — clear at scan-start (no stale notice across scans).
- `ai-assets-scanner.php:5,26` — Version + CU_SCANNER_VERSION 1.7.28b → 1.7.29b (cache-bust; scanner.js
  enqueued with CU_SCANNER_VERSION at class-admin-pages.php:36).
- `CHANGELOG.md` + `README.md` badge — doc-debt closed.

Verification:
- `node --check admin/js/scanner.js` → OK.
- Render output test (3 cases): clean WP Rocket → "Detected WP Rocket on getkush.cc." with `inline` class ✓;
  XSS in host/name escaped ✓ (Rule 3); null clears ✓.
- PHP suite: 540 tests / 1259 assertions / 0 failures (2 pre-existing risky MenuBadge tests unrelated).

AC: operator confirms the notice surfaces on an external-URL pre-scan after SFTP deploy of the 3 runtime files.
