const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');

// 1.8.6 — the Step-3 "Live URL status" table shows at most 15 URLs per page. Operator rulings
// (2026-09-10): page size 15; the page moves ONLY when the user clicks Prev / Next (no
// auto-follow of scan progress); the pager reads "Page N of M", like the Step-4 results pager.
//
// Everything is driven through the production path — handleStatusUpdate() for polls, click()
// on the real pager elements for paging, beginScanPolling() for a new scan — never by calling
// the pager helper directly.

const PER_PAGE = 15;

function rows(h)   { return h.els['cu-pages-tbody'].children; }
function el(h, id) { return h.els[id]; }
function url(i)    { return 'https://x.test/p' + i + '/'; }
function range(from, to) { return Array.from({ length: to - from }, (_, k) => from + k); }

// n pages: the first `done` carry a worker-echoed url, the rest are pending with NO url —
// exactly what the worker returns for pages its slots have not reached yet.
function pagesOf(n, done) {
  return Array.from({ length: n }, (_, i) => (i < done ? { status: 'done', url: url(i) } : { status: 'pending' }));
}

function poll(T, pages) {
  T.handleStatusUpdate({ status: 'in_progress', completed: 0, total: pages.length, pages: pages });
}

// Indices of the rows on screen. Every row's `hidden` must be a real boolean: a row nobody set
// (`undefined`) is visible in the browser, and a falsy check would wave it through.
function shown(h) {
  const out = [];
  rows(h).forEach((r, i) => {
    assert.strictEqual(typeof r.hidden, 'boolean', 'row ' + i + ' must have hidden set explicitly, got ' + r.hidden);
    if (r.hidden === false) out.push(i);
  });
  return out;
}

function fresh(n) {
  const h = createHarness();
  const T = h.sandbox.window.__cuTest;
  T.setScanUrlsForTest(range(0, n).map(url), {});
  return { h, T };
}

// 1) Exactly one page's worth: no pager, nothing hidden.
function runFifteenIsOnePage() {
  const { h, T } = fresh(PER_PAGE);
  poll(T, pagesOf(PER_PAGE, 0));
  assert.strictEqual(rows(h).length, 15, 'one row per URL');
  assert.deepStrictEqual(shown(h), range(0, 15), 'all 15 rows visible');
  assert.strictEqual(el(h, 'cu-live-pager').hidden, true, 'no pager for a single page');
  console.log('OK step3-live-pager: 15 URLs = one page, pager hidden');
}

// 2) One URL over: page 1 of 2, rows 0-14 only, Prev disabled, Next enabled.
function runSixteenPaginates() {
  const { h, T } = fresh(16);
  poll(T, pagesOf(16, 0));
  assert.strictEqual(rows(h).length, 16, 'every row stays in the DOM');
  assert.deepStrictEqual(shown(h), range(0, 15), 'page 1 shows rows 0-14');
  assert.strictEqual(rows(h)[15].hidden, true, 'row 15 is on page 2');
  assert.strictEqual(el(h, 'cu-live-pager').hidden, false, 'pager shown above one page');
  assert.strictEqual(el(h, 'cu-live-page-label').textContent, 'Page 1 of 2');
  assert.strictEqual(el(h, 'cu-live-prev').disabled, true, 'Prev disabled on page 1');
  assert.strictEqual(el(h, 'cu-live-next').disabled, false, 'Next enabled on page 1 of 2');
  console.log('OK step3-live-pager: 16 URLs = page 1 of 2');
}

// 3) Manual only — scan progress onto page 2 must NOT move the table.
function runProgressDoesNotMoveThePage() {
  const { h, T } = fresh(30);
  poll(T, pagesOf(30, 0));
  poll(T, pagesOf(30, 20)); // page 1 finished; the scan is working on page 2
  assert.strictEqual(rows(h).length, 30, 'rows are updated in place, not re-appended');
  assert.deepStrictEqual(shown(h), range(0, 15), 'still page 1 — no auto-follow');
  assert.strictEqual(el(h, 'cu-live-page-label').textContent, 'Page 1 of 2');
  console.log('OK step3-live-pager: progress does not move the page');
}

