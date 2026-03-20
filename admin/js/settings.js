(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const form    = document.getElementById('cu-scanner-settings-form');
        const msg     = document.getElementById('cu-settings-message');
        const balance = document.getElementById('cu-credit-balance');
        const refresh = document.getElementById('cu-refresh-balance');

        function showMsg(text, type) {
            msg.textContent = text;
            msg.className   = 'notice notice-' + type + ' is-dismissible';
            msg.style.display = 'block';
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const data = new FormData(form);
            data.append('action', 'cu_scanner_save_settings');
            fetch(cuScannerSettings.ajaxUrl, { method: 'POST', body: data })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        showMsg('Settings saved. Credits: ' + res.data.credits, 'success');
                        balance.textContent = res.data.credits;
                    } else {
                        showMsg('Error: ' + res.data, 'error');
                    }
                });
        });

        refresh.addEventListener('click', function () {
            const data = new FormData();
            data.append('action', 'cu_scanner_fetch_balance');
            data.append('nonce', cuScannerSettings.nonce);
            fetch(cuScannerSettings.ajaxUrl, { method: 'POST', body: data })
                .then(r => r.json())
                .then(res => {
                    balance.textContent = res.success ? res.data.balance : '(error)';
                });
        });

        // Auto-refresh balance on page load
        refresh.click();
    });
}());
