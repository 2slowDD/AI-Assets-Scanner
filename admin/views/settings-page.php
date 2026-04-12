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
                            <a href="https://wpservice.pro/our-products/ai-assets-scanner/#cu-pricing-inner" target="_blank" class="button button-primary cu-balance-btn">+ Buy Credits</a>
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

    <div class="cu-body" style="margin-top:24px">
        <h3 style="margin-top:0">Cloudflare WAF Bypass <small style="font-weight:normal;font-size:12px;color:#a7aaad;">(Advanced)</small></h3>
        <p>If your site uses Cloudflare Bot Fight Mode or Super Bot Fight Mode, create a custom WAF rule
           so the scanner passes through automatically &mdash; no need to disable protection before each scan.</p>
        <ol>
            <li>Log in to <strong>Cloudflare Dashboard</strong> &rarr; select your domain &rarr; <strong>Security &rarr; WAF &rarr; Custom Rules</strong></li>
            <li>Click <strong>Create rule</strong></li>
            <li>Set the expression (use Edit expression / plain text):<br>
                <code style="display:inline-block;margin-top:4px;padding:4px 8px;background:#f0f0f1;border-radius:3px">http.request.headers["x-cu-scanner"][0] eq "<?php echo esc_html( $scanner_secret ); ?>"</code>
            </li>
            <li>Set Action: <strong>Skip</strong> &rarr; check <em>All remaining custom rules</em> and <em>Skip Bot Fight Mode</em></li>
            <li>Click <strong>Deploy</strong></li>
        </ol>
        <p>The scanner will bypass Cloudflare bot checks automatically on every scan.</p>
        <p><strong>WordFence users:</strong> Go to <strong>WordFence &rarr; Firewall &rarr; Allowlisted IPs</strong> and add the
           Railway server IP. Note: Railway IPs can change on redeploy &mdash; temporarily disabling rate limiting
           before a scan is more reliable.</p>
    </div>

</div>
</div>
