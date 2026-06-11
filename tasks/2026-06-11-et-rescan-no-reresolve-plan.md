# Plan — ET rescan must NOT re-resolve URLs (FU-AAS-SUFFIX-DROP-ON-RESOLVE)

> **Status (2026-06-11):** plan drafted; awaiting operator GO.
> **Operator directive (2026-06-11, verbatim intent):** "I don't want URLs resolving between those ET
> continuation scans, they should be identical for the scan. Resolve should fire only on a first scan
> (if needed)."
> **Version:** 1.7.29b → **1.7.30b** (beta `b`-suffix patch-bump rule). **Repo:** 2slowDD/AI-Assets-Scanner **main**. Deploy: operator SFTP.

## Root cause (🟢 CONFIRMED, all file:line verified this session)

The ET rescan ("Rescan ET Candidates") carries the Step-4 **result** URLs into a full re-run of the
normal submit flow, which treats them as fresh input:

1. `primeRescanEt()` (scanner.js:1662-1683) loads the prior scan's final page URLs into
   `selectedUrls`/`extraTimeUrls` (`etCarryOver = true`).
2. The Start-Scan handler re-runs the external probe (`cu_scanner_probe_target_stack`,
   scanner.js:986-1011) → server `wp_remote_get` redirect-following re-resolves each URL fresh.
3. `resolvedByUrl` is repopulated and the submission maps through it:
   `urls: selectedUrls.map(u => resolvedByUrl[u] || u)` (scanner.js:1039; also 1042 `extra_time_urls`,
   1063 consent-retry).
4. Live consequence 2026-06-11: rescan probe of `getkush.cc?nowprocket` hit a redirect to
   `getkush.cc/` → suffix LOST → scanned un-bypassed AND `r_orig_matches()`
   (class-scanner-ajax.php:1501-1517) compares Railway page URLs vs persisted R_orig URLs → mismatch
   → **ET-ratchet gate can never open** for these URLs.

## Fix design (JS-only; minimal diff; probe behavior otherwise unchanged)

Track which URLs were **carried over from a prior result** and force identity resolution for exactly
those. New/extra URLs added during the carried view still resolve normally (= "resolve only on a
first scan, if needed").

1. **New IIFE-scope var** `etCarriedUrls = []`, declared next to the `resolvedByUrl` declaration
   (~scanner.js:970) — same scope as all readers (1.7.18/1.7.19 cross-function-scope lesson:
   ⚠️ verified by runtime walk-through, not just review).
2. **`primeRescanEt()`:** `etCarriedUrls = etUrls.slice();`
3. **`saveEtCarryOver()` (scanner.js:756-766):** add `etCarriedUrls: etCarriedUrls` to the blob.
4. **`restoreEtCarryOver()` (scanner.js:1688-1709):**
   `etCarriedUrls = Array.isArray(d.etCarriedUrls) ? d.etCarriedUrls : d.discoveredUrls.slice();`
   (old-blob fallback: treat all restored URLs as carried — conservative, F-DEG-safe).
5. **`clearEtCarryOver()` (scanner.js:767-770):** `etCarriedUrls = [];` (single reset path; marker
   one-writer-one-semantic lesson respected — every writer means "URLs carried from a prior result").
6. **Submit handler**, immediately after the probe block (after scanner.js:1021):
   ```js
   // FU-AAS-SUFFIX-DROP-ON-RESOLVE — carried-over ET URLs are scanned byte-identically;
   // resolution fires only on a URL's first scan (operator directive 2026-06-11).
   etCarriedUrls.forEach(function (u) { resolvedByUrl[u] = u; });
   ```
   Identity entries are inert everywhere: submission maps yield `u` (1039/1042/1063), and the
   "← resolved from" note has a `resolved !== submitted` guard (scanner.js:1361) so no self-note renders.
7. **Version bump** 1.7.29b → 1.7.30b (plugin header + `CU_SCANNER_VERSION` — JS cache-bust).

**Probe is intentionally KEPT on rescans** (stack warnings, Class B/C dialogs, bypass suggestions
unchanged) — only the *application of its resolved_url* is suppressed for carried URLs.
⚠️ **Assumption** — probe's `suggested_bypass_per_url` for carried URLs is harmless to keep sending
(the carried URL already embeds whatever bypass the operator used); basis: bypass map is suggestions,
not rewrites. Verified harmless if wrong: server ignores unknown entries.

## What this does NOT touch

- No PHP logic changes (class-scanner-ajax.php untouched except none; version constants only).
- No worker changes. No ratchet/merger changes. No new env vars, options, or endpoints.
- wp-compliance: no new input surfaces; render path already `cuEscHtml`-escaped. Checklist run: clean.

## ACs

- **AC-1 (the fix):** ET rescan submits carried URLs byte-identically (DevTools: `cu_scanner_submit_job`
  payload `urls[]` === Step-4 URLs of the prior scan), regardless of probe redirects.
- **AC-2:** No "← resolved from" note on carried URLs in the rescan result.
- **AC-3 (regression):** Fresh first scan of a redirecting external URL still resolves + shows the note.
- **AC-4:** A NEW external URL manually added during the carried view still resolves (first-scan semantics).
- **AC-5:** Carry-over round-trip (navigate away → return → scan) preserves no-re-resolve behavior
  (etCarriedUrls survives via localStorage blob).
- **AC-6 (the payoff — closes FU-RATCHET-BENIGN-RESTORE):** getkush ET cycle with CU_SCANNER_DEBUG on:
  `[ratchet][gate] r_orig_matches:true` → `[merged] recovered>0` → final A preserved.

## Validation before deploy

- `node --check admin/js/scanner.js` (syntax).
- Runtime walk-through of the rescan flow (cross-function-scope lesson — review alone insufficient).
- PHPUnit suite: expected no-op (JS-only) — run anyway to confirm baseline unchanged.

## Follow-ups discovered during this task

- The probe abort path (probe failure → alert → return, scanner.js:991-1003) blocks a rescan even
  though carried URLs don't need resolution. Pre-existing; candidate soft-fail for rescans. Not bundled
  (separate failure surface).
- `persist_r_orig` self-clobber (FU-AAS-PERSIST-SELF-CLOBBER, already in ledger) — unchanged here.
