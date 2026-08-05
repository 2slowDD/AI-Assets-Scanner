// AC-3 / AC-4 / AC-5 / AC-15 — Step-4 result-truth rendering.
//
// Runs the REAL admin/js/scanner.js through the shared sandbox harness, so the copy
// builders and restoreStep4 under test are the shipped ones, not a re-implementation.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const { createHarness } = require('./r3-stage-c-harness');

const h = createHarness();
const T = h.sandbox.window.__cuTest;

assert.ok(T.buildSummaryLine, 'buildSummaryLine must be exposed on __cuTest');
assert.ok(T.buildSyncCopy, 'buildSyncCopy must be exposed on __cuTest');
assert.ok(T.buildRefundLine, 'buildRefundLine must be exposed on __cuTest');

const { buildSummaryLine, buildSyncCopy, buildRefundLine } = T;

// --- split line -------------------------------------------------------------
assert.strictEqual(
  buildSummaryLine({ urls: 2, safeCount: 0, aggCount: 3, alreadyPresent: { safe: 0, aggressive: 3 } }),
  'Scan complete. 2 URLs scanned, 0 safe rules, 3 aggressive rules generated. → 0 new, 3 already in Code Unloader',
  'all-already'
);
assert.strictEqual(
  buildSummaryLine({ urls: 2, safeCount: 1, aggCount: 3, alreadyPresent: { safe: 0, aggressive: 2 } }),
  'Scan complete. 2 URLs scanned, 1 safe rules, 3 aggressive rules generated. → 2 new, 2 already in Code Unloader',
  'mixed'
);
// AC-11: null => today's line only. NO claim in either direction.
assert.strictEqual(
  buildSummaryLine({ urls: 2, safeCount: 0, aggCount: 3, alreadyPresent: null }),
  'Scan complete. 2 URLs scanned, 0 safe rules, 3 aggressive rules generated.',
  'null renders no claim'
);
// A 0-rule scan makes no claim either.
assert.strictEqual(
  buildSummaryLine({ urls: 2, safeCount: 0, aggCount: 0, alreadyPresent: { safe: 0, aggressive: 0 } }),
  'Scan complete. 2 URLs scanned, 0 safe rules, 0 aggressive rules generated.',
  'zero rules'
);
// "X new" must never render negative.
const line = buildSummaryLine({ urls: 1, safeCount: 0, aggCount: 2, alreadyPresent: { safe: 0, aggressive: 5 } });
assert.ok(!/-\d+ new/.test(line), 'X new must never be negative');

// --- Sync copy --------------------------------------------------------------
assert.strictEqual(
  buildSyncCopy({ externalOnly: false, safeCount: 0, aggCount: 3, alreadyPresent: { safe: 0, aggressive: 3 } }),
  'Nothing new to sync — all 3 rules already in Code Unloader'
);
assert.strictEqual(
  buildSyncCopy({ externalOnly: false, safeCount: 1, aggCount: 3, alreadyPresent: { safe: 0, aggressive: 2 } }),
  '', 'something is new => no notice'
);
// AC-15: the externalOnly branch owns #cu-push-result. Never overwrite it.
assert.strictEqual(
  buildSyncCopy({ externalOnly: true, safeCount: 0, aggCount: 3, alreadyPresent: { safe: 0, aggressive: 3 } }),
  '', 'external-only: must not clobber the "External URLs scanned" notice'
);
assert.strictEqual(
  buildSyncCopy({ externalOnly: false, safeCount: 0, aggCount: 3, alreadyPresent: null }),
  '', 'null => no claim'
);

// --- refund line (renders in the SUMMARY block, so externalOnly is irrelevant) --
assert.strictEqual(
  buildRefundLine({ creditsRefunded: 2 }),
  '2 page credits returned — pages whose rules were already in Code Unloader are not billed.'
);
assert.strictEqual(buildRefundLine({ creditsRefunded: 1 }),
  '1 page credit returned — pages whose rules were already in Code Unloader are not billed.');
assert.strictEqual(buildRefundLine({ creditsRefunded: 0 }), '');
assert.strictEqual(buildRefundLine({ creditsRefunded: undefined }), '');

// --- copy discipline --------------------------------------------------------
const all = [
  buildSummaryLine({ urls: 1, safeCount: 0, aggCount: 1, alreadyPresent: { safe: 0, aggressive: 1 } }),
  buildSyncCopy({ externalOnly: false, safeCount: 0, aggCount: 1, alreadyPresent: { safe: 0, aggressive: 1 } }),
  buildRefundLine({ creditsRefunded: 1 }),
].join(' ');
assert.ok(!/already optimi[sz]ed|already applied/i.test(all),
  'copy must say "already in Code Unloader" — a rule can exist without taking effect (spec Q6)');

// --- restoreStep4 renders through the real DOM ------------------------------
// AC-3/AC-5: the summary element and the refund element are written by the shipped
// restoreStep4, driven by the options object.
(function renderThroughRestoreStep4() {
  const h2 = createHarness();
  const T2 = h2.sandbox.window.__cuTest;
  T2.restoreStep4({
    jobId: 'job1', safeCount: 0, aggCount: 3, canPush: true, externalOnly: false,
    bannerData: {}, urlsScanned: 2, pages: [], scanId: 'scan1', hasActiveCuRules: false,
    alreadyPresent: { safe: 0, aggressive: 3 }, creditsRefunded: 2
  });

  assert.strictEqual(
    h2.els['cu-result-summary'].textContent,
    'Scan complete. 2 URLs scanned, 0 safe rules, 3 aggressive rules generated. → 0 new, 3 already in Code Unloader',
    'restoreStep4 writes the split summary line'
  );
  assert.strictEqual(
    h2.els['cu-result-refund'].textContent,
    '2 page credits returned — pages whose rules were already in Code Unloader are not billed.',
    'the refund line renders in the SUMMARY block, not #cu-push-result'
  );
  assert.ok(
    /Nothing new to sync/.test(h2.els['cu-push-result'].innerHTML),
    'the Sync notice renders in the non-externalOnly branch'
  );
}());

