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

    $(document).on('heartbeat-tick', function (event, response) {
        // Wire-shape: response.aias_badge is 'green' | 'red' | null.
        // hasOwnProperty distinguishes "key absent" (no MenuBadge installed) from
        // "key present, null" (MenuBadge says no badge).
        if (response && Object.prototype.hasOwnProperty.call(response, 'aias_badge')) {
            applyState(response.aias_badge);
        }
    });
})(jQuery);
