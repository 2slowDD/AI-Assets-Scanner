# Include URLs Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an "Include URLs" textarea to Step 1 that lets users scan specific URLs directly, bypassing or supplementing page discovery.

**Architecture:** Pure client-side feature. Two PHP files change (view + CSS); no backend changes. The JS gains three helper functions (`getIncludedUrls`, `syncIncludedUrls`, `updateStartScanVisibility`) and an `includedUrls` state variable. The Included group slots into the existing `GROUP_META` / `renderUrlList` pattern.

**Tech Stack:** PHP (view template), vanilla JS (scanner.js), CSS (ai-assets-scanner-admin.css)

---

## Files Changed

| File | Change |
|------|--------|
| `admin/views/scanner-page.php` | Restructure Step 1: move Discover button, add Include textarea, add Included filter pill, remove Discover from action row |
| `admin/css/ai-assets-scanner-admin.css` | Add `.cu-discover-row`, `.cu-group-header--included`, `.cu-included-badge` |
| `admin/js/scanner.js` | Add `includedUrls` state, 3 helper functions, input listener, update discover handler, extend `GROUP_META` + `renderUrlList`, guard Start Scan click |

---

## Task 1: Restructure `scanner-page.php` Step 1

**Files:**
- Modify: `admin/views/scanner-page.php`

- [ ] **Step 1: Make targeted edits inside Step 1 — do NOT replace the whole block**

The sonar SVG inside `#cu-sonar-anim` is untouched. Make these three targeted changes:

**Change A — Add discover row div.** Find the line:
```html
        <div class="cu-sonar-anim" id="cu-sonar-anim" style="display:none">
```
Insert this new div **immediately after** the closing `</div>` of `#cu-sonar-anim`:
```html
        <!-- Discover row (top, normal-width button) -->
        <div class="cu-discover-row">
            <button id="cu-btn-discover" class="button">Discover Pages</button>
            <span class="description">or fill Include URLs below to scan specific pages</span>
        </div>
```

**Change B — Add Included filter pill.** Inside `#cu-filter-bar`, find:
```html
                <span class="cu-filter-divider">|</span>
```
Insert this line immediately before it:
```html
                <span class="cu-filter-pill"           data-filter="included" id="cu-pill-included" style="display:none">Included</span>
```

**Change C — Replace `#cu-exclusions` with `#cu-url-inputs` + Include textarea.** Find the entire `#cu-exclusions` div:
```html
        <!-- Exclusion textarea -->
        <div id="cu-exclusions">
            <label>Exclude URLs (one per line):<br>
                <textarea id="cu-excluded-urls" rows="4" style="width:100%"></textarea>
            </label>
            <p class="description" style="margin-top:4px">Tip: deselecting URLs above is simpler for most cases.</p>
        </div>
```
Replace it with:
```html
        <!-- URL inputs: Include + Exclude -->
        <div id="cu-url-inputs">
            <div style="margin-bottom:8px">
                <label>Include URLs (one per line):<br>
                    <textarea id="cu-included-urls" rows="4" style="width:100%"></textarea>
                </label>
                <p class="description" style="margin-top:4px">Scan these URLs directly without running Discover Pages.</p>
            </div>
            <div>
                <label>Exclude URLs (one per line):<br>
                    <textarea id="cu-excluded-urls" rows="4" style="width:100%"></textarea>
                </label>
                <p class="description" style="margin-top:4px">Tip: deselecting URLs above is simpler for most cases.</p>
            </div>
        </div>
```

