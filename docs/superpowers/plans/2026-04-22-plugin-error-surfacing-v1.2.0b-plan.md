# Plugin v1.2.0b Implementation Plan — Error Surfacing + Version Constant Fix

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Spec:** `docs/superpowers/specs/2026-04-22-plugin-error-surfacing-v1.2.0b-design.md` (approved 2026-04-22)

**Goal:** Ship AI Assets Scanner v1.2.0b that (a) fixes the `CU_SCANNER_VERSION` constant drift from `'1.1.5'` to `'1.2.0b'`, and (b) surfaces HTTP status + response detail in scan-submission errors instead of the generic "Check server error logs" message.

**Architecture:** Extract the error-message formatting logic in `admin/class-scanner-ajax.php::submit_job()` into a public static helper `ScannerAjax::format_submit_error_detail()` so it's unit-testable. Existing `RailwayClient::parse()` already throws `\RuntimeException` with `"Railway HTTP {code}: {body.message}"` format — we just need to truncate + surface `$e->getMessage()` to the browser instead of swallowing it.

**Tech Stack:** PHP 8.1 (WordPress plugin), PHPUnit 9.6 + 10up/wp_mock 1.1, vanilla JS admin UI.

**Repo:** `D:/AI/CU/AI Assets Scanner/AI-Assets-Scanner/` on `main` branch, remote `origin` → `github.com/2slowDD/AI-Assets-Scanner.git`.

**Plugin state (verified):**
- `ai-assets-scanner.php` line 5 header: `Version: 1.2.0`
- `ai-assets-scanner.php` line 18 constant: `define( 'CU_SCANNER_VERSION', '1.1.5' );`
- `admin/class-scanner-ajax.php` line 194-195 (inside `submit_job()` catch block): `error_log(...); wp_send_json_error('Could not submit scan job. Check server error logs.');`
- `includes/api/class-railway-client.php` line 53: `throw new \RuntimeException( "Railway HTTP {$code}: " . ( $body['message'] ?? $body['error'] ?? 'error' ) );`
- `CHANGELOG.md` exists (no `readme.txt`)
- Tests in `tests/ScannerAjaxTest.php` — WP_Mock + PHPUnit pattern, no existing `submit_job` test.

**Commit convention:** conventional-commits style (`feat:`, `fix:`, `chore:`, `test:`) + `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>` trailer. Git local-by-default — no push until explicit user YES per CLAUDE.md P9.

**Test runner:** `./vendor/bin/phpunit` OR `composer test` if a script is defined.

**Known A-era context (verified):** the plugin HAS received A's `Plugin RailwayClient uses job_token` change (commit `80b6aed`). Its RailwayClient sends `Bearer <job_token>` on `POST /jobs`. So the 401 we observed is NOT a pre-A-flow issue; root cause is separate (follow-up after this ships).

---

## Task 1: Bump version (constant + header)

**Files:**
- Modify: `ai-assets-scanner.php` lines 5 and 18

- [ ] **Step 1: Verify current file state**

```bash
cd "D:/AI/CU/AI Assets Scanner/AI-Assets-Scanner"
grep -n "Version:\|CU_SCANNER_VERSION" ai-assets-scanner.php | head -5
```

Expected output:
```
5: * Version:     1.2.0
18:define( 'CU_SCANNER_VERSION', '1.1.5' );
```

If either line is already `1.2.0b`, STOP and ask (someone else may have partially done this).

- [ ] **Step 2: Bump plugin header line 5**

Edit `ai-assets-scanner.php` line 5 — replace the `Version:` value:

Before:
```php
 * Version:     1.2.0
```
After:
```php
 * Version:     1.2.0b
```

Preserve the surrounding comment block exactly (spacing, asterisks).

- [ ] **Step 3: Bump constant line 18**

Edit `ai-assets-scanner.php` line 18:

Before:
```php
define( 'CU_SCANNER_VERSION', '1.1.5' );
```
After:
```php
define( 'CU_SCANNER_VERSION', '1.2.0b' );
```

- [ ] **Step 4: php -l syntax check**

```bash
php -l ai-assets-scanner.php
```

Expected: `No syntax errors detected in ai-assets-scanner.php`

