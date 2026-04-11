# Scanning Notice — Design Spec
**Date:** 2026-04-11
**Plugin:** AI Assets Scanner
**Version target:** 1.1.1

---

## Problem

Step 3 (scanning) shows a progress bar and URL table but gives no guidance on whether the user must stay on the page. With scan resume now implemented (v1.1.0), users can safely leave and return — but they don't know that. They also have no warning against editing page content mid-scan, which can corrupt results.

---

## Solution

A static informational notice in `scanner-page.php`, placed between the progress bar and the URL table inside `#step-3`. No JavaScript. No new CSS. Uses WordPress's built-in `.notice.notice-info` classes.

---

## Placement

Inside `#step-3`, between `#cu-progress-bar-wrap` and `#cu-pages-table`:

```
[sonar animation]
[progress bar]
────────────────────────────────────────
[notice: safe to close + don't edit]   ← NEW
────────────────────────────────────────
[URL table]
[Cancel button]
```

---

## Copy

```
You can safely close this tab — the scan runs in the background.
Results will be waiting when you return.

Do not edit the content of pages being scanned while the scan is active.
```

The first two lines form the reassuring message (shown as `<strong>` + plain text in one `<p>`). The warning is a second `<p>`. Both inside a single `.notice.notice-info` div.

---

## HTML

```html
<div class="notice notice-info inline" style="margin:12px 0">
    <p><strong>You can safely close this tab</strong> &mdash; the scan runs in the background. Results will be waiting when you return.</p>
    <p>Do not edit the content of pages being scanned while the scan is active.</p>
</div>
```

The `inline` class prevents the WP notice left-border-only style from being stripped in the admin context.

---

## Files Changed

| File | Change |
|------|--------|
| `admin/views/scanner-page.php` | Add notice div between `#cu-progress-bar-wrap` and `#cu-pages-table` |
| `ai-assets-scanner.php` | Bump version `1.1.0` → `1.1.1` |

---

## Out of Scope

- Dismissible notice (unnecessary — message is always relevant while Step 3 is visible)
- JS-injected notice (static HTML is sufficient; Step 3 is hidden until scan starts)
- Railway queuing (no queue exists server-side; out of scope for this change)
