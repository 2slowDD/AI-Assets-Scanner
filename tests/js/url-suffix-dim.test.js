// FU-AAS-URL-SUFFIX-DIM — the results-table URL cell splits the scanned URL at its FIRST '?'
// and wraps the query string in <span class="cu-url-suffix"> so the page path reads stronger
// than the optimizer-bypass suffix the scanner appended. Renders the REAL admin/js/scanner.js
// through restoreStep4 -> renderResultUrlList (no jest in this repo; see r3-stage-c-harness.js).
//
// The four cases below are chosen so that each one goes RED under a different specific mistake:
//   1. span dropped / never wired          -> testSuffixWrapped
//   2. split on LAST '?' or on every '?'   -> testSplitsOnFirstQuestionMarkOnly
//   3. span emitted for suffix-less URLs   -> testNoQueryStringIsUntouched  (regression: the
//      exact-match asserts in channel-off-note.test.js all use suffix-less fixture URLs)
//   4. escaping lost across the split      -> testEscapingSurvivesTheSplit
const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');

// Mirrors scanner.js's cuEscHtml() (textContent -> innerHTML: escapes & < > but NOT quotes).
// Kept local so this test does not depend on cuEscHtml being exported.
function escHtml(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function urlCellHtml(tableHtml, rowIndex) {
  const re = /<td class="cu-url-cell">([\s\S]*?)<\/td>/g;
  let m, i = 0;
  while ((m = re.exec(tableHtml)) !== null) {
    if (i === rowIndex) return m[1];
    i++;
  }
  throw new Error('row ' + rowIndex + ' not found');
}

function render(pages) {
  const h = createHarness();
  h.sandbox.window.__cuTest.restoreStep4({
    jobId: 'job1', safeCount: 1, aggCount: 0, canPush: true, externalOnly: false,
    bannerData: {}, urlsScanned: pages.length, pages: pages, scanId: 'scan1',
    hasActiveCuRules: false
  });
  return h.els['cu-result-url-list']._html;
}

function makeRow(n, url) {
  return {
    n: n, url: url, status_class: 'ok', status_label: 'OK',
    credits: 1, safe: 1, aggressive: 0, needed: 0, et_candidate: false,
    visual_channel_off: []
  };
}

function cellFor(url) {
  return urlCellHtml(render([makeRow(1, url)]), 0);
}

// 1. The suffix is wrapped, the path is not, and '&' is still escaped inside the span.
function testSuffixWrapped() {
  const cell = cellFor('https://example.test/a/?nowprocket&nowpcu&perfmattersoff');
  const want = '<span class="cu-url-primary">https://example.test/a/'
             + '<span class="cu-url-suffix">' + escHtml('?nowprocket&nowpcu&perfmattersoff') + '</span></span>';
  assert.strictEqual(cell, want, 'URL cell must render its primary line with a dimmed, escaped suffix span');
  console.log('OK suffix wrapped in .cu-url-suffix (path outside the span)');
}

// 2. Later '?' characters are legal inside a query value — split on the FIRST one only.
function testSplitsOnFirstQuestionMarkOnly() {
  const cell = cellFor('https://example.test/b/?a=1&next=/x?y=2');
  assert.strictEqual((cell.match(/cu-url-suffix/g) || []).length, 1,
    'exactly one suffix span even when the query value contains another "?"');
  assert.ok(cell.indexOf('<span class="cu-url-primary">https://example.test/b/<span class="cu-url-suffix">?a=1') === 0,
    'span must open at the FIRST "?", with the whole remainder inside it');
  console.log('OK splits on the first "?" only');
}

// 3. A URL with no query string must render exactly as it did before this feature —
//    channel-off-note.test.js asserts URL-cell HTML with strictEqual on suffix-less URLs.
function testNoQueryStringIsUntouched() {
  const cell = cellFor('https://example.test/c/');
  assert.strictEqual(cell, '<span class="cu-url-primary">https://example.test/c/</span>',
    'suffix-less URL must render in the primary URL line with no suffix span');
  assert.ok(!/cu-url-suffix/.test(cell), 'suffix-less URL must emit no .cu-url-suffix span');
  console.log('OK suffix-less URL renders byte-identically (no span)');
}

// 4. Splitting the string must not open an escaping hole on either side of the '?'.
function testEscapingSurvivesTheSplit() {
  const hostile = 'https://example.test/<script>x</script>/?q=<img src=x>&b=1';
  const cell = cellFor(hostile);
  assert.ok(!/<script>/.test(cell), 'raw <script> must never survive into the cell');
  assert.ok(!/<img /.test(cell), 'raw <img> must never survive into the cell');
  assert.ok(cell.indexOf(escHtml('/<script>x</script>/')) !== -1, 'path half stays escaped');
  assert.ok(cell.indexOf(escHtml('?q=<img src=x>&b=1')) !== -1, 'query half stays escaped');
  // The ONLY markup the cell may introduce is the suffix span itself.
  const tags = cell.match(/<[^>]+>/g) || [];
  assert.deepStrictEqual(tags, ['<span class="cu-url-primary">', '<span class="cu-url-suffix">', '</span>', '</span>'],
    'only the primary URL and suffix spans may be emitted for a hostile URL');
  console.log('OK escaping survives the split (both halves, no injected markup)');
}

testSuffixWrapped();
testSplitsOnFirstQuestionMarkOnly();
testNoQueryStringIsUntouched();
testEscapingSurvivesTheSplit();
console.log('url-suffix-dim: all assertions passed');
