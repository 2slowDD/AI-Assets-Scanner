<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap" id="cu-scanner-app" data-current-step="1">
<h1 class="screen-reader-text">AI Assets Scanner</h1>
<h2 class="screen-reader-text cu-admin-notice-anchor">AI Assets Scanner notices</h2>
<div class="cu-wrap">

    <!-- Header (step label updated by JS via data-step-label) -->
    <div class="cu-header">
        <img class="cu-header-logo"
             src="<?php echo esc_url( CU_SCANNER_URL . 'admin/images/ai-assets-scanner-logo.png' ); ?>"
             alt="AI Assets Scanner" />
        <div class="cu-header-text">
            <h2>AI Assets Scanner <small class="cu-header-version">v<?php echo esc_html( CU_SCANNER_VERSION ); ?></small></h2>
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
            <div class="cu-pip is-active" id="cu-pip-1" aria-label="Discover pages" title="Discover pages"></div>
            <div class="cu-pip" id="cu-pip-2" aria-label="Reserve credits" title="Reserve credits"></div>
            <div class="cu-pip" id="cu-pip-3" aria-label="Scan pages" title="Scan pages"></div>
            <div class="cu-pip" id="cu-pip-4" aria-label="Review recommendations" title="Review recommendations"></div>
        </div>
    </div>

    <div class="cu-scanner-layout">
    <main class="cu-scanner-main">

    <!-- Step 1: Discovery & Filtering -->
    <div id="step-1" class="cu-step cu-step--active cu-body">
        <section class="cu-panel cu-readiness-card" id="cu-readiness-card" aria-labelledby="cu-readiness-title">
            <div class="cu-panel-heading">
                <div>
                    <span class="cu-eyebrow">Pre-scan check</span>
                    <h2 id="cu-readiness-title">Scan readiness</h2>
                    <p>AAS checked the plugins and services that can affect scan accuracy.</p>
                </div>
                <span class="cu-readiness-state" id="cu-readiness-state">Checking&hellip;</span>
            </div>
            <div id="cu-plugin-warnings"></div>
            <div class="cu-readiness-summary" id="cu-readiness-summary" hidden></div>
            <div class="notice notice-warning inline" id="cu-bot-notice" style="display:none">
                <p><strong>Before you scan:</strong> If Cloudflare, WordFence, or another bot-protection
                tool is active on this site, temporarily disable rate limiting and bot blocking &mdash;
                otherwise the scanner may be blocked or return incomplete results.
                If you use Cloudflare, a permanent WAF bypass rule in
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=cu-scanner-settings' ) ); ?>">Settings</a>
                covers Cloudflare-issued blocks &mdash; but it does <strong>not</strong> raise your own
                server's rate limit, so leave host-level throttling relaxed during the scan.</p>
            </div>
        </section>

        <section class="cu-panel cu-discovery-card" aria-labelledby="cu-discovery-title">
            <div class="cu-panel-heading cu-panel-heading--compact">
                <div>
                    <span class="cu-eyebrow">Scan scope</span>
                    <h2 id="cu-discovery-title">Discover pages to scan</h2>
                    <p>Find your site pages automatically, or provide exact URLs below.</p>
                </div>
            </div>

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
                <span class="cu-filter-pill cu-filter-action" id="cu-btn-et-all">&#9745; Extra Time: all</span>
                <span class="cu-filter-pill cu-filter-action" id="cu-btn-et-none">&#9744; Extra Time: none</span>
                <span class="cu-filter-divider">|</span>
                <span class="cu-filter-pill cu-filter-action" id="cu-btn-select-all">&#9745; Select all</span>
                <span class="cu-filter-pill cu-filter-action" id="cu-btn-deselect-all">&#9744; Deselect all</span>
            </div>

            <!-- Grouped URL list (populated by JS) -->
            <div class="cu-url-list" id="cu-url-list"></div>
        </div>

        <!-- URL inputs: Include + Exclude -->
        <div id="cu-url-inputs" class="cu-url-input-grid">
            <div class="cu-url-input-card">
                <label for="cu-included-urls">Include URLs</label>
                <textarea id="cu-included-urls" rows="4" placeholder="https://example.com/page-one&#10;https://example.com/page-two"></textarea>
                <p class="description">Scan these URLs directly without running Discover Pages.</p>
            </div>
            <div class="cu-url-input-card">
                <label for="cu-excluded-urls">Exclude URLs</label>
                <textarea id="cu-excluded-urls" rows="4" placeholder="https://example.com/private-page"></textarea>
                <p class="description">Deselecting discovered URLs is simpler for most scans.</p>
            </div>
        </div>

        <!-- Credit badge + actions -->
        <div class="cu-action-row" id="cu-action-row-1">
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
        </section>
    </div>

    <!-- Step 2: Reservation -->
    <div id="step-2" class="cu-step cu-body" style="display:none">
        <section class="cu-panel cu-reservation-card" aria-live="polite">
            <span class="cu-state-orbit" aria-hidden="true"></span>
            <span class="cu-eyebrow">Preparing your scan</span>
            <h2>Reserving credits</h2>
            <p>Checking your balance and reserving credits for the selected URLs.</p>
            <span class="spinner is-active"></span>
        </section>
    </div>

    <!-- Step 3: Live Progress -->
    <div id="step-3" class="cu-step cu-body" style="display:none">
        <section class="cu-scan-console" aria-live="polite">
            <div class="cu-scan-radar-panel">
                <div class="cu-sonar-anim" id="cu-sonar-anim-3" style="display:flex">
                    <div class="cu-radar-stage">
                        <svg class="cu-radar-svg" viewBox="0 0 400 400" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Animated scan radar">
                            <defs>
                                <radialGradient id="cu-radar-field" cx="50%" cy="50%" r="50%">
                                    <stop offset="0%" stop-color="#153b3c"/>
                                    <stop offset="68%" stop-color="#102f36"/>
                                    <stop offset="100%" stop-color="#0a1824"/>
                                </radialGradient>
                                <linearGradient id="cu-radar-wedge" x1="0" y1="0" x2="1" y2="1">
                                    <stop offset="0%" stop-color="#6ef5a4" stop-opacity=".64"/>
                                    <stop offset="100%" stop-color="#6ef5a4" stop-opacity="0"/>
                                </linearGradient>
                                <filter id="cu-radar-glow" x="-300%" y="-300%" width="700%" height="700%">
                                    <feGaussianBlur stdDeviation="5" result="blur"/>
                                    <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
                                </filter>
                            </defs>
                            <circle cx="200" cy="200" r="184" fill="url(#cu-radar-field)" stroke="#2f6570" stroke-width="2"/>
                            <g class="cu-radar-grid" fill="none" stroke="#4d8c8c" stroke-width="1">
                                <circle cx="200" cy="200" r="144"/><circle cx="200" cy="200" r="104"/><circle cx="200" cy="200" r="64"/>
                                <path d="M16 200H384M200 16V384M70 70L330 330M330 70L70 330"/>
                            </g>
                            <g class="cu-radar-sweep">
                                <path d="M200 200 L200 16 A184 184 0 0 1 330 70 Z" fill="url(#cu-radar-wedge)"/>
                                <line x1="200" y1="200" x2="200" y2="16" stroke="#8dffb7" stroke-width="3" stroke-linecap="round" filter="url(#cu-radar-glow)"/>
                            </g>
                            <circle class="cu-radar-blip cu-radar-blip--1" cx="130" cy="94" r="5"/>
                            <circle class="cu-radar-blip cu-radar-blip--2" cx="292" cy="118" r="6"/>
                            <circle class="cu-radar-blip cu-radar-blip--3" cx="330" cy="244" r="5"/>
                            <circle class="cu-radar-blip cu-radar-blip--4" cx="250" cy="316" r="7"/>
                            <circle class="cu-radar-blip cu-radar-blip--5" cx="108" cy="286" r="6"/>
                            <circle class="cu-radar-blip cu-radar-blip--6" cx="76" cy="188" r="4"/>
                            <circle class="cu-radar-blip cu-radar-blip--7" cx="226" cy="142" r="4"/>
                            <circle class="cu-radar-core" cx="200" cy="200" r="6" filter="url(#cu-radar-glow)"/>
                        </svg>
                        <div class="cu-radar-progress-card">
                            <div class="cu-radar-progress-heading"><strong>Scan progress</strong><span id="cu-progress-text">0 / 0</span></div>
                            <progress id="cu-progress-bar" value="0" max="100"></progress>
                            <span class="cu-sonar-label">Scanning selected URLs&hellip;</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="cu-scan-details">
                <div class="cu-scan-details-heading">
                    <span class="cu-scan-signal" aria-hidden="true"></span>
                    <div>
                        <span class="cu-eyebrow">Active scan</span>
                        <h2>Probe and page checks</h2>
                        <p>Live access, bypass, and asset results appear here as the scan progresses.</p>
                    </div>
                    <span class="cu-live-state">In progress</span>
                </div>
                <div id="cu-target-stack-notice"></div>
                <div class="cu-scan-validation-list">
                    <div class="cu-scan-validation-row"><span class="cu-validation-icon">&#10003;</span><div><strong>Access checks</strong><small>Connectivity and reserved scan access validated.</small></div><span>Ready</span></div>
                    <div class="cu-scan-validation-row"><span class="cu-validation-icon">&#10003;</span><div><strong>Optimizer bypass</strong><small>Detected bypass rules are applied per URL when available.</small></div><span>Applied</span></div>
                </div>
                <div class="cu-scan-note">
                    <p><strong>You can safely close this tab.</strong> The scan runs in the background and results will be waiting when you return.</p>
                    <p>Do not edit the content of pages being scanned while the scan is active.</p>
                </div>
                <div class="cu-live-pages">
                    <div class="cu-live-pages-heading"><strong>Live URL status</strong><span>Updated automatically</span></div>
                    <table id="cu-pages-table" class="wp-list-table widefat striped">
                        <thead><tr><th>URL</th><th>Status</th></tr></thead>
                        <tbody id="cu-pages-tbody"></tbody>
                    </table>
                </div>
                <div class="cu-scan-footer-actions">
                    <button id="cu-btn-cancel" class="button button-secondary">Cancel Scan</button>
                    <span>You can leave this page while the scan continues.</span>
                </div>
            </div>
        </section>
    </div>

    <!-- Step 4: Output -->
    <div id="step-4" class="cu-step cu-body" style="display:none">
        <div id="cu-banner-area"></div>
        <div class="cu-results-shell">
            <div class="cu-results-primary">
                <section class="cu-completion-card" aria-labelledby="cu-complete-title">
                    <div class="cu-completion-heading">
                        <div>
                            <span class="cu-complete-mark" aria-hidden="true">&#10003;</span>
                            <h2 id="cu-complete-title">Scan complete</h2>
                            <p id="cu-complete-copy">Recommendations are ready to review.</p>
                        </div>
                        <span class="cu-scan-id" id="cu-complete-scan-id"></span>
                    </div>
                </section>

                <section class="cu-metric-grid cu-metric-card-grid" aria-label="Scan summary">
                    <div class="cu-metric"><strong id="cu-metric-urls">0</strong><span>URLs scanned</span></div>
                    <div class="cu-metric cu-metric--safe"><strong id="cu-metric-safe">0</strong><span>Safe rules</span></div>
                    <div class="cu-metric cu-metric--aggressive"><strong id="cu-metric-aggressive">0</strong><span>Aggressive rules</span></div>
                    <div class="cu-metric"><strong id="cu-metric-credits">0</strong><span>Credits used</span></div>
                    <div class="cu-metric"><strong id="cu-metric-balance">&mdash;</strong><span>Total credits left</span></div>
                </section>

                <section class="cu-panel cu-recommendations-card" aria-labelledby="cu-recommendations-title">
                    <div class="cu-recommendations-copy">
                        <h2 id="cu-recommendations-title">Recommendations</h2>
                        <p id="cu-recommendations-copy">Sync is the safest way to add these rules while keeping your existing Code Unloader setup.</p>
                        <small id="cu-recommendations-footnote">You can undo the last Push/Sync after applying changes.</small>
                    </div>
                    <div class="cu-step4-action-row" id="cu-step4-action-row">
                        <button id="cu-btn-sync" class="button button-primary" style="display:none">Sync with Code Unloader</button>
                        <button id="cu-btn-push" class="button button-secondary" style="display:none">Push to Code Unloader</button>
                        <a id="cu-btn-download" class="button button-secondary" href="#">Download JSON</a>
                    </div>
                    <div id="cu-push-result"></div>
                </section>

                <section class="cu-kept-assets-panel" id="cu-kept-assets-panel" hidden aria-live="polite">
                    <span class="cu-kept-assets-icon" aria-hidden="true">&#128737;</span>
                    <div class="cu-kept-assets-copy">
                        <strong id="cu-kept-assets-summary">0 crucial assets kept</strong>
                        <span>Protected and required dependencies remain loaded.</span>
                    </div>
                    <button type="button" class="cu-kept-details-toggle" id="cu-kept-details-toggle" aria-expanded="false" aria-controls="cu-kept-protection-note">View details <span aria-hidden="true">&#8964;</span></button>
                    <p id="cu-kept-protection-note" class="cu-kept-protection" hidden></p>
                </section>

                <section class="cu-panel cu-results-card" aria-labelledby="cu-results-heading">
                    <div class="cu-results-heading-row">
                        <h2 id="cu-results-heading">Page results <span id="cu-results-success-count">0 of 0 successful</span></h2>
                    </div>
                    <div class="cu-results-summary-block">
                        <p id="cu-result-summary"></p>
                        <?php // Result-truth credit-back line. Sits with the summary, NOT in
                              // #cu-push-result, which the external-only branch overwrites wholesale
                              // (AC-15). Inserted between summary and url-list so the ordering
                              // ScannerPageMarkupTest pins stays true. Filled by restoreStep4. ?>
                        <p id="cu-result-refund"></p>
                    </div>
                    <div id="cu-result-url-list"></div>
                    <div class="cu-results-footer-actions">
                        <div class="cu-rescan-row">
                            <button type="button" class="button button-secondary cu-btn-run-another">Run Another Scan</button>
                            <button type="button" class="button button-secondary cu-btn-rescan-et" style="display:none">Rescan ET Candidates</button>
                            <button type="button" class="button button-secondary cu-btn-rescan-noopt-all" style="display:none">Rescan 0-Results URLs</button>
                        </div>
                        <a href="https://wpservice.pro/contact/" target="_blank" rel="noopener" class="cu-results-contact">Found a bug? Get in touch</a>
                    </div>
                </section>

                <section class="cu-speed-footer">
                    <img src="<?php echo esc_url( CU_SCANNER_URL . 'admin/images/iconSA-256x256.png' ); ?>" alt="Speed Analyzer">
                    <div><span class="cu-eyebrow">Measure the improvement</span><h3>Compare performance with Speed Analyzer</h3><p>Check how much the applied recommendations improved your pages.</p></div>
                    <a href="https://wordpress.org/plugins/speed-analyzer/" target="_blank" rel="noopener noreferrer" class="button button-secondary">Get Speed Analyzer</a>
                </section>
            </div>

            <aside class="cu-results-guidance" aria-label="Recommendation guidance">
                <div class="cu-guidance-card cu-guidance-card--legend">
                    <h3>Recommendations S / A / N</h3>
                    <p>Review the recommendations for each URL.</p>
                    <dl class="cu-guidance-list">
                        <div><dt><span class="cu-legend-token cu-legend-token--safe">Safe</span></dt><dd>Not loaded on the page</dd></div>
                        <div><dt><span class="cu-legend-token cu-legend-token--aggressive">Aggressive</span></dt><dd>Loaded but tested safe to remove</dd></div>
                        <div><dt><span class="cu-legend-token cu-legend-token--needed">Needed</span></dt><dd>Loaded with usage or protected</dd></div>
                        <div><dt><span class="cu-kept-chip">&#128737; Kept</span></dt><dd>Protected by whitelist</dd></div>
                    </dl>
                </div>
                <div class="cu-guidance-card cu-guidance-card--apply">
                    <h3>Ready to apply</h3>
                    <div class="cu-ready-list">
                        <div><span class="cu-ready-icon cu-ready-icon--rules" aria-hidden="true">&#10021;</span><p><strong><span id="cu-ready-rule-total">0</span> unload rules will be added</strong><small><span id="cu-apply-safe">0</span> safe + <span id="cu-apply-aggressive">0</span> aggressive</small></p></div>
                        <div><span class="cu-ready-icon cu-ready-icon--credits" aria-hidden="true">&#36;</span><p><strong id="cu-ready-credits">0 credits were used</strong><small id="cu-ready-balance" hidden></small></p></div>
                    </div>
                </div>
                <div class="cu-guidance-card cu-guidance-card--status">
                    <h3>Code Unloader status</h3>
                    <div class="cu-cu-status-row"><span class="cu-cu-status-icon" aria-hidden="true">&#10003;</span><div><strong id="cu-cu-status-title">Checking result state</strong><p id="cu-cu-status-copy">Available actions depend on the scanned URLs.</p></div></div>
                    <a id="cu-cu-settings-link" href="<?php echo esc_url( admin_url( 'admin.php?page=code-unloader' ) ); ?>" class="button button-secondary">View Code Unloader Settings</a>
                </div>
                <div class="cu-guidance-card cu-guidance-card--next">
                    <h3>What&rsquo;s next?</h3>
                    <strong id="cu-next-step-title">Review the scan result</strong>
                    <p id="cu-next-step-copy">Choose an available action after reviewing the URL rows.</p>
                </div>
                <div class="cu-guidance-card cu-guidance-card--undo">
                    <button type="button" id="cu-btn-undo-last-push-sync" class="button cu-undo-last-push-sync" disabled>Undo last Push/Sync</button>
                    <p>Revert the last applied changes. Available for 7 days after action.</p>
                </div>
            </aside>
        </div>
    </div>

    </main>
    <aside class="cu-admin-sidebar cu-step-one-sidebar">
        <div class="cu-sidebar-box cu-sidebar-box--cta">
            <h3 class="cu-sidebar-heading">Measure Your Gains</h3>
            <p class="cu-sidebar-text">Check by how much AI Assets Scanner improved your pages with our Speed Analyzer plugin.</p>
            <a href="https://wordpress.org/plugins/speed-analyzer/" target="_blank" rel="noopener noreferrer" class="cu-sidebar-sa-link">
                <img src="<?php echo esc_url( CU_SCANNER_URL . 'admin/images/iconSA-256x256.png' ); ?>" alt="Speed Analyzer" class="cu-sidebar-sa-icon">
            </a>
            <a href="https://wordpress.org/plugins/speed-analyzer/" target="_blank" rel="noopener noreferrer" class="button button-secondary cu-sidebar-btn">
                Get Speed Analyzer
            </a>
        </div>
    </aside>
    </div>

</div>
</div>
