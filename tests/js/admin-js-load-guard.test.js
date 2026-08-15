// FU-G — parse + load guard for the two admin/js files nothing else touches.
//
// admin/js holds four files. scanner.js and menu-badge.js are executed by the shared
// harness (createHarness / createMenuBadgeHarness), so a syntax error in either throws at
// load and goes red. history.js and settings.js were referenced by NOTHING in tests/js —
// a syntax error, a stray character from a bad merge, or a pinned line commented out
// shipped green through the whole suite. That is the same hole A2b closed for
// menu-badge.js, closed the same way: run the SHIPPED file.
//
// vm.runInContext PARSES the file, so §1's minimum bar (it parses at all) is met by the
// act of loading it. §2 and §3 then drive each file's load-time path far enough to pin the
// handful of facts that are cheap to assert and expensive to get wrong — in both files
// that is the nonce riding every privileged AJAX call, plus settings.js's masked-API-key
// branch, whose failure silently overwrites a customer's saved paid key with the mask.
//
// Scope note: deliberately NOT a second harness. The doubles below are the smallest thing
// each file's own load path calls, and the DOM double is reused from r3-stage-c-harness.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { makeEl } = require('./r3-stage-c-harness');

const ADMIN_JS = path.join(__dirname, '..', '..', 'admin', 'js');
const read = (name) => fs.readFileSync(path.join(ADMIN_JS, name), 'utf8');

// --- 1. Both files must PARSE ---------------------------------------------------
// Compiled, not run: a SyntaxError anywhere in the file throws here regardless of which
// branches a later section happens to execute. This is the minimum bar, and it is the one
// that was missing — the load-time coverage in §2/§3 is a superset, but keeping the parse
// check explicit means the guard survives even if a future refactor makes a file harder
// to drive.
['history.js', 'settings.js'].forEach(function (name) {
  assert.doesNotThrow(
    function () { new vm.Script(read(name), { filename: name }); },
    name + ' must parse — nothing else in the suite reads this file, so a syntax error'
      + ' here reaches production without a single test going red'
  );
});

// --- 2. history.js — executed through its jQuery entry point ---------------------
// Shape: (function ($) { $(function () { ... }); })(jQuery). The double records what the
// ready callback wires up, so the two privileged calls can be asserted on the values the
// shipped code actually emits.
(function historyJsLoadsAndSendsItsNonce() {
  const HISTORY_CFG = {
    ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
    nonce: 'NONCE-history-1234',
    deleteWarning: 'Delete all scan history?',
  };

  function run(opts) {
    const nodes = {};
    const posts = [];
    const ready = [];
    const node = (sel) => (nodes[sel] = nodes[sel] || {
      _handlers: {}, _props: {},
      on(ev, fn) { (this._handlers[ev] = this._handlers[ev] || []).push(fn); return this; },
      prop(k, v) { this._props[k] = v; return this; },
      fire(ev) {
        (this._handlers[ev] || []).forEach((fn) => fn({ preventDefault() {} }));
      },
    });
    const $ = (arg) => (typeof arg === 'function' ? (ready.push(arg), {}) : node(String(arg)));
    // jQuery's Deferred reduced to the two continuations history.js chains.
    const deferred = (ok) => ({ done(fn) { if (ok && fn) fn(); return this; },
                                fail(fn) { if (!ok && fn) fn(opts.xhr || {}); return this; } });
    $.post = (url, data) => { posts.push({ url, data }); return deferred(opts.postOk !== false); };

    const sandbox = {
      console, jQuery: $,
      cuScannerHistory: HISTORY_CFG,
      setTimeout: () => 0,
      location: { href: '' },
      confirm: () => opts.confirm !== false,
      alert: (m) => { sandbox.alerts.push(m); },
      alerts: [],
      reloaded: false,
    };
    sandbox.window = sandbox;
    sandbox.globalThis = sandbox;
    sandbox.location.reload = () => { sandbox.reloaded = true; };
    vm.createContext(sandbox);
    vm.runInContext(read('history.js'), sandbox);          // parses AND runs the IIFE
    assert.strictEqual(ready.length, 1, 'history.js registers exactly one jQuery ready callback');
    ready[0]();                                            // DOM ready
    return { sandbox, nodes, posts, node };
  }

  // Export — a redirect-download. The nonce rides the query string; drop it and
  // cu_scanner_export_history dies on check_ajax_referer with an empty ZIP for the operator.
  {
    const h = run({});
    h.node('#cu-history-export').fire('click');
    const url = h.sandbox.location.href;
    assert.ok(/[?&]action=cu_scanner_export_history(&|$)/.test(url),
      'the export button navigates to the export action, got: ' + url);
    assert.ok(url.indexOf('nonce=' + encodeURIComponent(HISTORY_CFG.nonce)) !== -1,
      'the export URL carries the nonce — without it the handler rejects the download');
    assert.ok(url.indexOf(HISTORY_CFG.ajaxUrl) === 0, 'and targets admin-ajax.php');
  }

  // Delete-all — destructive, and confirmed first.
  {
    const h = run({});
    h.node('#cu-history-delete').fire('click');
    assert.strictEqual(h.posts.length, 1, 'the delete button POSTs exactly once');
    assert.strictEqual(h.posts[0].url, HISTORY_CFG.ajaxUrl);
    assert.strictEqual(h.posts[0].data.action, 'cu_scanner_delete_history');
    assert.strictEqual(h.posts[0].data.nonce, HISTORY_CFG.nonce,
      'the delete POST carries the nonce — this is a state-changing privileged action');
    assert.strictEqual(h.sandbox.reloaded, true, 'a successful delete reloads the page');
  }

  // Declining the confirm must send NOTHING. "Delete all history" is unrecoverable.
  {
    const h = run({ confirm: false });
    h.node('#cu-history-delete').fire('click');
    assert.strictEqual(h.posts.length, 0,
      'declining the confirm must not delete anything — the guard is the only thing between'
      + ' a misclick and the whole scan history');
  }
}());

