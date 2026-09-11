// 1.8.7 — the Step-4 Sync / Push busy indicator, through the REAL scanner.js click handlers.
//
// Every Code Unloader write is HELD: the stubbed fetch returns a promise this file settles by hand,
// so the in-flight state is observable before each exit path is driven. P17 — every exit path is
// driven here, not just the happy one:
//   - in flight: #cu-sync-push-busy carries the exact busy copy plus an aria-hidden spinner, and
//     BOTH action buttons are locked (today only the clicked one was)
//   - settled — success, server error, network failure, needs_confirm -> Cancel, needs_confirm ->
//     OK -> second request: the line is empty, the OTHER button is back exactly as it was, and the
//     CLICKED button follows the unchanged rule (success keeps it disabled; error / Cancel re-enable)
//   - a missing status line fails OPEN: the lock and the request still happen
//   - the ET column tooltip states the conditional credit (visible box and aria-label)
const assert = require('assert');
const { createHarness, makeEl } = require('./r3-stage-c-harness');

const SYNC_BUSY = 'Syncing with Code Unloader… This can take a while for large rule sets.';
const PUSH_BUSY = 'Pushing to Code Unloader… This can take a while for large rule sets.';

function flush() { return new Promise((r) => setImmediate(r)).then(() => new Promise((r) => setImmediate(r))); }

// Background actions (detect_plugins on load, …) get a benign {}; the two Code Unloader writes are
// held until the test settles them.
function setup(opts = {}) {
  const held = [];
  const h = createHarness({
    confirm: opts.confirm,
    fetch: (url, opt) => {
      const body = opt && opt.body;
      const action = body && typeof body.get === 'function' ? body.get('action') : '?';
      if (action !== 'cu_scanner_sync_to_cu' && action !== 'cu_scanner_push_to_cu') {
        return Promise.resolve({ ok: true, json: () => Promise.resolve({}) });
      }
      return new Promise((resolve, reject) => {
        held.push({
          action,
          confirmed: String(body.get('confirmed')),
          respond: (json) => resolve({ ok: true, json: () => Promise.resolve(json) }),
          fail: () => reject(new TypeError('Failed to fetch')),
        });
      });
    },
  });
  // The Undo button is in the shipped view but not in the harness's default id list.
  h.els['cu-btn-undo-last-push-sync'] = makeEl('cu-btn-undo-last-push-sync', 'button');
  return { h, held, line: h.els['cu-sync-push-busy'], sync: h.els['cu-btn-sync'], push: h.els['cu-btn-push'] };
}

function assertBusy(t, copy, label) {
  assert.strictEqual(t.line.textContent, copy, label + ': the status line carries the busy copy');
  assert.strictEqual(t.line.children.length, 2, label + ': spinner + text');
  const spin = t.line.children[0];
  assert.strictEqual(spin.className, 'cu-sync-push-busy-spinner', label + ': the spinner carries the class the CSS animates');
  assert.strictEqual(spin.getAttribute('aria-hidden'), 'true', label + ': the spinner is hidden from screen readers');
  assert.strictEqual(t.sync.disabled, true, label + ': Sync is locked');
  assert.strictEqual(t.push.disabled, true, label + ': Push is locked');
}

function assertIdle(t, label) {
  assert.strictEqual(t.line.textContent, '', label + ': the status line is empty');
  assert.strictEqual(t.line.children.length, 0, label + ': no spinner left behind');
}

const SYNC_OK = { appended_safe: 1, appended_aggressive: 2, already_present: 0, error_count: 0, error_message: '', created_rule_ids: [1, 2, 3], undo_state: { available: false } };
const PUSH_OK = { safe_count: 3, aggressive_count: 4, error_count: 0, error_message: '', created_rule_ids: [4, 5], undo_state: { available: false } };

const tests = [];
function test(name, fn) { tests.push({ name, fn }); }

