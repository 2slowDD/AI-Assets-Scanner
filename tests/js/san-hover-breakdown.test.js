const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');

// FU-SAN-HOVER-BREAKDOWN (1.8.2b) — hovering S: or A: names the assets behind that number, the
// same way the kept chip already names the assets it kept.
//
// Everything here drives the REAL Step-4 renderer (restoreStep4 -> renderResultUrlList -> the
// post-render title pass), not the helpers directly, so the wiring is covered too. The producer
// side (CuJsonBuilder::build / ScannerAjax::recompute_by_page, whose counts these lists sum to by
// construction) is pinned separately in CuJsonBuilderTest.
//
// Two rules this file exists to defend:
//   1. A tooltip must never appear on a zero token — there is nothing to name.
//   2. A tooltip must never contradict the digit it hangs off. The `all_already` case is the
//      sharp edge: those rows DISPLAY S:0 A:0 while the raw safe/aggressive fields stay positive,
//      so a tooltip keyed off the raw field would name assets on a token reading 0.

function makePage(over) {
  return Object.assign({
    n: 1, url: 'https://x.test/a', status_class: 'ok', status_label: 'OK',
    credits: 1, safe: 0, aggressive: 0, needed: 3, kept_count: 0,
  }, over);
}

function render(h, pages) {
  h.sandbox.window.__cuTest.restoreStep4({
    jobId: 'job-san-hover', safeCount: 0, aggCount: 0, canPush: true, externalOnly: false,
    bannerData: {}, urlsScanned: pages.length, pages: pages, scanId: 'scan-san-hover',
    hasActiveCuRules: false, keptProtectionSummary: { count: 0, labels: [] }, availableBalance: 10,
  });
  return h.els['cu-result-url-list'];
}

function tokens(host) {
  return host.querySelectorAll('.cu-san-token[data-cu-san]');
}

// 1) A positive S: is tagged and titled with its handles; the zero A: beside it is neither.
function runSafeTitled() {
  const h = createHarness();
  const host = render(h, [makePage({
    safe: 2, aggressive: 0,
    safe_breakdown: [{ label: 'jquery-migrate', count: 1 }, { label: 'wc-blocks-style', count: 1 }],
    aggressive_breakdown: [],
  })]);

  const tagged = tokens(host);
  assert.strictEqual(tagged.length, 1, 'only the positive token is tagged: ' + tagged.length);
  assert.strictEqual(tagged[0].getAttribute('data-cu-san'), 'safe');
  assert.strictEqual(
    tagged[0].title,
    'Safe to unload on this page: jquery-migrate, wc-blocks-style',
    'S: names its handles, comma-joined, in producer order'
  );
  console.log('OK san-hover: S: titled with its handles, A:0 untagged');
}

// 2) Aggressive side, and a handle that produced more than one rule renders "handle (2)" — so the
//    parenthesised counts SUM to the number on the token (2 + 1 === A:3).
function runAggressiveTitledWithCounts() {
  const h = createHarness();
  const host = render(h, [makePage({
    safe: 0, aggressive: 3,
    safe_breakdown: [],
    aggressive_breakdown: [{ label: 'dup-handle', count: 2 }, { label: 'solo-handle', count: 1 }],
  })]);

  const tagged = tokens(host);
  assert.strictEqual(tagged.length, 1);
  assert.strictEqual(tagged[0].getAttribute('data-cu-san'), 'aggressive');
  assert.strictEqual(
    tagged[0].title,
    'Aggressive — loaded but unused on this page: dup-handle (2), solo-handle',
    'A: parenthesises a repeated handle so the counts still sum to the token'
  );
  console.log('OK san-hover: A: titled, repeated handle carries its count');
}

// 3) An all-zero row gets no tagged token at all — nothing to name, so no hover affordance.
function runZeroRowHasNoHover() {
  const h = createHarness();
  const host = render(h, [makePage({ safe: 0, aggressive: 0, safe_breakdown: [], aggressive_breakdown: [] })]);
  assert.strictEqual(tokens(host).length, 0, 'a zero row tags nothing');
  assert.ok(!/data-cu-san/.test(host._html), 'no hover marker anywhere in a zero row');
  console.log('OK san-hover: zero row has no hover affordance');
}

// 4) THE SHARP EDGE. all_already displays S:0 A:0 while the raw fields stay positive. The token
//    must stay untagged and untitled — a tooltip here would name assets on a token reading 0.
function runAllAlreadyNeverTitled() {
  const h = createHarness();
  const host = render(h, [makePage({
    safe: 5, aggressive: 4, all_already: true,
    safe_breakdown: [{ label: 'already-safe', count: 5 }],
    aggressive_breakdown: [{ label: 'already-agg', count: 4 }],
  })]);

  assert.strictEqual(tokens(host).length, 0, 'an all_already row must tag nothing');
  assert.ok(!/already-safe|already-agg/.test(host._html), 'no handle leaks into an all_already row');
  console.log('OK san-hover: all_already (displays S:0 A:0) is never titled');
}

// 5) N: is the untouched-asset residue, not a recommendation — never tagged however large.
function runNeededNeverTagged() {
  const h = createHarness();
  const host = render(h, [makePage({
    safe: 1, aggressive: 0, needed: 240,
    safe_breakdown: [{ label: 'only-safe', count: 1 }], aggressive_breakdown: [],
  })]);
  const tagged = tokens(host);
  assert.strictEqual(tagged.length, 1, 'only S: is tagged');
  assert.strictEqual(tagged[0].getAttribute('data-cu-san'), 'safe');
  assert.ok(!/cu-san-needed"[^>]*data-cu-san/.test(host._html), 'N: is never tagged');
  console.log('OK san-hover: N:240 never tagged');
}

// 6) A legacy row (no breakdown keys — older transient, replayed job) still renders and simply
//    goes untitled. The count is unaffected; only the hover is absent.
function runLegacyRowDegrades() {
  const h = createHarness();
  const host = render(h, [makePage({ safe: 2, aggressive: 0 })]); // no *_breakdown keys at all
  const tagged = tokens(host);
  assert.strictEqual(tagged.length, 1, 'the token is still tagged (the count is real)');
  // strictEqual(undefined), NOT a falsy check: `title = ''` is falsy too, and the difference is
  // load-bearing — the `.cu-san-token[title]` cursor rule matches an EMPTY title, so an
  // empty-string assignment would show a help cursor promising a tooltip that never appears.
  // A falsy assertion here survives that mutation; this one does not.
  assert.strictEqual(tagged[0].title, undefined,
    'a legacy row with no breakdown must be left untitled, not assigned an empty title');
  assert.ok(/<strong>S:2<\/strong>/.test(host._html), 'the count itself still renders');
  console.log('OK san-hover: legacy row degrades to untitled, count intact');
}

runSafeTitled();
runAggressiveTitledWithCounts();
runZeroRowHasNoHover();
runAllAlreadyNeverTitled();
runNeededNeverTagged();
runLegacyRowDegrades();
console.log('ALL san-hover-breakdown tests passed');
