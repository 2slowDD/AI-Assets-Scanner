# AI Assets Scanner v1.2.0b — Error Surfacing + Version Constant Fix (Design)

**Status:** Approved by user 2026-04-22
**Date:** 2026-04-22
**Scope:** Plugin-only patch (no Railway / SaaS / CU-Scanner changes)
**Release target:** `2slowDD/AI-Assets-Scanner` `main` + tag `v1.2.0b`

---

## 1. Background

During Sub-spec B rollout, first real test scan from the live-production wpservice.pro pipeline failed. Plugin showed opaque error: `"Could not submit scan job. Check server error logs."` Railway HTTP Logs tab eventually revealed the cause — **POST /jobs returned 401** — but that required the operator to know Railway's dashboard has an HTTP Logs tab, navigate to it, and correlate timestamps. The plugin-side error was operationally useless.

Separately noticed: the plugin's file header reports `Version: 1.2.0` (what WP Plugins dashboard displays) but the `CU_SCANNER_VERSION` constant at `ai-assets-scanner.php:18` still holds `'1.1.5'`. This constant powers the internal admin banner. Commit `ce3f311 chore: bump to 1.2.0 (mandatory update — Railway requires job_token post 2026-04-20 deploy)` bumped the header but missed the constant.

Both issues are plugin-side and fixable in a single patch.

---

## 2. Goals / Non-goals

**Goals**

- Surface HTTP status + first 50 chars of response body in scan-submission errors, so operators can diagnose 4xx/5xx from Railway or SaaS without leaving wp-admin.
- Fix the `CU_SCANNER_VERSION` constant drift (unify with plugin header at `1.2.0b`).
- Ship as plugin version `1.2.0b` — a post-B marker aligned with Sub-spec B's merge window. Other repos (Railway, SaaS, CU-Scanner) are NOT synced to this version at this time (user directive: independent version bumps).

**Non-goals**

- Fix the 401 root cause. This patch just makes 401s (and any future non-2xx) diagnosable. Root cause diagnosis happens AFTER this ships and the retry exposes the actual response body.
- Touch the happy path — successful scan submission flow is unchanged.
- Any localization / i18n work on the error string (out of scope).
- Any rename / structural refactor of `class-scanner-ajax.php`.
- Troubleshooting hints / "common causes" blocks appended to the error. Operators can Google error codes; prescriptive hints rot as infra evolves.

---

## 3. Architecture

Three file edits + one test extension, all within the plugin repo. No external dependencies change.

```
ai-assets-scanner.php           (header + CU_SCANNER_VERSION constant)
admin/class-scanner-ajax.php    (error-message block, ~line 195)
readme.txt                      (Stable tag + changelog entry)
tests/ScannerAjaxTest.php       (new test case for error surfacing)
```

---

## 4. Components

### 4.1 Version bump (`ai-assets-scanner.php`)

Two edits in the same file:

- File header comment block, line 5:
  ```php
   * Version:     1.2.0b
  ```
  Was: `* Version:     1.2.0`
- `CU_SCANNER_VERSION` constant, line 18:
  ```php
  define( 'CU_SCANNER_VERSION', '1.2.0b' );
  ```
  Was: `define( 'CU_SCANNER_VERSION', '1.1.5' );`

Rationale: `1.2.0b` as a suffixed beta/B marker. `version_compare( '1.2.0', '1.2.0b', '<' )` returns `true` in PHP — so any existing `>= 1.2.0` check still passes, and any `>= 1.2.0b` check distinguishes this release.

### 4.2 Error surfacing (`admin/class-scanner-ajax.php` line ~195)

Replace the single-line opaque error:

```php
wp_send_json_error( 'Could not submit scan job. Check server error logs.' );
```

With a status-aware block that distinguishes the two failure shapes (`WP_Error` network-level vs. `array` HTTP-level response):

```php
$http_status = is_wp_error( $response )
    ? 0
    : (int) wp_remote_retrieve_response_code( $response );

$body_snippet = is_wp_error( $response )
    ? $response->get_error_message()
    : mb_substr( (string) wp_remote_retrieve_body( $response ), 0, 50 );

$ellipsis = ( ! is_wp_error( $response ) && strlen( (string) wp_remote_retrieve_body( $response ) ) > 50 )
    ? '…'
    : '';

wp_send_json_error( sprintf(
    /* translators: 1: HTTP status code, 2: truncated response body, 3: ellipsis if truncated */
    'Scan submission failed: HTTP %1$d. Response: %2$s%3$s',
    $http_status,
    $body_snippet,
    $ellipsis
) );
```

