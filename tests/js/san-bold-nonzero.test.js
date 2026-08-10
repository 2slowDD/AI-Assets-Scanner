// FU-AAS-SAN-BOLD-NONZERO — the results-table S/A/N cell bolds S: and A: ONLY when that
// count is > 0. N: is never bolded (it is the untouched-assets residue, not an outcome).
// Renders the REAL admin/js/scanner.js through restoreStep4 -> renderResultUrlList
// (no jest in this repo; see r3-stage-c-harness.js).
//
// The cases below are chosen so that each goes RED under a different specific mistake:
//   1. bolding never wired, or applied to the wrong token   -> testAggressiveNonZeroIsBold
//   2. bolding keyed off the wrong field (A checked for S)  -> testSafeNonZeroIsBold
//   3. bolding applied unconditionally / to zero counts     -> testZeroCountsAreNotBold
//   4. N: dragged into the bolding rule                     -> testNeededIsNeverBold
//   5. error rows made to render counts instead of an em-dash -> testErrorRowUnchanged
const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');

function sanCellHtml(tableHtml, rowIndex) {
  const re = /<td class="cu-san">([\s\S]*?)<\/td>/g;
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

function makeRow(opts) {
  return Object.assign({
    n: 1, url: 'https://example.test/a/', status_class: 'ok', status_label: 'OK',
    credits: 1, safe: 0, aggressive: 0, needed: 0, et_candidate: false,
    visual_channel_off: []
  }, opts);
}

function cellFor(opts) {
  return sanCellHtml(render([makeRow(opts)]), 0);
}

// 1. The screenshot case: A:13 bold, S:0 plain, N:56 plain.
function testAggressiveNonZeroIsBold() {
  const cell = cellFor({ safe: 0, aggressive: 13, needed: 56 });
  assert.strictEqual(cell, 'S:0 <strong>A:13</strong> N:56',
    'A > 0 must be bold; S:0 and N: must stay plain');
  console.log('OK aggressive > 0 renders <strong>A:13</strong>, S:0 / N:56 plain');
}

// 2. Same rule on the safe side — guards against both tokens keying off one field.
function testSafeNonZeroIsBold() {
  const cell = cellFor({ safe: 4, aggressive: 0, needed: 12 });
  assert.strictEqual(cell, '<strong>S:4</strong> A:0 N:12',
    'S > 0 must be bold; A:0 and N: must stay plain');
  console.log('OK safe > 0 renders <strong>S:4</strong>, A:0 / N:12 plain');
}

// 3. The all-zero row must emit no <strong> at all (this is the common row shape —
//    unconditional bolding would make the whole column heavy and defeat the point).
function testZeroCountsAreNotBold() {
  const cell = cellFor({ safe: 0, aggressive: 0, needed: 93 });
  assert.ok(cell.indexOf('S:0 A:0 N:93') === 0,
    'zero counts must render plain, in order, with no markup between them');
  assert.ok(!/<strong>/.test(cell), 'a zero-count row must emit no <strong> at all');
  console.log('OK zero counts emit no <strong>');
}

// 4. N: is not an outcome and must never be bolded, however large it gets.
function testNeededIsNeverBold() {
  const cell = cellFor({ safe: 2, aggressive: 7, needed: 240 });
  assert.strictEqual(cell, '<strong>S:2</strong> <strong>A:7</strong> N:240',
    'both counts bold when both > 0, and N: stays plain even at a large value');
  assert.ok(!/<strong>N:/.test(cell), 'N: must never be wrapped in <strong>');
  console.log('OK both counts bold; N:240 never bold');
}

// 5. Error rows short-circuit to an em-dash before any count is formatted.
function testErrorRowUnchanged() {
  const cell = cellFor({ status_class: 'error', status_label: 'Error', safe: 0, aggressive: 0, needed: 0 });
  assert.strictEqual(cell, '—', 'error rows must still render a bare em-dash');
  console.log('OK error row still renders "—"');
}

testAggressiveNonZeroIsBold();
testSafeNonZeroIsBold();
testZeroCountsAreNotBold();
testNeededIsNeverBold();
testErrorRowUnchanged();
console.log('san-bold-nonzero: all assertions passed');
