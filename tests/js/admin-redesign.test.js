// Admin redesign contract: exercise the shipped Step-4 renderer with three real outcome
// shapes. This catches dashboard metrics that drift from the row payload and presentation
// regressions that collapse OK / partial / error or S / A / N into indistinguishable output.
const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');

const h = createHarness({
  querySelectorAll: (selector, els) => selector === '#step-4 .cu-btn-rescan-noopt-all'
    ? [els['cu-rescan-noopt-button']]
    : [],
});
const T = h.sandbox.window.__cuTest;
h.els['cu-rescan-noopt-button'] = h.els['cu-rescan-noopt-button'] || { style: { display: 'none' } };

const pages = [
  { n: 1, url: 'https://example.test/ok?nowprocket', status_class: 'ok', status_label: 'OK',
    credits: 1, safe: 3, aggressive: 2, needed: 8, kept_count: 2 },
  { n: 2, url: 'https://example.test/partial', status_class: 'partial', status_label: 'Partial',
    credits: 2, safe: 1, aggressive: 5, needed: 4, kept_count: 1 },
  { n: 3, url: 'https://example.test/error', status_class: 'error', status_label: 'Error',
    credits: 0, safe: 0, aggressive: 0, needed: 0, kept_count: 0 },
];

T.restoreStep4({
  jobId: 'job-redesign', safeCount: 4, aggCount: 7, canPush: true, externalOnly: false,
  bannerData: {}, urlsScanned: 3, pages: pages, scanId: 'scan-redesign',
  hasActiveCuRules: false, keptProtectionSummary: { count: 3, labels: [] }, availableBalance: 52
});

assert.strictEqual(h.els['cu-metric-urls'].textContent, '3', 'URLs metric uses completed result data');
assert.strictEqual(h.els['cu-metric-safe'].textContent, '4', 'Safe metric uses completed result data');
assert.strictEqual(h.els['cu-metric-aggressive'].textContent, '7', 'Aggressive metric uses completed result data');
assert.strictEqual(h.els['cu-metric-credits'].textContent, '3', 'Credits metric sums net displayed row credits');
assert.strictEqual(h.els['cu-metric-balance'].textContent, '49', 'Credit balance subtracts the displayed scan usage');
assert.strictEqual(h.els['cu-complete-scan-id'].textContent, 'Scan ID: scan-redesign');
assert.strictEqual(h.els['cu-results-success-count'].textContent, '1 of 3 successful', 'Page results title carries the live success count');
assert.strictEqual(h.els['cu-ready-rule-total'].textContent, '11', 'sidebar totals every generated recommendation');
assert.strictEqual(h.els['cu-ready-credits'].textContent, '3 credits were used', 'sidebar uses the displayed net credit total');
assert.strictEqual(h.els['cu-cu-status-title'].textContent, 'Code Unloader is active', 'internal results show the integration state');
assert.strictEqual(h.els['cu-next-step-title'].textContent, 'Choose Sync or Push', 'internal results name the available workflow');
assert.strictEqual(h.els['cu-kept-assets-summary'].textContent, '3 crucial assets kept', 'kept strip uses a compact count-first summary');
assert.strictEqual(h.els['cu-kept-assets-panel'].hidden, false, 'kept strip is revealed for non-zero kept assets');

const html = h.els['cu-result-url-list'].innerHTML;
assert.ok(/cu-row-ok/.test(html), 'successful outcome keeps its status class');
assert.ok(/cu-row-partial/.test(html), 'partial outcome keeps its status class');
assert.ok(/cu-row-error/.test(html), 'error outcome keeps its status class');
assert.ok(/cu-san-safe/.test(html), 'Safe renders as its own semantic badge');
assert.ok(/cu-san-aggressive/.test(html), 'Aggressive renders as its own positive semantic badge');
assert.ok(/cu-san-needed/.test(html), 'Needed renders as its own neutral semantic badge');
assert.ok(/cu-san-safe is-positive"><strong>S:3<\/strong>/.test(html), 'non-zero safe recommendations retain strong emphasis');
assert.ok(/cu-san-aggressive is-positive"><strong>A:2<\/strong>/.test(html), 'non-zero aggressive recommendations retain strong emphasis');
assert.ok(/Recommendations S \/ A \/ N/.test(html), 'table owns the full recommendation heading');
assert.ok(/cu-url-primary/.test(html), 'URL gets a dedicated primary line');
assert.ok(/cu-url-meta/.test(html), 'scanner notes and kept details get a second URL line');
assert.ok(!/zero usage/i.test(html), 'Aggressive copy never uses the rejected zero-usage definition');

T.restoreStep4({
  jobId: 'job-zero-result', safeCount: 0, aggCount: 0, canPush: false, externalOnly: false,
  bannerData: {}, urlsScanned: 1,
  pages: [{ n: 1, url: 'https://example.test/zero', status_class: 'ok', status_label: 'OK', credits: 1, safe: 0, aggressive: 0, needed: 12 }],
  scanId: 'scan-zero-result', hasActiveCuRules: false, keptProtectionSummary: { count: 0, rows: [] }
});
assert.strictEqual(h.els['cu-rescan-noopt-button'].style.display, '', 'eligible OK S:0 A:0 results reveal the bulk zero-results rescan');

T.restoreStep4({
  jobId: 'job-external', safeCount: 2, aggCount: 3, canPush: false, externalOnly: true,
  bannerData: {}, urlsScanned: 1, pages: [pages[0]], scanId: 'scan-external',
  hasActiveCuRules: false, keptProtectionSummary: { count: 2, rows: [] }
});
assert.strictEqual(h.els['cu-cu-status-title'].textContent, 'External URLs scanned', 'external-only results do not claim a local integration action');
assert.strictEqual(h.els['cu-next-step-title'].textContent, 'Download the JSON file', 'external-only next step points to the only usable action');
assert.ok(/CU import JSON/.test(h.els['cu-recommendations-copy'].textContent), 'external-only recommendations explain the manual JSON workflow');
assert.ok(/unavailable/.test(h.els['cu-recommendations-footnote'].textContent), 'external-only recommendations do not advertise Push or Sync');
assert.strictEqual(h.els['cu-btn-sync'].style.display, 'none', 'Sync remains unavailable for external-only scans');
assert.strictEqual(h.els['cu-btn-push'].style.display, 'none', 'Push remains unavailable for external-only scans');
assert.strictEqual(h.els['cu-rescan-noopt-button'].style.display, 'none', 'a later result with positive S/A counts hides the zero-results rescan again');

console.log('admin-redesign.test.js OK');
