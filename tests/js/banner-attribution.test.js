const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');

// T0-C — the post-scan banner must name the party that rate-limited the scan.
//
// P17: this drives the REAL production activation path, not an injected one:
//   handleStatusUpdate('complete') -> buildResult() -> post('cu_scanner_build_result')
//   -> the bannerData projection (scanner.js:1875) -> restoreStep4() -> renderBrokenBanner().
// Handing renderBrokenBanner/restoreStep4 a hand-built bannerData would inject the very
// field under test and would stay green if the :1875 projection dropped it. It does not:
// the only thing supplied here is the worker's AJAX response body.

function flush() {
  return new Promise(function (r) { setImmediate(r); });
}

// Renders the banner for one `attribution` value by standing up a harness whose
// cu_scanner_build_result response carries it, exactly as the PHP payload does.
function renderBannerWith(attribution) {
  const data = {
    scan_id: 'scan1',
    total_pages: 3,
    pages_blocked: { desktop: 1, mobile: 0 },
    blocked_reasons: { tier1_http_rate_limit: 1 },
    rate_limit_attribution: attribution,
    safe_count: 1,
    aggressive_count: 0,
    can_push: true,
    pages: [],
    has_active_cu_rules: false,
  };
  const h = createHarness({
    fetch: function (url, opts) {
      const action = opts && opts.body ? opts.body.get('action') : null;
      const body = action === 'cu_scanner_build_result'
        ? { success: true, data: data }
        : { success: true, data: {} };
      return Promise.resolve({ ok: true, json: () => Promise.resolve(body) });
    },
  });
  // wp_localize_script's cuReasonCopy: without the category map every reason
  // degrades to 'bot' and the rate branch is never reached.
  h.sandbox.cuReasonCopy = {
    phrases: { tier1_http_rate_limit: 'rate limited' },
    categories: { tier1_http_rate_limit: 'rate' },
  };
  h.sandbox.window.__cuTest.handleStatusUpdate({ status: 'complete', total: 3, completed: 3, pages: [] });
  return flush().then(function () { return h.els['cu-banner-area'].innerHTML || ''; });
}

async function run() {
  const cf = await renderBannerWith('cloudflare');
  assert.ok(/Cloudflare rate-limited the scan/.test(cf), 'CF variant names Cloudflare');
  assert.ok(/cu-cloudflare-waf-bypass/.test(cf), 'CF variant keeps the settings anchor');
  assert.ok(!/Your server rate-limited/.test(cf), 'CF variant drops the old blame-the-origin copy');

  const host = await renderBannerWith('host');
  assert.ok(/rate-limited the scan/.test(host), 'host variant renders');
  assert.ok(/will not help here/.test(host), 'host variant keeps the load-bearing sentence');
  assert.ok(!/cu-cloudflare-waf-bypass/.test(host), 'host variant emits NO settings anchor');

  const absent = await renderBannerWith(undefined);
  assert.ok(/The scan was rate-limited/.test(absent), 'absent field falls back to unknown variant');
  assert.ok(/cu-cloudflare-waf-bypass/.test(absent), 'unknown variant keeps the anchor');

  // Untrusted worker enum: anything off the allowlist degrades to 'unknown' and is
  // never echoed. 'akamai'/'waf' are allowlisted members with no copy of their own.
  for (const bad of ['akamai', 'garbage', null, 42, '<script>', 'HOST']) {
    const out = await renderBannerWith(bad);
    assert.ok(/The scan was rate-limited/.test(out), 'unrecognised "' + bad + '" -> unknown variant');
    assert.ok(out.length > 0, 'never renders empty');
    assert.ok(!/<script>/.test(out), 'raw attribution is never interpolated into markup');
  }

  console.log('OK banner-attribution');
}

run().catch(function (e) { console.error(e); process.exit(1); });
