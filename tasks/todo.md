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
