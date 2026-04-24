# Scan History — Export & Delete All — Design

- **Plugin:** AI Assets Scanner (`cu-scanner`)
- **Target version:** 1.2.0f (next patch after 1.2.0e)
- **Date:** 2026-04-24
- **Status:** Approved — ready for implementation plan

## Problem

The Scan History admin page (`?page=cu-scanner-history`) displays the most recent 10 scans from the `cu_scanner_history` option, with per-row "Re-download" links to the stored CU JSON snapshots. Users currently have no way to (a) archive that history outside WordPress or (b) wipe it when they want a clean slate. Both need to be one-click actions reachable from the same page.

## Goals

1. Add a **"Export to ZIP"** button that downloads a single archive containing the full history plus each scan's stored CU JSON snapshot.
2. Add a **"Delete all history"** button that wipes `cu_scanner_history` and all per-scan `cu_scanner_json_<job_id>` option rows, after warning the user to export first.
3. Keep the UI consistent with existing `cu-*` admin styles; no new CSS file or stylesheet, no modal library. Minor inline styles on the new toolbar wrapper are acceptable and explicit in this spec.
4. Preserve existing WP security posture: `manage_options` capability + `cu_scanner_nonce` on every action.

## Non-goals (explicit YAGNI)

- No per-row delete button.
- No date-range / filtered export.
- No export-format picker in the UI — ZIP is the one format.
- No undo for delete — "export first" is the backup story, enforced via the warning copy.
- No database schema migration — storage stays in `wp_options` exactly as today.

## Storage model (unchanged)

- **`cu_scanner_history`** — array of up to 10 records. Fields: `job_id`, `domain`, `page_count`, `status`, `created_at` (ISO 8601 UTC), `credits_used`, `safe_count`, `aggressive_count`.
- **`cu_scanner_json_<job_id>`** — string containing the generated CU JSON payload for that scan. One option row per scan.

Both are managed by `CUScanner\ScanHistory` (`includes/class-scan-history.php`). Eviction of the oldest record beyond 10 already deletes its JSON option.

## UX

### Button placement and markup

Toolbar `<div class="cu-history-actions">` rendered **above** the history table, inside the existing `else` branch in `admin/views/history-page.php` (the branch shown only when `$history` is non-empty). The "No scans yet" empty state remains unchanged — no buttons there.

**Element type:** both actions are `<button type="button">` (NOT `<a>`). Anchors in WP admin look like buttons when given `class="button"`, but jQuery's `.prop('disabled', true)` is a no-op on anchors — the double-click and in-flight guards would silently fail. `<button>` disables correctly and matches the precedent set by `<button>` usage elsewhere in the scanner's admin JS.

**Layout:** inline flex via `style` attribute on the wrapper, so the change is self-contained with no new CSS file:

```php
<div class="cu-history-actions" style="display:flex;justify-content:flex-end;gap:8px;margin:0 0 12px;">
    <button type="button" id="cu-history-export" class="button">
        <?php esc_html_e( 'Export to ZIP', 'cu-scanner' ); ?>
    </button>
    <button type="button" id="cu-history-delete" class="button button-link-delete">
        <?php esc_html_e( 'Delete all history', 'cu-scanner' ); ?>
    </button>
</div>
```

Rendered:

```
                                    [ Export to ZIP ]  [ Delete all history ]
```

The Delete button uses WordPress's standard destructive styling (`button-link-delete`, native red-link).

### Export interaction

1. Click → `window.location.href = <admin-ajax.php with action=cu_scanner_export_history&nonce=...>`. No AJAX wrapping — browser handles the download directly.
2. Server streams the ZIP with `Content-Disposition: attachment`.
3. Button is briefly disabled for 2 seconds after click to prevent double-clicks, then re-enabled.

### Delete interaction

1. Click → native `window.confirm()` with this exact copy:

   > ⚠ This will permanently delete all scan history AND all stored scan JSON snapshots. Re-download links will stop working for old scans.
   >
   > Did you export a backup first?
   >
   > Click OK to delete everything, or Cancel to abort.

2. On OK → `fetch()` AJAX POST to `cu_scanner_delete_history` with nonce.
3. On success → `window.location.reload()` — the page re-renders into its empty state naturally.
4. On failure → `alert()` with the server-provided error message; no reload.

## Server design

### New AJAX action: `cu_scanner_export_history`

Location: `admin/class-scanner-ajax.php` — new public method `export_history()` registered via the existing action-array pattern in the constructor.

