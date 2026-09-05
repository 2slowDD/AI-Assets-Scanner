// FU-AAS-SYNC-SCOPE-LAST-SCAN — the two menu-badge.js writers of cu_scanner_result:
//   (iii) W2 triggerBuildResult — hand-built literal, MUST carry apply_* (spec AC-10(iii)).
//         Drives heartbeat-tick -> Railway status -> cu_scanner_build_result -> the write, with the
//         non-vacuity guard from kept-protection-note.test.js §9. Goes through the real fetch promise => await flush().
//   (iv)  W3 pollBadgeState — verbatim writer, MUST stay verbatim (AC-10(iv)). Fire the recorded 2 s poll
//         timer (select by ms === 2000, never by index: the 30 s interval shares the callback). The $.post shim
//         resolves inline => NO flush. applyState no-ops on the harness's null menu link.
const assert = require('assert');
const { createMenuBadgeHarness } = require('./r3-stage-c-harness');
const flush = () => new Promise(r => setImmediate(r));

async function testW2CarriesApplyFields() {
  const mb = createMenuBadgeHarness({
    sessionStorage: { cu_scanner_active_job: JSON.stringify({ job_id: 'job-bg', job_token: 'tok', railway_url: 'https://worker.example' }) },
    fetch: () => Promise.resolve({ json: () => Promise.resolve({ status: 'complete' }) }),
    post: (data) => (data.action !== 'cu_scanner_build_result' ? { success: true, data: {} } : {
      success: true,
      data: { safe_count: 1, aggressive_count: 8, can_push: true, total_pages: 4, scan_id: 'scan-bg', pages: [],
              already_present: null, credits_refunded: null, cu_rules_active: false,
              has_internal_rules: true, apply_safe_count: 1, apply_aggressive_count: 6 },
    }),
  });
  mb.tick({ aias_badge: 'green' });
  await flush();
  assert.ok(mb.posts.some(p => p.action === 'cu_scanner_build_result'), 'the tick really reached build_result (guard the guard)');
  const blob = JSON.parse(mb.sandbox.localStorage.getItem('cu_scanner_result'));
  assert.strictEqual(blob.apply_safe_count, 1, 'W2 carries apply_safe_count');
  assert.strictEqual(blob.apply_aggressive_count, 6, 'W2 carries apply_aggressive_count');
  console.log('OK W2 (triggerBuildResult) carries apply_* to localStorage');
}

function testW3StaysVerbatim() {
  const result = { job_id: 'job-poll', safe_count: 1, agg_count: 8, can_push: true, external_only: false, total_pages: 4, pages: [], scan_id: 'scan-p',
                   already_present: null, credits_refunded: null, cu_rules_active: false, has_internal_rules: true, apply_safe_count: 1, apply_aggressive_count: 6 };
  const mb = createMenuBadgeHarness({
    localStorage: { cu_scanner_result: JSON.stringify({ job_id: 'someone-else' }) },   // non-matching job => the write gate opens
    post: (data) => (data.action === 'cu_scanner_get_badge_state' ? { success: true, data: { badge: 'green', result: result } } : { success: true, data: {} }),
  });
  const poll = mb.timers.find(t => t.ms === 2000);
  assert.ok(poll, 'the 2 s badge poll timer was registered at load');
  poll.fn();   // synchronous: the $.post shim resolves inline
  assert.ok(mb.posts.some(p => p.action === 'cu_scanner_get_badge_state'), 'the poll really posted');
  assert.strictEqual(mb.sandbox.localStorage.getItem('cu_scanner_result'), JSON.stringify(result), 'W3 wrote res.data.result byte-for-byte (verbatim writer stays verbatim)');
  console.log('OK W3 (pollBadgeState) stays verbatim');
}

(async () => {
  await testW2CarriesApplyFields();
  testW3StaysVerbatim();
  console.log('apply-scope-menu-badge: all assertions passed');
})().catch((e) => { console.error(e); process.exitCode = 1; });
