// The shared harness's textContent contract, pinned.
//
// r3-stage-c-harness.js's makeEl is a DOM double every other JS test reads its assertions
// through. Its textContent semantics have now been edited twice in one task (A2b and the A2b
// fix round), and both times the failure mode was the same shape: a change that looks local
// silently redefines what a dozen assertions in other files MEAN. Nothing pinned those
// semantics, so the only signal was whether unrelated suites happened to still pass.
//
// This file is that pin. It asserts the double's behaviour directly, against the real DOM's
// behaviour as the reference, so a regression reds here — next to the rule it broke — instead
// of surfacing as a confusing failure somewhere downstream.
//
// Falsification answers — the mistake each test exists to catch:
//   1. testAssignedTextSurvivesAnAppend  — the getter REPLACING own text with the children
//                                          instead of concatenating (the pre-fix bug): the
//                                          assigned prefix vanishes.
//   2. testSetterReplacesChildren        — dropping `this.children.length = 0` from the
//                                          setter: a re-render stacks instead of replacing.
//   3. testNestedElementsAggregate       — a getter that walks only direct text-node children
//                                          and not into child ELEMENTS: <strong> text is lost.
//   4. testInnerHtmlMirrorStaysExcluded  — folding _domChildren() into the getter: an
//                                          assigned "<img …>" reads back entity-encoded
//                                          and/or doubled (this is the boundary that
//                                          kept-protection-note.test.js:177 depends on).
//   5. testMixedSinkResidual             — CHARACTERIZATION of a known, documented gap, not an
//                                          endorsement of it. See the comment on that test.
const assert = require('assert');
const { makeEl } = require('./r3-stage-c-harness');

// 1. Real DOM: `textContent = 'a'` creates a text-node CHILD, so a later appendChild does not
//    erase it — el.textContent reads 'ab'. The double must agree, or a production node that
//    writes then appends (scanner.js's summary line does exactly this under a partial revert)
//    is mis-modelled and the tests reading it draw the wrong conclusion.
function testAssignedTextSurvivesAnAppend() {
  const el = makeEl('', 'p');
  el.textContent = 'Prefix: ';
  el.appendChild({ nodeType: 3, textContent: 'tail' });
  assert.strictEqual(el.textContent, 'Prefix: tail',
    'assigned text is CONCATENATED with appended children, never replaced by them');
  console.log('OK assigned text survives a later appendChild');
}

// 2. Real DOM: assigning textContent removes every existing child.
function testSetterReplacesChildren() {
  const el = makeEl('', 'p');
  el.appendChild({ nodeType: 3, textContent: 'old' });
  el.textContent = 'new';
  assert.strictEqual(el.textContent, 'new', 'the setter drops previously appended children');
  assert.strictEqual(el.children.length, 0, 'and really removes them, not just hides them from the getter');
  console.log('OK the setter replaces children, as the real setter does');
}

// 3. textContent is the concatenation of ALL descendant text, at any depth.
function testNestedElementsAggregate() {
  const el = makeEl('', 'p');
  const strong = makeEl('', 'strong');
  strong.appendChild({ nodeType: 3, textContent: '7' });
  el.appendChild({ nodeType: 3, textContent: 'count: ' });
  el.appendChild(strong);
  el.appendChild({ nodeType: 3, textContent: ' rules' });
  assert.strictEqual(el.textContent, 'count: 7 rules', 'text is aggregated through child elements');
  console.log('OK nested element text aggregates');
}

// 4. The innerHTML-parsed mirror is deliberately NOT walked by the getter. The textContent
//    setter writes _html as an ESCAPED copy of what it just stored, so folding that mirror in
//    would return '&lt;img' where the real DOM returns '<img' — and would double the text
//    besides. kept-protection-note.test.js:177 asserts exactly this raw round trip on
//    worker-supplied vendor names, so this boundary is load-bearing, not a convenience.
function testInnerHtmlMirrorStaysExcluded() {
  const el = makeEl('', 'p');
  el.textContent = 'kept (<img src=x onerror=alert(1)>)';
  assert.strictEqual(el.textContent, 'kept (<img src=x onerror=alert(1)>)',
    'assigned text reads back RAW and exactly once — the escaped _html mirror is not folded in');
  assert.ok(/&lt;img/.test(el.innerHTML), 'while innerHTML still shows it entity-escaped');
  console.log('OK the innerHTML mirror stays out of the getter');
}

// 5. KNOWN RESIDUAL — characterization, NOT endorsement.
//    An element written through `innerHTML =` and THEN appendChild'd reports only the appended
//    half through the bare getter, because closing the gap would mean folding in the
//    entity-encoded _dom mirror that test 4 exists to keep out. There is exactly one such node
//    in the plugin (scanner.js:462-464: an innerHTML label plus an appended text node of
//    worker-supplied ids), and it is read correctly through probe-outcome-dialog.test.js's
//    textOf walker, which composes _ownText / _domChildren() / children itself.
//    If you close this gap, change THIS assertion and the harness comment in the same commit —
//    the point of pinning it is that the semantics cannot drift silently, not that the gap is
//    desirable.
function testMixedSinkResidual() {
  const el = makeEl('', 'p');
  el.innerHTML = '<strong>Security stack detected:</strong> ';
  el.appendChild({ nodeType: 3, textContent: 'Cloudflare' });

  assert.strictEqual(el.textContent, 'Cloudflare',
    'documented residual: the bare getter under-reports an innerHTML+appendChild node');
  // The composing walker gets it right, which is why nothing under-reports in practice.
  assert.strictEqual(el._ownText, '', '_ownText is empty when innerHTML wrote the own content');
  assert.strictEqual(el._domChildren().length, 2, 'and the innerHTML half is reachable via _domChildren()');
  console.log('OK the mixed-sink residual is where the harness comment says it is');
}

testAssignedTextSurvivesAnAppend();
testSetterReplacesChildren();
testNestedElementsAggregate();
testInnerHtmlMirrorStaysExcluded();
testMixedSinkResidual();
console.log('harness-textcontent: all assertions passed');