**Request:** GET `admin-ajax.php?action=cu_scanner_export_history&nonce=<cu_scanner_nonce>`

**Flow:**

1. `check_ajax_referer( 'cu_scanner_nonce', 'nonce' )`.
2. `current_user_can( 'manage_options' )` — `wp_die( 'Forbidden', 403 )` if not.
3. Load records via `( new ScanHistory() )->get_all()`.
4. If empty: respond `wp_die( 'No history to export', 200 )` with plain-text body (defensive — UI should have disabled the button).
5. **Primary path — ZipArchive available and usable.** Attempt this path iff `class_exists( 'ZipArchive' )`:
    - Create temp file via `wp_tempnam( 'cu-scanner-history' )`.
    - Instantiate `ZipArchive` and call `$zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE )`. **Check the return value** — it must be strictly `=== true`. On a non-true return (integer error code such as `ZipArchive::ER_OPEN`, `ER_READ`, `ER_SEEK`): `@unlink( $tmp )`, `error_log()` the returned code at debug level, and fall through to step 6 (CSV fallback). No partial output is emitted — headers have not been sent yet.
    - Add `history.json` — `wp_json_encode( $records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )`.
    - Add `history.csv` — header row + one data row per record, 8 columns matching the table.
    - For each record: if `cu_scanner_json_<job_id>` option is a non-empty string, add `scans/<job_id>.json` (raw stored string); otherwise, track the `job_id` in a `$missing_snapshots` array to list in README.
    - Add `README.txt` containing: plugin version, ISO 8601 export timestamp, total record count, and — if `$missing_snapshots` is non-empty — a `Missing snapshots:` line with the comma-separated `job_id`s so the user knows which rows had no stored payload.
    - Call `$zip->close()`. **Check the return value** — must be strictly `=== true`. On false: `@unlink( $tmp )`, log, fall through to step 6.
    - Send headers: `Content-Type: application/zip`, `Content-Disposition: attachment; filename="ai-assets-scanner-history-YYYY-MM-DD-HHMMSS.zip"`, `Content-Length: <filesize($tmp)>`.
    - `readfile( $tmp )`.
    - `@unlink( $tmp )` — if unlink fails, `error_log()` at debug level (do not emit to the stream).
    - `exit;` — **not** `wp_die()`. Avoids third-party `wp_die_ajax_handler` filters appending content to the binary stream.
6. **Fallback — CSV only.** Reached when `ZipArchive` is unavailable OR the primary path fell through due to `open()`/`close()` failure:
    - Send `Content-Type: text/csv; charset=utf-8` + `Content-Disposition: attachment; filename="ai-assets-scanner-history-YYYY-MM-DD-HHMMSS.csv"`.
    - Echo the UTF-8 BOM (`\xEF\xBB\xBF`).
    - Stream CSV via `fputcsv( fopen( 'php://output', 'w' ), … )` — per-scan JSON snapshots are omitted (documented tradeoff).
    - `exit;`.

### CSV escaping

Use `fputcsv()` against `php://output` so PHP handles quoting of commas, quotes, and newlines per RFC 4180. No manual quote-escaping. UTF-8 BOM (`\xEF\xBB\xBF`) prepended so Excel opens the file correctly.

**Formula-injection defuse.** Before passing each cell value to `fputcsv`, if its first character is in `{=, +, -, @, \t (0x09), \r (0x0D)}`, prefix the value with a single quote `'`. This covers the OWASP CSV-injection set including the TAB and CR variants that some spreadsheet apps treat as formula starts. Implemented as one helper function used by every emitted cell.

### New AJAX action: `cu_scanner_delete_history`

Location: `admin/class-scanner-ajax.php` — new public method `delete_history()`.

**Request:** POST `admin-ajax.php` with body `action=cu_scanner_delete_history&nonce=<cu_scanner_nonce>`.

**Flow:**

1. `check_ajax_referer( 'cu_scanner_nonce', 'nonce' )`.
2. `current_user_can( 'manage_options' )` — `wp_send_json_error( [ 'message' => 'Forbidden' ], 403 )` if not.
3. Load records via `( new ScanHistory() )->get_all()`.
4. Delegate to `ScanHistory::delete_all()` (new helper — see next section) which performs the per-job + master-option deletions and returns the integer count of records removed.
5. `set_transient( 'cu_scanner_history_deleted_notice', $count, 30 )` — a 30-second one-time notice payload consumed on the next page render.
6. `wp_send_json_success( [ 'deleted' => $count ] )`.

