<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap cu-admin-page" id="cu-scanner-history">
<h1 class="screen-reader-text">AI Assets Scanner history</h1>
<h2 class="screen-reader-text cu-admin-notice-anchor">AI Assets Scanner notices</h2>
<div class="cu-wrap">

    <div class="cu-header">
        <img class="cu-header-logo"
             src="<?php echo esc_url( CU_SCANNER_URL . 'admin/images/ai-assets-scanner-logo.png' ); ?>"
             alt="AI Assets Scanner" />
        <div class="cu-header-text">
            <h2>AI Assets Scanner <small class="cu-header-version">v<?php echo esc_html( CU_SCANNER_VERSION ); ?></small></h2>
            <span class="cu-step-label">Scan history</span>
        </div>
        <svg class="cu-header-icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" width="36" height="36" aria-hidden="true">
            <circle cx="10" cy="10" r="8.5"  stroke="#72aee6" stroke-width="1.2" opacity="0.3"/>
            <circle cx="10" cy="10" r="5.5"  stroke="#72aee6" stroke-width="1.2" opacity="0.55"/>
            <circle cx="10" cy="10" r="2.8"  stroke="#72aee6" stroke-width="1.2" opacity="0.85"/>
            <circle cx="10" cy="10" r="1"    fill="#72aee6"/>
            <line x1="10" y1="10" x2="16.5" y2="3.5" stroke="#72aee6" stroke-width="1.2" stroke-linecap="round"/>
        </svg>
        <span class="cu-header-by">by <a href="https://wpservice.pro/" target="_blank" rel="noopener">WPservice.pro</a></span>
    </div>

    <main class="cu-history-body">
        <?php
        // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file included within a class method; variables are local to method scope, not global.
        $history = ( new CUScanner\ScanHistory() )->get_all();
        $cu_total_scans = count( $history );
        $cu_total_pages = 0;
        $cu_total_credits = 0;
        $cu_total_recommendations = 0;
        foreach ( $history as $cu_summary_record ) {
            $cu_total_pages += (int) ( $cu_summary_record['page_count'] ?? 0 );
            $cu_total_credits += (int) ( $cu_summary_record['credits_used'] ?? 0 );
            $cu_total_recommendations += (int) ( $cu_summary_record['safe_count'] ?? 0 );
            $cu_total_recommendations += (int) ( $cu_summary_record['aggressive_count'] ?? 0 );
        }
        ?>
        <section class="cu-history-summary" aria-label="Scan history summary">
            <div><strong><?php echo esc_html( $cu_total_scans ); ?></strong><span>Total scans</span></div>
            <div><strong><?php echo esc_html( $cu_total_pages ); ?></strong><span>URLs scanned</span></div>
            <div><strong><?php echo esc_html( $cu_total_credits ); ?></strong><span>Credits used</span></div>
            <div><strong><?php echo esc_html( $cu_total_recommendations ); ?></strong><span>Recommendations</span></div>
        </section>
        <?php
        if ( empty( $history ) ) : ?>
            <section class="cu-history-empty">
                <span class="dashicons dashicons-chart-area" aria-hidden="true"></span>
                <h2>No scans yet.</h2>
                <p>Your completed scans, credit usage, and recommendation counts will appear here.</p>
                <a class="button button-primary" href="?page=cu-scanner">Run your first scan</a>
            </section>
        <?php else : ?>
            <section class="cu-history-table-card" aria-labelledby="cu-history-table-title">
            <div class="cu-history-table-heading">
                <div><span class="cu-eyebrow">Activity</span><h2 id="cu-history-table-title">Recent scans</h2><p>Re-download completed reports or export your full history.</p></div>
                <div class="cu-history-actions">
                <button type="button" id="cu-history-export" class="button">
                    <?php esc_html_e( 'Export to ZIP', 'AI-Assets-Scanner' ); ?>
                </button>
                <button type="button" id="cu-history-delete" class="button button-link-delete">
                    <?php esc_html_e( 'Delete all history', 'AI-Assets-Scanner' ); ?>
                </button>
            </div>
            </div>
            <div class="cu-history-table-scroll">
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>Date</th><th>Domain</th><th>Pages</th><th>Credits</th>
                        <th>Safe Rules</th><th>Aggressive Rules</th><th>Status</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $history as $record ) : ?>
                    <?php
                    $cu_status = (string) ( $record['status'] ?? '' );
                    $cu_status_classes = [ 'complete', 'partial', 'failed', 'cancelled', 'error' ];
                    $cu_status_modifier = in_array( $cu_status, $cu_status_classes, true ) ? $cu_status : 'unknown';
                    ?>
                    <tr>
                        <td><?php echo esc_html( $record['created_at'] ); ?></td>
                        <td class="cu-history-domain"><?php echo esc_html( $record['domain'] ); ?></td>
                        <td><?php echo esc_html( $record['page_count'] ); ?></td>
                        <td><?php
                            // Result-truth: gross charge, annotated with what came back.
                            // The two numbers are shown side by side rather than netted —
                            // credits_used keeps its existing meaning everywhere.
                            // ⚠️ ?? 0 is required: create_record() does not seed this key,
                            // so up to MAX_RECORDS existing rows lack it entirely.
                            $cu_refunded = (int) ( $record['credits_refunded'] ?? 0 );
                            echo esc_html( $record['credits_used'] );
                            if ( $cu_refunded > 0 ) {
                                echo ' (' . esc_html( $cu_refunded ) . ' returned)';
                            }
                        ?></td>
                        <td><?php echo esc_html( $record['safe_count'] ); ?></td>
                        <td><?php echo esc_html( $record['aggressive_count'] ); ?></td>
                        <td><span class="cu-history-status cu-history-status--<?php echo esc_attr( $cu_status_modifier ); ?>"><?php if ( 'partial' === $cu_status ) {
                                // Show the actual charge (credits_used), NOT a "X of Y pages" count:
                                // credits_used adds +1 per Extra-Time page, so it can diverge from the
                                // completed-page count the live banner shows (data.completed). Labelling it
                                // "credits charged" keeps the History tab honest about the unit.
                                $cu_partial_credits = (int) $record['credits_used'];
                                $cu_partial_refund  = (int) ( $record['credits_refunded'] ?? 0 );
                                echo 'Partial &mdash; ' . esc_html( $cu_partial_credits ) . ' credit' . ( 1 === $cu_partial_credits ? '' : 's' ) . ' charged';
                                if ( $cu_partial_refund > 0 ) {
                                    echo ', ' . esc_html( $cu_partial_refund ) . ' returned';
                                }
                            } elseif ( 'complete' === $cu_status ) {
                                echo 'Complete';
                            } else {
                                echo esc_html( $cu_status );
                            } ?></span></td>
                        <td>
                            <?php if ( in_array( $cu_status, [ 'complete', 'partial' ], true ) ) :
                                $dl_url = admin_url( 'admin-ajax.php' ) . '?action=cu_scanner_download_json&job_id=' . rawurlencode( $record['job_id'] ) . '&nonce=' . wp_create_nonce( 'cu_scanner_nonce' );
                            ?>
                                <a href="<?php echo esc_url( $dl_url ); ?>" class="button button-small">Re-download</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            </section>
        <?php
        // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        endif; ?>
    </main>

</div>
</div>