**Change D — Remove Discover button from action row.** Find inside `#cu-action-row-1`:
```html
            <button id="cu-btn-discover" class="button">Discover Pages</button>
```
Delete that line (the button is now in the discover row above).

        <!-- Discover row (top, normal-width button) -->
        <div class="cu-discover-row">
            <button id="cu-btn-discover" class="button">Discover Pages</button>
            <span class="description">or fill Include URLs below to scan specific pages</span>
        </div>

        <!-- URL list area (hidden until discovery completes) -->
        <div id="cu-url-list-area" style="display:none">
            <!-- Filter bar (counts populated by JS) -->
            <div class="cu-filter-bar" id="cu-filter-bar">
                <span class="cu-filter-pill is-active" data-filter="all"      id="cu-pill-all">All</span>
                <span class="cu-filter-pill"           data-filter="page"     id="cu-pill-page"     style="display:none">Pages</span>
                <span class="cu-filter-pill"           data-filter="post"     id="cu-pill-post"     style="display:none">Posts</span>
                <span class="cu-filter-pill"           data-filter="other"    id="cu-pill-other"    style="display:none">Other</span>
                <span class="cu-filter-pill"           data-filter="included" id="cu-pill-included" style="display:none">Included</span>
                <span class="cu-filter-divider">|</span>
                <span class="cu-filter-pill cu-filter-action" id="cu-btn-select-all">&#9745; Select all</span>
                <span class="cu-filter-pill cu-filter-action" id="cu-btn-deselect-all">&#9744; Deselect all</span>
            </div>

            <!-- Grouped URL list (populated by JS) -->
            <div class="cu-url-list" id="cu-url-list"></div>
        </div>

        <!-- URL inputs: Include + Exclude -->
        <div id="cu-url-inputs">
            <div style="margin-bottom:8px">
                <label>Include URLs (one per line):<br>
                    <textarea id="cu-included-urls" rows="4" style="width:100%"></textarea>
                </label>
                <p class="description" style="margin-top:4px">Scan these URLs directly without running Discover Pages.</p>
            </div>
            <div>
                <label>Exclude URLs (one per line):<br>
                    <textarea id="cu-excluded-urls" rows="4" style="width:100%"></textarea>
                </label>
                <p class="description" style="margin-top:4px">Tip: deselecting URLs above is simpler for most cases.</p>
            </div>
        </div>

        <!-- Credit badge + actions -->
        <div class="cu-action-row" id="cu-action-row-1">
            <div class="cu-credit-badge" id="cu-credit-badge" style="display:none">
                <span class="cu-credit-num" id="cu-credit-num">0</span>
                credits for this scan
                <span class="cu-credit-deselected" id="cu-credit-deselected" style="display:none"></span>
            </div>
            <div class="cu-spacer"></div>
            <button id="cu-btn-next-1" class="button button-primary" style="display:none">Start Scan &rarr;</button>
        </div>
    </div>
```

- [ ] **Step 2: Reload the plugin admin page**

Navigate to **AI Assets Scanner** in WP admin. Verify:
- "Discover Pages" button appears at the top, normal width
- "Include URLs" textarea is below it, above "Exclude URLs"
- No Discover button in the bottom action row
- Start Scan button is hidden initially

- [ ] **Step 3: Commit**

```bash
cd "D:/ai/cu/AI Assets Scanner/AI-Assets-Scanner"
git add admin/views/scanner-page.php
git commit -m "feat: restructure Step 1 layout — Discover to top, add Include URLs textarea"
```

---

## Task 2: Add CSS for new elements

**Files:**
- Modify: `admin/css/ai-assets-scanner-admin.css`

- [ ] **Step 1: Add styles at the end of the file**

Append these rules to the bottom of `ai-assets-scanner-admin.css`:

```css
/* --- Include URLs feature (2026-04-10) --- */

/* Discover row: button + hint text, top of Step 1 */
.cu-discover-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 14px;
}
.cu-discover-row .description {
    margin: 0;
}

/* Spacing between Include and Exclude inputs */
#cu-url-inputs {
    margin-bottom: 14px;
}

