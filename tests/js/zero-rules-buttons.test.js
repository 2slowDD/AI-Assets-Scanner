const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');

// FU — a completed scan that produced 0 rules (0 safe + 0 aggressive) must leave BOTH
// "Push to Code Unloader" and "Sync with Code Unloader" dormant — there is nothing to push.
function runZeroRules() {
  const h = createHarness();
  const T = h.sandbox.window.__cuTest;
  assert.ok(T.restoreStep4, 'restoreStep4 must be exposed on __cuTest');

  // 0 safe + 0 aggressive, canPush=true (would normally ENABLE both), not external, no requeue.
  T.restoreStep4('job1', 0, 0, true, false, {}, 5, [], 'scan1', false);

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
  T.restoreStep4('job2', 1, 0, true, false, {}, 5, [], 'scan2', false);
  const push = h.els['cu-btn-push'];
  assert.strictEqual(push.disabled, false, 'Push enabled when >=1 rule exists');
  assert.ok(!push._classes.has('cu-btn-dormant'), 'Push not dormant when rules exist');
  console.log('OK with-rules-buttons');
}

runZeroRules();
runWithRules();
