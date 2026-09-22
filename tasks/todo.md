# 1.8.9 — updater null-package fatal, Tested-up-to 7.1.2, primary "Run Another Scan" (2026-09-22)

Base: `1.8.8` on the primary checkout. Ledger: off for this session (operator, Option 4).
Brief: operator, 2026-09-22 — a customer site sat in maintenance mode after an uncaught `TypeError`
in `PrivateUpdater::filter_pre_download()` during the wp-cron auto-update run, reported from that
site's PHP error log.

## Root cause (confirmed)

`filter_pre_download()` declares `string $package` on the `upgrader_pre_download` filter, which fires
for **every** package WordPress downloads. `Plugin_Upgrader::upgrade()` passes `$upgrade_data->package`
straight from the `update_plugins` transient; an entry with no `package` property yields `null`, and a
non-nullable `string` parameter turns that into an uncaught `TypeError`.

Verified against WP core source (line numbers match the client's trace exactly):
- `class-wp-automatic-updater.php:474` → `$upgrader->maintenance_mode( true )`, **then** `:478` → `Plugin_Upgrader->upgrade()`
- `class-wp-upgrader.php:322` → `apply_filters( 'upgrader_pre_download', false, $package, … )`
- Core itself tolerates the null: `if ( empty( $package ) ) return new WP_Error( 'no_package', … )`

So the fatal is ours alone, and because it lands **after** maintenance mode is enabled and **before** it
is disabled, `.maintenance` is left on disk → "Briefly unavailable for scheduled maintenance."
Affected: every version shipping the private updater (1.7.8 → 1.8.8), including current.

## Tasks

- [x] 1. `includes/admin/class-private-updater.php` — `filter_pre_download()` takes `mixed $package`,
      coerces to `''` when not a string, and returns `$reply` untouched on an empty package (core then
      emits its own accurate `no_package` error instead of our misleading `aias_checksum_missing`).
- [x] 2. Same file — `TESTED_WP` `'7.1'` → `'7.1.2'`. (`sane_version()`'s `^\d+(\.\d+){0,3}$` accepts it.)
- [x] 3. `tests/PrivateUpdaterTest.php` — regression test passing `null` exactly as core does, plus the
      empty-string twin. Mutation-check: revert the fix, the new test must go red.
- [x] 4. `admin/views/scanner-page.php:322` — `button-secondary` → `button-primary` on `.cu-btn-run-another`.
- [x] 5. `ai-assets-scanner.php` — header `Version: 1.8.9`, `CU_SCANNER_VERSION` `1.8.9`,
      header `Tested up to: 7.1.2`. **`CU_SCANNER_ASSET_VERSION` stays `1.8.8.1`** — no `admin/js` or
      `admin/css` byte changes, so `JsCacheBustDriftTest`'s fingerprint is unmoved (1.8.5 is the precedent
      for a release that kept the prior asset key).
- [x] 6. `README.md` shields badge → `VERSION-1.8.9-007cba`.
- [x] 7. `tests/VersionLockstepTest.php` — expected header literal `1.8.8` → `1.8.9`.
- [x] 8. `CHANGELOG.md` — `## 1.8.9 — 2026-09-22` section.
- [x] 9. Full PHPUnit suite (not just touched tests) + CRLF byte-check on every edited file (Rule 28).
- [x] 10. `wp-compliance` SKILL.md — new rule: never declare non-nullable scalar/array types on a
      WordPress hook callback parameter. Local edit + commit first, then the P9 push gate (public repo).

## Release (operator go given 2026-09-22)

Packaged 1.8.9 via `build-release.py` + a hand-written `releases/1.8.9/stable.json`. The manifest's
`tested_wp` also reads `7.1.2` — `filter_plugin_row_meta()` prefers the **live manifest** over the
compiled-in `TESTED_WP`, so the Plugins-screen row only shows 7.1.2 once the published `stable.json`
carries it. `7.1.2` was resolved from `api.wordpress.org/core/version-check/1.7/` on 2026-09-22, not
copied forward from the 1.8.8 manifest.

## Follow-ups discovered during this task

- `admin/js/scanner.js:3161` reads `document.getElementById('cu-top-rescan-row')`, but no element with
  that id exists anywhere in the markup (grep over `*.php` + `*.js`, vendor excluded). The `<10 URLs`
  visibility branch has been dead since the top rescan row was removed. Harmless (guarded by `if`), but
  it is misleading code and the comment describes behaviour that does not happen. Defer.
- `filter_plugin_row_meta( array $links, … )` and `filter_heartbeat( array $response, array $data )`
  carry the same class of over-strict signature as the bug above. Core always supplies the right types,
  so only a misbehaving third-party filter up-chain could trip them — lower risk, but the new
  wp-compliance rule covers them. Defer to a later cleanup.

## Review

All ten tasks done; full PHPUnit suite green at **1032 tests / 2759 assertions / 0 failures** (2 risky +
5 skipped in `MenuBadgeTest` are pre-existing and unrelated).

What the suite caught that a touched-tests-only run would not: bumping `TESTED_WP` reddened two
assertions in `PrivateUpdaterTest` that pinned the old `v7.1` literal as the row-meta fallback. Both
were legitimate pins on a constant that genuinely moved, so both were updated rather than relaxed.

Both new guards were mutation-checked rather than assumed:
- Restoring `string $package` reproduced the reported error verbatim — *"Argument #2 ($package) must be
  of type string, null given"*.
- Removing the `'' === $package` short-circuit produced exactly the misleading `aias_checksum_missing`
  WP_Error the guard exists to prevent.

Line endings re-verified by byte count (CR total vs LF total per file) after an earlier
`grep -c $'\r$'` probe proved unreliable in this shell: all seven edited files are pure CRLF, so
Plugin Check's `Internal.LineEndings.Mixed` stays clean.

The general lesson was written up as **Rule 29 of the `wp-compliance` skill** (hook callbacks take
`mixed`, never a non-nullable scalar or array), published in `claude-compliance-by-D` `d4e3d44`.
