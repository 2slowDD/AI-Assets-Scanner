# 1.8.7 (proposed) — Step-4 Sync / Push busy indicator + ET-column tooltip credit wording (2026-09-11)

Base: `e1eb03f` (1.8.6) = `origin/main`, in the `codex` worktree on `feat/sync-push-busy-indicator`.
Brief: the operator's internal handoff doc (2026-09-11). Ledger: off (`-no ledger` handover).
Brainstorm path: **bounded** (existing Sync / Push flow, no spec file). F-*: N/A (admin rendering).

## Operator decisions (2026-09-11)

- Placement: **(D) a dedicated status line under the Step-4 action buttons.** Agent-proposed after the
  Phase-1 contrast check refuted the handoff's (A); operator chose D.
- Busy copy (customer-visible, confirmed, no rule count):
  **"Syncing with Code Unloader… This can take a while for large rule sets."** /
  **"Pushing to Code Unloader… This can take a while for large rule sets."**
- Lock **both** Sync and Push while either call is in flight. **Not** in scope: an Undo spinner,
  keyboard-focus handling, the Step-1 ET tooltip (all filed below).
- Shown immediately (no delay); reduced motion → opacity pulse instead of spin (1.8.1b precedent) —
  agent defaults, stated to the operator before the questions.
- ET-column tooltip (operator request): **"Re-run this URL with Extra Time (more probe budget, +1 credit
  only if Extra Time actually runs)."** The `aria-label` twin changes to match.
- Design approved; version **1.8.7** (asset key `1.8.7.1`, banner `1.0.11.11`) — operator, 2026-09-11.

## Phase-1 findings (each check run by this session)