test('Sync success: busy while held; then the line empties, Push is released, Sync stays disabled', async () => {
  const t = setup();
  t.sync.click();
  await flush();
  assert.strictEqual(t.held.length, 1, 'one Sync request is in flight');
  assert.strictEqual(t.held[0].action, 'cu_scanner_sync_to_cu');
  assertBusy(t, SYNC_BUSY, 'sync in flight');
  t.held[0].respond({ success: true, data: SYNC_OK });
  await flush();
  assertIdle(t, 'sync settled');
  assert.strictEqual(t.push.disabled, false, 'Push is released');
  assert.strictEqual(t.sync.disabled, true, 'Sync stays disabled after success (unchanged)');
  assert.ok(t.h.els['cu-push-result'].innerHTML.includes('Synced to Code Unloader — appended 1 safe + 2 aggressive rules (0 already present).'), 'the success notice still renders: ' + t.h.els['cu-push-result'].innerHTML);
});

test('Sync server error: the line empties and both buttons are back', async () => {
  const t = setup();
  t.sync.click();
  await flush();
  assertBusy(t, SYNC_BUSY, 'sync in flight');
  t.held[0].respond({ success: false, data: 'Code Unloader not active' });
  await flush();
  assertIdle(t, 'sync error');
  assert.strictEqual(t.sync.disabled, false, 'Sync is re-enabled (unchanged)');
  assert.strictEqual(t.push.disabled, false, 'Push is released');
  assert.ok(t.h.els['cu-push-result'].innerHTML.includes('Error: Code Unloader not active'), 'the error notice still renders');
});

test('Sync network failure: the line empties and both buttons are back', async () => {
  const t = setup();
  t.sync.click();
  await flush();
  assertBusy(t, SYNC_BUSY, 'sync in flight');
  t.held[0].fail();
  await flush();
  assertIdle(t, 'sync network failure');
  assert.strictEqual(t.sync.disabled, false, 'Sync is re-enabled (unchanged)');
  assert.strictEqual(t.push.disabled, false, 'Push is released');
  assert.ok(t.h.els['cu-push-result'].innerHTML.includes('Sync failed — check server error logs.'), 'the failure notice still renders');
});

test('Sync while Push is already disabled (re-scan, sync-only): Push stays disabled afterwards', async () => {
  const t = setup();
  t.push.disabled = true;
  t.sync.click();
  await flush();
  assertBusy(t, SYNC_BUSY, 'sync in flight');
  t.held[0].respond({ success: true, data: SYNC_OK });
  await flush();
  assertIdle(t, 'sync settled');
  assert.strictEqual(t.push.disabled, true, 'Push goes back to its PRIOR state — disabled — not to enabled');
});

test('Push success without a confirm: busy while held; then the line empties, Sync is released, Push stays disabled', async () => {
  const t = setup();
  t.push.click();
  await flush();
  assert.strictEqual(t.held.length, 1, 'one Push request is in flight');
  assert.strictEqual(t.held[0].action, 'cu_scanner_push_to_cu');
  assert.strictEqual(t.held[0].confirmed, '0', 'the first Push is unconfirmed');
  assertBusy(t, PUSH_BUSY, 'push in flight');
  t.held[0].respond({ success: true, data: PUSH_OK });
  await flush();
  assertIdle(t, 'push settled');
  assert.strictEqual(t.sync.disabled, false, 'Sync is released');
  assert.strictEqual(t.push.disabled, true, 'Push stays disabled after success (unchanged)');
  assert.ok(t.h.els['cu-push-result'].innerHTML.includes('Rules added to Code Unloader: 3 safe, 4 aggressive.'), 'the success notice still renders: ' + t.h.els['cu-push-result'].innerHTML);
});

test('Push server error: the line empties and both buttons are back', async () => {
  const t = setup();
  t.push.click();
  await flush();
  assertBusy(t, PUSH_BUSY, 'push in flight');
  t.held[0].respond({ success: false, data: 'No internal rules to push' });
  await flush();
  assertIdle(t, 'push error');
  assert.strictEqual(t.push.disabled, false, 'Push is re-enabled (unchanged)');
  assert.strictEqual(t.sync.disabled, false, 'Sync is released');
  assert.ok(t.h.els['cu-push-result'].innerHTML.includes('Error: No internal rules to push'), 'the error notice still renders');
});

