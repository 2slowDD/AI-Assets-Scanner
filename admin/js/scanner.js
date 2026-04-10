(function () {
    'use strict';

    const ajax  = cuScanner.ajaxUrl;
    const nonce = cuScanner.nonce;

    // --- State ---
    let discoveredUrls = [];   // full set returned by server
    let selectedUrls   = [];   // checked subset — used for reserve + submit
    let groupedUrls    = {};   // { page: [...], post: [...], other: [...] }
    let activeFilter   = 'all';
    let scanJobId      = null;
    let scanJobToken   = null;
    let railwayUrl     = null;
    let pollTimer      = null;
    let lastPageIndex  = 0;
    let totalPages     = 0;
    let hasSoftBlocks  = false;
    let includedUrls   = [];   // include URLs not duplicated in discoveredUrls

    const STEP_LABELS = {
        1: 'Step 1 \u2014 Discover Pages',
        2: 'Step 2 \u2014 Reserving Credits\u2026',
        3: 'Step 3 \u2014 Scanning',
        4: 'Step 4 \u2014 Done',
    };

    // --- Utilities ---

    function post(action, data) {
        const form = new FormData();
        form.append('action', action);
        form.append('nonce', nonce);
        Object.entries(data || {}).forEach(([k, v]) => {
            if (Array.isArray(v)) v.forEach(i => form.append(k + '[]', i));
            else form.append(k, v);
        });
        return fetch(ajax, { method: 'POST', body: form }).then(r => r.json());
    }

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
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

    // --- Step 1: Plugin detection ---

    function detectPlugins() {
        post('cu_scanner_detect_plugins').then(res => {
            if (!res.success) return;
            const warnings = document.getElementById('cu-plugin-warnings');
            const d = res.data;
            let html = '';

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

        includedUrls         = newIncluded;
        groupedUrls.included = newIncluded;
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
                    <input type="checkbox" class="cu-group-cb" data-type="${type}" checked>
                    ${meta.label} <span class="cu-group-count">${urls.length}</span>
                </label>
                <button class="cu-group-toggle-link" data-type="${type}">deselect all ${meta.label.toLowerCase()}</button>
            `;
            groupDiv.appendChild(header);

            // URL rows (first 20 visible, rest hidden)
            urls.forEach((url, idx) => {
                const row = document.createElement('div');
                row.className = 'cu-url-row';
                row.dataset.url = url;
                row.dataset.type = type;
                const badge = type === 'included' ? ' <span class="cu-included-badge">[included]</span>' : '';
                row.innerHTML = `<input type="checkbox" class="cu-row-cb" data-url="${esc(url)}" data-type="${type}" checked>
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
        const numEl      = document.getElementById('cu-credit-num');
        const desEl      = document.getElementById('cu-credit-deselected');
        const selected   = selectedUrls.length;
        const total      = discoveredUrls.length + includedUrls.length;
        const deselected = total - selected;

        if (!badge) return;
        badge.style.display = '';
        numEl.textContent = selected;

        if (deselected > 0) {
            desEl.textContent = `(${deselected} deselected)`;
            desEl.style.display = '';
        } else {
            desEl.style.display = 'none';
        }
    }

    // --- Step 2: Reserve + Submit ---

    document.getElementById('cu-btn-next-1').addEventListener('click', function () {
        // Include-only path: no discovery ran, populate state from textarea
        if (discoveredUrls.length === 0) {
            const includeList = getIncludedUrls();
            if (includeList.length === 0) return; // nothing to scan
            selectedUrls     = includeList;
            discoveredUrls   = includeList;
            groupedUrls      = { page: [], post: [], other: [], included: includeList };
            totalPages       = includeList.length;
        }
        showStep(2);
        // Use selectedUrls.length — only charge for URLs that will actually be scanned
        post('cu_scanner_reserve_job', { page_count: selectedUrls.length })
            .then(res => {
                if (!res.success) { showStep(1); alert('Error: ' + res.data); return; }
                const job_token = res.data.job_token;
                post('cu_scanner_submit_job', { urls: selectedUrls, job_token })
                    .then(res2 => {
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
        pollTimer = setInterval(pollProgress, 2000);
    }

    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
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
        const pages     = data.pages || [];
        const completed = data.completed || 0;
        const total     = data.total || totalPages;

        document.getElementById('cu-progress-bar').value = total ? (completed / total) * 100 : 0;
        document.getElementById('cu-progress-text').textContent = `${completed} / ${total}`;

        const tbody = document.getElementById('cu-pages-tbody');
        pages.forEach((page, idx) => {
            const globalIdx   = lastPageIndex + idx;
            const existing    = document.getElementById('cu-row-' + globalIdx);
            const safe        = (page.safe_count ?? 0);
            const agg         = (page.aggressive_count ?? 0);
            const statusLabel = page.status === 'done' ? '\u2713 Done' : page.status === 'error' ? '\u2717 Error' : '\u2026';
            if (existing) {
                existing.innerHTML = rowHtml(page.url, statusLabel, safe, agg);
            } else {
                const tr = document.createElement('tr');
                tr.id = 'cu-row-' + globalIdx;
                tr.innerHTML = rowHtml(page.url, statusLabel, safe, agg);
                tbody.appendChild(tr);
            }
        });

        lastPageIndex += pages.length;

        if (data.status === 'complete' || data.status === 'failed') {
            stopPolling();
            if (data.status === 'complete') {
                buildResult();
            } else {
                post('cu_scanner_handle_failure').then(() => {
                    showStep(1);
                    alert('Scan failed. Credits have been released. You may retry the scan.');
                });
            }
        }
    }

    function rowHtml(url, status, safe, agg) {
        return `<td>${esc(url)}</td><td>${esc(status)}</td><td>${esc(safe)}</td><td>${esc(agg)}</td>`;
    }

    function buildResult() {
        post('cu_scanner_build_result', { job_id: scanJobId, job_token: scanJobToken })
            .then(res => {
                if (!res.success) { alert('Error building result: ' + res.data); return; }
                const d = res.data;
                restoreStep4( scanJobId, d.safe_count, d.aggressive_count, d.can_push );
                localStorage.setItem( 'cu_scanner_result', JSON.stringify({
                    job_id:     scanJobId,
                    safe_count: d.safe_count,
                    agg_count:  d.aggressive_count,
                    can_push:   d.can_push,
                }) );
            });
    }

    function restoreStep4( jobId, safeCount, aggCount, canPush ) {
        document.getElementById('cu-result-summary').textContent =
            `Scan complete. ${safeCount} safe rules, ${aggCount} aggressive rules generated.`;
        const dlBtn = document.getElementById('cu-btn-download');
        dlBtn.href = ajax + '?action=cu_scanner_download_json&job_id=' + jobId + '&nonce=' + nonce;
        dlBtn.setAttribute('download', 'cu-scanner-' + jobId + '.json');
        if (canPush) document.getElementById('cu-btn-push').style.display = '';
        showStep(4);
    }

    // --- Cancel ---

    document.getElementById('cu-btn-cancel').addEventListener('click', function () {
        if (!confirm('Cancel this scan? Credits will not be charged.')) return;
        stopPolling();
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
            restoreStep4( d.job_id, d.safe_count, d.agg_count, d.can_push );
        } catch (_e) {
            localStorage.removeItem('cu_scanner_result');
        }
    }());

    detectPlugins();
}());
