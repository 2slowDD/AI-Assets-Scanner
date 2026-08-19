<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap cu-admin-page" id="cu-scanner-settings">
<h1 class="screen-reader-text">AI Assets Scanner settings</h1>
<h2 class="screen-reader-text cu-admin-notice-anchor">AI Assets Scanner notices</h2>
<div class="cu-wrap">

    <div class="cu-header">
        <img class="cu-header-logo"
             src="<?php echo esc_url( CU_SCANNER_URL . 'admin/images/ai-assets-scanner-logo.png' ); ?>"
             alt="AI Assets Scanner" />
        <div class="cu-header-text">
            <h2>AI Assets Scanner <small class="cu-header-version">v<?php echo esc_html( CU_SCANNER_VERSION ); ?></small></h2>
            <span class="cu-step-label">Settings</span>
        </div>
        <svg class="cu-header-icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" width="36" height="36">
            <circle cx="10" cy="10" r="8.5"  stroke="#72aee6" stroke-width="1.2" opacity="0.3"/>
            <circle cx="10" cy="10" r="5.5"  stroke="#72aee6" stroke-width="1.2" opacity="0.55"/>
            <circle cx="10" cy="10" r="2.8"  stroke="#72aee6" stroke-width="1.2" opacity="0.85"/>
            <circle cx="10" cy="10" r="1"    fill="#72aee6"/>
            <line x1="10" y1="10" x2="16.5" y2="3.5" stroke="#72aee6" stroke-width="1.2" stroke-linecap="round"/>
        </svg>
        <span class="cu-header-by">by <a href="https://wpservice.pro/" target="_blank" rel="noopener">WPservice.pro</a></span>
    </div>

    <main class="cu-settings-grid">
        <?php
        // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file included within a class method; variables are local to method scope, not global.
        $settings  = new CUScanner\Settings();
        $api_key   = $settings->get_api_key();
        $buy_url   = $settings->get_buy_credits_url( $api_key );
        $len       = mb_strlen( $api_key );
        $masked    = ( $len > 12 )
            ? mb_substr( $api_key, 0, 6 ) . str_repeat( '•', $len - 12 ) . mb_substr( $api_key, -6 )
            : $api_key;
        $is_masked = ( $len > 12 );
        $http_auth = $settings->get_http_auth();
        $scanner_secret = $settings->get_scanner_secret();
        $omit_cu_bypass = $settings->get_omit_cu_bypass();
        // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        ?>
        <form id="cu-scanner-settings-form" class="cu-settings-form">
            <section class="cu-settings-card cu-settings-card--account" aria-labelledby="cu-settings-account-title">
                <div class="cu-settings-card-heading">
                    <span class="cu-settings-icon" aria-hidden="true">&#9673;</span>
                    <div><span class="cu-eyebrow">Account</span><h2 id="cu-settings-account-title">API access and credits</h2><p>Connect the scanner and keep track of available scan credits.</p></div>
                </div>
                <div class="cu-settings-field">
                    <label for="cu_api_key">API key</label>
                    <input type="text" id="cu_api_key" name="api_key"
                           value="<?php echo esc_attr( $masked ); ?>"
                           <?php if ( $is_masked ) : ?>data-masked="1"<?php endif; ?>
                           autocomplete="off" class="regular-text" placeholder="cusk_..." />
                    <p class="description">Get your API key from <a href="https://wpservice.pro" target="_blank" rel="noopener">wpservice.pro</a>.</p>
                    <?php if ( $settings->is_pending_free_key( $api_key ) ) : ?>
                        <p class="cu-inline-state cu-inline-state--pending">Free API key activation is pending. Please try again later.</p>
                    <?php endif; ?>
                </div>
                <div class="cu-settings-field cu-settings-field--balance">
                    <span class="cu-settings-label">Credit balance</span>
                    <div class="cu-balance-widget">
                        <div class="cu-balance-card" id="cu-balance-card">
                            <svg class="cu-balance-icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" width="22" height="22" aria-hidden="true">
                                <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="1.4"/>
                                <text x="10" y="14.5" text-anchor="middle" fill="currentColor" font-size="11" font-weight="700" font-family="sans-serif">C</text>
                            </svg>
                            <div class="cu-balance-info"><span class="cu-balance-num" id="cu-credit-balance">&mdash;</span><span class="cu-balance-label">credits available</span></div>
                        </div>
                        <button type="button" id="cu-refresh-balance" class="button cu-balance-btn" title="Refresh balance">Refresh</button>
                        <a href="<?php echo esc_url( $buy_url ); ?>" target="_blank" rel="noopener" class="button button-primary cu-balance-btn">Buy credits</a>
                    </div>
                </div>
            </section>

            <section class="cu-settings-card cu-settings-card--environment" aria-labelledby="cu-settings-environment-title">
                <div class="cu-settings-card-heading">
                    <span class="cu-settings-icon" aria-hidden="true">&#9678;</span>
                    <div><span class="cu-eyebrow">Scan environment</span><h2 id="cu-settings-environment-title">Access and bypass behavior</h2><p>Configure protected staging access and how the scanner sees your pages.</p></div>
                </div>
                <div class="cu-settings-field">
                    <span class="cu-settings-label">HTTP Basic Auth</span>
                    <p class="description">For staging sites protected by server-level HTTP authentication.</p>
                    <div class="cu-input-pair">
                        <input type="text" name="http_user" placeholder="Username" value="<?php echo esc_attr( $http_auth['username'] ?? '' ); ?>" class="regular-text" autocomplete="username" />
                        <input type="password" name="http_pass" placeholder="Password" value="" class="regular-text" autocomplete="new-password" />
                    </div>
                    <?php if ( $http_auth ) : ?>
                        <label class="cu-check-row"><input type="checkbox" name="clear_http_auth" value="1" /> Clear saved credentials</label>
                    <?php endif; ?>
                </div>
                <div class="cu-settings-field">
                    <label for="cu-scanner-secret">Scanner secret</label>
                    <div class="cu-secret-row">
                        <input type="text" id="cu-scanner-secret" value="<?php echo esc_attr( $scanner_secret ); ?>" readonly class="regular-text cu-mono-input" />
                        <button type="button" id="cu-copy-secret" class="button">Copy</button>
                    </div>
                    <p class="description">Used to create a CDN or WAF exemption. Keep this value private.</p>
                </div>
            </section>

            <section class="cu-settings-card cu-settings-card--options" aria-labelledby="cu-settings-options-title">
                <div class="cu-settings-card-heading">
                    <span class="cu-settings-icon" aria-hidden="true">&#9881;</span>
                    <div><span class="cu-eyebrow">Scan behavior</span><h2 id="cu-settings-options-title">Scan options</h2><p>Control how AAS prepares pages before each scan.</p></div>
                </div>
                <div class="cu-settings-options-list">
                    <label for="cu_omit_cu_bypass" class="cu-option-row">
                        <input type="checkbox" id="cu_omit_cu_bypass" name="omit_cu_bypass" value="1" <?php checked( $omit_cu_bypass ); ?> />
                        <span><strong><?php esc_html_e( "Remove Code Unloader's suffix (?nowpcu) from scans", 'ai-assets-scanner' ); ?></strong><small>Scan pages with your existing Code Unloader rules applied.</small></span>
                    </label><span class="cu-help" tabindex="0" aria-label="Scans normally add ?nowpcu to each URL, which switches Code Unloader off so the scanner sees every asset a page can load. Tick this to leave the suffix off. Scans then run with your existing Code Unloader rules applied, the pages as visitors actually receive them. On heavy pages this often surfaces rules an earlier scan missed, because the page loads lighter and the scanner gets further through it. Assets your current rules already unload will not appear in the results, so use Sync with Code Unloader to add new rules on top of your existing ones. Push would replace them."><span class="cu-help-box">Scans normally add <strong>?nowpcu</strong> to each URL, which switches Code Unloader off so the scanner sees every asset a page can load.<br><br>Tick this to leave the suffix off. Scans then run with your existing Code Unloader rules applied &mdash; the pages as visitors actually receive them. On heavy pages this often surfaces rules an earlier scan missed, because the page loads lighter and the scanner gets further through it.<br><br>Assets your current rules already unload won't appear in the results, so use <strong>Sync with Code Unloader</strong> to add new rules on top of your existing ones. <strong>Push</strong> would replace them.</span></span>
                </div>
            </section>

            <?php wp_nonce_field( 'cu_scanner_settings_nonce', 'nonce' ); ?>
            <div class="cu-settings-savebar">
                <div><strong>Save scanner settings</strong><span>Changes apply to future scans.</span></div>
                <button type="submit" class="button button-primary">Save settings</button>
            </div>
            <div id="cu-settings-message" style="display:none"></div>
        </form>

        <section class="cu-settings-card cu-settings-card--cdn" id="cu-cloudflare-waf-bypass" aria-labelledby="cu-settings-cdn-title">
            <div class="cu-settings-card-heading">
                <span class="cu-settings-icon" aria-hidden="true">&#8644;</span>
                <div><span class="cu-eyebrow">Network access</span><h2 id="cu-settings-cdn-title">CDN rate limiting exemption</h2><p>Allow authenticated scanner traffic through your CDN or web application firewall.</p></div>
            </div>
        <?php
        // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file included within a class method; variables are local to method scope, not global.
        $cdn_registry     = \CUScanner\Cdn\Detector::default_registry();
        $detected_cdn     = ( new \CUScanner\Cdn\Detector() )->detect();
        $acknowledged_cdn = ( new \CUScanner\Settings() )->get_acknowledged_cdn();

        $detected_adapter = null;
        if ( null !== $detected_cdn ) {
            foreach ( $cdn_registry->all() as $cdn_adapter ) {
                if ( $cdn_adapter->name() === $detected_cdn ) {
                    $detected_adapter = $cdn_adapter;
                    break;
                }
            }
        }
        // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        ?>

        <?php if ( null !== $detected_adapter ) : ?>

            <h3>Detected network</h3>
            <p>We detected your site is proxied through <strong><?php echo esc_html( ucfirst( $detected_cdn ) ); ?></strong>.
               Configure the exemption below so the scanner can reach your pages without hitting rate limits.</p>

            <?php
            // instructionsHtml() is plugin-authored and self-escapes the secret via esc_html() internally — safe to echo as-is.
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plugin-authored HTML; secret escaped via esc_html() inside instructionsHtml().
            echo $detected_adapter->instructionsHtml( $scanner_secret );
            ?>

            <?php if ( $acknowledged_cdn !== $detected_cdn ) : ?>
                <p style="margin-top:16px">
                    <button type="button"
                            id="cu-ack-cdn"
                            class="button button-primary"
                            data-cdn="<?php echo esc_attr( $detected_cdn ); ?>">
                        I&rsquo;ve configured this exemption
                    </button>
                </p>
            <?php else : ?>
                <p style="margin-top:12px;color:#2a9d55">
                    &#10003; Exemption marked as configured.
                    <button type="button"
                            id="cu-ack-cdn"
                            class="button"
                            data-cdn="<?php echo esc_attr( $detected_cdn ); ?>"
                            style="margin-left:8px">
                        Re-confirm
                    </button>
                </p>
            <?php endif; ?>

        <?php else : ?>

            <h3>Manual network setup <small>(Optional)</small></h3>
            <p>We couldn&rsquo;t automatically detect a CDN on this site. If you use one, select it below
               to see instructions for creating a rate-limit exemption rule.</p>

            <p>
                <label for="cu-cdn-select"><strong>My CDN:</strong></label>
                <select id="cu-cdn-select" style="margin-left:8px">
                    <option value="">— Select CDN —</option>
                    <?php foreach ( $cdn_registry->all() as $cdn_adapter ) : ?>
                        <option value="<?php echo esc_attr( $cdn_adapter->name() ); ?>">
                            <?php echo esc_html( ucfirst( $cdn_adapter->name() ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <?php foreach ( $cdn_registry->all() as $cdn_adapter ) : ?>
                <div id="cu-cdn-instructions-<?php echo esc_attr( $cdn_adapter->name() ); ?>"
                     class="cu-cdn-instructions-block"
                     style="display:none">
                    <?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plugin-authored HTML; secret escaped via esc_html() inside instructionsHtml().
                    echo $cdn_adapter->instructionsHtml( $scanner_secret );
                    ?>
                    <p style="margin-top:16px">
                        <button type="button"
                                id="cu-ack-cdn-<?php echo esc_attr( $cdn_adapter->name() ); ?>"
                                class="button button-primary cu-ack-cdn-manual"
                                data-cdn="<?php echo esc_attr( $cdn_adapter->name() ); ?>">
                            I&rsquo;ve configured this exemption
                        </button>
                    </p>
                </div>
            <?php endforeach; ?>

        <?php endif; ?>

        </section>
    </main>
</div>
