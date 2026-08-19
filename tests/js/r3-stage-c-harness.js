// Minimal browser-global harness that evaluates the REAL admin/js/scanner.js
// in Node so we can runtime-test handleStatusUpdate's branches (no jest in this repo).
const fs = require('fs');
const path = require('path');
const vm = require('vm');

// --- Tiny selector + fragment support (additive; see makeEl below) ---------------
// Only what the scanner's own querySelector calls and the JS tests need: an optional
// tag name, zero or more .classes, and an optional [attr] presence check. Anything
// more exotic (descendant combinators, attr=value) returns no match rather than
// pretending — tests should not silently pass on a selector we cannot honour.
const VOID_TAGS = new Set(['br', 'hr', 'img', 'input', 'meta', 'link', 'source', 'area', 'col']);

function parseSelector(sel) {
  const m = /^([a-zA-Z][\w-]*)?((?:\.[\w-]+)*)(?:\[([\w-]+)\])?$/.exec(String(sel).trim());
  if (!m) return null;
  return { tag: (m[1] || '').toLowerCase(), classes: m[2] ? m[2].split('.').filter(Boolean) : [], attr: m[3] || '' };
}

// An element's classes come from BOTH classList.add() and a `className = '...'`
// assignment (scanner.js uses both); match against the union so neither is missed.
function elClasses(el) {
  const out = new Set(el._classes || []);
  if (typeof el.className === 'string') {
    el.className.split(/\s+/).forEach((c) => { if (c) out.add(c); });
  }
  return out;
}

function matchesSelector(el, q) {
  if (!el || el.nodeType === 3) return false;
  if (q.tag && String(el.tagName || '').toLowerCase() !== q.tag) return false;
  if (q.classes.length) {
    const cs = elClasses(el);
    for (const c of q.classes) { if (!cs.has(c)) return false; }
  }
  if (q.attr && !el[q.attr] && (typeof el.getAttribute !== 'function' || el.getAttribute(q.attr) === null)) return false;
  return true;
}

// Minimal well-formed-HTML tokenizer. The scanner builds its dialog markup from
// hand-written template strings, so a tag/text tokenizer is enough — this is a test
// double, not a browser. Entities are left encoded; attribute values must be quoted.
function parseFragment(html, mk) {
  const nodes = [];
  const stack = [];
  const push = (n) => {
    const parent = stack[stack.length - 1];
    if (parent) { parent.children.push(n); n.parentNode = parent; } else { nodes.push(n); }
  };
  const re = /<(\/?)([a-zA-Z][\w-]*)((?:"[^"]*"|'[^']*'|[^>])*?)(\/?)>/g;
  let last = 0;
  let m;
  while ((m = re.exec(html)) !== null) {
    const text = html.slice(last, m.index);
    if (text) push({ nodeType: 3, textContent: text });
    last = re.lastIndex;
    const tag = m[2].toLowerCase();
    if (m[1] === '/') {
      for (let i = stack.length - 1; i >= 0; i--) { if (stack[i].tagName === tag) { stack.length = i; break; } }
      continue;
    }
    const el = mk('', tag);
    const attrRe = /([\w:.-]+)\s*=\s*"([^"]*)"/g;
    let a;
    while ((a = attrRe.exec(m[3] || '')) !== null) {
      if (a[1] === 'class') el.className = a[2];
      else if (a[1] === 'id') el.id = a[2];
      else el.setAttribute(a[1], a[2]);
    }
    push(el);
    if (m[4] !== '/' && !VOID_TAGS.has(tag)) stack.push(el);
  }
  const tail = html.slice(last);
  if (tail) push({ nodeType: 3, textContent: tail });
  return nodes;
}

