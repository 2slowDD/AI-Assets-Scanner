// Train 2, A2b — the Step-4 summary line bolds its NON-ZERO rule counts.
//
// Operator-confirmed from a live mockup 2026-08-14: "N safe rules" and "N aggressive rules"
// render bold when N > 0. Nothing else on the line is bolded, and the sentence itself does
// not change — only its markup does.
//
// FU-K + FU-I (2026-08-16, coupled, ship together) — a live scan reading
// "0 safe rules, 9 aggressive rules generated" showed A2b was only bolding the DIGIT
// (`<strong>1</strong> safe rules`), not the phrase the operator meant to emphasise. FU-K
// moves the bold boundary to cover the count AND its noun ( `<strong>1 safe rule</strong>` ),
// and FU-I adds the missing singular branch in the same phrase ("1 safe rule", not
// "1 safe rules"). They are coupled on purpose: bolding the ungrammatical plural would have
// made the grammar bug louder, not fixed it. `o.urls` is still never bold, and zero still
// stays plain.
//
// Runs the REAL admin/js/scanner.js through the shared harness and reads the REAL
// #cu-result-summary element, so this exercises the production render path (restoreStep4 ->
// buildSummaryParts -> renderSummaryParts), not an injected one.
//
// The load-bearing case is testHintDoesNotFlattenTheBold. `el.textContent += hint` reads the
// element's text back — DISCARDING every <strong> — and reassigns it as flat text. That form
// shipped here before A2b, so on every state that has a next-step hint (which is the common
// state: a scan that produced rules and can push them) the bold would silently vanish in
// production with no error and no other test going red.
//
// Falsification answers — the mistake each test exists to catch, and why it goes RED:
//   1. testNonZeroCountsAreBold        — bold never wired / wired to the wrong segment:
//                                        querySelectorAll('strong') returns [] or the wrong text.
//   2. testZeroIsNotBold               — bolding on `!= null` / `>= 0` instead of `> 0`:
//                                        a strong appears around "0".
//   3. testNaNIsNotBold                — bolding on truthiness or on presence: NaN, a string,
//                                        undefined and null all become <strong>.
//   4. testUrlsIsNeverBold             — bolding every numeric-looking token: the URL count
//                                        (and '?', which is not a number at all) get wrapped.
//   5. testTextIsByteIdenticalToTheLine— the parts builder drifting from buildSummaryLine (a
//                                        lost space, a doubled segment, a dropped tail):
//                                        strictEqual against buildSummaryLine for the SAME
//                                        input, so the two literally cannot diverge.
//   6. testHintDoesNotFlattenTheBold   — `textContent +=` restored at the append site: the
//                                        strongs are flattened away, so the list goes empty.
//   7. testAlreadyPresentTailNotBold   — bolding the fresh/already numbers (explicitly out of
//                                        scope): they show up in the strong list.
//   8. testReRenderReplaces            — renderSummaryParts appending instead of replacing:
//                                        the second render stacks and the text doubles.
//   9. testLiveScanPathRendersBold     — bold wired into a helper the real wire never reaches:
//                                        every seam-driven case stays green, this one reds.
//  10. testSingularNounForCountOfOne  — FU-I's singular branch missing, or keyed on
//                                       truthiness/`>= 1` instead of an exact numeric 1: "1
//                                       safe rule" renders as "1 safe rules", or a non-1 value
//                                       wrongly takes the singular noun.
//  11. testBoldPhraseNotBareDigit     — FU-K's segment-boundary regression: the bold flag
//                                       stays on the bare count instead of moving with the
//                                       words, so <strong> wraps "3" instead of "3 safe
//                                       rules".
//  12. testUrlsScannedSingularNoun    — Operator ruling 2026-08-16: FU-I alone left a
//                                       1-URL scan reading "1 URLs scanned" while the rule
//                                       counts read grammatically — worse than uniform. The
//                                       URLs-scanned segment gets the same singular branch,
//                                       keyed the same way (Number(n) === 1), with the '?'
//                                       unknown-count fallback pinned to stay PLURAL.
const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');

