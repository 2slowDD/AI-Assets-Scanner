const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

// FU-N — admin/js/settings.js had no .catch() on any of its fetch chains.
//
// r.json() REJECTS on a 5xx that returns an HTML error page, or on any non-JSON body. With no
// rejection handler the promise died silently: the save form did nothing at all, and the balance
// sat on its '…' spinner forever. The user could not tell a failed save from a slow one.
//
// WHY THIS FILE STANDS ALONE. tests/js/r3-stage-c-harness.js hardcodes scanner.js and
// menu-badge.js, and settings.js touches a different, much smaller DOM surface. Building its
// stubs here keeps the change off a harness that 17 passing suites depend on.
//
// P17: this drives the REAL admin/js/settings.js through a rejecting fetch. It does not assert
// that the source contains the string '.catch' — a source-text pin catches deletion only, and
// would stay green if the handler were attached to the wrong chain or swallowed the wrong thing.

function makeEl(id) {
  const listeners = {};
  let _text = '';
  return {
    id, value: '', disabled: false, className: '', style: {},
    dataset: {}, _clicks: 0,
    // The real DOM COERCES textContent to a string: `el.textContent = 7` reads back '7'.
    // A plain property stored the number, and setBalance() is fed a number straight off the
    // JSON payload — so an assertion written against the real behaviour failed on the stub
    // rather than on the code. Faithful coercion here, or the stub decides the verdict.
    set textContent(v) { _text = (v === null || v === undefined) ? '' : String(v); },
    get textContent() { return _text; },
    addEventListener(ev, fn) { (listeners[ev] = listeners[ev] || []).push(fn); },
    fire(ev, arg) { (listeners[ev] || []).forEach((fn) => fn.call(this, arg || { preventDefault() {} })); },
    click() { this._clicks++; this.fire('click'); },
    querySelector() { return null; },
    removeAttribute() {},
    setAttribute() {},
    classList: { add() {}, remove() {}, toggle() {}, contains() { return false; } },
  };
}

// `fetchImpl` decides how the request settles, so one harness covers reject-at-transport,
// reject-at-json (the real FU-N shape) and success.
function harness(fetchImpl) {
  const ids = [
    'cu-scanner-settings-form', 'cu-settings-message', 'cu-credit-balance',
    'cu-refresh-balance', 'cu_api_key', 'cu-balance-card',
    // Present so the THIRD fetch chain (postAckCdn) is reachable — without this element
    // settings.js never wires it, and its .catch() would be untested and therefore decorative.
    'cu-ack-cdn',
  ];
  const els = {};
  ids.forEach((id) => { els[id] = makeEl(id); });

  const domReady = [];
  const sandbox = {
    console,
    setTimeout, clearTimeout, parseInt, isNaN, Promise,
    cuScannerSettings: { ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'nonce-1' },
    navigator: { clipboard: { writeText: () => Promise.resolve() } },
    fetch: fetchImpl,
    FormData: class {
      constructor() { this._d = []; }
      append(k, v) { this._d.push([k, v]); }
      delete(k) { this._d = this._d.filter(([kk]) => kk !== k); }
      get(k) { const h = this._d.find(([kk]) => kk === k); return h ? h[1] : null; }
    },
    document: {
      getElementById: (id) => els[id] || null,
      addEventListener: (ev, fn) => { if (ev === 'DOMContentLoaded') domReady.push(fn); },
      // settings.js wires the per-adapter manual CDN ack buttons through this. Returning an
      // empty list is faithful to a settings page with no CDN adapter block rendered, which
      // is the state the fetch chains under test are reached in.
      querySelectorAll: () => [],
    },
  };
  sandbox.window = sandbox;
  sandbox.globalThis = sandbox;
  sandbox.window.addEventListener = () => {};

  const code = fs.readFileSync(path.join(__dirname, '../../admin/js/settings.js'), 'utf8');
  vm.createContext(sandbox);
  vm.runInContext(code, sandbox);
  domReady.forEach((fn) => fn());   // settings.js does all its work inside DOMContentLoaded
  return { els, sandbox };
}

const flush = () => new Promise((r) => setImmediate(r))
  .then(() => new Promise((r) => setImmediate(r)))
  .then(() => new Promise((r) => setImmediate(r)));