// --- 3. settings.js — executed through its DOMContentLoaded entry point ----------
(function settingsJsLoadsAndProtectsTheSavedApiKey() {
  const SETTINGS_CFG = {
    ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
    nonce: 'NONCE-settings-5678',
  };
  const SAVED_KEY_PLACEHOLDER = 'Saved paid key';

  // The one element behaviour makeEl does not model. Everything else settings.js touches
  // (addEventListener/click/classList/style/textContent/value) it already provides.
  const el = (id, tag) => Object.assign(makeEl(id, tag || 'div'), { dataset: {} });

  function run() {
    const fetches = [];
    const ids = {
      'cu-scanner-settings-form': el('cu-scanner-settings-form', 'form'),
      'cu-settings-message': el('cu-settings-message'),
      'cu-credit-balance': el('cu-credit-balance'),
      'cu-refresh-balance': el('cu-refresh-balance', 'button'),
      'cu_api_key': el('cu_api_key', 'input'),
      'cu-balance-card': el('cu-balance-card'),
    };
    // What `new FormData(form)` harvests in a real browser.
    ids['cu-scanner-settings-form']._fields = [['api_key', 'FAKE-TYPED-KEY-not-a-credential'], ['nonce', SETTINGS_CFG.nonce]];

    const ready = [];
    const sandbox = {
      console,
      cuScannerSettings: SETTINGS_CFG,
      document: {
        addEventListener: (ev, fn) => { if (ev === 'DOMContentLoaded') ready.push(fn); },
        getElementById: (id) => ids[id] || null,
        querySelectorAll: () => [],
        createElement: (t) => el('dyn', t),
      },
      addEventListener() {},
      setTimeout: () => 0,
      navigator: { clipboard: { writeText: () => Promise.resolve() } },
      FormData: class FormData {
        constructor(form) { this._data = (form && form._fields ? form._fields.slice() : []); }
        append(k, v) { this._data.push([k, String(v)]); }
        delete(k) { this._data = this._data.filter(([ek]) => ek !== k); }
        get(k) { const e = this._data.find(([ek]) => ek === k); return e ? e[1] : null; }
        has(k) { return this._data.some(([ek]) => ek === k); }
      },
      fetch: (url, init) => {
        fetches.push({ url, body: init && init.body });
        return Promise.resolve({ json: () => Promise.resolve({ success: true, data: { balance: 7, credits: 7 } }) });
      },
    };
    sandbox.window = sandbox;
    sandbox.globalThis = sandbox;
    vm.createContext(sandbox);
    vm.runInContext(read('settings.js'), sandbox);        // parses AND runs the IIFE
    assert.strictEqual(ready.length, 1, 'settings.js registers exactly one DOMContentLoaded handler');
    ready[0]();                                           // DOM ready — this is where it wires up
    return { sandbox, ids, fetches };
  }

  // Load-time auto-refresh. `refresh.click()` runs at the bottom of the ready handler, so a
  // load that reached the end at all produces exactly this call — which makes it the cheapest
  // possible proof that the whole handler executed rather than throwing halfway through.
  {
    const h = run();
    assert.strictEqual(h.fetches.length, 1, 'the ready handler runs to the end and auto-refreshes the balance');
    assert.strictEqual(h.fetches[0].url, SETTINGS_CFG.ajaxUrl);
    assert.strictEqual(h.fetches[0].body.get('action'), 'cu_scanner_fetch_balance');
    assert.strictEqual(h.fetches[0].body.get('nonce'), SETTINGS_CFG.nonce,
      'the balance refresh carries the nonce');
  }

  // The masked-key branch. The settings screen renders the saved paid key as a PLACEHOLDER
  // string, never the real one. Submitting while it is still masked must drop api_key and
  // ask the server to keep what it has — lose this branch and the save writes the literal
  // placeholder over the customer's working key, and every scan 401s afterwards.
  {
    const h = run();
    const form = h.ids['cu-scanner-settings-form'];
    const input = h.ids['cu_api_key'];
    input.value = SAVED_KEY_PLACEHOLDER;
    input.dataset.masked = '1';

    form._fire('submit', { preventDefault() {} });
    const body = h.fetches[h.fetches.length - 1].body;
    assert.strictEqual(body.get('action'), 'cu_scanner_save_settings');
    assert.strictEqual(body.has('api_key'), false,
      'a masked key must NOT be submitted — the field holds a placeholder, not the key');
    assert.strictEqual(body.get('keep_api_key'), '1',
      'and the server must be told to keep the key it already has');
  }

  // Control: an operator who actually typed a new key must have it submitted. This is what
  // stops the branch above from being "fixed" into dropping api_key unconditionally.
  {
    const h = run();
    const form = h.ids['cu-scanner-settings-form'];
    form._fire('submit', { preventDefault() {} });
    const body = h.fetches[h.fetches.length - 1].body;
    assert.strictEqual(body.get('api_key'), 'FAKE-TYPED-KEY-not-a-credential',
      'an unmasked (freshly typed) key must be submitted');
    assert.strictEqual(body.has('keep_api_key'), false, 'and keep_api_key must not be sent');
  }
}());

console.log('admin-js-load-guard.test.js OK');