const T = createHarness().sandbox.window.__cuTest;
assert.ok(T.buildSummaryParts, 'buildSummaryParts must be exposed on __cuTest');
const { buildSummaryLine, buildSummaryParts } = T;

const HINT_PUSH = ' You can apply them now with the Push or Sync buttons.';

// Drives the SHIPPED restoreStep4 and reports what actually landed in the DOM.
function render(opts) {
  const h = createHarness();
  h.sandbox.window.__cuTest.restoreStep4(Object.assign({
    jobId: 'job1', safeCount: 0, aggCount: 0, canPush: true, externalOnly: false,
    bannerData: {}, urlsScanned: 5, pages: [], scanId: 'scan1', hasActiveCuRules: false
  }, opts));
  const el = h.els['cu-result-summary'];
  return {
    h: h,
    el: el,
    // Element CONTENTS, not a serialized string: what the operator sees emphasised.
    strongs: el.querySelectorAll('strong').map(function (s) { return s.textContent; }),
    text: el.textContent
  };
}

// --- 1. Both counts bold when both are above zero ------------------------------------
// FU-K: the bold segment is the WHOLE phrase (count + noun), not the bare digit.
function testNonZeroCountsAreBold() {
  const r = render({ safeCount: 3, aggCount: 7 });
  assert.deepStrictEqual(r.strongs, ['3 safe rules', '7 aggressive rules'],
    'both non-zero counts render bold as the whole phrase, in sentence order; got: ' + JSON.stringify(r.strongs));
  // The bold wraps a TEXT node — the count is server-sourced, so this must never be an
  // HTML sink. A <strong> holding anything but a text node is the tell.
  const strongEls = r.el.querySelectorAll('strong');
  strongEls.forEach(function (s) {
    assert.strictEqual(s.children.length, 1, 'a bold segment holds exactly one node');
    assert.strictEqual(s.children[0].nodeType, 3, 'and that node is TEXT, never markup');
  });
  console.log('OK non-zero safe + aggressive counts are bold, as text nodes');
}

// --- 2. Zero is never bold -----------------------------------------------------------
function testZeroIsNotBold() {
  assert.deepStrictEqual(render({ safeCount: 0, aggCount: 7 }).strongs, ['7 aggressive rules'],
    'a zero safe count stays plain while the aggressive count is bold');
  assert.deepStrictEqual(render({ safeCount: 3, aggCount: 0 }).strongs, ['3 safe rules'],
    'and the other way round');
  assert.deepStrictEqual(render({ safeCount: 0, aggCount: 0 }).strongs, [],
    'a scan that produced nothing bolds nothing at all');
  console.log('OK zero counts are never bold');
}

// --- 3. NaN / non-numeric is never bold ----------------------------------------------
function testNaNIsNotBold() {
  const cases = [NaN, 'abc', undefined, null, -2];
  cases.forEach(function (bad) {
    const r = render({ safeCount: bad, aggCount: 7 });
    assert.deepStrictEqual(r.strongs, ['7 aggressive rules'],
      'safeCount ' + JSON.stringify(String(bad)) + ' must not be bold; got: ' + JSON.stringify(r.strongs));
  });
  // ...and the unbolded value still renders, verbatim, as the sentence always rendered it.
  assert.ok(/NaN safe rules/.test(render({ safeCount: NaN, aggCount: 7 }).text),
    'the NaN still appears in the copy — A2b changes markup, never the words');
  console.log('OK NaN / non-numeric / negative counts are never bold');
}

// --- 4. The URL count is never bold, and neither is its '?' fallback ------------------
function testUrlsIsNeverBold() {
  const known = render({ urlsScanned: 9, safeCount: 4, aggCount: 0 });
  assert.deepStrictEqual(known.strongs, ['4 safe rules'],
    'a known URL count is never bold, however large; got: ' + JSON.stringify(known.strongs));

  // urlsScanned absent => restoreStep4 renders the literal '?'. Bolding a number-shaped
  // token would be wrong here twice over: it is not a rule count, and it is not a number.
  const unknown = render({ urlsScanned: undefined, safeCount: 4, aggCount: 0 });
  assert.ok(/\? URLs scanned/.test(unknown.text), "an unknown URL count still renders '?'");
  assert.deepStrictEqual(unknown.strongs, ['4 safe rules'], "'?' is never bold");
  console.log("OK the URL count — including the '?' fallback — is never bold");
}