- [ ] **Step 5: Grep verify no drift left**

```bash
grep -n "1\\.1\\.5\\|1\\.2\\.0[^b]" ai-assets-scanner.php
```

Expected: no lines match (no leftover `1.1.5` or bare `1.2.0`).
Exception: if the grep happens to catch `1.2.0b` (it shouldn't, the `[^b]` guard avoids that), ignore.

- [ ] **Step 6: Commit**

```bash
git add ai-assets-scanner.php
git commit -m "$(cat <<'EOF'
fix: bump CU_SCANNER_VERSION constant to 1.2.0b (sync with header)

Commit ce3f311 bumped the plugin header to 1.2.0 but missed the
CU_SCANNER_VERSION constant, which powers the internal admin banner.
Bumps both to 1.2.0b — a post-Sub-spec-B marker. Other CU Scanner
repos are NOT synced to this version; they'll bump independently
when modified.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Extract + test error-formatting helper

**Files:**
- Modify: `admin/class-scanner-ajax.php` (add new static method, call it from existing catch block)
- Modify: `tests/ScannerAjaxTest.php` (add 3 new tests for the helper)

- [ ] **Step 1: Write the 3 failing tests**

Open `tests/ScannerAjaxTest.php` and append these test methods inside the existing `ScannerAjaxTest` class (just before the final `}` of the class):

```php
    public function test_format_submit_error_detail_short_message_is_untruncated(): void {
        $result = ScannerAjax::format_submit_error_detail( 'Railway HTTP 401: no such token' );
        $this->assertSame( 'Scan submission failed: Railway HTTP 401: no such token', $result );
    }

    public function test_format_submit_error_detail_truncates_at_80_chars_with_ellipsis(): void {
        $long = str_repeat( 'x', 200 );
        $result = ScannerAjax::format_submit_error_detail( $long );

        // Prefix is fixed literal text
        $this->assertStringStartsWith( 'Scan submission failed: ', $result );

        // Detail portion = first 80 chars of input + ellipsis
        $detail = mb_substr( $result, mb_strlen( 'Scan submission failed: ' ) );
        $this->assertSame( str_repeat( 'x', 80 ) . '…', $detail );
    }

    public function test_format_submit_error_detail_at_exactly_80_chars_no_ellipsis(): void {
        $exact = str_repeat( 'x', 80 );
        $result = ScannerAjax::format_submit_error_detail( $exact );
        $this->assertSame( 'Scan submission failed: ' . $exact, $result );
        $this->assertStringEndsNotWith( '…', $result );
    }
```

The import at the top of the file is already `use CUScanner\Admin\ScannerAjax;` — no additional imports needed.

- [ ] **Step 2: Run the tests, expect FAIL**

```bash
./vendor/bin/phpunit --filter format_submit_error_detail tests/ScannerAjaxTest.php
```

Expected: 3 failures, all saying `Method ScannerAjax::format_submit_error_detail() does not exist` or similar PHP `Error: Call to undefined method`.

- [ ] **Step 3: Add the helper method to ScannerAjax**

Open `admin/class-scanner-ajax.php`. Find a suitable insertion point — **immediately after the existing public `submit_job` method closes** (ends on the line with `}` after the catch block around line 197; the `check_job()` method begins right after). Insert the new static method BETWEEN them:

```php
    /**
     * Formats an exception message from submit_job() failures for user-visible display.
     *
     * The underlying RailwayClient::parse() throws "Railway HTTP {code}: {body.message}".
     * We surface that to the browser (was previously swallowed into a generic message)
     * but truncate to 80 chars to bound what a malformed response could echo.
     *
     * Sub-spec B rollout surfaced that "Could not submit scan job. Check server error
     * logs." is operationally useless — admins need the HTTP status and body extract
     * to diagnose without SSH + tail.
     *
     * @param string $message Exception message (from $e->getMessage()).
     * @return string Formatted user-visible detail, prefixed with "Scan submission failed: ".
     */
    public static function format_submit_error_detail( string $message ): string {
        $detail = mb_substr( $message, 0, 80 );
        if ( mb_strlen( $message ) > 80 ) {
            $detail .= '…';
        }
        return 'Scan submission failed: ' . $detail;
    }
