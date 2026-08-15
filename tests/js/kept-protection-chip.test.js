// A2c — per-row "kept protection" chip in the Step-4 results table. Renders the REAL
// admin/js/scanner.js through restoreStep4 -> renderResultUrlList (no jest in this repo;
// see r3-stage-c-harness.js) and asserts BOTH directions: the chip appears EXACTLY on rows
// whose kept_protection is a non-empty array, and on no other row shape.
//
// IMPORTANT: CHIP_HTML below is an INDEPENDENT copy of the markup scanner.js builds. It is
// deliberately NOT imported from the source — a test that reads its expectation out of the
// code under test cannot fail when that code changes. Keep the two in sync by hand.
//
// Ruling R19 (A2c only): this region is already an escaped-string -> innerHTML pipeline, so
// the chip is concatenated into it rather than appended as a DOM node. The safety therefore
// comes from the PAYLOAD, not the sink — runPayloadIsStaticOnly() below is the test that
// holds that line: no server-supplied string may ever reach the chip markup.
const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');

// The chip, verbatim. U+1F6E1 with NO variation selector — matches the bare-codepoint
// convention already shipping in this file (👁 at scanner.js's choff note).
const CHIP_HTML = ' <span class="cu-kept-chip">\u{1F6E1} kept</span>';

// The two noopt S/A/N-cell notes, verbatim from scanner.js. A2c must not touch this cell:
// the chip lives in the URL cell, these notes live in the S/A/N cell.
const NOOPT_PLAIN = ' <span class="cu-noopt-note">This scan found nothing to unload —<br>a rescan occasionally finds more. Please rescan.</span>';
const NOOPT_ET = ' <span class="cu-noopt-note cu-noopt-et">Needs Extra Time —<br>rescan with \u{201C}Rescan ET Candidates\u{201D}</span>';

