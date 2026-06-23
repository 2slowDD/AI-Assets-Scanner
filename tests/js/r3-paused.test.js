const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');

// Drive the paused branch by stubbing fetch to return a paused payload, then
// invoking the poll loop. We reach handleStatusUpdate via the exposed seam.
function run() {
  const now = Date.now();
  const h = createHarness();
  const T = h.sandbox.window.__cuTest;
  assert.ok(T.handleStatusUpdate, 'handleStatusUpdate must be exposed on __cuTest');

  // 1) paused render + single countdown timer
  T.handleStatusUpdate({ status: 'paused', resume_at: now + 125000, rung_ms: 900000,
                         completed: 2, total: 7, pages: [] });
  const banner = h.els['cu-paused-banner'];
  assert.ok(/Scan paused/.test(banner.innerHTML), 'paused banner rendered');
  assert.ok(/Stop & keep/.test(banner.innerHTML), 'Stop&keep button present');
  const intervals = h.timers.filter((t) => t.type === 'interval' && !t.cleared);
  assert.strictEqual(intervals.length, 1, 'exactly one countdown interval');

  // 2) re-poll while still paused (escalation) → NO second interval
  T.handleStatusUpdate({ status: 'paused', resume_at: now + 3600000, rung_ms: 3600000,
                         completed: 2, total: 7, pages: [] });
  assert.strictEqual(h.timers.filter((t) => t.type === 'interval' && !t.cleared).length, 1,
                     'still exactly one interval after escalation');

  // 3) leave paused → teardown guard clears the interval
  T.handleStatusUpdate({ status: 'in_progress', completed: 3, total: 7, pages: [] });
  assert.strictEqual(h.timers.filter((t) => t.type === 'interval' && !t.cleared).length, 0,
                     'interval cleared on non-paused exit');
  console.log('OK r3-paused');
}
run();