// 4) Paging by click — and the chosen page SURVIVES the next poll (handleStatusUpdate runs every 2 s).
function runClickPagingSurvivesPolls() {
  const { h, T } = fresh(40);
  poll(T, pagesOf(40, 0));
  el(h, 'cu-live-next').click();
  assert.deepStrictEqual(shown(h), range(15, 30), 'Next shows rows 15-29');
  assert.strictEqual(el(h, 'cu-live-page-label').textContent, 'Page 2 of 3');
  assert.strictEqual(el(h, 'cu-live-prev').disabled, false, 'Prev enabled on a middle page');
  assert.strictEqual(el(h, 'cu-live-next').disabled, false, 'Next enabled on a middle page');

  poll(T, pagesOf(40, 5));
  assert.deepStrictEqual(shown(h), range(15, 30), 'a poll keeps the user on page 2');
  assert.strictEqual(el(h, 'cu-live-page-label').textContent, 'Page 2 of 3');

  el(h, 'cu-live-next').click();
  assert.deepStrictEqual(shown(h), range(30, 40), 'the last page shows the remaining 10 rows');
  assert.strictEqual(el(h, 'cu-live-page-label').textContent, 'Page 3 of 3');
  assert.strictEqual(el(h, 'cu-live-next').disabled, true, 'Next disabled on the last page');

  el(h, 'cu-live-next').click(); // the harness fires click even on a disabled button
  assert.strictEqual(el(h, 'cu-live-page-label').textContent, 'Page 3 of 3', 'Next past the last page is a no-op');

  el(h, 'cu-live-prev').click();
  assert.deepStrictEqual(shown(h), range(15, 30), 'Prev goes back one page');
  el(h, 'cu-live-prev').click();
  el(h, 'cu-live-prev').click(); // no-op on page 1
  assert.deepStrictEqual(shown(h), range(0, 15), 'Prev stops at page 1');
  assert.strictEqual(el(h, 'cu-live-prev').disabled, true, 'Prev disabled again on page 1');
  console.log('OK step3-live-pager: click paging, page survives polls');
}

// 5) A new scan opens on page 1 — and the old pager does not linger while the new scan is queued
//    (the queued branch returns before the row loop, so nothing else would hide it).
function runNewScanResetsToPageOne() {
  const { h, T } = fresh(40);
  poll(T, pagesOf(40, 0));
  el(h, 'cu-live-next').click();
  assert.strictEqual(el(h, 'cu-live-page-label').textContent, 'Page 2 of 3', 'precondition: on page 2');

  T.beginScanPolling();
  assert.strictEqual(rows(h).length, 0, 'the new scan clears the old rows');
  assert.strictEqual(el(h, 'cu-live-pager').hidden, true, 'no stale pager while the new scan is queued');

  poll(T, pagesOf(40, 0));
  assert.deepStrictEqual(shown(h), range(0, 15), 'the new scan opens on page 1');
  assert.strictEqual(el(h, 'cu-live-page-label').textContent, 'Page 1 of 3');
  console.log('OK step3-live-pager: a new scan resets to page 1');
}

// 6) The bypass verdict is still computed from ALL pages, not from the page on screen.
function runBypassVerdictUsesEveryPage() {
  const { h, T } = fresh(20);
  const pages = pagesOf(20, 20);
  pages[18] = { status: 'done', url: url(18) + '?nowprocket' }; // the only bypass — on page 2
  poll(T, pages);
  assert.strictEqual(rows(h)[18].hidden, true, 'precondition: the bypassed row is off screen');
  assert.strictEqual(el(h, 'cu-bypass-status-label').textContent, 'Applied', 'a bypass on page 2 still reads Applied');
  console.log('OK step3-live-pager: bypass verdict sees every page');
}