function escHtml(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

// Pulls the raw HTML of one row's <td class="..."> out of the rendered table, by row order
// (rows render in st.pages order — see renderResultUrlListPage). Non-greedy up to the first
// following </td> is safe: neither cell's content contains a literal "</td>".
function cellHtml(tableHtml, cls, rowIndex) {
  const re = new RegExp('<td class="' + cls + '">([\\s\\S]*?)<\\/td>', 'g');
  let m, i = 0;
  while ((m = re.exec(tableHtml)) !== null) {
    if (i === rowIndex) return m[1];
    i++;
  }
  throw new Error(cls + ' row ' + rowIndex + ' not found');
}
const urlCellHtml = (html, i) => cellHtml(html, 'cu-url-cell', i);
const sanCellHtml = (html, i) => cellHtml(html, 'cu-san', i);

function render(pages) {
  const h = createHarness();
  h.sandbox.window.__cuTest.restoreStep4({
    jobId: 'job1', safeCount: 1, aggCount: 0, canPush: true, externalOnly: false,
    bannerData: {}, urlsScanned: pages.length, pages: pages, scanId: 'scan1',
    hasActiveCuRules: false,
  });
  return h.els['cu-result-url-list']._html;
}

// A plain OK row that produced rules, so it never takes the noopt branch. `keep` is applied
// ONLY when explicitly passed, so the "key absent entirely" (legacy row) case is reachable.
function makeRow(n, url, keep) {
  const row = {
    n: n, url: url, status_class: 'ok', status_label: 'OK',
    credits: 1, safe: 1, aggressive: 0, needed: 0, et_candidate: false,
  };
  if (arguments.length > 2) row.kept_protection = keep;
  return row;
}

const ENTRY = { display_name: 'Cloudflare', handles: ['cf-challenge|script'] };

// ---------------------------------------------------------------------------------------
// Direction 1 — the chip IS there, in the right cell, with the right text.
// ---------------------------------------------------------------------------------------
function runChipPresent() {
  const html = render([ makeRow(1, 'https://example.test/a', [ ENTRY ]) ]);
  const cell = urlCellHtml(html, 0);
  assert.strictEqual(cell, escHtml('https://example.test/a') + CHIP_HTML,
    'URL cell of a kept-protection row must be the URL followed by exactly the chip');
  console.log('OK chip renders on a non-empty kept_protection row');
}

// The chip's TEXT, not just its class — a chip whose label was emptied or reworded still
// carries class="cu-kept-chip" and would sail past a class-only assertion.
function runChipTextPinned() {
  const html = render([ makeRow(1, 'https://example.test/a', [ ENTRY, ENTRY ]) ]);
  const m = /<span class="cu-kept-chip">([\s\S]*?)<\/span>/.exec(html);
  assert.ok(m, 'a cu-kept-chip span must be present');
  assert.strictEqual(m[1], '\u{1F6E1} kept', 'chip text must be the shield glyph followed by "kept"');
  // The array is read for LENGTH only: two entries must not produce two chips or a count.
  assert.strictEqual((html.match(/cu-kept-chip/g) || []).length, 1,
    'a row with two kept entries still renders exactly one chip');
  console.log('OK chip text is pinned and the array is read for length only');
}

// ---------------------------------------------------------------------------------------
// Direction 2 — the chip is NOT there, on every other row shape.
// ---------------------------------------------------------------------------------------
function runChipAbsentVariants() {
  const cases = [
    ['empty array', []],
    ['null', null],
    ['non-array string', 'Cloudflare'],
    ['non-array number', 3],
    ['non-array object', { count: 2 }],
    ['array-like object', { length: 2, 0: ENTRY }],
    ['boolean true', true],
  ];
  cases.forEach(function (c) {
    const html = render([ makeRow(1, 'https://example.test/a', c[1]) ]);
    const cell = urlCellHtml(html, 0);
    assert.strictEqual(cell, escHtml('https://example.test/a'),
      'kept_protection = ' + c[0] + ' must render the URL cell with no chip at all');
  });
  console.log('OK no chip on []/null/string/number/object/array-like/boolean (' + cases.length + ' shapes)');
}

// A row restored from pre-A2c storage (aias_last_result / localStorage cu_scanner_result)
// carries NO kept_protection key at all — not even an empty array. Must not throw.
function runLegacyRowGuard() {
  let html;
  assert.doesNotThrow(function () { html = render([ makeRow(1, 'https://example.test/c') ]); },
    'legacy row (no kept_protection key) must not throw');
  assert.strictEqual(urlCellHtml(html, 0), escHtml('https://example.test/c'),
    'legacy row must render with no chip');
  console.log('OK legacy row (key absent entirely) renders with no chip and no throw');
}

// ---------------------------------------------------------------------------------------
// The discriminating test — a chip that renders on EVERY row passes both tests above.
// ---------------------------------------------------------------------------------------
function runMixedTableDiscrimination() {
  const pages = [
    makeRow(1, 'https://example.test/1', [ ENTRY ]),   // chip
    makeRow(2, 'https://example.test/2', []),          // no chip
    makeRow(3, 'https://example.test/3'),              // no chip (key absent)
    makeRow(4, 'https://example.test/4', 'junk'),      // no chip
    makeRow(5, 'https://example.test/5', [ ENTRY ]),   // chip
  ];
  const html = render(pages);
  const got = pages.map(function (_, i) { return urlCellHtml(html, i).indexOf('cu-kept-chip') !== -1; });
  assert.deepStrictEqual(got, [true, false, false, false, true],
    'the chip must appear on exactly the non-empty-array rows and no others');
  assert.strictEqual((html.match(/cu-kept-chip/g) || []).length, 2, 'exactly two chips in the table');
  console.log('OK mixed table: chip on exactly the non-empty-array rows');
}

// ---------------------------------------------------------------------------------------
// Ruling R19 — the safety is in the PAYLOAD. Nothing off kept_protection is interpolated.
// ---------------------------------------------------------------------------------------
function runPayloadIsStaticOnly() {
  const hostile = [
    { display_name: '<img src=x onerror=alert(1)>', handles: ['</span><script>alert(2)</script>|script'] },
    { display_name: '"><svg onload=alert(3)>', handles: ['&amp;<b>|style'] },
  ];
  const html = render([ makeRow(1, 'https://example.test/a', hostile) ]);
  const cell = urlCellHtml(html, 0);
  assert.strictEqual(cell, escHtml('https://example.test/a') + CHIP_HTML,
    'a hostile kept_protection payload must render the SAME static chip, byte for byte');
  ['onerror', 'onload', '<script', '<img', '<svg', 'alert(', 'Cloudflare'].forEach(function (needle) {
    assert.strictEqual(html.indexOf(needle), -1,
      'no kept_protection-derived string may reach the markup — found "' + needle + '"');
  });
  console.log('OK hostile payload never reaches the chip markup (static text only)');
}

// ---------------------------------------------------------------------------------------
// The 0-result (noopt) row shape must be undisturbed — the chip lives in the URL cell, the
// noopt notes live in the S/A/N cell, and A2c must not touch the latter.
// ---------------------------------------------------------------------------------------
function runNooptShapeUndisturbed() {
  const noopt = function (n, url, etCandidate, keep) {
    const row = {
      n: n, url: url, status_class: 'ok', status_label: 'OK',
      credits: 0, safe: 0, aggressive: 0, needed: 5, et_candidate: etCandidate, et_charged: false,
    };
    if (arguments.length > 3) row.kept_protection = keep;
    return row;
  };
  const pages = [
    noopt(1, 'https://example.test/n1', false),               // plain noopt, no kept
    noopt(2, 'https://example.test/n2', true),                // ET noopt, no kept
    noopt(3, 'https://example.test/n3', false, [ ENTRY ]),    // plain noopt WITH kept
  ];
  const html = render(pages);
  assert.strictEqual(sanCellHtml(html, 0), 'S:0 A:0 N:5' + NOOPT_PLAIN,
    'plain noopt S/A/N cell must render byte-identically');
  assert.strictEqual(sanCellHtml(html, 1), 'S:0 A:0 N:5' + NOOPT_ET,
    'ET noopt S/A/N cell must render byte-identically');
  // The load-bearing one: a noopt row that ALSO kept a protection script gets the chip in
  // the URL cell and an untouched S/A/N cell.
  assert.strictEqual(sanCellHtml(html, 2), 'S:0 A:0 N:5' + NOOPT_PLAIN,
    'a noopt row carrying kept_protection must leave the S/A/N cell untouched');
  assert.strictEqual(urlCellHtml(html, 2), escHtml('https://example.test/n3') + CHIP_HTML,
    'the chip goes in the URL cell, on a noopt row too');
  assert.strictEqual(urlCellHtml(html, 0).indexOf('cu-kept-chip'), -1, 'no chip on a noopt row without kept');
  // The noopt row class is what colours the row; the chip must not disturb it.
  assert.ok(/<tr class="cu-row-ok cu-row-noopt">/.test(html), 'noopt row class survives');
  console.log('OK noopt row shape undisturbed (both notes + row class), chip lands in the URL cell');
}

// ---------------------------------------------------------------------------------------
// Placement — the chip must stay in the INLINE run, before the block-level choff note.
// .cu-choff-note is display:block (admin css), so a chip concatenated after it would be
// orphaned onto its own line instead of sitting after the URL text (Step 2's requirement).
// ---------------------------------------------------------------------------------------
function runChipOrderingAgainstNotes() {
  const row = makeRow(1, 'https://example.test/a', [ ENTRY ]);
  row.bypass_suffixes = ['nowprocket'];
  row.visual_channel_off = ['mobile'];
  const cell = urlCellHtml(render([ row ]), 0);
  const iBypass = cell.indexOf('cu-bypass-note');
  const iChip = cell.indexOf('cu-kept-chip');
  const iChoff = cell.indexOf('cu-choff-note');
  assert.ok(iBypass !== -1 && iChip !== -1 && iChoff !== -1, 'all three annotations render together');
  assert.ok(iBypass < iChip, 'the chip follows the inline bypass note');
  assert.ok(iChip < iChoff, 'the chip precedes the BLOCK-level choff note, so it is not orphaned onto its own line');
  assert.ok(cell.startsWith(escHtml('https://example.test/a')), 'the URL still reads first');
  console.log('OK chip sits in the inline run, before the block-level choff note');
}

runChipPresent();
runChipTextPinned();
runChipAbsentVariants();
runLegacyRowGuard();
runMixedTableDiscrimination();
runPayloadIsStaticOnly();
runNooptShapeUndisturbed();
runChipOrderingAgainstNotes();