```

- [ ] **Step 4: Wire the helper into the existing catch block**

In `admin/class-scanner-ajax.php` around line 195, replace:

```php
            error_log( '[AI Assets Scanner] submit_job: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional production logging: exception detail is withheld from the browser and written to server error log only.
            wp_send_json_error( 'Could not submit scan job. Check server error logs.' );
```

With:

```php
            error_log( '[AI Assets Scanner] submit_job: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional production logging: full exception detail to server log; truncated user-visible detail via format_submit_error_detail().
            wp_send_json_error( self::format_submit_error_detail( $e->getMessage() ) );
```

Note: update the `phpcs:ignore` trailing comment too — the rationale now differs (we no longer WITHHOLD the detail from the browser; we surface a truncated version).

- [ ] **Step 5: php -l both touched files**

```bash
php -l admin/class-scanner-ajax.php
php -l tests/ScannerAjaxTest.php
```

Expected: both print `No syntax errors detected`.

- [ ] **Step 6: Run the 3 new tests, expect PASS**

```bash
./vendor/bin/phpunit --filter format_submit_error_detail tests/ScannerAjaxTest.php
```

Expected: `OK (3 tests, ~6 assertions)`.

- [ ] **Step 7: Run the full ScannerAjaxTest file to check for regressions**

```bash
./vendor/bin/phpunit tests/ScannerAjaxTest.php
```

Expected: all tests pass, including any pre-existing ones. No regressions.

- [ ] **Step 8: Run full test suite to check for cross-file regressions**

```bash
./vendor/bin/phpunit
```

Expected: all tests pass. If any previously-passing test fails now, investigate — the helper addition should be purely additive and not touch any existing call path except the one line in the `submit_job` catch block.

- [ ] **Step 9: Commit**

```bash
git add admin/class-scanner-ajax.php tests/ScannerAjaxTest.php
git commit -m "$(cat <<'EOF'
feat: surface HTTP status + response snippet in scan-submit errors

The submit_job() catch block previously returned a generic
"Could not submit scan job. Check server error logs." to the browser,
while logging the useful \$e->getMessage() only to server error_log.

Sub-spec B rollout surfaced an opaque 401 during production testing;
the admin had to use Railway's HTTP Logs tab to diagnose. Now the
browser gets the same detail (Railway HTTP {code}: {body.message})
truncated to 80 chars with ellipsis. Server error_log still captures
the untruncated message for long-form triage.

Extracted the format logic to ScannerAjax::format_submit_error_detail()
for unit-testability. 3 PHPUnit tests cover: short-message passthrough,
long-message truncation + ellipsis, exactly-80-char boundary.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Update CHANGELOG.md

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Inspect the CHANGELOG's current format**

```bash
head -20 CHANGELOG.md
```

Note the existing format (heading style, date format, entry style). The plan below assumes Keep-a-Changelog-style headings (`## [1.2.0] - 2026-04-20`). If the file uses a different style (e.g. WordPress `= 1.2.0 =` readme.txt-style), mirror whatever the file already uses.

- [ ] **Step 2: Add the 1.2.0b entry at the top**

Insert directly below the top-of-file heading (likely `# Changelog`), before the existing most-recent entry. Use the exact style the file already uses. Example if Keep-a-Changelog style:

```markdown
## [1.2.0b] - 2026-04-22

### Fixed
- `CU_SCANNER_VERSION` constant no longer drifts from the plugin header (was stuck at `1.1.5` since commit `ce3f311`).

### Changed
- Scan-submission errors now surface the HTTP status code and a 80-char response snippet (e.g. `Scan submission failed: Railway HTTP 401: no such token`) instead of the generic `Could not submit scan job. Check server error logs.` message. Server `error_log` still receives the untruncated exception detail.

```

Adapt the heading style + section headers to match the file's convention if different.

- [ ] **Step 3: Commit**

```bash
git add CHANGELOG.md
git commit -m "$(cat <<'EOF'
chore: CHANGELOG entry for v1.2.0b

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Final verification + annotated tag

**Files:** none modified.

- [ ] **Step 1: Final php -l sweep on all 3 changed PHP files**

```bash
cd "D:/AI/CU/AI Assets Scanner/AI-Assets-Scanner"
php -l ai-assets-scanner.php
php -l admin/class-scanner-ajax.php
php -l tests/ScannerAjaxTest.php
```

Expected: all 3 print `No syntax errors detected`.

- [ ] **Step 2: Run full PHPUnit suite one more time**

```bash
./vendor/bin/phpunit
```

Expected: all tests pass, including the 3 new ones from Task 2.

Record the line like `OK (N tests, M assertions)` for the final report.

- [ ] **Step 3: Verify commit history**

```bash
git log --oneline -5
```

Expected: the top 3 commits should be (in order newest first):
```
<sha> chore: CHANGELOG entry for v1.2.0b
<sha> feat: surface HTTP status + response snippet in scan-submit errors
<sha> fix: bump CU_SCANNER_VERSION constant to 1.2.0b (sync with header)
<sha> docs(b): plugin v1.2.0b design spec — error surfacing + version constant fix
<sha> chore: bump to 1.2.0 (mandatory update ...)
```

If Task 1 used the wrong commit message or any commit is missing, STOP and report.

- [ ] **Step 4: Create annotated git tag**

```bash
git tag -a v1.2.0b -m "AI Assets Scanner v1.2.0b — error surfacing + version constant fix

- Fix: CU_SCANNER_VERSION constant drift from 1.1.5 unified with header.
- Feat: scan-submission errors now show HTTP status + 80-char response snippet.

See docs/superpowers/specs/2026-04-22-plugin-error-surfacing-v1.2.0b-design.md
See docs/superpowers/plans/2026-04-22-plugin-error-surfacing-v1.2.0b-plan.md"

git tag --list 'v1.*'
```

Expected: `v1.2.0b` appears in the tag list (alongside any prior version tags).

- [ ] **Step 5: Confirm git state is clean (no uncommitted changes)**

```bash
git status -sb
```

Expected: `## main...origin/main [ahead 3]` (or ahead 4 if the spec-commit from brainstorming is still unpushed) with no staged/unstaged changes shown.

- [ ] **Step 6: Stop here and hand to user for push decision**

Do NOT `git push`. CLAUDE.md P9 requires explicit user YES confirmation in chat before pushing to any `2slowDD/*` remote.

Print a summary like:

```
Plan complete. Local state:
- 3 new B-era commits on main (Task 1, 2, 3)
- Tag v1.2.0b created locally, points at Task 3's commit
- All tests pass (N tests, M assertions)
- Working tree clean, ahead of origin/main by 3
- Ready for `git push origin main && git push origin v1.2.0b` after user P9 YES
```

Wait for user to authorize the push.

---

## Appendix — Acceptance Criteria satisfaction

Mapped from the spec's §Appendix to plan tasks:

- **AC-1 — Version constant bumped:** Task 1 + Task 4 Step 1 (grep-style verification via Task 1 Step 5).
- **AC-2 — Error message contains HTTP status + body snippet:** Task 2 Step 1 tests (the 3 new PHPUnit tests) + Task 2 Step 4 wiring.
- **AC-3 — WP_Error case surfaces network-level message:** IMPLICITLY satisfied — `RailwayClient::parse()` at line 45 already throws `new \RuntimeException( $response->get_error_message() )` on `is_wp_error`. Its message is the WP_Error text (e.g. "cURL error 28: Operation timed out"). After our Task 2 change, that message flows through `format_submit_error_detail()` and surfaces to the browser with the `Scan submission failed: ` prefix. No additional test needed — this is regression-safe via the existing `RailwayClient::parse()` behavior that we are not modifying.
- **AC-4 — readme.txt reflects v1.2.0b:** N/A per plan. Verified in the spec's plan-time review: this plugin has `CHANGELOG.md` but no `readme.txt`. Task 3 updates `CHANGELOG.md` instead. This deviates from the spec's wording but matches the repo reality.
- **AC-5 — No regressions:** Task 2 Step 7 (file-level) + Task 4 Step 2 (suite-wide). Both must be green.

---

**End of plan.**
