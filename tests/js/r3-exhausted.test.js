const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');

function run() {
  const h = createHarness();
  const T = h.sandbox.window.__cuTest;
  // Render the banner directly (build path needs Railway; we assert the banner copy + button).
  T.renderPartialBanner({ status: 'paused_exhausted', completed: 4, total: 10,
                          remainder_urls: ['a', 'b', 'c', 'd', 'e', 'f'] });
  const html = h.els['cu-banner-area'].innerHTML;
  assert.ok(/repeatedly rate-limited or blocked/.test(html), 'honest non-429-specific copy');
  assert.ok(/charged for the 4 completed pages/.test(html), 'charged-X copy');
  assert.ok(/Re-queue the remaining 6 pages/.test(html), 'requeue Y-X');
  assert.ok(/id="cu-partial-requeue-btn"/.test(html), 'reuses requeue button id');
  console.log('OK r3-exhausted');
}
run();
