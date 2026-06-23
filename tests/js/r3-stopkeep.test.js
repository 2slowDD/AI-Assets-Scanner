const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');

function run() {
  const now = Date.now();
  let cancelCalled = false, confirmShown = '';
  const h = createHarness({
    confirm: (msg) => { confirmShown = msg; return true; },
    // stub the post() path: scanner.js calls fetch to admin-ajax for cu_scanner_cancel_job
    fetch: (url, opt) => {
      if (String(opt && opt.body).indexOf('cu_scanner_cancel_job') !== -1) {
        cancelCalled = true;
        return Promise.resolve({ json: () => Promise.resolve({ success: true, data: { pages_completed: 2 } }) });
      }
      return Promise.resolve({ ok: true, json: () => Promise.resolve({ status: 'paused', resume_at: now + 1000, completed: 2, total: 7, pages: [] }) });
    },
  });
  const T = h.sandbox.window.__cuTest;
  T.handleStatusUpdate({ status: 'paused', resume_at: now + 125000, rung_ms: 900000, completed: 2, total: 7, pages: [] });
  h.els['cu-paused-stopkeep'].click();

  return new Promise((resolve) => setImmediate(() => {
    assert.ok(/Keep your 2 completed pages/.test(confirmShown), 'paused-context confirm copy');
    assert.ok(cancelCalled, 'cu_scanner_cancel_job called');
    assert.strictEqual(h.timers.filter((t) => t.type === 'interval' && !t.cleared).length, 0,
                       'countdown cleared on Stop&keep');
    console.log('OK r3-stopkeep'); resolve();
  }));
}
run();
