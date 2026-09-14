const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

// F1 — the scanner-secret Regenerate button (admin/views/settings-page.php #cu-regenerate-secret).
//
// This drives the REAL admin/js/settings.js through the click handler: confirm() gating, the
// FormData POST shape (action + nonce, matching the nonce cu_scanner_fetch_balance already
// sends), the disable-while-in-flight state, reload() on success, and re-enable + alert() on a
// server-reported failure or a transport rejection. A source-text pin ("the file contains
// 'cu_scanner_regenerate_secret'") would catch deletion only — it would stay green if the action
// string were sent under the wrong key, or the nonce were left off entirely.
//
// WHY THIS FILE STANDS ALONE (same reasoning as tests/js/settings-fetch-failure.test.js): the
// r3-stage-c-harness.js hardcodes scanner.js + menu-badge.js, and settings.js touches a much
// smaller, different DOM surface. Building the stub set here keeps this change off a harness that
// many other passing suites depend on.

function makeEl(id) {
  const listeners = {};
  let _text = '';
  return {
    id, value: '', disabled: false, className: '', style: {},
    dataset: {}, _clicks: 0,
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

// `regenerateFetchImpl` decides how the cu_scanner_regenerate_secret POST settles. The
// auto-firing balance refresh (refresh.click() on load) is answered separately so it never
// interferes with the assertions under test.
function harness(regenerateFetchImpl) {
  const ids = [
    'cu-scanner-settings-form', 'cu-settings-message', 'cu-credit-balance',
    'cu-refresh-balance', 'cu_api_key', 'cu-balance-card',
    'cu-scanner-secret', 'cu-copy-secret', 'cu-regenerate-secret',
  ];
  const els = {};
  ids.forEach((id) => { els[id] = makeEl(id); });

  const domReady = [];
  const fetchCalls = [];

  const sandbox = {
    console,
    setTimeout, clearTimeout, parseInt, isNaN, Promise,
    cuScannerSettings: { ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'nonce-1' },
    navigator: { clipboard: { writeText: () => Promise.resolve() } },
    confirm: () => true,
    _alerts: [],
    alert: function (m) { sandbox._alerts.push(m); },
    _reloadCount: 0,
    location: { reload: function () { sandbox._reloadCount++; } },
    fetch: function (url, opts) {
      const call = { url, opts };
      fetchCalls.push(call);
      const action = opts.body.get('action');
      if (action === 'cu_scanner_regenerate_secret') {
        return regenerateFetchImpl(opts);
      }
      // Auto-fired balance refresh — benign, unrelated to what this file tests.
      return Promise.resolve({
        ok: true, status: 200,
        json: () => Promise.resolve({ success: true, data: { balance: 7 } }),
      });
    },
    FormData: class {
      constructor() { this._d = []; }
      append(k, v) { this._d.push([k, v]); }
      delete(k) { this._d = this._d.filter(([kk]) => kk !== k); }
      get(k) { const h = this._d.find(([kk]) => kk === k); return h ? h[1] : null; }
    },
    document: {
      getElementById: (id) => els[id] || null,
      addEventListener: (ev, fn) => { if (ev === 'DOMContentLoaded') domReady.push(fn); },
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
  return { els, sandbox, fetchCalls };
}

const flush = () => new Promise((r) => setImmediate(r))
  .then(() => new Promise((r) => setImmediate(r)))
  .then(() => new Promise((r) => setImmediate(r)));

async function run() {
  // --- 1. Cancelling the confirm() dialog must send no request at all --------------
  {
    const h = harness(() => {
      throw new Error('regenerate request sent despite confirm() returning false');
    });
    await flush();
    h.sandbox.confirm = () => false;
    const btn = h.els['cu-regenerate-secret'];

    btn.click();
    await flush();

    const regenerateCalls = h.fetchCalls.filter((c) => c.opts.body.get('action') === 'cu_scanner_regenerate_secret');
    assert.strictEqual(regenerateCalls.length, 0, 'cancelling the confirm dialog must not POST');
    assert.strictEqual(btn.disabled, false, 'a cancelled confirm must leave the button enabled');
    console.log('OK confirm=false sends no request');
  }

  // --- 2. Confirming POSTs the right action + nonce, and disables the button -------
  {
    let resolveFetch;
    const h = harness(() => new Promise((r) => { resolveFetch = r; }));
    await flush();
    h.sandbox.confirm = () => true;
    const btn = h.els['cu-regenerate-secret'];

    btn.click();
    await flush();

    const regenerateCalls = h.fetchCalls.filter((c) => c.opts.body.get('action') === 'cu_scanner_regenerate_secret');
    assert.strictEqual(regenerateCalls.length, 1, 'confirming must POST exactly one regenerate request');
    assert.strictEqual(regenerateCalls[0].opts.body.get('nonce'), 'nonce-1',
      'the regenerate request must carry the same settings nonce cu_scanner_fetch_balance sends');
    assert.strictEqual(btn.disabled, true, 'the button must be disabled while the request is in flight');

    // Let the in-flight request settle so it does not leak into the next scenario.
    resolveFetch({ ok: true, status: 200, json: () => Promise.resolve({ success: true, data: { secret: 'abc' } }) });
    await flush();
    console.log('OK confirm=true POSTs action=cu_scanner_regenerate_secret with the settings nonce, and disables the button');
  }

  // --- 3. Success reloads the page -------------------------------------------------
  {
    const h = harness(() => Promise.resolve({
      ok: true, status: 200,
      json: () => Promise.resolve({ success: true, data: { secret: 'deadbeefdeadbeefdeadbeefdeadbeef' } }),
    }));
    await flush();
    h.sandbox.confirm = () => true;
    const btn = h.els['cu-regenerate-secret'];

    btn.click();
    await flush();

    assert.strictEqual(h.sandbox._reloadCount, 1, 'a successful regenerate must reload the page');
    assert.strictEqual(h.sandbox._alerts.length, 0, 'a successful regenerate must not alert');
    console.log('OK success reloads the page');
  }

  // --- 4. A server-reported failure re-enables the button and alerts ---------------
  {
    const h = harness(() => Promise.resolve({
      ok: true, status: 200,
      json: () => Promise.resolve({ success: false, data: 'Forbidden' }),
    }));
    await flush();
    h.sandbox.confirm = () => true;
    const btn = h.els['cu-regenerate-secret'];

    btn.click();
    await flush();

    assert.strictEqual(h.sandbox._reloadCount, 0, 'a failed regenerate must not reload the page');
    assert.strictEqual(btn.disabled, false, 'a failed regenerate must re-enable the button');
    assert.strictEqual(h.sandbox._alerts.length, 1, 'a failed regenerate must alert exactly once');
    assert.strictEqual(
      h.sandbox._alerts[0],
      'Could not regenerate the secret. Please reload the page and try again.'
    );
    console.log('OK server-reported failure re-enables the button and alerts');
  }

  // --- 5. A transport / JSON-parse rejection does the same -------------------------
  {
    const h = harness(() => Promise.reject(new TypeError('Failed to fetch')));
    await flush();
    h.sandbox.confirm = () => true;
    const btn = h.els['cu-regenerate-secret'];

    btn.click();
    await flush();

    assert.strictEqual(h.sandbox._reloadCount, 0, 'a rejected fetch must not reload the page');
    assert.strictEqual(btn.disabled, false, 'a rejected fetch must re-enable the button so it can be retried');
    assert.strictEqual(h.sandbox._alerts.length, 1, 'a rejected fetch must alert exactly once');
    assert.strictEqual(
      h.sandbox._alerts[0],
      'Could not regenerate the secret. Please reload the page and try again.'
    );
    console.log('OK network rejection re-enables the button and alerts');
  }

  console.log('regenerate-secret: all assertions passed');
}

run().catch(function (e) { console.error(e); process.exit(1); });
