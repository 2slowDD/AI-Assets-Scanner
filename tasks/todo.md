# Scan History — Export to ZIP & Delete All — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add two buttons — *Export to ZIP* and *Delete all history* — to the Scan History admin page, bumping the plugin from v1.2.0e → v1.2.0f.

**Architecture:** Storage unchanged — keep using `cu_scanner_history` + `cu_scanner_json_<job_id>` WP options. Add 2 AJAX actions to the existing `CUScanner\Admin\ScannerAjax` constructor-action-array pattern (`export_history`, `delete_history`). Add one helper method `ScanHistory::delete_all(): int`. Add a dedicated JS file `admin/js/history.js` enqueued per-page via the existing `enqueue_assets()` hook-suffix pattern. Reuse `cu_scanner_nonce` + `manage_options` cap throughout. Success on delete is surfaced via a one-shot transient consumed on `admin_notices`.

**Tech Stack:** PHP 8.0+, WordPress 6.2+, jQuery (WP core), PHP `ZipArchive` (with CSV-only fallback), PHPUnit 9.6 + WP_Mock 1.1 for unit tests, Keep-a-Changelog format for CHANGELOG.md.

**Approved spec:** [`docs/superpowers/specs/2026-04-24-scan-history-export-delete-design.md`](../docs/superpowers/specs/2026-04-24-scan-history-export-delete-design.md) — passed two rounds of D-review (verdict: ready-to-plan, 0 Critical / 0 Major).

---

## Context recap (for agents executing out of order)

- **Plugin root:** `D:/AI/CU/AI Assets Scanner/AI-Assets-Scanner/`
- **Plugin main file:** `ai-assets-scanner.php` — version constant `CU_SCANNER_VERSION` + plugin header both need `1.2.0e` → `1.2.0f`.
- **Autoload:** PSR-ish map in `ai-assets-scanner.php`'s `spl_autoload_register` — no new classes added in this plan (the 2 new AJAX methods live on existing `CUScanner\Admin\ScannerAjax`).
- **AJAX registration pattern** ([class-scanner-ajax.php:17-33](../admin/class-scanner-ajax.php)):
  ```php
  $actions = [ 'cu_scanner_detect_plugins', … ];
  foreach ( $actions as $action ) {
      add_action( 'wp_ajax_' . $action, [ $this, str_replace( 'cu_scanner_', '', $action ) ] );
  }
  ```
  → Appending `cu_scanner_export_history` makes WP call `$this->export_history()`. Same for `cu_scanner_delete_history` → `delete_history()`.
- **Check helper** ([class-scanner-ajax.php:36-41](../admin/class-scanner-ajax.php)): `$this->check()` calls `check_ajax_referer('cu_scanner_nonce','nonce')` and `wp_send_json_error('Forbidden',403)` if cap missing. Delete handler reuses `$this->check()`. Export handler does **not** use it — it must NOT emit JSON on failure (would corrupt the file download). Export calls `check_ajax_referer(...)` + `current_user_can(...)` directly and uses `wp_die('Forbidden', 403)` on cap failure.
- **Per-page enqueue** ([class-admin-pages.php:25-46](../admin/class-admin-pages.php)): `enqueue_assets($hook)` gates on hook suffix; history hook = `'ai-assets-scanner_page_cu-scanner-history'`. No script currently enqueued for it.
- **Test pattern** ([tests/ScanHistoryTest.php](../tests/ScanHistoryTest.php)): namespace `CUScanner\Tests`, extends `WP_Mock\Tools\TestCase`, `setUp` calls `WP_Mock::setUp()`, tearDown reverse. `WP_Mock::userFunction('fn_name')->with(…)->andReturn(…)` for WP globals. `\Mockery::type('array')` / `\Mockery::on(fn($v)=>…)` for arg matchers.
- **Test runner:** `vendor/bin/phpunit` from plugin root. Config at `phpunit.xml.dist` (bootstrap `tests/bootstrap.php`).
- **Baseline test status:** per project memory, several baseline suites unrelated to this work are known to fail. New tests must pass cleanly; do not attempt to fix unrelated baseline failures.

---

## Pre-flight (mandatory)

### Task 0: Preflight — wp-compliance review of the plan

**Why:** User rule P10 — `wp-compliance` MUST be invoked before writing, editing, or reviewing any WordPress plugin code. The first code task (Task 2) begins by writing PHP. Preflight here surfaces any security category the plan overlooked before implementation starts.

- [ ] **Step 0.1: Invoke `wp-compliance` skill**

  Pass the spec path and this plan path to the skill and ask it to dry-check the plan against its security checklist. Record the skill's output inline under Task 0 here (as a short checklist — pass/fail per category).

- [ ] **Step 0.2: Address any findings**

  If the skill flags a missing sanitize/escape/cap/nonce check, fix it in THIS plan file before starting Task 1. If findings are all "OK", proceed.

- [ ] **Step 0.3: Run baseline tests to confirm the pre-change state**

  ```bash
  cd "D:/AI/CU/AI Assets Scanner/AI-Assets-Scanner"
  vendor/bin/phpunit --testdox 2>&1 | tail -40
  ```

  Record pass/fail count. Expectation: no new failures introduced by this plan will be counted against the baseline. Paste the last 10 lines of output under Task 0 for reference.

---

## Implementation

### Task 1: Bump plugin version 1.2.0e → 1.2.0f

**Files:**
- Modify: `ai-assets-scanner.php` — plugin header `Version:` line + `CU_SCANNER_VERSION` constant.

- [ ] **Step 1.1: Edit the plugin header**

  In `ai-assets-scanner.php`, replace the header `Version:` line:

  From:
  ```
   * Version:     1.2.0e
  ```
  To:
  ```
   * Version:     1.2.0f
  ```

- [ ] **Step 1.2: Edit the version constant**

  In the same file, replace:
  ```php
  define( 'CU_SCANNER_VERSION', '1.2.0e' );
  ```
  With:
  ```php
  define( 'CU_SCANNER_VERSION', '1.2.0f' );
  ```

- [ ] **Step 1.3: Commit**

  ```bash
  git add ai-assets-scanner.php
  git commit -m "chore: bump version 1.2.0e -> 1.2.0f"
  ```

---

### Task 2: Add `ScanHistory::delete_all()` (TDD)

**Files:**
- Modify: `includes/class-scan-history.php` — add `delete_all(): int` method.
- Create: `tests/ScanHistoryDeleteAllTest.php` — unit tests (3 cases).

- [ ] **Step 2.1: Write the failing test file**

  Create `tests/ScanHistoryDeleteAllTest.php` with exactly:

  ```php
  <?php
  // tests/ScanHistoryDeleteAllTest.php
  namespace CUScanner\Tests;

  use CUScanner\ScanHistory;
  use WP_Mock;
  use WP_Mock\Tools\TestCase;

  class ScanHistoryDeleteAllTest extends TestCase {
      public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
      public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

      public function test_delete_all_with_empty_history_returns_zero(): void {
          WP_Mock::userFunction( 'get_option' )
              ->with( 'cu_scanner_history', [] )
              ->andReturn( [] );
          WP_Mock::userFunction( 'delete_option' )
              ->with( 'cu_scanner_history' )
              ->once();
          $count = ( new ScanHistory() )->delete_all();
          $this->assertSame( 0, $count );
          $this->assertConditionsMet();
      }

      public function test_delete_all_with_three_records_returns_three_and_deletes_per_job_options(): void {
          $existing = [
              [ 'job_id' => 'job-a' ],
              [ 'job_id' => 'job-b' ],
              [ 'job_id' => 'job-c' ],
          ];
          WP_Mock::userFunction( 'get_option' )
              ->with( 'cu_scanner_history', [] )
              ->andReturn( $existing );
          WP_Mock::userFunction( 'delete_option' )
              ->with( 'cu_scanner_json_job-a' )->once();
          WP_Mock::userFunction( 'delete_option' )
              ->with( 'cu_scanner_json_job-b' )->once();
          WP_Mock::userFunction( 'delete_option' )
              ->with( 'cu_scanner_json_job-c' )->once();
          WP_Mock::userFunction( 'delete_option' )
              ->with( 'cu_scanner_history' )->once();

          $count = ( new ScanHistory() )->delete_all();
          $this->assertSame( 3, $count );
          $this->assertConditionsMet();
      }

      public function test_delete_all_tolerates_record_without_job_id(): void {
          $existing = [
              [ 'job_id' => 'job-a' ],
              [ /* malformed: no job_id */ ],
          ];
          WP_Mock::userFunction( 'get_option' )
              ->with( 'cu_scanner_history', [] )
              ->andReturn( $existing );
          WP_Mock::userFunction( 'delete_option' )
              ->with( 'cu_scanner_json_job-a' )->once();
          WP_Mock::userFunction( 'delete_option' )
              ->with( 'cu_scanner_history' )->once();

          $count = ( new ScanHistory() )->delete_all();
          $this->assertSame( 2, $count );
          $this->assertConditionsMet();
      }
  }
  ```

