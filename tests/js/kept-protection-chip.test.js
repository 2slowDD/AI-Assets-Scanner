// A2c — per-row "kept protection" chip in the Step-4 results table. Renders the REAL
// admin/js/scanner.js through restoreStep4 -> renderResultUrlList (no jest in this repo;
// see r3-stage-c-harness.js) and asserts BOTH directions: the chip appears EXACTLY on rows
// whose kept_protection is a non-empty array, and on no other row shape.
//
// IMPORTANT: chipHtml below is an INDEPENDENT copy of the markup scanner.js builds. It is
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
// FU-KEPT-BADGE-HOVER-INFO — the chip carries its OWN slice index as data-cu-row, so the
// post-render tooltip pass can find its row without re-deriving the chip predicate (two
// predicates that must agree is the defect class kept_count exists to close). The index is
// a map() loop counter — digits by construction, static-safe for the R19 innerHTML sink.
const chipHtml = (i) => ' <span class="cu-kept-chip" data-cu-row="' + i + '">\u{1F6E1} kept</span>';

// The two noopt S/A/N-cell notes, verbatim from scanner.js. A2c must not touch this cell:
// the chip lives in the URL cell, these notes live in the S/A/N cell.
const NOOPT_PLAIN = ' <span class="cu-noopt-note">No unloads found. A rescan may find more.</span>';
// FU-NOOPT-NOTE-CONFLATION — the ET note carries a \u{23F3} prefix (plus CSS badge
// styling) so it cannot be misread as one of the four plain informational noopt notes:
// A customer read a plain "Please scan again" as a second ET candidate and reported a phantom
// "lost rescan candidate". The glyph is part of the pinned markup on purpose — dropping it
// silently would re-open the conflation.
const NOOPT_ET = ' <span class="cu-noopt-note cu-noopt-et">\u{23F3} Needs Extra Time —<br>rescan with \u{201C}Rescan ET Candidates\u{201D}</span>';
const ZERO_SAN = '<span class="cu-san-token cu-san-safe">S:0</span> '
  + '<span class="cu-san-token cu-san-aggressive">A:0</span> '
  + '<span class="cu-san-token cu-san-needed">N:5</span>';

