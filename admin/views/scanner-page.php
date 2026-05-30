<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap" id="cu-scanner-app">
<div class="cu-wrap">

    <!-- Header (step label updated by JS via data-step-label) -->
    <div class="cu-header">
        <img class="cu-header-logo"
             src="<?php echo esc_url( CU_SCANNER_URL . 'admin/images/ai-assets-scanner-logo.png' ); ?>"
             alt="AI Assets Scanner" />
        <div class="cu-header-text">
            <h2>AI Assets Scanner <small style="font-size:11px;font-weight:normal;color:#a7aaad;vertical-align:middle;">v<?php echo esc_html( CU_SCANNER_VERSION ); ?></small></h2>
            <span class="cu-step-label" id="cu-step-label">Step 1 &mdash; Discover Pages</span>
        </div>
        <svg class="cu-header-icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" width="36" height="36">
            <circle cx="10" cy="10" r="8.5"  stroke="#72aee6" stroke-width="1.2" opacity="0.3"/>
            <circle cx="10" cy="10" r="5.5"  stroke="#72aee6" stroke-width="1.2" opacity="0.55"/>
            <circle cx="10" cy="10" r="2.8"  stroke="#72aee6" stroke-width="1.2" opacity="0.85"/>
            <circle cx="10" cy="10" r="1"    fill="#72aee6"/>
            <line x1="10" y1="10" x2="16.5" y2="3.5" stroke="#72aee6" stroke-width="1.2" stroke-linecap="round"/>
        </svg>
        <span class="cu-header-by">by <a href="https://wpservice.pro/" target="_blank" rel="noopener">WPservice.pro</a></span>
        <div class="cu-step-pips" id="cu-step-pips">
            <div class="cu-pip is-active" id="cu-pip-1"></div>
            <div class="cu-pip" id="cu-pip-2"></div>
            <div class="cu-pip" id="cu-pip-3"></div>
            <div class="cu-pip" id="cu-pip-4"></div>
        </div>
    </div>

    <div class="cu-scanner-layout">
    <main class="cu-scanner-main">

    <!-- Step 1: Discovery & Filtering -->
    <div id="step-1" class="cu-step cu-step--active cu-body">
        <div id="cu-plugin-warnings"></div>

        <!-- Sonar animation (shown while AJAX is in-flight) -->
        <div class="cu-sonar-anim" id="cu-sonar-anim" style="display:none">
            <svg class="cu-sonar-svg" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg">
                <rect width="120" height="120" rx="8" fill="#1a2744"/>
                <circle class="cu-ring cu-ring-1" cx="60" cy="60" r="44" stroke="#72aee6" stroke-width="1.5" fill="none"/>
                <circle class="cu-ring cu-ring-2" cx="60" cy="60" r="30" stroke="#72aee6" stroke-width="1.5" fill="none"/>
                <circle class="cu-ring cu-ring-3" cx="60" cy="60" r="16" stroke="#72aee6" stroke-width="1.5" fill="none"/>
                <path class="cu-sweep-wedge" d="M60 60 L60 16 A44 44 0 0 1 91 29 Z" fill="#72aee6" opacity="0.12"/>
                <g class="cu-sweep-arm">
                    <line x1="60" y1="60" x2="60" y2="16" stroke="#72aee6" stroke-width="1.5" stroke-linecap="round"/>
                </g>
                <circle cx="60" cy="60" r="3" fill="#72aee6"/>
            </svg>
            <p class="cu-sonar-label">Discovering pages&hellip;</p>
        </div>

        <!-- Discover row (top, normal-width button) -->
        <div class="cu-discover-row">
            <button id="cu-btn-discover" class="button button-primary">Discover Pages</button>
            <span class="description">or fill Include URLs below to scan specific pages</span>
            <div class="cu-spacer"></div>
            <span class="cu-contact-hint">Found a bug or want to get in touch?
                <a href="https://wpservice.pro/contact/" target="_blank" rel="noopener" class="button button-secondary cu-contact-btn">Get in touch</a>
            </span>
        </div>

        <!-- URL list area (hidden until discovery completes) -->
        <div id="cu-url-list-area" style="display:none">
            <!-- Top "Start Scan" — mirrors the bottom button; appears once URLs are selected (after Discover) -->
            <div class="cu-action-row cu-action-row--top">
                <div class="cu-spacer"></div>
                <button id="cu-btn-next-1-top" class="button button-primary" style="display:none">Start Scan &rarr;</button>
            </div>
            <!-- Filter bar (counts populated by JS) -->
            <div class="cu-filter-bar" id="cu-filter-bar">
                <span class="cu-filter-pill is-active" data-filter="all"   id="cu-pill-all">All</span>
                <span class="cu-filter-pill"           data-filter="page"  id="cu-pill-page" style="display:none">Pages</span>
                <span class="cu-filter-pill"           data-filter="post"  id="cu-pill-post" style="display:none">Posts</span>
                <span class="cu-filter-pill"           data-filter="other" id="cu-pill-other" style="display:none">Other</span>
                <span class="cu-filter-pill"           data-filter="included" id="cu-pill-included" style="display:none">Included</span>
                <span class="cu-filter-divider">|</span>
                <span class="cu-filter-pill cu-filter-action" id="cu-btn-select-all">&#9745; Select all</span>
                <span class="cu-filter-pill cu-filter-action" id="cu-btn-deselect-all">&#9744; Deselect all</span>
            </div>

            <!-- Grouped URL list (populated by JS) -->
            <div class="cu-url-list" id="cu-url-list"></div>
        </div>

        <!-- URL inputs: Include + Exclude -->
        <div id="cu-url-inputs">
            <div style="margin-bottom:8px">
                <label>Include URLs (one per line):<br>
                    <textarea id="cu-included-urls" rows="4" style="width:100%"></textarea>
                </label>
                <p class="description" style="margin-top:4px">Scan these URLs directly without running Discover Pages.</p>
            </div>
            <div>
                <label>Exclude URLs (one per line):<br>
                    <textarea id="cu-excluded-urls" rows="4" style="width:100%"></textarea>
                </label>
                <p class="description" style="margin-top:4px">Tip: deselecting URLs above is simpler for most cases.</p>
            </div>
        </div>

        <!-- Credit badge + actions -->
        <div class="cu-action-row" id="cu-action-row-1">
            <div class="notice notice-warning inline" id="cu-bot-notice" style="display:none">
                <p><strong>Before you scan:</strong> If Cloudflare, WordFence, or another bot-protection
                tool is active on this site, temporarily disable rate limiting and bot blocking &mdash;
                otherwise the scanner may be blocked or return incomplete results.
                Cloudflare users can set up a permanent WAF bypass rule instead &mdash;
                see <a href="<?php echo esc_url( admin_url( 'admin.php?page=cu-scanner-settings' ) ); ?>">Settings</a> for instructions.</p>
            </div>
            <div class="cu-credit-badge" id="cu-credit-badge" style="display:none">
                <span class="cu-credit-num" id="cu-credit-num">0</span>
                credits for this scan
                <span class="cu-credit-deselected" id="cu-credit-deselected" style="display:none"></span>
            </div>
            <div class="cu-credit-badge" id="cu-balance-badge" style="display:none">
                <span class="cu-credit-num" id="cu-balance-num">—</span>
                credits available
            </div>
            <div class="cu-spacer"></div>
            <button id="cu-btn-next-1" class="button button-primary" style="display:none">Start Scan &rarr;</button>
        </div>
    </div>

    <!-- Step 2: Reservation -->
    <div id="step-2" class="cu-step cu-body" style="display:none">
        <p>Checking your balance and reserving credits for this scan.</p>
        <span class="spinner is-active"></span>
    </div>

    <!-- Step 3: Live Progress -->
    <div id="step-3" class="cu-step cu-body" style="display:none">
        <div class="cu-sonar-anim" id="cu-sonar-anim-3" style="display:flex">
            <svg class="cu-sonar-svg" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg">
                <rect width="120" height="120" rx="8" fill="#1a2744"/>
                <circle class="cu-ring cu-ring-1" cx="60" cy="60" r="44" stroke="#72aee6" stroke-width="1.5" fill="none"/>
                <circle class="cu-ring cu-ring-2" cx="60" cy="60" r="30" stroke="#72aee6" stroke-width="1.5" fill="none"/>
                <circle class="cu-ring cu-ring-3" cx="60" cy="60" r="16" stroke="#72aee6" stroke-width="1.5" fill="none"/>
                <path class="cu-sweep-wedge" d="M60 60 L60 16 A44 44 0 0 1 91 29 Z" fill="#72aee6" opacity="0.12"/>
                <g class="cu-sweep-arm">
                    <line x1="60" y1="60" x2="60" y2="16" stroke="#72aee6" stroke-width="1.5" stroke-linecap="round"/>
                </g>
                <circle cx="60" cy="60" r="3" fill="#72aee6"/>
            </svg>
            <p class="cu-sonar-label">Scanning&hellip;</p>
        </div>
        <div id="cu-progress-bar-wrap">
            <progress id="cu-progress-bar" value="0" max="100" style="width:100%"></progress>
            <span id="cu-progress-text">0 / 0</span>
        </div>
        <div class="notice notice-info inline" style="margin:12px 0">
            <p><strong>You can safely close this tab</strong> &mdash; the scan runs in the background. Results will be waiting when you return.</p>
            <p>Do not edit the content of pages being scanned while the scan is active.</p>
        </div>
        <table id="cu-pages-table" class="wp-list-table widefat striped">
            <thead><tr><th>URL</th><th>Status</th></tr></thead>
            <tbody id="cu-pages-tbody"></tbody>
        </table>
        <button id="cu-btn-cancel" class="button button-secondary" style="margin-top:12px">Cancel Scan</button>
    </div>

    <!-- Step 4: Output -->
    <div id="step-4" class="cu-step cu-body" style="display:none">
        <div id="cu-banner-area"></div>
        <p id="cu-result-summary"></p>
        <div style="display:flex; gap:16px; margin-top:16px">
            <a id="cu-btn-download" class="button button-primary" href="#">Download CU Import File</a>
            <button id="cu-btn-push" class="button button-primary" style="display:none">Push to Code Unloader</button>
            <button id="cu-btn-sync" class="button button-primary" style="display:none">Sync with Code Unloader</button>
            <a href="https://wpservice.pro/contact/" target="_blank" rel="noopener" class="button button-secondary cu-contact-btn" style="margin-left:auto">Found a bug? Get in touch</a>
        </div>
        <div id="cu-push-result" style="margin-top:12px"></div>
        <!-- Run Another Scan — secondary button, mirrored above + below the results table.
             (Batch B will add a "Rescan ET Candidates" button beside each.) -->
        <div class="cu-rescan-row" style="margin-top:16px; display:flex; gap:12px; align-items:center">
            <button type="button" class="button button-secondary cu-btn-run-another">Run Another Scan</button>
        </div>
    <div id="cu-result-url-list"></div>
        <div class="cu-rescan-row" style="margin-top:16px; display:flex; gap:12px; align-items:center">
            <button type="button" class="button button-secondary cu-btn-run-another">Run Another Scan</button>
        </div>
    </div>

    </main>
    <aside class="cu-admin-sidebar">
        <div class="cu-sidebar-box cu-sidebar-box--cta">
            <h3 class="cu-sidebar-heading">Measure Your Gains</h3>
            <p class="cu-sidebar-text">Check by how much AI Assets Scanner improved your pages with our Speed Analyzer plugin.</p>
            <a href="https://wordpress.org/plugins/speed-analyzer/" target="_blank" rel="noopener noreferrer" class="cu-sidebar-sa-link">
                <img src="<?php echo esc_url( CU_SCANNER_URL . 'admin/images/iconSA-256x256.png' ); ?>" alt="Speed Analyzer" class="cu-sidebar-sa-icon">
            </a>
            <a href="https://wordpress.org/plugins/speed-analyzer/" target="_blank" rel="noopener noreferrer" class="button button-primary cu-sidebar-btn">
                Get Speed Analyzer
            </a>
        </div>
    </aside>
    </div>

</div>
</div>
