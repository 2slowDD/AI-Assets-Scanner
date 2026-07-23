const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');

// FDEG Plan B Task 4 — showProbeOutcomeDialog() forks on TWO conditions, not one:
//   every host informational AND no security stack -> non-blocking .cu-probe-toast
//                                                     + immediate resolve(true)
//   anything else                                  -> today's blocking <dialog>
//                                                     (the WAF bail-BEFORE-credits gate)
//
// The second branch is the load-bearing one: it must stay blocking and must never
// auto-resolve, or the operator spends credits on a scan a WAF will eat.
//
// Why the outcome half of the guard matters: this function has exactly ONE call site
// and it is gated on warning_needed, and compute_warning_needed() fires on ANY host
// whose outcome !== 'class_a_clean' — not just on stacks. So a stack-only guard would
// auto-proceed on probe_failed / no_clue / non_wordpress, i.e. exactly the states the
// gate exists for. runNonInformationalBlocks() below is the regression guard for that.

// Element text = own textContent plus every descendant's (innerHTML-derived nodes
// included), so an appended text node is visible to the assertions below.
function textOf(node) {
  if (!node) return '';
  if (node.nodeType === 3) return node.textContent || '';
  let s = node.textContent || '';
  (node._kids ? node._kids() : []).forEach(function (c) { s += textOf(c); });
  return s;
}

// Every innerHTML string in a subtree — used to prove a wire value never reached an
// HTML sink in the first place (not merely that it failed to parse into an element).
function htmlOf(node) {
  if (!node || node.nodeType === 3) return '';
  let s = node._html || '';
  (node._kids ? node._kids() : []).forEach(function (c) { s += htmlOf(c); });
  return s;
}

function flush() {
  return new Promise(function (r) { setImmediate(r); });
}

// (a) Clean detection — no security_stacks anywhere: a toast, no dialog, and the
// promise resolves true without any operator interaction.
function runCleanCase() {
  const h = createHarness();
  const T = h.sandbox.window.__cuTest;
  assert.ok(T.showProbeOutcomeDialog, 'showProbeOutcomeDialog must be exposed on __cuTest');
  const body = h.sandbox.document.body;
  const before = h.timers.length;

  const p = T.showProbeOutcomeDialog({
    summary: { uniform_outcome: false },
    per_host_results: [
      { host: 'example.com', outcome: 'class_bc_only', detected: [], security_stacks: [] },
      { host: 'shop.example.com', outcome: 'hybrid_a_plus_bc', detected: [] },
    ],
  });

  // Synchronous: the toast is already up and nothing modal was created.
  const toast = body.querySelector('.cu-probe-toast');
  assert.ok(toast, 'clean path renders a .cu-probe-toast');
  assert.strictEqual(body.querySelector('dialog'), null, 'clean path creates no <dialog>');
  assert.strictEqual(body.querySelector('.cu-probe-outcome-dialog'), null, 'no blocking modal on the clean path');

  // Content comes from the shared builders (per-host list here, since not uniform).
  const txt = textOf(toast);
  assert.ok(txt.indexOf('example.com') !== -1, 'toast carries the per-host summary');
  assert.ok(txt.indexOf('shop.example.com') !== -1, 'toast lists every probed host');
  assert.ok(toast.querySelector('ul.cu-probe-host-list'), 'non-uniform summary uses buildPerHostList');

  // Auto-dismiss: fade first, detach at ~10s. Only timers this call scheduled.
  const mine = h.timers.slice(before).filter(function (t) { return t.type === 'timeout'; });
  const removeTimer = mine.filter(function (t) { return t.ms === 10000; })[0];
  const fadeTimer = mine.filter(function (t) { return t.ms > 0 && t.ms < 10000; })[0];
  assert.ok(removeTimer, 'a 10s removal timer is scheduled');
  assert.ok(fadeTimer, 'a fade timer fires before the removal');
  fadeTimer.fn();
  assert.ok(toast._classes.has('cu-probe-toast-hide'), 'fade timer adds the hide class');
  removeTimer.fn();
  assert.strictEqual(body.querySelector('.cu-probe-toast'), null, 'toast detaches itself after ~10s');

  // ...and the scan was never gated on a click.
  return p.then(function (v) {
    assert.strictEqual(v, true, 'clean path resolves true with no operator interaction');
    console.log('OK probe-outcome-toast-clean');
  });
}

