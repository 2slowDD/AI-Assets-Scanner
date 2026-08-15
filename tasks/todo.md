# 1.7.94b release + AAS-update rule hardening — todo

**Date:** 2026-08-15 · **Plugin:** AI Assets Scanner · **Branch:** `feat/challenge-keeplist-note` @ `eaa551f` (pushed to `main`)

> (Prior content — the FU-AAS-CACHE-STACK-NOTICE-MISSING / 1.7.29b plan, June 2026 — was a completed task
> shipped long ago. Replaced with the active task per the P7 tasks/todo.md workflow, matching this file's
> own precedent.)

## Context

Train 2 (challenge-script keeplist) shipped to `main` as `576361a..eaa551f`, 14 commits, pure fast-forward.
**Code is on GitHub; nothing is on the update host.** AAS does not auto-deploy — the operator-visible plugin
row moves only when the release train runs.

🟢 **Verified 2026-08-15:**
- Live manifest `updates.wpservice.pro/ai-assets-scanner/stable.json` = version **1.7.93b**, `tested_wp` **"7.0.3"**.
- `api.wordpress.org/core/version-check/1.7/` → current WordPress = **7.0.4** (so the 7.0.4 we shipped in-repo is correct).
- Local `releases/` holds 42 dirs, newest **1.7.91b**; **1.7.92b and 1.7.93b dirs are absent on this machine** (they exist only on the laptop — known, recorded in the Train-2 handoff). Not a blocker: 1.7.94b is built fresh.
- `build-release.py` present; Python at `C:\Python314\python.exe`.
- `build-release.py` writes the ZIP, `checksum.txt` and `changelog.html` — it does **NOT** write `stable.json` (hand-written, Write tool, UTF-8 no BOM).

## Plan

### A — Post-push ratification (operator answered `y`)
- [ ] Propose the specific doc/plan files + sections to ratify; **wait for confirmation before editing**.

### B — Build 1.7.94b release artifacts (LOCAL ONLY, non-destructive)
- [ ] `python build-release.py 1.7.94b` from the worktree root.
- [ ] Verify builder output: `BACKSLASH_COUNT 0`, `HAS_MAIN True`, `UPPERCASE_ROOT False`, `CHANGELOG_HTML present|generated`, non-zero `ZIP_BYTES`.
- [ ] Confirm ZIP first entries start `ai-assets-scanner/` and `Get-FileHash -Algorithm SHA256` matches `checksum.txt`.
- [ ] Confirm `changelog.html` exists and is non-empty (it is the `stable.json.changelog_url` target — a missing one silently 404s).
- [ ] Write `releases/1.7.94b/stable.json` with the Write tool: `version` 1.7.94b, **`tested_wp` "7.0.4"** (⚠️ every tracked `releases/*/stable.json` carries "7.0" — copying one forward re-ships the stale value), `sha256` = built ZIP hash, `download_url` + `changelog_url` pointing at 1.7.94b, `released_at` 2026-08-15.
- [ ] Verify manifest: first byte `0x7B` (no BOM), parses as JSON, `sha256` matches, `version` matches the three-place lockstep (1.7.94b).

### C — Publish to the update host (OUTWARD-FACING)
- [ ] ⛔ **Blocked on credentials I do not hold.** SFTP to Hostinger + Cloudflare purge are operator-held.
      Upload order is load-bearing: `ai-assets-scanner.zip` → `changelog.html` → `checksum.txt` → **`stable.json` LAST**.
      Target: `/home/u367160631/domains/wpservice.pro/public_html/updates/ai-assets-scanner/`.
- [ ] Purge Cloudflare for `stable.json`.
- [ ] Post-publish verify: re-GET the live manifest and confirm `version` 1.7.94b + `tested_wp` "7.0.4"; GET the ZIP URL and confirm its SHA256 matches the manifest.

### D — Harden the AAS-update rule so `tested_wp` stops being manual
- [ ] Add a release-workflow step that queries `https://api.wordpress.org/core/version-check/1.7/` for the current
      WordPress version and sets `tested_wp` from it, rather than copying a previous manifest forward.
- [ ] Add it to Required Verification so a stale `tested_wp` blocks the release.
- [ ] Also cover the in-repo surfaces the same fact lives on: plugin header `Tested up to:` and
      `class-private-updater.php` `TESTED_WP` fallback — all three must agree with the live WP version at release time.
- [ ] Fix the skill's stale machine paths (`C:\AI\...` → `D:\AI\...` on this PC) found while reading it.

## Follow-ups discovered during this task
- **FU-H** (already filed in `10-improvements/improvements-ledger.md`): nothing in the suite enforces the
  three-place version lockstep — mutating the header *or* the constant leaves the suite green because
  `tests/bootstrap.php:7` shadows the constant.
- Skill drift: `AAS-update/SKILL.md` carries `C:\AI\CU\...` repo + ledger paths that do not exist on this machine,
  and says "25 rules active" for wp-compliance where the live skill announces 28. Both cost a fresh agent a
  wrong turn. (Fixed under D.)
- Release-dir gap: `releases/1.7.92b` and `1.7.93b` are absent locally (laptop-only). Harmless for this release,
  but the repo's release history is not reproducible from this machine alone.

## Review

(completed at close-out)
