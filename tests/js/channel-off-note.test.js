// FU-VFM-MASKING AC-M7b (JS side) / AC-M8 — the channel-off URL-cell note + dual-sink
// tooltip. Renders the REAL admin/js/scanner.js through restoreStep4 -> renderResultUrlList
// (no jest in this repo; see r3-stage-c-harness.js) and asserts the six normative strings
// from spec §3.2.2/§3.2.3 (2026-07-31-fu-vfm-masking-channel-off-surfacing-design.md) plus
// the legacy-row no-throw guard (AC-M7b).
//
// IMPORTANT: the CU_CHOFF_ARIA / CU_CHOFF_BOX_BODY consts below are an INDEPENDENT copy of
// scanner.js's own consts of the same name (admin/js/scanner.js, beside cuEscHtml) — both
// copies must be kept in sync with spec §3.2.3 verbatim if either changes, so this test
// actually proves the rendered output against the spec text, not just against itself.
const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');

// The two normative strings (spec §3.2.3), %s = the <devices> token, substituted exactly
// as scanner.js does: split('%s').join(list).
const CU_CHOFF_ARIA = "Visual comparison off (%s): this page's normal variation between visits is larger than any visual difference the scanner could detect, so the visual check was switched off for this page on %s during this scan. Other checks (code coverage, console, network) still decide here. Deliberate, evidence-based — not an error.";
const CU_CHOFF_BOX_BODY = "this page's own normal variation between visits is measured to be larger than any visual difference the scanner could detect, so the visual check was switched off for this page on %s during this scan. Unload decisions here are made by the scanner's other checks (code coverage, console, network). This is a deliberate, evidence-based state — not an error.";

// Mirrors scanner.js's esc() (attribute-safe) — kept local so this test does not depend on
// esc() being exported.
function esc(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}
// cuEscHtml equivalent for plain device-list text (no & < > " ' in "mobile" / "desktop &
// mobile" except the literal " & " join separator, which must itself come through escaped
// since cuEscHtml round-trips via textContent -> innerHTML).
function escHtml(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function expectedAria(list) {
  return esc(CU_CHOFF_ARIA.split('%s').join(list));
}
function expectedHelpBox(list) {
  return '<span class="cu-help" tabindex="0" aria-label="' + expectedAria(list) + '">'
       + '<span class="cu-help-box"><strong>Visual comparison off (' + escHtml(list) + '):</strong> '
       + CU_CHOFF_BOX_BODY.split('%s').join(escHtml(list)) + '</span></span>';
}
function expectedNote(list) {
  return ' <span class="cu-choff-note">\u{1F441} visual comparison off — ' + escHtml(list) + expectedHelpBox(list) + '</span>';
}
function expectedUrlCell(url, meta) {
  return '<span class="cu-url-primary">' + escHtml(url) + '</span>'
       + (meta ? '<span class="cu-url-meta">' + meta + '</span>' : '');
}

// Pulls the raw HTML of a single row's <td class="cu-url-cell">...</td> out of the
// rendered table by URL cell order (rows render in st.pages order — see
// renderResultUrlListPage). Non-greedy match up to the first following </td> is safe: the
// url-cell content itself never contains a literal "</td>".
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
  const T = h.sandbox.window.__cuTest;
  T.restoreStep4({ jobId: 'job1', safeCount: 1, aggCount: 0, canPush: true, externalOnly: false,
                   bannerData: {}, urlsScanned: pages.length, pages: pages, scanId: 'scan1', hasActiveCuRules: false });
  return h.els['cu-result-url-list']._html;
}

function makeRow(n, url, choffOverride) {
  const row = {
    n: n, url: url, status_class: 'ok', status_label: 'OK',
    credits: 1, safe: 1, aggressive: 0, needed: 0, et_candidate: false,
  };
  if (choffOverride !== undefined) row.visual_channel_off = choffOverride;
  return row;
}

function runSingleDevice() {
  const pages = [ makeRow(1, 'https://example.test/a', ['mobile']) ];
  const html = render(pages);
  const cell = urlCellHtml(html, 0);
  const want = expectedUrlCell('https://example.test/a', expectedNote('mobile'));
  assert.strictEqual(cell, want, 'single-device (mobile) URL-cell HTML must match spec §3.2.2/§3.2.3 exactly');
  console.log('OK note+aria+help-box (mobile)');
}

function runDualDevice() {
  const pages = [ makeRow(1, 'https://example.test/b', ['desktop', 'mobile']) ];
  const html = render(pages);
  const cell = urlCellHtml(html, 0);
  const want = expectedUrlCell('https://example.test/b', expectedNote('desktop & mobile'));
  assert.strictEqual(cell, want, 'dual-device (desktop & mobile) URL-cell HTML must match spec §3.2.2/§3.2.3 exactly');
  console.log('OK note+aria+help-box (desktop & mobile)');
}

// AC-M7b (JS side) — a row restored from pre-release storage carries NO visual_channel_off
// key at all (not even an empty array). Must render with no throw and no note.
function runLegacyRowGuard() {
  const pages = [ makeRow(1, 'https://example.test/c') ]; // no visual_channel_off key
  let html;
  assert.doesNotThrow(function () { html = render(pages); }, 'legacy row (no visual_channel_off key) must not throw');
  const cell = urlCellHtml(html, 0);
  assert.strictEqual(cell, expectedUrlCell('https://example.test/c'), 'legacy row must render with no choff note');
  assert.ok(!/cu-choff-note/.test(cell), 'legacy row must not contain a cu-choff-note span');
  console.log('OK legacy-row guard (no throw, no note)');
}

runSingleDevice();
runDualDevice();
runLegacyRowGuard();
