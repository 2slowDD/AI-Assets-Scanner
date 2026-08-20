const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');

// 1.8.1b — Step-3 optimizer-bypass presentation. Two defects are pinned here:
//
//   (a) the "Optimizer bypass" validation row hardcoded "Applied" in scanner-page.php, so it
//       claimed a bypass on EVERY scan — including scans against hosts where no optimizer was
//       detected and no bypass param was ever appended to a URL.
//   (b) the appended bypass suffix rendered at the same weight as the URL itself, so the
//       operator could not tell the address being scanned from the scanner's own query params.
//
// Everything below is driven through the REAL production path (handleStatusUpdate -> the
// Step-3 render), not by calling the helpers directly, so the wiring is covered too.

function tbodyRows(h) { return h.els['cu-pages-tbody'].children; }
function label(h)     { return h.els['cu-bypass-status-label'].textContent; }
function copyText(h)  { return h.els['cu-bypass-status-copy'].textContent; }
function isNA(h)      { return h.els['cu-bypass-status-row'].classList.contains('is-not-applicable'); }

function poll(T, pages, completed) {
  T.handleStatusUpdate({
    status: 'in_progress', completed: completed || 0, total: pages.length, pages: pages,
  });
}

// 1) The appended bypass suffix is dimmed; the URL it was appended to is not.
function runSuffixDimmed() {
  const h = createHarness();
  const T = h.sandbox.window.__cuTest;
  T.setScanUrlsForTest(['https://x.test/a/', 'https://x.test/b/'], {});
  poll(T, [
    { status: 'done', url: 'https://x.test/a/?LSCWP_CTRL=before_optm' },
    { status: 'done', url: 'https://x.test/b/' },
  ], 2);
  const rows = tbodyRows(h);
  assert.ok(rows[0]._html.includes('<span class="cu-live-bypass-suffix">?LSCWP_CTRL=before_optm</span>'),
    'bypass suffix is wrapped for dimming: ' + rows[0]._html);
  assert.ok(rows[0]._html.includes('https://x.test/a/<span'),
    'the scanned URL stays OUTSIDE the dimmed span: ' + rows[0]._html);
  assert.ok(!rows[1]._html.includes('cu-live-bypass-suffix'),
    'a URL with no bypass gets no dimmed span: ' + rows[1]._html);
  console.log('OK step3-bypass: suffix dimmed, URL undimmed');
}

// 2) FALSE-POSITIVE GUARD. A user-supplied query string is NOT the scanner's bypass suffix.
//    Keying on a bare "?" would dim it AND would flip the status row to a false "Applied".
function runUserQueryIsNotABypass() {
  const h = createHarness();
  const T = h.sandbox.window.__cuTest;
  T.setScanUrlsForTest(['https://x.test/a/'], {});
  poll(T, [{ status: 'done', url: 'https://x.test/a/?utm_source=news&utm_medium=email' }], 1);
  assert.ok(!tbodyRows(h)[0]._html.includes('cu-live-bypass-suffix'),
    'a user query string must NOT be dimmed as a bypass suffix: ' + tbodyRows(h)[0]._html);
  assert.strictEqual(label(h), 'Not applied (N/A)', 'a user query string must not claim a bypass');
  console.log('OK step3-bypass: user query string is not mistaken for a bypass');
}

// 3) A bypass appended AFTER a user query string dims only the appended tail.
//    build_scan_url() always appends bypass params last, so the split is at the first one.
function runMixedQuerySplitsAtBypass() {
  const h = createHarness();
  const T = h.sandbox.window.__cuTest;
  T.setScanUrlsForTest(['https://x.test/a/'], {});
  poll(T, [{ status: 'done', url: 'https://x.test/a/?utm_source=news&nowprocket&nowpcu' }], 1);
  const html = tbodyRows(h)[0]._html;
  assert.ok(html.includes('https://x.test/a/?utm_source=news<span class="cu-live-bypass-suffix">&amp;nowprocket&amp;nowpcu</span>'),
    'only the appended bypass tail is dimmed: ' + html);
  assert.strictEqual(label(h), 'Applied', 'a real bypass param reports Applied');
  console.log('OK step3-bypass: mixed query splits at the first bypass param');
}