// AC-15: on an external-only scan the branch owns #cu-push-result outright.
(function externalOnlyKeepsItsNotice() {
  const h3 = createHarness();
  const T3 = h3.sandbox.window.__cuTest;
  T3.restoreStep4({
    jobId: 'job1', safeCount: 0, aggCount: 3, canPush: true, externalOnly: true,
    bannerData: {}, urlsScanned: 2, pages: [], scanId: 'scan1', hasActiveCuRules: false,
    alreadyPresent: { safe: 0, aggressive: 3 }, creditsRefunded: 0
  });

  assert.ok(
    /External URLs scanned/.test(h3.els['cu-push-result'].innerHTML),
    'the external-only notice must survive'
  );
  assert.ok(
    !/Nothing new to sync/.test(h3.els['cu-push-result'].innerHTML),
    'the Sync copy must not clobber it'
  );
}());

// AC-11 through the real render: null makes no claim anywhere.
(function nullRendersNoClaim() {
  const h4 = createHarness();
  const T4 = h4.sandbox.window.__cuTest;
  T4.restoreStep4({
    jobId: 'job1', safeCount: 0, aggCount: 3, canPush: true, externalOnly: false,
    bannerData: {}, urlsScanned: 2, pages: [], scanId: 'scan1', hasActiveCuRules: false,
    alreadyPresent: null, creditsRefunded: undefined
  });

  assert.strictEqual(
    h4.els['cu-result-summary'].textContent,
    'Scan complete. 2 URLs scanned, 0 safe rules, 3 aggressive rules generated.',
    'null => no claim in the summary'
  );
  assert.strictEqual(h4.els['cu-result-refund'].textContent, '', 'no refund line');
  assert.ok(!/already in Code Unloader/.test(h4.els['cu-push-result'].innerHTML), 'no sync claim');
}());

// --- AC-3: the per-URL "Already in CU" cell -----------------------------------
// A null `already` (multi-page pattern group, or CU unreachable) must render EMPTY,
// never 0 — rendering 0 would be a false claim that nothing is already present.
(function perUrlAlreadyCell() {
  const h5 = createHarness();
  const T5 = h5.sandbox.window.__cuTest;
  const pages = [
    { n: 1, url: 'https://s.com/a', status_class: 'ok', status_label: 'OK', credits: 1,
      safe: 0, aggressive: 2, needed: 0, already: 2 },
    { n: 2, url: 'https://s.com/b', status_class: 'ok', status_label: 'OK', credits: 1,
      safe: 0, aggressive: 1, needed: 0, already: null },
    { n: 3, url: 'https://s.com/c', status_class: 'ok', status_label: 'OK', credits: 1,
      safe: 0, aggressive: 1, needed: 0 },
  ];
  T5.restoreStep4({
    jobId: 'job1', safeCount: 0, aggCount: 4, canPush: true, externalOnly: false,
    bannerData: {}, urlsScanned: 3, pages: pages, scanId: 'scan1', hasActiveCuRules: false,
    alreadyPresent: { safe: 0, aggressive: 2 }, creditsRefunded: 0
  });

  const html = h5.els['cu-result-url-list'].innerHTML;
  const tbody = html.slice(html.indexOf('<tbody>') + 7, html.indexOf('</tbody>'));
  const rows = tbody.split('<tr').slice(1);
  assert.strictEqual(rows.length, 3, 'three data rows rendered');

  const cellsOf = (row) => (row.match(/<td[^>]*>([\s\S]*?)<\/td>/g) || []).map(
    (c) => c.replace(/<td[^>]*>/, '').replace(/<\/td>/, '')
  );

  assert.strictEqual(cellsOf(rows[0])[5], '2', 'a known already-count renders its value');
  assert.strictEqual(cellsOf(rows[1])[5], '', 'null renders EMPTY, never 0');
  assert.strictEqual(cellsOf(rows[2])[5], '', 'an absent key renders EMPTY too');
  assert.ok(/Already in CU/.test(html), 'the column has a header');
}());

// --- AC-4: the two JS localStorage writers must carry the same field NAMES ----
// Writers 3 and 4. The in-tree aggressive_count/agg_count split is exactly this bug
// class, so assert both writers name the new fields identically. Reads the shipped
// sources and checks the object literals actually contain the keys next to the
// localStorage write — the falsification check below deletes them and must go red.
(function bothJsWritersCarryTheFields() {
  const read = (p) => fs.readFileSync(path.join(__dirname, '..', '..', 'admin', 'js', p), 'utf8');
  const writers = { 'scanner.js': read('scanner.js'), 'menu-badge.js': read('menu-badge.js') };

  for (const [name, src] of Object.entries(writers)) {
    const idx = src.indexOf("localStorage.setItem('cu_scanner_result'") >= 0
      ? src.indexOf("localStorage.setItem('cu_scanner_result'")
      : src.indexOf("localStorage.setItem( 'cu_scanner_result'");
    assert.ok(idx > 0, name + ': could not locate the cu_scanner_result write');
    const block = src.slice(idx, idx + 1200);
    assert.ok(/already_present\s*:/.test(block),
      name + ' must carry already_present on the localStorage write (menu-badge.js is the background-completion path)');
    assert.ok(/credits_refunded\s*:/.test(block),
      name + ' must carry credits_refunded on the localStorage write');
  }
}());

console.log('result-truth-display.test.js OK');
