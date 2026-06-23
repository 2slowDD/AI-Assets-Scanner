(function () {
    'use strict';

    const SCANNER_JS_VERSION = '1.0.10.23';
    console.log( '[AI Assets Scanner] scanner.js v' + SCANNER_JS_VERSION + ' loaded' );

    const ajax    = cuScanner.ajaxUrl;
    const nonce   = cuScanner.nonce;
    const siteUrl = cuScanner.siteUrl || window.location.origin;

    // --- State ---
    let discoveredUrls = [];   // full set returned by server
    let discoveryRan   = false; // true once a REAL Discover Pages run completed (distinguishes mixed mode from include-only)
    let selectedUrls   = [];   // checked subset — used for reserve + submit
    let extraTimeUrls = []; // FU-AAS-EXTRA-TIME — URLs the operator marked for Extra Time
    let etCarryOver   = false; // FU-AAS-ET-VIEW-PERSIST — true while showing the post-scan ET carry-over Step-1 view
    let groupedUrls    = {};   // { page: [...], post: [...], other: [...] }
    let activeFilter   = 'all';
    let scanJobId        = null;
    let scanJobToken     = null;
    let railwayUrl       = null;
    let pollTimer        = null;
    let countdownInterval = null;  // R3 Stage C — single live-countdown ticker
    let lastPageIndex    = 0;
    let totalPages       = 0;
    let lastKnownStatus  = null;
    let hasSoftBlocks  = false;
    let includedUrls   = [];   // include URLs not duplicated in discoveredUrls
    let availableBalance = null; // credit balance fetched from detect_plugins response
    let outboxTickTimer  = null; // interval id for outbox polling (null = not ticking)

    const STEP_LABELS = {
        1: 'Step 1 \u2014 Discover Pages',
        2: 'Step 2 \u2014 Reserving Credits\u2026',
        3: 'Step 3 \u2014 Scanning',
        4: 'Step 4 \u2014 Done',
    };

    // --- Utilities ---

    function isExternalUrl(url) {
        try {
            const normalised = /^https?:\/\//i.test(url) ? url : 'https://' + url;
            const urlHost    = new URL(normalised).host.replace(/^www\./, '').toLowerCase();
            const homeHost   = new URL(siteUrl).host.replace(/^www\./, '').toLowerCase();
            return urlHost !== homeHost;
        } catch (_) {
            return false;
        }
    }

    function allSelectedAreExternal() {
        if (selectedUrls.length === 0) return false;
        return selectedUrls.every(isExternalUrl);
    }

    function post(action, data, opts) {
        const form = new FormData();
        form.append('action', action);
        // Send nonce under both field names: legacy handlers read `nonce`
        // (via $this->check()), probe_target_stack reads `_wpnonce`.
        // Same nonce value, same action — sending both is additive and harmless.
        form.append('nonce', nonce);
        form.append('_wpnonce', nonce);
        Object.entries(data || {}).forEach(([k, v]) => {
            appendField(form, k, v);
        });
        const fetchOpts = { method: 'POST', body: form };
        if (opts && opts.signal) fetchOpts.signal = opts.signal;
        return fetch(ajax, fetchOpts).then(r => r.json());
    }

    // Recursively append a value to a FormData using PHP's bracket array syntax,
    // so PHP $_POST sees the structure as a native array.
    //   string/number/bool  → key=value
    //   array               → key[]=v0, key[]=v1, …  (or key[][child]= for objects)
    //   plain object        → key[child1]=v1, key[child2]=v2, …
    //   null/undefined      → skipped (matches today's behavior)
    function appendField(form, key, value) {
        if (value === null || value === undefined) return;
        if (Array.isArray(value)) {
            value.forEach((item, i) => appendField(form, key + '[' + i + ']', item));
            return;
        }
        if (typeof value === 'object') {
            Object.entries(value).forEach(([childKey, childVal]) => {
                appendField(form, key + '[' + childKey + ']', childVal);
            });
            return;
        }
        form.append(key, value);
    }

    function esc(s) {
        // Attribute-safe HTML escape: covers &, <, >, ", '. Round-tripping
        // via textContent → innerHTML only escapes &/</> — the explicit
        // quote/apostrophe replacements protect attribute-context interpolation
        // (e.g. `data-x="${esc(val)}"`).
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function showStep(n) {
        document.querySelectorAll('.cu-step').forEach(el => el.style.display = 'none');
        const el = document.getElementById('step-' + n);
        if (el) el.style.display = 'block';

        // Update step label in header
        const label = document.getElementById('cu-step-label');
        if (label) label.innerHTML = STEP_LABELS[n] || '';

        // Update pips
        for (let i = 1; i <= 4; i++) {
            const pip = document.getElementById('cu-pip-' + i);
            if (!pip) continue;
            pip.className = 'cu-pip';
            if (i < n)      pip.classList.add('is-done');
            else if (i === n) pip.classList.add('is-active');
        }
    }

    // Phase 5 — Class C consent modal duration estimator.
    // Placeholder constant until Settings exposes a `scan_timeout` knob (Phase 6).
    // Empirical Railway scan time ≈ 30-50 seconds per URL across desktop+mobile passes.
    const SCAN_TIME_PER_URL_MINUTES = 0.75;
    function estimateScanMinutes(urlCount) {
        return Math.max(1, Math.ceil(urlCount * SCAN_TIME_PER_URL_MINUTES));
    }

    // Phase 5 — Build the consent dialog DOM tree. Returns a <dialog> element
    // ready to be appended to document.body and shown via showModal().
    // Pure DOM construction; no side effects.
    function buildConsentDialog(classCActive, urlCount) {
        const dialog = document.createElement('dialog');
        dialog.className = 'cu-consent-dialog';

        const heading = document.createElement('h2');
        heading.textContent = 'Temporary plugin pause required';
        dialog.appendChild(heading);

        const intro = document.createElement('p');
        intro.textContent = 'Accurate scanning requires that the following optimizer be paused for the duration of this scan:';
        dialog.appendChild(intro);

        const pluginList = document.createElement('ul');
        pluginList.className = 'cu-consent-plugins';
        for (const entry of classCActive) {
            const li = document.createElement('li');
            const name = document.createElement('strong');
            name.textContent = entry.name || entry.slug || 'Unknown plugin';
            li.appendChild(name);
            const warning = (entry.warning || '').trim();
            if (warning) {
                li.appendChild(document.createTextNode(' — ' + warning));
            }
            pluginList.appendChild(li);
        }
        dialog.appendChild(pluginList);

        const meansHeader = document.createElement('p');
        meansHeader.innerHTML = '<strong>What this means while the scan runs:</strong>';
        dialog.appendChild(meansHeader);

        const minutes = estimateScanMinutes(urlCount);
        const meansList = document.createElement('ul');
        meansList.innerHTML =
            '<li>Your site will load with the un-optimized CSS and JS during the scan window. ' +
            'Estimated duration: <strong>~' + minutes + ' minute' + (minutes === 1 ? '' : 's') + '</strong> (' + urlCount + ' URLs).</li>' +
            '<li>Visitors arriving during this window will see the un-optimized site.</li>' +
            '<li>The plugin’s options will be restored automatically when the scan finishes.</li>';
        dialog.appendChild(meansList);

        const crashNote = document.createElement('p');
        crashNote.innerHTML =
            '<strong>If the scan crashes:</strong> the plugin will still be re-enabled — on the next admin request after the timeout window expires, OR via the watchdog cron job. ' +
            'On a very low-traffic site without OS cron, this fallback may not be immediate.';
        dialog.appendChild(crashNote);

        const auditNote = document.createElement('p');
        auditNote.innerHTML = '<em>Audit trail: every disable and restore is logged in AI Assets Scanner → Logs.</em>';
        dialog.appendChild(auditNote);

        const actions = document.createElement('div');
        actions.className = 'cu-consent-actions';
        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'button';
        cancelBtn.dataset.cuConsent = 'cancel';
        cancelBtn.textContent = 'Cancel';
        const confirmBtn = document.createElement('button');
        confirmBtn.type = 'button';
        confirmBtn.className = 'button button-primary';
        confirmBtn.dataset.cuConsent = 'confirm';
        confirmBtn.textContent = 'Pause and start scan';
        actions.appendChild(cancelBtn);
        actions.appendChild(confirmBtn);
        dialog.appendChild(actions);

        return dialog;
    }

    // Phase 5 — Mount the consent dialog and resolve a Promise with the
    // user's choice. Removes itself from the DOM after resolution.
    function showConsentDialog(classCActive, urlCount) {
        return new Promise(function (resolve) {
            const dialog = buildConsentDialog(classCActive, urlCount);
            document.body.appendChild(dialog);

            let resolved = false;
            function done(value) {
                if (resolved) return;
                resolved = true;
                resolve(value);
                if (dialog.open) dialog.close();
                dialog.remove();
            }

            dialog.querySelector('[data-cu-consent="confirm"]').addEventListener('click', function () {
                done(true);
            });
            dialog.querySelector('[data-cu-consent="cancel"]').addEventListener('click', function () {
                done(false);
            });
            // Native <dialog> doesn't auto-close on backdrop click — emulate it.
            // Click target === dialog itself when the click was on the backdrop padding.
            dialog.addEventListener('click', function (e) {
                if (e.target === dialog) done(false);
            });
            // Escape, programmatic close, anything else that fires the close event.
            dialog.addEventListener('close', function () { done(false); });

            dialog.showModal();
        });
    }

    // FU-NEW-2 Phase 6 — Inline spinner for cu_scanner_probe_target_stack.
    // Returns { hide(), signal } — signal is an AbortSignal wired to a Cancel button
    // so the operator can abort the probe mid-flight (spec §9). Probe typically
    // completes in 1-3s; cancel UX exists for slow upstream cases.
    function showInlineSpinner(message) {
        const host = document.getElementById('cu-probe-spinner-host')
            || (function () {
                const h = document.createElement('div');
                h.id = 'cu-probe-spinner-host';
                // Append to step-1 container so it lives near the scanning UI.
                const step1 = document.getElementById('step-1') || document.body;
                step1.appendChild(h);
                return h;
            })();

        host.innerHTML = '';
        host.className = 'cu-probe-spinner';
        host.style.display = 'flex';

        const spin = document.createElement('span');
        spin.className = 'cu-probe-spinner-icon';
        spin.setAttribute('aria-hidden', 'true');

        const label = document.createElement('span');
        label.className = 'cu-probe-spinner-label';
        label.textContent = message || 'Working…';

        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'button button-secondary cu-probe-spinner-cancel';
        cancelBtn.textContent = 'Cancel';

        host.appendChild(spin);
        host.appendChild(label);
        host.appendChild(cancelBtn);

        const controller = ('AbortController' in window) ? new AbortController() : null;
        cancelBtn.addEventListener('click', function () {
            if (controller) controller.abort();
            host.style.display = 'none';
            host.innerHTML = '';
        });

        return {
            signal: controller ? controller.signal : undefined,
            hide: function () {
                host.style.display = 'none';
                host.innerHTML = '';
            },
        };
    }

    /**
     * FU-NEW-2 Phase 6 — Render an outcome-specific dialog from
     * cu_scanner_probe_target_stack result. Returns Promise<boolean>:
     * true = continue with scan, false = cancel. Dialog content per spec §6.3.
     */
    function showProbeOutcomeDialog(probeData) {
        return new Promise(function (resolve) {
            const dialog = document.createElement('dialog');
            dialog.className = 'cu-probe-outcome-dialog';

            const uniform = !!(probeData.summary && probeData.summary.uniform_outcome);
            const summaryHtml = uniform
                ? buildUniformMessage(probeData.per_host_results || [])
                : buildPerHostList(probeData.per_host_results || []);

            dialog.innerHTML =
                '<div class="cu-probe-dialog-body">' +
                    '<h2>Target site detection</h2>' +
                    summaryHtml +
                    '<p>Continue with scan?</p>' +
                    '<div class="cu-probe-dialog-actions">' +
                        '<button type="button" class="button button-secondary cu-probe-cancel">Cancel</button>' +
                        '<button type="button" class="button button-primary cu-probe-continue">Continue</button>' +
                    '</div>' +
                '</div>';

            let resolved = false;
            function done(value) {
                if (resolved) return;
                resolved = true;
                resolve(value);
                if (dialog.open) dialog.close();
                dialog.remove();
            }

            dialog.querySelector('.cu-probe-cancel').addEventListener('click', function () {
                done(false);
            });
            dialog.querySelector('.cu-probe-continue').addEventListener('click', function () {
                done(true);
            });
            dialog.addEventListener('click', function (e) {
                if (e.target === dialog) done(false);
            });
            dialog.addEventListener('close', function () { done(false); });

            document.body.appendChild(dialog);
            dialog.showModal();
        });
    }

    function buildUniformMessage(results) {
        if (!results.length) return '<p>No probe results.</p>';
        return '<p>' + outcomeMessage(results[0]) + '</p>';
    }

    function buildPerHostList(results) {
        if (!results.length) return '<p>No probe results.</p>';
        const items = results.map(function (r) {
            return '<li>' + outcomeMessage(r) + '</li>';
        }).join('');
        return '<ul class="cu-probe-host-list">' + items + '</ul>';
    }

    function outcomeMessage(r) {
        const host = esc(r.host || 'unknown host');
        switch (r.outcome) {
            case 'class_a_clean':
                return 'Detected ' + listDetected(r.detected) + ' on <strong>' + host + '</strong>.';
            case 'class_bc_only':
                return 'Detected cache plugin <strong>' + listDetected(r.detected) + '</strong> on <strong>' + host
                    + '</strong>. No proper bypass available; results may not be complete. '
                    + '<em>Consider temporarily disabling any bot protection / firewall / CDN that may be blocking the scanner.</em>';
            case 'hybrid_a_plus_bc':
                return 'Detected <strong>' + listDetected(r.detected, ['A', 'A_star']) + '</strong> (will bypass) + '
                    + 'cache plugin <strong>' + listDetected(r.detected, ['B', 'C']) + '</strong> on <strong>' + host
                    + '</strong> (may be busted by the bypass; results may not be complete).';
            case 'no_clue':
                return 'Couldn’t detect target caching stack on <strong>' + host + '</strong> (probed 2 URLs). '
                    + 'Results may not be complete. '
                    + '<em>Consider temporarily disabling any bot protection / firewall / CDN that may be blocking probing.</em>';
            case 'non_wordpress':
                return 'Target <strong>' + host + '</strong> may not be WordPress (no WP signals detected on probed URLs). '
                    + 'Results may not be meaningful.';
            case 'probe_failed':
                return 'Target probe to <strong>' + host + '</strong> failed: ' + esc(r.reason || 'unknown') + '. '
                    + '<em>Consider temporarily disabling any bot protection / firewall / CDN that may be blocking the scanner.</em>';
            default:
                return 'Unknown outcome on <strong>' + host + '</strong>.';
        }
    }

    function listDetected(detected, classFilter) {
        const arr = Array.isArray(detected) ? detected : [];
        const filtered = classFilter
            ? arr.filter(function (d) { return classFilter.indexOf(d.class) !== -1; })
            : arr;
        if (!filtered.length) return 'unknown';
        return filtered.map(function (d) { return esc(d.name || d.slug || 'unknown'); }).join(', ');
    }

    /**
     * FU-AAS-CACHE-STACK-NOTICE-MISSING — surface the detected target cache stack
     * as a passive inline notice on the silent (uniform class_a_clean) probe path.
     * The blocking showProbeOutcomeDialog() only fires when warning_needed=true, so a
     * cleanly-detected stack (suffix applied, no warning) previously showed nothing.
     * Content reuses the same esc()-escaped builders as the dialog. Pass null/empty to clear.
     */
    function renderTargetStackNotice(probeData) {
        const el = document.getElementById('cu-target-stack-notice');
        if (!el) return;
        const results = (probeData && probeData.per_host_results) || [];
        if (!results.length) { el.innerHTML = ''; return; }
        const uniform = !!(probeData.summary && probeData.summary.uniform_outcome);
        const body = uniform ? buildUniformMessage(results) : buildPerHostList(results);
        el.innerHTML = '<div class="notice notice-info inline">'
            + '<p><strong>Target site detection</strong></p>'
            + body
            + '</div>';
    }

    // --- Step 1: Plugin detection ---

    function detectPlugins() {
        post('cu_scanner_detect_plugins').then(res => {
            if (!res.success) return;
            const warnings = document.getElementById('cu-plugin-warnings');
            const d = res.data;
            availableBalance = (typeof d.balance === 'number') ? d.balance : null;
            let html = '';

            // Code Unloader missing: red error notice shown at top
            if (d.cu_missing === true) {
                html += `<div class="notice notice-error"><p><strong>The Code Unloader plugin was not found.</strong> AI Assets Scanner is designed to work with <a href="https://wordpress.org/plugins/code-unloader/" target="_blank" rel="noopener">Code Unloader</a>. Install and activate Code Unloader to be able to unload assets.</p></div>`;
            }

            hasSoftBlocks = Object.keys(d.soft_block || {}).length > 0;

            // Soft-block: full WP notice (user must acknowledge)
            Object.entries(d.soft_block || {}).forEach(([name, reason]) => {
                const id = 'override-' + name.replace(/\s+/g, '-');
                html += `<div class="notice notice-error">
                    <p><strong>${esc(name)}:</strong> ${esc(reason)}</p>
                    <label><input type="checkbox" class="cu-soft-block-override" data-plugin="${esc(name)}" id="${esc(id)}" />
                    I have disabled ${esc(name)} \u2014 proceed anyway</label></div>`;
            });

            // Soft-warn: full WP notice (informational)
            Object.entries(d.soft_warn || {}).forEach(([name, reason]) => {
                html += `<div class="notice notice-warning"><p><strong>${esc(name)}:</strong> ${esc(reason)}</p></div>`;
            });

            // Security-warn: warning notice
            Object.entries(d.security_warn || {}).forEach(([name, data]) => {
                html += `<div class="notice notice-warning"><p><strong>${esc(name)}:</strong> ${esc(data.reason)}</p></div>`;
            });

            // CDN detected: warning notice with deep-link to WAF bypass settings
            if (d.cdn_notice) {
                // Capitalise the CDN slug for display (e.g. "cloudflare" → "Cloudflare")
                const cdnName = esc(d.cdn_notice.name).replace(/^./, c => c.toUpperCase());
                html += `<div class="notice notice-warning"><p><strong>CDN detected (${cdnName})</strong> — set up the scanner rate-limit exemption to avoid 429 errors during scans. <a href="${esc(d.cdn_notice.settings_url)}">Open the Cloudflare WAF Bypass settings</a>.</p></div>`;
            }

            // A1: last scan was rate-limited — attribution-branched pre-scan notice (supersedes cdn_notice for same CDN, server-side)
            if (d.last_scan_throttle) {
                const t = d.last_scan_throttle;
                if (t.kind === 'cdn') {
                    const tName = esc(t.name).replace(/^./, c => c.toUpperCase());
                    html += `<div class="notice notice-warning"><p><strong>Your last scan was rate-limited by ${tName}</strong> — set up the exemption before re-scanning. <a href="${esc(t.settings_url)}">Open the Cloudflare WAF Bypass settings</a>.</p></div>`;
                } else if (t.kind === 'origin') {
                    html += `<div class="notice notice-warning"><p><strong>Your last scan was rate-limited by your origin server</strong> (e.g. Wordfence or host limits). A CDN exemption won't help — temporarily raise or disable rate limiting on your server before scanning.</p></div>`;
                } else {
                    html += `<div class="notice notice-warning"><p><strong>Your last scan hit rate-limiting (429).</strong> If your site is behind a CDN, set up the exemption; otherwise check your origin/server rate limits.</p></div>`;
                }
            }

            // Auto-bypass: compact single-line banner
            Object.keys(d.auto_bypass || {}).forEach(slug => {
                // Derive a readable label from the slug (wp-rocket → WP Rocket, code-unloader → Code Unloader)
                const label = slug.split('-').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
                html += `<div class="cu-bypass-notice">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="12" cy="12" r="10" stroke="#2271b1" stroke-width="2"/>
                        <line x1="12" y1="8" x2="12" y2="12" stroke="#2271b1" stroke-width="2" stroke-linecap="round"/>
                        <circle cx="12" cy="16" r="1" fill="#2271b1"/>
                    </svg>
                    <strong>${esc(label)}</strong> \u2014 temporary bypass applied.
                </div>`;
            });

            warnings.innerHTML = html;

            if (hasSoftBlocks) {
                updateStartScanGate();
                warnings.addEventListener('change', updateStartScanGate);
            }
        });
    }

    function updateStartScanGate() {
        const overrides  = document.querySelectorAll('.cu-soft-block-override');
        const allChecked = Array.from(overrides).every(cb => cb.checked);
        ['cu-btn-next-1', 'cu-btn-next-1-top'].forEach(function (id) {
            const btn = document.getElementById(id);
            if (btn) btn.disabled = !allChecked;
        });
    }

    // --- Include URLs helpers ---

    function getIncludedUrls() {
        const el = document.getElementById('cu-included-urls');
        if (!el) return [];
        return el.value.split('\n').map(u => u.trim()).filter(u => u.length > 0);
    }

    function normaliseUrl(u) {
        // Strip trailing slashes and lowercase for dedup comparison
        return u.replace(/\/+$/, '').toLowerCase();
    }

    function updateStartScanVisibility() {
        const hasIncluded   = getIncludedUrls().length > 0;
        const hasDiscovered = discoveredUrls.length > 0;
        const disp = (hasIncluded || hasDiscovered) ? '' : 'none';
        ['cu-btn-next-1', 'cu-btn-next-1-top'].forEach(function (id) {
            const btn = document.getElementById(id);
            if (btn) btn.style.display = disp;
        });
    }

    function syncIncludedUrls() {
        // Only merges when discovery has already run. Include-only path is
        // handled directly in the Start Scan click handler.
        if (discoveredUrls.length === 0) return;

        const raw = getIncludedUrls();
        const discoveredSet = new Set(discoveredUrls.map(normaliseUrl));

        // URLs in the include list that are NOT already discovered (deduped within list too)
        const seen = new Set();
        const newIncluded = raw.filter(u => {
            const n = normaliseUrl(u);
            if (seen.has(n) || discoveredSet.has(n)) return false;
            seen.add(n);
            return true;
        });

        // Remove previously-tracked included URLs from selectedUrls
        const oldSet = new Set(includedUrls.map(normaliseUrl));
        selectedUrls = selectedUrls.filter(u => !oldSet.has(normaliseUrl(u)));

        // Add newly-included URLs to selectedUrls
        selectedUrls = [...selectedUrls, ...newIncluded];

        includedUrls = newIncluded;

        // 1.4.2 fix — only set the `included` marker when there are actual include URLs.
        // Pre-1.4.2 this line unconditionally set `groupedUrls.included = []` even on the
        // post-Discover sync (line 546) when the textarea was empty, which made the Start
        // Scan handler's `groupedUrls.included !== undefined` predicate (the FU-NEW-6
        // include-only-mode marker, line 841) wrongly TRUE — silent no-op on every
        // Discover→unselect→select→Scan flow with an empty Include URLs textarea.
        if ( newIncluded.length > 0 ) {
            groupedUrls.included = newIncluded;
        } else {
            delete groupedUrls.included;
        }
    }

    // --- Step 1: Discovery ---

    document.getElementById('cu-included-urls').addEventListener('input', function () {
        if (discoveredUrls.length === 0) {
            selectedUrls = getIncludedUrls();
            totalPages   = selectedUrls.length;
            updateCreditBadge();
        } else {
            syncIncludedUrls();
            renderUrlList();
            updateCreditBadge();
        }
        updateStartScanVisibility();
    });

    document.getElementById('cu-btn-discover').addEventListener('click', function () {
        // Capture button ref — `this` is not available inside .then() in strict mode
        const discoverBtn = this;

        // Show sonar animation, hide button row
        discoverBtn.style.display = 'none';
        document.getElementById('cu-btn-next-1').style.display = 'none';
        document.getElementById('cu-url-list-area').style.display = 'none';
        document.getElementById('cu-sonar-anim').style.display = 'flex';

        post('cu_scanner_discover_pages', {
            excluded_urls: document.getElementById('cu-excluded-urls').value.split('\n').filter(Boolean),
        }).then(res => {
            // Hide sonar anim
            document.getElementById('cu-sonar-anim').style.display = 'none';
            // Restore discover button (now labelled Re-discover)
            discoverBtn.style.display = '';
            discoverBtn.textContent = 'Re-discover';

            if (!res.success) { alert('Discovery failed: ' + res.data); return; }

            // Initialise state from response — selectedUrls reset here, before any render
            discoveredUrls = res.data.urls;
            groupedUrls    = res.data.groups || { page: [], post: [], other: [] };
            selectedUrls   = discoveredUrls.slice(); // copy — all selected by default
            totalPages     = discoveredUrls.length;
            activeFilter   = 'all';
            discoveryRan   = true; // a real discovery completed — mixed-mode include URLs now MERGE, not replace
            clearEtCarryOver();    // FU-AAS-ET-VIEW-PERSIST — a fresh discovery exits the ET carry-over view
            sessionStorage.removeItem('cu_scanner_rescan_requeue'); // clear stale "Scan again" dormant-origin flag on re-discover (parity with clearEtCarryOver)

            syncIncludedUrls();
            renderUrlList();
            updateCreditBadge();

            document.getElementById('cu-url-list-area').style.display = 'block';
            updateStartScanVisibility();
        });
    });

    // --- URL list rendering ---

    const GROUP_META = {
        page:     { label: 'Pages',    cls: 'cu-group-header--page',     more: 'pages' },
        post:     { label: 'Posts',    cls: 'cu-group-header--post',     more: 'posts' },
        other:    { label: 'Other',    cls: 'cu-group-header--other',    more: '' },
        included: { label: 'Included', cls: 'cu-group-header--included', more: 'included' },
    };

    function renderUrlList() {
        const list = document.getElementById('cu-url-list');
        list.innerHTML = '';

        // Update filter pill counts and visibility
        ['page', 'post', 'other', 'included'].forEach(type => {
            const pill = document.getElementById('cu-pill-' + type);
            const count = (groupedUrls[type] || []).length;
            if (pill) {
                pill.style.display = count > 0 ? '' : 'none';
                pill.textContent = GROUP_META[type].label + ' ' + count;
            }
        });
        const allPill = document.getElementById('cu-pill-all');
        if (allPill) allPill.textContent = 'All ' + totalPages;

        // Render each group
        ['page', 'post', 'other', 'included'].forEach(type => {
            const urls = groupedUrls[type] || [];
            if (urls.length === 0) return;

            const meta = GROUP_META[type];
            const visible = (activeFilter === 'all' || activeFilter === type);

            const groupDiv = document.createElement('div');
            groupDiv.dataset.groupType = type;
            groupDiv.style.display = visible ? '' : 'none';

            // Group header
            const header = document.createElement('div');
            header.className = 'cu-group-header ' + meta.cls;
            header.innerHTML = `
                <label>
                    <input type="checkbox" class="cu-group-cb" data-type="${esc(type)}" checked>
                    ${meta.label} <span class="cu-group-count">${urls.length}</span>
                </label>
                <button class="cu-group-toggle-link" data-type="${esc(type)}">deselect all ${meta.label.toLowerCase()}</button>
            `;
            groupDiv.appendChild(header);

            // URL rows (first 20 visible, rest hidden)
            urls.forEach((url, idx) => {
                const row = document.createElement('div');
                row.className = 'cu-url-row';
                row.dataset.url = url;
                row.dataset.type = type;
                const isChecked = selectedUrls.includes(url);
                if (!isChecked) row.classList.add('is-deselected');
                const badge = type === 'included' ? ' <span class="cu-included-badge">[included]</span>' : '';
                row.innerHTML = `<input type="checkbox" class="cu-row-cb" data-url="${esc(url)}" data-type="${esc(type)}"${isChecked ? ' checked' : ''}>
                    <span class="cu-url-text">${esc(url)}</span>${badge}
                    <label class="cu-et-label"><input type="checkbox" class="cu-et-cb" data-url="${esc(url)}"${extraTimeUrls.includes(url) ? ' checked' : ''}>
                    Extra Time</label><span class="cu-help" tabindex="0" aria-label="Extra Time gives the worker more time on this URL — likely more unloads — and costs an additional credit."><span class="cu-help-box">Extra Time means more time for the worker to go through this URL, but it costs an additional credit.</span></span>`;
                row.style.display = idx < 20 ? '' : 'none';
                groupDiv.appendChild(row);
            });

            // Overflow expand link
            if (urls.length > 20) {
                const more = document.createElement('div');
                more.className = 'cu-url-more';
                const label = meta.more ? `more ${meta.more}` : 'more';
                more.textContent = `\u2026 and ${urls.length - 20} ${label}`;
                more.addEventListener('click', function () {
                    groupDiv.querySelectorAll('.cu-url-row').forEach(r => r.style.display = '');
                    this.remove();
                });
                groupDiv.appendChild(more);
            }

            list.appendChild(groupDiv);
        });

        // Bind group header events
        list.querySelectorAll('.cu-group-cb').forEach(cb => {
            cb.addEventListener('change', onGroupCheckboxChange);
        });
        list.querySelectorAll('.cu-group-toggle-link').forEach(btn => {
            btn.addEventListener('click', onGroupToggleLinkClick);
        });
        list.querySelectorAll('.cu-row-cb').forEach(cb => {
            cb.addEventListener('change', onRowCheckboxChange);
        });
        list.querySelectorAll('.cu-et-cb').forEach(cb => { cb.addEventListener('change', onEtRowCheckboxChange); });
    }

    // --- Checkbox logic ---

    function onGroupCheckboxChange(e) {
        const type    = e.target.dataset.type;
        const checked = e.target.checked;
        const urls    = groupedUrls[type] || [];

        // Check/uncheck all rows in this group
        document.querySelectorAll(`.cu-row-cb[data-type="${type}"]`).forEach(cb => {
            cb.checked = checked;
            cb.closest('.cu-url-row').classList.toggle('is-deselected', !checked);
        });

        // Rebuild selectedUrls for this group
        selectedUrls = selectedUrls.filter(u => !urls.includes(u));
        if (checked) selectedUrls = selectedUrls.concat(urls);

        updateGroupToggleLink(type);
        updateCreditBadge();
    }

    function onGroupToggleLinkClick(e) {
        const type = e.target.dataset.type;
        const urls  = groupedUrls[type] || [];
        const anyChecked = urls.some(u => selectedUrls.includes(u));
        const willCheck  = !anyChecked; // if any are checked → deselect all; if none → select all

        document.querySelectorAll(`.cu-row-cb[data-type="${type}"]`).forEach(cb => {
            cb.checked = willCheck;
            cb.closest('.cu-url-row').classList.toggle('is-deselected', !willCheck);
        });

        selectedUrls = selectedUrls.filter(u => !urls.includes(u));
        if (willCheck) selectedUrls = selectedUrls.concat(urls);

        updateGroupCheckbox(type);
        updateGroupToggleLink(type);
        updateCreditBadge();
    }

    function onRowCheckboxChange(e) {
        const url     = e.target.dataset.url;
        const checked = e.target.checked;
        e.target.closest('.cu-url-row').classList.toggle('is-deselected', !checked);

        if (checked) {
            if (!selectedUrls.includes(url)) selectedUrls.push(url);
        } else {
            selectedUrls = selectedUrls.filter(u => u !== url);
        }

        updateGroupCheckbox(e.target.dataset.type);
        updateGroupToggleLink(e.target.dataset.type);
        updateCreditBadge();
    }

    function onEtRowCheckboxChange(e) {
        const url = e.target.dataset.url;
        if (e.target.checked) { if (!extraTimeUrls.includes(url)) extraTimeUrls.push(url); }
        else { extraTimeUrls = extraTimeUrls.filter(u => u !== url); }
        updateCreditBadge();
    }

    // FU-AAS-ET-VIEW-PERSIST — snapshot/clear the post-scan ET carry-over view so it survives
    // WP-admin navigation (mirrors the Step-4 cu_scanner_result restore). saveEtCarryOver()
    // no-ops outside that view (etCarryOver gate), so it is safe to call from updateCreditBadge().
    function saveEtCarryOver() {
        if (!etCarryOver) return;
        try {
            localStorage.setItem('cu_scanner_et_carry_over', JSON.stringify({
                discoveredUrls: discoveredUrls,
                groupedUrls:    groupedUrls,
                selectedUrls:   selectedUrls,
                extraTimeUrls:  extraTimeUrls,
                etCarriedUrls:  etCarriedUrls, // FU-AAS-SUFFIX-DROP-ON-RESOLVE
            }));
        } catch (_e) {}
    }
    function clearEtCarryOver() {
        etCarryOver = false;
        etCarriedUrls = []; // FU-AAS-SUFFIX-DROP-ON-RESOLVE — leaving the carry-over view restores normal resolution
        try { localStorage.removeItem('cu_scanner_et_carry_over'); } catch (_e) {}
    }

    function updateGroupCheckbox(type) {
        const urls    = groupedUrls[type] || [];
        const cb      = document.querySelector(`.cu-group-cb[data-type="${type}"]`);
        if (!cb) return;
        const selectedInGroup = urls.filter(u => selectedUrls.includes(u)).length;
        if (selectedInGroup === 0) {
            cb.checked = false;
            cb.indeterminate = false;
        } else if (selectedInGroup === urls.length) {
            cb.checked = true;
            cb.indeterminate = false;
        } else {
            cb.checked = false;
            cb.indeterminate = true;
        }
    }

    function updateGroupToggleLink(type) {
        const btn = document.querySelector(`.cu-group-toggle-link[data-type="${type}"]`);
        if (!btn) return;
        const urls = groupedUrls[type] || [];
        const label = GROUP_META[type].label.toLowerCase();
        const anySelected = urls.some(u => selectedUrls.includes(u));
        btn.textContent = anySelected ? `deselect all ${label}` : `select all ${label}`;
    }

    // --- Filter pills ---

    document.getElementById('cu-filter-bar').addEventListener('click', function (e) {
        const pill = e.target.closest('.cu-filter-pill');
        if (!pill) return;

        if (pill.id === 'cu-btn-et-all')  { setAllExtraTimeInFilter(true);  return; }
        if (pill.id === 'cu-btn-et-none') { setAllExtraTimeInFilter(false); return; }

        if (pill.id === 'cu-btn-select-all') {
            setAllInFilter(true);
            return;
        }
        if (pill.id === 'cu-btn-deselect-all') {
            setAllInFilter(false);
            return;
        }

        const filter = pill.dataset.filter;
        if (!filter) return;
        activeFilter = filter;

        // Update pill active state
        document.querySelectorAll('.cu-filter-pill[data-filter]').forEach(p => {
            p.classList.toggle('is-active', p.dataset.filter === activeFilter);
        });

        // Show/hide groups
        document.querySelectorAll('#cu-url-list [data-group-type]').forEach(g => {
            const t = g.dataset.groupType;
            g.style.display = (activeFilter === 'all' || activeFilter === t) ? '' : 'none';
        });
    });

    function setAllInFilter(checked) {
        // Operate on currently visible groups
        const types = activeFilter === 'all'
            ? ['page', 'post', 'other', 'included']
            : [activeFilter];

        types.forEach(type => {
            const urls = groupedUrls[type] || [];
            document.querySelectorAll(`.cu-row-cb[data-type="${type}"]`).forEach(cb => {
                cb.checked = checked;
                cb.closest('.cu-url-row').classList.toggle('is-deselected', !checked);
            });
            selectedUrls = selectedUrls.filter(u => !urls.includes(u));
            if (checked) selectedUrls = selectedUrls.concat(urls);
            updateGroupCheckbox(type);
            updateGroupToggleLink(type);
        });
        updateCreditBadge();
    }

    function setAllExtraTimeInFilter(on) {
        // Mirror setAllInFilter — operate on the same URL set the active filter covers.
        const types = activeFilter === 'all'
            ? ['page', 'post', 'other', 'included']
            : [activeFilter];

        types.forEach(type => {
            const urls = groupedUrls[type] || [];
            // The ET checkbox carries data-url (not data-type); scope by URL membership
            // in this group — the same set setAllInFilter operates on.
            document.querySelectorAll('.cu-et-cb').forEach(cb => {
                if (urls.includes(cb.dataset.url)) cb.checked = on;
            });
            extraTimeUrls = extraTimeUrls.filter(u => !urls.includes(u));
            if (on) extraTimeUrls = extraTimeUrls.concat(urls);
        });
        updateCreditBadge();
    }

    // --- Credit badge ---

    function updateCreditBadge() {
        saveEtCarryOver(); // FU-AAS-ET-VIEW-PERSIST — persists the ET carry-over view (no-op otherwise)
        const badge      = document.getElementById('cu-credit-badge');
        const notice     = document.getElementById('cu-bot-notice');
        const numEl      = document.getElementById('cu-credit-num');
        const desEl      = document.getElementById('cu-credit-deselected');
        const selected   = selectedUrls.length;
        const etCount    = extraTimeUrls.filter(u => selectedUrls.includes(u)).length; // only count ET on SELECTED URLs
        const totalCredits = selected + etCount;
        const total      = discoveredUrls.length + includedUrls.length;
        const deselected = total - selected;

        if (!badge) return;
        badge.style.display = '';
        if (notice) notice.style.display = '';
        numEl.textContent = totalCredits;

        if (deselected > 0) {
            desEl.textContent = `(${deselected} deselected)`;
            desEl.style.display = '';
        } else {
            desEl.style.display = 'none';
        }

        const balBadge = document.getElementById('cu-balance-badge');
        const balNumEl = document.getElementById('cu-balance-num');
        if (balBadge && balNumEl) {
            if (availableBalance !== null) {
                balNumEl.textContent = availableBalance;
                balBadge.style.display = '';
                if (availableBalance < totalCredits) {
                    balBadge.classList.add('cu-credit-badge--low');
                } else {
                    balBadge.classList.remove('cu-credit-badge--low');
                }
            } else {
                balBadge.style.display = 'none';
                balBadge.classList.remove('cu-credit-badge--low');
            }
        }
    }

    // --- Phase O: Outbox banner + tick ---

    /**
     * Show a persistent "queued locally" banner in the step-3 scan-status area.
     * Mirrors the showQueueBanner() DOM pattern (notice notice-info inline, inserted
     * before the progress bar) so it uses the same markup conventions.
     */
    function showOutboxBanner() {
        showStep(3);
        let banner = document.getElementById('cu-outbox-banner');
        if (!banner) {
            banner = document.createElement('div');
            banner.id = 'cu-outbox-banner';
            banner.className = 'notice notice-info inline';
            banner.style.marginTop = '10px';
            const progressBar = document.getElementById('cu-progress-bar');
            if (progressBar && progressBar.parentNode) {
                progressBar.parentNode.insertBefore(banner, progressBar);
            }
        }
        banner.innerHTML = '<p><strong>Backend temporarily unavailable</strong> — your scan is queued locally and will dispatch automatically when the backend is reachable.</p>';
        banner.style.display = '';
        const pb = document.getElementById('cu-progress-bar');
        if (pb) pb.style.display = 'none';
        const pt = document.getElementById('cu-progress-text');
        if (pt) pt.style.display = 'none';
    }

    function hideOutboxBanner() {
        const banner = document.getElementById('cu-outbox-banner');
        if (banner) banner.style.display = 'none';
    }

    /**
     * Poll cu_scanner_outbox_tick every 30 s.
     * Terminal states (dispatched / failed / none) stop the interval.
     * Guard: does nothing if an interval is already running.
     */
    function startOutboxTick() {
        if (outboxTickTimer !== null) return; // already ticking
        outboxTickTimer = setInterval(function () {
            post('cu_scanner_outbox_tick', {}).then(function (res) {
                if (!res.success) return; // server error — keep ticking
                const d = res.data || {};
                const state = d.state || 'none';

                if (state === 'queued') {
                    // Still waiting — keep the banner, keep ticking.
                    // Optionally show next-attempt time if provided.
                    return;
                }

                // Terminal state — stop polling.
                clearInterval(outboxTickTimer);
                outboxTickTimer = null;

                if (state === 'dispatched') {
                    hideOutboxBanner();
                    scanJobId     = d.job_id    || null;
                    scanJobToken  = d.job_token  || null;
                    railwayUrl    = d.railway_url || null;
                    lastPageIndex = 0;
                    sessionStorage.setItem('cu_scanner_active_job', JSON.stringify({
                        job_id:      scanJobId,
                        job_token:   scanJobToken,
                        railway_url: railwayUrl,
                    }));
                    showStep(3);
                    startPolling();
                } else if (state === 'failed') {
                    hideOutboxBanner();
                    showStep(1);
                    alert('Scan failed: ' + esc(d.message || 'Unknown error. Please try again.'));
                } else {
                    // state === 'none' — nothing queued (e.g. cleared externally).
                    hideOutboxBanner();
                    showStep(1);
                }
            });
        }, 30000);
    }

    /**
     * Helper: build the outbox intent payload from the current scan state.
     * Must include the same fields that cu_scanner_submit_job sends so the
     * server's intent_from_post() can reconstruct the scan.
     */
    // All handler-local values (bypassPerUrl, stackSummary, consentGiven) are passed in
    // as parameters: this function lives at IIFE scope and must NOT read the `let`s that
    // are block-scoped to the Step-2 click handler (doing so throws ReferenceError under
    // 'use strict'). Only selectedUrls / resolvedByUrl are genuinely IIFE-scope here.
    function buildOutboxPayload( pageCount, etCount, etSelected, jobToken, bypassPerUrl, stackSummary, consentGiven ) {
        const payload = {
            urls:                  selectedUrls.map(u => resolvedByUrl[u] || u),
            submitted_urls:        selectedUrls,
            extra_time_urls:       etSelected.map(u => resolvedByUrl[u] || u),
            target_bypass_per_url: bypassPerUrl,
            target_stack_summary:  stackSummary,
            page_count:            pageCount,
            extra_time_count:      etCount,
            class_c_consent_given: consentGiven || '',
        };
        if (jobToken) {
            payload.job_token = jobToken;
        }
        return payload;
    }

    // Group C: surface a reserve/submit error. A `scan_already_active` (409 from the gate
    // or SaaS reserve) gets the server's friendly account-busy message verbatim — no
    // "Error:" prefix — since it's an expected state, not a fault. Everything else keeps
    // the "Error:" prefix.
    function submitErrorAlert(data, msg) {
        if (data && data.error === 'scan_already_active') {
            alert(msg);
        } else {
            alert('Error: ' + msg);
        }
    }

    // --- Step 2: Reserve + Submit ---

    // Top "Start Scan" button (above the URL list) mirrors the bottom one —
    // delegate to the same handler so there is a single submit path.
    (function () {
        var topBtn = document.getElementById('cu-btn-next-1-top');
        if (topBtn) topBtn.addEventListener('click', function () {
            document.getElementById('cu-btn-next-1').click();
        });
    })();

    document.getElementById('cu-btn-next-1').addEventListener('click', async function () {
        // Clear any prior scan's target-stack notice so it never lingers into a
        // warning-path or no-external scan (FU-AAS-CACHE-STACK-NOTICE-MISSING).
        renderTargetStackNotice(null);
        // Mode is keyed on `discoveryRan` (set true only by a completed Discover Pages
        // run), NOT on `groupedUrls.included`. syncIncludedUrls() sets `included` whenever
        // ANY include URL exists, so the old `groupedUrls.included !== undefined` marker
        // mis-flagged MIXED mode (discovery pages selected + an external include URL) as
        // include-only and REPLACED the selected discovery pages with just the include URL
        // — operator-reported: only the external URL got scanned. Mixed mode now merges.
        const isIncludeOnlyMode = !discoveryRan;
        if (isIncludeOnlyMode) {
            // No real discovery — (re-)read the textarea so a 2nd+ Start Scan click in the
            // same page session submits the current URLs, not a prior scan's (FU-NEW-6 rev 2).
            const includeList = getIncludedUrls();
            if (includeList.length === 0) return; // nothing to scan
            selectedUrls     = includeList;
            discoveredUrls   = includeList;
            groupedUrls      = { page: [], post: [], other: [], included: includeList };
            totalPages       = includeList.length;
        } else {
            // Mixed / Discover mode: merge the include-URL textarea into the selected
            // discovery pages so BOTH are scanned (union). syncIncludedUrls() keeps the
            // checked discovery pages on selectedUrls and adds the include URLs.
            syncIncludedUrls();
            totalPages = selectedUrls.length;
        }

        // FU-NEW-2 Phase 6 — target-stack-aware bypass routing for external URLs.
        // Replaces the simple external-URL confirm() with a probe + outcome-specific dialog.
        // Probe runs BEFORE cu_scanner_reserve_job — does NOT consume credit by construction.
        const externalUrls = selectedUrls.filter(isExternalUrl);
        let targetBypassPerUrl = {};
        let targetStackSummary = null;
        // AC-RC-8a — per-URL resolved-URL map (mirrors targetBypassPerUrl). Populated
        // from the probe response; maps each submitted URL to its post-redirect resolved
        // URL (or itself when no redirect). We scan the resolved URL but carry the
        // original submitted URL through to the server for honest attribution.
        // NOTE: declared at IIFE scope (see resolvedByUrl declaration near cuUrlListState) so
        // renderResultUrlListPage() — a sibling fn — can read it. Reset (not redeclared) per scan.
        resolvedByUrl = {};

        if (externalUrls.length > 0) {
            // 1.3.4 (2026-05-16) — Pre-probe external-URL safety gate (restores the
            // pre-FU-NEW-2 confirm before the probe AJAX hits the external site).
            const uniqueHosts = [...new Set(externalUrls.map(function (u) {
                try { return new URL(u).host; } catch (e) { return u; }
            }))];
            const hostLabel = uniqueHosts.length === 1 ? 'host' : 'hosts';
            if (!window.confirm(
                'You\'re about to scan ' + externalUrls.length + ' URL(s) on external '
                + hostLabel + ': ' + uniqueHosts.join(', ') + '.\n\n'
                + 'External sites may have firewalls or CDNs that affect scanning. Continue?'
            )) {
                return;
            }

            const spinnerCtl = showInlineSpinner('Detecting target stack…');
            let probeResult;
            try {
                probeResult = await post(
                    'cu_scanner_probe_target_stack',
                    { urls: externalUrls },
                    { signal: spinnerCtl.signal }
                );
            } catch (err) {
                spinnerCtl.hide();
                if (err && err.name === 'AbortError') return; // operator cancelled spinner
                alert('Target stack probe error: ' + (err && err.message ? err.message : String(err)));
                return;
            }
            spinnerCtl.hide();
            if (!probeResult || !probeResult.success) {
                const errMsg = probeResult && probeResult.data
                    ? (typeof probeResult.data === 'string' ? probeResult.data : JSON.stringify(probeResult.data))
                    : (probeResult && probeResult.error ? probeResult.error : 'unknown error');
                alert('Probe failed: ' + errMsg);
                return;
            }
            targetBypassPerUrl = probeResult.data.suggested_bypass_per_url || {};
            targetStackSummary = probeResult.data.per_host_results || [];
            // AC-RC-8a — store resolved_url per submitted URL (default to identity).
            const respResolved = probeResult.data.resolved_per_url || {};
            externalUrls.forEach(function (submittedUrl) {
                resolvedByUrl[submittedUrl] = respResolved[submittedUrl] || submittedUrl;
            });

            if (probeResult.data.warning_needed) {
                const userConfirmed = await showProbeOutcomeDialog(probeResult.data);
                if (!userConfirmed) return;
            } else {
                // Uniform class_a_clean: no blocking dialog, but surface WHICH stack
                // was detected as a passive inline notice (FU-AAS-CACHE-STACK-NOTICE-MISSING).
                renderTargetStackNotice(probeResult.data);
            }
        }

        // FU-AAS-SUFFIX-DROP-ON-RESOLVE — carried-over ET URLs are scanned byte-identically;
        // resolution fires only on a URL's first scan (operator directive 2026-06-11). Identity
        // entries are inert downstream: the submission maps yield u unchanged and the
        // "← resolved from" note has a resolved !== submitted guard.
        etCarriedUrls.forEach(function (u) { resolvedByUrl[u] = u; });

        showStep(2);
        // Scroll to the top so the operator sees the scanning progress UI
        // instead of being stuck at the bottom of the long URL selection list.
        window.scrollTo({ top: 0, behavior: 'smooth' });
        // FU-AAS-EXTRA-TIME — only ET URLs that are actually selected count toward billing/payload.
        const etSelected = extraTimeUrls.filter(u => selectedUrls.includes(u));
        // Use selectedUrls.length — only charge for URLs that will actually be scanned.
        // extra_time_count drives the SaaS reserve gate (N pages + M extra-time = N+M credits).
        const pageCount = selectedUrls.length;
        const etCount   = etSelected.length;
        // Class C optimizer-disable consent: '' until the user confirms the consent
        // dialog (then '1'). Carried into the outbox intent so a cron/closed-tab replay
        // of a consented Class-C scan isn't terminal-failed for "missing consent".
        let classCConsentGiven = '';

        // Helper: route a retryable outbox failure. Enqueues the scan intent
        // and shows the queued-locally banner + starts the 30 s tick poll.
        // jobToken: pass the reserve token when available (post-reserve failure),
        // or omit/null when the reserve itself failed (token not yet issued).
        // targetBypassPerUrl / targetStackSummary / classCConsentGiven come from THIS
        // handler's scope (routeToOutbox is nested here) and are passed as params —
        // buildOutboxPayload is at IIFE scope and cannot read these block-scoped lets.
        function routeToOutbox( jobToken ) {
            post('cu_scanner_outbox_enqueue', buildOutboxPayload( pageCount, etCount, etSelected, jobToken, targetBypassPerUrl, targetStackSummary, classCConsentGiven ))
                .then(function () {
                    showOutboxBanner();
                    startOutboxTick();
                });
        }

        post('cu_scanner_reserve_job', { page_count: pageCount, extra_time_count: etCount })
            .then(res => {
                if (!res.success) {
                    // res.data is now {message, retryable} — defensive: handle legacy string too.
                    const retryable = res.data && res.data.retryable === true;
                    const msg       = (res.data && res.data.message) ? res.data.message : res.data;
                    if (retryable) {
                        routeToOutbox( null );
                    } else {
                        showStep(1);
                        submitErrorAlert(res.data, msg);
                    }
                    return;
                }
                const job_token = res.data.job_token;
                post('cu_scanner_submit_job', {
                    // AC-RC-8a — scan the resolved URL, carry the original submitted URL.
                    // submitted_urls[] is index-aligned with urls[] (both mapped from the
                    // same selectedUrls array in the same order).
                    urls: selectedUrls.map(u => resolvedByUrl[u] || u),
                    submitted_urls: selectedUrls,
                    job_token,
                    extra_time_urls: etSelected.map(u => resolvedByUrl[u] || u),
                    target_bypass_per_url: targetBypassPerUrl,
                    target_stack_summary: targetStackSummary,
                })
                    .then(async res2 => {
                        // Phase 5 — Class C consent gate.
                        // submit_job returns class_c_consent_required when Class C optimizers
                        // are active and the user hasn't yet confirmed. Render the modal,
                        // retry on confirm, fall back to existing failure path on cancel.
                        if (!res2.success && res2.data && res2.data.error === 'class_c_consent_required') {
                            const consented = await showConsentDialog(
                                res2.data.class_c_active || [],
                                selectedUrls.length
                            );
                            if (!consented) {
                                post('cu_scanner_handle_failure');
                                showStep(1);
                                return;
                            }
                            classCConsentGiven = '1'; // carry consent into any later outbox enqueue (incl. a retry network-failure)
                            const retry = await post('cu_scanner_submit_job', {
                                // AC-RC-8a — same resolved/submitted threading as above.
                                urls: selectedUrls.map(u => resolvedByUrl[u] || u),
                                submitted_urls: selectedUrls,
                                job_token: job_token,
                                class_c_consent_given: '1',
                                extra_time_urls: etSelected.map(u => resolvedByUrl[u] || u),
                                target_bypass_per_url: targetBypassPerUrl,
                                target_stack_summary: targetStackSummary,
                            });
                            if (!retry.success) {
                                // res.data is now {message, retryable} — defensive: handle legacy string too.
                                const retryable = retry.data && retry.data.retryable === true;
                                const msg       = (retry.data && retry.data.message) ? retry.data.message : retry.data;
                                post('cu_scanner_handle_failure');
                                if (retryable) {
                                    routeToOutbox( job_token );
                                } else {
                                    showStep(1);
                                    submitErrorAlert(retry.data, msg);
                                }
                                return;
                            }
                            res2 = retry;
                        }
                        if (!res2.success) {
                            // res.data is now {message, retryable} — defensive: handle legacy string too.
                            const retryable = res2.data && res2.data.retryable === true;
                            const msg       = (res2.data && res2.data.message) ? res2.data.message : res2.data;
                            post('cu_scanner_handle_failure');
                            if (retryable) {
                                routeToOutbox( job_token );
                            } else {
                                showStep(1);
                                submitErrorAlert(res2.data, msg);
                            }
                            return;
                        }
                        scanJobId     = res2.data.job_id;
                        scanJobToken  = res2.data.job_token;
                        railwayUrl    = res2.data.railway_url;
                        lastPageIndex = 0;
                        // FU-AAS-YELLOW-S0A0-ROWS — a "Scan again" rescan reuses the 429 dormant
                        // button state: mark this job as a re-queue so restoreStep4 disables Push
                        // (Sync-only) when CU rules already exist. Mirrors reQueueRemainder (:1832).
                        if ( sessionStorage.getItem('cu_scanner_rescan_requeue') ) {
                            localStorage.setItem('cu_scanner_requeue_' + scanJobId, '1');
                            sessionStorage.removeItem('cu_scanner_rescan_requeue');
                        }
                        sessionStorage.setItem( 'cu_scanner_active_job', JSON.stringify({
                            job_id:      scanJobId,
                            job_token:   scanJobToken,
                            railway_url: railwayUrl,
                        }) );
                        clearEtCarryOver(); // FU-AAS-ET-VIEW-PERSIST — scan started; resume Step 3 on return, not the ET view
                        beginScanPolling();
                    })
                    .catch(() => {
                        // Network error on submit — retryable (canonical outage case).
                        post('cu_scanner_handle_failure');
                        routeToOutbox( job_token );
                    });
            })
            .catch(() => {
                // Network error on reserve — retryable (canonical outage case).
                routeToOutbox( null );
            });
    });

    // --- Step 3: Polling + Progress ---

    function startPolling() {
        pollProgress(); // poll immediately, then self-schedule
    }

    // beginScanPolling() — called on new-scan-start submit paths ONLY (main submit + reQueueRemainder).
    // Clears the Step-3 URL table so rows from a previous (longer) scan don't linger, then starts polling.
    // Do NOT call from the resume-after-reload path (cu_scanner_check_job / restoreOutboxState) —
    // those paths legitimately repopulate the table from the worker's pages[] array.
    function beginScanPolling() {
        document.getElementById('cu-pages-tbody').innerHTML = '';
        showStep(3);
        startPolling();
    }

    function stopPolling() {
        if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
    }

    function scheduleNextPoll() {
        // 10s while queued (no active scan work), 2s once in_progress
        const interval = lastKnownStatus === 'queued' ? 10000 : 2000;
        pollTimer = setTimeout(pollProgress, interval);
    }

    var PAUSED_COUNTDOWN_TICK_MS = 1000, PAUSED_POLL_FLOOR_MS = 15000,
        PAUSED_POLL_BUFFER_MS = 3000, PAUSED_CATCHUP_MS = 10000;
    var pausedResumeAt = 0;   // latest resume_at (ms) — countdown reads this

    function formatEtaShort(s) {
        if (s < 3600) return Math.max(1, Math.round(s / 60)) + ' min';
        return (s / 3600).toFixed(1) + ' h';
    }

    // R3 Stage C — countdown formatter for the paused banner. ms<1h → "M:SS",
    // ms>=1h → "H:MM:SS"; non-positive → "0:00". Pure (no DOM/network).
    function formatCountdown(ms) {
        var s = Math.max(0, Math.floor((Number(ms) || 0) / 1000));
        var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
        var two = function (n) { return (n < 10 ? '0' : '') + n; };
        return h > 0 ? h + ':' + two(m) + ':' + two(sec) : m + ':' + two(sec);
    }

    function showQueueBanner(position, total, message, etaS) {
        let banner = document.getElementById('cu-queue-banner');
        if (!banner) {
            banner = document.createElement('div');
            banner.id = 'cu-queue-banner';
            banner.className = 'notice notice-info inline';
            banner.style.marginTop = '10px';
            const progressBar = document.getElementById('cu-progress-bar');
            if (progressBar && progressBar.parentNode) {
                progressBar.parentNode.insertBefore(banner, progressBar);
            }
        }
        if (message) {
            banner.innerHTML = '<p>' + esc(message) + '</p>';
        } else if (position !== null && position !== undefined) {
            var etaTxt = (typeof etaS === 'number' && etaS > 0)
                ? ' Estimated start: ~' + esc(formatEtaShort(etaS)) + ' (estimate).'
                : '';
            banner.innerHTML = '<p>Your scan is queued \u2014 position #' + esc(String(position)) +
                ' of ' + esc(String(total)) + '.' + etaTxt + ' It will start automatically.</p>';
        } else {
            banner.innerHTML = '<p>Your scan is queued. It will start automatically.</p>';
        }
        banner.style.display = '';
        const pb = document.getElementById('cu-progress-bar');
        if (pb) pb.style.display = 'none';
        const pt = document.getElementById('cu-progress-text');
        if (pt) pt.style.display = 'none';
    }

    function hideQueueBanner() {
        const banner = document.getElementById('cu-queue-banner');
        if (banner) banner.style.display = 'none';
        const pb = document.getElementById('cu-progress-bar');
        if (pb) pb.style.display = '';
        const pt = document.getElementById('cu-progress-text');
        if (pt) pt.style.display = '';
    }

    function pollProgress() {
        const url = `${railwayUrl}/jobs/${scanJobId}/status?from=${lastPageIndex}`;
        fetch(url, { headers: { 'Authorization': 'Bearer ' + scanJobToken } })
            .then(r => r.json())
            .then(data => { handleStatusUpdate(data); })
            .catch(() => {
                post('cu_scanner_poll_status', { job_id: scanJobId, job_token: scanJobToken, from: lastPageIndex })
                    .then(res => { if (res.success) handleStatusUpdate(res.data); });
            });
    }

    function handleStatusUpdate(data) {
        lastKnownStatus = data.status;

        // R3 Stage C — one teardown point: clear the live countdown on EVERY
        // non-paused state (resumed/terminal/Stop&keep all pass through here).
        if (countdownInterval && data.status !== 'paused') {
            clearInterval(countdownInterval);
            countdownInterval = null;
        }
        if (data.status !== 'paused') {
            var pb = document.getElementById('cu-paused-banner');
            if (pb) pb.style.display = 'none';
        }

        if (data.status === 'queued') {
            showQueueBanner(data.queue_position, data.total_queued, null, data.eta_s);
            scheduleNextPoll();
            return;
        }

        if (data.status === 'cancelled_timeout') {
            stopPolling();
            sessionStorage.removeItem('cu_scanner_active_job');
            showQueueBanner(null, null, data.message || 'Your scan was cancelled after waiting 3 hours in queue. Credits have been returned. Please try again later.');
            return;
        }

        // Task 5 — shared {pages, completed, total} computation hoisted ABOVE the
        // killed branch so killed/complete/failed can all use it. queued +
        // cancelled_timeout returned already (they don't need page data).
        const pages     = data.pages || [];
        const completed = data.completed || 0;
        const total     = data.total || totalPages;

        if (data.status === 'killed') {
            stopPolling();
            sessionStorage.removeItem('cu_scanner_active_job');
            // FU-7 — also update the plugin's local ScanHistory record so the
            // History tab no longer shows this scan as in_progress/queued.
            // Fire-and-forget; UI banner is the user-visible signal regardless.
            post('cu_scanner_handle_killed');
            // Killed = admin kill: charged 0, no rules delivered → no build_result.
            // Route through the unified terminal-incomplete handler for the banner.
            handleTerminalIncomplete({
                status:       'killed',
                completed:    completed,
                total:        total,
                pages:        pages,
                selectedUrls: selectedUrls.slice(),
            });
            return;
        }

        hideQueueBanner(); // clears banner if transitioning from queued → in_progress

        document.getElementById('cu-progress-bar').value = total ? (completed / total) * 100 : 0;
        document.getElementById('cu-progress-text').textContent = `${completed} / ${total}`;

        const tbody = document.getElementById('cu-pages-tbody');
        pages.forEach((page, idx) => {
            // Railway always returns all pages in order from index 0.
            // Use idx directly — lastPageIndex offset caused duplicate rows after first poll.
            const globalIdx   = idx;
            const existing    = document.getElementById('cu-row-' + globalIdx);
            const statusLabel = page.status === 'done' ? '\u2713 Done' : page.status === 'error' ? '\u2717 Error' : '\u2026';
            if (existing) {
                existing.innerHTML = rowHtml(page.url, statusLabel);
            } else {
                const tr = document.createElement('tr');
                tr.id = 'cu-row-' + globalIdx;
                tr.innerHTML = rowHtml(page.url, statusLabel);
                tbody.appendChild(tr);
            }
        });

        if (data.status === 'paused') {
            renderPausedBanner(data);
            if (!countdownInterval) {                 // AC-C-4: never a second timer
                countdownInterval = setInterval(function () {
                    var el = document.getElementById('cu-paused-countdown');
                    if (el) el.textContent = formatCountdown(pausedResumeAt - Date.now());
                }, PAUSED_COUNTDOWN_TICK_MS);
            }
            schedulePausedPoll(data);                 // AC-C-3: aligned, not 2s
            return;                                   // do NOT hit scheduleNextPoll
        }

        if (data.status === 'complete' || data.status === 'failed') {
            stopPolling();
            sessionStorage.removeItem('cu_scanner_active_job');
            if (data.status === 'complete') {
                buildResult();
            } else if (completed >= total) {
                // failed but every page actually completed → treat as a normal
                // complete (deliver rules + Step-4, no partial banner).
                buildResult();
            } else if (completed > 0) {
                // Charged partial: 0 < completed < total. Deliver the X-page rules
                // (build_result) AND show the partial banner. If build_result errors
                // we fall back to the pre-submit-fatal failure path below.
                buildResult({
                    status:       'failed',
                    completed:    completed,
                    total:        total,
                    pages:        pages,
                    selectedUrls: selectedUrls.slice(),
                }).then((built) => {
                    // built === false means build_result returned res.success === false
                    // (do_build_result threw "No coverage data" — nothing was delivered).
                    if (!built) {
                        post('cu_scanner_handle_failure').then(() => {
                            showStep(1);
                            alert('Scan failed. Credits have been released. You may retry the scan.');
                        });
                    }
                });
            } else {
                // completed === 0 → pre-submit fatal / zero delivered. Existing path.
                post('cu_scanner_handle_failure').then(() => {
                    showStep(1);
                    alert('Scan failed. Credits have been released. You may retry the scan.');
                });
            }
        } else {
            scheduleNextPoll();
        }
    }

    function rowHtml(url, status) {
        return `<td>${esc(url)}</td><td>${esc(status)}</td>`;
    }

    // buildResult([terminalInfo]) — delivers the X-page rules and renders Step 4.
    // When terminalInfo is supplied (a charged terminal-incomplete: failed/user_cancel
    // partial), after Step 4 renders we route it through handleTerminalIncomplete() for
    // the partial banner. Returns a Promise<boolean> resolving true when the result was
    // built (res.success) and false when build_result errored (no coverage delivered),
    // so callers can fall back to the pre-submit-fatal failure path.
    function buildResult(terminalInfo) {
        const externalOnly = allSelectedAreExternal();
        // R2 1.7.43b: on a partial, pass the SaaS-charged page count (the cancel/failed
        // completed count) so History's credits_used mirrors the actual charge (= the banner),
        // not the build-time delivered pages a fast-cancel race can inflate.
        const chargedCount = (terminalInfo && terminalInfo.completed != null) ? terminalInfo.completed : '';
        return post('cu_scanner_build_result', { job_id: scanJobId, job_token: scanJobToken, charged_count: chargedCount })
            .then(res => {
                if (!res.success) {
                    // For a terminal-incomplete partial, an error here means nothing was
                    // delivered (do_build_result threw "No coverage data"). Signal the
                    // caller (return false) so it can run the failure fallback rather
                    // than alerting — avoids a double "error" surface.
                    if (terminalInfo) return false;
                    alert('Error building result: ' + res.data);
                    return false;
                }
                const d = res.data;
                const bannerData = {
                    scan_id:         d.scan_id          || '',
                    pages_blocked:   d.pages_blocked    || { desktop: 0, mobile: 0 },
                    blocked_reasons: d.blocked_reasons  || {},
                    total_pages:     d.total_pages      || 0,
                };
                restoreStep4( scanJobId, d.safe_count, d.aggressive_count, d.can_push, externalOnly, bannerData, d.total_pages, d.pages, d.scan_id, d.has_active_cu_rules );
                localStorage.setItem( 'cu_scanner_result', JSON.stringify({
                    job_id:        scanJobId,
                    safe_count:    d.safe_count,
                    agg_count:     d.aggressive_count,
                    can_push:      d.can_push,
                    external_only: externalOnly,
                    total_pages:   d.total_pages || 0,
                    pages:         d.pages   || [],
                    scan_id:       d.scan_id || '',
                    // banner data not persisted \u2014 shown once per live build_result call only.
                }) );
                // Task 5 \u2014 charged terminal-incomplete partial: after Step 4 renders,
                // route through the unified handler for the partial banner.
                if (terminalInfo) {
                    // R2 1.7.43b: use the BUILD-TIME result rows (d.pages) for the remainder
                    // discriminator, not the stale cancel-click snapshot \u2014 so a page that
                    // finished in-flight after the cancel isn't re-queued.
                    terminalInfo.pages = (d.pages || terminalInfo.pages || []);
                    handleTerminalIncomplete(terminalInfo);
                }
                return true;
            });
    }

    // Task 5 \u2014 IIFE-scoped stash of the most recent terminal-incomplete info so
    // Tasks 6 (banner/remainder persistence) and 7 (re-queue) can consume it.
    let currentPartialInfo = null;

    // handleTerminalIncomplete(info) \u2014 unified routing target for every terminal
    // path that ends a scan before all selected pages completed:
    //   - killed     (admin kill; charged 0, no rules)
    //   - failed     (worker failure mid-scan; charged {completed}, X-page rules)
    //   - user_cancel (operator cancel mid-scan; charged {completed}, X-page rules)
    // info = { status, completed, total, pages, selectedUrls }.
    // Task 6: stash info, compute + persist remainder, render full banner.
    function handleTerminalIncomplete(info) {
        // currentPartialInfo is assigned to partialPayload below (after the remainder is
        // computed) so it carries remainder_urls — reQueueRemainder reads
        // currentPartialInfo.remainder_urls. Setting it to the raw `info` here left it
        // undefined on the live path → "No remaining pages to re-queue" (1.7.43b fix).

        // --- Compute remainder ---
        // Strip any query string (bypass params) from a URL for clean comparison.
        // normaliseUrl() already strips trailing slashes + lowercases; we also
        // drop the ?query portion so bypass-baked pages[].url matches selectedUrls.
        function cleanUrl(u) {
            return normaliseUrl(String(u || '').replace(/\?.*$/, ''));
        }

        const submitted = Array.isArray(info && info.selectedUrls) ? info.selectedUrls : [];
        const pages     = Array.isArray(info && info.pages)        ? info.pages        : [];

        // Build the set of clean URLs that GENUINELY scanned (captured real assets).
        // info.pages here is the BUILD-TIME result set (buildResult passes do_build_result's
        // d.pages). A cut-off page (in-flight when cancelled) is marked done by the worker but
        // has zero assets / zero S:A:N — it is NOT genuinely scanned, so it stays in the
        // remainder to be re-queued (alongside the never-reached pages). (1.7.43b)
        function isGenuinelyScanned(p) {
            if (!p) return false;
            var san = (Number(p.safe) || 0) + (Number(p.aggressive) || 0) + (Number(p.needed) || 0);
            if (san > 0) return true;                              // build-time result row (real rules/needed)
            return Array.isArray(p.assets) && p.assets.length > 0; // raw worker row (killed/fallback)
        }
        const doneSet = new Set();
        pages.forEach(function (p) {
            if (p && p.url && isGenuinelyScanned(p)) {
                doneSet.add(cleanUrl(p.url));
            }
        });

        // Remainder = submitted URLs to re-queue.
        // killed → ALL submitted (admin kill delivered zero rule file for any page;
        //   "Retry the scan" re-runs everything regardless of how many pages polled done).
        // failed / user_cancel → non-done set-difference (charged pages already delivered).
        const remainderUrls = (info.status === 'killed')
            ? submitted.slice()
            : submitted.filter(function (u) { return !doneSet.has(cleanUrl(u)); });

        // --- Persist to localStorage ---
        // Key is 'cu_scanner_partial' (un-namespaced). The spec calls for
        // 'cu_scanner_partial_{user_id}' but cuScanner exposes no user_id to JS;
        // the sibling key 'cu_scanner_result' is also un-namespaced \u2014 staying
        // consistent keeps this task scanner.js-only (no PHP changes needed).
        var partialPayload = {
            job_id:        scanJobId,
            status:        info.status,
            completed:     Number(info.completed) || 0,
            total:         Number(info.total)     || 0,
            remainder_urls: remainderUrls,
        };
        // Stash the payload WITH remainder_urls so reQueueRemainder works on the LIVE path
        // (it reads currentPartialInfo.remainder_urls) — identical to the reload-restore path
        // (restorePartialBanner sets currentPartialInfo to this same shape). (1.7.43b fix.)
        currentPartialInfo = partialPayload;
        try {
            localStorage.setItem('cu_scanner_partial', JSON.stringify(partialPayload));
        } catch (_e) { /* localStorage unavailable \u2014 banner still renders live */ }

        // --- Render banner (uses persisted payload so reload-restore uses same path) ---
        renderPartialBanner(partialPayload);

        // --- killed visibility: #cu-banner-area lives on Step 4; killed skips
        // buildResult/showStep(4), so we must advance to Step 4 here. ---
        // For failed/user_cancel, restoreStep4() inside buildResult already called
        // showStep(4) before we arrive here, so this is a no-op for those paths.
        if (info.status === 'killed') {
            showStep(4);
        }
    }

    // R3 Stage C \u2014 idempotent paused banner. Re-render updates resume_at; the
    // countdown text node (#cu-paused-countdown) is rewritten by the 1s interval.
    function renderPausedBanner(data) {
        pausedResumeAt = Number(data.resume_at) || 0;
        var area = document.getElementById('cu-paused-banner');
        if (!area) {
            area = document.createElement('div');
            area.id = 'cu-paused-banner';
            area.className = 'notice notice-warning inline';
            var bar = document.getElementById('cu-progress-bar');
            if (bar && bar.parentNode) bar.parentNode.insertBefore(area, bar);
        }
        if (!area._cuPausedBuilt) {
            area.innerHTML =
                '<p>&#9208; <strong>Scan paused</strong> \u2014 your origin repeatedly rate-limited or ' +
                'blocked the scanner. Auto-retrying in <span id="cu-paused-countdown">' +
                esc(formatCountdown(pausedResumeAt - Date.now())) + '</span>\u2026 (no action needed)</p>' +
                '<p><button type="button" class="button" id="cu-paused-stopkeep">' +
                'Stop &amp; keep results now</button></p>';
            area._cuPausedBuilt = true;
            var btn = document.getElementById('cu-paused-stopkeep');
            if (btn) btn.addEventListener('click', stopAndKeep);   // Task 3
        }
        area.style.display = '';
    }

    // R3 Stage C \u2014 align the next /status poll to resume_at; once we're at/past
    // it, poll every PAUSED_CATCHUP_MS until the status leaves 'paused'.
    function schedulePausedPoll(data) {
        stopPolling();                                    // clear any prior timer before rescheduling
        var remaining = (Number(data.resume_at) || 0) - Date.now();
        var delay = remaining > 0
            ? Math.max(remaining + PAUSED_POLL_BUFFER_MS, PAUSED_POLL_FLOOR_MS)
            : PAUSED_CATCHUP_MS;
        pollTimer = setTimeout(pollProgress, delay);
    }

    // renderPartialBanner(payload) \u2014 renders the full partial-failure banner into
    // #cu-banner-area. Accepts either a live info object or a reloaded payload from
    // localStorage (both share the same shape after Task 6 normalisation).
    // Task 7 wires the button click handlers \u2014 do NOT add click logic here.
    function renderPartialBanner(payload) {
        const area = document.getElementById('cu-banner-area');
        if (!area) return;

        const status    = (payload && payload.status)    || '';
        const completed = Number(payload && payload.completed) || 0;
        const total     = Number(payload && payload.total)     || 0;
        const remainder = Array.isArray(payload && payload.remainder_urls)
            ? payload.remainder_urls : [];
        const N = remainder.length;

        var html;
        if (status === 'killed') {
            html = '<div class="notice notice-warning inline aias-partial-banner">' +
                '<p><strong>&#9888; Your scan was stopped by an administrator.</strong> ' +
                '<strong>You were not charged.</strong></p>' +
                '<p><button type="button" class="button" id="cu-partial-retry-btn">' +
                'Retry the scan' +
                '</button></p>' +
                '</div>';
        } else {
            var reason = (status === 'user_cancel')
                ? 'You cancelled this scan.'
                : 'Your scan was interrupted before it finished.';
            html = '<div class="notice notice-warning inline aias-partial-banner">' +
                '<p><strong>&#9888; Scan stopped at page ' + esc(String(completed)) +
                ' of ' + esc(String(total)) + '.</strong> ' +
                esc(reason) + ' You were charged for the ' +
                esc(String(completed)) + ' completed page' + (completed === 1 ? '' : 's') + '.</p>' +
                '<p><button type="button" class="button" id="cu-partial-requeue-btn">' +
                'Re-queue the remaining ' + esc(String(N)) + ' page' + (N === 1 ? '' : 's') +
                '</button></p>' +
                '</div>';
        }
        area.innerHTML = html;

        // killed has no rule file — hide the download button so it is not
        // shown as a non-functional href="#". Push/sync are already display:none
        // by default in the template, but hide them explicitly for robustness.
        // This guard covers BOTH the live path (handleTerminalIncomplete →
        // renderPartialBanner) and the reload-restore path (restorePartialBanner
        // IIFE → renderPartialBanner) because both converge here.
        if (status === 'killed') {
            var dlBtn  = document.getElementById('cu-btn-download');
            var pshBtn = document.getElementById('cu-btn-push');
            var synBtn = document.getElementById('cu-btn-sync');
            if (dlBtn)  dlBtn.style.display  = 'none';
            if (pshBtn) pshBtn.style.display = 'none';
            if (synBtn) synBtn.style.display = 'none';
            // 1.7.44b — killed reaches Step 4 via renderPartialBanner WITHOUT running
            // restoreStep4, so the top "Run Another Scan" row keeps its template-default
            // visibility and duplicates the always-shown bottom one. killed has no results
            // table, so the top row is purely redundant — hide it. Covers both the live path
            // (handleTerminalIncomplete) and the reload path (restorePartialBanner), which
            // both converge here. (Charged partials keep restoreStep4's own rescan-row logic.)
            var topRescanRow = document.querySelector('#step-4 .cu-rescan-row');
            if (topRescanRow) topRescanRow.style.display = 'none';
        }

        // Wire click handlers for the banner buttons.
        // Must run after area.innerHTML is set — buttons are recreated on each render.
        wirePartialBannerHandlers();
    }

    // wirePartialBannerHandlers() — attach click handlers to whichever partial-banner
    // button is currently in the DOM. Called at the end of renderPartialBanner so it
    // covers both the live path and the reload-restore path (both call renderPartialBanner).
    function wirePartialBannerHandlers() {
        var requeueBtn = document.getElementById('cu-partial-requeue-btn');
        var retryBtn   = document.getElementById('cu-partial-retry-btn');
        if (requeueBtn) requeueBtn.addEventListener('click', reQueueRemainder);
        if (retryBtn)   retryBtn.addEventListener('click',   reQueueRemainder);
    }

    // reQueueRemainder() — shared handler for both partial-banner buttons.
    // Reads the URL set from currentPartialInfo.remainder_urls (persisted payload),
    // which survives a page reload (resolvedByUrl / selectedUrls do NOT).
    // Mirrors the reserve → submit flow in the main submit handler (L1182-L1284).
    async function reQueueRemainder() {
        if (reQueueRemainder._inFlight) return;
        reQueueRemainder._inFlight = true;
        try {
            var requeueUrls = (currentPartialInfo && Array.isArray(currentPartialInfo.remainder_urls))
                ? currentPartialInfo.remainder_urls : [];
            var N = requeueUrls.length;
            if (N === 0) {
                alert('No remaining pages to re-queue.');
                return;
            }

            // Reserve credits for N pages (no extra-time on re-queue).
            var resRes;
            try {
                resRes = await post('cu_scanner_reserve_job', { page_count: N, extra_time_count: 0 });
            } catch (_e) {
                // Network error on reserve — retryable path mirrors main flow.
                // No outbox integration here (no targetBypassPerUrl / classCConsent scope).
                alert('Network error reserving credits. Please try again.');
                return;
            }

            if (!resRes.success) {
                // Covers Phase-G 409 scan_already_active + insufficient-credits messages.
                // submitErrorAlert() already has the right copy for both — reuse it.
                var resMsg = (resRes.data && resRes.data.message) ? resRes.data.message : resRes.data;
                var resRetryable = resRes.data && resRes.data.retryable === true;
                if (resRetryable) {
                    alert('Network error reserving credits. Please try again.');
                } else {
                    showStep(1);
                    submitErrorAlert(resRes.data, resMsg);
                }
                return;
            }

            var job_token = resRes.data.job_token;

            // Submit the re-queue. remainder_urls are already clean (bypass-stripped by
            // handleTerminalIncomplete). Send them as both urls and submitted_urls; no
            // resolvedByUrl mapping (absent after reload — remainder already clean).
            // Class-C consent gate is handled the same way as the main submit handler.
            var submitPayload = {
                urls:           requeueUrls,
                submitted_urls: requeueUrls,
                job_token:      job_token,
                extra_time_urls: [],
            };

            var subRes;
            try {
                subRes = await post('cu_scanner_submit_job', submitPayload);
            } catch (_e) {
                post('cu_scanner_handle_failure');
                alert('Network error submitting re-queue. Please try again.');
                return;
            }

            // Class-C consent gate — mirrors main submit handler pattern.
            if (!subRes.success && subRes.data && subRes.data.error === 'class_c_consent_required') {
                var consented = await showConsentDialog(subRes.data.class_c_active || [], N);
                if (!consented) {
                    post('cu_scanner_handle_failure');
                    showStep(1);
                    return;
                }
                var retryPayload = Object.assign({}, submitPayload, { class_c_consent_given: '1' });
                try {
                    subRes = await post('cu_scanner_submit_job', retryPayload);
                } catch (_e) {
                    post('cu_scanner_handle_failure');
                    alert('Network error submitting re-queue. Please try again.');
                    return;
                }
                if (!subRes.success) {
                    var retryMsg      = (subRes.data && subRes.data.message) ? subRes.data.message : subRes.data;
                    var retryRetryable = subRes.data && subRes.data.retryable === true;
                    post('cu_scanner_handle_failure');
                    if (!retryRetryable) {
                        showStep(1);
                        submitErrorAlert(subRes.data, retryMsg);
                    } else {
                        alert('Network error submitting re-queue. Please try again.');
                    }
                    return;
                }
            }

            if (!subRes.success) {
                var subMsg      = (subRes.data && subRes.data.message) ? subRes.data.message : subRes.data;
                var subRetryable = subRes.data && subRes.data.retryable === true;
                post('cu_scanner_handle_failure');
                if (!subRetryable) {
                    showStep(1);
                    submitErrorAlert(subRes.data, subMsg);
                } else {
                    alert('Network error submitting re-queue. Please try again.');
                }
                return;
            }

            // Success — transition to Step 3 polling, same as main submit handler.
            scanJobId     = subRes.data.job_id;
            scanJobToken  = subRes.data.job_token;
            railwayUrl    = subRes.data.railway_url;
            lastPageIndex = 0;
            sessionStorage.setItem('cu_scanner_active_job', JSON.stringify({
                job_id:      scanJobId,
                job_token:   scanJobToken,
                railway_url: railwayUrl,
            }));
            // Task 9 marker — set AFTER reserve returns the new job_id.
            localStorage.setItem('cu_scanner_requeue_' + scanJobId, '1');
            // Clear the persisted remainder so a reload no longer shows the old banner.
            localStorage.removeItem('cu_scanner_partial');
            beginScanPolling();
        } finally {
            reQueueRemainder._inFlight = false;
        }
    }

    function restoreStep4( jobId, safeCount, aggCount, canPush, externalOnly, bannerData, urlsScanned, pages, scanId, hasActiveCuRules ) {
        const urls = (typeof urlsScanned === 'number') ? urlsScanned : '?';
        document.getElementById('cu-result-summary').textContent =
            `Scan complete. ${urls} URLs scanned, ${safeCount} safe rules, ${aggCount} aggressive rules generated.`;
        const dlBtn = document.getElementById('cu-btn-download');
        dlBtn.href = ajax + '?action=cu_scanner_download_json&job_id=' + jobId + '&nonce=' + nonce;
        dlBtn.setAttribute('download', 'cu-scanner-' + jobId + '.json');

        const pushBtn    = document.getElementById('cu-btn-push');
        const syncBtn    = document.getElementById('cu-btn-sync');
        const pushResult = document.getElementById('cu-push-result');

        // G6: re-queue partial scans must not clobber already-pushed rules.
        const isRequeue = !!localStorage.getItem('cu_scanner_requeue_' + jobId);
        const syncOnly  = isRequeue && !!hasActiveCuRules;

        if (externalOnly) {
            pushBtn.style.display = 'none';
            syncBtn.style.display = 'none';
            pushResult.innerHTML = '<div class="notice notice-info inline"><p><strong>External URLs scanned.</strong> Rules can only be downloaded \u2014 direct push/sync to Code Unloader is not available when all scanned URLs are from external sites.</p></div>';
        } else if (syncOnly) {
            syncBtn.style.display = '';
            pushBtn.style.display = '';
            pushBtn.disabled = true;
            pushBtn.classList.add('cu-btn-dormant');
            pushResult.innerHTML = '<div class="notice notice-info inline"><p>These rules are from a re-scan. Use <strong>Sync</strong> to add them to your existing pushed rules \u2014 Push is disabled so it can\u2019t replace them.</p></div>';
        } else if (canPush) {
            pushBtn.style.display = '';
            syncBtn.style.display = '';
            pushBtn.disabled = false;
            pushBtn.classList.remove('cu-btn-dormant');
        }

        // Consume the re-queue marker now that the result screen has rendered.
        if (isRequeue) {
            localStorage.removeItem('cu_scanner_requeue_' + jobId);
        }

        // Subsystem D-4: render broken-banner if pages were blocked.
        renderBrokenBanner( bannerData || {} );

        // Per-URL results table (hidden when pages is empty/undefined).
        renderResultUrlList( pages, scanId );

        // Top "Run Another Scan" is redundant on a short results list. Hide it for
        // <10 scanned URLs, but RESERVE its space (visibility:hidden, not display:none)
        // so the content below does not shift (no CLS). Bottom button always shows.
        var topRescan = document.querySelector('#step-4 .cu-rescan-row');
        if (topRescan) {
            var scannedCount = Array.isArray(pages) ? pages.length : ((typeof urlsScanned === 'number') ? urlsScanned : 0);
            topRescan.style.visibility = (scannedCount < 10) ? 'hidden' : 'visible';
        }

        // Reveal "Rescan ET Candidates" (both rows) when at least one ET candidate exists.
        var hasEtCandidate = Array.isArray(pages) && pages.some(function (p) { return p && p.et_candidate; });
        if (hasEtCandidate) {
            document.querySelectorAll('#step-4 .cu-btn-rescan-et').forEach(function (btn) { btn.style.display = ''; });
        }

        // Reveal "Rescan 0-Results URLs" (both rows) when at least one S:0 A:0 (noopt) row exists.
        var hasNoopt = Array.isArray(pages) && pages.some(function (p) { return p && p.status_class === 'ok' && Number(p.safe) === 0 && Number(p.aggressive) === 0; });
        if (hasNoopt) {
            document.querySelectorAll('#step-4 .cu-btn-rescan-noopt-all').forEach(function (btn) { btn.style.display = ''; });
        }

        showStep(4);
    }

    // --- Per-URL results table (Step 4) -------------------------------------
    var cuUrlListState = { pages: [], scanId: '', page: 0, perPage: 25, etChecked: new Set() };
    // AC-RC-8b — resolved→submitted map, IIFE-scoped so both the submit handler (which
    // populates it from the probe response) and renderResultUrlListPage() (which reads it
    // for the "← resolved from" note) can see it. Was previously let-scoped inside the submit
    // handler → ReferenceError in renderResultUrlListPage → Step-4 render threw on every scan.
    var resolvedByUrl = {};

    // FU-AAS-SUFFIX-DROP-ON-RESOLVE — URLs carried over from a prior scan result (ET rescan /
    // carry-over view). These are scanned byte-identically: the submit handler forces
    // resolvedByUrl[u] = u for each, so the probe's fresh redirect resolution never rewrites
    // them (resolution fires only on a URL's first scan — operator directive 2026-06-11).
    // Writers (all with this one semantic): primeRescanEt(), restoreEtCarryOver(),
    // clearEtCarryOver() (reset). IIFE-scoped for the same cross-function reason as above.
    var etCarriedUrls = [];

    function cuEscHtml( v ) { var d = document.createElement('div'); d.textContent = ( v == null ? '' : String( v ) ); return d.innerHTML; }

    function renderResultUrlList( pages, scanId ) {
        var host = document.getElementById('cu-result-url-list');
        if ( ! host ) { return; }
        if ( ! pages || ! pages.length ) { host.innerHTML = ''; host.style.display = 'none'; return; }
        cuUrlListState.pages  = pages;
        cuUrlListState.scanId = scanId || '';
        cuUrlListState.page   = 0;
        // Seed ET-checkbox state ONCE from et_candidate rows (first render only).
        // Pagination re-renders host.innerHTML each page change; the persistent Set
        // is what survives across pages, so it must be seeded here, not per-page.
        cuUrlListState.etChecked = new Set();
        pages.forEach( function ( p ) { if ( p.et_candidate ) { cuUrlListState.etChecked.add( p.url ); } } );
        host.style.display = '';
        renderResultUrlListPage();
    }

    function renderResultUrlListPage() {
        var host = document.getElementById('cu-result-url-list'), st = cuUrlListState;
        var total = st.pages.length, pageCount = Math.ceil( total / st.perPage );
        var slice = st.pages.slice( st.page * st.perPage, st.page * st.perPage + st.perPage );
        var c = { ok: 0, partial: 0, blocked: 0, error: 0, skipped: 0, cancelled: 0 };
        st.pages.forEach( function ( p ) { if ( c[ p.status_class ] != null ) { c[ p.status_class ]++; } } );
        // AC-RC-8b — build reverse map (resolved → submitted) from the probe-session
        // resolvedByUrl map. Only populated during a live scan; gracefully absent when
        // results are restored from localStorage after a page reload.
        var submittedByResolved = {};
        if ( typeof resolvedByUrl !== 'undefined' && resolvedByUrl ) {
            Object.keys( resolvedByUrl ).forEach( function ( submitted ) {
                var resolved = resolvedByUrl[ submitted ];
                if ( resolved && resolved !== submitted ) {
                    submittedByResolved[ resolved ] = submitted;
                }
            } );
        }
        var rows = slice.map( function ( p ) {
            // FU-AAS-YELLOW-S0A0-ROWS — completed row that optimized nothing. Number() mirrors
            // the existing coercion at scanner.js:1586 (safe/aggressive are PHP int → JSON number).
            var noopt = ( p.status_class === 'ok' && Number( p.safe ) === 0 && Number( p.aggressive ) === 0 );
            var san = ( p.status_class === 'error' ) ? '—'
                : ( 'S:' + p.safe + ' A:' + p.aggressive + ' N:' + p.needed
                    + ( p.ratchet_recovered > 0 ? ' <span class="cu-ratchet" title="restored from the first scan by the ET ratchet">↩ +' + p.ratchet_recovered + '</span>' : '' )
                    + ( noopt ? ' <span class="cu-noopt-note">Please scan again</span>' : '' ) );
            var origUrl = submittedByResolved[ p.url ];
            var urlCell = cuEscHtml( p.url )
                + ( origUrl ? ' <span class="cu-resolved-note">← resolved from ' + cuEscHtml( origUrl ) + '</span>' : '' );
            return '<tr class="cu-row-' + cuEscHtml( p.status_class ) + ( noopt ? ' cu-row-noopt' : '' ) + '">'
                + '<td>' + cuEscHtml( p.n ) + '</td>'
                + '<td class="cu-url-cell">' + urlCell + '</td>'
                + '<td>' + cuEscHtml( p.status_label ) + '</td>'
                + '<td>' + cuEscHtml( p.credits ) + '</td>'
                + '<td class="cu-san">' + san + '</td>'
                + '<td>' + cuEscHtml( p.et_candidate ? 'yes' : '—' ) + '</td>'
                + '<td>' + ( p.et_candidate
                    ? '<input type="checkbox" class="cu-et-result-cb" data-url="' + esc( p.url ) + '"' + ( st.etChecked.has( p.url ) ? ' checked' : '' ) + '>'
                    : '—' ) + '</td></tr>';
        } ).join( '' );
        var pager = ( pageCount > 1 )
            ? '<div class="cu-url-pager"><button type="button" class="button" id="cu-url-prev"' + ( st.page === 0 ? ' disabled' : '' ) + '>« Prev</button>'
              + '<span>Page ' + ( st.page + 1 ) + ' of ' + pageCount + '</span>'
              + '<button type="button" class="button" id="cu-url-next"' + ( st.page >= pageCount - 1 ? ' disabled' : '' ) + '>Next »</button></div>'
            : '';
        host.innerHTML =
            '<h3 class="cu-url-title">Scan ID: ' + cuEscHtml( st.scanId ) + '</h3>'
          + '<p class="cu-url-summary">' + c.ok + ' OK · ' + c.partial + ' partial · ' + c.blocked + ' blocked · ' + c.error + ' error · ' + c.cancelled + ' cancelled (' + total + ' URLs)</p>'
          + '<table class="cu-url-table widefat"><thead><tr><th>#</th><th>URL</th><th>Status</th><th>Credits</th><th>S / A / N</th><th>ET candidate <span class="cu-help" tabindex="0" aria-label="ET candidate: URLs that would benefit from the worker spending extra time on them — likely more unloads."><span class="cu-help-box">ET candidates are URLs that would benefit from the worker spending extra time on them — likely yielding more unloads.</span></span></th><th>Extra Time <span class="cu-help" tabindex="0" aria-label="Re-run this URL with Extra Time — more probe budget, plus one credit."><span class="cu-help-box">Re-run this URL with Extra Time (more probe budget, +1 credit).</span></span></th></tr></thead><tbody>' + rows + '</tbody></table>'
          + '<p class="cu-et-result-all-row"><label><input type="checkbox" id="cu-et-result-all"> Extra Time: all ET candidates</label></p>'
          + pager;
        var prev = document.getElementById('cu-url-prev'); if ( prev ) { prev.onclick = function () { if ( st.page > 0 ) { st.page--; renderResultUrlListPage(); } }; }
        var next = document.getElementById('cu-url-next'); if ( next ) { next.onclick = function () { if ( st.page < pageCount - 1 ) { st.page++; renderResultUrlListPage(); } }; }
        // Per-row ET checkbox → mutate the persistent Set (by data-url) so the choice
        // survives pagination re-renders.
        host.querySelectorAll('.cu-et-result-cb').forEach( function ( cb ) {
            cb.addEventListener('change', function () {
                var url = cb.getAttribute('data-url');
                if ( cb.checked ) { st.etChecked.add( url ); } else { st.etChecked.delete( url ); }
                syncEtResultAll();
            } );
        } );
        // Per-row "Scan again" link removed — noopt rows now show plain "Please scan again" text;
        // the bottom "Rescan 0-Results URLs" button rescans every noopt URL in one batch.
        // All-on/off → toggle every ET-candidate URL across ALL pages (iterate st.pages,
        // not just the visible slice), then re-sync the visible checkboxes.
        var allCb = document.getElementById('cu-et-result-all');
        if ( allCb ) {
            allCb.addEventListener('change', function () {
                st.pages.forEach( function ( p ) {
                    if ( ! p.et_candidate ) { return; }
                    if ( allCb.checked ) { st.etChecked.add( p.url ); } else { st.etChecked.delete( p.url ); }
                } );
                host.querySelectorAll('.cu-et-result-cb').forEach( function ( cb ) { cb.checked = allCb.checked; } );
            } );
            syncEtResultAll();
        }
        // Reflect the master checkbox state: checked only when every ET candidate is in the Set.
        function syncEtResultAll() {
            if ( ! allCb ) { return; }
            var etTotal = st.pages.filter( function ( p ) { return p.et_candidate; } ).length;
            allCb.checked = ( etTotal > 0 && st.etChecked.size >= etTotal );
        }
    }

    /**
     * Renders a dismissable warning banner in #cu-banner-area when any pages
     * were blocked during the scan. Noop when bannerData is absent/zeroed.
     *
     * @param {{ scan_id?: string, pages_blocked?: {desktop:number,mobile:number},
     *            blocked_reasons?: Object<string,number>, total_pages?: number }} bd
     */
    function renderBrokenBanner( bd ) {
        const area = document.getElementById('cu-banner-area');
        if ( !area ) return;
        area.innerHTML = '';

        const blockedD = (bd.pages_blocked && bd.pages_blocked.desktop) || 0;
        const blockedM = (bd.pages_blocked && bd.pages_blocked.mobile)  || 0;
        if ( blockedD + blockedM === 0 ) return;

        const scanId     = bd.scan_id    || '';
        const total      = bd.total_pages || 0;
        const reasons    = bd.blocked_reasons || {};

        // Build copy.
        const bits = [];
        if ( blockedD > 0 ) bits.push( 'Desktop scanner blocked on ' + blockedD + ' of ' + total + ' pages.' );
        if ( blockedM > 0 ) bits.push( 'Mobile scanner blocked on '  + blockedM + ' of ' + total + ' pages.' );

        const phraseMap = {
            tier2_cf_challenge:       'Cloudflare challenge',
            tier2_akamai_challenge:   'Akamai Bot Manager',
            tier2_imperva_challenge:  'Imperva WAF',
            tier2_waf_challenge:      'firewall/WAF',
            tier2_unknown_challenge:  'bot/firewall protection (unidentified)',
            tier2_rocket_loader_stub: 'Cloudflare Rocket-Loader stub',
            tier2_small_body:         'asymmetric stub response',
            tier1_zero_bytes:         'empty response',
            tier1_http_4xx:           'site denial (4xx)',
            tier1_http_5xx:           'site error (5xx)',
            tier1_http_rate_limit:    'rate limit (429)',
            tier1_transport_error:    'unreachable',
            // FU-NEW-X-A (2026-05-17 PM late): synthetic reason used by the AAS PHP
            // fallback at class-scanner-ajax.php:~594 when a page has status='error'
            // but no broken_devices payload (scan errored before broken-device
            // detection populated). Keeps the banner visible on hard-error external
            // scans (e.g., pre-probe-confirmed 403 that proceeds to a failing scan).
            scan_errored:             'scan errored',
        };
        const phrases = [...new Set( Object.keys(reasons).map( k => phraseMap[k] || k ) )];
        const reasonClause = phrases.length ? ' (' + phrases.map(esc).join(', ') + ')' : '';

        // Per-reason action copy — must match class-broken-banner.php:108-156
        // (reason_category + action_clause). Mixed-category reasons fall back to
        // the generic 'bot' clause, matching the PHP-side fallback.
        function reasonCategory( reason ) {
            if ( reason === 'tier1_http_rate_limit' ) return 'rate';
            // 'scan_errored' is the PHP-side synthetic reason from class-scanner-ajax.php
            // when a page has status='error' but no broken_devices — maps to 'error' copy.
            if ( reason === 'tier1_http_4xx' || reason === 'tier1_http_5xx' || reason === 'tier1_transport_error' || reason === 'scan_errored' ) return 'error';
            return 'bot';
        }
        const categories = [...new Set( Object.keys(reasons).map(reasonCategory) )];
        let action;
        if ( categories.length === 1 && categories[0] === 'rate' ) {
            action = 'Your server rate-limited the scanner. The rules from the unblocked device (if any) are complete and safe to apply. Wait a few minutes between scans, or temporarily raise rate limits during scans.';
        } else if ( categories.length === 1 && categories[0] === 'error' ) {
            action = 'Your server returned an error or didn\'t respond. The rules from the unblocked device (if any) are complete and safe to apply. Try again later, or check site health.';
        } else {
            action = 'Your bot protection denied the scanner. The rules from the unblocked device are complete and safe to apply. For full coverage, temporarily disable bot protection during scans.';
        }

        const copy = bits.map(esc).join(' ') + reasonClause + ' ' + esc(action);

        // CDN exemption solution line \u2014 shown whenever 'rate' is one of the categories
        // (covers both pure-rate and mixed rate+bot scans). The href is a static,
        // hardcoded, user-input-free admin-relative URL \u2014 safe to inline without esc().
        // Must match class-broken-banner.php action_clause() rate-branch copy.
        const cdnLink = categories.includes('rate')
            ? ' Behind Cloudflare or another CDN? Set up the scanner rate-limit exemption so future scans aren\'t throttled \u2014 <a href="admin.php?page=cu-scanner-settings#cu-cloudflare-waf-bypass">open AI Assets Scanner settings</a>.'
            : '';

        area.innerHTML =
            '<div class="notice notice-warning inline aias-broken-banner" data-scan-id="' + esc(scanId) + '">' +
            '<p><strong>\u26a0 Some pages couldn\'t be fully scanned</strong></p>' +
            '<p>' + copy + cdnLink + '</p>' +
            '<p><button type="button" class="button aias-dismiss-banner">Got it \u2014 don\'t show again for this scan</button></p>' +
            '</div>';
    }

    // Dismiss banner via AJAX (event delegation \u2014 banner is injected dynamically).
    document.getElementById('cu-scanner-app').addEventListener('click', function(e) {
        if ( !e.target.classList.contains('aias-dismiss-banner') ) return;
        const banner = e.target.closest('.aias-broken-banner');
        if ( !banner ) return;
        const scanId = banner.dataset.scanId || '';
        const nonceBanner = (typeof aiasBannerL10n !== 'undefined') ? aiasBannerL10n.nonce : '';
        jQuery.post( ajax, {
            action:       'aias_dismiss_banner',
            scan_id:      scanId,
            _ajax_nonce:  nonceBanner,
        }, function() {
            banner.style.display = 'none';
        } );
    });

    // --- Cancel ---

    document.getElementById('cu-btn-cancel').addEventListener('click', async function () {
        let msg;
        // Task 5 — widen progress.pages beyond the try block so the user_cancel
        // terminal-incomplete routing can source the page rows the confirm fetch
        // already retrieved (avoids a second /status round-trip).
        let progressPages = [];
        try {
            const res = await fetch(
                railwayUrl + '/jobs/' + encodeURIComponent(scanJobId) + '/status',
                { headers: { Authorization: 'Bearer ' + scanJobToken } }
            );
            if (!res.ok) throw new Error('status ' + res.status);
            const progress = await res.json();
            // Railway's /jobs/:id/status returns { status, completed, total, pages: [...] }.
            // The field is 'completed' (not 'pages_completed') — earlier code read the wrong key,
            // so the confirm dialog always said "0 pages already scanned" regardless of progress.
            const pages = Number(progress.completed) || 0;
            progressPages = Array.isArray(progress.pages) ? progress.pages : [];
            msg = 'Cancelling now will charge you for ' + pages + ' page' + (pages === 1 ? '' : 's') + ' already scanned.\n\nContinue?';
        } catch (_e) {
            msg = 'Unable to fetch current progress. Cancel anyway? (You may still be charged for pages already scanned.)';
        }
        if (!confirm(msg)) return;
        stopPolling();
        // FU-AAS-CANCEL-RELEASE-RESILIENCE: only tear down local state on a confirmed cancel.
        // If the backend was unreachable (retryable), the scan is still running server-side —
        // keep the active-job state, resume tracking, and let the user retry the cancel.
        post('cu_scanner_cancel_job').then((res) => {
            if (res && res.success) {
                sessionStorage.removeItem('cu_scanner_active_job');
                // Task 5 — user_cancel charged partial: deliver the X-page rules +
                // Step-4, then route through the unified handler for the partial banner.
                // pages source: progressPages (from the confirm-fetch status above) —
                // already in hand, no extra round-trip. Task 3 made cancel_job return
                // res.data.pages_completed.
                const completedPages = (res.data && res.data.pages_completed) || 0;
                buildResult({
                    status:       'user_cancel',
                    completed:    completedPages,
                    total:        totalPages,
                    pages:        progressPages,
                    selectedUrls: selectedUrls.slice(),
                }).then((built) => {
                    // build_result errored (no coverage delivered) → fall back to the
                    // pre-Task-5 behaviour: return the operator to Step 1.
                    if (!built) showStep(1);
                });
            } else {
                const m = (res && res.data && res.data.message)
                    ? res.data.message
                    : 'Could not cancel — please try again.';
                alert(m);
                startPolling(); // scan still active; sessionStorage preserved for re-attach
            }
        });
    });

    // Notify any open Code Unloader admin Rules tab (channel 'code-unloader',
    // message 'cu.rule.changed', source 'scanner') so it refreshes without reload.
    function cuNotifyRulesChanged() {
        try {
            const msg = { type: 'cu.rule.changed', source: 'scanner', action: 'bulk-create' };
            if (typeof BroadcastChannel !== 'undefined') {
                const bc = new BroadcastChannel('code-unloader');
                bc.postMessage(msg);
                bc.close();
            } else {
                const key = 'cu-bus:code-unloader';
                localStorage.setItem(key, JSON.stringify({ t: Date.now(), msg: msg }));
                localStorage.removeItem(key);
            }
        } catch (_e) { /* BroadcastChannel/localStorage unavailable — skip silently */ }
    }

    // --- Push to CU ---

    document.getElementById('cu-btn-push').addEventListener('click', function () {
        const btn = this;
        btn.disabled = true;
        cuDoPush( btn, false );
    });

    // Two-phase push: the first call (confirmed=false) lets the server decide whether a
    // confirm is needed. It returns { needs_confirm: true } WITHOUT pushing only when CU
    // has active rules to overwrite; an empty CU pushes immediately (no dialog).
    function cuDoPush( btn, confirmed ) {
        post('cu_scanner_push_to_cu', { job_id: scanJobId, confirmed: confirmed ? 1 : 0 }).then(res => {
            const el = document.getElementById('cu-push-result');
            if (res.success && res.data && res.data.needs_confirm) {
                if (window.confirm('This will save and overwrite your existing Code Unloader rules. Continue?')) {
                    cuDoPush( btn, true );
                } else {
                    btn.disabled = false;
                }
                return;
            }
            if (res.success) {
                const errNote = res.data.error_count
                    ? ` (${esc(res.data.error_count)} errors — first: ${esc(res.data.error_message)})`
                    : '';
                el.innerHTML = `<div class="notice notice-success"><p>Rules added to Code Unloader: ${esc(res.data.safe_count)} safe, ${esc(res.data.aggressive_count)} aggressive.${errNote}</p></div>`;
                cuNotifyRulesChanged();
            } else {
                el.innerHTML = `<div class="notice notice-error"><p>Error: ${esc(res.data)}</p></div>`;
                btn.disabled = false;
            }
        }).catch(() => {
            const el = document.getElementById('cu-push-result');
            el.innerHTML = `<div class="notice notice-error"><p>Push failed — check server error logs.</p></div>`;
            btn.disabled = false;
        });
    }

    document.getElementById('cu-btn-sync').addEventListener('click', function () {
        const btn = this;
        btn.disabled = true;
        post('cu_scanner_sync_to_cu', { job_id: scanJobId }).then(res => {
            const el = document.getElementById('cu-push-result');
            if (res.success) {
                const d = res.data;
                const errNote = d.error_count
                    ? ` (${esc(d.error_count)} errors — first: ${esc(d.error_message)})`
                    : '';
                el.innerHTML = `<div class="notice notice-success"><p>Synced to Code Unloader — appended ${esc(d.appended_safe)} safe + ${esc(d.appended_aggressive)} aggressive rules (${esc(d.already_present)} already present).${errNote}</p></div>`;
                cuNotifyRulesChanged();
            } else {
                el.innerHTML = `<div class="notice notice-error"><p>Error: ${esc(res.data)}</p></div>`;
                btn.disabled = false;
            }
        }).catch(() => {
            const el = document.getElementById('cu-push-result');
            el.innerHTML = `<div class="notice notice-error"><p>Sync failed — check server error logs.</p></div>`;
            btn.disabled = false;
        });
    });

    // --- "Run Another Scan" buttons (above + below the results table) clear the
    // stored result and reload to a fresh Step 1 (buttons don't navigate natively). ---
    document.querySelectorAll('#step-4 .cu-btn-run-another').forEach(function (btn) {
        btn.addEventListener('click', function () {
            localStorage.removeItem('cu_scanner_result');
            localStorage.removeItem('cu_scanner_et_carry_over'); // FU-AAS-ET-VIEW-PERSIST — reset to a fresh Step 1
            // 1.7.44b — "Run Another Scan" = discard ALL partial state and start over.
            // Without this, cu_scanner_partial survives the reload and restorePartialBanner
            // re-renders the (now un-dismissable) banner on every load. Also drop any stale
            // re-queue markers so they can't mis-flag a brand-new scan as a re-queue.
            localStorage.removeItem('cu_scanner_partial');
            Object.keys(localStorage).forEach(function (k) {
                if (k.indexOf('cu_scanner_requeue_') === 0) { localStorage.removeItem(k); }
            });
            window.location.href = '?page=cu-scanner';
        });
    });

    // --- "Rescan ET Candidates" — stash the checked ET URLs in sessionStorage and
    // reload to a fresh Step 1, where primeRescanEt() picks them up (Extra Time
    // pre-checked). No-op when nothing is checked. ---
    document.querySelectorAll('#step-4 .cu-btn-rescan-et').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var urls = Array.from(cuUrlListState.etChecked);
            if (!urls.length) { return; }
            sessionStorage.setItem('cu_scanner_rescan_et', JSON.stringify(urls));
            window.location.href = '?page=cu-scanner';
        });
    });

    // --- "Rescan 0-Results URLs" — collect every S:0 A:0 (noopt) URL, stash them, and reload to a
    // fresh Step 1, where primeRescanSingle() picks them up (NO Extra Time; charged as a normal
    // 1-credit-per-URL scan, same as the per-row rescan it replaces). No-op when there are none. ---
    document.querySelectorAll('#step-4 .cu-btn-rescan-noopt-all').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var urls = cuUrlListState.pages.filter(function (p) {
                return p && p.status_class === 'ok' && Number(p.safe) === 0 && Number(p.aggressive) === 0;
            }).map(function (p) { return p.url; });
            if (!urls.length) { return; }
            sessionStorage.setItem('cu_scanner_rescan_single', JSON.stringify(urls));
            window.location.href = '?page=cu-scanner';
        });
    });

    // --- Init: restore Step 4 if a completed result is stored ---
    (function () {
        const stored = localStorage.getItem('cu_scanner_result');
        if (!stored) return;
        try {
            const d = JSON.parse(stored);
            scanJobId = d.job_id;
            restoreStep4( d.job_id, d.safe_count, d.agg_count, d.can_push, !!d.external_only, undefined, d.total_pages, d.pages, d.scan_id, d.has_active_cu_rules );
        } catch (_e) {
            localStorage.removeItem('cu_scanner_result');
        }
    }());

    // --- Init: restore partial-failure banner on page reload ---
    // Runs AFTER the cu_scanner_result restore above (Step-4 wins when both exist).
    // If a partial was stored by handleTerminalIncomplete, re-render the banner so
    // the re-queue/retry button survives a page reload. Task 7 clears this key on
    // successful re-queue submit.
    (function restorePartialBanner() {
        if (localStorage.getItem('cu_scanner_result')) return; // Step-4 result wins
        var raw = localStorage.getItem('cu_scanner_partial');
        if (!raw) return;
        var p;
        try { p = JSON.parse(raw); } catch (_e) { localStorage.removeItem('cu_scanner_partial'); return; }
        if (!p || !p.status) { localStorage.removeItem('cu_scanner_partial'); return; }
        currentPartialInfo = p;
        renderPartialBanner(p);
        showStep(4);
    }());

    // --- "Rescan ET Candidates" prime — runs AFTER the Step-4 restore above so its
    // showStep(1) wins when both a stored result and a pending rescan exist. Loads the
    // checked ET URLs into Step 1 in Discover/merge mode (discoveryRan=true), each
    // selected, each with Extra Time PRE-CHECKED, badge = count×2, ready for Start Scan.
    (function primeRescanEt() {
        var raw = sessionStorage.getItem('cu_scanner_rescan_et');
        if (!raw) return;
        sessionStorage.removeItem('cu_scanner_rescan_et');
        var etUrls = []; try { etUrls = JSON.parse(raw) || []; } catch (e) { return; }
        if (!etUrls.length) return;
        // Stale Step-4 result would bounce the user back to Step 4 on a later reload; clear it.
        localStorage.removeItem('cu_scanner_result');
        discoveredUrls = etUrls;
        groupedUrls    = { page: [], post: [], other: [], included: etUrls };
        selectedUrls   = etUrls.slice();
        extraTimeUrls  = etUrls.slice();   // Extra Time PRE-CHECKED (the payoff)
        etCarriedUrls  = etUrls.slice();   // FU-AAS-SUFFIX-DROP-ON-RESOLVE — scan these byte-identically
        totalPages     = etUrls.length;
        activeFilter   = 'all';
        discoveryRan   = true;             // mixed/merge mode — NOT include-only
        etCarryOver    = true;             // FU-AAS-ET-VIEW-PERSIST — this IS the carry-over view
        renderUrlList();
        updateCreditBadge();               // persists the view via saveEtCarryOver()
        document.getElementById('cu-url-list-area').style.display = 'block';
        updateStartScanVisibility();
        showStep(1);
    }());

    // --- Rescan-noopt prime (FU-AAS-YELLOW-S0A0-ROWS item 2) — consumes cu_scanner_rescan_single,
    // now fed by the "Rescan 0-Results URLs" bulk button (one or many S:0 A:0 URLs). Mirrors
    // primeRescanEt but with NO Extra Time, and sets the requeue-origin flag so the completed rescan
    // reuses the existing Push-dormant button state (Sync-only when CU rules already exist). Runs
    // before restoreEtCarryOver so its etCarryOver=true wins. ---
    (function primeRescanSingle() {
        var raw = sessionStorage.getItem('cu_scanner_rescan_single');
        if (!raw) return;
        sessionStorage.removeItem('cu_scanner_rescan_single');
        var urls = []; try { urls = JSON.parse(raw) || []; } catch (e) { return; }
        if (!urls.length) return;
        localStorage.removeItem('cu_scanner_result');         // clear stale Step-4 bounce
        discoveredUrls = urls;
        groupedUrls    = { page: [], post: [], other: [], included: urls };
        selectedUrls   = urls.slice();
        extraTimeUrls  = [];                                  // PLAIN rescan — NO Extra Time
        etCarriedUrls  = urls.slice();                        // scan byte-identically (no re-resolve)
        totalPages     = urls.length;
        activeFilter   = 'all';
        discoveryRan   = true;
        etCarryOver    = true;
        // Dormant-origin flag: survives a pre-Start reload; consumed at the Start-Scan seam (:1282).
        sessionStorage.setItem('cu_scanner_rescan_requeue', '1');
        renderUrlList();
        updateCreditBadge();
        document.getElementById('cu-url-list-area').style.display = 'block';
        updateStartScanVisibility();
        showStep(1);
    }());

    // --- FU-AAS-ET-VIEW-PERSIST: restore the ET carry-over view on a later page return ---
    // Mirrors the Step-4 restore IIFE above. Runs AFTER primeRescanEt so a just-primed rescan
    // (etCarryOver already true) wins; a stored Step-4 result also takes precedence.
    (function restoreEtCarryOver() {
        if (etCarryOver) return;                                // primeRescanEt already built it
        if (localStorage.getItem('cu_scanner_result')) return;  // Step-4 result wins
        var raw = localStorage.getItem('cu_scanner_et_carry_over');
        if (!raw) return;
        var d; try { d = JSON.parse(raw); } catch (e) { localStorage.removeItem('cu_scanner_et_carry_over'); return; }
        if (!d || !Array.isArray(d.discoveredUrls) || !d.discoveredUrls.length) return;
        discoveredUrls = d.discoveredUrls;
        groupedUrls    = (d.groupedUrls && typeof d.groupedUrls === 'object') ? d.groupedUrls : { page: [], post: [], other: [], included: d.discoveredUrls };
        selectedUrls   = Array.isArray(d.selectedUrls) ? d.selectedUrls : d.discoveredUrls.slice();
        extraTimeUrls  = Array.isArray(d.extraTimeUrls) ? d.extraTimeUrls : [];
        // FU-AAS-SUFFIX-DROP-ON-RESOLVE — old (pre-1.7.30b) blobs lack etCarriedUrls; treat all
        // restored URLs as carried (conservative: no re-resolve for any of them).
        etCarriedUrls  = Array.isArray(d.etCarriedUrls) ? d.etCarriedUrls : d.discoveredUrls.slice();
        totalPages     = discoveredUrls.length;
        activeFilter   = 'all';
        discoveryRan   = true;
        etCarryOver    = true;
        renderUrlList();
        updateCreditBadge();
        var area = document.getElementById('cu-url-list-area');
        if (area) area.style.display = 'block';
        updateStartScanVisibility();
        showStep(1);
    }());

    // --- Resume in-progress scan on page return ---
    (function checkForActiveJob() {
        const stored = sessionStorage.getItem('cu_scanner_active_job');
        if (!stored) return;
        try {
            JSON.parse(stored); // validate JSON — corrupt entry falls to catch
            post('cu_scanner_check_job').then(res => {
                if (!res.success) {
                    sessionStorage.removeItem('cu_scanner_active_job');
                    return;
                }
                scanJobId     = res.data.job_id;
                scanJobToken  = res.data.job_token;
                railwayUrl    = res.data.railway_url;
                lastPageIndex = 0;
                showStep(3);
                startPolling();
            });
        } catch (_) {
            sessionStorage.removeItem('cu_scanner_active_job');
        }
    }());

    // --- Phase O: Re-attach outbox on page load ---
    // If the server reports a queued or dispatched outbox entry (populated by PHP
    // via the cuScanner.outbox localized value), restore the matching UI state so
    // a page reload during an outage doesn't show an idle Step 1.
    (function restoreOutboxState() {
        var ob = (typeof cuScanner !== 'undefined' && cuScanner.outbox) ? cuScanner.outbox : null;
        if (!ob || !ob.state) return;

        if (ob.state === 'queued') {
            showOutboxBanner();
            startOutboxTick();
        } else if (ob.state === 'dispatched') {
            scanJobId     = ob.job_id    || null;
            scanJobToken  = ob.job_token  || null;
            railwayUrl    = ob.railway_url || null;
            lastPageIndex = 0;
            sessionStorage.setItem('cu_scanner_active_job', JSON.stringify({
                job_id:      scanJobId,
                job_token:   scanJobToken,
                railway_url: railwayUrl,
            }));
            showStep(3);
            startPolling();
        }
        // 'failed' and 'none' need no special UI — Step 1 is already shown.
    }());

    detectPlugins();

    // Test-only seam (Node harness). Harmless in the browser; never read by UI code.
    window.__cuTest = { formatCountdown: formatCountdown, handleStatusUpdate: handleStatusUpdate };
}());
