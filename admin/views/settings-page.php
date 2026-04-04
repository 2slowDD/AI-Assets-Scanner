<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap" id="cu-scanner-settings">
<div class="cu-wrap">

    <div class="cu-header">
        <svg class="cu-header-icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" width="36" height="36">
            <circle cx="10" cy="10" r="8.5"  stroke="#72aee6" stroke-width="1.2" opacity="0.3"/>
            <circle cx="10" cy="10" r="5.5"  stroke="#72aee6" stroke-width="1.2" opacity="0.55"/>
            <circle cx="10" cy="10" r="2.8"  stroke="#72aee6" stroke-width="1.2" opacity="0.85"/>
            <circle cx="10" cy="10" r="1"    fill="#72aee6"/>
            <line x1="10" y1="10" x2="16.5" y2="3.5" stroke="#72aee6" stroke-width="1.2" stroke-linecap="round"/>
        </svg>
        <div class="cu-header-text">
            <h2>CU Scanner <small style="font-size:11px;font-weight:normal;color:#a7aaad;vertical-align:middle;">v<?php echo esc_html( CU_SCANNER_VERSION ); ?></small></h2>
            <span class="cu-step-label">Settings</span>
        </div>
    </div>

    <div class="cu-body">
        <?php
        $settings  = new CUScanner\Settings();
        $api_key   = $settings->get_api_key();
        $http_auth = $settings->get_http_auth();
        ?>
        <form id="cu-scanner-settings-form">
            <table class="form-table">
                <tr>
                    <th><label for="cu_api_key">API Key</label></th>
                    <td>
                        <input type="text" id="cu_api_key" name="api_key"
                               value="<?php echo esc_attr( $api_key ); ?>"
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
                            <a href="https://wpservice.pro/shop/?orderby=date" target="_blank" class="button button-primary cu-balance-btn">+ Buy Credits</a>
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
            </table>
            <?php wp_nonce_field( 'cu_scanner_settings_nonce', 'nonce' ); ?>
            <p class="submit">
                <button type="submit" class="button button-primary">Save Settings</button>
            </p>
        </form>
        <div id="cu-settings-message" style="display:none"></div>
    </div>

</div>
</div>