### One-time admin notice on deletion

In `admin/class-admin-pages.php`, hook `admin_notices` on the history page hook only. The callback:
1. Reads `get_transient( 'cu_scanner_history_deleted_notice' )`.
2. If set (integer count), `delete_transient(...)` immediately (single-consume), then renders `<div class="notice notice-success is-dismissible"><p><?php printf( esc_html__( 'History deleted (%d records).', 'cu-scanner' ), (int) $count ); ?></p></div>`.
3. Does not persist across reloads — a second refresh after delete shows no notice.

### New helper method on `ScanHistory`

To keep AJAX handlers thin and match the existing class's ownership of history storage, add `ScanHistory::delete_all(): int` — returns the number of records deleted. The AJAX handler calls this instead of reaching into options directly. Unit-testable in isolation.

## Front-end

### New file: `admin/js/history.js` (~40 lines)

```js
(function ($) {
    $(function () {
        var $export = $('#cu-history-export');
        var $delete = $('#cu-history-delete');

        $export.on('click', function (e) {
            e.preventDefault();
            var url = cuScannerHistory.ajaxUrl
                + '?action=cu_scanner_export_history'
                + '&nonce=' + encodeURIComponent(cuScannerHistory.nonce);
            $export.prop('disabled', true);
            window.location.href = url;
            setTimeout(function () { $export.prop('disabled', false); }, 2000);
        });

        $delete.on('click', function (e) {
            e.preventDefault();
            var ok = window.confirm(cuScannerHistory.deleteWarning);
            if (!ok) return;
            $delete.prop('disabled', true);
            $.post(cuScannerHistory.ajaxUrl, {
                action: 'cu_scanner_delete_history',
                nonce: cuScannerHistory.nonce
            }).done(function () {
                window.location.reload();
            }).fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
                    || 'Failed to delete history.';
                window.alert(msg);
                $delete.prop('disabled', false);
            });
        });
    });
})(jQuery);
```

### Enqueue + localize

In `admin/class-admin-pages.php`, detect the history page hook suffix and enqueue `admin/js/history.js` only there (matches the existing per-page enqueue pattern — this pattern is asserted but not verified in this review; implementation plan must confirm against `scanner.js` / `settings.js` enqueue site):

```php
wp_enqueue_script(
    'cu-scanner-history',
    CU_SCANNER_URL . 'admin/js/history.js',
    [ 'jquery' ],          // script uses $ and $.post — jQuery is a required dep
    CU_SCANNER_VERSION,
    true                   // enqueue in footer
);
```

Then pass a `cuScannerHistory` object via `wp_localize_script`:

```php
wp_localize_script( 'cu-scanner-history', 'cuScannerHistory', [
    'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
    'nonce'         => wp_create_nonce( 'cu_scanner_nonce' ),
    'deleteWarning' => __( "⚠ This will permanently delete all scan history AND all stored scan JSON snapshots. Re-download links will stop working for old scans.\n\nDid you export a backup first?\n\nClick OK to delete everything, or Cancel to abort.", 'cu-scanner' ),
] );
```

## Security (WP compliance)

Will be re-validated by the `wp-compliance` skill at the start of the implementation plan (per global rule P10). Summary of guarantees this design makes:

- **Nonce:** both handlers verify `cu_scanner_nonce` via `check_ajax_referer`.
- **Capability:** both handlers gate on `current_user_can( 'manage_options' )`.
- **User input:** export takes no user input beyond the nonce; delete takes no user input beyond the nonce. No SQL injection surface; `delete_option` is safe.
- **Output escaping in view:** button attrs via `esc_attr`; localized script values are strings (jQuery `.text()`/`.val()` context on the JS side, never `.html()`).
- **CSV injection:** records contain `domain`, `status`, etc. — to defuse spreadsheet-formula injection (CVE-class for CSV exports), prefix any cell whose first character is in `{=, +, -, @, \t (0x09), \r (0x0D)}` with a single quote `'` before writing. Includes the TAB and CR variants from OWASP's extended list. Documented in the implementation plan as a discrete task.
- **Nonce in GET URL:** export uses `window.location.href = admin-ajax.php?action=cu_scanner_export_history&nonce=…`, so the nonce lands in the browser URL bar, session history, and possibly Referer / server access logs. Accepted risk because: (a) the action is read-only and idempotent, (b) WP nonce lifetime is 24 h, (c) the stolen-nonce replay window is bounded by that TTL, and (d) the alternative — auto-submitting a hidden POST form — adds DOM complexity that exceeds the risk reduction for a backup-export action gated behind `manage_options`.
- **Path traversal:** ZIP filenames are `history.json`, `history.csv`, `README.txt`, and `scans/<job_id>.json`. `job_id` comes from our own stored records (UUID-shaped, created server-side). Defensively, strip anything that isn't `[A-Za-z0-9._-]` before concatenation.
- **Temp file cleanup:** explicit `@unlink( $tmp )` at every exit branch of the primary path (success readfile, `open()` failure, `close()` failure) — see §Server design step 5. Each branch is a distinct code path, so inline unlink per branch is clearer than a wrapping `try/finally`.
- **Headers:** all `header()` calls before any output; no stray whitespace from included files.

