# Challenge-script keeplist FOLLOW-UP QUEUE (FU-A…FU-K + R20) — todo

**Date:** 2026-08-15 · **Plugin:** AI Assets Scanner · **Branch:** `feat/challenge-keeplist-note` @ `7a95d48` (pushed to `main`, divergence 0/0, tree clean)
**Controller:** Fable · **Implementers/reviewers:** Opus 5 (explicit `model: opus`, P15)
**Recovery map:** `D:\AI\CU\docs\product-docs\04-development\2026-08-15-keeplist-followups-handoff.md`
**Canonical FU rows:** `10-improvements/improvements-ledger.md` §"Session-discovered follow-ups (2026-08-14…)"

> Supersedes the 1.7.94b release plan (that release **shipped and is verified in production**, scan `6255833f56eb`).
> Its one **unfinished** section is carried forward below under *Carried from the prior plan*. Nothing else was open.

> ## ✅ STATUS 2026-08-16 — this queue is CLOSED; everything below shipped
>
> **Phases 0.2 / 1 / 1.5 all released.** They went out in **1.7.95b**, which is live and verified
> against the update host (manifest, served ZIP and checksum all agreeing). The "UNPUSHED" and
> "committed locally" notes further down are **historical** — `origin/main` is now `4394f24` and
> the branch is 0/0. They are left in place rather than deleted so the sequencing stays readable.
>
> **What came after this queue, and is not described in it:** the **R20 render** — report EVERY
> known-asset whitelist keep, not just protection — shipped as **1.7.96b** (`ceae547` chip gate,
> `35844f3` aggregation, `474acfd` render, `210e9a8` ratchet pin, `a90d4c9` version bump). Plan:
> `<AI-ROOT>\CU\docs\product-docs\04-development\2026-08-16-r20-aas-render-implementation-plan.md`.
> Spec: `…\2026-08-15-r20-all-known-asset-keeps-spec.md` (Rev 2.1).
>
> Also shipped alongside it, none of it customer-facing: `package.json` so the 17 JS suites run as
> one `npm test`; a per-version `admin/js` fingerprint pin closing the same-version JS drift class;
> the secret-fixture guard extended to the `sk-` prefix family; and `public-push-sweep.py`, which
> makes P9's public-push checks a command instead of a memory exercise.
>
> ⚠️ **1.7.96b is BUILT and PUSHED but NOT YET UPLOADED** to `updates.wpservice.pro` at the time of
> writing. Until that upload lands, none of the R20 work is visible to a customer.

---

## Phase 0 — free, no code (IN PROGRESS)