test('Push network failure: the line empties and both buttons are back', async () => {
  const t = setup();
  t.push.click();
  await flush();
  assertBusy(t, PUSH_BUSY, 'push in flight');
  t.held[0].fail();
  await flush();
  assertIdle(t, 'push network failure');
  assert.strictEqual(t.push.disabled, false, 'Push is re-enabled (unchanged)');
  assert.strictEqual(t.sync.disabled, false, 'Sync is released');
  assert.ok(t.h.els['cu-push-result'].innerHTML.includes('Push failed — check server error logs.'), 'the failure notice still renders');
});

test('Push needs_confirm -> Cancel: the line is empty while the dialog is up; then both buttons are back', async () => {
  const atConfirm = [];
  let t = null;
  t = setup({ confirm: () => { atConfirm.push(t.line.textContent); return false; } });
  t.push.click();
  await flush();
  assertBusy(t, PUSH_BUSY, 'first push in flight');
  t.held[0].respond({ success: true, data: { needs_confirm: true } });
  await flush();
  assert.strictEqual(atConfirm.length, 1, 'the overwrite confirm was shown once');
  assert.strictEqual(atConfirm[0], '', 'nothing is in flight while the dialog is up, so the line is already empty');
  assert.strictEqual(t.held.length, 1, 'Cancel sends no second request');
  assertIdle(t, 'push cancelled');
  assert.strictEqual(t.push.disabled, false, 'Push is re-enabled by Cancel (unchanged)');
  assert.strictEqual(t.sync.disabled, false, 'Sync is released');
});

test('Push needs_confirm -> OK: the confirmed request is busy again, then settles', async () => {
  const atConfirm = [];
  let t = null;
  t = setup({ confirm: () => { atConfirm.push(t.line.textContent); return true; } });
  t.push.click();
  await flush();
  assertBusy(t, PUSH_BUSY, 'first push in flight');
  t.held[0].respond({ success: true, data: { needs_confirm: true } });
  await flush();
  assert.strictEqual(atConfirm.length, 1, 'the overwrite confirm was shown once');
  assert.strictEqual(atConfirm[0], '', 'nothing is in flight while the dialog is up');
  assert.strictEqual(t.held.length, 2, 'OK sends the confirmed request');
  assert.strictEqual(t.held[1].confirmed, '1', 'the second Push is confirmed');
  assertBusy(t, PUSH_BUSY, 'confirmed push in flight');
  t.held[1].respond({ success: true, data: PUSH_OK });
  await flush();
  assertIdle(t, 'confirmed push settled');
  assert.strictEqual(t.sync.disabled, false, 'Sync is released');
  assert.strictEqual(t.push.disabled, true, 'Push stays disabled after success (unchanged)');
});

test('A missing status line fails open: Sync still locks both buttons, posts, and releases Push', async () => {
  const t = setup();
  delete t.h.els['cu-sync-push-busy'];
  t.sync.click();
  await flush();
  assert.strictEqual(t.held.length, 1, 'the Sync request still goes');
  assert.strictEqual(t.sync.disabled, true, 'Sync is locked');
  assert.strictEqual(t.push.disabled, true, 'Push is locked');
  t.held[0].respond({ success: true, data: SYNC_OK });
  await flush();
  assert.strictEqual(t.push.disabled, false, 'Push is released');
  // The handler still ran after release(): a throwing release would have skipped it and landed in the
  // catch ("Sync failed" + Sync re-enabled) after a successful Sync.
  assert.strictEqual(t.sync.disabled, true, 'Sync stays disabled after success (the success handler ran)');
  assert.ok(t.h.els['cu-push-result'].innerHTML.includes('Synced to Code Unloader — appended 1 safe + 2 aggressive rules (0 already present).'), 'the success notice renders: ' + t.h.els['cu-push-result'].innerHTML);
});

