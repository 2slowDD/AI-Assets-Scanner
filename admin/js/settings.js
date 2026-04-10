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
                });
        });

        // Auto-refresh balance on page load
        refresh.click();
    });
}());
