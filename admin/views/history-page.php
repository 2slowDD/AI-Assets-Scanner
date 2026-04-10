<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap">
<div class="cu-wrap">

    <div class="cu-header">
        <img class="cu-header-logo"
             src="<?php echo esc_url( CU_SCANNER_URL . 'admin/images/ai-assets-scanner-logo.png' ); ?>"
             alt="AI Assets Scanner" />
        <div class="cu-header-text">
            <h2>AI Assets Scanner <small style="font-size:11px;font-weight:normal;color:#a7aaad;vertical-align:middle;">v<?php echo esc_html( CU_SCANNER_VERSION ); ?></small></h2>
            <span class="cu-step-label">Scan History</span>
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
        $history = ( new CUScanner\ScanHistory() )->get_all();
        if ( empty( $history ) ) : ?>
            <p>No scans yet. <a href="?page=cu-scanner">Run your first scan.</a></p>
        <?php else : ?>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>Date</th><th>Domain</th><th>Pages</th><th>Credits</th>
                        <th>Safe Rules</th><th>Aggressive Rules</th><th>Status</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $history as $record ) : ?>
                    <tr>
                        <td><?php echo esc_html( $record['created_at'] ); ?></td>
                        <td><?php echo esc_html( $record['domain'] ); ?></td>
                        <td><?php echo esc_html( $record['page_count'] ); ?></td>
                        <td><?php echo esc_html( $record['credits_used'] ); ?></td>
                        <td><?php echo esc_html( $record['safe_count'] ); ?></td>
                        <td><?php echo esc_html( $record['aggressive_count'] ); ?></td>
                        <td><?php echo esc_html( $record['status'] ); ?></td>
                        <td>
                            <?php if ( $record['status'] === 'complete' ) :
                                $dl_url = admin_url( 'admin-ajax.php' ) . '?action=cu_scanner_download_json&job_id=' . urlencode( $record['job_id'] ) . '&nonce=' . wp_create_nonce( 'cu_scanner_nonce' );
                            ?>
                                <a href="<?php echo esc_url( $dl_url ); ?>" class="button button-small">Re-download</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div>
</div>