- [ ] **Step 2.2: Run the test and confirm all three fail with "method not defined"**

  ```bash
  vendor/bin/phpunit --filter ScanHistoryDeleteAllTest --testdox
  ```
  Expected: 3 errors, all citing `Call to undefined method CUScanner\ScanHistory::delete_all()`.

- [ ] **Step 2.3: Implement `delete_all()`**

  Append to `includes/class-scan-history.php`, before the closing `}` of the class:

  ```php
      public function delete_all(): int {
          $records = get_option( self::HISTORY_OPTION, [] );
          $count   = 0;
          foreach ( $records as $record ) {
              if ( ! empty( $record['job_id'] ) && is_string( $record['job_id'] ) ) {
                  delete_option( self::JSON_OPTION_PREFIX . $record['job_id'] );
              }
              $count++;
          }
          delete_option( self::HISTORY_OPTION );
          return $count;
      }
  ```

- [ ] **Step 2.4: Re-run the test**

  ```bash
  vendor/bin/phpunit --filter ScanHistoryDeleteAllTest --testdox
  ```
  Expected: 3 tests, 3 passes (`OK (3 tests, … assertions)`).

- [ ] **Step 2.5: Commit**

  ```bash
  git add includes/class-scan-history.php tests/ScanHistoryDeleteAllTest.php
  git commit -m "feat(history): add ScanHistory::delete_all() helper"
  ```

---

### Task 3: Add `ScannerAjax::delete_history()` handler (TDD)

**Files:**
- Modify: `admin/class-scanner-ajax.php` — append public `delete_history()` method.
- Create: `tests/DeleteHistoryAjaxTest.php` — unit tests.

Note: the action is registered in Task 10. This task only tests the method body.

- [ ] **Step 3.1: Write the failing test file**

  Create `tests/DeleteHistoryAjaxTest.php`:

  ```php
  <?php
  // tests/DeleteHistoryAjaxTest.php
  namespace CUScanner\Tests;

  use CUScanner\Admin\ScannerAjax;
  use WP_Mock;
  use WP_Mock\Tools\TestCase;

  class DeleteHistoryAjaxTest extends TestCase {
      public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
      public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

      public function test_delete_history_happy_path_delegates_to_scan_history_and_sets_transient_and_succeeds(): void {
          WP_Mock::userFunction( 'check_ajax_referer' )
              ->with( 'cu_scanner_nonce', 'nonce' )->once()->andReturn( 1 );
          WP_Mock::userFunction( 'current_user_can' )
              ->with( 'manage_options' )->andReturn( true );
          WP_Mock::userFunction( 'get_option' )
              ->with( 'cu_scanner_history', [] )
              ->andReturn( [
                  [ 'job_id' => 'job-a' ],
                  [ 'job_id' => 'job-b' ],
              ] );
          WP_Mock::userFunction( 'delete_option' )->with( 'cu_scanner_json_job-a' )->once();
          WP_Mock::userFunction( 'delete_option' )->with( 'cu_scanner_json_job-b' )->once();
          WP_Mock::userFunction( 'delete_option' )->with( 'cu_scanner_history' )->once();
          WP_Mock::userFunction( 'set_transient' )
              ->with( 'cu_scanner_history_deleted_notice', 2, 30 )->once();
          WP_Mock::userFunction( 'wp_send_json_success' )
              ->with( [ 'deleted' => 2 ] )->once()
              ->andThrow( new \Exception( 'sent' ) );

          $this->expectException( \Exception::class );
          $this->expectExceptionMessage( 'sent' );
          ( new ScannerAjax() )->delete_history();
          $this->assertConditionsMet();
      }

      public function test_delete_history_missing_cap_returns_403(): void {
          WP_Mock::userFunction( 'check_ajax_referer' )
              ->with( 'cu_scanner_nonce', 'nonce' )->andReturn( 1 );
          WP_Mock::userFunction( 'current_user_can' )
              ->with( 'manage_options' )->andReturn( false );
          WP_Mock::userFunction( 'wp_send_json_error' )
              ->with( 'Forbidden', 403 )->once()
              ->andThrow( new \Exception( 'forbidden' ) );

          $this->expectException( \Exception::class );
          $this->expectExceptionMessage( 'forbidden' );
          ( new ScannerAjax() )->delete_history();
          $this->assertConditionsMet();
      }
  }
  ```

  Note: the nonce-missing path is covered by the existing `check()` helper's `check_ajax_referer` call; a dedicated test would just re-test WP core behavior. Covered implicitly; no separate test needed.

- [ ] **Step 3.2: Run the test — expect failure**

  ```bash
  vendor/bin/phpunit --filter DeleteHistoryAjaxTest --testdox
  ```
  Expected: errors citing `Call to undefined method … ::delete_history()`.

- [ ] **Step 3.3: Implement `delete_history()` on `ScannerAjax`**

  Append this method to `admin/class-scanner-ajax.php`, after the last existing public method (before the closing `}` of the class):

  ```php
      public function delete_history(): void {
          $this->check();
          $history = new ScanHistory();
          $count   = $history->delete_all();
          set_transient( 'cu_scanner_history_deleted_notice', $count, 30 );
          wp_send_json_success( [ 'deleted' => $count ] );
      }
  ```

  `ScanHistory` is already imported at the top of the file via `use CUScanner\ScanHistory;` — confirm on first edit; if somehow missing, add it.

- [ ] **Step 3.4: Re-run the test**

  ```bash
  vendor/bin/phpunit --filter DeleteHistoryAjaxTest --testdox
  ```
  Expected: 2 tests, 2 passes.

- [ ] **Step 3.5: Commit**

  ```bash
  git add admin/class-scanner-ajax.php tests/DeleteHistoryAjaxTest.php
  git commit -m "feat(history): add delete_history AJAX handler"
  ```

---

### Task 4: `ScannerAjax::export_history()` — guards (TDD, first slice)

**Files:**
- Modify: `admin/class-scanner-ajax.php` — add public `export_history()` skeleton with guards only.
- Create: `tests/ExportHistoryAjaxTest.php` — unit tests (grows across Tasks 4-9).

