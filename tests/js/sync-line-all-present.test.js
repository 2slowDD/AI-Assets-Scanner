// FU-AAS-SYNC-LINE-ALL-PRESENT — the Sync success line through the REAL scanner.js click
// handler (spec §3.4, AC-8): stubbed fetch answers cu_scanner_sync_to_cu; the button's real
// click() fires the handler; the rendered #cu-push-result is asserted.
//   (a) all present  → "Synced to Code Unloader — all 6 rules are already present."
//   (b) 0/1/5        → today's counts line, byte for byte; undo button activates
//   (c) errors       → today's counts line + errNote, NOT the all-present line
//   (d) 2/3/0        → today's counts line
//   (e) 0/0/0        → today's counts line (unreachable on success; the fallback pinned)
const assert = require('assert');
const { createHarness, makeEl } = require('./r3-stage-c-harness');

function flush() { return new Promise((r) => setImmediate(r)).then(() => new Promise((r) => setImmediate(r))); }

async function clickSync(summary) {
  const actions = [];
  const h = createHarness({
    fetch: (url, opt) => {
      const action = opt && opt.body && typeof opt.body.get === 'function' ? opt.body.get('action') : '?';
      actions.push(action);
      const data = action === 'cu_scanner_sync_to_cu' ? summary : {};
      return Promise.resolve({ ok: true, json: () => Promise.resolve({ success: true, data }) });
    },
  });
  // The undo button is NOT in the harness's default id list; pre-create it DISABLED / non-active
  // so leg (b)'s three assertions can only pass if setUndoLastPushSyncState actually ran (r2 m2).
  const undo = makeEl('cu-btn-undo-last-push-sync', 'button');
  undo.disabled = true; undo.setAttribute('aria-disabled', 'true');
  h.els['cu-btn-undo-last-push-sync'] = undo;

  h.els['cu-btn-sync'].click();
  await flush();
  assert.ok(actions.includes('cu_scanner_sync_to_cu'), 'the click posted the sync action (non-vacuity)');
  assert.strictEqual(h.els['cu-btn-sync'].disabled, true, 'the Sync button is disabled after a click');
  return { html: h.els['cu-push-result'].innerHTML, undo };
}

const COUNTS = (s, a, p) => `Synced to Code Unloader — appended ${s} safe + ${a} aggressive rules (${p} already present).`;

(async () => {
  // (a)
  let r = await clickSync({ appended_safe: 0, appended_aggressive: 0, already_present: 6, error_count: 0, error_message: '', created_rule_ids: [], undo_state: { available: false } });
  assert.ok(r.html.includes('Synced to Code Unloader — all 6 rules are already present.'), '(a) all-present line: ' + r.html);
  assert.ok(!r.html.includes('appended'), '(a) never the counts line');
  assert.strictEqual(r.undo.disabled, true); assert.ok(!r.undo.classList.contains('is-active'));
  console.log('OK (a) all present');

  // (b)
  r = await clickSync({ appended_safe: 0, appended_aggressive: 1, already_present: 5, error_count: 0, error_message: '', created_rule_ids: [301], undo_state: { available: true, counts: { safe: 0, aggressive: 1, already_present: 5 } } });
  assert.ok(r.html.includes(COUNTS(0, 1, 5)), '(b) today\'s line byte for byte: ' + r.html);
  assert.strictEqual(r.undo.disabled, false, '(b) undo enabled');
  assert.ok(r.undo.classList.contains('is-active'), '(b) undo is-active');
  assert.strictEqual(r.undo.getAttribute('aria-disabled'), 'false', '(b) aria-disabled=false');
  console.log('OK (b) counts line + undo activated');

  // (c)
  r = await clickSync({ appended_safe: 0, appended_aggressive: 0, already_present: 6, error_count: 2, error_message: 'boom', created_rule_ids: [], undo_state: { available: false } });
  assert.ok(r.html.includes(COUNTS(0, 0, 6) + ' (2 errors — first: boom)'), '(c) counts line + errNote: ' + r.html);
  assert.ok(!r.html.includes('all 6 rules'), '(c) never the all-present line on the error path');
  console.log('OK (c) error path keeps the counts line');

  // (d)
  r = await clickSync({ appended_safe: 2, appended_aggressive: 3, already_present: 0, error_count: 0, error_message: '', created_rule_ids: [1, 2, 3, 4, 5], undo_state: { available: true } });
  assert.ok(r.html.includes(COUNTS(2, 3, 0)), '(d) ' + r.html);
  console.log('OK (d) counts line');

  // (e)
  r = await clickSync({ appended_safe: 0, appended_aggressive: 0, already_present: 0, error_count: 0, error_message: '', created_rule_ids: [], undo_state: { available: false } });
  assert.ok(r.html.includes(COUNTS(0, 0, 0)), '(e) ' + r.html);
  console.log('OK (e) fallback');
})().catch((e) => { console.error(e && e.stack || e); process.exit(1); });