// (a2) Uniform clean detection — same toast, uniform copy path.
function runUniformCleanCase() {
  const h = createHarness();
  const T = h.sandbox.window.__cuTest;
  const body = h.sandbox.document.body;

  const p = T.showProbeOutcomeDialog({
    summary: { uniform_outcome: true },
    per_host_results: [{ host: 'uniform.example', outcome: 'class_bc_only', detected: [{ name: 'WP Rocket', class: 'B' }], security_stacks: [] }],
  });

  const toast = body.querySelector('.cu-probe-toast');
  assert.ok(toast, 'uniform clean path renders a toast');
  assert.strictEqual(toast.querySelector('ul.cu-probe-host-list'), null, 'uniform summary uses buildUniformMessage, not the list');
  assert.ok(textOf(toast).indexOf('WP Rocket') !== -1, 'uniform toast carries the detected stack copy');
  assert.strictEqual(body.querySelector('dialog'), null, 'still no <dialog>');

  return p.then(function (v) {
    assert.strictEqual(v, true, 'uniform clean path resolves true immediately');
    console.log('OK probe-outcome-toast-clean-uniform');
  });
}

// (b) THE GATE. A detected security stack must still produce today's blocking modal,
// and the promise must stay pending until the operator actually clicks.
function runStackDetectedCase() {
  const h = createHarness();
  const T = h.sandbox.window.__cuTest;
  const body = h.sandbox.document.body;
  const before = h.timers.length;

  let settled = 'PENDING';
  const p = T.showProbeOutcomeDialog({
    summary: { uniform_outcome: true },
    per_host_results: [{ host: 'walled.example', outcome: 'no_clue', detected: [], security_stacks: ['cloudflare'] }],
  });
  p.then(function (v) { settled = v; });

  const dialog = body.querySelector('dialog.cu-probe-outcome-dialog');
  assert.ok(dialog, 'WAF bail-gate: the blocking dialog is still created');
  assert.strictEqual(dialog.open, true, 'the dialog was opened modally (showModal)');
  assert.strictEqual(body.querySelector('.cu-probe-toast'), null, 'no toast on the stack path — the gate is not softened');
  assert.ok(dialog.querySelector('.cu-security-stack-block'), 'the security-stack warning block is present');
  assert.ok(dialog.querySelector('.cu-probe-cancel'), 'Cancel button present');
  assert.ok(dialog.querySelector('.cu-probe-continue'), 'Continue button present');

  // No self-dismiss timer may be attached to the gate.
  const mine = h.timers.slice(before);
  assert.strictEqual(mine.length, 0, 'the gate schedules NO auto-dismiss timer');

  return flush().then(flush).then(function () {
    assert.strictEqual(settled, 'PENDING', 'the gate does NOT auto-resolve — it waits for the operator');
    dialog.querySelector('.cu-probe-continue').click();
    return flush().then(flush);
  }).then(function () {
    assert.strictEqual(settled, true, 'clicking Continue resolves true');
    console.log('OK probe-outcome-dialog-waf-gate-blocking');
  });
}

// (b2) Cancel on the gate still resolves false — the bail path is intact.
function runStackCancelCase() {
  const h = createHarness();
  const T = h.sandbox.window.__cuTest;
  const body = h.sandbox.document.body;

  let settled = 'PENDING';
  T.showProbeOutcomeDialog({
    summary: { uniform_outcome: true },
    per_host_results: [{ host: 'walled.example', outcome: 'no_clue', detected: [], security_stacks: ['sucuri', 'wordfence'] }],
  }).then(function (v) { settled = v; });

  const dialog = body.querySelector('dialog.cu-probe-outcome-dialog');
  assert.ok(dialog, 'gate dialog created for a multi-stack result');
  return flush().then(flush).then(function () {
    assert.strictEqual(settled, 'PENDING', 'still pending before any click');
    dialog.querySelector('.cu-probe-cancel').click();
    return flush().then(flush);
  }).then(function () {
    assert.strictEqual(settled, false, 'Cancel bails before credits are spent');
    console.log('OK probe-outcome-dialog-waf-gate-cancel');
  });
}

// (c) security_stacks ids are untrusted wire data. They must land in the DOM as text,
// never as parsed markup, and must never be concatenated into an innerHTML sink.
function runUntrustedStackId() {
  const h = createHarness();
  const T = h.sandbox.window.__cuTest;
  const body = h.sandbox.document.body;
  const payload = '<img src=x onerror="alert(1)">';

  T.showProbeOutcomeDialog({
    summary: { uniform_outcome: true },
    per_host_results: [{ host: 'evil.example', outcome: 'no_clue', detected: [], security_stacks: [payload] }],
  });

  const dialog = body.querySelector('dialog.cu-probe-outcome-dialog');
  assert.ok(dialog, 'a stack id — however hostile — still opens the gate');
  const block = dialog.querySelector('.cu-security-stack-block');
  assert.ok(block, 'stack block rendered');

  assert.ok(textOf(block).indexOf(payload) !== -1, 'the raw id survives verbatim as TEXT');
  assert.strictEqual(block.querySelector('img'), null, 'the id must not become an <img> element');
  assert.strictEqual(dialog.querySelector('img'), null, 'no injected element anywhere in the dialog');
  assert.ok(htmlOf(block).indexOf('<img') === -1, 'the id never reaches an innerHTML sink');
  console.log('OK probe-outcome-untrusted-stack-id');
}