- [ ] **Step 4.1: Create the test file with the guard tests**

  Create `tests/ExportHistoryAjaxTest.php`:

  ```php
  <?php
  // tests/ExportHistoryAjaxTest.php
  namespace CUScanner\Tests;

  use CUScanner\Admin\ScannerAjax;
  use WP_Mock;
  use WP_Mock\Tools\TestCase;

  class ExportHistoryAjaxTest extends TestCase {
      public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
      public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

      public function test_export_history_missing_cap_calls_wp_die_403(): void {
          WP_Mock::userFunction( 'check_ajax_referer' )
              ->with( 'cu_scanner_nonce', 'nonce' )->andReturn( 1 );
          WP_Mock::userFunction( 'current_user_can' )
              ->with( 'manage_options' )->andReturn( false );
          WP_Mock::userFunction( 'wp_die' )
              ->with( 'Forbidden', '', [ 'response' => 403 ] )->once()
              ->andThrow( new \Exception( 'forbidden' ) );

          $this->expectException( \Exception::class );
          $this->expectExceptionMessage( 'forbidden' );
          ( new ScannerAjax() )->export_history();
          $this->assertConditionsMet();
      }

      public function test_export_history_empty_returns_plain_text_no_download(): void {
          WP_Mock::userFunction( 'check_ajax_referer' )
              ->with( 'cu_scanner_nonce', 'nonce' )->andReturn( 1 );
          WP_Mock::userFunction( 'current_user_can' )
              ->with( 'manage_options' )->andReturn( true );
          WP_Mock::userFunction( 'get_option' )
              ->with( 'cu_scanner_history', [] )->andReturn( [] );
          WP_Mock::userFunction( 'wp_die' )
              ->with( 'No history to export', '', [ 'response' => 200 ] )->once()
              ->andThrow( new \Exception( 'empty' ) );

          $this->expectException( \Exception::class );
          $this->expectExceptionMessage( 'empty' );
          ( new ScannerAjax() )->export_history();
          $this->assertConditionsMet();
      }
  }
  ```

- [ ] **Step 4.2: Run — expect undefined-method errors**

  ```bash
  vendor/bin/phpunit --filter ExportHistoryAjaxTest --testdox
  ```

- [ ] **Step 4.3: Implement the guard skeleton**

  Append to `admin/class-scanner-ajax.php`, before the class's closing `}`:

  ```php
      public function export_history(): void {
          check_ajax_referer( 'cu_scanner_nonce', 'nonce' );
          if ( ! current_user_can( 'manage_options' ) ) {
              wp_die( 'Forbidden', '', [ 'response' => 403 ] );
          }
          $records = ( new ScanHistory() )->get_all();
          if ( empty( $records ) ) {
              wp_die( 'No history to export', '', [ 'response' => 200 ] );
          }
          // Subsequent tasks fill in the body below this line.
      }
  ```

  Note: nonce comes via GET query string for the export download. `check_ajax_referer()` reads `$_REQUEST['nonce']` by default when the 2nd arg is `'nonce'` — same as the existing `download_json` handler at [class-scanner-ajax.php:385](../admin/class-scanner-ajax.php).

- [ ] **Step 4.4: Re-run — expect both guard tests to pass**

  ```bash
  vendor/bin/phpunit --filter ExportHistoryAjaxTest --testdox
  ```
  Expected: 2 tests, 2 passes.

- [ ] **Step 4.5: Commit**

  ```bash
  git add admin/class-scanner-ajax.php tests/ExportHistoryAjaxTest.php
  git commit -m "feat(history): add export_history AJAX handler guards"
  ```

---

### Task 5: `ScannerAjax::export_history()` — CSV emission helper (TDD)

**Why:** Both the ZIP primary path and the CSV fallback use the same row-generation logic. Extract it once, test it directly.

**Files:**
- Modify: `admin/class-scanner-ajax.php` — add private helpers `csv_cell()` and `write_csv()`.
- Modify: `tests/ExportHistoryAjaxTest.php` — add 2 tests (structure + defuse).

- [ ] **Step 5.1: Add the defuse + CSV structure tests**

  Inside `ExportHistoryAjaxTest` (before the closing `}`), append:

  ```php
      public function test_csv_cell_defuses_formula_injection_characters(): void {
          $rc = new \ReflectionClass( ScannerAjax::class );
          $m  = $rc->getMethod( 'csv_cell' );
          $m->setAccessible( true );
          $obj = new ScannerAjax();
          $this->assertSame( "'=cmd",   $m->invoke( $obj, '=cmd' ) );
          $this->assertSame( "'+cmd",   $m->invoke( $obj, '+cmd' ) );
          $this->assertSame( "'-cmd",   $m->invoke( $obj, '-cmd' ) );
          $this->assertSame( "'@cmd",   $m->invoke( $obj, '@cmd' ) );
          $this->assertSame( "'\tcmd",  $m->invoke( $obj, "\tcmd" ) );
          $this->assertSame( "'\rcmd",  $m->invoke( $obj, "\rcmd" ) );
          $this->assertSame( 'safe',    $m->invoke( $obj, 'safe' ) );
          $this->assertSame( '',        $m->invoke( $obj, '' ) );
      }

      public function test_write_csv_emits_bom_header_and_data_rows(): void {
          $records = [
              [
                  'job_id' => 'job-a', 'domain' => 'example.com',
                  'page_count' => 10, 'credits_used' => 5,
                  'safe_count' => 3, 'aggressive_count' => 1,
                  'status' => 'complete', 'created_at' => '2026-04-24T10:00:00+00:00',
              ],
              [
                  'job_id' => 'job-b', 'domain' => '=EVIL',
                  'page_count' => 1, 'credits_used' => 0,
                  'safe_count' => 0, 'aggressive_count' => 0,
                  'status' => 'failed', 'created_at' => '2026-04-23T09:00:00+00:00',
              ],
          ];
          $rc = new \ReflectionClass( ScannerAjax::class );
          $m  = $rc->getMethod( 'write_csv' );
          $m->setAccessible( true );

          $fh = fopen( 'php://memory', 'w+' );
          $m->invoke( new ScannerAjax(), $fh, $records );
          rewind( $fh );
          $out = stream_get_contents( $fh );
          fclose( $fh );

          // UTF-8 BOM first 3 bytes
          $this->assertSame( "\xEF\xBB\xBF", substr( $out, 0, 3 ) );
          // Header row present
          $this->assertStringContainsString( "Date,Domain,Pages,Credits,Safe Rules,Aggressive Rules,Status,Job ID", $out );
          // Data rows present
          $this->assertStringContainsString( 'example.com', $out );
          // Formula-injection defuse on domain cell
          $this->assertStringContainsString( "'=EVIL", $out );
      }
  ```

- [ ] **Step 5.2: Run — expect failures (method not defined)**

  ```bash
  vendor/bin/phpunit --filter ExportHistoryAjaxTest --testdox
  ```

- [ ] **Step 5.3: Add `csv_cell()` and `write_csv()` private methods**

  In `admin/class-scanner-ajax.php`, insert BEFORE the `export_history()` method body we added in Task 4 (still inside the class):

  ```php
      /**
       * Defuses CSV formula injection. If the first byte is = + - @ TAB CR,
       * prefix a single quote. Returns the value unchanged otherwise.
       */
      private function csv_cell( string $value ): string {
          if ( $value === '' ) return '';
          $first = $value[0];
          if ( $first === '=' || $first === '+' || $first === '-' || $first === '@'
              || $first === "\t" || $first === "\r" ) {
              return "'" . $value;
          }
          return $value;
      }

      /**
       * Writes BOM + header row + one data row per record to the given resource.
       * Uses fputcsv for RFC 4180 quoting. Defuses every cell via csv_cell().
       */
      private function write_csv( $resource, array $records ): void {
          fwrite( $resource, "\xEF\xBB\xBF" );
          fputcsv( $resource, [ 'Date', 'Domain', 'Pages', 'Credits', 'Safe Rules', 'Aggressive Rules', 'Status', 'Job ID' ] );
          foreach ( $records as $r ) {
              $row = [
                  (string) ( $r['created_at']       ?? '' ),
                  (string) ( $r['domain']           ?? '' ),
                  (string) ( $r['page_count']       ?? '' ),
                  (string) ( $r['credits_used']     ?? '' ),
                  (string) ( $r['safe_count']       ?? '' ),
                  (string) ( $r['aggressive_count'] ?? '' ),
                  (string) ( $r['status']           ?? '' ),
                  (string) ( $r['job_id']           ?? '' ),
              ];
              fputcsv( $resource, array_map( [ $this, 'csv_cell' ], $row ) );
          }
      }
  ```