async function run() {
  // --- 1. The real FU-N shape: HTTP 500 returning an HTML error page ---------------
  // The response resolves; r.json() is what rejects. A .catch() on the wrong link in the
  // chain, or none at all, leaves the user with no feedback.
  {
    const h = harness(() => Promise.resolve({
      ok: false, status: 500,
      json: () => Promise.reject(new SyntaxError('Unexpected token < in JSON at position 0')),
    }));
    await flush();

    // The load-time refresh.click() already fired this path.
    assert.strictEqual(h.els['cu-credit-balance'].textContent, '—',
      'a rejected balance fetch must not leave the spinner hanging');

    h.els['cu-scanner-settings-form'].fire('submit');
    await flush();
    const msg = h.els['cu-settings-message'];
    assert.ok(msg.textContent.length > 0,
      'FU-N: a failed save must say something — silence is the defect');
    assert.ok(/notice-error/.test(msg.className),
      'a failed save must be reported as an error, not a success');
    console.log('OK 500 + HTML body: both fetch chains report instead of dying silently');
  }

  // --- 2. Transport failure (offline / DNS) ---------------------------------------
  {
    const h = harness(() => Promise.reject(new TypeError('Failed to fetch')));
    await flush();
    assert.strictEqual(h.els['cu-credit-balance'].textContent, '—',
      'a rejected transport must clear the spinner too');

    h.els['cu-scanner-settings-form'].fire('submit');
    await flush();
    assert.ok(h.els['cu-settings-message'].textContent.length > 0,
      'a transport failure must be reported');
    console.log('OK transport rejection reported on both chains');
  }

  // --- 3. The success path is unchanged -------------------------------------------
  // Without this, a .catch() that swallowed everything would pass tests 1 and 2.
  {
    const h = harness(() => Promise.resolve({
      ok: true, status: 200,
      json: () => Promise.resolve({ success: true, data: { credits: 42, balance: 7 } }),
    }));
    await flush();
    assert.strictEqual(h.els['cu-credit-balance'].textContent, '7',
      'the balance refresh must still render on success');

    h.els['cu-scanner-settings-form'].fire('submit');
    await flush();
    const msg = h.els['cu-settings-message'];
    assert.ok(/Settings saved/.test(msg.textContent), 'the success copy must survive the fix');
    assert.ok(/notice-success/.test(msg.className), 'success must not be reported as an error');
    console.log('OK success path unchanged (guards against a catch-all that hides everything)');
  }

  // --- 4. A server-reported error is still distinct from a transport failure -------
  {
    const h = harness(() => Promise.resolve({
      ok: true, status: 200,
      json: () => Promise.resolve({ success: false, data: 'Invalid API key' }),
    }));
    await flush();
    h.els['cu-scanner-settings-form'].fire('submit');
    await flush();
    assert.ok(/Invalid API key/.test(h.els['cu-settings-message'].textContent),
      'a wp_send_json_error message must still reach the user verbatim');
    console.log('OK server-reported error still surfaces its own message');
  }

  // --- 5. The THIRD chain: postAckCdn --------------------------------------------
  // This one is deliberately silent on failure, so there is no message to assert. What IS
  // assertable is that the rejection is HANDLED: Node treats an unhandled rejection as fatal,
  // so deleting this .catch() takes the whole process down instead of leaving the button
  // retryable. Reaching the end of this block is the assertion.
  {
    const h = harness(() => Promise.resolve({
      ok: false, status: 502,
      json: () => Promise.reject(new SyntaxError('Unexpected token < in JSON at position 0')),
    }));
    await flush();
    const ackBtn = h.els['cu-ack-cdn'];
    ackBtn.dataset.cdn = 'cloudflare';
    ackBtn.click();
    await flush();

    assert.strictEqual(ackBtn.disabled, false,
      'a failed ack must leave the button enabled so it can be retried');
    assert.notStrictEqual(ackBtn.textContent, 'Saved!',
      'a failed ack must not claim success');
    console.log('OK ack chain handles its rejection and stays retryable');
  }

  console.log('settings-fetch-failure: all assertions passed');
}

run().catch(function (e) { console.error(e); process.exit(1); });