- 🔴 **REFUTED (the handoff's placement A)** — WP core `wp-includes/css/buttons.css` (master, L315–324)
  forces a disabled `.button-primary` to `#8a8a8a` on `#e2e2e2` with `!important`: ≈ 2.7:1 contrast
  (computed). "Syncing…" inside the disabled button would read as today's greyed-out state → D.
- 🟢 **CONFIRMED** — no id / function collisions:
  `grep -rnE "cu-sync-push-busy|SyncPushBusy|cu-busy-" admin includes tests` → empty.
- 🟢 **CONFIRMED** — nothing re-enables Push / Sync mid-call: their `.disabled` is written only in
  `restoreStep4` (scanner.js L3071–3090) and the click handlers; `restoreStep4`'s two callers (L2126
  buildResult, L3934 load-time restore) are not reachable from a Sync / Push click.
  ⚠️ **Corrected by the review (#1):** right about cause and effect, but not about concurrency — a
  re-queued scan (the Step-4 partial banner's Re-queue button) can finish and re-render Step 4 WHILE a
  Sync / Push is still out. Handled by the render epoch below; reproduced red first, then fixed.
- 🟢 **CONFIRMED** — `ScannerPageMarkupTest` pins the button order, `cu-push-result` before
  `cu-result-summary`, and 7 `<th><span class="cu-th-inner">` in scanner.js; a new div and a tooltip text
  edit move none of them.
- 🟢 **CONFIRMED** — the existing suites assert settled states only; `sync-line-all-present.test.js` pins
  the Sync button **disabled after success** (the clicked button is never re-enabled on success — kept).
- 🟢 **CONFIRMED** — harness `removeAttribute()` is a no-op (`r3-stage-c-harness.js` › makeEl); the design
  clears the line with `textContent = ''`, which the harness models (the setter drops children).
- 🟢 **CONFIRMED** — `.cu-recommendations-card` is a grid with `gap: 14px 20px` (css L2092–2098); an empty
  extra child adds a row gap (the reason for `#cu-push-result:empty { display: none }`, L2116). A live
  region must not be `display: none` (that drops it from the accessibility tree) → an out-of-flow,
  visually-hidden `:empty` rule instead.
- 🟢 **CONFIRMED** — the ET wording matches the plugin's billing model: Step 1 reserves
  `selected + etCount` credits (scanner.js L1239–1240) and `et_charged` stays false for "selected-but-refunded
  ET … no ET actually ran" (`class-scan-status.php` L178–180). ⚠️ The refund itself is SaaS-side — not read.
- ⚠️ **Assumption (accepted)** — `Promise.prototype.finally` (ES2018) is safe: no precedent in scanner.js,
  but the file already relies on ES2017 (`Object.entries` in `post()`).
- ⚠️ **Assumption (accepted-as-risk)** — screen readers announce the busy text. Task 6 checks that the
  accessibility tree exposes a `status` holding it; no NVDA / VoiceOver run is possible here.

## Design

- **Markup** (`scanner-page.php`, static): `<div id="cu-sync-push-busy" class="cu-sync-push-busy"
  role="status" aria-live="polite"></div>` between `#cu-step4-action-row` and `#cu-push-result`. Always
  present, empty when idle, so the live region is registered before any text lands.
- **JS** (`scanner.js`, beside the Push / Sync handlers):
  - `lockSyncPush( clickedBtn, message )` — disables BOTH action buttons (snapshotting the other button's
    prior `disabled`) and fills the line with `span.cu-sync-push-busy-spinner[aria-hidden="true"]` + a text
    span (`textContent`, static copy — R19). Returns `release()`.
  - `release()` — empties the line (`textContent = ''`) and restores the OTHER button's prior `disabled`.
    The CLICKED button stays with the handlers: success keeps it disabled, error / catch / Cancel re-enable
    it — all as today.
  - Every Sync / Push request runs as `post(...).finally(release)`: the indicator spans exactly one in-flight
    request, and every exit (success, server error, network reject, a throw in a handler) clears it by
    construction.
  - Push `needs_confirm`: the first request's release runs BEFORE `window.confirm` (nothing is in flight
    while the dialog is up); OK → the confirmed call locks again; Cancel → today's `btn.disabled = false`.
  - **Render epoch (review #1):** `restoreStep4` bumps `step4RenderEpoch` and empties the line; a
    `release()` captured under an older epoch does nothing, so a request that outlives a re-render cannot
    undo the new render's buttons (G6's sync-only Push lock included). Only `restoreStep4` bumps it — two
    locks cannot overlap without a render between them, because both buttons are locked.
  - Success / error / all-present copy: byte-identical.
- **CSS** (new `v1.8.7` block at the END): `.cu-sync-push-busy` flex row with readable muted text and
  `grid-column: -2 / -1` (under the buttons in the two-column card, the only column in the one-column
  layout — no media query); `:empty` → visually hidden and `position: absolute` (out of the grid flow, still
  in the accessibility tree); `.cu-sync-push-busy-spinner` reuses the `.cu-probe-spinner-icon` look
  (`cu-probe-spin`); `prefers-reduced-motion` → the `cuOrbitPulse` opacity pulse with an even border.
- **ET tooltip** (`scanner.js`, results-table header): the visible `.cu-help-box` and the `aria-label` both
  gain "…only if Extra Time actually runs".

## Tasks

- [x] **1. Harness.** Registered `cu-sync-push-busy` in `createHarness`; the 25 existing suites stayed green.
- [x] **2. Tests first (red).** New `tests/js/sync-push-busy.test.js` (`git add -f`), through the REAL click
      handlers with a HELD `fetch` (a promise resolved by hand). Mid-flight: the exact busy copy
      (`strictEqual`), spinner `aria-hidden`, BOTH buttons `disabled === true`. After settling: line
      `textContent === ''`, the other button back to its prior state, the clicked one per today's rule.
      Paths: Sync success / server error / network reject; Push direct success / server error / network
      reject / `needs_confirm` → Cancel / `needs_confirm` → OK → second held request (busy again) →
      success. A prior-disabled other button (syncOnly: Push dormant) stays disabled after a Sync. ET-tooltip
      pin (visible + `aria-label`; old strings absent) on a rendered Step-4 table. A missing status line
      fails OPEN (buttons still lock, the request still goes). *(The planned CSS-text pin is dropped: a
      grep of the stylesheet proves only that the source is the source — reduced motion is verified in
      the real browser, task 6.)* Watched **11 / 11 red** before any code, each on the missing feature
      (`sync in flight: the status line carries the busy copy` — `''` vs the copy).
- [x] **3. Markup + PHP pin.** The static status div; `ScannerPageMarkupTest` pins the exact always-present
      markup (no `hidden`), that scanner.js looks the id up, and its position between `cu-step4-action-row`
      and `cu-push-result`. Watched red first.
- [x] **4. JS.** `lockSyncPush` / `release`, both handlers through `.finally(release)` (the handlers' own
      `btn.disabled = true` moved into the lock), the ET strings. **19 / 19 mutants killed** in a scratchpad
      mirror, each by its intended test (15 JS: each finally dropped, other-button lock / restore /
      prior-state, line not cleared, release moved after the handler, confirmed call not relocking,
      spinner not aria-hidden, both missing-line guards, clicked button not locked, copy swapped, both ET
      strings reverted; 4 view/JS lockstep: id renamed on either side, `hidden` added, order swapped).
      Baseline green first; mirror restored byte-for-byte; working tree sha256-unchanged.
- [x] **5. CSS.** The `v1.8.7` block at the END of the stylesheet (4 rules; the sheet loads 666 = 662 + 4).
- [x] **6. Real-browser check** — real view + WP core `common` / `buttons` / `list-tables` CSS + plugin CSS +
      real `scanner.js`, cache-busted, `window.fetch` held by hand. Idle: the card is 119 px with and without
      the empty line (no gap); the line is `position: absolute`, 1×1, `role="status"`. Sync in flight: the
      exact copy, `#33465c` 12 px, exactly the button column (x 360.8→1002), `cu-probe-spin` spinner, both
      buttons disabled, `#cu-push-result` untouched; the accessibility snapshot shows `status: "Syncing with
      Code Unloader… …"` with the spinner pruned. Reduced motion → `cuOrbitPulse` 1.6 s, even `#2271b1`
      border. Settled: line empty, Push released, Sync disabled, notice verbatim, Undo enabled. Push via
      the REAL confirm dialog: in flight → busy; Cancel → line empty, both enabled, one request; OK → the
      confirmed request busy again → success. 600 px (one-column card): the line fills the only column, no
      horizontal overflow; server error clears it. The ET tooltip popover reads the new wording.
      Screenshots in the session scratchpad (not in the repo).
- [x] **7. Version bump — operator chose 1.8.7.** Header, `CU_SCANNER_VERSION`, README badge and the
      `VersionLockstepTest` pin → `1.8.7`; `CU_SCANNER_ASSET_VERSION` `1.8.7.1`; `SCANNER_JS_VERSION`
      `1.0.11.11`; fingerprint rows ADDED (`1.8.7.1` → `f8f15a40…`, `1.0.11.11` → `37bc73a1…`, recomputed
      after the review fix) by a script that first reproduced the shipped 1.8.6 rows on the primary
      checkout; CHANGELOG `## 1.8.7`. The CHANGELOG is public, so it states the tooltip wording only — not
      the refund mechanism, which is SaaS-side and was not read.
- [x] **8. Verify + commit.** `php -l` ×5, `node --check`, JS 26 / 26, PHP 1019 tests / 2717 assertions /
      0 failures (5 skipped, 2 risky `MenuBadgeTest` — pre-existing), CRLF byte-check on all 12 touched
      files, no shipped fingerprint row touched; committed locally. **HOLD** — nothing pushed (P9; the
      repo is public).
- [x] **9. Release (operator go, 2026-09-11).** README 1.8.7 bullet + the Extra Time line (P9 doc-debt);
      `releases/1.8.7/` built by `build-release.py` (ZIP sha256 in its `checksum.txt`; `tested_wp` 7.1,
      re-derived from api.wordpress.org 2026-09-11); pushed to public `main` in the same push as this
      line (code + docs + artifacts). Hostinger upload + Cloudflare purge: operator.

## Independent review (Opus reviewer, 2026-09-11) — adjudication

- #1 a late `release()` undoes a Step-4 re-render (re-queued scan finishing mid-Sync → G6 sync-only Push
  lock re-enabled; a second Sync's copy doubled and then wiped) → **fixed**: the render epoch (design
  above). Reproduced by two new tests (red first); proven in Chromium (Push stays disabled + dormant after
  the stale answer, no page errors); E1–E3 mutants killed. The reviewer's version bumped on every lock too
  — dropped as redundant (both buttons are locked, so locks never overlap without a render).
