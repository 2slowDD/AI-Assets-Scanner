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