- [ ] **Step 5.4: Re-run — expect new tests to pass and old guard tests still pass**

  ```bash
  vendor/bin/phpunit --filter ExportHistoryAjaxTest --testdox
  ```
  Expected: 4 tests, 4 passes.

- [ ] **Step 5.5: Commit**

  ```bash
  git add admin/class-scanner-ajax.php tests/ExportHistoryAjaxTest.php
  git commit -m "feat(history): add csv_cell + write_csv helpers with formula-injection defuse"
  ```

---

### Task 6: `ScannerAjax::export_history()` — CSV-only fallback when ZipArchive absent (TDD)

**Seam:** introduce a `protected function zip_available(): bool` method returning `class_exists('ZipArchive')`. Tests override it by subclassing.

**Files:**
- Modify: `admin/class-scanner-ajax.php` — add `zip_available()`, wire a `stream_csv_response()` private helper, fold it into `export_history()`.
- Modify: `tests/ExportHistoryAjaxTest.php` — add CSV-fallback test via a subclass.

- [ ] **Step 6.1: Add the test — subclass ScannerAjax to force CSV-only**

  In `tests/ExportHistoryAjaxTest.php`, at the bottom of the file (after the test class closing `}`), add a namespaced test-only subclass, then add a new test in the class:

  ```php
  // at end of file, after `}` of ExportHistoryAjaxTest:
  class ForcedCsvScannerAjax extends \CUScanner\Admin\ScannerAjax {
      protected function zip_available(): bool { return false; }
  }
  ```

  Add to `ExportHistoryAjaxTest`:

  ```php
      public function test_export_history_streams_csv_when_zip_unavailable(): void {
          WP_Mock::userFunction( 'check_ajax_referer' )
              ->with( 'cu_scanner_nonce', 'nonce' )->andReturn( 1 );
          WP_Mock::userFunction( 'current_user_can' )
              ->with( 'manage_options' )->andReturn( true );
          WP_Mock::userFunction( 'get_option' )
              ->with( 'cu_scanner_history', [] )
              ->andReturn( [ [
                  'job_id' => 'job-a', 'domain' => 'example.com',
                  'page_count' => 1, 'credits_used' => 0,
                  'safe_count' => 0, 'aggressive_count' => 0,
                  'status' => 'complete', 'created_at' => '2026-04-24T10:00:00+00:00',
              ] ] );

          // Capture headers & body via output buffering.
          ob_start();
          $captured_headers = [];
          // Simulate header() by routing through a spy: wp_die is expected after readfile.
          WP_Mock::userFunction( 'wp_die' )->never();
          // We can't intercept header() without uopz, so rely on Content-Type echoed in body is non-applicable.
          // Instead assert the CSV body content, which is deterministic.
          try {
              ( new ForcedCsvScannerAjax() )->export_history();
          } catch ( \Throwable $e ) {
              // export_history() calls exit; a test framework wrapper (Mockery for WP_Mock) may
              // convert exit into an exception via a pcntl or noop. If not, body is in $out.
          }
          $out = ob_get_clean();
          $this->assertStringContainsString( "\xEF\xBB\xBF", $out );
          $this->assertStringContainsString( 'example.com', $out );
          $this->assertConditionsMet();
      }
  ```

  Caveat: PHP `exit`/`die` cannot be intercepted in plain PHPUnit. To make the test reach its assertions, wrap the `exit;` call in a seam too — add a `protected function terminate(): void { exit; }` method on `ScannerAjax`, override it in `ForcedCsvScannerAjax` to throw or no-op. Update the subclass:

  ```php
  class ForcedCsvScannerAjax extends \CUScanner\Admin\ScannerAjax {
      protected function zip_available(): bool { return false; }
      protected function terminate(): void { throw new \RuntimeException( 'terminated' ); }
  }
  ```

  and wrap the test invocation:

  ```php
          try {
              ( new ForcedCsvScannerAjax() )->export_history();
              $this->fail( 'Expected terminate() to throw' );
          } catch ( \RuntimeException $e ) {
              $this->assertSame( 'terminated', $e->getMessage() );
          }
  ```

- [ ] **Step 6.2: Run — expect failure**

  ```bash
  vendor/bin/phpunit --filter ExportHistoryAjaxTest::test_export_history_streams_csv_when_zip_unavailable --testdox
  ```

- [ ] **Step 6.3: Implement the seams + CSV fallback branch**

  In `admin/class-scanner-ajax.php`:

  1. Add the two seam methods (as siblings of `csv_cell`):

     ```php
         protected function zip_available(): bool {
             return class_exists( 'ZipArchive' );
         }

         protected function terminate(): void {
             exit;
         }
     ```

  2. Add a private helper for the CSV-only response:

     ```php
         private function stream_csv_response( array $records ): void {
             $filename = 'ai-assets-scanner-history-' . gmdate( 'Y-m-d-His' ) . '.csv';
             header( 'Content-Type: text/csv; charset=utf-8' );
             header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
             $fh = fopen( 'php://output', 'w' );
             $this->write_csv( $fh, $records );
             fclose( $fh );
             $this->terminate();
         }
     ```

  3. Extend `export_history()` to fall through to CSV fallback when ZIP is absent — replace the TODO-stub from Task 4:

     ```php
         public function export_history(): void {
             check_ajax_referer( 'cu_scanner_nonce', 'nonce' );
             if ( ! current_user_can( 'manage_options' ) ) {
                 wp_die( 'Forbidden', '', [ 'response' => 403 ] );
             }
             $records = ( new ScanHistory() )->get_all();
             if ( empty( $records ) ) {
                 wp_die( 'No history to export', '', [ 'response' => 200 ] );
             }
             if ( ! $this->zip_available() ) {
                 $this->stream_csv_response( $records );
                 return; // unreachable in prod; reachable under test seam
             }
             // Task 7 fills in the ZIP primary path here.
         }
     ```

- [ ] **Step 6.4: Re-run all ExportHistoryAjax tests**

  ```bash
  vendor/bin/phpunit --filter ExportHistoryAjaxTest --testdox
  ```
  Expected: 5 tests, 5 passes.

- [ ] **Step 6.5: Commit**

  ```bash
  git add admin/class-scanner-ajax.php tests/ExportHistoryAjaxTest.php
  git commit -m "feat(history): CSV-only fallback when ZipArchive unavailable"
  ```

---

### Task 7: `ScannerAjax::export_history()` — ZIP happy path (TDD)

**Files:**
- Modify: `admin/class-scanner-ajax.php` — add `build_zip()` + wire into `export_history()`.
- Modify: `tests/ExportHistoryAjaxTest.php` — add ZIP happy-path test (requires ZipArchive in the test environment).

