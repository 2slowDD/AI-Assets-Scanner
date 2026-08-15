const assert = require('assert');
const fs = require('fs');
const path = require('path');
const { createHarness, createMenuBadgeHarness } = require('./r3-stage-c-harness');

// Train 2, A2 — the Step-4 "protection scripts kept" note.
//
// P17: this drives the REAL production activation path, not an injected one. The field
// crosses FOUR closed key literals before it can reach the operator's screen:
//
//   PHP payload
//     -> scanner.js:1886  restoreStep4 options literal      (live render)
//     -> scanner.js:1895  localStorage persist literal      (survives a reload)
//     -> scanner.js:3103  restore options literal           (rebuild after reload)
//   and, on a BACKGROUND-completed scan,
//     -> menu-badge.js:134 localStorage persist literal
//
// "Applied at one writer, dropped at a second" is this plugin's most common defect class,
// and the persist literal is the one that gets missed: skip it and the note appears after a
// live scan and silently disappears on every page reload. So the round-trip below never
// hand-builds the persisted JSON — it captures whatever the shipped writer actually wrote
// and feeds THAT into a second harness. A hand-built object would inject the very field
// under test and stay green with the persist literal deleted.

const COPY_TAIL = ' — anti-bot/anti-spam scripts detected on pages with forms are never unloaded.';

function flush() {
  return new Promise(function (r) { setImmediate(r); })
    .then(function () { return new Promise(function (r) { setImmediate(r); }); });
}

// Every node in the summary's container carrying the note id. An ARRAY on purpose: the
// idempotency check below asserts a second render does not append a duplicate.
function notesIn(h) {
  return h.summaryHost.children.filter(function (c) { return c && c.id === 'cu-kept-protection-note'; });
}

// Stands up a harness whose cu_scanner_build_result response carries `kept`, then drives
// handleStatusUpdate('complete') -> buildResult() -> post() -> the two live writers.
function liveScanWith(kept) {
  const data = {
    scan_id: 'scan1', total_pages: 2, safe_count: 1, aggressive_count: 0,
    can_push: true, pages: [], has_active_cu_rules: false,
    already_present: null, credits_refunded: 0, cu_rules_active: false,
  };
  if (kept !== undefined) { data.kept_protection_summary = kept; }

  const h = createHarness({
    fetch: function (url, opts) {
      const action = opts && opts.body ? opts.body.get('action') : null;
      const body = action === 'cu_scanner_build_result'
        ? { success: true, data: data }
        : { success: true, data: {} };
      return Promise.resolve({ ok: true, json: () => Promise.resolve(body) });
    },
  });
  h.sandbox.window.__cuTest.handleStatusUpdate({ status: 'complete', total: 2, completed: 2, pages: [] });
  return flush().then(function () { return h; });
}

