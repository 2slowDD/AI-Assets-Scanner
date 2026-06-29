const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');

// FU-AAS-UNDEFINED-URL (1.7.53b) — the live Step-3 "Scanning" table renders each row's URL
// from the worker's per-page status. Pending (not-yet-started) pages come back without a
// `url` ({status:'pending'} for indices beyond PAGE_CONCURRENCY=3 at 0/N), so page.url was
// undefined → esc(undefined) printed the literal "undefined" in those rows. Prod repro on
// 1.7.52b: first 3 URLs real, the rest "undefined" across 6/4/5-URL scans. The fix falls
// back to the resolved submitted URL (selectedUrls / resolvedByUrl) so pending rows match
// the started rows.

function tbodyRows(h) { return h.els['cu-pages-tbody'].children; }

// 1) Core: pending rows (no page.url) fall back to the submitted URL; NO row says "undefined".
function runPendingFallback() {
  const h = createHarness();
  const T = h.sandbox.window.__cuTest;
  assert.ok(T.setScanUrlsForTest, 'setScanUrlsForTest seam must exist');

  const submitted = [
    'https://x.test/a', 'https://x.test/b', 'https://x.test/c',
    'https://x.test/d', 'https://x.test/e', 'https://x.test/f',
  ];
  T.setScanUrlsForTest(submitted, {}); // resolvedByUrl empty → resolved == submitted

  // Worker mid-scan at 0/6: first 3 in-flight (carry url), rest pending (NO url) — the bug input.
  T.handleStatusUpdate({
    status: 'in_progress', completed: 0, total: 6,
    pages: [
      { status: 'done',    url: 'https://x.test/a' },
      { status: 'done',    url: 'https://x.test/b' },
      { status: 'pending', url: 'https://x.test/c' },
      { status: 'pending' },                          // not started — no url
      { status: 'pending' },                          // not started — no url
      { status: 'pending' },                          // not started — no url
    ],
  });

  const rows = tbodyRows(h);
  assert.strictEqual(rows.length, 6, 'all 6 rows rendered');
  rows.forEach((r, i) => assert.ok(!r._html.includes('undefined'), `row ${i} must not render "undefined": ${r._html}`));
  assert.ok(rows[3]._html.includes('https://x.test/d'), 'pending row 3 falls back to submitted url d');
  assert.ok(rows[4]._html.includes('https://x.test/e'), 'pending row 4 falls back to submitted url e');
  assert.ok(rows[5]._html.includes('https://x.test/f'), 'pending row 5 falls back to submitted url f');
  assert.ok(rows[0]._html.includes('https://x.test/a'), 'started row 0 keeps the worker url a');
  console.log('OK scanning-url-fallback: pending rows fall back, no "undefined"');
}

// 2) Display consistency: a redirect-resolved (suffixed) URL is shown on the pending row,
//    matching what the worker echoes for started rows.
function runResolvedSuffix() {
  const h = createHarness();
  const T = h.sandbox.window.__cuTest;
  const submitted = ['https://x.test/a', 'https://x.test/b'];
  const resolved  = { 'https://x.test/b': 'https://x.test/b/?nowprocket&nowpcu' };
  T.setScanUrlsForTest(submitted, resolved);
  T.handleStatusUpdate({
    status: 'in_progress', completed: 0, total: 2,
    pages: [ { status: 'done', url: 'https://x.test/a' }, { status: 'pending' } ],
  });
  const rows = tbodyRows(h);
  assert.ok(rows[1]._html.includes('nowpcu'), 'pending row uses the resolved (suffixed) url: ' + rows[1]._html);
  assert.ok(!rows[1]._html.includes('undefined'), 'no undefined');
  console.log('OK scanning-url-fallback: resolved-suffix consistency');
}

// 3) Reattach (fresh tab, no submit → selectedUrls empty): a pending row with neither a
//    page.url nor a submitted url renders EMPTY, never "undefined".
function runReattachGraceful() {
  const h = createHarness();
  const T = h.sandbox.window.__cuTest;
  // intentionally do NOT seed urls
  T.handleStatusUpdate({
    status: 'in_progress', completed: 0, total: 2,
    pages: [ { status: 'done', url: 'https://x.test/a' }, { status: 'pending' } ],
  });
  const rows = tbodyRows(h);
  assert.ok(!rows[1]._html.includes('undefined'), 'reattach pending row must not render "undefined": ' + rows[1]._html);
  console.log('OK scanning-url-fallback: reattach renders empty not undefined');
}

runPendingFallback();
runResolvedSuffix();
runReattachGraceful();
console.log('ALL scanning-url-fallback tests passed');
