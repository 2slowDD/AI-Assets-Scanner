<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap" id="cu-scanner-app">
    <h1>CU Scanner</h1>

    <!-- Step 1: Discovery & Filtering -->
    <div id="step-1" class="cu-step cu-step--active">
        <h2>Step 1 — Discover Pages</h2>
        <div id="cu-plugin-warnings"></div>
        <div id="cu-discovered-urls">
            <p><em>Click Discover to find all pages on this site.</em></p>
        </div>
        <div id="cu-exclusions">
            <label>Exclude URLs (one per line):<br>
                <textarea id="cu-excluded-urls" rows="4" style="width:100%"></textarea>
            </label>
        </div>
        <p id="cu-credit-preview"></p>
        <button id="cu-btn-discover" class="button">Discover Pages</button>
        <button id="cu-btn-next-1" class="button button-primary" style="display:none">Start Scan &rarr;</button>
    </div>

    <!-- Step 2: Reservation (shows spinner then auto-advances to step 3) -->
    <div id="step-2" class="cu-step" style="display:none">
        <h2>Step 2 — Reserving Credits&hellip;</h2>
        <p>Checking your balance and reserving credits for this scan.</p>
        <span class="spinner is-active"></span>
    </div>

    <!-- Step 3: Live Progress -->
    <div id="step-3" class="cu-step" style="display:none">
        <h2>Step 3 — Scanning</h2>
        <div id="cu-progress-bar-wrap">
            <progress id="cu-progress-bar" value="0" max="100" style="width:100%"></progress>
            <span id="cu-progress-text">0 / 0</span>
        </div>
        <table id="cu-pages-table" class="wp-list-table widefat striped">
            <thead><tr><th>URL</th><th>Status</th><th>Safe</th><th>Aggressive</th></tr></thead>
            <tbody id="cu-pages-tbody"></tbody>
        </table>
        <button id="cu-btn-cancel" class="button button-secondary">Cancel Scan</button>
    </div>

    <!-- Step 4: Output -->
    <div id="step-4" class="cu-step" style="display:none">
        <h2>Step 4 — Done</h2>
        <p id="cu-result-summary"></p>
        <div style="display:flex; gap:16px; margin-top:16px">
            <a id="cu-btn-download" class="button button-primary" href="#">Download CU Import File</a>
            <button id="cu-btn-push" class="button button-primary" style="display:none">Push to Code Unloader</button>
        </div>
        <div id="cu-push-result" style="margin-top:12px"></div>
        <p style="margin-top:16px"><a href="?page=cu-scanner">Run Another Scan</a></p>
    </div>
</div>