function makeEl(id, tagName) {
  const listeners = {};
  const classes = new Set();
  const attrs = {};
  let _text = '';
  return {
    id, _html: '', style: {}, value: 0, disabled: false,
    tagName: tagName || '', nodeType: 1, parentNode: null, open: false,
    children: [],
    classList: {
      add: (c) => classes.add(c), remove: (c) => classes.delete(c),
      contains: (c) => classes.has(c),
      toggle: (c, f) => { const on = (f === undefined) ? !classes.has(c) : f; if (on) classes.add(c); else classes.delete(c); },
    },
    // Raw string stays authoritative (existing tests read _html/innerHTML); the parsed
    // mirror is built lazily and only ever consumed by querySelector/_kids.
    set innerHTML(v) { this._html = v; this._dom = null; }, get innerHTML() { return this._html; },
    // Mirrors the real DOM's textContent -> innerHTML round trip (&/</> only, no quote
    // escaping — matches the codebase's own cuEscHtml() convention, and the esc() comment
    // at scanner.js:96-100 documenting exactly this asymmetry).
    //
    // The GETTER walks appended nodes: restoreStep4 builds the summary line out of <strong>
    // + text nodes (renderSummaryParts), and a getter that returned only _text would report
    // '' for the shipped render — the whole suite would go loudly red on values that are
    // really there. It CONCATENATES rather than replaces, because the real setter creates a
    // text-node CHILD: after `el.textContent = 'a'; el.appendChild(text('b'))` the real DOM
    // reads 'ab', not 'b'. Recursion happens through each child's own getter, so a nested
    // element carrying both assigned text and appended children behaves the same way.
    //
    // Nodes parsed out of an innerHTML string are deliberately NOT walked: that mirror keeps
    // entities encoded, so folding it in would hand back '&lt;img' where the real DOM hands
    // back '<img' (kept-protection-note.test.js:177 pins exactly that raw round trip). The
    // cost of that boundary is a KNOWN residual — an element written by `innerHTML =` and
    // then appendChild'd (scanner.js:462-464 is the only such node in the plugin) still
    // under-reports its innerHTML half. Read those through _kids()/innerHTML, not this
    // getter. Pinned in tests/js/harness-textcontent.test.js, residual included.
    get textContent() {
      if (!this.children.length) return _text;
      return _text + this.children.map((n) => {
        if (!n) return '';
        const t = n.textContent;
        return (t === undefined || t === null) ? '' : String(t);
      }).join('');
    },
    set textContent(v) {
      _text = (v === null || v === undefined) ? '' : String(v);
      this._html = _text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
      // The real setter REPLACES every child. renderSummaryParts relies on it: restoreStep4
      // runs twice per page life and the second render must not stack on the first.
      this.children.length = 0;
      this._dom = null;
    },
    _domChildren() {
      if (this._dom === null || this._dom === undefined) {
        try { this._dom = this._html ? parseFragment(this._html, makeEl) : []; } catch (e) { this._dom = []; }
        this._dom.forEach((n) => { n.parentNode = this; });
      }
      return this._dom;
    },
    // innerHTML-derived nodes first, then appendChild'd ones — matches DOM order.
    _kids() { return this._domChildren().concat(this.children); },
    addEventListener(ev, fn) { (listeners[ev] = listeners[ev] || []).push(fn); },
    _fire(ev, arg) { (listeners[ev] || []).slice().forEach((f) => f(arg || { preventDefault() {}, target: this })); },
    click() { this._fire('click', { preventDefault() {}, target: this }); },
    appendChild(c) { this.children.push(c); if (c) c.parentNode = this; return c; },
    insertBefore(node, ref) {
      const list = (ref && this._domChildren().indexOf(ref) !== -1) ? this._domChildren() : this.children;
      const i = ref ? list.indexOf(ref) : -1;
      if (i === -1) list.push(node); else list.splice(i, 0, node);
      if (node) node.parentNode = this;
      return node;
    },
    remove() {
      const p = this.parentNode;
      if (!p) return;
      for (const list of [p.children, (p._dom || [])]) {
        const i = list.indexOf(this);
        if (i !== -1) { list.splice(i, 1); break; }
      }
      this.parentNode = null;
    },
    showModal() { this.open = true; }, close() { this.open = false; this._fire('close'); },
    querySelector(sel) {
      const q = parseSelector(sel);
      if (!q) return null;
      const walk = (node) => {
        for (const c of (node._kids ? node._kids() : [])) {
          if (matchesSelector(c, q)) return c;
          const hit = walk(c);
          if (hit) return hit;
        }
        return null;
      };
      return walk(this);
    },
    querySelectorAll(sel) {
      const q = parseSelector(sel);
      const out = [];
      if (!q) return out;
      const walk = (node) => {
        for (const c of (node._kids ? node._kids() : [])) {
          if (matchesSelector(c, q)) out.push(c);
          walk(c);
        }
      };
      walk(this);
      return out;
    },
    removeAttribute() {},
    setAttribute(k, v) { attrs[k] = v; }, getAttribute(k) { return (k in attrs) ? attrs[k] : null; },
    // Own assigned text, WITHOUT the appended children the getter above folds in. Exposed
    // for the same reason _html/_classes/_attrs/_dom are: a walker that needs to compose the
    // pieces itself (probe-outcome-dialog.test.js's textOf) cannot subtract them back out of
    // the aggregating getter. Read-only introspection; nothing in the plugin reads it.
    get _ownText() { return _text; },
    _listeners: listeners, _classes: classes, _attrs: attrs, _dom: null,
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
   'cu-progress-text', 'cu-push-result', 'cu-queue-banner', 'cu-result-refund',
   'cu-result-summary',
   'cu-complete-title', 'cu-complete-copy', 'cu-complete-scan-id',
   'cu-metric-urls', 'cu-metric-safe', 'cu-metric-aggressive',
   'cu-metric-credits', 'cu-metric-balance',
   'cu-apply-safe', 'cu-apply-aggressive', 'cu-apply-kept',
    'cu-ready-rule-total', 'cu-ready-credits', 'cu-ready-balance',
    'cu-recommendations-copy', 'cu-recommendations-footnote',
   'cu-results-success-count', 'cu-kept-assets-panel', 'cu-kept-assets-summary',
   'cu-kept-details-toggle',
   'cu-rescan-noopt-button',
   'cu-cu-status-title', 'cu-cu-status-copy', 'cu-cu-settings-link',
   'cu-next-step-title', 'cu-next-step-copy',
   'cu-result-url-list', 'cu-scanner-app', 'cu-sonar-anim', 'cu-step-label',
   'cu-target-stack-notice', 'cu-url-list', 'cu-url-list-area', 'cu-url-next',
   'cu-url-prev', 'cu-paused-banner', 'cu-paused-countdown', 'cu-paused-stopkeep',
   'step-1'].forEach(ensure);

  // #cu-result-summary sits inside a container in admin/views/scanner-page.php, so code that
  // inserts a SIBLING next to it (restoreStep4's kept-protection note) needs a real parentNode.
  // Give it one here. Deliberately NOT attached to `body` — body.children stays reserved for
  // the appended dialogs/toasts other tests assert on. Built before runInContext below so the
  // load-time localStorage-restore path can reach it.
  const summaryHost = makeEl('', 'div');
  summaryHost.appendChild(els['cu-result-summary']);

  const timers = [];
  // document.body is real (an element) so code paths that append dialogs/toasts to it
  // can be asserted on; document-level querySelector stays a no-op, unchanged.
  const body = makeEl('', 'body');
  const sandbox = {
    console,
    window: {},
    document: {
      body,
      getElementById: (id) => els[id] || null,
      createElement: (tag) => makeEl('dyn', String(tag || '').toLowerCase()),
      createTextNode: (t) => ({ nodeType: 3, textContent: String(t), parentNode: null }),
      querySelector: () => null,
      querySelectorAll: (selector) => opts.querySelectorAll ? opts.querySelectorAll(selector, els) : [],
      addEventListener() {},
    },
    sessionStorage: makeStorage(opts.sessionStorage),
    localStorage: makeStorage(opts.localStorage),
    cuScanner: opts.cuScanner || { ajaxUrl: '', nonce: 'n', siteUrl: 's', outbox: { state: 'none' } },
    fetch: opts.fetch || (() => Promise.resolve({ ok: true, json: () => Promise.resolve({}) })),  // benign default: detectPlugins() fires fetch on load; a reject here becomes a fatal unhandled rejection (node>=20) after the test's OK.
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
      constructor() { this._data = []; }
      append(k, v) { this._data.push([k, v]); }
      get(k) { const e = this._data.find(([ek]) => ek === k); return e ? e[1] : null; }
      set(k, v) { this._data = this._data.filter(([ek]) => ek !== k); this._data.push([k, v]); }
      has(k) { return this._data.some(([ek]) => ek === k); }
      delete(k) { this._data = this._data.filter(([ek]) => ek !== k); }
      entries() { return this._data[Symbol.iterator](); }
      toString() { return this._data.map(([k, v]) => k + '=' + encodeURIComponent(v)).join('&'); }
    },
    location: opts.location || { href: '', hostname: '', pathname: '/', search: '', hash: '' },
  };
  sandbox.window = sandbox; sandbox.globalThis = sandbox;
  const code = fs.readFileSync(path.join(__dirname, '../../admin/js/scanner.js'), 'utf8');
  vm.createContext(sandbox);
  vm.runInContext(code, sandbox);
  return { sandbox, els, timers, body, summaryHost };
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

