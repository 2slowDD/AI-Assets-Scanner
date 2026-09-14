// Step-4 results row: the "Needs Extra Time" note must NOT render on a page that just had
// Extra Time in this scan (operator 2026-09-14). The server stamps each row's et_requested
// from the submit-time ET URL set (ScannerAjax::do_build_result); a zero-yield ET continuation
// legitimately omits extra_time_charged, so et_charged alone kept recommending another ET pass.
//
// Renders the REAL admin/js/scanner.js through restoreStep4 -> renderResultUrlList (the same
// harness as kept-protection-chip.test.js) and asserts BOTH directions, plus the untouched
// billed-ET branch, ET candidate cell and Extra Time checkbox.
//
// The expected markup below is an INDEPENDENT copy of what scanner.js builds — deliberately
// not read out of the source, so a change to the code under test can fail this file.
const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');

const ZERO_SAN = '<span class="cu-san-token cu-san-safe">S:0</span> '
  + '<span class="cu-san-token cu-san-aggressive">A:0</span> '
  + '<span class="cu-san-token cu-san-needed">N:5</span>';
const NOOPT_ET = ' <span class="cu-noopt-note cu-noopt-et">\u{23F3} Needs Extra Time —<br>rescan with \u{201C}Rescan ET Candidates\u{201D}</span>';
const SCAN_AGAIN = ' <span class="cu-noopt-note">Please scan again</span>';

function render(pages) {
  const h = createHarness();
  h.sandbox.window.__cuTest.restoreStep4({
    jobId: 'job1', safeCount: 0, aggCount: 0, canPush: true, externalOnly: false,
    bannerData: {}, urlsScanned: pages.length, pages: pages, scanId: 'scan1',
    hasActiveCuRules: false,
  });
  return h.els['cu-result-url-list']._html;
}

// One body row's full HTML, by row order. Header rows carry no class attribute, body rows
// always do (cu-row-<status>), so this never matches the thead.
function rowHtml(tableHtml, rowIndex) {
  const re = /<tr class="[^"]*">([\s\S]*?)<\/tr>/g;
  let m, i = 0;
  while ((m = re.exec(tableHtml)) !== null) {
    if (i === rowIndex) return m[1];
    i++;
  }
  throw new Error('row ' + rowIndex + ' not found');
}
function sanCellHtml(tableHtml, rowIndex) {
  const m = /<td class="cu-san">([\s\S]*?)<\/td>/.exec(rowHtml(tableHtml, rowIndex));
  if (!m) throw new Error('cu-san cell not found in row ' + rowIndex);
  return m[1];
}

// A zero-rule OK ET-candidate row. `extra` is merged LAST, so passing a key overrides the
// default and omitting et_requested leaves the key absent (the legacy stored-result shape).
function etRow(n, url, extra) {
  return Object.assign({
    n: n, url: url, status_class: 'ok', status_label: 'OK',
    credits: 1, safe: 0, aggressive: 0, needed: 5, et_candidate: true, et_charged: false,
  }, extra || {});
}

// (c) et_requested:true -> no note at all; ET candidate cell and Extra Time checkbox intact.
function runRequestedRowHasNoNote() {
  const html = render([ etRow(1, 'https://example.test/et', { et_requested: true }) ]);
  const row = rowHtml(html, 0);
  assert.strictEqual(sanCellHtml(html, 0), ZERO_SAN,
    'an et_requested row renders the S/A/N tokens and NO note in its place');
  assert.strictEqual(row.indexOf('cu-noopt-et'), -1, 'no cu-noopt-et element on an et_requested row');
  assert.strictEqual(row.indexOf('Needs Extra Time'), -1, 'no "Needs Extra Time" text on an et_requested row');
  assert.ok(row.indexOf('<td>yes</td>') !== -1, 'the ET candidate cell still reads "yes"');
  assert.ok(/<input type="checkbox" class="cu-et-result-cb" data-url="https:\/\/example\.test\/et"[^>]*>/.test(row),
    'the per-row Extra Time checkbox still renders');
  console.log('OK et_requested row: no Needs Extra Time note; ET candidate "yes" + checkbox intact');
}

// (d) et_requested:false AND the key absent -> the note IS present, byte for byte.
function runUnrequestedRowKeepsNote() {
  const html = render([
    etRow(1, 'https://example.test/f', { et_requested: false }),
    etRow(2, 'https://example.test/legacy'),
  ]);
  assert.ok(!('et_requested' in etRow(2, 'x')), 'fixture sanity: the legacy row carries no et_requested key');
  assert.strictEqual(sanCellHtml(html, 0), ZERO_SAN + NOOPT_ET, 'et_requested:false keeps the Needs Extra Time note');
  assert.strictEqual(sanCellHtml(html, 1), ZERO_SAN + NOOPT_ET, 'a legacy row (key absent) keeps the note exactly as today');
  console.log('OK et_requested false / absent: Needs Extra Time note present');
}

// (e) the billed-ET branch is unchanged: et_charged:true still says "Please scan again",
// even when et_requested is also true.
function runChargedBranchUnchanged() {
  const html = render([ etRow(1, 'https://example.test/c', { et_charged: true, et_requested: true }) ]);
  assert.strictEqual(sanCellHtml(html, 0), ZERO_SAN + SCAN_AGAIN,
    'et_charged + et_requested still renders "Please scan again"');
  console.log('OK et_charged branch unchanged ("Please scan again")');
}

// Discrimination: a gate that dropped the note on EVERY row would pass (c); one that never
// dropped it would pass (d). A mixed table pins that the flag is read per row.
function runMixedTable() {
  const html = render([
    etRow(1, 'https://example.test/1', { et_requested: false }),
    etRow(2, 'https://example.test/2', { et_requested: true }),
    etRow(3, 'https://example.test/3'),
  ]);
  const got = [0, 1, 2].map(function (i) { return sanCellHtml(html, i).indexOf('cu-noopt-et') !== -1; });
  assert.deepStrictEqual(got, [true, false, true], 'the note is dropped on exactly the et_requested row');
  console.log('OK mixed table: note dropped only on the et_requested row');
}

runRequestedRowHasNoNote();
runUnrequestedRowKeepsNote();
runChargedBranchUnchanged();
runMixedTable();
