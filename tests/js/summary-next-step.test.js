// FU-AAS-SUMMARY-NEXT-STEP — the Step-4 summary line tells the operator what to do with the
// rules it just reported, and names ONLY controls that are visible + enabled in that state.
// Renders the REAL admin/js/scanner.js through restoreStep4 (no jest in this repo; see
// r3-stage-c-harness.js) and reads the REAL #cu-result-summary element, so a hint that
// disagrees with the button branches shows up here.
//
// The load-bearing case is #2: an external-only scan HIDES Push and Sync
// (scanner.js restoreStep4 -> `if (externalOnly)`), so inviting the operator to "push or
// sync" there points at two buttons that are display:none. That is the exact state in the
// screenshot that prompted this change.
//
// Each case goes RED under a different specific mistake:
//   1. hint never wired, or wrong copy for the normal case -> testCanPushInvitesPushOrSync
//   2. hint promises push/sync when both buttons are hidden -> testExternalOnlyOffersDownloadOnly
//   3. hint invites action on a scan with nothing to apply  -> testNoRulesStaysSilent
//   4. hint names Push when Push is deliberately disabled   -> testSyncOnlyNamesSyncOnly
//   5. base summary text mangled by the append              -> testBaseSummaryLineSurvives
const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');

function summaryFor(opts) {
  const h = createHarness();
  h.sandbox.window.__cuTest.restoreStep4(Object.assign({
    jobId: 'job1', safeCount: 0, aggCount: 49, canPush: true, externalOnly: false,
    bannerData: {}, urlsScanned: 5, pages: [], scanId: 'scan1', hasActiveCuRules: false
  }, opts));
  return h.els['cu-result-summary'].textContent;
}

// 1. The normal case: both buttons live, so both may be named.
function testCanPushInvitesPushOrSync() {
  const s = summaryFor({ canPush: true, externalOnly: false });
  assert.ok(/You can apply them now with the Push or Sync buttons\.$/.test(s),
    'a pushable scan invites Push or Sync; got: ' + JSON.stringify(s));
  console.log('OK canPush -> "apply them now with the Push or Sync buttons"');
}

// 2. External-only HIDES both buttons — the hint must offer the download instead.
function testExternalOnlyOffersDownloadOnly() {
  const s = summaryFor({ externalOnly: true, aggCount: 49 });
  assert.ok(/download the CU import file/.test(s),
    'external-only must point at the download; got: ' + JSON.stringify(s));
  assert.ok(!/Push/.test(s), 'external-only must never name Push — the button is hidden');
  assert.ok(!/Sync/.test(s), 'external-only must never name Sync — the button is hidden');
  console.log('OK externalOnly -> download only, never Push/Sync');
}

// 3. Nothing was produced, so there is nothing to apply and no hint to give.
function testNoRulesStaysSilent() {
  const s = summaryFor({ safeCount: 0, aggCount: 0 });
  assert.ok(!/You can /.test(s), 'a 0-rule scan must not invite any action; got: ' + JSON.stringify(s));
  console.log('OK 0-rule scan appends no hint');
}

// 4. Re-scan state: Push is deliberately disabled, so only Sync may be named.
function testSyncOnlyNamesSyncOnly() {
  const h = createHarness();
  h.sandbox.window.localStorage.setItem('cu_scanner_requeue_job1', '1');
  h.sandbox.window.__cuTest.restoreStep4({
    jobId: 'job1', safeCount: 0, aggCount: 49, canPush: true, externalOnly: false,
    bannerData: {}, urlsScanned: 5, pages: [], scanId: 'scan1', hasActiveCuRules: true
  });
  const s = h.els['cu-result-summary'].textContent;
  assert.ok(/with the Sync button/.test(s), 'sync-only must name Sync; got: ' + JSON.stringify(s));
  assert.ok(!/Push/.test(s), 'sync-only must never name Push — that button is dormant here');
  console.log('OK syncOnly -> Sync only, never Push');
}

// 5. Every rule is already in Code Unloader. buildSyncCopy puts "Nothing new to sync" on the
//    same screen, so an invite to Push/Sync here would contradict it. Caught by
//    result-truth-display.test.js when this hint was first wired unconditionally.
function testNothingNewStaysSilent() {
  const s = summaryFor({
    safeCount: 0, aggCount: 3, canPush: true, externalOnly: false,
    alreadyPresent: { safe: 0, aggressive: 3 }
  });
  assert.ok(/already in Code Unloader$/.test(s),
    'the summary must end at the already-present clause; got: ' + JSON.stringify(s));
  assert.ok(!/You can /.test(s),
    'no apply invite when every rule is already in CU — it would contradict "Nothing new to sync"');
  console.log('OK all-already-present appends no hint');
}

// 6. The hint is appended, so the original summary sentence must survive it intact.
function testBaseSummaryLineSurvives() {
  const s = summaryFor({ urlsScanned: 5, safeCount: 0, aggCount: 49 });
  assert.ok(s.indexOf('Scan complete. 5 URLs scanned, 0 safe rules, 49 aggressive rules generated.') === 0,
    'the base summary must remain, unchanged, at the front; got: ' + JSON.stringify(s));
  console.log('OK base summary line survives the append');
}

testCanPushInvitesPushOrSync();
testExternalOnlyOffersDownloadOnly();
testNoRulesStaysSilent();
testSyncOnlyNamesSyncOnly();
testNothingNewStaysSilent();
testBaseSummaryLineSurvives();
console.log('summary-next-step: all assertions passed');