function escHtml(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
function expectedUrlCell(url, meta) {
  return '<span class="cu-url-primary">' + escHtml(url) + '</span>'
       + (meta ? '<span class="cu-url-meta">' + meta + '</span>' : '');
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
  assert.strictEqual(cell, expectedUrlCell('https://example.test/a', chipHtml(0)),
    'URL cell of a kept-protection row must be the URL followed by exactly the chip');
  console.log('OK chip renders on a non-empty kept_protection row');
}

// The chip's TEXT, not just its class — a chip whose label was emptied or reworded still
// carries class="cu-kept-chip" and would sail past a class-only assertion.
function runChipTextPinned() {
  const html = render([ makeRow(1, 'https://example.test/a', [ ENTRY, ENTRY ]) ]);
  const m = /<span class="cu-kept-chip" data-cu-row="\d+">([\s\S]*?)<\/span>/.exec(html);
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
    assert.strictEqual(cell, expectedUrlCell('https://example.test/a'),
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
  assert.strictEqual(urlCellHtml(html, 0), expectedUrlCell('https://example.test/c'),
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
  assert.strictEqual(cell, expectedUrlCell('https://example.test/a', chipHtml(0)),
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
  assert.strictEqual(sanCellHtml(html, 0), ZERO_SAN + NOOPT_PLAIN,
    'plain noopt S/A/N cell must render byte-identically');
  assert.strictEqual(sanCellHtml(html, 1), ZERO_SAN + NOOPT_ET,
    'ET noopt S/A/N cell must render byte-identically');
  // The load-bearing one: a noopt row that ALSO kept a protection script gets the chip in
  // the URL cell and an untouched S/A/N cell.
  assert.strictEqual(sanCellHtml(html, 2), ZERO_SAN + NOOPT_PLAIN,
    'a noopt row carrying kept_protection must leave the S/A/N cell untouched');
  assert.strictEqual(urlCellHtml(html, 2), expectedUrlCell('https://example.test/n3', chipHtml(2)),
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
  assert.ok(cell.startsWith('<span class="cu-url-primary">' + escHtml('https://example.test/a') + '</span>'),
    'the primary URL line still reads first');
  console.log('OK chip sits in the inline run, before the block-level choff note');
}

// ---------------------------------------------------------------------------------------
// R20 — the chip carries THAT ROW'S count and covers non-protection keeps.
//
// Every fixture above deliberately omits kept_count: those are pre-R20 rows, and their
// continued green is the back-compat pin. A user who updates the plugin has their last scan
// in localStorage without the key, and the chip must degrade to the countless form rather
// than disappear.
// ---------------------------------------------------------------------------------------
function runR20CountChip() {
  // A row with a kept_count renders it. `n kept`, not the bare `kept`.
  const withCount = function (count, keep) {
    const row = makeRow(1, 'https://example.test/a', keep === undefined ? [] : keep);
    row.kept_count = count;
    return row;
  };

  const m = /<span class="cu-kept-chip" data-cu-row="\d+">([\s\S]*?)<\/span>/.exec(render([ withCount(3) ]));
  assert.ok(m, 'a counted row still renders a chip');
  assert.strictEqual(m[1], '\u{1F6E1} 3 kept', 'chip text carries that row\'s own count');

  // AC-10 AT THE RENDER LAYER — the whole point of R20. kept_protection is EMPTY here: every
  // keep on this page is non-protection (Fathom, Stripe, wp-core). Before R20 this row showed
  // no chip at all while the scan note counted its keeps.
  const nonProtection = render([ withCount(4, []) ]);
  assert.ok(/cu-kept-chip/.test(nonProtection),
    'a page whose keeps are entirely non-protection must still show a chip');
  assert.strictEqual(
    /<span class="cu-kept-chip" data-cu-row="\d+">([\s\S]*?)<\/span>/.exec(nonProtection)[1], '\u{1F6E1} 4 kept');

  // Zero and junk both mean "no chip" — never a chip reading "0 kept" or "NaN kept". The
  // count reaches an HTML sink, so a non-numeric payload must fail the gate, not stringify.
  [0, -1, 'abc', null, {}, [], '3; alert(1)'].forEach(function (bad) {
    const html = render([ withCount(bad) ]);
    assert.strictEqual((html.match(/cu-kept-chip/g) || []).length, 0,
      'kept_count ' + JSON.stringify(bad) + ' must render no chip');
  });

  // A numeric string is still a number after coercion, and only digits can reach the markup.
  assert.strictEqual(
    /<span class="cu-kept-chip" data-cu-row="\d+">([\s\S]*?)<\/span>/.exec(render([ withCount('7') ]))[1],
    '\u{1F6E1} 7 kept', 'a numeric string coerces to its number');

  console.log('OK R20 chip carries the row count and covers non-protection keeps');
}

// ---------------------------------------------------------------------------------------
// FU-KEPT-BADGE-HOVER-INFO — the chip's hover tooltip names THIS ROW's kept assets from
// p.kept_breakdown (producer-derived in AIAS_Scan_Status::build_pages(), same composite
// unit as kept_count). R19 line held: labels are worker strings and reach the DOM ONLY via
// the title PROPERTY (post-render pass), which never parses as HTML — a hostile label must
// land in .title verbatim-inert and never in the markup.
// ---------------------------------------------------------------------------------------
function renderH(pages) {
  const h = createHarness();
  h.sandbox.window.__cuTest.restoreStep4({
    jobId: 'job1', safeCount: 1, aggCount: 0, canPush: true, externalOnly: false,
    bannerData: {}, urlsScanned: pages.length, pages: pages, scanId: 'scan1',
    hasActiveCuRules: false,
  });
  return h.els['cu-result-url-list'];
}

function runKeptTooltip() {
  const rowWith = function (n, url, breakdown, count) {
    const row = makeRow(n, url, [ ENTRY ]);
    if (breakdown !== undefined) row.kept_breakdown = breakdown;
    if (count !== undefined) row.kept_count = count;
    return row;
  };

  // Named breakdown → title lists labels in producer order; count > 1 parenthesized,
  // count 1 bare — mirroring the scan-level note's convention.
  const host = renderH([
    rowWith(1, 'https://example.test/1',
      [ { label: 'Cloudflare Turnstile', count: 1 }, { label: 'Gravity Forms', count: 2 } ], 3),
    rowWith(2, 'https://example.test/2'),                                   // legacy: no breakdown key
    rowWith(3, 'https://example.test/3', [], 1),                            // empty breakdown
  ]);
  const chips = host.querySelectorAll('.cu-kept-chip');
  assert.strictEqual(chips.length, 3, 'all three rows render a chip');
  assert.strictEqual(chips[0].title,
    'Kept on this page — never unloaded: Cloudflare Turnstile, Gravity Forms (2)',
    'tooltip names the row\'s kept assets with composite counts');
  assert.ok(!chips[1].title, 'a legacy row without kept_breakdown gets no tooltip');
  assert.ok(!chips[2].title, 'an empty breakdown gets no tooltip');

  // The R19 line, extended to the tooltip: hostile labels are INERT in the title property
  // and NEVER reach the markup.
  const hostileLabel = '"><img src=x onerror=alert(9)>';
  const host2 = renderH([ rowWith(1, 'https://example.test/h',
    [ { label: hostileLabel, count: 1 } ], 1) ]);
  const chip2 = host2.querySelector('.cu-kept-chip');
  assert.ok(chip2.title.indexOf(hostileLabel) !== -1,
    'a hostile label lands in the title property verbatim (inert text, never parsed)');
  assert.strictEqual(host2._html.indexOf('onerror'), -1,
    'no kept_breakdown-derived string may reach the markup');
  assert.strictEqual(host2._html.indexOf('alert(9)'), -1,
    'no kept_breakdown-derived string may reach the markup');

  // Junk shapes: no throw, no tooltip, filtered per-row like every other worker field.
  const host3 = renderH([
    rowWith(1, 'https://example.test/j1', 'junk', 1),
    rowWith(2, 'https://example.test/j2',
      [ 'junk', null, { label: '', count: 2 }, { label: 'Real', count: 0 }, { label: 'Kept One', count: 1 } ], 1),
  ]);
  const chips3 = host3.querySelectorAll('.cu-kept-chip');
  assert.ok(!chips3[0].title, 'a non-array breakdown gets no tooltip');
  assert.strictEqual(chips3[1].title, 'Kept on this page — never unloaded: Kept One',
    'junk entries, empty labels and zero counts are filtered; valid rows survive');

  console.log('OK kept-chip tooltip: named rows, legacy/empty/junk silent, hostile label inert');
}

runChipPresent();
runChipTextPinned();
runChipAbsentVariants();
runLegacyRowGuard();
runMixedTableDiscrimination();
runPayloadIsStaticOnly();
runNooptShapeUndisturbed();
runChipOrderingAgainstNotes();
runR20CountChip();
runKeptTooltip();