// 4) Status state machine: no verdict until the evidence is in.
function runStatusStateMachine() {
  const h = createHarness();
  const T = h.sandbox.window.__cuTest;
  T.setScanUrlsForTest(['https://x.test/a/', 'https://x.test/b/'], {});

  // Not every page has a worker-echoed URL yet -> make NO claim either way.
  poll(T, [{ status: 'done', url: 'https://x.test/a/' }, { status: 'pending' }], 1);
  assert.strictEqual(label(h), 'Checking…', 'unresolved pages must not produce a verdict');
  assert.strictEqual(isNA(h), false, 'the pending state is not the N/A state');

  // All resolved, none carried a bypass -> the honest negative.
  poll(T, [{ status: 'done', url: 'https://x.test/a/' }, { status: 'done', url: 'https://x.test/b/' }], 2);
  assert.strictEqual(label(h), 'Not applied (N/A)', 'no bypass anywhere -> Not applied');
  assert.strictEqual(isNA(h), true, 'the N/A state is flagged for neutral styling');
  assert.ok(/No optimizer bypass was needed/.test(copyText(h)), 'supporting copy matches the N/A verdict');
  console.log('OK step3-bypass: Checking -> Not applied (N/A)');
}

// 5) THE LATCH. Rows arrive progressively; a page that has not started yet comes back without
//    a url and its row falls back to the CLEAN submitted URL. Once a bypass has been seen the
//    verdict must not flip back, or the row would lie in the other direction.
function runLatchHolds() {
  const h = createHarness();
  const T = h.sandbox.window.__cuTest;
  T.setScanUrlsForTest(['https://x.test/a/', 'https://x.test/b/'], {});

  poll(T, [{ status: 'done', url: 'https://x.test/a/?nowprocket' }, { status: 'pending' }], 1);
  assert.strictEqual(label(h), 'Applied', 'first sighting of a bypass latches Applied');

  // A later poll whose payload happens to carry no bypass must NOT downgrade the verdict.
  // Operator rule: one bypass in a multi-URL scan is enough to read "Applied".
  poll(T, [{ status: 'done', url: 'https://x.test/a/' }, { status: 'done', url: 'https://x.test/b/' }], 2);
  assert.strictEqual(label(h), 'Applied', 'a later bypass-free payload must not clear the latch');
  assert.strictEqual(isNA(h), false, 'a latched Applied never wears the N/A styling');
  console.log('OK step3-bypass: Applied latches and survives later polls');
}

// 6) Splitting the URL must not drop an escape on either half.
function runEscapingSurvivesTheSplit() {
  const h = createHarness();
  const T = h.sandbox.window.__cuTest;
  T.setScanUrlsForTest(['https://x.test/a/'], {});
  poll(T, [{ status: 'done', url: 'https://x.test/a/?q="><img src=x onerror=alert(1)>&nowprocket' }], 1);
  const html = tbodyRows(h)[0]._html;
  assert.ok(!/<img/i.test(html), 'no raw tag survives the split: ' + html);
  assert.ok(html.includes('&quot;') && html.includes('&lt;img'), 'both halves stay escaped: ' + html);
  assert.ok(html.includes('cu-live-bypass-suffix'), 'the bypass tail is still detected after hostile input');
  console.log('OK step3-bypass: escaping survives the URL split');
}

runSuffixDimmed();
runUserQueryIsNotABypass();
runMixedQuerySplitsAtBypass();
runStatusStateMachine();
runLatchHolds();
runEscapingSurvivesTheSplit();
console.log('ALL step3-bypass-status tests passed');
