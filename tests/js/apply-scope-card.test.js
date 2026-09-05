// FU-AAS-SYNC-SCOPE-LAST-SCAN — the Ready-to-apply card renders the scoped, host-internal
// apply_* counts and the Push/Sync flag is DERIVED from them (spec §3.3 wire site 4), on the
// live path, the localStorage restore path, and for a legacy blob without apply_*.
//
// Falsification answers:
//   testCardUsesApplyCounts          — card wired to safe/agg instead of apply_*: total reads 9, not 7.
//   testTilesKeepScanTotals          — the existing pair reassigned instead of a second pair: tiles read 7 (AC-5(i)).
//   testMixedHostLive                — flag not derived: buttons live beside card 0.
//   testMixedHostRestore             — flag derived on live path only: restore renders live buttons (r2 M2).
//   testLegacyBlobFallsBack          — apply_* treated as 0 when absent: card 0 / buttons dormant on an old blob.
//   testNullIsNotKnown               — Number(null)===0 accepted as known: buttons dormant on explicit nulls (r3 m4).
//   testW1CarriesApplyFields         — live literal missing the fields: the blob lacks them.
const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');

function text(h, id) { return h.els[id] ? h.els[id].textContent : undefined; }
function dormant(h, id) { const b = h.els[id]; return !!(b && (b.disabled === true || (b.classList && b.classList.contains('cu-btn-dormant')))); }
function flush() { return new Promise(function (r) { setImmediate(r); }).then(function () { return new Promise(function (r) { setImmediate(r); }); }); }

function renderLive(h, d) {
  // Drive the same restoreStep4 the live path calls, with the payload shape do_build_result returns.
  h.sandbox.window.__cuTest.restoreStep4({
    jobId: 'job1', safeCount: d.safe_count, aggCount: d.aggressive_count, canPush: true, externalOnly: false,
    bannerData: {}, urlsScanned: 4, pages: [], scanId: 'scan1', hasActiveCuRules: false,
    hasInternalRules: d.has_internal_rules,
    applySafeCount: d.apply_safe_count, applyAggCount: d.apply_aggressive_count,
  });
}

const FIXTURE_B = { safe_count: 1, aggressive_count: 8, has_internal_rules: true, apply_safe_count: 1, apply_aggressive_count: 6 };
const MIXED_NONE = { safe_count: 0, aggressive_count: 2, has_internal_rules: false, apply_safe_count: 0, apply_aggressive_count: 0 };