// admin/js/menu-badge.js is the BACKGROUND-completion writer for cu_scanner_result: a scan
// that finishes while the operator is on another wp-admin page never runs scanner.js's
// writer at all. Nothing in this suite used to PARSE that file — it was only ever read as
// source TEXT by pin regexes — so a syntax error in it shipped green through every JS test,
// and commenting a pinned line out still satisfied its pin (P17: "a call sitting in a
// comment"). This runs the real file instead: vm.runInContext PARSES it, so a syntax error
// throws here, and `tick()` drives the shipped heartbeat handler through the Railway status
// poll and cu_scanner_build_result to the localStorage write, so tests can assert the
// payload the production path actually emits.
//
// opts: { sessionStorage, localStorage, fetch, post(data) -> response, aiasMenuBadgeData }
function createMenuBadgeHarness(opts = {}) {
  const handlers = {};
  const posts = [];
  const timers = [];
  // jQuery's Deferred reduced to what menu-badge.js calls — .then() and .always(). Settles
  // synchronously, so a driven tick needs no extra flushing beyond fetch's real promise.
  const deferred = (value) => ({
    then(fn) { return deferred(fn ? fn(value) : undefined); },
    always(fn) { if (fn) fn(value); return deferred(value); },
  });
  const $ = () => ({ on(ev, fn) { (handlers[ev] = handlers[ev] || []).push(fn); return this; } });
  $.post = (url, data) => {
    posts.push(data);
    return deferred((opts.post || (() => ({ success: true, data: {} })))(data));
  };

  const sandbox = {
    console,
    jQuery: $,
    document: { querySelector: () => null, addEventListener() {} },
    sessionStorage: makeStorage(opts.sessionStorage),
    localStorage: makeStorage(opts.localStorage),
    fetch: opts.fetch || (() => Promise.resolve({ json: () => Promise.resolve({}) })),
    // Recorded, never auto-fired: menu-badge.js starts a 30s badge poller at load, and a
    // test that did not ask for it should not have it running underneath.
    setTimeout: (fn, ms) => { timers.push({ fn, ms, type: 'timeout' }); return timers.length; },
    setInterval: (fn, ms) => { timers.push({ fn, ms, type: 'interval' }); return timers.length; },
    aiasMenuBadgeData: ('aiasMenuBadgeData' in opts) ? opts.aiasMenuBadgeData : { ajaxurl: '/admin-ajax.php', nonce: 'n' },
  };
  sandbox.window = sandbox; sandbox.globalThis = sandbox;
  const code = fs.readFileSync(path.join(__dirname, '../../admin/js/menu-badge.js'), 'utf8');
  vm.createContext(sandbox);
  vm.runInContext(code, sandbox);
  return {
    sandbox, posts, timers,
    tick: (response) => (handlers['heartbeat-tick'] || []).forEach((fn) => fn({}, response)),
  };
}

module.exports = { createHarness, createMenuBadgeHarness, makeEl, makeStorage };

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