## Testing

Add PHPUnit+WP_Mock tests alongside the existing `tests/ScanHistoryTest.php`:

1. **`tests/ScanHistoryDeleteAllTest.php`** — unit test on `ScanHistory::delete_all()`:
    - Three records → `delete_option` called 3 times on per-job keys + once on `cu_scanner_history`; return value is 3.
    - Empty history → returns 0; `delete_option('cu_scanner_history')` still called (idempotent).
2. **`tests/ExportHistoryAjaxTest.php`** — AJAX handler:
    - Missing nonce → `WPDieException` via mocked `check_ajax_referer`.
    - Insufficient cap → `wp_die` 403.
    - Empty history → 200 plain text, no ZIP emitted.
    - Happy path with fixture records + mocked `ZipArchive`: assert added file list `[history.json, history.csv, scans/<id1>.json, scans/<id2>.json, README.txt]`.
    - CSV-injection-defuse: records whose cells start with `=`, `+`, `-`, `@`, `\t`, or `\r` produce output cells prefixed with a single quote.
    - CSV has UTF-8 BOM as its first 3 bytes (`0xEF 0xBB 0xBF`).
    - `ZipArchive::open()` failure simulation (mocked to return `ZipArchive::ER_OPEN` instead of `true`) → falls through to CSV-only response with `Content-Type: text/csv; charset=utf-8`, temp file unlinked, no zero-byte ZIP written.
    - `ZipArchive` class-absent simulation (injected via `class_exists` shim or dependency-wrapped check) → CSV-only response streamed correctly, no `ZipArchive` instantiation attempted.
    - Record with missing/empty `cu_scanner_json_<job_id>` option → that `job_id` appears in README's "Missing snapshots:" line and no file appears under `scans/` for it.
    - Temp file cleanup: `unlink` is invoked on success path AND on `open()`-failure fallback path.
3. **`tests/DeleteHistoryAjaxTest.php`** — AJAX handler:
    - Missing nonce → failure.
    - Insufficient cap → 403.
    - Happy path: asserts `delete_all()` is called once; asserts `set_transient('cu_scanner_history_deleted_notice', $count, 30)` is called with the returned count; asserts `wp_send_json_success` with `deleted` count.
    - Admin-notice hook: calling the `admin_notices` callback once with the transient set renders the success notice markup AND deletes the transient (second call renders nothing).

All tests follow the existing WP_Mock pattern from `tests/ScanHistoryTest.php` (mock `get_option` / `update_option` / `delete_option`).

## Files touched

| File | Change |
| --- | --- |
| `admin/views/history-page.php` | Add toolbar markup + button elements with IDs `cu-history-export` and `cu-history-delete`. |
| `admin/class-scanner-ajax.php` | Register 2 new `wp_ajax_*` actions in constructor array; implement `export_history()` + `delete_history()` methods. |
| `admin/class-admin-pages.php` | Enqueue `admin/js/history.js` on the history page only; `wp_localize_script` with nonce + warning copy. |
| `admin/js/history.js` | **New** — ~40 lines, two click handlers. |
| `includes/class-scan-history.php` | Add `delete_all(): int` method. |
| `tests/ScanHistoryDeleteAllTest.php` | **New** — unit test. |
| `tests/ExportHistoryAjaxTest.php` | **New** — AJAX handler test. |
| `tests/DeleteHistoryAjaxTest.php` | **New** — AJAX handler test. |
| `ai-assets-scanner.php` | Bump `Version:` header + `CU_SCANNER_VERSION` constant from `1.2.0e` → `1.2.0f`. |
| `readme.txt` (if present) | Add changelog entry under `== Changelog ==`. |