function testCardUsesApplyCounts() {
  const h = createHarness(); renderLive(h, FIXTURE_B);
  assert.strictEqual(text(h, 'cu-apply-safe'), '1'); assert.strictEqual(text(h, 'cu-apply-aggressive'), '6');
  assert.strictEqual(text(h, 'cu-ready-rule-total'), '7', 'card = apply_safe + apply_aggressive');
  console.log('OK card renders the apply_* counts');
}
function testTilesKeepScanTotals() {
  const h = createHarness(); renderLive(h, FIXTURE_B);
  assert.strictEqual(text(h, 'cu-metric-safe'), '1'); assert.strictEqual(text(h, 'cu-metric-aggressive'), '8', 'tiles = scan totals (a SECOND pair, not a reassign)');
  console.log('OK metric tiles keep the scan totals');
}
function testMixedHostLive() {
  const h = createHarness(); renderLive(h, MIXED_NONE);
  assert.strictEqual(text(h, 'cu-ready-rule-total'), '0');
  assert.ok(dormant(h, 'cu-btn-push') && dormant(h, 'cu-btn-sync'), 'both buttons dormant beside card 0 (AC-5(i))');
  assert.strictEqual(text(h, 'cu-metric-aggressive'), '2', 'tiles still show the external page');
  console.log('OK mixed-host live: card 0 + dormant buttons + tiles at totals');
}
function testMixedHostRestore() {
  // The blob W1 writes for MIXED_NONE has NO has_internal_rules (pre-existing); the flag must still derive false.
  const blob = { job_id: 'job1', safe_count: 0, agg_count: 2, can_push: true, external_only: false, total_pages: 2, pages: [], scan_id: 'scan1',
                 already_present: null, credits_refunded: null, cu_rules_active: false, apply_safe_count: 0, apply_aggressive_count: 0 };
  const h = createHarness({ localStorage: { cu_scanner_result: JSON.stringify(blob) } });
  // scanner.js's restore IIFE ran at load with that blob (createHarness executes the file); assert what landed.
  assert.strictEqual(text(h, 'cu-ready-rule-total'), '0');
  assert.ok(dormant(h, 'cu-btn-push') && dormant(h, 'cu-btn-sync'), 'restore path: dormant buttons beside card 0 (AC-5(ii))');
  console.log('OK mixed-host restore: derived flag keeps the buttons dormant');
}
function testLegacyBlobFallsBack() {
  const blob = { job_id: 'job1', safe_count: 1, agg_count: 8, can_push: true, external_only: false, total_pages: 4, pages: [], scan_id: 'scan1' };
  const h = createHarness({ localStorage: { cu_scanner_result: JSON.stringify(blob) } });
  assert.strictEqual(text(h, 'cu-ready-rule-total'), '9', 'no apply_* => card falls back to the scan totals (AC-5(iii))');
  assert.ok(!dormant(h, 'cu-btn-sync'), 'and the flag falls back to totalRules > 0');
  console.log('OK legacy blob: today\'s behaviour');
}
function testNullIsNotKnown() {
  const h = createHarness();
  renderLive(h, { safe_count: 1, aggressive_count: 8, has_internal_rules: true, apply_safe_count: null, apply_aggressive_count: null });
  assert.strictEqual(text(h, 'cu-ready-rule-total'), '9', 'explicit nulls are NOT "known 0/0" (r3 m4)');
  assert.ok(!dormant(h, 'cu-btn-sync'));
  console.log('OK null apply_* is not treated as known');
}
// testW1CarriesApplyFields drives the REAL live write path: handleStatusUpdate({status:'complete'})
// -> buildResult() -> post('cu_scanner_build_result') (fetch) -> the W1 localStorage.setItem literal.
// This is the same pattern summary-bold-counts.test.js's testLiveScanPathRendersBold uses to drive
// the live branch through the harness's fetch shim, so no harness-only __cuTest addition and no
// second write path are needed.
function testW1CarriesApplyFields() {
  const h = createHarness({
    fetch: function (url, o) {
      const action = o && o.body ? o.body.get('action') : null;
      return Promise.resolve({
        ok: true,
        json: function () {
          return Promise.resolve(action === 'cu_scanner_build_result'
            ? { success: true, data: Object.assign({
                scan_id: 'scan1', total_pages: 4, safe_count: FIXTURE_B.safe_count,
                aggressive_count: FIXTURE_B.aggressive_count, can_push: true, pages: [],
                has_active_cu_rules: false, has_internal_rules: FIXTURE_B.has_internal_rules,
                already_present: null, credits_refunded: 0, cu_rules_active: false,
              }, { apply_safe_count: FIXTURE_B.apply_safe_count, apply_aggressive_count: FIXTURE_B.apply_aggressive_count }) }
            : { success: true, data: {} });
        }
      });
    }
  });
  h.sandbox.window.__cuTest.handleStatusUpdate({ status: 'complete', total: 4, completed: 4, pages: [] });
  return flush().then(function () {
    const blob = JSON.parse(h.sandbox.localStorage.getItem('cu_scanner_result'));
    assert.strictEqual(blob.apply_safe_count, 1); assert.strictEqual(blob.apply_aggressive_count, 6);
    console.log('OK W1 carries apply_safe_count / apply_aggressive_count');
  });
}

testCardUsesApplyCounts();
testTilesKeepScanTotals();
testMixedHostLive();
testMixedHostRestore();
testLegacyBlobFallsBack();
testNullIsNotKnown();
testW1CarriesApplyFields().then(function () {
  console.log('apply-scope-card: all assertions passed');
}).catch(function (e) {
  console.error(e);
  process.exitCode = 1;
});