/* Included group header (teal, distinct from page/post/other) */
.cu-group-header--included { background: #1a5f4a; }

/* [included] badge on URL rows */
.cu-included-badge {
    font-size: 10px;
    color: #2271b1;
    font-weight: 600;
    margin-left: 6px;
    white-space: nowrap;
}
```

- [ ] **Step 2: Verify in browser**

Reload the admin page. The layout should have correct spacing between Include and Exclude textareas, and the Discover row should align horizontally.

- [ ] **Step 3: Commit**

```bash
git add admin/css/ai-assets-scanner-admin.css
git commit -m "feat: add CSS for cu-discover-row, included group header, included badge"
```

---

## Task 3: JS — State variable and helper functions

**Files:**
- Modify: `admin/js/scanner.js`

- [ ] **Step 1: Add `includedUrls` to the state block**

Find the `// --- State ---` comment block at the top of the IIFE. It currently ends with `let hasSoftBlocks = false;`. Add one line after it:

```js
    let hasSoftBlocks  = false;
    let includedUrls   = [];   // include URLs not duplicated in discoveredUrls
```

- [ ] **Step 2: Add three helper functions after the `updateStartScanGate` function**

Find `function updateStartScanGate() { ... }` (the function that sets `btn.disabled` based on soft-block overrides). Add the three new functions immediately after it:

```js
    // --- Include URLs helpers ---

    function getIncludedUrls() {
        const el = document.getElementById('cu-included-urls');
        if (!el) return [];
        return el.value.split('\n').map(u => u.trim()).filter(u => u.length > 0);
    }

    function normaliseUrl(u) {
        // Strip trailing slashes and lowercase for dedup comparison
        return u.replace(/\/+$/, '').toLowerCase();
    }

    function updateStartScanVisibility() {
        const btn = document.getElementById('cu-btn-next-1');
        if (!btn) return;
        const hasIncluded  = getIncludedUrls().length > 0;
        const hasDiscovered = discoveredUrls.length > 0;
        btn.style.display = (hasIncluded || hasDiscovered) ? '' : 'none';
    }

    function syncIncludedUrls() {
        // Only merges when discovery has already run. Include-only path is
        // handled directly in the Start Scan click handler.
        if (discoveredUrls.length === 0) return;

        const raw = getIncludedUrls();
        const discoveredSet = new Set(discoveredUrls.map(normaliseUrl));

        // URLs in the include list that are NOT already discovered
        const newIncluded = raw.filter(u => !discoveredSet.has(normaliseUrl(u)));

        // Remove previously-tracked included URLs from selectedUrls
        const oldSet = new Set(includedUrls.map(normaliseUrl));
        selectedUrls = selectedUrls.filter(u => !oldSet.has(normaliseUrl(u)));

        // Add newly-included URLs to selectedUrls
        selectedUrls = [...selectedUrls, ...newIncluded];

        includedUrls          = newIncluded;
        groupedUrls.included  = newIncluded;
    }
```

- [ ] **Step 3: Open browser console on the scanner page and verify the functions exist**

Open WP admin → AI Assets Scanner. Open DevTools console and run:
```js
typeof getIncludedUrls   // → "function"
typeof syncIncludedUrls  // → "function"
typeof updateStartScanVisibility // → "function"
```

Expected: all three return `"function"`.

- [ ] **Step 4: Commit**

```bash
git add admin/js/scanner.js
git commit -m "feat: add includedUrls state, getIncludedUrls, syncIncludedUrls, updateStartScanVisibility"
```

---

## Task 4: JS — Input listener on Include URLs textarea

**Files:**
- Modify: `admin/js/scanner.js`

- [ ] **Step 1: Add input listener**

Find the discover button click listener: `document.getElementById('cu-btn-discover').addEventListener('click', function () {`. Add the new listener **immediately before** it:

```js
    // --- Include URLs: live update on input ---

    document.getElementById('cu-included-urls').addEventListener('input', function () {
        if (discoveredUrls.length === 0) {
            // Include-only mode: drive selectedUrls directly from the textarea
            selectedUrls = getIncludedUrls();
            totalPages   = selectedUrls.length;
            updateCreditBadge();
        } else {
            // Discovery already ran: re-merge and re-render
            syncIncludedUrls();
            renderUrlList();
            updateCreditBadge();
        }
        updateStartScanVisibility();
    });
```

- [ ] **Step 2: Manual test — include-only**

On the scanner page:
1. Type `https://example.com/` in the Include URLs field
2. Expect: Start Scan button appears, credit badge shows "1 credits for this scan"
3. Add a second line `https://example.com/shop`
4. Expect: credit badge updates to "2 credits"
5. Clear the field
6. Expect: Start Scan hides, credit badge hides

- [ ] **Step 3: Commit**

```bash
git add admin/js/scanner.js
git commit -m "feat: wire input listener on cu-included-urls — show Start Scan and update credit badge"
```

---

## Task 5: JS — Update discovery handler to merge Include URLs

**Files:**
- Modify: `admin/js/scanner.js`

- [ ] **Step 1: Update the post-discovery block**

Inside the `cu-btn-discover` click listener, find the `.then(res => {` success block. It currently ends with:

```js
            renderUrlList();
            updateCreditBadge();

            document.getElementById('cu-url-list-area').style.display = 'block';
            document.getElementById('cu-btn-next-1').style.display = '';
```

Replace those four lines with:

```js
            syncIncludedUrls();   // merge Include URLs into state before render
            renderUrlList();
            updateCreditBadge();

            document.getElementById('cu-url-list-area').style.display = 'block';
            updateStartScanVisibility();   // handles show/hide based on urls + include
```

- [ ] **Step 2: Manual test — Include + Discover**

1. Type `https://yoursite.com/specific-page` in Include URLs
2. Click Discover Pages
3. Expect: sonar runs, then URL list appears with discovered pages AND an "Included" pill in the filter bar showing "Included 1" (assuming `/specific-page` wasn't already discovered)
4. Credit badge should show discovered count + 1
5. Click the "Included" pill — only the included URL row should be visible
6. Deselect the included URL → credit drops by 1

- [ ] **Step 3: Test deduplication**

1. Type a URL that IS in the sitemap (e.g. your homepage) in Include URLs
2. Click Discover Pages
3. Expect: homepage appears in the Pages/Other group (wherever discovered), NOT in the Included group. No duplicate.

- [ ] **Step 4: Commit**

```bash
git add admin/js/scanner.js
git commit -m "feat: merge Include URLs into discovered state after page discovery"
```

---

## Task 6: JS — Add Included group to `renderUrlList`

**Files:**
- Modify: `admin/js/scanner.js`

- [ ] **Step 1: Add `included` to `GROUP_META`**

Find the `const GROUP_META = {` object. It currently has `page`, `post`, `other`. Add `included`:

```js
    const GROUP_META = {
        page:     { label: 'Pages',    cls: 'cu-group-header--page',     more: 'pages' },
        post:     { label: 'Posts',    cls: 'cu-group-header--post',     more: 'posts' },
        other:    { label: 'Other',    cls: 'cu-group-header--other',    more: '' },
        included: { label: 'Included', cls: 'cu-group-header--included', more: 'included URLs' },
    };
```

- [ ] **Step 2: Add `included` to the filter pill update loop in `renderUrlList`**

Inside `renderUrlList()`, find the loop that updates pill counts and visibility:

```js
        ['page', 'post', 'other'].forEach(type => {
```

Change it to:

```js
        ['page', 'post', 'other', 'included'].forEach(type => {
```

- [ ] **Step 3: Add `[included]` badge to Included group rows**

Inside `renderUrlList()`, find the section where URL rows are built. It will have a `urls.forEach((url, idx) => {` block that creates each row. Inside that block, find where the URL label/text is set. The row label will look something like:

```js
            label.textContent = url;
```

or it may use `innerHTML`. After the URL text is set, add a badge for the included type:

```js
            if (type === 'included') {
                const badge = document.createElement('span');
                badge.className = 'cu-included-badge';
                badge.textContent = '[included]';
                label.appendChild(badge);
            }
```

Place this block immediately after the line that sets the label text/URL.

- [ ] **Step 4: Manual test — Included group rendering**

1. Type 2 URLs in Include field (not in sitemap)
2. Click Discover Pages
3. Expect: "Included 2" pill appears in the filter bar
4. Click "Included" pill → only 2 rows visible, each with `[included]` badge in teal
5. Click "All" pill → all discovered + 2 included rows visible
6. Click group-level checkbox on Included header → deselects both included URLs

- [ ] **Step 5: Commit**

```bash
git add admin/js/scanner.js
git commit -m "feat: add Included group to GROUP_META and renderUrlList with badge"
```

---

## Task 7: JS — Guard Start Scan click for include-only path

**Files:**
- Modify: `admin/js/scanner.js`

- [ ] **Step 1: Find the Start Scan click handler**

Search the file for `cu-btn-next-1` click listener. It will look like:

```js
    document.getElementById('cu-btn-next-1').addEventListener('click', function () {
```

- [ ] **Step 2: Add include-only guard at the top of the handler**

Insert this block as the very first thing inside the click handler, before any existing logic (before `showStep(2)` or any `post(...)` call):

```js
        // Include-only path: no discovery ran — populate state from Include URLs
        if (discoveredUrls.length === 0) {
            const urls = getIncludedUrls();
            if (urls.length === 0) return;  // shouldn't be reachable, Start Scan would be hidden
            selectedUrls   = urls.slice();
            discoveredUrls = urls.slice();  // needed so totalPages and poll loop work correctly
            groupedUrls    = { page: [], post: [], other: [], included: [] };
            totalPages     = urls.length;
        }
```

- [ ] **Step 3: Manual test — include-only scan submission**

1. Refresh the scanner page (clear any discovery state)
2. Enter 2 URLs in Include URLs (use real URLs on your site)
3. Do NOT click Discover Pages
4. Click Start Scan →
5. Expect: moves to Step 2 "Reserving Credits", reserves 2 credits, proceeds to Step 3 scanning exactly those 2 URLs
6. Verify in Step 3 progress table: exactly 2 rows, no others

- [ ] **Step 4: Commit**

```bash
git add admin/js/scanner.js
git commit -m "feat: populate state from Include URLs when Start Scan clicked without discovery"
```

---

## Task 8: End-to-end testing and final commit

- [ ] **Step 1: Full include-only path test**

1. Enter 3 URLs in Include URLs
2. Verify credit badge = 3
3. Click Start Scan → scan runs on exactly 3 URLs
4. Verify Step 3 table shows exactly 3 rows

- [ ] **Step 2: Full Include + Discover path test**

1. Enter 2 URLs (not in sitemap) in Include URLs
2. Click Discover Pages → Included pill shows "Included 2", credit = discovered + 2
3. Deselect 1 included URL via checkbox → credit drops by 1
4. Re-discover → Included group rebuilds from current textarea content
5. Click Start Scan → scan runs on selected discovered + 1 remaining included URL

- [ ] **Step 3: Clear-Include-after-discovery test**

1. Enter 2 URLs, click Discover, verify Included pill shows 2
2. Clear the Include URLs textarea
3. Verify: Included pill disappears, credit reverts to discovered-only count

- [ ] **Step 4: Dedup test**

1. Enter your homepage URL in Include (e.g. `https://yoursite.com/`)
2. Click Discover → homepage appears in its discovered group, NOT in Included group
3. No duplicate row

- [ ] **Step 5: Soft-block gate test**

1. If any optimization plugins (WP Rocket, NitroPack etc.) are active, confirm their override checkbox still disables Start Scan until acknowledged — even when Include URLs has content

- [ ] **Step 6: Bump version and update CHANGELOG**

In `ai-assets-scanner.php`, bump `Version: 1.0.7` → `Version: 1.0.8` and update `CU_SCANNER_VERSION`.

In `CHANGELOG.md`, add at the top:

```markdown
## [1.0.8] — 2026-04-10

### New features

- **Include URLs** — New textarea in Step 1 lets users enter specific URLs (one per line) to scan directly without running Discover Pages. When Include URLs has content, Start Scan appears immediately.
- **Include + Discover merge** — If Discover Pages is also run, Include URLs are merged into the discovered set as a pre-selected "Included" group with its own filter pill. URLs already in the discovered set are deduplicated.
- **Discover Pages moved to top** — Discover Pages button now sits above both URL input fields for clearer visual hierarchy.
```

- [ ] **Step 7: Final commit**

```bash
git add admin/views/scanner-page.php admin/js/scanner.js admin/css/ai-assets-scanner-admin.css \
        ai-assets-scanner.php CHANGELOG.md
git commit -m "feat: Include URLs — scan specific pages without or alongside discovery (v1.0.8)"
git push
```