## Acceptance criteria

1. On `?page=cu-scanner-history` with ≥1 record, the two `<button type="button">` elements render above the table inside `.cu-history-actions`; with 0 records, no buttons render and the existing "No scans yet" message is unchanged.
2. Clicking **Export to ZIP** triggers a file download named `ai-assets-scanner-history-YYYY-MM-DD-HHMMSS.zip` containing `history.json`, `history.csv`, `README.txt`, and one `scans/<job_id>.json` per record that has a stored non-empty snapshot.
3. The exported `history.csv` (both standalone and inside the ZIP) satisfies these mechanically-assertable properties: first 3 bytes are `0xEF 0xBB 0xBF` (UTF-8 BOM); field quoting comes from `fputcsv` (RFC 4180-compliant); no emitted cell's first character is in `{=, +, -, @, \t, \r}` — any such cell carries a single-quote prefix.
4. Clicking **Delete all history** shows the warning dialog; Cancel is a no-op; OK deletes every history record + every `cu_scanner_json_<job_id>` option, then the page reloads.
5. Both handlers reject requests missing a valid `cu_scanner_nonce` or lacking `manage_options`.
6. When `ZipArchive` is unavailable OR `ZipArchive::open()`/`close()` returns non-`true`, the handler falls through to a CSV-only response with `Content-Type: text/csv; charset=utf-8` and a `.csv` filename. No 500, no zero-byte ZIP.
7. After a successful delete, the reloaded page shows a `notice notice-success is-dismissible` admin notice reading "History deleted (N records)." A second reload does not re-render that notice (transient is single-consume).
8. For every record whose `cu_scanner_json_<job_id>` option is missing or empty, `README.txt` inside the ZIP lists that `job_id` under a "Missing snapshots:" line, and no corresponding file appears under `scans/`.
9. Temp files created via `wp_tempnam` are unlinked on both the success path and every failure path (open-fail, close-fail). Tests assert `unlink` is invoked.
10. All new unit tests pass alongside the existing baseline (baseline failures from prior MVP work remain out of scope per project memory).
11. `wp-compliance` skill sign-off at the end of implementation.

## Risks & open questions

- **ZipArchive absence:** uncommon on modern WP hosts (PHP 8.0+ requirement already on the plugin) but not guaranteed. The CSV fallback keeps the button functional but loses snapshots. Acceptable — the warning copy on delete implicitly assumes export succeeded, and CSV-only still represents "history exported."
- **`ZipArchive::open()` failure (not absence):** class exists but `open()` returns an integer error code (filesystem permissions, disk full, path collision with `wp_tempnam`). Explicit non-true check + fall-through to CSV is the design. Not a residual risk; listed here for completeness.
- **Large snapshots:** CU JSON per scan can be sizable. 10 scans × large JSON could push ZIP memory usage. `ZipArchive::addFile` streams from disk, but we'd need to `file_put_contents` each snapshot to a temp file first to use `addFile` instead of `addFromString`. For v1 we accept `addFromString` (loads all snapshots into memory transiently) — MAX_RECORDS=10 caps the worst case. Revisit only if users report memory errors.
- **Nonce in GET URL (export):** accepted — see Security section.
- **Assumptions to verify at plan time (not blocking this spec):**
    - `admin/class-scanner-ajax.php` uses a constructor action-array pattern for `wp_ajax_*` registration — spec relies on this to add 2 new actions in the same place. If the pattern is different, the plan will describe the actual shape; the 2 handlers are added either way.
    - `admin/class-admin-pages.php` has a per-page enqueue site keyed on hook suffix — plan will locate and match the existing pattern (precedent exists: `scanner.js` and `settings.js` are enqueued per-page today).
    - `wp_tempnam()` produces a path that `ZipArchive::open(..., CREATE|OVERWRITE)` can successfully replace. `OVERWRITE` is explicitly required because `wp_tempnam` creates a 0-byte placeholder. If `open()` refuses the pre-existing file on some host, the `open()` return-value check catches it and the CSV fallback fires.
    - The `⚠` character (U+26A0) in the warning copy survives the `__()` → `wp_localize_script` → browser round trip. This works as long as the PHP source file is saved UTF-8 **without** BOM (standard for WP plugin files). Plan verifies source encoding.
- **No open questions blocking implementation.**
