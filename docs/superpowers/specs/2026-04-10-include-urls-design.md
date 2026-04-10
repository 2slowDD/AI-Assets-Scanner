# Include URLs — Design Spec

**Date:** 2026-04-10
**Status:** Approved
**Version:** 1.0

---

## Overview

Add an "Include URLs" textarea to Step 1 of the scanner page. When filled, it allows the user to scan a specific set of URLs directly — bypassing the Discover Pages step. If the user also runs Discover Pages, the included URLs are merged into the discovered set as a pre-selected "Included" group.

---

## Layout Changes

**File:** `admin/views/scanner-page.php`

Step 1 (`#step-1`) is restructured as follows:

1. Plugin warnings (`#cu-plugin-warnings`) — unchanged, top of body
2. Sonar animation (`#cu-sonar-anim`) — unchanged
3. **Discover Pages button** — moved to the top of the action area, left-aligned, normal width (not full-width). A short hint sits beside it: *"or fill Include URLs below to scan specific pages"*. After discovery runs, it relabels to "Re-discover" as before.
4. **Include URLs textarea** (`#cu-included-urls`) — new field, above Exclude URLs. Label: `Include URLs (one per line)`. Helper text below: *"Scan these URLs directly without running Discover Pages."*
5. **Exclude URLs textarea** (`#cu-excluded-urls`) — existing field, unchanged, below Include URLs
6. URL list area (`#cu-url-list-area`) — unchanged, shown after discovery
7. Action row (`#cu-action-row-1`) — credit badge left, Start Scan right. Start Scan visibility now driven by two conditions (see JS logic).

The containing div `#cu-exclusions` is renamed to `#cu-url-inputs` to reflect its expanded role.

---

## JS Logic

**File:** `admin/js/scanner.js`

### New state

No new top-level state variables. The existing `selectedUrls`, `discoveredUrls`, and `groupedUrls` cover everything. A helper function `getIncludedUrls()` reads and parses `#cu-included-urls` at call time.

```
getIncludedUrls() → string[]
  Reads #cu-included-urls value, splits on newlines,
  trims each line, filters empty lines and non-URL strings.
```

### Start Scan visibility rule

Start Scan (`#cu-btn-next-1`) is shown when **either** condition is true:
- Include URLs textarea has ≥ 1 non-empty line, **OR**
- Discovery has completed (existing behaviour — `discoveredUrls.length > 0`)

An `input` event listener on `#cu-included-urls` calls `updateStartScanVisibility()` on every keystroke.

### Include-only path (no Discover clicked)

When the user clicks Start Scan without having run Discover, the click handler calls `getIncludedUrls()` first to populate state before proceeding to `reserve_job`:

1. `getIncludedUrls()` is called
2. `selectedUrls` is set to the include list
3. `discoveredUrls` is set to the include list (needed for credit badge and submit)
4. `groupedUrls` is set to `{ page: [], post: [], other: [], included: includeUrls }`
5. The URL list area is NOT shown — flow proceeds directly to `reserve_job`

The credit badge is updated via `updateCreditBadge()` as URLs are typed (reflects `selectedUrls.length`).

### Include + Discover path

After `discover_pages` AJAX response succeeds:

1. Existing logic runs: `discoveredUrls`, `groupedUrls`, `selectedUrls` are set from the response
2. `getIncludedUrls()` is called
3. Deduplication: any include URL already present in `discoveredUrls` is skipped (normalized comparison: trailing slash insensitive)
4. Remaining include URLs are:
   - Appended to `selectedUrls`
   - Stored in `groupedUrls.included = deduped_include_urls`
5. `renderUrlList()` is called — it renders the Included group with its own group header and filter pill ("Included N"), using the same checkbox mechanic as other groups
6. Credit badge reflects the combined `selectedUrls.length`

If the user later clears the Include URLs textarea after discovery:
- `updateStartScanVisibility()` fires but Start Scan stays visible (discovery already ran)
- `updateCreditBadge()` recalculates — this requires re-merging on every include change

To keep this simple: the `input` listener on `#cu-included-urls` calls a `syncIncludedUrls()` function that re-runs the merge logic and calls `renderUrlList()` + `updateCreditBadge()` whenever discovery has already completed.

### Included group rendering

`renderUrlList()` gains a new branch for `groupedUrls.included`:

- Group header: dark teal/distinct colour, label "Included"
- Filter pill: `#cu-pill-included`, hidden when `groupedUrls.included` is empty
- Each included URL row has a `[included]` badge (small text, blue)
- Checkbox behaviour: identical to other groups (deselecting removes from `selectedUrls`)

### Deduplication detail

Normalise both sides before comparing:
```
normalise(url) → trailingslash(lowercase(url))
```
If a normalised include URL matches a normalised discovered URL, it is not added to the Included group — it is already in the discovered list and already selected. No visual change needed for that URL.

---

## Backend

No changes. `class-scanner-ajax.php` is unmodified:

- `discover_pages()` — already accepts and applies `excluded_urls`; include URL merging happens client-side after the response
- `reserve_job()` — uses `page_count` from client; automatically correct since `selectedUrls` already includes the included set
- `submit_job()` — receives `selected_urls` array from client; already correct

---

## Files Changed

| File | Change |
|------|--------|
| `admin/views/scanner-page.php` | Restructure Step 1 layout; move Discover button; add `#cu-included-urls` textarea; rename wrapper div |
| `admin/js/scanner.js` | `getIncludedUrls()`, `syncIncludedUrls()`, `updateStartScanVisibility()` helpers; `input` listener on new textarea; post-discovery merge; Included group in `renderUrlList()` |

---

## Testing Checklist

**Include-only path:**
- [ ] Type 3 URLs in Include field → Start Scan appears, credit badge shows 3
- [ ] Click Start Scan → scan runs on exactly those 3 URLs (check Railway payload)
- [ ] Clear Include field → Start Scan hides

**Include + Discover path:**
- [ ] Type 2 URLs in Include → click Discover → Included pill appears with those 2, credit = discovered + 2
- [ ] Deselect 1 included URL → credit drops by 1
- [ ] Deselect all included URLs → credit reflects discovered-only count
- [ ] Clear Include field after discovery → Included pill disappears, credit reflects discovered only
- [ ] Dedup: type a URL Discover will also return → appears once in discovered list (not in Included group), stays selected

**Edge cases:**
- [ ] Include URL with/without trailing slash dedups correctly against discovered URL
- [ ] Soft-block override gate still works (Start Scan disabled until overrides checked)
- [ ] Re-discover after filling Include URLs → merge re-runs correctly
- [ ] localStorage Step 4 restore still works (unrelated to Step 1 changes)
