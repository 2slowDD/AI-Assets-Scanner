const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');

// FU — a completed scan that produced 0 rules (0 safe + 0 aggressive) must leave BOTH
// "Push to Code Unloader" and "Sync with Code Unloader" dormant — there is nothing to push.
function runZeroRules() {
  const h = createHarness();
  const T = h.sandbox.window.__cuTest;
  assert.ok(T.restoreStep4, 'restoreStep4 must be exposed on __cuTest');

  // 0 safe + 0 aggressive, canPush=true (would normally ENABLE both), not external, no requeue.
  T.restoreStep4({ jobId: 'job1', safeCount: 0, aggCount: 0, canPush: true, externalOnly: false,
                   bannerData: {}, urlsScanned: 5, pages: [], scanId: 'scan1', hasActiveCuRules: false });

  const push = h.els['cu-btn-push'];
  const sync = h.els['cu-btn-sync'];
  assert.strictEqual(push.disabled, true, 'Push is disabled when 0 rules');
  assert.ok(push._classes.has('cu-btn-dormant'), 'Push has cu-btn-dormant when 0 rules');
  assert.strictEqual(sync.disabled, true, 'Sync is disabled when 0 rules');
  assert.ok(sync._classes.has('cu-btn-dormant'), 'Sync has cu-btn-dormant when 0 rules');
  console.log('OK zero-rules-buttons');
}

// Regression guard — a scan WITH rules must keep Push enabled (the 0-rules branch must
// not over-fire and dormant a pushable result).
function runWithRules() {
  const h = createHarness();
  const T = h.sandbox.window.__cuTest;
  T.restoreStep4({ jobId: 'job2', safeCount: 1, aggCount: 0, canPush: true, externalOnly: false,
                   hasInternalRules: true,
                   bannerData: {}, urlsScanned: 5, pages: [], scanId: 'scan2', hasActiveCuRules: false });
  const push = h.els['cu-btn-push'];
  assert.strictEqual(push.disabled, false, 'Push enabled when >=1 rule exists');
  assert.ok(!push._classes.has('cu-btn-dormant'), 'Push not dormant when rules exist');
  console.log('OK with-rules-buttons');
}

// A mixed scan can contain aggregate recommendations from external URLs while producing
// no rules eligible for this site's Code Unloader. Both direct actions must remain visible
// but dormant, matching the server-side host filter used by Push and Sync.
function runExternalRecommendationsOnly() {
  const h = createHarness();
  const T = h.sandbox.window.__cuTest;
  T.restoreStep4({ jobId: 'job-mixed', safeCount: 0, aggCount: 15, canPush: true, externalOnly: false,
                   hasInternalRules: false,
                   bannerData: {}, urlsScanned: 4, pages: [], scanId: 'scan-mixed', hasActiveCuRules: false });

  const push = h.els['cu-btn-push'];
  const sync = h.els['cu-btn-sync'];
  assert.strictEqual(push.style.display, '', 'Push remains visible when no internal rules exist');
  assert.strictEqual(sync.style.display, '', 'Sync remains visible when no internal rules exist');
  assert.strictEqual(push.disabled, true, 'Push is disabled when only external recommendations exist');
  assert.strictEqual(sync.disabled, true, 'Sync is disabled when only external recommendations exist');
  assert.ok(push._classes.has('cu-btn-dormant'), 'Push has cu-btn-dormant with no internal rules');
  assert.ok(sync._classes.has('cu-btn-dormant'), 'Sync has cu-btn-dormant with no internal rules');
  console.log('OK external-recommendations-only buttons');
}

// A zero-result rescan is page-result state, not sticky UI state. Reusing Step 4 for a
// later positive result must hide the bulk rescan again.
function runZeroResultRescanReset() {
  const h = createHarness({
    querySelectorAll: (selector, els) => selector === '#step-4 .cu-btn-rescan-noopt-all'
      ? [els['cu-rescan-noopt-button']]
      : [],
  });
  const T = h.sandbox.window.__cuTest;
  const button = h.els['cu-rescan-noopt-button'];
  button.style.display = 'none';

  T.restoreStep4({ jobId: 'job-zero-page', safeCount: 0, aggCount: 0, canPush: false, externalOnly: false,
                   bannerData: {}, urlsScanned: 1, scanId: 'scan-zero-page', hasActiveCuRules: false,
                   pages: [{ url: 'https://example.test/zero', status_class: 'ok', safe: 0, aggressive: 0, needed: 12, credits: 1 }] });
  assert.strictEqual(button.style.display, '', 'OK S:0 A:0 reveals Rescan 0-Results URLs');

  T.restoreStep4({ jobId: 'job-positive-page', safeCount: 1, aggCount: 0, canPush: true, externalOnly: false,
                   bannerData: {}, urlsScanned: 1, scanId: 'scan-positive-page', hasActiveCuRules: false,
                   pages: [{ url: 'https://example.test/positive', status_class: 'ok', safe: 1, aggressive: 0, needed: 10, credits: 1 }] });
  assert.strictEqual(button.style.display, 'none', 'later positive result hides Rescan 0-Results URLs');
  console.log('OK zero-result rescan visibility resets');
}

runZeroRules();
runWithRules();
runExternalRecommendationsOnly();
runZeroResultRescanReset();