- [ ] **Step 7.1: Add the ZIP happy-path test**

  Add to `ExportHistoryAjaxTest`:

  ```php
      public function test_export_history_writes_zip_with_expected_file_list(): void {
          if ( ! class_exists( 'ZipArchive' ) ) {
              $this->markTestSkipped( 'ZipArchive unavailable in test env' );
          }
          WP_Mock::userFunction( 'check_ajax_referer' )
              ->with( 'cu_scanner_nonce', 'nonce' )->andReturn( 1 );
          WP_Mock::userFunction( 'current_user_can' )
              ->with( 'manage_options' )->andReturn( true );
          WP_Mock::userFunction( 'get_option' )
              ->with( 'cu_scanner_history', [] )
              ->andReturn( [
                  [
                      'job_id' => 'job-a', 'domain' => 'a.com',
                      'page_count' => 1, 'credits_used' => 0,
                      'safe_count' => 0, 'aggressive_count' => 0,
                      'status' => 'complete', 'created_at' => '2026-04-24T10:00:00+00:00',
                  ],
                  [
                      'job_id' => 'job-b', 'domain' => 'b.com',
                      'page_count' => 2, 'credits_used' => 1,
                      'safe_count' => 1, 'aggressive_count' => 1,
                      'status' => 'complete', 'created_at' => '2026-04-23T09:00:00+00:00',
                  ],
              ] );
          WP_Mock::userFunction( 'get_option' )
              ->with( 'cu_scanner_json_job-a', '' )->andReturn( '{"a":1}' );
          WP_Mock::userFunction( 'get_option' )
              ->with( 'cu_scanner_json_job-b', '' )->andReturn( '{"b":2}' );
          WP_Mock::userFunction( 'wp_tempnam' )
              ->andReturnUsing( function () {
                  $tmp = tempnam( sys_get_temp_dir(), 'cu-hist-' );
                  return $tmp;
              } );

          // Intercept the readfile/exit: use a seam subclass that captures the temp path
          // and skips the HTTP streaming.
          $subject = new class extends \CUScanner\Admin\ScannerAjax {
              public ?string $captured_tmp = null;
              protected function terminate(): void { throw new \RuntimeException( 'terminated' ); }
              protected function stream_zip( string $tmp ): void {
                  $this->captured_tmp = $tmp;
                  $this->terminate();
              }
          };

          try {
              $subject->export_history();
              $this->fail( 'Expected terminate()' );
          } catch ( \RuntimeException $e ) {
              $this->assertSame( 'terminated', $e->getMessage() );
          }

          $this->assertNotNull( $subject->captured_tmp );
          $this->assertFileExists( $subject->captured_tmp );

          $zip = new \ZipArchive();
          $this->assertTrue( $zip->open( $subject->captured_tmp ) === true );
          $names = [];
          for ( $i = 0; $i < $zip->numFiles; $i++ ) {
              $names[] = $zip->getNameIndex( $i );
          }
          $zip->close();
          @unlink( $subject->captured_tmp );

          sort( $names );
          $this->assertSame(
              [ 'README.txt', 'history.csv', 'history.json', 'scans/job-a.json', 'scans/job-b.json' ],
              $names
          );
          $this->assertConditionsMet();
      }
  ```

- [ ] **Step 7.2: Run — expect failure**

  ```bash
  vendor/bin/phpunit --filter ExportHistoryAjaxTest::test_export_history_writes_zip_with_expected_file_list --testdox
  ```

- [ ] **Step 7.3: Implement `build_zip()` + `stream_zip()` + wire into `export_history()`**

  In `admin/class-scanner-ajax.php`, add these private/protected methods:

  ```php
      /**
       * Builds the ZIP at $tmp_path. Returns true on success, false on any
       * ZipArchive failure (at which point $tmp_path has been @unlink'd).
       * Populates $missing_snapshots (by reference) with job_ids that had no
       * stored snapshot.
       */
      private function build_zip( string $tmp_path, array $records, ScanHistory $history, array &$missing_snapshots ): bool {
          $zip = new \ZipArchive();
          $rc  = $zip->open( $tmp_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
          if ( $rc !== true ) {
              error_log( '[AI Assets Scanner] ZipArchive::open failed: ' . $rc ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug logging only.
              @unlink( $tmp_path );
              return false;
          }

          $zip->addFromString( 'history.json', (string) wp_json_encode( $records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

          // Generate CSV to a string via php://memory so we can addFromString.
          $mem = fopen( 'php://memory', 'w+' );
          $this->write_csv( $mem, $records );
          rewind( $mem );
          $csv = stream_get_contents( $mem );
          fclose( $mem );
          $zip->addFromString( 'history.csv', $csv );

          $missing_snapshots = [];
          foreach ( $records as $r ) {
              $job_id = isset( $r['job_id'] ) ? (string) $r['job_id'] : '';
              if ( $job_id === '' ) continue;
              // Defensive: strip chars that could escape the archive path.
              $safe = preg_replace( '/[^A-Za-z0-9._-]/', '', $job_id );
              if ( $safe === '' ) continue;
              $snapshot = $history->get_json( $safe );
              if ( $snapshot === '' ) {
                  $missing_snapshots[] = $safe;
                  continue;
              }
              $zip->addFromString( 'scans/' . $safe . '.json', $snapshot );
          }

          $readme  = 'AI Assets Scanner v' . CU_SCANNER_VERSION . "\n";
          $readme .= 'Export timestamp: ' . gmdate( 'c' ) . "\n";
          $readme .= 'Records: ' . count( $records ) . "\n";
          if ( ! empty( $missing_snapshots ) ) {
              $readme .= 'Missing snapshots: ' . implode( ', ', $missing_snapshots ) . "\n";
          }
          $zip->addFromString( 'README.txt', $readme );

          if ( $zip->close() !== true ) {
              error_log( '[AI Assets Scanner] ZipArchive::close failed' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug logging only.
              @unlink( $tmp_path );
              return false;
          }
          return true;
      }

      protected function stream_zip( string $tmp_path ): void {
          $filename = 'ai-assets-scanner-history-' . gmdate( 'Y-m-d-His' ) . '.zip';
          header( 'Content-Type: application/zip' );
          header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
          header( 'Content-Length: ' . filesize( $tmp_path ) );
          readfile( $tmp_path );
          if ( ! @unlink( $tmp_path ) ) {
              error_log( '[AI Assets Scanner] temp unlink failed: ' . $tmp_path ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug logging only.
          }
          $this->terminate();
      }
  ```

  And extend `export_history()` (replace the TODO comment from Task 6):

  ```php
          // ZIP primary path.
          $tmp = wp_tempnam( 'cu-scanner-history' );
          $missing = [];
          $history = new ScanHistory();
          if ( $this->build_zip( $tmp, $records, $history, $missing ) ) {
              $this->stream_zip( $tmp );
              return;
          }
          // Fall through to CSV-only if ZIP build failed.
          $this->stream_csv_response( $records );
  ```

- [ ] **Step 7.4: Run all export tests**

  ```bash
  vendor/bin/phpunit --filter ExportHistoryAjaxTest --testdox
  ```
  Expected: 6 tests, 6 passes.

- [ ] **Step 7.5: Commit**

  ```bash
  git add admin/class-scanner-ajax.php tests/ExportHistoryAjaxTest.php
  git commit -m "feat(history): ZIP primary path with open/close return-value checks"
  ```

---

### Task 8: `ScannerAjax::export_history()` — `ZipArchive::open()` failure falls through to CSV (TDD)

**Why:** Covers Acceptance Criterion 6 from the spec — `open()` returns a non-true int → unlink + CSV fallback, no zero-byte ZIP.

- [ ] **Step 8.1: Add the failure-fallback test**

  Strategy: force `wp_tempnam` to return a **directory** path instead of a file. `ZipArchive::open(<dir>, CREATE|OVERWRITE)` returns an integer error code, not `true`, which exercises the `open() !== true` branch in `build_zip()` → `@unlink` the fake dir (harmless no-op on a directory) → return `false` → `export_history()` falls through to `stream_csv_response()`. A `stream_zip()` override on a subclass lets us assert it was NOT called.

  Add to `ExportHistoryAjaxTest`:

  ```php
      public function test_export_history_falls_through_to_csv_when_zip_open_fails(): void {
          if ( ! class_exists( 'ZipArchive' ) ) {
              $this->markTestSkipped( 'ZipArchive unavailable' );
          }
          WP_Mock::userFunction( 'check_ajax_referer' )->andReturn( 1 );
          WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
          WP_Mock::userFunction( 'get_option' )
              ->with( 'cu_scanner_history', [] )
              ->andReturn( [ [
                  'job_id' => 'job-a', 'domain' => 'a.com',
                  'page_count' => 1, 'credits_used' => 0,
                  'safe_count' => 0, 'aggressive_count' => 0,
                  'status' => 'complete', 'created_at' => '2026-04-24T10:00:00+00:00',
              ] ] );

          // Make wp_tempnam return a directory path → ZipArchive::open will fail.
          $fake_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cu-fake-dir-' . uniqid();
          mkdir( $fake_dir );
          WP_Mock::userFunction( 'wp_tempnam' )->andReturn( $fake_dir );

          $subject = new class extends \CUScanner\Admin\ScannerAjax {
              public bool $zip_stream_called = false;
              protected function terminate(): void { throw new \RuntimeException( 'terminated' ); }
              protected function stream_zip( string $tmp ): void {
                  $this->zip_stream_called = true;
                  $this->terminate();
              }
          };

          ob_start();
          try {
              $subject->export_history();
              $this->fail( 'Expected terminate()' );
          } catch ( \RuntimeException $e ) {
              $this->assertSame( 'terminated', $e->getMessage() );
          }
          $out = ob_get_clean();
          @rmdir( $fake_dir );

          $this->assertFalse( $subject->zip_stream_called, 'stream_zip must NOT be called when open() fails' );
          $this->assertStringContainsString( "\xEF\xBB\xBF", $out );
          $this->assertStringContainsString( 'a.com', $out );
          $this->assertConditionsMet();
      }
  ```