async function run() {
  // --- 1. THE ROUND TRIP: live render -> persist -> reload -> restore -------------
  // Pins all three scanner.js literals in one pass. The sample scan reads 1 (distinct
  // scripts, the count semantics A1 shipped), so this also pins the singular boundary.
  {
    const live = await liveScanWith({ count: 1, vendors: ['Cloudflare Turnstile'] });

    // Literal #1 — the live restoreStep4 options object.
    const liveNotes = notesIn(live);
    assert.strictEqual(liveNotes.length, 1, 'live scan renders exactly one kept-protection note');
    assert.strictEqual(
      liveNotes[0].textContent,
      '🛡 1 protection script kept (Cloudflare Turnstile)' + COPY_TAIL,
      'live note copy — singular at count 1, vendors in parentheses'
    );
    assert.strictEqual(liveNotes[0].className, 'cu-kept-protection', 'note carries the styling class');

    // Literal #2 — whatever the SHIPPED persist writer actually wrote. Not hand-built.
    const persisted = live.sandbox.localStorage.getItem('cu_scanner_result');
    assert.ok(persisted, 'the live scan persisted a cu_scanner_result entry');
    const parsed = JSON.parse(persisted);
    assert.deepStrictEqual(
      parsed.kept_protection_summary, { count: 1, vendors: ['Cloudflare Turnstile'] },
      'the persist literal carries kept_protection_summary, unrenamed'
    );

    // Literal #3 — reload. The restore IIFE runs at load time inside createHarness and
    // rebuilds Step 4 from exactly the bytes the persist writer produced.
    const reloaded = createHarness({ localStorage: { cu_scanner_result: persisted } });
    const restoredNotes = notesIn(reloaded);
    assert.strictEqual(restoredNotes.length, 1, 'the note survives a page reload');
    assert.strictEqual(
      restoredNotes[0].textContent,
      '🛡 1 protection script kept (Cloudflare Turnstile)' + COPY_TAIL,
      'and restores with identical copy'
    );
  }

  // --- 2. Plural boundary + multi-vendor join ------------------------------------
  {
    const h = await liveScanWith({ count: 2, vendors: ['Cloudflare Turnstile', 'reCAPTCHA'] });
    assert.strictEqual(
      notesIn(h)[0].textContent,
      '🛡 2 protection scripts kept (Cloudflare Turnstile, reCAPTCHA)' + COPY_TAIL,
      'plural at count 2; vendors comma-joined'
    );
  }

  // --- 3. count > 0 but no vendor names -> no empty parentheses -------------------
  {
    const h = await liveScanWith({ count: 3, vendors: [] });
    assert.strictEqual(
      notesIn(h)[0].textContent,
      '🛡 3 protection scripts kept' + COPY_TAIL,
      'no vendors => no empty "()" in the copy'
    );
  }

  // --- 4. Nothing kept: PHP OMITS the field entirely (A1's count-0 contract) ------
  {
    const h = await liveScanWith(undefined);
    assert.strictEqual(notesIn(h).length, 0, 'no field => no note element at all');

    const parsed = JSON.parse(h.sandbox.localStorage.getItem('cu_scanner_result'));
    assert.ok(!('kept_protection_summary' in parsed),
      'an absent field must persist as ABSENT, never as null/0 — the restore gates on presence');

    // A worker that sends an explicit zero must also render nothing.
    const z = await liveScanWith({ count: 0, vendors: [] });
    assert.strictEqual(notesIn(z).length, 0, 'count 0 renders no note');
  }

  // --- 5. Idempotency: a second render must not append a duplicate ----------------
  // The harness's document.getElementById only knows the ids it pre-registered, so a
  // dynamically-created node is invisible to it. Registering the SHIPPED node under its
  // own id is what a real document does for free; the create-once branch under test is
  // still scanner.js's, not the test's.
  {
    const h = await liveScanWith({ count: 1, vendors: ['Turnstile'] });
    h.els['cu-kept-protection-note'] = notesIn(h)[0];

    h.sandbox.window.__cuTest.restoreStep4({
      jobId: 'j', safeCount: 1, aggCount: 0, canPush: true, externalOnly: false, bannerData: {},
      urlsScanned: 2, pages: [], scanId: 's', hasActiveCuRules: false,
      alreadyPresent: null, creditsRefunded: 0,
      keptProtectionSummary: { count: 4, vendors: ['hCaptcha'] }
    });

    const after = notesIn(h);
    assert.strictEqual(after.length, 1, 'a re-render REUSES the note node, never appends a second');
    assert.strictEqual(after[0].textContent, '🛡 4 protection scripts kept (hCaptcha)' + COPY_TAIL,
      'and rewrites it with the new result');
  }

  // --- 6. Staleness: a later result that kept nothing must clear the note ---------
  // Without this an operator whose first scan kept a script, then rescans a page with no
  // forms, keeps reading a claim that is no longer true.
  {
    const h = await liveScanWith({ count: 1, vendors: ['Turnstile'] });
    h.els['cu-kept-protection-note'] = notesIn(h)[0];

    h.sandbox.window.__cuTest.restoreStep4({
      jobId: 'j', safeCount: 1, aggCount: 0, canPush: true, externalOnly: false, bannerData: {},
      urlsScanned: 2, pages: [], scanId: 's', hasActiveCuRules: false,
      alreadyPresent: null, creditsRefunded: 0
      // no keptProtectionSummary — nothing kept this time
    });

    assert.strictEqual(notesIn(h).length, 0,
      'a result with nothing kept REMOVES the stale note (emptying it would leave an empty blue box)');
  }

  // --- 7. The vendor names are worker-supplied: text sink, never an HTML sink -----
  {
    const h = await liveScanWith({ count: 1, vendors: ['<img src=x onerror=alert(1)>'] });
    const note = notesIn(h)[0];
    assert.ok(/<img src=x onerror=alert\(1\)>/.test(note.textContent),
      'the raw vendor string is preserved as TEXT');
    assert.ok(!/<img/.test(note.innerHTML),
      'and never reaches the DOM as markup — textContent, not innerHTML');
    assert.ok(/&lt;img/.test(note.innerHTML), 'it is entity-escaped on the way in');
  }

  // --- 8. BOTH JS localStorage writers must carry the field, under the same name --
  // menu-badge.js is the BACKGROUND-completion writer: a scan that finishes while the
  // operator is on another admin page never runs scanner.js's writer at all. Source-text
  // pinned the same way result-truth-display.test.js pins already_present/credits_refunded.
  {
    const read = (p) => fs.readFileSync(path.join(__dirname, '..', '..', 'admin', 'js', p), 'utf8');
    for (const name of ['scanner.js', 'menu-badge.js']) {
      const src = read(name);
      const idx = src.indexOf("localStorage.setItem('cu_scanner_result'") >= 0
        ? src.indexOf("localStorage.setItem('cu_scanner_result'")
        : src.indexOf("localStorage.setItem( 'cu_scanner_result'");
      assert.ok(idx > 0, name + ': could not locate the cu_scanner_result write');
      // Bound the slice by the literal's own closing delimiter, not a magic character
      // count — a fixed window silently stops covering the last key as the literal grows,
      // which is how this pin would rot into a false green.
      const close = [src.indexOf('}) );', idx), src.indexOf('}));', idx)].filter((n) => n > 0);
      assert.ok(close.length, name + ': could not find the end of the persisted object literal');
      const block = src.slice(idx, Math.min.apply(null, close));

      // Pin the VALUE EXPRESSION, not just the key name. A key-name-only regex catches
      // deletion but is blind to a wrong source (`res.data.kept_protection`), which
      // JSON.stringify then drops as undefined — a green suite persisting a note-less
      // blob on the one writer with no behavioural coverage at all. P17: source-text pins
      // "miss retyping, a wrong argument, and a call sitting in a comment".
      const SOURCE_EXPR = {
        'scanner.js':    /kept_protection_summary\s*:\s*d\.kept_protection_summary\b/,
        'menu-badge.js': /kept_protection_summary\s*:\s*res\.data\.kept_protection_summary\b/,
      };
      assert.ok(SOURCE_EXPR[name].test(block),
        name + ' must persist kept_protection_summary FROM THE RIGHT SOURCE — absent, renamed,'
             + ' or read off the wrong expression all land here');
      assert.ok(!/keptProtectionSummary\s*:/.test(block),
        name + ': the PERSISTED key stays snake_case — the option and both writers share one name');
    }
  }

  // --- 9. The background writer, EXECUTED — not read as text (A2b, ruling R24) -----
  // §8 above is a source-TEXT pin, and P17 names its blind spot exactly: "a call sitting in
  // a comment". `// kept_protection_summary: res.data.kept_protection_summary` satisfies
  // every regex in §8 while the field is functionally gone — verified by mutation before
  // this section existed, along with a second hole: NOTHING in the suite parsed
  // menu-badge.js, so a syntax error in it shipped green through all 13 JS tests.
  //
  // Both close the same way. createMenuBadgeHarness runs the SHIPPED file (so a syntax
  // error throws at load) and drives the real background path — heartbeat tick -> Railway
  // status poll -> cu_scanner_build_result -> the localStorage write — so the assertion is
  // on the payload that actually lands. A commented-out line cannot satisfy that.
  {
    const KEPT = { count: 2, vendors: ['Cloudflare Turnstile', 'reCAPTCHA'] };
    const mb = createMenuBadgeHarness({
      sessionStorage: {
        cu_scanner_active_job: JSON.stringify({
          job_id: 'job-bg', job_token: 'tok', railway_url: 'https://worker.example',
        }),
      },
      fetch: () => Promise.resolve({ json: () => Promise.resolve({ status: 'complete' }) }),
      post: (data) => (data.action !== 'cu_scanner_build_result' ? { success: true, data: {} } : {
        success: true,
        data: {
          safe_count: 1, aggressive_count: 0, can_push: true, total_pages: 2, scan_id: 'scan-bg',
          pages: [], already_present: { safe: 0, aggressive: 1 }, credits_refunded: 3,
          cu_rules_active: false, kept_protection_summary: KEPT,
        },
      }),
    });

    mb.tick({ aias_badge: 'green' });
    await flush();

    assert.ok(mb.posts.some((p) => p.action === 'cu_scanner_build_result'),
      'the tick really reached the build_result call (guard the guard — a harness that never'
      + ' fired would make every assertion below vacuous)');

    const raw = mb.sandbox.localStorage.getItem('cu_scanner_result');
    assert.ok(raw, 'the background writer persisted a cu_scanner_result entry');
    const blob = JSON.parse(raw);
    assert.deepStrictEqual(blob.kept_protection_summary, KEPT,
      'menu-badge.js carries kept_protection_summary through to localStorage — deleted,'
      + ' renamed, read off the wrong source, or COMMENTED OUT all land here');
    // The A1-era result-truth fields ride the same writer and shared the same blind spot;
    // assert them on the real payload rather than leaving them on a text pin alone.
    assert.deepStrictEqual(blob.already_present, { safe: 0, aggressive: 1 },
      'already_present survives the background write');
    assert.strictEqual(blob.credits_refunded, 3, 'credits_refunded survives the background write');

    // And what it wrote must restore into a rendered note — that AAS-return path is the
    // entire reason the background writer carries the field at all.
    const reloaded = createHarness({ localStorage: { cu_scanner_result: raw } });
    const restored = notesIn(reloaded);
    assert.strictEqual(restored.length, 1, 'the background-written blob restores the note on AAS-return');
    assert.strictEqual(restored[0].textContent,
      '🛡 2 protection scripts kept (Cloudflare Turnstile, reCAPTCHA)' + COPY_TAIL,
      'with the same copy the live path renders');
  }

  console.log('kept-protection-note: all assertions passed');
}

run().catch(function (e) { console.error(e); process.exit(1); });
