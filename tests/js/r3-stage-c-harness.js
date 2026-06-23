// Minimal browser-global harness that evaluates the REAL admin/js/scanner.js
// in Node so we can runtime-test handleStatusUpdate's branches (no jest in this repo).
const fs = require('fs');
const path = require('path');
const vm = require('vm');

function makeEl(id) {
  const listeners = {};
  return {
    id, _html: '', style: {}, value: 0, textContent: '', disabled: false,
    children: [],
    set innerHTML(v) { this._html = v; }, get innerHTML() { return this._html; },
    addEventListener(ev, fn) { (listeners[ev] = listeners[ev] || []).push(fn); },
    click() { (listeners['click'] || []).forEach((f) => f({ preventDefault() {} })); },
    appendChild(c) { this.children.push(c); return c; },
    querySelector() { return null; }, removeAttribute() {},
    _listeners: listeners,
  };
}

function createHarness(opts = {}) {
  const els = {};
  const ensure = (id) => (els[id] = els[id] || makeEl(id));
  // Pre-create ALL ids scanner.js touches on load (chained addEventListener + others).
  ['cu-balance-badge', 'cu-balance-num', 'cu-banner-area', 'cu-bot-notice',
   'cu-btn-cancel', 'cu-btn-discover', 'cu-btn-download', 'cu-btn-next-1',
   'cu-btn-next-1-top', 'cu-btn-push', 'cu-btn-sync', 'cu-credit-badge',
   'cu-credit-deselected', 'cu-credit-num', 'cu-et-result-all', 'cu-excluded-urls',
   'cu-filter-bar', 'cu-included-urls', 'cu-outbox-banner', 'cu-pages-tbody',
   'cu-partial-requeue-btn', 'cu-partial-retry-btn', 'cu-pill-all',
   'cu-plugin-warnings', 'cu-probe-spinner-host', 'cu-progress-bar',
   'cu-progress-text', 'cu-push-result', 'cu-queue-banner', 'cu-result-summary',
   'cu-result-url-list', 'cu-scanner-app', 'cu-sonar-anim', 'cu-step-label',
   'cu-target-stack-notice', 'cu-url-list', 'cu-url-list-area', 'cu-url-next',
   'cu-url-prev', 'cu-paused-banner', 'step-1'].forEach(ensure);

  const timers = [];
  const sandbox = {
    console,
    window: {},
    document: {
      getElementById: (id) => els[id] || null,
      createElement: () => makeEl('dyn'),
      querySelector: () => null, querySelectorAll: () => [],
      addEventListener() {},
    },
    sessionStorage: makeStorage(opts.sessionStorage),
    localStorage: makeStorage(opts.localStorage),
    cuScanner: opts.cuScanner || { ajaxUrl: '', nonce: 'n', siteUrl: 's', outbox: { state: 'none' } },
    fetch: opts.fetch || (() => Promise.reject(new Error('no fetch stub'))),
    setTimeout: (fn, ms) => { const t = { fn, ms, type: 'timeout', cleared: false }; timers.push(t); return t; },
    clearTimeout: (t) => { if (t) t.cleared = true; },
    setInterval: (fn, ms) => { const t = { fn, ms, type: 'interval', cleared: false }; timers.push(t); return t; },
    clearInterval: (t) => { if (t) t.cleared = true; },
    confirm: opts.confirm || (() => true),
    alert: () => {},
    Date,
    URL,
    AbortController,
    FormData: class FormData {
      append() {} get() { return null; } set() {} has() { return false; }
      delete() {} entries() { return [][Symbol.iterator](); }
    },
    location: opts.location || { href: '', hostname: '', pathname: '/', search: '', hash: '' },
  };
  sandbox.window = sandbox; sandbox.globalThis = sandbox;
  const code = fs.readFileSync(path.join(__dirname, '../../admin/js/scanner.js'), 'utf8');
  vm.createContext(sandbox);
  vm.runInContext(code, sandbox);
  return { sandbox, els, timers };
}

function makeStorage(seed) {
  const m = new Map(Object.entries(seed || {}));
  return {
    getItem: (k) => (m.has(k) ? m.get(k) : null),
    setItem: (k, v) => m.set(k, String(v)),
    removeItem: (k) => m.delete(k),
    key: (i) => Array.from(m.keys())[i], get length() { return m.size; },
  };
}

module.exports = { createHarness, makeEl, makeStorage };

// Inline self-test for formatCountdown (run: node tests/js/r3-stage-c-harness.js)
if (require.main === module) {
  const { sandbox } = createHarness();
  const f = sandbox.window.__cuTest.formatCountdown;
  const cases = [[0, '0:00'], [-5000, '0:00'], [5000, '0:05'], [65000, '1:05'],
                 [3600000, '1:00:00'], [3661000, '1:01:01']];
  let fail = 0;
  for (const [ms, want] of cases) {
    const got = f(ms);
    if (got !== want) { console.error(`formatCountdown(${ms}) = ${got}, want ${want}`); fail++; }
  }
  console.log(fail === 0 ? 'OK formatCountdown' : `FAIL ${fail}`);
  process.exit(fail === 0 ? 0 : 1);
}
