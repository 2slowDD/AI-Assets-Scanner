<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap" id="cu-scanner-settings">
<div class="cu-wrap">

    <div class="cu-header">
        <img class="cu-header-logo"
             src="<?php echo esc_url( CU_SCANNER_URL . 'admin/images/ai-assets-scanner-logo.png' ); ?>"
             alt="AI Assets Scanner" />
        <div class="cu-header-text">
            <h2>AI Assets Scanner <small style="font-size:11px;font-weight:normal;color:#a7aaad;vertical-align:middle;">v<?php echo esc_html( CU_SCANNER_VERSION ); ?></small></h2>
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

    <div class="cu-body">
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
        // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        ?>
        <form id="cu-scanner-settings-form">
            <table class="form-table">
                <tr>
                    <th><label for="cu_api_key">API Key</label></th>
                    <td>
                        <input type="text" id="cu_api_key" name="api_key"
                               value="<?php echo esc_attr( $masked ); ?>"
                               <?php if ( $is_masked ) : ?>data-masked="1"<?php endif; ?>
                               autocomplete="off"
                               class="regular-text" placeholder="cusk_..." />
                        <p class="description">Get your API key from <a href="https://wpservice.pro" target="_blank">wpservice.pro</a></p>
                        <?php if ( $settings->is_pending_free_key( $api_key ) ) : ?>
                            <p class="description">Free API key activation is pending. Please try again later.</p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Credit Balance</th>
                    <td>
                        <div class="cu-balance-widget">
                            <div class="cu-balance-card" id="cu-balance-card">
                                <svg class="cu-balance-icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" width="22" height="22">
                                    <circle cx="10" cy="10" r="9" stroke="#c8a000" stroke-width="1.4"/>
                                    <text x="10" y="14.5" text-anchor="middle" fill="#c8a000" font-size="11" font-weight="700" font-family="sans-serif">C</text>
                                </svg>
                                <div class="cu-balance-info">
                                    <span class="cu-balance-num" id="cu-credit-balance">—</span>
                                    <span class="cu-balance-label">credits</span>
                                </div>
                            </div>
                            <button type="button" id="cu-refresh-balance" class="button cu-balance-btn" title="Refresh balance">
                                <svg width="13" height="13" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align:-2px">
                                    <path d="M3 10a7 7 0 1 1 1.6 4.4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                    <polyline points="3,14.4 3,10 7.4,10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                Refresh
                            </button>
                            <a href="<?php echo esc_url( $buy_url ); ?>" target="_blank" class="button button-primary cu-balance-btn">+ Buy Credits</a>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th><label>HTTP Basic Auth</label></th>
                    <td>
                        <p class="description">For staging sites protected by server-level HTTP authentication.</p>
                        <input type="text" name="http_user" placeholder="Username"
                               value="<?php echo esc_attr( $http_auth['username'] ?? '' ); ?>" class="regular-text" />
                        <input type="password" name="http_pass" placeholder="Password" value="" class="regular-text" />
                        <?php if ( $http_auth ) : ?>
                            <label><input type="checkbox" name="clear_http_auth" value="1" /> Clear saved credentials</label>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="cu-scanner-secret">Scanner Secret</label></th>
                    <td>
                        <input type="text" id="cu-scanner-secret"
                               value="<?php echo esc_attr( $scanner_secret ); ?>"
                               readonly class="regular-text" style="font-family:monospace" />
                        <button type="button" id="cu-copy-secret" class="button" style="margin-left:6px">Copy</button>
                        <p class="description">Used to create a Cloudflare WAF bypass rule. Do not share this value publicly.</p>
                    </td>
                </tr>
            </table>
            <?php wp_nonce_field( 'cu_scanner_settings_nonce', 'nonce' ); ?>
            <p class="submit">
                <button type="submit" class="button button-primary">Save Settings</button>
            </p>
        </form>
        <div id="cu-settings-message" style="display:none"></div>
    </div>

    <div class="cu-body" id="cu-cloudflare-waf-bypass" style="margin-top:24px">
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

            <h3 style="margin-top:0">CDN Rate Limiting Exemption</h3>
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

            <h3 style="margin-top:0">CDN Rate Limiting Exemption <small style="font-weight:normal;font-size:12px;color:#a7aaad;">(Optional)</small></h3>
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

    </div>

</div>
</div>
