# Design: Scanner Page UI Improvements

**Date:** 2026-04-12
**Status:** Approved

## Summary

Three UI improvements to the AI Assets Scanner scanner page (`step-1`):

1. Add a "Get in touch" contact button top-right, at Discover Pages button height
2. Change the "Discover Pages" button to primary style (blue, white text)
3. Add a "credits available" balance badge beside the existing "credits for this scan" badge, with a red tint when balance is insufficient

---

## Change 1: "Get in touch" contact element

**File:** `admin/views/scanner-page.php`

Inside `.cu-discover-row` (currently holds the Discover Pages button and hint text), add a flex spacer and a right-aligned contact hint:

```html
<div class="cu-discover-row">
    <button id="cu-btn-discover" class="button button-primary">Discover Pages</button>
    <span class="description">or fill Include URLs below to scan specific pages</span>
    <div class="cu-spacer"></div>
    <span class="cu-contact-hint">Found a bug or want to get in touch?
        <a href="https://wpservice.pro/contact/" target="_blank" rel="noopener" class="button button-secondary cu-contact-btn">Get in touch</a>
    </span>
</div>
```

The `.cu-spacer` class (already defined: `flex: 1`) pushes the contact element to the far right, aligning it vertically with the Discover Pages button.

**File:** `admin/css/ai-assets-scanner-admin.css`

```css
.cu-contact-hint {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: #787c82;
    white-space: nowrap;
}
```

---

## Change 2: Discover Pages button → primary style

**File:** `admin/views/scanner-page.php`

Change the `#cu-btn-discover` button class from `"button"` to `"button button-primary"`:

```html
<!-- Before -->
<button id="cu-btn-discover" class="button">Discover Pages</button>

<!-- After -->
<button id="cu-btn-discover" class="button button-primary">Discover Pages</button>
```

Uses WordPress's built-in `button-primary` class. No custom CSS required.

---

## Change 3: Credit balance badge

### 3a — PHP: add `balance` to detect_plugins response

**File:** `admin/class-scanner-ajax.php`, `detect_plugins()` method

After calling `(new PluginDetector())->detect()`, fetch the balance and merge it into the response:

```php
public function detect_plugins(): void {
    $this->check();
    $plugins = ( new PluginDetector() )->detect();

    try {
        $client  = new WpserviceClient( CU_SCANNER_WPSERVICE_URL, $this->settings()->get_api_key() );
        $balance = $client->get_credits();
    } catch ( \RuntimeException $e ) {
        $balance = null;
    }

    wp_send_json_success( array_merge( $plugins, [ 'balance' => $balance ] ) );
}
```

If `get_api_key()` returns empty or the API call fails, `balance` is `null` — the badge is hidden gracefully.

### 3b — HTML: balance badge in scanner-page.php

**File:** `admin/views/scanner-page.php`

Add `#cu-balance-badge` directly after `#cu-credit-badge` inside `.cu-action-row`:

```html
<div class="cu-credit-badge" id="cu-credit-badge" style="display:none">
    <span class="cu-credit-num" id="cu-credit-num">0</span>
    credits for this scan
    <span class="cu-credit-deselected" id="cu-credit-deselected" style="display:none"></span>
</div>
<div class="cu-credit-badge" id="cu-balance-badge" style="display:none">
    <span class="cu-credit-num" id="cu-balance-num">—</span>
    credits available
</div>
```

### 3c — JS: store balance and update badge

**File:** `admin/js/scanner.js`

1. In `detectPlugins()`, after `const d = res.data;`, store balance:
   ```js
   availableBalance = (typeof d.balance === 'number') ? d.balance : null;
   ```
   `availableBalance` is a module-level variable (declared alongside `selectedUrls`, `discoveredUrls`, etc.).

2. In `updateCreditBadge()`, after the existing badge logic, update the balance badge:
   ```js
   const balBadge  = document.getElementById('cu-balance-badge');
   const balNumEl  = document.getElementById('cu-balance-num');
   if (balBadge && balNumEl) {
       if (availableBalance !== null) {
           balNumEl.textContent = availableBalance;
           balBadge.style.display = '';
           if (availableBalance < selected) {
               balBadge.classList.add('cu-credit-badge--low');
           } else {
               balBadge.classList.remove('cu-credit-badge--low');
           }
       } else {
           balBadge.style.display = 'none';
       }
   }
   ```

### 3d — CSS: low-balance modifier

**File:** `admin/css/ai-assets-scanner-admin.css`

```css
.cu-credit-badge--low {
    background: #fff0f0;
    border-color: #d46060;
    color: #8b1a1a;
}
.cu-credit-badge--low .cu-credit-num {
    color: #8b1a1a;
}
```

---

## Files Modified

| File | Changes |
|------|---------|
| `admin/views/scanner-page.php` | Changes 1, 2, 3b |
| `admin/css/ai-assets-scanner-admin.css` | Changes 1 (contact hint CSS), 3d (low badge CSS) |
| `admin/class-scanner-ajax.php` | Change 3a (balance in detect_plugins) |
| `admin/js/scanner.js` | Change 3c (balance variable + updateCreditBadge) |

---

## Error Handling

- Balance API failure: `balance = null` → balance badge stays hidden, no UI disruption
- `detectPlugins()` AJAX failure: existing early return on `!res.success` already handles it

## Testing

- With URLs selected: both badges appear side by side
- Balance > credits needed: balance badge normal yellow style
- Balance < credits needed: balance badge shows mild red background
- API key missing / balance fetch fails: balance badge hidden, scan-cost badge unaffected
- No API key at all: balance is null, badge hidden gracefully
