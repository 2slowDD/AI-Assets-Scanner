/**
 * AAS 1.4.3 — Menu badge dynamic update via WP Heartbeat.
 * Server (CUScanner\MenuBadge::filter_heartbeat) returns {aias_badge: 'green'|'red'|null}.
 * This script syncs the DOM badge node accordingly on every heartbeat-tick.
 */
(function ($) {
    'use strict';

    function findMenuLink() {
        return document.querySelector('#toplevel_page_cu-scanner > a.menu-top');
    }

    function findBadge() {
        return document.querySelector('#toplevel_page_cu-scanner .aias-menu-badge');
    }

    function applyState(state) {
        var link = findMenuLink();
        if (!link) return;

        var existing = findBadge();

        if (state === null) {
            if (existing && existing.parentNode) {
                existing.parentNode.removeChild(existing);
            }
            return;
        }

        // state === 'green' || 'red'
        var cls = 'aias-menu-badge aias-menu-badge--' + state;
        if (existing) {
            existing.className = cls;          // re-color in place
            existing.textContent = '!';
        } else {
            var span = document.createElement('span');
            span.className = cls;
            span.setAttribute('aria-label', 'Unseen scan result');
            span.textContent = '!';
            link.appendChild(document.createTextNode(' '));
            link.appendChild(span);
        }
    }

    // 1.4.4 — background active-job poller. Closes the architectural gap where
    // the 1.4.3 badge only appeared after the operator returned to AAS (because
    // cu_scanner_history's status flip from 'queued' → 'complete' requires the
    // client-side cu_scanner_build_result AJAX to fire, which only happens on
    // the AAS scanner page). Now: on every Heartbeat tick (~15s), if there's
    // an active job in sessionStorage, this script polls Railway directly and
    // triggers the appropriate completion handler when the scan reaches a
    // terminal state — even when the operator is on another wp-admin page.
    //
    // Single-tab scope: sessionStorage is per-tab, so this only works in the
    // tab that started the scan. Multi-tab support is a future iteration.
    //
    // Concurrency: if AAS tab is open and also polling via scanner.js, both
    // can fire cu_scanner_build_result. The PHP handler is idempotent on
    // already-'complete' records — second call just re-writes the same data.

    function maybeCheckActiveJob() {
        var stored = sessionStorage.getItem('cu_scanner_active_job');
        if (!stored) return;

        var job;
        try {
            job = JSON.parse(stored);
        } catch (e) {
            sessionStorage.removeItem('cu_scanner_active_job');
            return;
        }
        if (!job || !job.job_id || !job.job_token || !job.railway_url) {
            sessionStorage.removeItem('cu_scanner_active_job');
            return;
        }

        // Direct fetch to Railway (mirrors scanner.js pollProgress). Fallback
        // to plugin AJAX poll on network/CORS failure.
        fetch(job.railway_url + '/jobs/' + job.job_id + '/status?from=0', {
            headers: { 'Authorization': 'Bearer ' + job.job_token }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) { handleStatus(job, data); })
            .catch(function () {
                if (!window.aiasMenuBadgeData) return;
                $.post(window.aiasMenuBadgeData.ajaxurl, {
                    action:    'cu_scanner_poll_status',
                    job_id:    job.job_id,
                    job_token: job.job_token,
                    from:      0,
                    nonce:     window.aiasMenuBadgeData.nonce
                }).then(function (res) {
                    if (res && res.success) {
                        handleStatus(job, res.data);
                    }
                });
            });
    }

    function handleStatus(job, data) {
        if (!data || !data.status) return;

        if (data.status === 'complete') {
            triggerBuildResult(job);
        } else if (data.status === 'failed') {
            triggerHandleFailure();
        } else if (data.status === 'killed') {
            triggerHandleKilled();
        } else if (data.status === 'cancelled_timeout') {
            // Terminal but no AAS-side action needed; just stop polling.
            sessionStorage.removeItem('cu_scanner_active_job');
        }
        // queued / in_progress / etc: no-op, next tick re-polls.
    }

    function triggerBuildResult(job) {
        if (!window.aiasMenuBadgeData) return;
        $.post(window.aiasMenuBadgeData.ajaxurl, {
            action:    'cu_scanner_build_result',
            job_id:    job.job_id,
            job_token: job.job_token,
            nonce:     window.aiasMenuBadgeData.nonce
        }).then(function (res) {
            if (res && res.success && res.data) {
                // Mirror scanner.js localStorage write so AAS-return shows
                // step 4 (results) directly without an in-progress flash.
                // external_only is approximated as false — banner data is
                // a one-shot live-render concern and is intentionally skipped
                // on background completion.
                try {
                    localStorage.setItem('cu_scanner_result', JSON.stringify({
                        job_id:        job.job_id,
                        safe_count:    res.data.safe_count,
                        agg_count:     res.data.aggressive_count,
                        can_push:      res.data.can_push,
                        external_only: false,
                        total_pages:   res.data.total_pages || 0,
                        scan_id:       res.data.scan_id || '',
                        pages:         res.data.pages || []
                    }));
                } catch (_storageErr) {
                    // localStorage quota or disabled — non-fatal; the badge
                    // still appears via the next Heartbeat tick.
                }
            }
            sessionStorage.removeItem('cu_scanner_active_job');
            // Server-side cu_scanner_history is now 'complete'; next Heartbeat
            // tick's filter_heartbeat returns aias_badge:'green' and applyState
            // injects the badge.
        });
    }

    function triggerHandleFailure() {
        if (!window.aiasMenuBadgeData) return;
        $.post(window.aiasMenuBadgeData.ajaxurl, {
            action: 'cu_scanner_handle_failure',
            nonce:  window.aiasMenuBadgeData.nonce
        }).always(function () {
            sessionStorage.removeItem('cu_scanner_active_job');
        });
    }

    function triggerHandleKilled() {
        if (!window.aiasMenuBadgeData) return;
        $.post(window.aiasMenuBadgeData.ajaxurl, {
            action: 'cu_scanner_handle_killed',
            nonce:  window.aiasMenuBadgeData.nonce
        }).always(function () {
            sessionStorage.removeItem('cu_scanner_active_job');
        });
    }

    $(document).on('heartbeat-tick', function (event, response) {
        // Wire-shape: response.aias_badge is 'green' | 'red' | null.
        // hasOwnProperty distinguishes "key absent" (no MenuBadge installed) from
        // "key present, null" (MenuBadge says no badge).
        if (response && Object.prototype.hasOwnProperty.call(response, 'aias_badge')) {
            applyState(response.aias_badge);
        }

        // 1.4.4 — also check active-job state for background completion.
        maybeCheckActiveJob();
    });

    // 1.4.10 — browser-driven setInterval poller. Decouples badge state sync
    // from operator navigation. The 1.4.5/1.4.7-diag/1.4.8-diag investigation
    // proved that heartbeat_received is bypassed on the operator's WP install
    // (another plugin replaces wp_ajax_heartbeat). The 1.4.9 admin_init poller
    // works but only fires when the operator navigates — operator idling on
    // one admin page during the scan-end transition misses the state flip.
    //
    // This setInterval fires every 30s independent of operator navigation, hits
    // the new cu_scanner_get_badge_state AJAX endpoint, and applies the
    // returned badge state to the DOM. The endpoint internally drives the same
    // Railway poll → ScanHistory update path the admin_init poller uses, so
    // the badge appears within ~30s of scan completion regardless of where
    // the operator is in wp-admin.
    function pollBadgeState() {
        if (!window.aiasMenuBadgeData) return;
        $.post(window.aiasMenuBadgeData.ajaxurl, {
            action: 'cu_scanner_get_badge_state',
            nonce:  window.aiasMenuBadgeData.nonce
        }).then(function (res) {
            if (!res || !res.success || !res.data) return;
            // res.data.badge: 'green' | 'red' | null
            applyState(res.data.badge);

            // 1.4.11 — when the server returns a result snapshot (badge='green'
            // and there's an unseen complete scan), populate cu_scanner_result
            // in localStorage so scanner.js init at admin/js/scanner.js:1349
            // finds the result on AAS-return and restores Step 4. Idempotent:
            // only writes when localStorage is missing OR stores a different
            // job_id (avoids clobbering a fresher entry written by scanner.js
            // itself on the AAS tab).
            if (res.data.result && res.data.result.job_id) {
                try {
                    var existing = localStorage.getItem('cu_scanner_result');
                    var existingJob = null;
                    if (existing) {
                        try { existingJob = (JSON.parse(existing) || {}).job_id; } catch (_e) {}
                    }
                    if (existingJob !== res.data.result.job_id) {
                        localStorage.setItem('cu_scanner_result', JSON.stringify(res.data.result));
                    }
                } catch (_storageErr) {
                    // localStorage quota or disabled — non-fatal; the badge
                    // still appears and operator can re-scan if needed.
                }
            }
        });
    }

    // First poll on page load (after a short delay so jQuery + DOM are ready),
    // then every 30s thereafter. 30s matches the operator-visibility cadence
    // we want — fast enough to catch scan-end inside a typical "look away then
    // back" window, slow enough to keep server load negligible.
    setTimeout(pollBadgeState, 2000);
    setInterval(pollBadgeState, 30000);
})(jQuery);