// --- 5. The words are untouched: byte-identical to the plain string builder -----------
function testTextIsByteIdenticalToTheLine() {
  // No hint state (0 rules => restoreStep4 appends nothing), so textContent IS the sentence.
  const bare = render({ urlsScanned: 5, safeCount: 0, aggCount: 0 });
  assert.strictEqual(
    bare.text,
    buildSummaryLine({ urls: 5, safeCount: 0, aggCount: 0, alreadyPresent: undefined }),
    'the rendered text is byte-identical to buildSummaryLine for the same input'
  );

  // Every shape the builder branches on, compared part-join vs string, so a future edit to
  // one and not the other cannot survive.
  const shapes = [
    { urls: 5, safeCount: 3, aggCount: 7, alreadyPresent: null },
    { urls: '?', safeCount: 0, aggCount: 0, alreadyPresent: { safe: 0, aggressive: 0 } },
    { urls: 2, safeCount: 1, aggCount: 3, alreadyPresent: { safe: 0, aggressive: 2 } },
    { urls: 2, safeCount: 0, aggCount: 3, alreadyPresent: { safe: 0, aggressive: 3 } },
    { urls: 1, safeCount: 0, aggCount: 2, alreadyPresent: { safe: 0, aggressive: 5 } },
    { urls: 0, safeCount: NaN, aggCount: undefined, alreadyPresent: null }
  ];
  shapes.forEach(function (o) {
    const joined = buildSummaryParts(o).map(function (p) { return p.text; }).join('');
    assert.strictEqual(joined, buildSummaryLine(o),
      'parts join must equal the line for ' + JSON.stringify(o));
  });
  console.log('OK the sentence is byte-for-byte what it always was');
}

// --- 6. THE REGRESSION THAT MADE A2b NECESSARY ---------------------------------------
// The next-step hint is appended AFTER the summary is rendered. With `textContent +=` the
// append silently flattens the markup; with a text node it does not.
function testHintDoesNotFlattenTheBold() {
  const r = render({ urlsScanned: 5, safeCount: 3, aggCount: 7, canPush: true });
  assert.ok(r.text.indexOf(HINT_PUSH) > 0, 'this state really does append a hint (guard the guard)');
  assert.deepStrictEqual(r.strongs, ['3 safe rules', '7 aggressive rules'],
    'the <strong> elements SURVIVE the hint append; got: ' + JSON.stringify(r.strongs));
  assert.strictEqual(
    r.text,
    buildSummaryLine({ urls: 5, safeCount: 3, aggCount: 7, alreadyPresent: undefined }) + HINT_PUSH,
    'and the full text is still sentence + hint, unchanged'
  );
  console.log('OK the next-step hint appends without flattening the bold');
}

// --- 7. The "N new, M already" tail is explicitly out of scope ------------------------
function testAlreadyPresentTailNotBold() {
  const r = render({ urlsScanned: 2, safeCount: 1, aggCount: 3, alreadyPresent: { safe: 0, aggressive: 2 } });
  assert.ok(/→ 2 new, 2 already in Code Unloader/.test(r.text), 'the tail renders');
  // safeCount: 1 exercises FU-I's singular noun in the same assertion as FU-K's phrase bold.
  assert.deepStrictEqual(r.strongs, ['1 safe rule', '3 aggressive rules'],
    'only the two rule-count phrases are bold — the fresh/already numbers are not; got: ' + JSON.stringify(r.strongs));
  console.log('OK the new/already tail is never bolded');
}

