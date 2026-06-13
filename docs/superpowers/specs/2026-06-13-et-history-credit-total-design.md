# AAS — Scan-History "Credits" total under-counts Extra-Time scans (design)

**Date:** 2026-06-13
**Version target:** 1.7.31b → 1.7.32b
**Scope:** 1 PHP file + tests + CHANGELOG. No SaaS/worker changes.

## Problem

The admin **Scan History** table's **Credits** column shows `1` for an ET (Extra-Time)
continuation scan that was actually billed `2` credits. Confirmed live on wpservice.pro
(scan `c092bf59`, page-hash `2ad7726e353f`, 2026-06-13 08:10 — SaaS ledger = 2 credits,
plugin history = 1).

## Root cause (code-confirmed)

- Credits cell renders `$record['credits_used']` — `admin/views/history-page.php:51`.
- `credits_used` is set at scan completion to `billable_page_count($pages_raw)` —
  `admin/class-scanner-ajax.php:743-748`.
- `billable_page_count()` (`class-scanner-ajax.php:564-566`) **counts** non-`error`/
  non-`origin_unavailable` pages. It is **ET-blind** — ignores `extra_time_charged`.
- The *per-URL* Step-4 Credits column is already correct: it uses
  `AIAS_Scan_Status::classify()`, ET-fixed on 2026-06-02 (`class-scan-status.php:29-32`).
  **That fix patched the per-URL cell but missed the history summary.**
- The cancel path already records the authoritative Railway `pages_completed`
  (`class-scanner-ajax.php:632`). The *complete* path is the lone ET-blind divergence.

## Fix (Option B — approved)

Replace the page-count with a **sum of the per-page credit** computed by the same
`classify()` rule that drives the per-URL column. Rename to match new semantics.

```php
public static function billable_credit_total( array $pages_raw ): int {
    return array_sum( array_map(
        fn( $p ) => \AIAS_Scan_Status::classify( (array) $p )['credits'],
        $pages_raw
    ) );
}
```

Update the single caller (`class-scanner-ajax.php:743`).

**Guarantees:** history Credits == sum of Step-4 per-URL Credits column == SaaS-billed
amount, by construction. No duplicated ET credit logic (DRY — reuses the tested rule).

## Why not Option A (Railway `pages_completed`)

`$status['pages_completed']` is not referenced at the complete path; would require
verifying Railway exposes it there + handling absence on older responses. More risk,
no benefit — Option B already equals the billed number in every observed case.

## Test impact

- `tests/ScannerAjaxTest.php:278-286` `test_billable_count_excludes_origin_unavailable`:
  fixture `[done, done, origin_unavailable, error]` → classify-sum `1+1+0+0 = 2`. Still
  passes. Rename the method reference; keep assertion.
- **Add** `test_billable_credit_total_adds_extra_time_charged`: 1 `done` page with
  `extra_time_charged:true` → `2`. (Mirrors `ScanStatusTest` ET cases at the summary level.)
- Error+ET edge: classify gives error+ET = `1` (et_credit only — established by
  `test_extra_time_charged_error_shows_et_credit_only`). New behavior aligns history with
  that decision; previously such pages contributed `0`. Backfill-safe: missing flag → `0` ET.

## Acceptance

- AC-1: ET continuation scan's history Credits == base + ET (e.g. 2 for 1 ET page).
- AC-2: non-ET scan history Credits unchanged (== billable page count).
- AC-3: existing suite green; new ET-summary test green.
- AC-4 (live): re-scan an ET-triggering URL on wpservice.pro → history shows 2.