// 7) A pending row on page 2 falls back to the submitted URL by its GLOBAL index.
function runFallbackUsesGlobalIndex() {
  const { h, T } = fresh(20);
  poll(T, pagesOf(20, 3));
  el(h, 'cu-live-next').click();
  assert.strictEqual(rows(h)[17].hidden, false, 'row 17 is on screen on page 2');
  assert.ok(rows(h)[17]._html.includes(url(17)), 'pending row 17 shows submitted url 17: ' + rows(h)[17]._html);
  assert.ok(!rows(h)[17]._html.includes(url(2)), 'not the page-local url 2: ' + rows(h)[17]._html);
  console.log('OK step3-live-pager: pending fallback keeps the global index');
}

// 8) Fail OPEN: if the view ever lacks the pager (a partial deploy, an id drift), no row may be
//    hidden — rows 16+ would be unreachable, which is worse than no pagination at all.
function runMissingPagerHidesNothing() {
  const { h, T } = fresh(20);
  delete h.els['cu-live-pager'];
  poll(T, pagesOf(20, 0));
  assert.strictEqual(rows(h).length, 20, 'one row per URL');
  rows(h).forEach((r, i) => assert.notStrictEqual(r.hidden, true, 'row ' + i + ' must stay visible without a pager'));
  console.log('OK step3-live-pager: a missing pager hides nothing');
}

// 9) An outbox dispatch is a NEW scan (a submit that hit a network error and was queued), so it
//    must reset like one: the previous scan's rows and page must not carry over. Driven through
//    the real path: page-load outbox restore -> startOutboxTick -> 30 s tick -> 'dispatched'.
async function runOutboxDispatchIsANewScan() {
  const h = createHarness({
    cuScanner: { ajaxUrl: '', nonce: 'n', siteUrl: 's', outbox: { state: 'queued' } },
    fetch: (u, init) => {
      const action = init && init.body && init.body.get ? init.body.get('action') : '';
      const body = action === 'cu_scanner_outbox_tick'
        ? { success: true, data: { state: 'dispatched', job_id: 'j2', job_token: 't2', railway_url: 'https://w.test' } }
        : {};
      return Promise.resolve({ ok: true, json: () => Promise.resolve(body) });
    },
  });
  const T = h.sandbox.window.__cuTest;
  T.setScanUrlsForTest(range(0, 40).map(url), {});
  poll(T, pagesOf(40, 0)); // the previous scan, left on page 3
  el(h, 'cu-live-next').click();
  el(h, 'cu-live-next').click();
  assert.strictEqual(el(h, 'cu-live-page-label').textContent, 'Page 3 of 3', 'precondition: on page 3');

  const tick = h.timers.find((t) => t.type === 'interval' && t.ms === 30000);
  assert.ok(tick, 'the page-load outbox restore started the 30 s tick');
  tick.fn();
  for (let i = 0; i < 5; i++) await new Promise((r) => setImmediate(r));

  assert.strictEqual(rows(h).length, 0, 'the dispatched scan clears the previous scan\'s rows');
  assert.strictEqual(el(h, 'cu-live-pager').hidden, true, 'and its pager');
  T.setScanUrlsForTest(range(0, 10).map(url), {});
  poll(T, pagesOf(10, 0));
  assert.deepStrictEqual(shown(h), range(0, 10), 'the new 10-URL scan shows all of its rows');
  console.log('OK step3-live-pager: an outbox dispatch starts a fresh table');
}

(async () => {
  runFifteenIsOnePage();
  runSixteenPaginates();
  runProgressDoesNotMoveThePage();
  runClickPagingSurvivesPolls();
  runNewScanResetsToPageOne();
  runBypassVerdictUsesEveryPage();
  runFallbackUsesGlobalIndex();
  runMissingPagerHidesNothing();
  await runOutboxDispatchIsANewScan();
  console.log('ALL step3-live-pager tests passed');
})().catch((e) => { console.error(e); process.exit(1); });