// (c2) Same discipline on the toast path: an untrusted host name must not smuggle markup.
function runUntrustedHostInToast() {
  const h = createHarness();
  const T = h.sandbox.window.__cuTest;
  const body = h.sandbox.document.body;
  const payload = '<img src=x onerror="alert(1)">';

  T.showProbeOutcomeDialog({
    summary: { uniform_outcome: true },
    per_host_results: [{ host: payload, outcome: 'class_bc_only', detected: [], security_stacks: [] }],
  });

  const toast = body.querySelector('.cu-probe-toast');
  assert.ok(toast, 'toast rendered');
  assert.strictEqual(toast.querySelector('img'), null, 'a hostile host name must not become an <img> in the toast');
  assert.ok(htmlOf(toast).indexOf('&lt;img') !== -1, 'the host name is esc()-escaped before it reaches the DOM');
  assert.ok(htmlOf(toast).indexOf('<img') === -1, 'no raw markup from wire data in the toast HTML');
  console.log('OK probe-outcome-toast-untrusted-host');
}

// (d) REGRESSION GUARD — a non-informational outcome must BLOCK even with no stack.
// showProbeOutcomeDialog is only reached when warning_needed is true, and
// compute_warning_needed() fires on any outcome !== 'class_a_clean'. So probe_failed /
// no_clue / non_wordpress arrive here with security_stacks EMPTY. A stack-only guard
// would silently auto-proceed and spend credits on a probe that was blocked, on a
// target that is not WordPress, or on a stack we could not identify. Each of these
// must still get the blocking modal.
function runNonInformationalBlocks() {
  ['probe_failed', 'no_clue', 'non_wordpress'].forEach(function (outcome) {
    const h = createHarness();
    const T = h.sandbox.window.__cuTest;
    const body = h.sandbox.document.body;
    const before = h.timers.length;

    let settled = 'PENDING';
    T.showProbeOutcomeDialog({
      summary: { uniform_outcome: true },
      per_host_results: [{ host: 'x.example', outcome: outcome, detected: [], security_stacks: [] }],
    }).then(function (v) { settled = v; });

    assert.ok(body.querySelector('dialog.cu-probe-outcome-dialog'),
      outcome + ' (no stack) must STILL open the blocking gate');
    assert.strictEqual(body.querySelector('.cu-probe-toast'), null,
      outcome + ' must NOT be downgraded to a toast');
    assert.strictEqual(h.timers.slice(before).length, 0,
      outcome + ' must schedule no auto-dismiss timer');
    assert.strictEqual(settled, 'PENDING', outcome + ' must not auto-resolve');
  });

  // A mixed set is only informational if EVERY host is — one bad host blocks.
  const h = createHarness();
  const T = h.sandbox.window.__cuTest;
  const body = h.sandbox.document.body;
  T.showProbeOutcomeDialog({
    summary: { uniform_outcome: false },
    per_host_results: [
      { host: 'good.example', outcome: 'class_bc_only', detected: [], security_stacks: [] },
      { host: 'bad.example', outcome: 'probe_failed', detected: [], security_stacks: [] },
    ],
  });
  assert.ok(body.querySelector('dialog.cu-probe-outcome-dialog'),
    'one non-informational host blocks the whole set');
  assert.strictEqual(body.querySelector('.cu-probe-toast'), null,
    'no toast when any host is non-informational');

  // Unknown/empty state is not informational either — it blocks.
  const h2 = createHarness();
  const body2 = h2.sandbox.document.body;
  h2.sandbox.window.__cuTest.showProbeOutcomeDialog({ summary: {}, per_host_results: [] });
  assert.ok(body2.querySelector('dialog.cu-probe-outcome-dialog'),
    'empty per_host_results is unknown state — it must block, not auto-proceed');

  console.log('OK probe-outcome-non-informational-blocks');
}

Promise.resolve()
  .then(runCleanCase)
  .then(runUniformCleanCase)
  .then(runStackDetectedCase)
  .then(runStackCancelCase)
  .then(runUntrustedStackId)
  .then(runUntrustedHostInToast)
  .then(runNonInformationalBlocks)
  .then(function () { console.log('ALL probe-outcome-dialog tests passed'); })
  .catch(function (e) { console.error(e); process.exit(1); });