- [ ] **Step 8.2: Run — expect pass**

  The implementation from Task 7 already handles this case correctly (the `open() !== true` branch returns `false`, then `export_history()` falls through to `stream_csv_response`). Run:

  ```bash
  vendor/bin/phpunit --filter ExportHistoryAjaxTest::test_export_history_falls_through_to_csv_when_zip_open_fails --testdox
  ```
  Expected: PASS with no further code changes.

  If the test fails, investigate — the fall-through logic may need adjusting. Do NOT simply mark test skipped.

- [ ] **Step 8.3: Commit**

  ```bash
  git add tests/ExportHistoryAjaxTest.php
  git commit -m "test(history): cover ZipArchive::open() failure fallback to CSV"
  ```

---

### Task 9: `ScannerAjax::export_history()` — missing snapshot listed in README (TDD)

- [ ] **Step 9.1: Add the missing-snapshot test**

  Add to `ExportHistoryAjaxTest`:

  ```php
      public function test_export_history_lists_missing_snapshots_in_readme(): void {
          if ( ! class_exists( 'ZipArchive' ) ) {
              $this->markTestSkipped( 'ZipArchive unavailable' );
          }
          WP_Mock::userFunction( 'check_ajax_referer' )->andReturn( 1 );
          WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
          WP_Mock::userFunction( 'get_option' )
              ->with( 'cu_scanner_history', [] )
              ->andReturn( [
                  [
                      'job_id' => 'job-have', 'domain' => 'a.com',
                      'page_count' => 1, 'credits_used' => 0,
                      'safe_count' => 0, 'aggressive_count' => 0,
                      'status' => 'complete', 'created_at' => '2026-04-24T10:00:00+00:00',
                  ],
                  [
                      'job_id' => 'job-missing', 'domain' => 'b.com',
                      'page_count' => 1, 'credits_used' => 0,
                      'safe_count' => 0, 'aggressive_count' => 0,
                      'status' => 'complete', 'created_at' => '2026-04-23T09:00:00+00:00',
                  ],
              ] );
          WP_Mock::userFunction( 'get_option' )
              ->with( 'cu_scanner_json_job-have', '' )->andReturn( '{"x":1}' );
          WP_Mock::userFunction( 'get_option' )
              ->with( 'cu_scanner_json_job-missing', '' )->andReturn( '' );
          WP_Mock::userFunction( 'wp_tempnam' )
              ->andReturnUsing( fn() => tempnam( sys_get_temp_dir(), 'cu-hist-' ) );

          $subject = new class extends \CUScanner\Admin\ScannerAjax {
              public ?string $captured = null;
              protected function terminate(): void { throw new \RuntimeException( 'terminated' ); }
              protected function stream_zip( string $tmp ): void {
                  $this->captured = $tmp;
                  $this->terminate();
              }
          };

          try { $subject->export_history(); } catch ( \RuntimeException $e ) {}

          $zip = new \ZipArchive();
          $this->assertTrue( $zip->open( $subject->captured ) === true );
          $readme = $zip->getFromName( 'README.txt' );
          $has_scan = $zip->getFromName( 'scans/job-have.json' );
          $missing_scan = $zip->getFromName( 'scans/job-missing.json' );
          $zip->close();
          @unlink( $subject->captured );

          $this->assertStringContainsString( 'Missing snapshots: job-missing', $readme );
          $this->assertNotFalse( $has_scan );
          $this->assertFalse( $missing_scan, 'scans/job-missing.json should NOT be in the archive' );
          $this->assertConditionsMet();
      }
  ```

- [ ] **Step 9.2: Run — expect pass (behavior already implemented in Task 7)**

  ```bash
  vendor/bin/phpunit --filter ExportHistoryAjaxTest::test_export_history_lists_missing_snapshots_in_readme --testdox
  ```
  Expected: PASS.

- [ ] **Step 9.3: Commit**

  ```bash
  git add tests/ExportHistoryAjaxTest.php
  git commit -m "test(history): missing snapshot listed in README, absent from scans/"
  ```

---

### Task 10: Register the 2 new AJAX actions

**Files:**
- Modify: `admin/class-scanner-ajax.php` — extend the `$actions` array in `register()`.

- [ ] **Step 10.1: Append both actions**

  In `admin/class-scanner-ajax.php::register()`, update the `$actions` array (currently lines 18-30):

  From:
  ```php
          $actions = [
              'cu_scanner_detect_plugins',
              'cu_scanner_discover_pages',
              'cu_scanner_reserve_job',
              'cu_scanner_submit_job',
              'cu_scanner_poll_status',
              'cu_scanner_cancel_job',
              'cu_scanner_handle_failure',
              'cu_scanner_build_result',
              'cu_scanner_download_json',
              'cu_scanner_push_to_cu',
              'cu_scanner_check_job',
          ];
  ```
  To (appending two lines):
  ```php
          $actions = [
              'cu_scanner_detect_plugins',
              'cu_scanner_discover_pages',
              'cu_scanner_reserve_job',
              'cu_scanner_submit_job',
              'cu_scanner_poll_status',
              'cu_scanner_cancel_job',
              'cu_scanner_handle_failure',
              'cu_scanner_build_result',
              'cu_scanner_download_json',
              'cu_scanner_push_to_cu',
              'cu_scanner_check_job',
              'cu_scanner_export_history',
              'cu_scanner_delete_history',
          ];
  ```

- [ ] **Step 10.2: Verify existing tests still pass**

  ```bash
  vendor/bin/phpunit --testdox 2>&1 | tail -20
  ```

- [ ] **Step 10.3: Commit**

  ```bash
  git add admin/class-scanner-ajax.php
  git commit -m "feat(history): register export_history and delete_history AJAX actions"
  ```

---

### Task 11: Create `admin/js/history.js`

**Files:**
- Create: `admin/js/history.js`