// A re-queued scan can finish (restoreStep4 re-renders Step 4) while a Sync from the previous result is
// still out. With a re-scan marker and active CU rules the new render is G6 sync-only: Push disabled so
// it cannot replace pushed rules. The stale request's release() must not undo that.
function rerenderSyncOnly(t) {
  t.h.sandbox.localStorage.setItem('cu_scanner_requeue_job-rq', '1');
  t.h.sandbox.window.__cuTest.restoreStep4({
    jobId: 'job-rq', safeCount: 1, aggCount: 0, canPush: true, externalOnly: false,
    bannerData: {}, urlsScanned: 1, pages: [], scanId: 'scan-rq', hasActiveCuRules: true,
  });
  assert.strictEqual(t.push.disabled, true, 'G6: Push is disabled on the re-scan result');
  assert.strictEqual(t.sync.disabled, false, 'G6: Sync is available on the re-scan result');
}

test('A Step-4 re-render mid-Sync owns the card: the stale answer leaves Push disabled and the line empty', async () => {
  const t = setup();
  t.sync.click();
  await flush();
  assertBusy(t, SYNC_BUSY, 'sync in flight');
  rerenderSyncOnly(t);
  assertIdle(t, 'after the re-render');
  t.held[0].respond({ success: true, data: SYNC_OK });
  await flush();
  assertIdle(t, 'after the stale answer');
  assert.strictEqual(t.push.disabled, true, 'G6 survives the stale answer: Push stays disabled');
});

test('A Sync started after the re-render is not disturbed by the stale answer', async () => {
  const t = setup();
  t.sync.click();
  await flush();
  rerenderSyncOnly(t);
  t.sync.click();
  await flush();
  assert.strictEqual(t.held.length, 2, 'the second Sync is in flight');
  assertBusy(t, SYNC_BUSY, 'second sync in flight');
  t.held[0].respond({ success: true, data: SYNC_OK });
  await flush();
  assertBusy(t, SYNC_BUSY, 'second sync still in flight after the stale answer');
  t.held[1].respond({ success: true, data: SYNC_OK });
  await flush();
  assertIdle(t, 'second sync settled');
  assert.strictEqual(t.push.disabled, true, 'Push goes back to the re-render\'s disabled state');
});

test('ET column tooltip: +1 credit only if Extra Time actually runs (visible box and aria-label)', async () => {
  const h = createHarness();
  h.sandbox.window.__cuTest.restoreStep4({
    jobId: 'job-et', safeCount: 1, aggCount: 0, canPush: true, externalOnly: false,
    bannerData: {}, urlsScanned: 1, scanId: 'scan-et', hasActiveCuRules: false,
    pages: [{ n: 1, url: 'https://example.test/et', status_class: 'ok', status_label: 'OK', credits: 1, safe: 1, aggressive: 0, needed: 3 }],
  });
  const html = h.els['cu-result-url-list'].innerHTML;
  assert.ok(html.includes('<span class="cu-help-box">Re-run this URL with Extra Time (more probe budget, +1 credit only if Extra Time actually runs).</span>'), 'the visible tooltip states the conditional credit');
  assert.ok(html.includes('aria-label="Re-run this URL with Extra Time — more probe budget, plus one credit only if Extra Time actually runs."'), 'the screen-reader label says the same');
  assert.ok(!html.includes('+1 credit).'), 'the unconditional visible wording is gone');
  assert.ok(!html.includes('plus one credit."'), 'the unconditional aria-label wording is gone');
});

(async () => {
  let failed = 0;
  for (const { name, fn } of tests) {
    try { await fn(); console.log('OK   ' + name); }
    catch (e) { failed++; console.error('FAIL ' + name + '\n     ' + (e && e.message)); }
  }
  if (failed) { console.error(failed + ' of ' + tests.length + ' failed'); process.exit(1); }
})();
