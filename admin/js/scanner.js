(function () {
    'use strict';

    const SCANNER_JS_VERSION = '1.0.10.15';
    console.log( '[AI Assets Scanner] scanner.js v' + SCANNER_JS_VERSION + ' loaded' );

    const ajax    = cuScanner.ajaxUrl;
    const nonce   = cuScanner.nonce;
    const siteUrl = cuScanner.siteUrl || window.location.origin;

    // --- State ---
    let discoveredUrls = [];   // full set returned by server
    let discoveryRan   = false; // true once a REAL Discover Pages run completed (distinguishes mixed mode from include-only)
    let selectedUrls   = [];   // checked subset — used for reserve + submit
    let groupedUrls    = {};   // { page: [...], post: [...], other: [...] }
    let activeFilter   = 'all';
    let scanJobId        = null;
    let scanJobToken     = null;
    let railwayUrl       = null;
    let pollTimer        = null;
    let lastPageIndex    = 0;
    let totalPages       = 0;
    let lastKnownStatus  = null;
    let hasSoftBlocks  = false;
    let includedUrls   = [];   // include URLs not duplicated in discoveredUrls
    let availableBalance = null; // credit balance fetched from detect_plugins response

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
        const btn = document.getElementById('cu-btn-next-1');
        if (btn) btn.disabled = !allChecked;
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
        const btn = document.getElementById('cu-btn-next-1');
        if (!btn) return;
        const hasIncluded   = getIncludedUrls().length > 0;
        const hasDiscovered = discoveredUrls.length > 0;
        btn.style.display = (hasIncluded || hasDiscovered) ? '' : 'none';
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
                    <span class="cu-url-text">${esc(url)}</span>${badge}`;
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

    // --- Credit badge ---

    function updateCreditBadge() {
        const badge      = document.getElementById('cu-credit-badge');
        const notice     = document.getElementById('cu-bot-notice');
        const numEl      = document.getElementById('cu-credit-num');
        const desEl      = document.getElementById('cu-credit-deselected');
        const selected   = selectedUrls.length;
        const total      = discoveredUrls.length + includedUrls.length;
        const deselected = total - selected;

        if (!badge) return;
        badge.style.display = '';
        if (notice) notice.style.display = '';
        numEl.textContent = selected;

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
                if (availableBalance < selected) {
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

    // --- Step 2: Reserve + Submit ---

    document.getElementById('cu-btn-next-1').addEventListener('click', async function () {
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

            if (probeResult.data.warning_needed) {
                const userConfirmed = await showProbeOutcomeDialog(probeResult.data);
                if (!userConfirmed) return;
            }
            // Silent proceed on uniform class_a_clean.
        }

        showStep(2);
        // Scroll to the top so the operator sees the scanning progress UI
        // instead of being stuck at the bottom of the long URL selection list.
        window.scrollTo({ top: 0, behavior: 'smooth' });
        // Use selectedUrls.length — only charge for URLs that will actually be scanned
        post('cu_scanner_reserve_job', { page_count: selectedUrls.length })
            .then(res => {
                if (!res.success) { showStep(1); alert('Error: ' + res.data); return; }
                const job_token = res.data.job_token;
                post('cu_scanner_submit_job', {
                    urls: selectedUrls,
                    job_token,
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
                            const retry = await post('cu_scanner_submit_job', {
                                urls: selectedUrls,
                                job_token: job_token,
                                class_c_consent_given: '1',
                                target_bypass_per_url: targetBypassPerUrl,
                                target_stack_summary: targetStackSummary,
                            });
                            if (!retry.success) {
                                post('cu_scanner_handle_failure');
                                showStep(1);
                                alert('Error: ' + retry.data);
                                return;
                            }
                            res2 = retry;
                        }
                        if (!res2.success) {
                            post('cu_scanner_handle_failure');
                            showStep(1);
                            alert('Error: ' + res2.data);
                            return;
                        }
                        scanJobId     = res2.data.job_id;
                        scanJobToken  = res2.data.job_token;
                        railwayUrl    = res2.data.railway_url;
                        lastPageIndex = 0;
                        sessionStorage.setItem( 'cu_scanner_active_job', JSON.stringify({
                            job_id:      scanJobId,
                            job_token:   scanJobToken,
                            railway_url: railwayUrl,
                        }) );
                        showStep(3);
                        startPolling();
                    })
                    .catch(() => {
                        post('cu_scanner_handle_failure');
                        showStep(1);
                        alert('Error: Scan submission failed. Please try again.');
                    });
            })
            .catch(() => {
                showStep(1);
                alert('Error: Could not connect to server. Please try again.');
            });
    });

    // --- Step 3: Polling + Progress ---

    function startPolling() {
        pollProgress(); // poll immediately, then self-schedule
    }

    function stopPolling() {
        if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
    }

    function scheduleNextPoll() {
        // 10s while queued (no active scan work), 2s once in_progress
        const interval = lastKnownStatus === 'queued' ? 10000 : 2000;
        pollTimer = setTimeout(pollProgress, interval);
    }

    function showQueueBanner(position, total, message) {
        let banner = document.getElementById('cu-queue-banner');
        if (!banner) {
            banner = document.createElement('div');
            banner.id = 'cu-queue-banner';
            banner.className = 'notice notice-info';
            banner.style.marginTop = '10px';
            const progressBar = document.getElementById('cu-progress-bar');
            if (progressBar && progressBar.parentNode) {
                progressBar.parentNode.insertBefore(banner, progressBar);
            }
        }
        if (message) {
            banner.innerHTML = '<p>' + esc(message) + '</p>';
        } else if (position !== null && position !== undefined) {
            banner.innerHTML = '<p>Your scan is queued \u2014 position #' + esc(String(position)) + ' of ' + esc(String(total)) + '. It will start automatically.</p>';
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

        if (data.status === 'queued') {
            showQueueBanner(data.queue_position, data.total_queued, null);
            scheduleNextPoll();
            return;
        }

        if (data.status === 'cancelled_timeout') {
            stopPolling();
            sessionStorage.removeItem('cu_scanner_active_job');
            showQueueBanner(null, null, data.message || 'Your scan was cancelled after waiting 3 hours in queue. Credits have been returned. Please try again later.');
            return;
        }

        if (data.status === 'killed') {
            stopPolling();
            sessionStorage.removeItem('cu_scanner_active_job');
            var completedCount = Number(data.completed) || 0;
            var totalCount     = Number(data.total) || totalPages || 0;
            // FU-7 — also update the plugin's local ScanHistory record so the
            // History tab no longer shows this scan as in_progress/queued.
            // Fire-and-forget; UI banner is the user-visible signal regardless.
            post('cu_scanner_handle_killed');
            showQueueBanner(
                null,
                null,
                'Your scan was cancelled by an administrator. ' + completedCount + ' of ' + totalCount + ' pages were scanned before the kill.'
            );
            return;
        }

        hideQueueBanner(); // clears banner if transitioning from queued → in_progress

        const pages     = data.pages || [];
        const completed = data.completed || 0;
        const total     = data.total || totalPages;

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

        if (data.status === 'complete' || data.status === 'failed') {
            stopPolling();
            sessionStorage.removeItem('cu_scanner_active_job');
            if (data.status === 'complete') {
                buildResult();
            } else {
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

    function buildResult() {
        const externalOnly = allSelectedAreExternal();
        post('cu_scanner_build_result', { job_id: scanJobId, job_token: scanJobToken })
            .then(res => {
                if (!res.success) { alert('Error building result: ' + res.data); return; }
                const d = res.data;
                const bannerData = {
                    scan_id:         d.scan_id          || '',
                    pages_blocked:   d.pages_blocked    || { desktop: 0, mobile: 0 },
                    blocked_reasons: d.blocked_reasons  || {},
                    total_pages:     d.total_pages      || 0,
                };
                restoreStep4( scanJobId, d.safe_count, d.aggressive_count, d.can_push, externalOnly, bannerData, d.total_pages, d.pages, d.scan_id );
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
            });
    }

    function restoreStep4( jobId, safeCount, aggCount, canPush, externalOnly, bannerData, urlsScanned, pages, scanId ) {
        const urls = (typeof urlsScanned === 'number') ? urlsScanned : '?';
        document.getElementById('cu-result-summary').textContent =
            `Scan complete. ${urls} URLs scanned, ${safeCount} safe rules, ${aggCount} aggressive rules generated.`;
        const dlBtn = document.getElementById('cu-btn-download');
        dlBtn.href = ajax + '?action=cu_scanner_download_json&job_id=' + jobId + '&nonce=' + nonce;
        dlBtn.setAttribute('download', 'cu-scanner-' + jobId + '.json');

        const pushBtn    = document.getElementById('cu-btn-push');
        const pushResult = document.getElementById('cu-push-result');
        if (externalOnly) {
            pushBtn.style.display = 'none';
            pushResult.innerHTML = '<div class="notice notice-info"><p><strong>External URLs scanned.</strong> Rules can only be downloaded \u2014 direct push to Code Unloader is not available when all scanned URLs are from external sites.</p></div>';
        } else if (canPush) {
            pushBtn.style.display = '';
        }

        // Subsystem D-4: render broken-banner if pages were blocked.
        renderBrokenBanner( bannerData || {} );

        // Per-URL results table (hidden when pages is empty/undefined).
        renderResultUrlList( pages, scanId );

        showStep(4);
    }

    // --- Per-URL results table (Step 4) -------------------------------------
    var cuUrlListState = { pages: [], scanId: '', page: 0, perPage: 25 };

    function cuEscHtml( v ) { var d = document.createElement('div'); d.textContent = ( v == null ? '' : String( v ) ); return d.innerHTML; }

    function renderResultUrlList( pages, scanId ) {
        var host = document.getElementById('cu-result-url-list');
        if ( ! host ) { return; }
        if ( ! pages || ! pages.length ) { host.innerHTML = ''; host.style.display = 'none'; return; }
        cuUrlListState.pages  = pages;
        cuUrlListState.scanId = scanId || '';
        cuUrlListState.page   = 0;
        host.style.display = '';
        renderResultUrlListPage();
    }

    function renderResultUrlListPage() {
        var host = document.getElementById('cu-result-url-list'), st = cuUrlListState;
        var total = st.pages.length, pageCount = Math.ceil( total / st.perPage );
        var slice = st.pages.slice( st.page * st.perPage, st.page * st.perPage + st.perPage );
        var c = { ok: 0, partial: 0, blocked: 0, error: 0 };
        st.pages.forEach( function ( p ) { if ( c[ p.status_class ] != null ) { c[ p.status_class ]++; } } );
        var rows = slice.map( function ( p ) {
            var san = ( p.status_class === 'error' ) ? '—' : ( 'S:' + p.safe + ' A:' + p.aggressive + ' N:' + p.needed );
            return '<tr class="cu-row-' + cuEscHtml( p.status_class ) + '">'
                + '<td>' + cuEscHtml( p.n ) + '</td>'
                + '<td class="cu-url-cell">' + cuEscHtml( p.url ) + '</td>'
                + '<td>' + cuEscHtml( p.status_label ) + '</td>'
                + '<td>' + cuEscHtml( p.credits ) + '</td>'
                + '<td class="cu-san">' + cuEscHtml( san ) + '</td></tr>';
        } ).join( '' );
        var pager = ( pageCount > 1 )
            ? '<div class="cu-url-pager"><button type="button" class="button" id="cu-url-prev"' + ( st.page === 0 ? ' disabled' : '' ) + '>« Prev</button>'
              + '<span>Page ' + ( st.page + 1 ) + ' of ' + pageCount + '</span>'
              + '<button type="button" class="button" id="cu-url-next"' + ( st.page >= pageCount - 1 ? ' disabled' : '' ) + '>Next »</button></div>'
            : '';
        host.innerHTML =
            '<h3 class="cu-url-title">Scan ID: ' + cuEscHtml( st.scanId ) + '</h3>'
          + '<p class="cu-url-summary">' + c.ok + ' OK · ' + c.partial + ' partial · ' + c.blocked + ' blocked · ' + c.error + ' error (' + total + ' URLs)</p>'
          + '<table class="cu-url-table widefat"><thead><tr><th>#</th><th>URL</th><th>Status</th><th>Credits</th><th>S / A / N</th></tr></thead><tbody>' + rows + '</tbody></table>'
          + pager;
        var prev = document.getElementById('cu-url-prev'); if ( prev ) { prev.onclick = function () { if ( st.page > 0 ) { st.page--; renderResultUrlListPage(); } }; }
        var next = document.getElementById('cu-url-next'); if ( next ) { next.onclick = function () { if ( st.page < pageCount - 1 ) { st.page++; renderResultUrlListPage(); } }; }
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
            action = 'Your server rate-limited the scanner. The mobile rules (if any) are complete and safe to apply. Wait a few minutes between scans, or temporarily raise rate limits during scans.';
        } else if ( categories.length === 1 && categories[0] === 'error' ) {
            action = 'Your server returned an error or didn\'t respond. The mobile rules (if any) are complete and safe to apply. Try again later, or check site health.';
        } else {
            action = 'Your bot protection denied the scanner. The mobile rules are complete and safe to apply. For full coverage, temporarily disable bot protection during scans.';
        }

        const copy = bits.map(esc).join(' ') + reasonClause + ' ' + esc(action);

        area.innerHTML =
            '<div class="notice notice-warning aias-broken-banner" data-scan-id="' + esc(scanId) + '">' +
            '<p><strong>\u26a0 Some pages couldn\'t be fully scanned</strong></p>' +
            '<p>' + copy + '</p>' +
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
            msg = 'Cancelling now will charge you for ' + pages + ' page' + (pages === 1 ? '' : 's') + ' already scanned.\n\nContinue?';
        } catch (_e) {
            msg = 'Unable to fetch current progress. Cancel anyway? (You may still be charged for pages already scanned.)';
        }
        if (!confirm(msg)) return;
        stopPolling();
        sessionStorage.removeItem('cu_scanner_active_job');
        post('cu_scanner_cancel_job').then(() => { showStep(1); });
    });

    // --- Push to CU ---

    document.getElementById('cu-btn-push').addEventListener('click', function () {
        const btn = this;
        btn.disabled = true;
        post('cu_scanner_push_to_cu', { job_id: scanJobId }).then(res => {
            const el = document.getElementById('cu-push-result');
            if (res.success) {
                const errNote = res.data.error_count
                    ? ` (${esc(res.data.error_count)} errors — first: ${esc(res.data.error_message)})`
                    : '';
                el.innerHTML = `<div class="notice notice-success"><p>Rules added to Code Unloader: ${esc(res.data.safe_count)} safe, ${esc(res.data.aggressive_count)} aggressive.${errNote}</p></div>`;

                // Notify any open Code Unloader admin Rules tab in this browser
                // that rules just changed, so it refreshes its list table + count
                // without manual reload. CU's assets/js/cu-bus.js is the listener;
                // both halves use channel name 'code-unloader' and message type
                // 'cu.rule.changed'. Source 'scanner' lets CU distinguish from
                // its own admin echoes (admin.js filters those out).
                try {
                    const msg = { type: 'cu.rule.changed', source: 'scanner', action: 'bulk-create' };
                    if (typeof BroadcastChannel !== 'undefined') {
                        const bc = new BroadcastChannel('code-unloader');
                        bc.postMessage(msg);
                        bc.close();
                    } else {
                        // Storage-event fallback for older browsers — write-then-
                        // remove so identical-payload emits still trigger 'storage'
                        // events in other tabs.
                        const key = 'cu-bus:code-unloader';
                        localStorage.setItem(key, JSON.stringify({ t: Date.now(), msg: msg }));
                        localStorage.removeItem(key);
                    }
                } catch (_e) { /* BroadcastChannel/localStorage unavailable — skip silently */ }
            } else {
                el.innerHTML = `<div class="notice notice-error"><p>Error: ${esc(res.data)}</p></div>`;
                btn.disabled = false;
            }
        }).catch(() => {
            const el = document.getElementById('cu-push-result');
            el.innerHTML = `<div class="notice notice-error"><p>Push failed — check server error logs.</p></div>`;
            btn.disabled = false;
        });
    });

    // --- "Run Another Scan" clears stored result ---
    document.querySelector('#step-4 a[href="?page=cu-scanner"]').addEventListener('click', () => {
        localStorage.removeItem('cu_scanner_result');
    });

    // --- Init: restore Step 4 if a completed result is stored ---
    (function () {
        const stored = localStorage.getItem('cu_scanner_result');
        if (!stored) return;
        try {
            const d = JSON.parse(stored);
            scanJobId = d.job_id;
            restoreStep4( d.job_id, d.safe_count, d.agg_count, d.can_push, !!d.external_only, undefined, d.total_pages, d.pages, d.scan_id );
        } catch (_e) {
            localStorage.removeItem('cu_scanner_result');
        }
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

    detectPlugins();
}());