**Why this shape:**

- `is_wp_error` branches cover A) network/transport failures (DNS, timeout, TLS) where `wp_remote_post` returns `WP_Error`, and B) HTTP non-2xx responses where `wp_remote_post` returns an `array` with `response.code` and `body`. Both surfaces land in the same error handler.
- `mb_substr(..., 0, 50)` is multi-byte-safe. If the body is ASCII JSON (typical), behaves as byte-slice. If it contains UTF-8, cut happens at character boundary.
- 50-char window is deliberately short — tokens/secrets in typical error bodies are 32–64 chars and would not fit in 50 chars plus structural JSON before getting truncated. Enough to read `{"code":"token_invalid","message":"no such tok...` → operator sees the error code class without leaking an echoed secret.
- Ellipsis `…` is a single Unicode char; safe for wp_send_json_error which JSON-encodes the payload.
- HTTP status `0` indicates WP_Error (no HTTP response at all). Status `200` would never reach this block (it's inside the failure branch). Status `4xx/5xx` is what we want to display.

**Context:** the surrounding code in `class-scanner-ajax.php` handles the `wp_remote_post` result variable (likely `$response`). If the variable name differs, implementer adapts. If the error block is inside a try/catch pattern rather than an if/else, implementer adapts.

### 4.3 Changelog (`readme.txt`)

Two line edits:

- `Stable tag: 1.2.0b` (was `1.2.0`)
- Add at top of changelog section:
  ```
  = 1.2.0b =
  * Fix: CU_SCANNER_VERSION constant no longer drifts from plugin header (was 1.1.5).
  * Improve: Scan-submission errors now surface HTTP status + response snippet
    instead of generic "Check server error logs".
  ```

### 4.4 Test (`tests/ScannerAjaxTest.php`)

One new test case:

```php
public function test_submit_job_failure_surfaces_http_status_and_body_snippet() {
    // Mock wp_remote_post returning a 401 array with a JSON body
    WP_Mock::userFunction( 'wp_remote_post' )->andReturn( array(
        'response' => array( 'code' => 401, 'message' => 'Unauthorized' ),
        'body'     => '{"code":"token_invalid","message":"no such token in db"}',
    ) );
    WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 401 );
    WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn(
        '{"code":"token_invalid","message":"no such token in db"}'
    );
    WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );

    $sent = array();
    WP_Mock::userFunction( 'wp_send_json_error' )->andReturnUsing( function ( $msg ) use ( &$sent ) {
        $sent[] = $msg;
    } );

    // Call the AJAX handler — exact entry-point is whatever class-scanner-ajax.php
    // exposes. Likely CU_Scanner_Ajax::submit_job() or similar.
    CU_Scanner_Ajax::submit_job();

    $this->assertNotEmpty( $sent );
    $this->assertStringContainsString( 'HTTP 401', $sent[0] );
    $this->assertStringContainsString( 'token_invalid', $sent[0] );
    $this->assertStringContainsString( '…', $sent[0] ); // body > 50 chars → ellipsis present
}
```

Also add a WP_Error case to ensure the network-level branch surfaces the WP_Error message:

```php
public function test_submit_job_wp_error_surfaces_network_failure() {
    $err = \Mockery::mock( '\WP_Error' );
    $err->shouldReceive( 'get_error_message' )->andReturn( 'cURL error 28: Operation timed out' );
    WP_Mock::userFunction( 'wp_remote_post' )->andReturn( $err );
    WP_Mock::userFunction( 'is_wp_error' )->andReturn( true );

    $sent = array();
    WP_Mock::userFunction( 'wp_send_json_error' )->andReturnUsing( function ( $msg ) use ( &$sent ) {
        $sent[] = $msg;
    } );

    CU_Scanner_Ajax::submit_job();

    $this->assertStringContainsString( 'HTTP 0', $sent[0] );
    $this->assertStringContainsString( 'cURL error 28', $sent[0] );
}
```

Exact helper method name, class name, and mock bootstrap pattern come from the existing `tests/ScannerAjaxTest.php` conventions; implementer matches those.

---

## 5. Data flow

Unchanged. The plugin's happy path (successful scan submission) is not touched. Only the error handler at `class-scanner-ajax.php:~195` is modified, and it only fires when `wp_remote_post` returns a non-2xx or a `WP_Error`.

---

## 6. Error handling

The modified block IS the error handler. Its behavior:

| Input | Branch | Output message |
|---|---|---|
| `wp_remote_post` returns 200 OK | Not this block (happy path) | N/A |
| `wp_remote_post` returns 4xx/5xx array | HTTP branch | `"Scan submission failed: HTTP 401. Response: {truncated body}…"` |
| `wp_remote_post` returns `WP_Error` (network fail) | WP_Error branch | `"Scan submission failed: HTTP 0. Response: cURL error 28: Operation timed out"` |
| Response body is empty | HTTP branch with empty snippet | `"Scan submission failed: HTTP 500. Response: "` (acceptable — operator sees status suffices) |
| Response body < 50 chars | HTTP branch, no ellipsis | `"Scan submission failed: HTTP 403. Response: {\"error\":\"forbidden\"}"` |
| Response body > 50 chars | HTTP branch, with ellipsis | `"Scan submission failed: HTTP 401. Response: {\"code\":\"token_invalid\",\"message\":\"no such tok…"` |

---

## 7. Testing strategy

- **PHPUnit (executed):** 2 new test cases in `tests/ScannerAjaxTest.php` covering HTTP-error branch + WP_Error branch. `phpunit` runs locally via the plugin's existing test harness (this plugin DOES execute PHPUnit tests, unlike SaaS which is deferred-write).
- **`php -l` syntax check:** all 4 touched files (`ai-assets-scanner.php`, `admin/class-scanner-ajax.php`, `readme.txt` is not PHP, and `tests/ScannerAjaxTest.php`).
- **Manual E2E verification:** after shipping, retry the scan that previously failed with the opaque "Check server error logs" message. New error message should show `HTTP 401` (or whatever the actual status is) and the first 50 chars of the response body. Paste to operator for 401 root-cause diagnosis in a separate work item.

---

## 8. Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Response-variable name in `class-scanner-ajax.php` is not `$response` | Medium | Implementer edits wrong variable | Implementer reads the file before editing, adapts to actual name |
| Error body contains sensitive data within first 50 chars | Low | Info disclosure to site admin | 50-char window deliberately shorter than typical token lengths; admin-only context (not customer-facing) |
| `mb_substr` on binary/non-UTF-8 body produces garbled slice | Very low | Garbled char in error UI | Non-UTF-8 bodies from Railway/SaaS are non-existent in practice (both return JSON) |
| Multiple error-reporting call sites exist | Low | Only one gets upgraded, others still opaque | `grep -n "Could not submit scan job" .` found only one call site at line 195 |
| Version string `1.2.0b` breaks `version_compare` semantics elsewhere | Low | Version checks misbehave | PHP `version_compare` treats `1.2.0b` as GREATER than `1.2.0` (per PHP docs on special-version-strings); existing `>= 1.2.0` gates still pass |

---

## 9. Rollout

1. Implement the 4 file edits + 2 test cases.
2. Run `phpunit` and `php -l` → green.
3. Commit sequence (conventional-commits style):
   - `fix: bump CU_SCANNER_VERSION constant to 1.2.0b (sync with header)`
   - `feat: surface HTTP status + response snippet in scan-submit errors`
   - `chore: bump to 1.2.0b, update readme + changelog`
4. Tag: `v1.2.0b` on the final commit (annotated, message: "AI Assets Scanner v1.2.0b — error surfacing + version constant fix").
5. Push commits + tag to `2slowDD/AI-Assets-Scanner` on `main` (requires user P9 YES).
6. Operator SFTPs the updated plugin to Hostinger for the production plugin directory AND to any test sites exhibiting the 401 issue.
7. Retry the failing scan from the test site — paste the new error message output in the conversation. That output feeds the 401 root-cause diagnosis (separate work item).

---

## 10. What this sub-spec does NOT do

- Fix the 401 root cause. That requires the new error output first.
- Sync versions across Railway / SaaS / CU-Scanner plugins. Each of those bumps independently when modified.
- Add troubleshooting / "common causes" hints to the error message.
- Change the admin UI layout, add new screens, or introduce new settings.
- Touch CU-Scanner integration (if any exists in this plugin).

---

## Appendix — Acceptance Criteria

**AC-1 — Version constant bumped:** `define( 'CU_SCANNER_VERSION', '1.2.0b' );` present in `ai-assets-scanner.php`; no remaining `'1.1.5'` or `'1.2.0'` string in version-constant position. Grep-checkable.

**AC-2 — Error message contains HTTP status + body snippet:** on a 401 response, the `wp_send_json_error` payload matches regex `/Scan submission failed: HTTP \d+\. Response: .{0,50}(…)?/`. PHPUnit-enforced.

**AC-3 — WP_Error case surfaces network-level message:** a `WP_Error` return from `wp_remote_post` produces an error message containing `HTTP 0` and the `WP_Error::get_error_message()` text. PHPUnit-enforced.

**AC-4 — readme.txt reflects v1.2.0b:** `Stable tag: 1.2.0b` present; changelog entry under `= 1.2.0b =` present.

**AC-5 — No regressions:** existing `tests/ScannerAjaxTest.php` happy-path test cases still pass after the edits.

---

**End of design — ready for implementation via writing-plans.**

---

## Addendum — v1.2.0c (same-day extension, 2026-04-22)

Shortly after v1.2.0b shipped + SFTP'd to production, the first real scan attempt exposed a SECOND instance of the same error-swallowing pattern — in `reserve_job` this time, not `submit_job`. The error "Could not reserve credits. You may not have enough credits for the selected pages — buy more or reduce the number of pages to scan." hid an actual `HTTP 429: rate limited` response (the SaaS rate limit — see Sub-spec A rollout lessons §D.3).

### Changes in v1.2.0c (commit `4da51cd`)

- **`admin/class-scanner-ajax.php:121`** — same pattern as v1.2.0b applied to the `reserve_job` catch block. `wp_send_json_error()` now receives the truncated exception detail instead of the hardcoded generic message.
- **Refactor:** extracted the core truncation logic (`mb_substr(..., 80)` + ellipsis) into a private `ScannerAjax::truncate_error_detail()` helper. Two public wrappers now exist:
  - `format_submit_error_detail( string $message ): string` — returns `"Scan submission failed: {truncated}"`
  - `format_reserve_error_detail( string $message ): string` — returns `"Could not reserve credits: {truncated}"`
- **3 new PHPUnit tests** for `format_reserve_error_detail` parallel to v1.2.0b's three `format_submit_error_detail` tests: short-message passthrough, long-message truncation + ellipsis, exactly-80-char boundary.
- **Version bump:** `1.2.0b` → `1.2.0c` in the plugin header, `CU_SCANNER_VERSION` constant, and CHANGELOG.

### Why not fold into v1.2.0b

The hook guardrail blocked reusing the `1.2.0b` marker for different code — correctly, since a released version marker should never change its code. Operators who deployed 1.2.0b before the reserve fix vs. after would otherwise both claim "1.2.0b" with different behavior, making support impossible.

### Out of scope in both v1.2.0b and v1.2.0c

- Surfacing errors in OTHER AJAX handlers (`check_job`, `cancel_job`, etc.) — pattern not observed there in production yet; extend only if operators report needing it.
- Structured error codes (machine-readable) — current UX is a plain-text admin-visible message, which is sufficient for operator diagnosis. A future version could add an `error_code` field to the JSON response.

### Release

- Commit `4da51cd` on `main`
- Tag `v1.2.0c` on `2slowDD/AI-Assets-Scanner`
- CHANGELOG entry under `## [1.2.0c] — 2026-04-22`

### Follow-up acceptance criterion

**AC-6 (new) — Reserve errors surface actual SaaS status code.** Operator can distinguish rate-limited (429), insufficient-credits (402), scan-in-progress (409), and domain-validation-failed (403) from the scanner UI without SSH access. Verified manually during rollout.