// --- 10. FU-I: singular noun fires ONLY on a real numeric 1 ---------------------------
// "1 safe rule" / "1 aggressive rule", never "...rules". Every other count (0, 9, and the
// non-numeric cases already covered by testNaNIsNotBold) keeps the plural noun.
function testSingularNounForCountOfOne() {
  const both = render({ safeCount: 1, aggCount: 1 });
  assert.deepStrictEqual(both.strongs, ['1 safe rule', '1 aggressive rule'],
    'both counts of exactly 1 take the singular noun, bold as one phrase; got: ' + JSON.stringify(both.strongs));
  assert.ok(both.text.indexOf('1 safe rule, 1 aggressive rule generated.') > 0,
    'the sentence reads grammatically; got: ' + JSON.stringify(both.text));

  const mixedLow = render({ safeCount: 1, aggCount: 9 });
  assert.deepStrictEqual(mixedLow.strongs, ['1 safe rule', '9 aggressive rules'],
    'singular safe, plural aggressive, independently; got: ' + JSON.stringify(mixedLow.strongs));

  const mixedHigh = render({ safeCount: 9, aggCount: 1 });
  assert.deepStrictEqual(mixedHigh.strongs, ['9 safe rules', '1 aggressive rule'],
    'plural safe, singular aggressive, independently; got: ' + JSON.stringify(mixedHigh.strongs));

  // A zero count stays plain AND plural — singular is about the noun, not the bold flag.
  const zeroAndOne = render({ safeCount: 0, aggCount: 1 });
  assert.deepStrictEqual(zeroAndOne.strongs, ['1 aggressive rule'],
    'a zero count never goes singular or bold; got: ' + JSON.stringify(zeroAndOne.strongs));
  assert.ok(zeroAndOne.text.indexOf('0 safe rules, 1 aggressive rule generated.') > 0,
    'zero stays plural, one goes singular, in the same sentence; got: ' + JSON.stringify(zeroAndOne.text));
  console.log('OK the singular noun fires only on a real numeric 1, independently per count');
}

// --- 11. FU-K: the bold segment is the phrase, not the bare digit ---------------------
// Falsifies a regression where the bold flag stays on the count and the noun words are
// pushed as a separate, unbold segment (i.e. A2b's original boundary, before FU-K moved it).
function testBoldPhraseNotBareDigit() {
  const r = render({ safeCount: 3, aggCount: 7 });
  const strongEls = r.el.querySelectorAll('strong');
  assert.strictEqual(strongEls.length, 2, 'exactly one <strong> per non-zero count');
  strongEls.forEach(function (s) {
    assert.ok(/^\d+ (safe|aggressive) rules?$/.test(s.textContent),
      'the bold segment is the whole "N noun rule(s)" phrase, not a bare digit; got: ' + JSON.stringify(s.textContent));
  });
  // The digit alone must NOT appear as its own <strong> — that would mean the boundary
  // reverted to wrapping just the count.
  assert.ok(r.strongs.indexOf('3') === -1 && r.strongs.indexOf('7') === -1,
    'no <strong> wraps a bare digit; got: ' + JSON.stringify(r.strongs));
  console.log('OK the bold segment carries the whole count+noun phrase, not a bare digit');
}

// --- 12. Operator ruling 2026-08-16: the URLs-scanned segment gets the same singular ---
// branch as FU-I, so the sentence doesn't ship half-fixed ("1 URLs scanned, 1 safe rule").
// o.urls stays PLAIN in every case — this is a noun fix, not a bolding change — and the
// '?' unknown-count fallback must stay PLURAL: '?' is not a real numeric 1.
function testUrlsScannedSingularNoun() {
  const one = render({ urlsScanned: 1, safeCount: 0, aggCount: 0 });
  assert.ok(one.text.indexOf('Scan complete. 1 URL scanned, ') === 0,
    'a 1-URL scan takes the singular noun; got: ' + JSON.stringify(one.text));

  const three = render({ urlsScanned: 3, safeCount: 0, aggCount: 0 });
  assert.ok(three.text.indexOf('Scan complete. 3 URLs scanned, ') === 0,
    'any other real count keeps the plural noun; got: ' + JSON.stringify(three.text));

  // The '?' fallback (urlsScanned absent/non-number) is the input nobody thinks to test —
  // it must NEVER take the singular noun.
  const unknown = render({ urlsScanned: undefined, safeCount: 0, aggCount: 0 });
  assert.ok(unknown.text.indexOf('Scan complete. ? URLs scanned, ') === 0,
    "the '?' fallback keeps the plural noun, never singular; got: " + JSON.stringify(unknown.text));

  // o.urls must never become bold as a side effect of this segment's grammar fix — this
  // render has safeCount:0/aggCount:0 too, so ANY <strong> here can only be the URL count.
  assert.deepStrictEqual(one.strongs, [],
    'the URL count is never bold, singular or not; got: ' + JSON.stringify(one.strongs));
  assert.deepStrictEqual(unknown.strongs, [],
    "the '?' fallback is never bold either; got: " + JSON.stringify(unknown.strongs));
  console.log('OK the URLs-scanned segment takes the singular noun only for a real count of 1, stays plain always, and never singularises "?"');
}