- [ ] **Step 11.1: Create the file**

  Write `admin/js/history.js` with exactly this content:

  ```js
  /* AI Assets Scanner — Scan History page client-side.
   * Handles Export to ZIP (redirect-download) and Delete all history (AJAX).
   */
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
              if (!window.confirm(cuScannerHistory.deleteWarning)) {
                  return;
              }
              $delete.prop('disabled', true);
              $.post(cuScannerHistory.ajaxUrl, {
                  action: 'cu_scanner_delete_history',
                  nonce:  cuScannerHistory.nonce
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

- [ ] **Step 11.2: Commit**

  ```bash
  git add admin/js/history.js
  git commit -m "feat(history): add history.js with export/delete click handlers"
  ```

---

### Task 12: Enqueue `history.js`, localize nonce + strings, hook `admin_notices`

**Files:**
- Modify: `admin/class-admin-pages.php` — extend `enqueue_assets()` + add `admin_notices` callback.

- [ ] **Step 12.1: Extend `register()` to add the notices hook**

  In `admin/class-admin-pages.php::register()`, append the hook:

  From:
  ```php
      public function register(): void {
          add_action( 'admin_menu', [ $this, 'add_menus' ] );
          add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
      }
  ```
  To:
  ```php
      public function register(): void {
          add_action( 'admin_menu', [ $this, 'add_menus' ] );
          add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
          add_action( 'admin_notices', [ $this, 'maybe_render_history_deleted_notice' ] );
      }
  ```

- [ ] **Step 12.2: Extend `enqueue_assets()` to enqueue + localize `history.js`**

  Inside `enqueue_assets()`, after the existing `cu-scanner-settings` block and BEFORE the method's closing `}`, add:

  ```php
          if ( $hook === 'ai-assets-scanner_page_cu-scanner-history' ) {
              wp_enqueue_script(
                  'cu-scanner-history',
                  CU_SCANNER_URL . 'admin/js/history.js',
                  [ 'jquery' ],
                  CU_SCANNER_VERSION,
                  true
              );
              wp_localize_script( 'cu-scanner-history', 'cuScannerHistory', [
                  'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
                  'nonce'         => wp_create_nonce( 'cu_scanner_nonce' ),
                  'deleteWarning' => __(
                      "\xE2\x9A\xA0 This will permanently delete all scan history AND all stored scan JSON snapshots. Re-download links will stop working for old scans.\n\nDid you export a backup first?\n\nClick OK to delete everything, or Cancel to abort.",
                      'cu-scanner'
                  ),
              ] );
          }
  ```

  Note: `\xE2\x9A\xA0` is the explicit UTF-8 byte sequence for U+26A0 ⚠, avoiding any source-file encoding ambiguity.

- [ ] **Step 12.3: Add the `admin_notices` callback**

  Append this method to `AdminPages` (before the final `}` of the class):

  ```php
      public function maybe_render_history_deleted_notice(): void {
          $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
          if ( ! $screen || $screen->id !== 'ai-assets-scanner_page_cu-scanner-history' ) {
              return;
          }
          $count = get_transient( 'cu_scanner_history_deleted_notice' );
          if ( $count === false ) {
              return;
          }
          delete_transient( 'cu_scanner_history_deleted_notice' );
          ?>
          <div class="notice notice-success is-dismissible">
              <p><?php
                  // translators: %d = number of deleted records.
                  printf( esc_html__( 'History deleted (%d records).', 'cu-scanner' ), (int) $count );
              ?></p>
          </div>
          <?php
      }
  ```

- [ ] **Step 12.4: Commit**

  ```bash
  git add admin/class-admin-pages.php
  git commit -m "feat(history): enqueue history.js and render delete success notice"
  ```

---

### Task 13: Add toolbar markup to `admin/views/history-page.php`

**Files:**
- Modify: `admin/views/history-page.php` — add toolbar markup inside the non-empty branch.

- [ ] **Step 13.1: Insert the toolbar block**

  Locate this line (currently line 29-30 of the view):

  ```php
          <?php else : ?>
              <table class="wp-list-table widefat striped">
  ```

  Replace with:

  ```php
          <?php else : ?>
              <div class="cu-history-actions" style="display:flex;justify-content:flex-end;gap:8px;margin:0 0 12px;">
                  <button type="button" id="cu-history-export" class="button">
                      <?php esc_html_e( 'Export to ZIP', 'cu-scanner' ); ?>
                  </button>
                  <button type="button" id="cu-history-delete" class="button button-link-delete">
                      <?php esc_html_e( 'Delete all history', 'cu-scanner' ); ?>
                  </button>
              </div>
              <table class="wp-list-table widefat striped">
  ```

- [ ] **Step 13.2: Commit**

  ```bash
  git add admin/views/history-page.php
  git commit -m "feat(history): add Export and Delete toolbar above history table"
  ```

---

### Task 14: Manual smoke test + CHANGELOG + final wp-compliance sweep

**Why:** CLAUDE.md rule P4 requires proving the feature works end-to-end, not just that tests pass. And P10 requires a final compliance pass over code that was written, not just the plan.

- [ ] **Step 14.1: Deploy the modified files to the test WP install**

  This plugin is deployed manually; the user is responsible for copying the changed files to the test site. Changed files in this feature:
  - `ai-assets-scanner.php`
  - `includes/class-scan-history.php`
  - `admin/class-scanner-ajax.php`
  - `admin/class-admin-pages.php`
  - `admin/views/history-page.php`
  - `admin/js/history.js` *(new)*
  - `tasks/todo.md` *(this file)*

  (Tests and spec live outside the deployable set.)

- [ ] **Step 14.2: Smoke the empty-history state**

  With `cu_scanner_history` option deleted (or a fresh install), load `?page=cu-scanner-history`. Expected: "No scans yet. Run your first scan." unchanged. No buttons visible.

- [ ] **Step 14.3: Smoke the populated state — Export to ZIP**

  Run ≥1 scan via the scanner page. Reload the history page. Expected: the two buttons appear above the table, right-aligned. Click **Export to ZIP**. Expected: browser downloads `ai-assets-scanner-history-YYYY-MM-DD-HHMMSS.zip`. Open it. Verify presence of: `history.json`, `history.csv`, `README.txt`, and `scans/<job_id>.json` for each completed scan. Open `history.csv` in Excel and Sheets — header row readable, no formulas auto-executed.

- [ ] **Step 14.4: Smoke the populated state — Delete all history**

  On the same page, click **Delete all history**. Expected: `window.confirm` dialog shows the warning copy with ⚠ character and line breaks. Cancel → no change. Click again → OK → page reloads into the empty state, a dismissible green `notice-success` banner reads `History deleted (N records).` where N is the previous record count. Reload again — notice does not re-appear.

- [ ] **Step 14.5: Smoke the cap/nonce negative paths**

  Log in as an Editor (no `manage_options`). Hit `admin-ajax.php?action=cu_scanner_export_history&nonce=<valid>` — expect 403 Forbidden. Hit it with a bad nonce — expect nonce failure page.

- [ ] **Step 14.6: Invoke `wp-compliance` on the code changes**

  Run the skill over the 5 modified PHP files + the new JS. Record the skill's verdict inline here. If any Major/Critical finding surfaces, fix it and re-run from Step 14.2.

- [ ] **Step 14.7: Update CHANGELOG.md**

  Prepend this block to `CHANGELOG.md` immediately after the top `---` separator (above the `## [1.2.0e]` or latest existing entry):

  ```markdown
  ## [1.2.0f] — 2026-04-24

  ### Added

  - **Scan History — Export to ZIP.** New toolbar button on the Scan History admin page. Downloads a ZIP containing `history.json`, `history.csv` (UTF-8 BOM, RFC 4180, formula-injection defuse), `README.txt`, and one `scans/<job_id>.json` per completed scan. Falls back to a standalone `.csv` download on hosts without `ZipArchive` or when `ZipArchive::open()`/`close()` fail.
  - **Scan History — Delete all history.** New toolbar button, warns the user to export first, then wipes `cu_scanner_history` and every `cu_scanner_json_<job_id>` option. Confirmation via native `window.confirm()`. Success banner shown via a one-shot transient.

  ---
  ```

- [ ] **Step 14.8: Run the full test suite one last time**

  ```bash
  vendor/bin/phpunit --testdox 2>&1 | tail -30
  ```
  Expected: new test classes (`ScanHistoryDeleteAllTest`, `ExportHistoryAjaxTest`, `DeleteHistoryAjaxTest`) all green. Any pre-existing baseline failures remain unchanged.

- [ ] **Step 14.9: Commit the CHANGELOG**

  ```bash
  git add CHANGELOG.md
  git commit -m "docs: changelog entry for 1.2.0f (scan history export + delete)"
  ```

