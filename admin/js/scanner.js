(function () {
    'use strict';

    const ajax  = cuScanner.ajaxUrl;
    const nonce = cuScanner.nonce;

    let discoveredUrls = [];
    let scanJobId      = null;
    let scanJobToken   = null;
    let railwayUrl     = null;
    let pollTimer      = null;
    let lastPageIndex  = 0;
    let totalPages     = 0;
    let hasSoftBlocks  = false;

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

    function showStep(n) {
        document.querySelectorAll('.cu-step').forEach(el => el.style.display = 'none');
        const el = document.getElementById('step-' + n);
        if (el) el.style.display = 'block';
    }

    // --- Step 1: Detect plugins + Discover ---

    function detectPlugins() {
        post('cu_scanner_detect_plugins').then(res => {
            if (!res.success) return;
            const warnings = document.getElementById('cu-plugin-warnings');
            const d = res.data;
            let html = '';

            hasSoftBlocks = Object.keys(d.soft_block || {}).length > 0;
            Object.entries(d.soft_block || {}).forEach(([name, reason]) => {
                const id = 'override-' + name.replace(/\s+/g, '-');
                html += `<div class="notice notice-error">
                    <p><strong>${name}:</strong> ${reason}</p>
                    <label><input type="checkbox" class="cu-soft-block-override" data-plugin="${name}" id="${id}" />
                    I have disabled ${name} \u2014 proceed anyway</label></div>`;
            });
            Object.entries(d.soft_warn || {}).forEach(([name, reason]) => {
                html += `<div class="notice notice-warning"><p><strong>${name}:</strong> ${reason}</p></div>`;
            });
            Object.keys(d.auto_bypass || {}).forEach(slug => {
                html += `<div class="notice notice-info"><p><strong>${slug}</strong> detected \u2014 bypass applied automatically.</p></div>`;
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

    document.getElementById('cu-btn-discover').addEventListener('click', function () {
        this.disabled = true;
        this.textContent = 'Discovering\u2026';
        post('cu_scanner_discover_pages', {
            excluded_urls: document.getElementById('cu-excluded-urls').value.split('\n').filter(Boolean),
        }).then(res => {
            this.disabled = false;
            this.textContent = 'Re-discover';
            if (!res.success) { alert('Discovery failed: ' + res.data); return; }
            discoveredUrls = res.data.urls;
            totalPages = discoveredUrls.length;
            document.getElementById('cu-credit-preview').textContent =
                `This scan will use ${totalPages} credit${totalPages !== 1 ? 's' : ''}.`;
            const container = document.getElementById('cu-discovered-urls');
            container.innerHTML = `<p>${totalPages} pages found.</p>` +
                discoveredUrls.slice(0, 20).map(u => `<div>${u}</div>`).join('') +
                (totalPages > 20 ? `<div>\u2026 and ${totalPages - 20} more</div>` : '');
            document.getElementById('cu-btn-next-1').style.display = '';
        });
    });

    // --- Step 2: Reserve + Submit ---

    document.getElementById('cu-btn-next-1').addEventListener('click', function () {
        showStep(2);
        post('cu_scanner_reserve_job', { page_count: totalPages }).then(res => {
            if (!res.success) { showStep(1); alert('Error: ' + res.data); return; }
            const job_token = res.data.job_token;
            post('cu_scanner_submit_job', { urls: discoveredUrls, job_token }).then(res2 => {
                if (!res2.success) { showStep(1); alert('Error: ' + res2.data); return; }
                scanJobId     = res2.data.job_id;
                scanJobToken  = res2.data.job_token;
                railwayUrl    = res2.data.railway_url;
                lastPageIndex = 0;
                showStep(3);
                startPolling();
            });
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
                // Fallback to WordPress proxy
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
        return `<td>${url}</td><td>${status}</td><td>${safe}</td><td>${agg}</td>`;
    }

    function buildResult() {
        post('cu_scanner_build_result', { job_id: scanJobId, job_token: scanJobToken })
            .then(res => {
                if (!res.success) { alert('Error building result: ' + res.data); return; }
                const d = res.data;
                document.getElementById('cu-result-summary').textContent =
                    `Scan complete. ${d.safe_count} safe rules, ${d.aggressive_count} aggressive rules generated.`;
                const dlBtn = document.getElementById('cu-btn-download');
                dlBtn.href = ajax + '?action=cu_scanner_download_json&job_id=' + scanJobId + '&nonce=' + nonce;
                dlBtn.setAttribute('download', 'cu-scanner-' + scanJobId + '.json');
                if (d.can_push) {
                    document.getElementById('cu-btn-push').style.display = '';
                }
                showStep(4);
            });
    }

    // --- Cancel ---

    document.getElementById('cu-btn-cancel').addEventListener('click', function () {
        if (!confirm('Cancel this scan? Credits will not be charged.')) return;
        stopPolling();
        post('cu_scanner_cancel_job').then(() => { showStep(1); });
    });

    // --- Push to CU ---

    document.getElementById('cu-btn-push').addEventListener('click', function () {
        this.disabled = true;
        post('cu_scanner_push_to_cu', { job_id: scanJobId }).then(res => {
            const el = document.getElementById('cu-push-result');
            if (res.success) {
                el.innerHTML = `<div class="notice notice-success"><p>Rules added to Code Unloader: ${res.data.safe_count} safe, ${res.data.aggressive_count} aggressive.</p></div>`;
            } else {
                el.innerHTML = `<div class="notice notice-error"><p>Error: ${res.data}</p></div>`;
                this.disabled = false;
            }
        });
    });

    // --- Init ---
    detectPlugins();
}());
