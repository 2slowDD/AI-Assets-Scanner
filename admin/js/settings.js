(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const form    = document.getElementById('cu-scanner-settings-form');
        const msg     = document.getElementById('cu-settings-message');
        const balance = document.getElementById('cu-credit-balance');
        const refresh = document.getElementById('cu-refresh-balance');

        const apiKeyInput = document.getElementById('cu_api_key');
        if (apiKeyInput) {
            apiKeyInput.addEventListener('input', function () {
                this.removeAttribute('data-masked');
            });
        }

        function showMsg(text, type) {
            msg.textContent = text;
            msg.className   = 'notice notice-' + type + ' is-dismissible';
            msg.style.display = 'block';
        }

        function setBalance(val) {
            const card = document.getElementById('cu-balance-card');
            balance.textContent = val;
            if (card) {
                const n = parseInt(val, 10);
                card.classList.toggle('cu-balance-low', !isNaN(n) && n < 10);
            }
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const data = new FormData(form);
            data.append('action', 'cu_scanner_save_settings');
            if (apiKeyInput && apiKeyInput.dataset.masked) {
                data.delete('api_key');
                data.append('keep_api_key', '1');
            }
            fetch(cuScannerSettings.ajaxUrl, { method: 'POST', body: data })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        showMsg('Settings saved. Credit balance: ' + res.data.credits, 'success');
                        setBalance(res.data.credits);
                    } else {
                        showMsg('Error: ' + res.data, 'error');
                    }
                });
        });

        refresh.addEventListener('click', function () {
            balance.textContent = '…';
            const data = new FormData();
            data.append('action', 'cu_scanner_fetch_balance');
            data.append('nonce', cuScannerSettings.nonce);
            fetch(cuScannerSettings.ajaxUrl, { method: 'POST', body: data })
                .then(r => r.json())
                .then(res => {
                    setBalance(res.success ? res.data.balance : '—');
                    if (res.success && res.data.api_key_updated) {
                        showMsg('Your paid AAS Scanner API key was received and saved. Credit balance: ' + res.data.balance, 'success');
                        if (apiKeyInput) {
                            apiKeyInput.value = 'Saved paid key';
                            apiKeyInput.dataset.masked = '1';
                        }
                    }
                });
        });

        // Auto-refresh balance on page load
        refresh.click();
        window.addEventListener('focus', function () {
            refresh.click();
        });

        const copyBtn = document.getElementById('cu-copy-secret');
        if (copyBtn) {
            copyBtn.addEventListener('click', function () {
                const secretInput = document.getElementById('cu-scanner-secret');
                if (!secretInput) return;
                navigator.clipboard.writeText(secretInput.value).then(function () {
                    const orig = copyBtn.textContent;
                    copyBtn.textContent = 'Copied!';
                    setTimeout(function () { copyBtn.textContent = orig; }, 2000);
                });
            });
        }

        // CF expression copy button (rendered by CloudflareAdapter::instructionsHtml).
        const copyExprBtn = document.getElementById('cu-copy-cf-expression');
        if (copyExprBtn) {
            // Check glyph shown briefly on success.
            const CHECK_SVG = '<svg class="cu-cdn-copy-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="20 6 9 17 4 12"></polyline></svg>';
            copyExprBtn.addEventListener('click', function () {
                const exprEl = document.getElementById('cu-cf-rule-expression');
                if (!exprEl) return;
                navigator.clipboard.writeText(exprEl.textContent).then(function () {
                    const orig = copyExprBtn.innerHTML;
                    copyExprBtn.innerHTML = CHECK_SVG;
                    copyExprBtn.setAttribute('aria-label', 'Copied');
                    setTimeout(function () {
                        copyExprBtn.innerHTML = orig;
                        copyExprBtn.setAttribute('aria-label', 'Copy expression');
                    }, 2000);
                }).catch(function () {
                    // Clipboard API rejects on non-secure context / denied permission.
                    copyExprBtn.setAttribute('aria-label', 'Copy failed');
                    copyExprBtn.title = 'Copy failed — select the text and copy manually';
                });
            });
        }

        // Helper: POST ack_cdn action and call callback on success.
        function postAckCdn(cdnName, onSuccess) {
            if (!cdnName) return;
            const nonceField = form.querySelector('[name="nonce"]');
            const nonceVal   = nonceField ? nonceField.value : cuScannerSettings.nonce;
            const data = new FormData();
            data.append('action', 'cu_scanner_ack_cdn');
            data.append('nonce',  nonceVal);
            data.append('cdn',    cdnName);
            fetch(cuScannerSettings.ajaxUrl, { method: 'POST', body: data })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success && typeof onSuccess === 'function') {
                        onSuccess();
                    }
                });
        }

        // Auto-detected CDN ack button.
        const ackBtn = document.getElementById('cu-ack-cdn');
        if (ackBtn) {
            ackBtn.addEventListener('click', function () {
                const cdnName = ackBtn.dataset.cdn;
                postAckCdn(cdnName, function () {
                    ackBtn.disabled    = true;
                    ackBtn.textContent = 'Saved!';
                });
            });
        }

        // Manual CDN selector — show the matching instructions block.
        const cdnSelect = document.getElementById('cu-cdn-select');
        if (cdnSelect) {
            cdnSelect.addEventListener('change', function () {
                document.querySelectorAll('.cu-cdn-instructions-block').forEach(function (el) {
                    el.style.display = 'none';
                });
                const chosen = cdnSelect.value;
                if (chosen) {
                    const block = document.getElementById('cu-cdn-instructions-' + chosen);
                    if (block) block.style.display = '';
                }
            });
        }

        // Manual CDN ack buttons (one per adapter block).
        document.querySelectorAll('.cu-ack-cdn-manual').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const cdnName = btn.dataset.cdn;
                postAckCdn(cdnName, function () {
                    btn.disabled    = true;
                    btn.textContent = 'Saved!';
                });
            });
        });
    });
}());