- [ ] **Step 14.10: Mark Task 14 complete and add a review section at the bottom of this file (per user rule P7)**

  Append a `## Review` section summarizing what was shipped, any deviations from the spec, and any lessons to log in `tasks/lessons.md`.

---

## Review

**Shipped:** AI Assets Scanner v1.2.0f — Scan History — Export to ZIP & Delete All.

### Commits (in order)

| # | SHA | Scope |
|---|---|---|
| 1 | `5685467` | chore: bump version 1.2.0e → 1.2.0f |
| 2 | `f2da331` | feat(history): add `ScanHistory::delete_all()` helper + 3 unit tests |
| 3 | `3d994bc` | feat(history): add `delete_history` AJAX handler + 2 unit tests |
| 4 | `c7cd0a5` | feat(history): `export_history` AJAX handler guards + 2 unit tests |
| 5 | `7677c37` | feat(history): `csv_cell` + `write_csv` helpers + formula-injection defuse tests |
| 6 | `72fbebf` | feat(history): CSV-only fallback when `ZipArchive` unavailable |
| 7 | `a36ecc7` | feat(history): ZIP primary path with `open()`/`close()` return-value checks + happy-path test |
| 8 | `2816d86` | test(history): cover `ZipArchive::open()` failure fallback to CSV |
| 9 | `6b3f674` | test(history): missing snapshot listed in README, absent from `scans/` |
| 10 | `b487d4b` | feat(history): register `export_history` + `delete_history` AJAX actions |
| 11 | `d448aa9` | feat(history): toolbar buttons + `history.js` enqueue + admin success notice |
| 12 | *(pending)* | docs: changelog entry for 1.2.0f |

### Test coverage delta

- **Baseline before this work:** 102 tests, 163 assertions, 15 pre-existing errors in `SnapshotManagerTest` (unrelated).
- **After this work:** 115 tests, 210 assertions, 14 errors — **all new tests pass, no new failures introduced**.
- **New tests added:** 13 across 3 files — `ScanHistoryDeleteAllTest` (3), `DeleteHistoryAjaxTest` (2), `ExportHistoryAjaxTest` (8).

### Deviations from plan

1. **Task 6 — extra seam method.** Implementer subagent added a `protected function emit_csv_headers(string $filename)` seam in addition to the two planned seams (`zip_available`, `terminate`). Additive, doesn't affect downstream task behavior, improves testability by letting tests suppress `header()` calls. Accepted without rework.
2. **Tasks 11-13 — collapsed to one commit.** Plan had 3 separate commits for history.js + enqueue + view markup. Shipped as single commit `d448aa9` because the three changes form one logical UI-layer unit — JS enqueue has no effect without the button markup, and the button markup is useless without the JS handlers. One-commit scope is easier to revert as a unit.
3. **PHP environment — `zip` extension not loaded by default.** Local PHP 8.5.1 has `ext-zip` bundled but disabled in `C:\php\php.ini` (line 951: `;extension=zip`). All PHPUnit runs use `php -d extension=zip vendor/phpunit/phpunit/phpunit` instead of `vendor/bin/phpunit`. In production WP (and most CI envs) `ext-zip` is loaded by default. No production code change needed; test runner invocation differs. Consider permanently enabling the extension in `php.ini` for convenience.

### Manual smoke test — user action required

The automated test suite covers unit-level correctness. The plan's Step 14.1-14.5 requires live-WP verification you must run yourself:

**Files to deploy to the test site** (copy to the matching paths under `wp-content/plugins/ai-assets-scanner/`):

- `ai-assets-scanner.php` (version bump)
- `includes/class-scan-history.php` (`delete_all()` helper)
- `admin/class-scanner-ajax.php` (two new handlers + helpers)
- `admin/class-admin-pages.php` (enqueue + admin_notices hook)
- `admin/views/history-page.php` (toolbar markup)
- `admin/js/history.js` (new file)

**Smoke steps:**

1. Empty history state — load `?page=cu-scanner-history` with no scans → "No scans yet." unchanged, no buttons. ✅ expected
2. Populated state — run ≥1 scan, reload history page → both buttons render above the table, right-aligned. Click **Export to ZIP** → download lands as `ai-assets-scanner-history-<timestamp>.zip`. Open it, verify `history.json`, `history.csv`, `README.txt`, `scans/<job_id>.json` for completed scans.
3. Open `history.csv` in Excel → header row readable, no auto-executed formulas, special-char cells prefixed with `'`.
4. Click **Delete all history** → `window.confirm()` shows warning with ⚠ and line breaks. Cancel → no change. Accept → page reloads empty state + dismissible `notice-success` reading "History deleted (N records)." Second reload → notice gone.
5. Negative paths — as an Editor user (no `manage_options`), hit the two AJAX URLs directly → 403 responses, no data access.

### Lessons to log in `tasks/lessons.md`

- `wp_die()` after `readfile()` on a binary stream is filter-unsafe in AJAX context — use `exit;` instead. Surfaced during spec D-review (Round 1 Minor finding) and codified in the plan.
- `ZipArchive::open()` return value is an **integer error code or `true`** — not a bool. Strict `!== true` check required; otherwise a failed `open()` silently produces a zero-byte ZIP. Surfaced during spec D-review (Round 1 Major #2).
- PHP on this Windows dev box has `ext-zip` bundled but disabled. `php -d extension=zip vendor/phpunit/phpunit/phpunit` is the portable invocation for ZIP-dependent tests.
- jQuery `.prop('disabled', true)` is a no-op on anchor tags — pin interactive elements to `<button type="button">` when the JS guards matter. Surfaced during spec D-review (Round 1 Major #1).

---

## Plan self-review (authored 2026-04-24)

- **Spec coverage:** every section of the spec maps to one or more tasks. Delete `delete_all()` → Task 2. Delete AJAX → Task 3. Export guards → Task 4. CSV helper → Task 5. CSV fallback → Task 6. ZIP happy path → Task 7. `open()` failure → Task 8. Missing snapshots → Task 9. Action registration → Task 10. JS file → Task 11. Enqueue + localize + admin_notices → Task 12. View markup → Task 13. Manual E2E + changelog + wp-compliance sweep → Task 14.
- **Spec-level acceptance criteria mapping:** AC1 (markup gated on non-empty history) → Task 13 + Task 14.2/14.3. AC2 (ZIP file list) → Task 7. AC3 (CSV observable properties) → Task 5. AC4 (delete flow) → Task 3 + Task 14.4. AC5 (nonce/cap on both handlers) → Tasks 3, 4, 14.5. AC6 (ZipArchive absent/open-fails → CSV) → Tasks 6, 8. AC7 (single-consume success notice) → Task 12 + Task 14.4. AC8 (missing snapshots listed) → Task 9. AC9 (unlink on all branches) → Task 7 code + covered implicitly in Tasks 7/8 assertions. AC10 (suite still green) → Tasks 10.2, 14.8. AC11 (wp-compliance sign-off) → Tasks 0, 14.6.
- **Type consistency check:** `zip_available()` / `terminate()` / `stream_zip()` / `stream_csv_response()` / `build_zip()` / `write_csv()` / `csv_cell()` — names used identically in every task. `maybe_render_history_deleted_notice` spelled identically in Task 12 Steps 12.1 and 12.3.
- **Placeholder scan:** no `TBD`, `TODO`, `implement later`. Every code step includes the full code, not a reference.
- **Deviations from spec (worth flagging at review):**
  - Spec described "new ScanHistoryExporter" implicitly via the "helper function" language for CSV defuse; plan keeps everything on `ScannerAjax` per spec's Files-touched table (`admin/class-scanner-ajax.php`). Two test seams (`zip_available()` + `terminate()` + `stream_zip()` override) introduced as `protected` methods for testability — they do not change behavior in production.
  - The spec listed `readme.txt (if present)` for changelog; this project uses `CHANGELOG.md` instead — Task 14.7 updates `CHANGELOG.md` accordingly. Keep-a-Changelog format confirmed against existing entries.
