<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap">
    <h1>CU Scanner — Scan History</h1>
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