### 0.1 — Close the production verification gap · ⏳ BLOCKED ON OPERATOR
- [ ] Operator runs **one Extra-Time re-scan** on a page carrying a protection script (e.g. `wpservice.pro/contact/`, which showed `🛡 kept`).
- [ ] Pull the Railway worker log **promptly** (shallow live-tail buffer) into `debug-evidence\15-8-26\<date>-et-rescan-a3-verify\`.
- [ ] Read `kept_protection_swept` in the merge diag + `dep_graph_guard` / `is_continuation` rows.
- Closes in one shot: **A3's ratchet sweep**, Train 1's **AC-20 ET-continuation veto** (never exercised in prod), the **reload persistence** path, the **background-completion** writer.

### 0.2 — FU-J ruling · ✅ RULED (see *Rulings* below) — **KEEP CROSS-HOST**, rides Phase 1 as test + comment.

---

## Phase 1 — AAS "unasserted guards" bundle · test-only · **no release** · ✅ DONE `2a99b56` — ✅ **PUSHED + RELEASED in 1.7.95b** *(the "UNPUSHED, held for Phase 4's release" note was true when written; that release happened)*

One branch off `7a95d48`, one review pass. **Zero production behaviour touched, no version bump, no SFTP, no manifest.**
(FU-J's deliverable includes a **comment amendment** in `includes/scanner/class-ratchet-merger.php` — PHP comment only, no behaviour, no enqueue, therefore no cache-bust and no bump. It rides Phase 4's release for free.)

### T1 — FU-H · version three-place lockstep guard (PHP test)
- [ ] New PHPUnit test asserting the three **file contents** agree:
      `ai-assets-scanner.php:5` header `Version:` · `ai-assets-scanner.php:26` `define( 'CU_SCANNER_VERSION', … )` **source line** · `README.md:5` shields.io badge.
- [ ] ⛔ It must **NOT** read the `CU_SCANNER_VERSION` constant — `tests/bootstrap.php:7` defines it as `1.0.0`. That shadow is exactly why the suite is blind today.
- [ ] **P17 falsification (mandatory):** mutate each of the three literals in turn, prove the test goes red each time, revert. A guard that cannot go red is decorative.
- [ ] Re-derive all line numbers **by content** — do not trust the numbers above.

### T2 — FU-F · delimiter-bounded slice (JS test)
- [ ] Replace the fixed `src.slice(idx, idx + 1200)` window in `tests/js/result-truth-display.test.js` with a slice bounded by the statement's real closing token — the pattern `tests/js/kept-protection-note.test.js` already uses.
- [ ] Apply to **both** writers (see the measurement below: `menu-badge.js` overruns too, which the FU row does not mention).
- [ ] Keep the existing falsification check (deleting the keys must still go red).

### T3 — FU-G · parse-guard for the unguarded `admin/js` files
- [ ] Nothing currently parses `admin/js/history.js` or `admin/js/settings.js` — a syntax error or a commented-out pinned line ships green there.
- [ ] Template: A2b's `createMenuBadgeHarness` (~45 lines). Minimum bar = the file **parses**; pin anything cheap and load-bearing beyond that.
- [ ] **P17 falsification:** introduce a deliberate syntax error in each, prove red, revert.

### T4 — FU-J · cross-host sweep test + comment amendment
- [ ] Add a **cross-HOST** test to `tests/RatchetMergerTest.php` alongside the existing cross-page ones: two pages on **different hosts**, same `handle|type`, keep reported on host A only, assert host B's non-re-derived unload rule is recorded `kept_protection_swept` and does **not** reach `$final`.
- [ ] Amend the Step-3b comment block in `includes/scanner/class-ratchet-merger.php` so it says **CROSS-HOST** (a superset of cross-page) and states the F-DEG > F-MISS reasoning for hosts explicitly — the way F-4 did for pages.
- [ ] Do **not** add a `url_pattern` component to `$kept_index`. See the ruling.

### Phase-1 gate (controller, at the gate — not inherited)
- [ ] `php vendor/phpunit/phpunit/phpunit --colors=always` — expect **≥889 tests**, 0 errors, 0 failures, **5 skipped / 2 risky with the identities named below**.
- [ ] All 16 `tests/js/*.test.js` executed individually with `node`, exit code checked each.
- [ ] ⚠️ `tests/` is **gitignored** — `git add` on a new test file exits 1 *while still staging your tracked files*. Use `git add -f` and verify with `git show --name-only HEAD`.
- [ ] ⛔ Never `composer install` in this worktree (`vendor/` is git-tracked, 1446 files).

---

## Phase 1.5 — FU-L + FU-M · API-key data loss · **AAS, code change** · ✅ DONE + VERIFIED 2026-08-15 — ✅ **PUSHED + RELEASED in 1.7.95b** *("committed locally, unpushed" was true when written)*

`admin/class-settings-ajax.php` `save_settings()` commits the submitted API key **before** it is validated, and
never rolls back. Re-derived by content 2026-08-15: `set_api_key()` at **:43**, `authenticate()` at **:61** — both
citations from the FU row still hold.

**Two reachable data-loss paths, not one.** The FU row names only the first:
1. **Mask overwrite.** The stored key is rendered masked into the `value` attr (`admin/views/settings-page.php:30-32`,
   `first6 + '•'×(len-12) + last6` when `len > 12`) with `data-masked="1"` at `:46`. `admin/js/settings.js:36-39` is the
   **sole** thing converting that into the `keep_api_key` sentinel. Lose that branch and `$keep` is false, so `:42-43`
   sanitizes the **mask** and writes it over the real key. (The FU row says the literal is `Saved paid key`; that
   string is only ever set by `settings.js:64` after an in-page paid-key claim. The common case is the bullet mask.)
2. **Empty wipe** — *not in the FU row.* Submit with an empty key field and no sentinel → `:43` calls
   `set_api_key('')` → `update_option( 'cu_scanner_api_key', '' )` (`includes/class-settings.php:28-30`, no empty
   guard) → the key is destroyed, and only *then* does `:46-47` report *"No API key is saved."*

### Approach — authenticate-then-commit (single write site, no rollback path)

Move the `set_api_key()` call to **after** `authenticate()` returns. Chosen over snapshot-and-restore and over a
server-side mask sniff because it needs to know **nothing** about the mask format: a mask string, an empty string and
any other garbage all fail to authenticate, so none of them can ever reach the option. A mask sniff in the handler
would duplicate the format that lives in the view — synchronise-two wearing the fix's clothes.

- [x] L1 · `save_settings()`: `$keep`/`else` branch computes `$api_key` only; the write moves inside the `try`,
      guarded by `if ( ! $keep )`, **after** `$auth = $client->authenticate()` and before `set_railway_url()`.
- [x] L2 · Comment the deliberate asymmetry, in the style of the existing `:30-35` block: `omit_cu_bypass` and
      `set_http_auth()` stay pre-auth **on purpose** (neither is validated by `authenticate()`); the API key is the
      one write whose validity the call actually decides.
- [x] L3 · `tests/SettingsAjaxSaveTest.php` (new; `git add -f`). House pattern = `tests/AckCdnAjaxTest.php`
      (same class, `WP_Mock` + `TestCase`). **P17:** drive the REAL `WpserviceClient` → real `parse()` → real
      `HttpException` by mocking `wp_remote_post` — no injected client, no DI seam. **10 tests shipped.**
      - [x] Bad key rejected (401 body) → `update_option( 'cu_scanner_api_key', … )` **never** called; error returned.
      - [x] Mask string submitted without the sentinel → stored key survives.
      - [x] Empty key submitted → `update_option` never called; `'No API key is saved.'` returned.
      - [x] Transport failure (`wp_remote_post` → `WP_Error`) → stored key survives.
      - [x] Happy path — valid new key → `update_option` called **once** with the new key, `railway_url` set,
            `wp_send_json_success` with credits. **Negative control**: the fix must not red on correct work.
      - [x] `keep_api_key` sentinel set → `update_option` never called for the key, success still returned.
      - [x] *(added)* `omit_cu_bypass` still persists when auth fails — pins the deliberate pre-auth asymmetry.
- [x] L4 · Mutation-prove each guard: revert the reorder, confirm the four loss tests go red, restore.
      **🟢 Run by the controller first-hand, not inherited** — Mutation A (write moved back pre-auth) → **5 red**
      (`rejected_key`, `mask_submitted`, `empty_key`, `transport_failure`, `unvalidated_options`); Mutation B
      (FU-M guard removed) → **1 ERROR + 1 FAIL**; Mutation C (`?? 0` removed) → **1 red**. File restored
      `diff -q` byte-identical after each. No decorative guards.
- [x] L5 · Full suite. Baseline to beat: **PHP 892 / 2144, 0 errors / 0 failures, 5 skipped / 2 risky** · **JS 17/17**.
      Verify skip/risky identities **by name** (3× ZipArchive in `ExportHistoryAjaxTest`, 2× `OptimizerStateNoticesTest`;
      2× `MenuBadgeTest::test_mark_seen_*`) — a matching count with changed identities is a masked regression.
      **🟢 Result: PHP 902 / 2179, 0 err / 0 fail, 5 skipped / 2 risky · JS 17/17.** Identities verified by name,
      unchanged from baseline. Both edited files are 100 % CRLF (wp-compliance R28).
- [x] L8 · *(added by operator 2026-08-15, "close/fix it")* `$auth['balance']` was the other unguarded dereference
      in the same statement — same class as FU-M but non-fatal: an auth response without it emits an
      "undefined array key" warning and puts `null` on the wire, which `settings.js` renders as
      *"Credit balance: null"*. Guarded with `?? 0`, matching `fetch_balance()`. Pinned by
      `test_auth_success_without_balance_reports_zero_credits`, mutation-proven (Mutation C).
      ⚠️ Note in the test comment: the symptom differs by environment — PHPUnit converts the warning into a
      `RuntimeException` the handler catches, so the test reds on `kind`; production only logs and renders `null`.
      The guard fixes both.
- [ ] L6 · No version bump, no enqueue change, no cache-bust: `settings.js` is **not** edited. Ships in Phase 4's release.

**L0 · RULED 2026-08-15 (operator): STRICT authenticate-then-commit, NO transport carve-out.** During a wpservice.pro
outage `authenticate()` throws with `get_status_code() === 0` (transport) vs a real HTTP status (rejection) —
`includes/api/class-http-exception.php:11-18`, `class-wpservice-client.php:120-131` — so a carve-out *was* available.
Refused: it would re-open the mask-overwrite path under exactly the condition where the user gets no feedback, and it
adds a second branch to a function whose whole defect is a second write path. The residual is that a *good* key cannot
be saved while the service is unreachable — recoverable by retry, unlike the key loss it prevents. `get_status_code()`
is therefore **not** consulted by this fix; do not add a status-code branch here without re-opening L0.

### L7 · FU-M bundled (operator, 2026-08-15) — same file, same function

- [x] `$auth['railway_url']` at `:62` is dereferenced unguarded into `set_railway_url( string $url )`
      (`includes/class-settings.php:89`). A response missing the key yields `null` → **`TypeError`, not a
      `RuntimeException`**, so the `:64` catch does not hold it: uncaught fatal → HTTP 500 → `settings.js:41`'s
      `r.json()` rejects with no `.catch()`, and the admin sees a form that silently does nothing.
- [x] Guard it the way `:103` already does (`! empty( … )` before use), so the two sites in this file agree.
      Decide and state in the comment what happens when it is absent: succeed-without-updating (matching `:103`'s
      existing semantic) rather than erroring, since the key itself has by then authenticated successfully.
- [x] Test: auth succeeds but the response carries **no** `railway_url` → no fatal, no `TypeError`, the API key **is**
      still committed (it authenticated), and the handler returns success. This is the P17 falsification case for L7 —
      it must go red against today's code.
- [x] ⚠️ **CORRECTION — the L7 test spec above was WRONG, caught by the implementer and reproduced first-hand by the
      controller.** The *absent-key* shape alone cannot prove FU-M in this harness. `$auth['railway_url']` on a
      missing key raises `E_WARNING`, and PHPUnit 9's default `convertWarningsToExceptions` turns that into
      `PHPUnit\Framework\Error\Warning`, whose ancestry is `→ Error → Exception → RuntimeException` (🟢 verified:
      `is_subclass_of( 'PHPUnit\Framework\Error\Warning', 'RuntimeException' ) === true`). The handler's own
      `catch ( \RuntimeException $e )` therefore swallows it and returns a tidy error — the test would red under
      mutation for the **wrong reason** and never exercise the production fatal. Mutation B shows them diverge:
      the null shape yields a real `TypeError` **ERROR**, the absent shape only an assertion **FAIL**.
      Closed by adding `test_auth_success_with_null_railway_url_commits_key_and_succeeds`
      (`{"balance":5,"railway_url":null}` — key present, so no warning fires and the null reaches the typed setter).
      **Both shapes are now covered.** Lesson: a converted-warning catch can make a guard look pinned when it is not.

---

## Phase map (later — not yet planned in detail)

| Phase | Contents | Release? |
|---|---|---|
| **2** | FU-A + FU-D (same file, `known-assets-rescue.js`) + FU-C — worker hygiene, no schema, no behaviour | worker push |
| **3** | FU-B + FU-E — **mandated to ship together** (R17); makes the R12 exclusion query and per-scan keep accounting executable on the durable sink | worker push |
| **4** | FU-K + FU-I (**coupled, in that order**) + 11px chip + M-3 + N-1 + Phase-1's PHP comment | **one AAS release** |
| **5** | R20 whitelist expansion — worker train → AAS train. **Do not start before Phases 2 and 3** (adds exports to the module FU-A pins; unmeasurable without FU-E's `scan_id`) | both |

Worker phases: branch off **`origin/master` = `1509f8fc`**, never off the primary checkout's HEAD (`fu/known-assets-tracker-gap` @ `996c8c64`) or its **stale local `master`** (`552a5291`). Docker Desktop up + `docker exec cu-redis-sdd redis-cli PING` → `PONG` first, or ~23 suites fail spuriously.

---

## Rulings made this session

**FU-J — cross-host sweep scope: KEEP CROSS-HOST. Do not scope the index per host.**

The A3 keeplist index is keyed `asset_handle|asset_type` with no host component, while every rule carries a
host-bearing `url_pattern`. Scoping the index per host would be a *tightening*: it would let the ratchet
resurrect a stale unload rule for a handle the worker has classified as a protection script, on a second host
in the same scan — i.e. trade an **F-DEG** risk (anti-bot/anti-spam script unloaded) for an **F-MISS** gain
(one recovered optimization). **F-DEG outranks F-MISS**, so the tightening is the wrong direction.

The sweep is also structurally incapable of harm: it fires **only at the three restore branches**
(`failsafe_benign`, `absent_restore`, `benign_restore`) and never at a drop branch or at `in_r_et`, so every
rule it touches is one **this rescan did not re-derive** — an unconfirmed floor restore. Its only possible
effect is leaving *more* assets loaded. The worker's "this is a protection script" verdict is a property of the
**handle**, which is stable across hosts running the same plugin (`gform_turnstile_vendor_script` means the same
thing everywhere), so cross-host is arguably the *more* correct scope, not a looser accident.

Residual accepted as risk: ⚠️ two unrelated plugins on two hosts in one scan could register the same handle
string, costing one missed optimization on the second host. F-MISS only, never F-DEG.

**Deliverable = T4** (test + comment). The point of FU-J was that the scope be a *decision*; this is the decision.

---

## Follow-ups discovered during this task

- **FU-F's "does not reconcile" warning is RESOLVED — both numbers were right.** The ledger row flags that the A2
  report's *"~252 chars of margin"* does not reconcile with the *1757-char literal* and says re-derive before acting.
  Measured first-hand: `scanner.js` statement = **1756 chars** from the anchor (overrun **556**), and the margin from
  the last asserted key (`credits_refunded` @ **+948**) to the 1200 edge = **252**. They measure different things —
  statement length vs. key-to-edge margin — and both are correct. Fold this correction into the FU-F row.
- **FU-F affects `menu-badge.js` too**, which the FU row does not say: its statement runs **1337** chars (overrun
  **137**), margin **270**. The fix must cover both writers.
- **FU-G's blast radius is exactly 2 files**: `admin/js` holds only 4 `.js` files; `scanner.js` + `menu-badge.js` are
  referenced by tests, `history.js` + `settings.js` are not.
- ~~**`tests/js` holds 17 `.js` files, of which 16 are `*.test.js`**~~ — **SUPERSEDED 2026-08-15 by Phase 1 itself**,
  which added `admin-js-load-guard.test.js`. 🟢 Re-counted first-hand: `tests/js` now holds **18** `.js` files, of
  which **17** are `*.test.js`; the 18th is `r3-stage-c-harness.js`, a harness. The "JS 17/17" in the handover and
  the ledger refers to the **17 `*.test.js` files**, all passing. Keep stating the `*.test.js` qualifier — the bare
  count moves every time a JS test is added, which is exactly how this line went stale within one session.

### Found while implementing FU-L (2026-08-15) — ✅ BOTH CLOSED 2026-08-16

> **FU-O ✅ `7776f06` · FU-N ✅ `fa324c9`, released in 1.7.97b.** Both were verified against the CODE
> before being touched — neither was implemented, so neither was a stale row. Two notes worth keeping:
>
> - **FU-N's count was wrong.** The row below names TWO fetch chains (submit + refresh). There are
>   **three** — `postAckCdn` was missed because the count came from a hand-written list. Found by
>   sweeping for `fetch(`. All three now have handlers, each mutation-proven.
> - **The version bump was not a judgement call.** B4's drift guard went red on the `settings.js`
>   edit and named the fix — the guard's first real catch, the same day it shipped. The new
>   fingerprint row was ADDED, not rewritten; `SCANNER_JS_VERSION` deliberately did not move,
>   because `scanner.js` did not change.
>
> New coverage: `tests/js/settings-fetch-failure.test.js` drives the real `settings.js` through a
> rejecting fetch (not a source-text pin for `.catch`), and `SettingsAjaxSaveTest` gains the
> rejected-`railway_url` case beside the existing absent- and null- ones.

- ✅ **CLOSED `7776f06`** — **FU-O · a non-allowlisted `railway_url` now misreports a save that succeeded.** `Settings::set_railway_url()`
  throws `RuntimeException( 'Refused to store Railway URL: must be HTTPS and on the host allowlist.' )`
  (`includes/class-settings.php`, re-derive by content). Post-fix the API key is committed **before** that throw, so
  the user sees a Railway-allowlist error on a save that did in fact store their key. Behaviour is safe and strictly
  better than pre-fix, but the message is wrong. Trivial. Bundle with FU-N in Phase 4.
- ✅ **CLOSED `fa324c9`** — **FU-N · `settings.js` `fetch` chains have no `.catch()`** ~~(submit + refresh handlers)~~ **— three chains, not two.** FU-M removes the specific
  500 that exposed this, but any other 5xx or non-JSON response still leaves the form silently doing nothing.
  Trivial; belongs with FU-K/FU-I in Phase 4.

### Found while scoping FU-L (2026-08-15)

- ✅ **CLOSED in Phase 1.5** (bundled on operator instruction) — **FU-M · `$auth['railway_url']` is dereferenced unguarded** at `admin/class-settings-ajax.php:62`, and
  `Settings::set_railway_url()` is typed `string` (`includes/class-settings.php:89`). An auth response missing that
  key therefore yields `null` → **`TypeError`, which is not a `RuntimeException`**, so the `:64` catch does not hold
  it: uncaught fatal, HTTP 500, and `settings.js:41`'s `r.json()` rejects with no `.catch()` — the admin sees a form
  that silently does nothing. Same unguarded shape does **not** appear at `:103`, which tests `! empty( … )` first.
  Small. Sits in the file FU-L is already editing; bundle-or-defer is the operator's call.
- ⏳ **STILL OPEN** — **FU-N**, restated with its Phase-4 home in the *Found while implementing* section above.

## Carried from the prior plan (still open, unrelated to the FU queue)

- ✅ **CLOSED 2026-08-16 — the skill now carries it.** 🟢 Verified in the live `AAS-update/SKILL.md`: a
  *"Compatibility (`tested_wp`) — derive it, never copy it forward"* section with the `api.wordpress.org`
  command, a three-surface table (served manifest / plugin header / `TESTED_WP` fallback), step **4a** in
  the Release Workflow, and a Required-Verification line that fails a release whose `tested_wp` is older
  than current WordPress. Exercised for real on the 1.7.96b release: **7.0.4 derived live**, all three
  surfaces checked and already in agreement. *(Original item kept verbatim below.)*
  ~~**Harden the `AAS-update` skill so `tested_wp` stops being manual**~~ — add a release step that reads
  `https://api.wordpress.org/core/version-check/1.7/` and sets `tested_wp` from it instead of copying a previous
  manifest forward; add it to Required Verification so a stale value blocks the release; cover the in-repo surfaces
  carrying the same fact (plugin header `Tested up to:`, `class-private-updater.php` `TESTED_WP` fallback).
- ✅ **CLOSED 2026-08-16 — both halves fixed in the live skill.** 🟢 Verified: paths are now written as
  `<AI-ROOT>\CU\...` with an explicit note that `<AI-ROOT>` is machine-dependent (it names the PC root and
  the laptop root separately) plus a "resolve it from the paths you are actually working in" instruction —
  which is the whole point, so this file should not restate either literal; the wp-compliance
  count now reads *"it was 25 when this line was written and 28 by 2026-08-15"*, which is accurate rather
  than stale — the live skill announced **28 rules active** when invoked this session. *(Item kept verbatim
  below.)*
  ~~**`AAS-update/SKILL.md` machine-path drift**~~ — carries *[laptop-root literal redacted 2026-08-16 — this
  is a public repo and `public-push-sweep.py` flags it; the original wrote the drive path out in full]*`\CU\...`
  paths that do not exist on this PC, and says
  "25 rules active" for wp-compliance where the live skill announces 28.
- **Release-history gap** — `releases/1.7.92b` and `1.7.93b` exist only on the laptop; the repo's release history is
  not reproducible from this machine alone.

## Review

(completed at close-out)