- #2 the fail-open test did not check the request's outcome (a throwing `release()` would turn a
  successful Sync into "Sync failed") → **fixed**: it asserts Sync stays disabled and the success notice
  renders; the reviewer's surviving mutant (E4) is now killed.
- #3 nothing tested the CSS half of the live-region rule → **fixed**: a negative `ScannerPageMarkupTest`
  scan — no rule targeting `.cu-sync-push-busy*` may `display: none` / `visibility: hidden` (C1–C2
  mutants killed). Kept narrow on purpose: it catches the one tempting mistake (copying
  `#cu-push-result:empty { display: none; }`), not the style.
- #4 hygiene → the new test is `git add -f`'d; adds stay scoped (`artifacts/` is untracked and not
  ignored); task 7 ticked; README feature bullet + README L40 "+1 credit" → P9 Step-2 doc-debt at push time.
- Mutation total after the review: **25 / 25 killed** (fresh mirror; baseline green; working tree
  sha256-unchanged).

## Follow-ups discovered during this task

- **Undo** keeps its text-only "Undoing the last Push/Sync..." notice and is not locked while a Sync / Push
  runs (operator scoped it out, 2026-09-11). The same line + lock would be cheap later.
- **Stale handlers after a Step-4 re-render (pre-existing, found in review):** a Sync / Push that answers
  after a re-queued scan re-rendered Step 4 still runs its handler against the new card — its outcome
  notice replaces the new `#cu-push-result` (browser-seen: "Synced…" replaced G6's re-scan notice), and a
  stale Push **error** re-enables Push on a G6 result. 1.8.6 had the same handlers; 1.8.7 fixed only the
  busy line and the other-button restore. The fix (skip a stale handler's UI writes, keyed on the same
  epoch) drops a customer-visible notice — the operator's call. Rare: a re-queued scan must finish while
  the request is still out.
- `artifacts/` (1.8.0b Playwright screenshots) is untracked and NOT ignored in `codex` — a `git add -A`
  would publish it to the public repo. Adds stay scoped; ignoring or removing it is the operator's call.
- **Keyboard focus** drops to `<body>` when the clicked Sync / Push button disables under it — the same class
  as the 1.8.6 pager follow-up below (operator scoped it out).
- **Step-1 ET tooltip** (per-URL checkbox, scanner.js ~L1012) still says Extra Time "costs an additional
  credit" with no condition (operator scoped it out of this release).
- **README L40** says Extra Time is "for +1 credit" with no qualifier — revisit at P9 doc-debt time.
- The Sync / Push **outcome notice** in `#cu-push-result` is not announced to screen readers (the card has no
  live region; only the new busy line is one).
- `.cu-probe-spinner-icon` (the Step-1 target-stack probe spinner) has **no `prefers-reduced-motion`** variant.
- ⚠️ Assumption, unmeasured — a Sync / Push that outlives a proxy timeout returns non-JSON, lands in `.catch`
  and reads "Sync failed — check server error logs." while the server may still finish the write. Belongs
  with the server-speed follow-up (handoff §5.2 item 6), not this task.

## Review

**Built.** While a Step-4 Sync or Push is in flight, a line under the buttons shows a spinner and
"Syncing with Code Unloader… This can take a while for large rule sets." (or "Pushing to…"); both action
buttons lock for the duration; every exit clears it; screen readers get it through an always-present
`role="status"` region; reduced motion pulses instead of spinning. The ET-column tooltip now says
"+1 credit only if Extra Time actually runs". Versioned 1.8.7 and committed locally; not pushed.

**Design choices that earned their keep.**
- A separate status line, not the button label: WP core forces disabled buttons to ≈ 2.7:1 grey — the
  exact washed-out look the operator was complaining about.
- `post(...).finally(release)` — one release per request by construction; the browser proved the
  confirm-dialog paths (Cancel and OK) with a real dialog.
- An always-present live region kept out of the grid flow by `:empty { position: absolute }` rather than
  `display: none` — the card measured 119 px with and without it; the accessibility tree keeps the
  `status`.

**Traps caught.** The handoff's recommended placement failed a contrast check; the Phase-1 "nothing
re-renders mid-call" claim missed a re-queued scan finishing mid-Sync (review #1, fixed); the fail-open
test could not see a throwing `release()` (review #2); nothing guarded the CSS half of the live region
(review #3); the Write tool saved the new test as LF among CRLF siblings (normalized, byte-checked); and a
first CHANGELOG draft claimed a SaaS refund mechanism no one in this session had read (cut to the tooltip
wording).

---

# 1.8.6 (proposed) — Step-3 "Live URL status" pagination, 15 URLs per page (2026-09-10)

Base: `9ad43ff` (1.8.5) = `origin/main`, in the `codex` worktree on `feat/live-table-pagination`.
Brief: the operator's internal handoff doc. Ledger: off
(`-no ledger` handover). Brainstorm path: **bounded** (existing flow, no spec file).

## Operator decisions (2026-09-10)

- Page size **15** — closed (handoff §5.1).
- Which page shows during a live scan: **manual only**. Opens on page 1 and moves only when the user
  clicks Prev / Next. No auto-follow.
- Pager copy: **"Page N of M"** between `« Prev` and `Next »` — word-for-word the Step-4 pager.
- "Errors on other pages" hint: **declined**. Not built, not filed.
- Render strategy: **Option A** (hide rows outside the page) — agent recommendation, approved with this plan.

## Phase-1 findings (each check run by this session)

- 🟢 **CONFIRMED** — every poll carries the FULL `pages[]` for all `total` indices; missing entries are
  filled `{status:'pending'}`, and the worker's status endpoint passes `pages` through unmapped (worker code-read).
- 🟢 **CONFIRMED** — a normal scan starts pages in index order, `page_concurrency` at a time
  (worker code-read: a concurrency-limited map over the pages, in order); a resume runs retry ∪ remaining first.
- 🟢 **CONFIRMED** — `skipped` is written only on kill (worker code-read), and `killed` is terminal
  in `handleStatusUpdate` (`stopPolling()` before the row loop). The live table shows it as `…`, but
  never mid-scan.
- 🟢 **CONFIRMED** — rows are created in index order on the first in-progress poll and updated in place by
  `cu-row-<idx>` afterwards, so `tbody.children[i]` IS row `i` in the real DOM (`scanner.js`
  `handleStatusUpdate` row loop; the queued branch returns before it with `pages: []`).
- 🟢 **CONFIRMED** — `updateBypassStatus(pages)` and the global-`idx` URL fallback live in that same loop;
  Option A leaves both byte-identical.
- 🟢 **CONFIRMED** — the Step-4 pager rebuilds its buttons via `innerHTML` on every render. Copied into a
  2 s poll it would destroy keyboard focus on "Next" every poll → the Step-3 pager is STATIC markup.
- 🟢 **CONFIRMED** — `.cu-url-pager { display: flex }` (css L919) beats the UA `[hidden]` rule, so the pager
  needs its own `[hidden] { display: none }` — the stylesheet's house pattern (5 such rules, L678–2312).
- 🟢 **CONFIRMED** — harness: rows made by `createElement` are not in its `els` map, so
  `getElementById('cu-row-N')` returns `null` under test and each poll APPENDS rows instead of updating
  them. Multi-poll pager tests need the harness to model the real in-place update.
- 🟢 **CONFIRMED** — `ScannerPageMarkupTest` pins only `id="cu-pages-tbody"` for Step 3 (L73); no other test
  reads the live-table markup (plain `grep -rln` over `tests/` — ripgrep skips it, it is gitignored).
- ⚠️ **Assumption (accepted, cosmetic)** — WP `.striped` keys off `:nth-child`, which counts hidden rows;
  with 15 (odd) per page, even pages start on the other stripe shade. Checked in a real browser at
  task 6; fixed only if it looks wrong.

## Design

- `var LIVE_TABLE_PER_PAGE = 15;` and `var liveTablePage = 0;` (IIFE scope, beside `bypassStatusLatched`).
- `applyLiveTablePage()` — walks `#cu-pages-tbody` rows (the DOM is the source of truth), sets
  `tr.hidden` on every row outside the current page, then updates the pager: hidden when
  `pageCount <= 1`; label `'Page ' + (page + 1) + ' of ' + pageCount` via `textContent` (numbers only,
  R19); Prev / Next `disabled` at the edges.
- Called once right after the row loop in `handleStatusUpdate` (before `updateBypassStatus(pages)`,
  which keeps receiving the FULL array), and from the two click handlers.
- `beginScanPolling()` resets `liveTablePage = 0`. Nothing else resets it — never `handleStatusUpdate`
  (it runs every 2 s); the resume paths start fresh from the page reload.
- Markup — static, in `scanner-page.php` right after `table#cu-pages-table`:
  `div#cu-live-pager.cu-url-pager.cu-live-pager[hidden]` › `button#cu-live-prev` (« Prev),
  `span#cu-live-page-label`, `button#cu-live-next` (Next »). New ids — NOT the Step-4 `cu-url-prev` /
  `cu-url-next`, which are in the page at the same time.
- Handlers bound ONCE at load with `addEventListener`, each guarded (`if (el)`), and bounds-checked
  like Step 4's.
- CSS: `#cu-scanner-app .cu-live-pager[hidden] { display: none; }` in a new block at the END of the
  stylesheet. Reuse `.cu-url-pager` for the look.

## Tasks

- [x] **1. Harness.** Registered the four pager ids in `createHarness`. `cu-pages-tbody` now models the real
      DOM: an appended row becomes findable by its `cu-row-<idx>` id, and `innerHTML = ''` drops the rows
      and their ids (so `beginScanPolling`'s clear is modelled too). The 24 existing suites stayed green.
- [x] **2. Tests first (red).** New `tests/js/step3-live-pager.test.js` (`git add -f`) — 9 tests, all through
      `handleStatusUpdate`, clicks on the real pager, `beginScanPolling`, or the real outbox tick. Watched
      red before the code: `row 0 must have hidden set explicitly, got undefined`.
- [x] **3. Markup.** Static pager in `scanner-page.php`; `ScannerPageMarkupTest` pins the markup, the
      `hidden` default, its position after `cu-pages-tbody`, the absence of the Step-4 ids, and (review #2)
      that every `cu-live-*` id `scanner.js` looks up exists in the view.
- [x] **4. JS.** `LIVE_TABLE_PER_PAGE` / `liveTablePage`, `applyLiveTablePage()` (fails OPEN when the pager
      markup is missing — review #1), the call after the row loop, the reset + immediate apply in
      `beginScanPolling`, handlers bound once. **15 / 15 mutants killed** in a scratchpad mirror (working
      tree untouched), each by its intended assertion; the PHP lockstep guard proven by a rename mutant
      with a sha256-verified restore.
- [x] **5. CSS.** `#cu-scanner-app .cu-live-pager[hidden] { display: none; }` in a new end-of-file block.
- [x] **6. Real-browser check** — the real rendered view + real stylesheet + real `scanner.js` under WP core
      `common` / `list-tables` / `buttons` CSS (cache-busted; 662 plugin rules loaded): 15 URLs → pager
      `display: none`; 40 URLs → rows 0–14 then 15–29, "Page 1 of 3" → "Page 2 of 3"; focus on Next survives
      a poll; deleting the `[hidden]` rule flips a hidden pager to `display: flex` (the rule is load-bearing).
      Stripes: even pages start on the other shade, still alternating within the page — left as is.
- [x] **7. Version bump — operator chose 1.8.6.** Header, `CU_SCANNER_VERSION`, README badge and the
      `VersionLockstepTest` pin → `1.8.6`; `CU_SCANNER_ASSET_VERSION` `1.8.6.1`; `SCANNER_JS_VERSION`
      `1.0.11.10`; fingerprint rows ADDED (`1.8.6.1` → `38efe682…`, `1.0.11.10` → `74b32e82…`) with the
      test's own algorithm after the banner bump; CHANGELOG `## 1.8.6`.
- [x] **8. Verify + commit.** `php -l` ×5, `node --check`, JS 25/25, PHP 1017 tests / 2700 assertions /
      0 failures (5 skipped, 2 risky `MenuBadgeTest` — pre-existing), CRLF byte-check on all 12 touched
      files, committed locally. **HOLD** — nothing pushed (P9; the repo is public).

## Independent review (Opus reviewer, 2026-09-10) — adjudication

- #1 rows hidden before the pager guard → **fixed** (guard first; test 8 + M14).
- #2 no JS/view id lockstep → **fixed** (`ScannerPageMarkupTest`; rename mutant proven).
- #3 the outbox `dispatched` branch skipped the new-scan reset (stale rows + stale page) → **fixed**
  (`beginScanPolling()`; test 9 through the real tick + M15).
- #4 label rewritten each poll inside `aria-live` → **declined**: every row's `innerHTML` is already
  rewritten each poll in the same region; filed below.
- #5 focus drops to `<body>` when Next / Prev disables at an edge → **deferred** (Step-4 parity; outside the
  approved design) — filed below.
- #6 clamp `liveTablePage` → **declined**: unreachable once #3 is fixed; a guard no test can reach is
  decorative (P17).

## Follow-ups discovered during this task

- The Step-3 console is one `aria-live="polite"` region, and `handleStatusUpdate` rewrites every row's
  `innerHTML` inside it on every 2 s poll. Screen-reader chatter is untested; scoping the live region
  (e.g. to the progress text only) would quiet it.
- Keyboard focus drops to `<body>` when a pager button disables under it (last / first page) — both the
  Step-3 and Step-4 pagers; Step 4 also loses focus on every click because it re-renders.
- The live table renders `skipped` as `…` (the status map knows only `done` / `error`). Unreachable mid-scan
  today (kill-only, and kill is terminal), so it is a note, not a defect.
- Harness: `makeEl`'s `innerHTML = ''` still does not clear `appendChild`'d children for any element other
  than `cu-pages-tbody` (fixed for that one container in this task).

## Review

**Built.** The Step-3 "Live URL status" table paginates at 15 URLs per page with a static
"« Prev · Page N of M · Next »" pager; paging is manual only; the page persists across polls; a new scan —
including an outbox dispatch — opens on page 1. Versioned 1.8.6 and committed locally; not pushed.

**Two design choices that earned their keep.**
- Hiding rows instead of rendering a slice left the row ids, the global-idx URL fallback and the bypass
  verdict byte-identical — tests 6–7 and M4 pin that.
- A static pager instead of Step 4's re-rendered one — the browser proved focus on Next survives a poll.

**Traps caught.** The harness appended a duplicate row set on every poll (rows were never findable by id),
which would have made every multi-poll pager test count wrong; an outbox-dispatched scan inherited the
previous scan's rows and page (found by review, pinned by test 9); `.cu-url-pager { display: flex }` defeats
`[hidden]` (browser-proven).

---

# 1.8.2b — settings/history polish + S:/A: hover breakdown (2026-08-23)

Base: `374ad93` (1.8.1b) on `main`, working in the `codex` worktree.
Carries two already-staged 1.8.1b-era tweaks (labelled Scan-ID copy payload; header letter-spacing)
— item 4 below **supersedes** the letter-spacing value.

---

## Phase-1 findings

- 🟢 **CONFIRMED** — item 1 root cause: `.cu-balance-btn` (specificity `0,1,0`) declares
  `display: inline-flex; align-items: center`, but WP core's `.wp-core-ui .button` (`0,2,0`) wins on
  `display` and forces `inline-block`. `.cu-admin-page .button` then pins `min-height: 34px`. A
  `<button>` (Refresh) centres its content natively; an `<a>` (Buy credits) does not — which is
  exactly the asymmetry in the screenshot. Fix must raise specificity, not re-declare at `0,1,0`.
- 🟢 **CONFIRMED** — item 2 geometry: `.cu-settings-card-heading` is `grid-template-columns: 38px
  minmax(0,1fr); gap: 12px` → heading text starts at **50px**. `.cu-option-row` is `auto
  minmax(0,1fr); gap: 9px; padding-left: 12px` → text starts at `12 + checkbox + 9` ≈ **37px**.
  Hence the ~13px left offset. Fix by making the first column a fixed width that sums to 50px,
  not by a magic padding nudge.
- 🟢 **CONFIRMED** — item 4: the title renders at `font-weight: 700` from the OLD-generation rule
  `.cu-header-text h2` (`0,1,1`, line ~145). The later combined rule declares no `font-weight`, so
  700 wins on `.cu-admin-page` pages. Settings/History use `.cu-admin-page`; the scan wizard uses
  `#cu-scanner-app` — the combined selector covers both.
- 🟢 **CONFIRMED** — item 5 data path: `CuJsonBuilder::build()` is the one true rule-emitting pass;
  each emitted rule carries `asset_handle`, and `$s`/`$a` increment once per emitted rule.
  **Every `combine()` map entry emits at most ONE rule**, so `count(handles) === $s` by construction.
- 🟢 **CONFIRMED** — item 5 trap: `by_page` has a **SECOND producer**, `recompute_by_page()`
  (`class-scanner-ajax.php:2634`, ET-ratchet merge path). It rebuilds safe/aggressive from the
  MERGED rules. Adding the breakdown only in `CuJsonBuilder` would leave the tooltip silently dead
  after a merge — and worse, counts from one producer with a breakdown from the other. Both
  producers must derive the breakdown from the same rules they count.
- 🟢 **CONFIRMED** — established precedent for this exact feature is `kept_breakdown`: producer
  emits `[{label,count}]`, client assigns via the **title PROPERTY only** (ruling R19 — worker
  strings are untrusted and `cuEscHtml()` does NOT escape double quotes, so a `title="…"` attribute
  concat would be an attribute-breakout). Mirror it exactly.

## Tasks

- [x] **1. Buy-credits vertical centring.** Add a specificity-winning rule
      (`.cu-admin-page .button.cu-balance-btn`, plus the `#cu-scanner-app` variant) restoring
      `inline-flex` + `align-items/justify-content: center`. Verify in-browser, not by eye.
- [x] **2. Option-row text alignment.** Give `.cu-option-row` a fixed first column so the row sums
      to the card heading's 50px text origin. Shipped as `1px border + 12px padding + 25px column +
      12px gap`; the first pass used a 26px column and measured 0.8px off, because the row is
      border-box and its own 1px border was not in the arithmetic. Checkbox centred in its column.
- [x] **3. History `th` weight.** `.cu-history-table-card th` `font-weight: 800` → `600`.
- [x] **4. Title weight + tracking, ALL AAS pages.** On the combined
      `#cu-scanner-app .cu-header-text h2, .cu-admin-page .cu-header-text h2` rule: add
      `font-weight: 600` and set `letter-spacing: .03em` (supersedes the staged `.05em`).
      Operator waived the fit test.
- [x] **5. S:/A: hover breakdown.** Producer-side, mirroring `kept_breakdown`:
      - `CuJsonBuilder::build()` — accumulate the handle on each rule emit, in the same branch that
        increments `$s`/`$a`; emit `safe_breakdown` / `aggressive_breakdown` as `[{label,count}]`
        deduped by handle, sorted case-insensitively. Σcount == the S/A number BY CONSTRUCTION.
      - `recompute_by_page()` — same shape, built from the merged `$rules` it already walks.
      - `AIAS_Scan_Status::build_pages()` — copy both onto the row, defensively validated.
      - `scanner.js` — generalise `buildKeptChipTitle` into one shared builder; tag the S/A tokens
        with `data-cu-row`; in the post-render pass assign `.title` **only when the count is > 0**
        (operator: hover must not fire on a zero token). N: is never tagged.
      - **Identifier choice: the asset HANDLE**, used identically for S and A (operator: "always
        have the same approach"). It is what the emitted rule carries, so no second lookup can drift.
- [x] **6. Version + release hygiene.** Bump to `1.8.2b` across the three lockstep sites, plus
      `CU_SCANNER_ASSET_VERSION` and `SCANNER_JS_VERSION`; **ADD** (never rewrite) new fingerprint
      rows computed with the test's own algorithm. Update `VersionLockstepTest`'s pin. Replace the
      `[Unreleased]` CHANGELOG block with a real `## 1.8.2b` entry.
- [x] **7. Verify + commit.** `php -l`, `node --check`, both suites, browser verification of items
      1/2/4/5, CRLF byte-check on every touched file, mutation-test the new gate. Then commit.

## Follow-ups discovered during this task

- The CSS file now carries FOUR generations of rules; `.cu-header-text h2` exists at ~145 (700),
  ~1096 (600) and ~1655 (combined). Two of the three are dead weight. Dedup pass overdue.
- `by_page`'s two producers (`CuJsonBuilder` + `recompute_by_page`) must stay in step on every
  field. Nothing enforces that — a registry-sweep test asserting both emit the same key set would
  close the drift surface this task had to navigate by hand.
- `CU_BYPASS_PARAM_KEYS` (JS) still duplicates `OPTIMIZERS` (PHP) with no lockstep guard — carried
  over from 1.8.1b.

## Review

**Shipped.** All five operator items plus the two carried-over 1.8.1b-era tweaks, released as 1.8.2b.

**Root causes, not symptoms.**
- Item 1 was not a padding problem: `.cu-balance-btn` already asked for flex centring but lost the
  `display` declaration to WP core at higher specificity, so the anchor never became a flex box.
  Re-declaring above core fixed it; a padding nudge would have masked it at one font size.
- Item 2 was a grid-geometry mismatch, closed by making the option row sum to the heading text
  origin (1px border + 12px padding + 25px column + 12px gap = 50px). Measured 0.2px apart.
- Item 4 was an inheritance gap: the combined selector declared no `font-weight`, so an older
  `.cu-header-text h2` rule pinned 700 everywhere except the wizard, which overrode it separately.

**Verification performed.**
- Real-browser measurement of items 1-4 against the real markup + real stylesheet: Buy-credits text
  gaps 12.6/13.4px (centred); option text 253.8 vs heading 254.0; history `th` 600; title 600 /
  0.66px (= .03em x 22px) / 22px on a `.cu-admin-page` screen.
- Item 5 verified in a real browser using production code SLICED FROM SOURCE (not retyped): the
  selector matched exactly the two tagged tokens, S: and A: carried their handle lists with
  `cursor: help`, and S:0 / A:0 / N: carried no title and no help cursor.
- Mutation-tested both new guards. Gating the hover on the RAW count instead of the displayed one
  turns the `all_already` test red; assigning an empty title turns the legacy-row test red.
- Suites: JS 21/21, PHP 950 / 2344 assertions / 0 failures. The 5 skipped + 2 risky (`MenuBadgeTest`)
  are pre-existing and untouched.
- CRLF verified byte-level on every changed file.

**Two traps caught before they shipped.**
1. `by_page` has TWO producers. Adding the breakdown only to `CuJsonBuilder` would have paired a
   MERGED count with an UNMERGED list on the ET-ratchet path — a tooltip quietly contradicting its
   own token. Both producers now collect handles in the branch that increments.
2. `all_already` rows DISPLAY S:0 A:0 while the raw fields stay positive. Keying the hover off
   `p.safe` would have hung an asset list on a token reading 0. Gated on the displayed value, and
   that is the mutation the test now pins.

**One self-correction worth recording.** The first version of the hover test asserted
`!tagged[0].title`, which a mutation assigning `title = ''` survived — `!''` is also true. That
mattered: the `.cu-san-token[title]` cursor rule matches an EMPTY title, so the bug would have shown
a help cursor promising a tooltip that never appears. Tightened to `strictEqual(undefined)`, which
kills the mutation. A falsy check is not an absence check.

**Harness limitation found.** `r3-stage-c-harness`s `parseSelector` supports only ONE `[attr]`, so
the original `.cu-san-token[data-cu-row][data-cu-san]` selector would have returned `[]` there and
every test would have passed for the wrong reason. Production now uses a single-marker selector —
better design anyway (one marker, one semantic) — and the harness exercises the real path.