// --- 8. A second render replaces the first, never stacks on it ------------------------
// restoreStep4 runs more than once per page life (a live build_result after a localStorage
// restore), so renderSummaryParts must clear before it writes.
function testReRenderReplaces() {
  const h = createHarness();
  const opts = {
    jobId: 'job1', safeCount: 3, aggCount: 7, canPush: true, externalOnly: false,
    bannerData: {}, urlsScanned: 5, pages: [], scanId: 'scan1', hasActiveCuRules: false
  };
  h.sandbox.window.__cuTest.restoreStep4(opts);
  const first = h.els['cu-result-summary'].textContent;
  h.sandbox.window.__cuTest.restoreStep4(opts);
  const second = h.els['cu-result-summary'].textContent;

  assert.strictEqual(second, first, 'a re-render REPLACES the summary; it must not stack');
  assert.deepStrictEqual(
    h.els['cu-result-summary'].querySelectorAll('strong').map(function (s) { return s.textContent; }),
    ['3 safe rules', '7 aggressive rules'],
    'and leaves exactly one <strong> per non-zero count phrase, not two'
  );
  console.log('OK a second render replaces rather than appends');
}

// --- 9. The LIVE scan path, not just the restoreStep4 seam ---------------------------
// Every case above enters through __cuTest.restoreStep4. That is the shipped render
// function, but it is reached in the tests through a test-only seam, so one case drives the
// real wire instead: handleStatusUpdate('complete') -> buildResult() -> the AJAX response ->
// restoreStep4. Falsification: if the bold were wired into some other, unreached helper —
// or if the live path stopped routing through renderSummaryParts — this goes red while
// every seam-driven case above stays green.
function flush() {
  return new Promise(function (r) { setImmediate(r); })
    .then(function () { return new Promise(function (r) { setImmediate(r); }); });
}

function testLiveScanPathRendersBold() {
  const h = createHarness({
    fetch: function (url, o) {
      const action = o && o.body ? o.body.get('action') : null;
      return Promise.resolve({
        ok: true,
        json: function () {
          return Promise.resolve(action === 'cu_scanner_build_result'
            ? { success: true, data: {
                scan_id: 'scan1', total_pages: 4, safe_count: 2, aggressive_count: 6,
                can_push: true, pages: [], has_active_cu_rules: false,
                already_present: null, credits_refunded: 0, cu_rules_active: false } }
            : { success: true, data: {} });
        }
      });
    }
  });
  h.sandbox.window.__cuTest.handleStatusUpdate({ status: 'complete', total: 4, completed: 4, pages: [] });

  return flush().then(function () {
    const el = h.els['cu-result-summary'];
    assert.ok(/Scan complete\./.test(el.textContent), 'the live path really rendered a summary');
    assert.deepStrictEqual(
      el.querySelectorAll('strong').map(function (s) { return s.textContent; }),
      ['2 safe rules', '6 aggressive rules'],
      'a real completed scan renders its non-zero count phrases bold; got: ' + JSON.stringify(el.textContent)
    );
    console.log('OK a live completed scan renders the bold counts');
  });
}

testNonZeroCountsAreBold();
testZeroIsNotBold();
testNaNIsNotBold();
testUrlsIsNeverBold();
testTextIsByteIdenticalToTheLine();
testHintDoesNotFlattenTheBold();
testAlreadyPresentTailNotBold();
testSingularNounForCountOfOne();
testBoldPhraseNotBareDigit();
testUrlsScannedSingularNoun();
testReRenderReplaces();
testLiveScanPathRendersBold()
  .then(function () { console.log('summary-bold-counts: all assertions passed'); })
  .catch(function (e) { console.error(e); process.exit(1); });
