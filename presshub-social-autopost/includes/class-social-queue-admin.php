<?php
/**
 * Admin Queue Dashboard and Log Viewer for PressHub Social Auto-Post.
 *
 * @package PressHub_Social_Autopost
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_Social_Queue_Admin {

    /**
     * Register hooks for admin menu and assets.
     *
     * @return void
     */
    public static function init(): void {
        add_action( 'presshub_social_render_queue_page', [ __CLASS__, 'render_page' ] );
    }

    /**
     * Register the admin submenu page.
     *
     * @return void
     */
    public static function register_menu(): void {
        global $admin_page_hooks;

        // Attach to PressHub Social Settings if present, otherwise options-general.php
        $parent_slug = isset( $admin_page_hooks['presshub_social_settings'] )
            ? 'presshub_social_settings'
            : 'options-general.php';

        add_submenu_page(
            $parent_slug,
            __( 'PressHub Social Queue & Logs', 'presshub-social-autopost' ),
            __( 'Social Queue', 'presshub-social-autopost' ),
            'manage_options',
            'presshub-social-queue',
            [ __CLASS__, 'render_page' ]
        );
    }

    /**
     * Render the admin Queue Dashboard page.
     *
     * @return void
     */
    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'presshub-social-autopost' ) );
        }

        $current_status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
        $current_platform = isset( $_GET['platform'] ) ? sanitize_key( wp_unslash( $_GET['platform'] ) ) : '';
        $paged            = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $per_page         = 25;
        $offset           = ( $paged - 1 ) * $per_page;

        $stats = PressHub_Social_Queue::get_stats();

        $query_filters = [
            'limit'  => $per_page,
            'offset' => $offset,
        ];
        if ( ! empty( $current_status ) ) {
            $query_filters['status'] = $current_status;
        }
        if ( ! empty( $current_platform ) ) {
            $query_filters['platform'] = $current_platform;
        }

        $jobs = PressHub_Social_Queue::query_jobs( $query_filters );
        $ajax_nonce = wp_create_nonce( 'presshub_social_nonce' );
        ?>
        <div class="wrap presshub-social-admin-wrap" data-nonce="<?php echo esc_attr( $ajax_nonce ); ?>">
            <h1 class="wp-heading-inline">
                <span class="dashicons dashicons-share" style="font-size:28px; width:28px; height:28px; vertical-align:middle; margin-right:6px;"></span>
                <?php esc_html_e( 'PressHub Social — Queue & Dispatch Monitor', 'presshub-social-autopost' ); ?>
            </h1>

            <!-- KPI Cards Section -->
            <div class="presshub-kpi-grid">
                <div class="presshub-kpi-card kpi-total">
                    <span class="kpi-number"><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></span>
                    <span class="kpi-label"><?php esc_html_e( 'Total Jobs', 'presshub-social-autopost' ); ?></span>
                </div>
                <div class="presshub-kpi-card kpi-pending">
                    <span class="kpi-number"><?php echo esc_html( number_format_i18n( $stats['pending'] ) ); ?></span>
                    <span class="kpi-label"><?php esc_html_e( 'Pending', 'presshub-social-autopost' ); ?></span>
                </div>
                <div class="presshub-kpi-card kpi-processing">
                    <span class="kpi-number"><?php echo esc_html( number_format_i18n( $stats['processing'] ) ); ?></span>
                    <span class="kpi-label"><?php esc_html_e( 'Processing', 'presshub-social-autopost' ); ?></span>
                </div>
                <div class="presshub-kpi-card kpi-published">
                    <span class="kpi-number"><?php echo esc_html( number_format_i18n( $stats['published'] ) ); ?></span>
                    <span class="kpi-label"><?php esc_html_e( 'Published', 'presshub-social-autopost' ); ?></span>
                </div>
                <div class="presshub-kpi-card kpi-failed">
                    <span class="kpi-number"><?php echo esc_html( number_format_i18n( $stats['failed'] ) ); ?></span>
                    <span class="kpi-label"><?php esc_html_e( 'Failed', 'presshub-social-autopost' ); ?></span>
                </div>
            </div>

            <!-- Toolbar & Filter Bar -->
            <div class="presshub-toolbar">
                <form method="get" class="presshub-filter-form">
                    <input type="hidden" name="page" value="presshub-social-queue" />

                    <select name="platform">
                        <option value=""><?php esc_html_e( 'All Platforms', 'presshub-social-autopost' ); ?></option>
                        <option value="twitter" <?php selected( $current_platform, 'twitter' ); ?>><?php esc_html_e( 'Twitter / X', 'presshub-social-autopost' ); ?></option>
                        <option value="facebook" <?php selected( $current_platform, 'facebook' ); ?>><?php esc_html_e( 'Facebook', 'presshub-social-autopost' ); ?></option>
                        <option value="instagram" <?php selected( $current_platform, 'instagram' ); ?>><?php esc_html_e( 'Instagram', 'presshub-social-autopost' ); ?></option>
                        <option value="tiktok" <?php selected( $current_platform, 'tiktok' ); ?>><?php esc_html_e( 'TikTok', 'presshub-social-autopost' ); ?></option>
                    </select>

                    <select name="status">
                        <option value=""><?php esc_html_e( 'All Statuses', 'presshub-social-autopost' ); ?></option>
                        <option value="pending" <?php selected( $current_status, 'pending' ); ?>><?php esc_html_e( 'Pending', 'presshub-social-autopost' ); ?></option>
                        <option value="processing" <?php selected( $current_status, 'processing' ); ?>><?php esc_html_e( 'Processing', 'presshub-social-autopost' ); ?></option>
                        <option value="published" <?php selected( $current_status, 'published' ); ?>><?php esc_html_e( 'Published', 'presshub-social-autopost' ); ?></option>
                        <option value="failed" <?php selected( $current_status, 'failed' ); ?>><?php esc_html_e( 'Failed', 'presshub-social-autopost' ); ?></option>
                        <option value="cancelled" <?php selected( $current_status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'presshub-social-autopost' ); ?></option>
                    </select>

                    <input type="submit" class="button button-secondary" value="<?php esc_attr_e( 'Filter', 'presshub-social-autopost' ); ?>" />
                    <?php if ( ! empty( $current_status ) || ! empty( $current_platform ) ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=presshub-social-queue' ) ); ?>" class="button button-link"><?php esc_html_e( 'Reset Filters', 'presshub-social-autopost' ); ?></a>
                    <?php endif; ?>
                </form>

                <div class="presshub-queue-actions">
                    <button type="button" class="button button-primary" id="btn-process-due-jobs">
                        <span class="dashicons dashicons-controls-play" style="vertical-align:middle;"></span>
                        <?php esc_html_e( 'Process Due Jobs Now', 'presshub-social-autopost' ); ?>
                    </button>
                    <span class="spinner" id="process-due-spinner"></span>
                </div>
            </div>

            <!-- Queue Table Responsive Wrapper -->
            <div class="presshub-table-responsive">
                <table class="wp-list-table widefat fixed striped presshub-admin-queue-table">
                    <thead>
                        <tr>
                            <th style="width: 45px;"><?php esc_html_e( 'ID', 'presshub-social-autopost' ); ?></th>
                            <th style="width: 130px;"><?php esc_html_e( 'Post Title', 'presshub-social-autopost' ); ?></th>
                            <th style="width: 85px;"><?php esc_html_e( 'Platform', 'presshub-social-autopost' ); ?></th>
                            <th style="width: 80px;"><?php esc_html_e( 'Engine', 'presshub-social-autopost' ); ?></th>
                            <th style="width: 85px;"><?php esc_html_e( 'Status', 'presshub-social-autopost' ); ?></th>
                            <th style="width: 65px;"><?php esc_html_e( 'Attempts', 'presshub-social-autopost' ); ?></th>
                            <th><?php esc_html_e( 'Copy Preview', 'presshub-social-autopost' ); ?></th>
                            <th style="width: 130px;"><?php esc_html_e( 'Scheduled / Dispatched', 'presshub-social-autopost' ); ?></th>
                            <th style="width: 180px;"><?php esc_html_e( 'Actions / Link', 'presshub-social-autopost' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $jobs ) ) : ?>
                            <tr>
                                <td colspan="9" style="text-align:center; padding: 24px; color: #646970;">
                                    <?php esc_html_e( 'No social queue jobs match the current criteria.', 'presshub-social-autopost' ); ?>
                                </td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $jobs as $job ) :
                                $job_id     = (int) $job['id'];
                                $post_id    = (int) $job['post_id'];
                                $post_title = get_the_title( $post_id ) ?: sprintf( __( 'Post #%d', 'presshub-social-autopost' ), $post_id );
                                $status     = (string) ( $job['status'] ?? 'pending' );
                                $engine     = (string) ( $job['engine'] ?? 'api' );
                                $platform   = (string) ( $job['platform'] ?? 'twitter' );
                                $status_cls = match ( $status ) {
                                    'published'  => 'badge-published',
                                    'processing' => 'badge-processing',
                                    'failed'     => 'badge-failed',
                                    'cancelled'  => 'badge-cancelled',
                                    default      => 'badge-pending',
                                };
                            ?>
                                <tr id="job-row-<?php echo esc_attr( $job_id ); ?>">
                                    <td><strong>#<?php echo esc_html( $job_id ); ?></strong></td>
                                    <td>
                                        <a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>" target="_blank" rel="noopener noreferrer">
                                            <?php echo esc_html( wp_trim_words( $post_title, 6 ) ); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="platform-name"><?php echo esc_html( ucfirst( $platform ) ); ?></span>
                                    </td>
                                    <td>
                                        <span class="presshub-badge <?php echo 'automation' === $engine ? 'badge-engine-automation' : 'badge-engine-api'; ?>">
                                            <?php echo esc_html( strtoupper( $engine ) ); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="presshub-status-badge <?php echo esc_attr( $status_cls ); ?>">
                                            <?php echo esc_html( ucfirst( $status ) ); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html( $job['attempts'] . '/' . $job['max_attempts'] ); ?></td>
                                    <td>
                                        <div class="presshub-copy-preview" id="copy-preview-<?php echo esc_attr( $job_id ); ?>" title="<?php echo esc_attr( $job['post_copy'] ); ?>">
                                            <?php echo esc_html( wp_trim_words( $job['post_copy'], 15 ) ); ?>
                                        </div>
                                        <?php if ( ! empty( $job['last_error'] ) ) : ?>
                                            <div class="presshub-error-msg" title="<?php echo esc_attr( $job['last_error'] ); ?>">
                                                <span class="dashicons dashicons-warning" style="font-size:14px; width:14px; height:14px; vertical-align:middle; color:#d63638;"></span>
                                                <?php echo esc_html( wp_trim_words( $job['last_error'], 10 ) ); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ( 'published' === $status && ! empty( $job['dispatched_at'] ) ) : ?>
                                            <small><?php esc_html_e( 'Dispatched:', 'presshub-social-autopost' ); ?></small><br />
                                            <code><?php echo esc_html( $job['dispatched_at'] ); ?></code>
                                        <?php else : ?>
                                            <small><?php esc_html_e( 'Scheduled:', 'presshub-social-autopost' ); ?></small><br />
                                            <code><?php echo esc_html( $job['scheduled_at'] ); ?></code>
                                        <?php endif; ?>
                                    </td>
                                    <td class="presshub-actions-cell">
                                        <?php if ( 'published' === $status && ! empty( $job['external_post_url'] ) ) : ?>
                                            <a href="<?php echo esc_url( $job['external_post_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="button button-small" title="<?php esc_attr_e( 'View live post on platform', 'presshub-social-autopost' ); ?>">
                                                <?php esc_html_e( 'View ↗', 'presshub-social-autopost' ); ?>
                                            </a>
                                        <?php endif; ?>

                                        <!-- Edit & Re-post Button -->
                                        <button type="button" class="button button-small btn-open-edit-modal"
                                            data-job-id="<?php echo esc_attr( $job_id ); ?>"
                                            data-post-id="<?php echo esc_attr( $post_id ); ?>"
                                            data-platform="<?php echo esc_attr( $platform ); ?>"
                                            data-engine="<?php echo esc_attr( $engine ); ?>"
                                            data-status="<?php echo esc_attr( $status ); ?>"
                                            data-post-title="<?php echo esc_attr( $post_title ); ?>"
                                            data-full-copy="<?php echo esc_attr( $job['post_copy'] ); ?>"
                                            title="<?php esc_attr_e( 'Modify copy and post again as a new job', 'presshub-social-autopost' ); ?>">
                                            <span class="dashicons dashicons-edit"></span>
                                            <?php esc_html_e( 'Edit / Re-post', 'presshub-social-autopost' ); ?>
                                        </button>

                                        <!-- Regenerate AI Copy Button -->
                                        <button type="button" class="button button-small btn-regenerate-job-copy"
                                            data-job-id="<?php echo esc_attr( $job_id ); ?>"
                                            data-post-id="<?php echo esc_attr( $post_id ); ?>"
                                            data-platform="<?php echo esc_attr( $platform ); ?>"
                                            title="<?php esc_attr_e( 'Regenerate post copy using AI prompts', 'presshub-social-autopost' ); ?>">
                                            <span class="dashicons dashicons-update"></span>
                                            <?php esc_html_e( 'Regen AI', 'presshub-social-autopost' ); ?>
                                        </button>

                                        <?php if ( 'pending' === $status ) : ?>
                                            <button type="button" class="button button-small button-primary btn-dispatch-now" data-job-id="<?php echo esc_attr( $job_id ); ?>" title="<?php esc_attr_e( 'Dispatch this job now', 'presshub-social-autopost' ); ?>">
                                                <?php esc_html_e( 'Dispatch', 'presshub-social-autopost' ); ?>
                                            </button>
                                            <button type="button" class="button button-small button-link-delete btn-cancel-job" data-job-id="<?php echo esc_attr( $job_id ); ?>" title="<?php esc_attr_e( 'Cancel scheduled job', 'presshub-social-autopost' ); ?>">
                                                <?php esc_html_e( 'Cancel', 'presshub-social-autopost' ); ?>
                                            </button>
                                        <?php endif; ?>

                                        <!-- Retry Button: Available for failed, cancelled, and published jobs -->
                                        <?php if ( 'failed' === $status || 'cancelled' === $status || 'published' === $status ) : ?>
                                            <button type="button" class="button button-small button-secondary btn-retry-job" data-job-id="<?php echo esc_attr( $job_id ); ?>" title="<?php esc_attr_e( 'Retry or re-post this job to social media', 'presshub-social-autopost' ); ?>">
                                                <span class="dashicons dashicons-controls-repeat"></span>
                                                <?php esc_html_e( 'Retry', 'presshub-social-autopost' ); ?>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Edit & Re-post Modal Dialog -->
            <div id="presshub-edit-copy-modal" class="presshub-modal-backdrop" style="display:none;">
                <div class="presshub-modal-container">
                    <div class="presshub-modal-header">
                        <h3 class="presshub-modal-title">
                            <span class="dashicons dashicons-edit" style="vertical-align:middle; margin-right:4px;"></span>
                            <?php esc_html_e( 'Modify Social Post Copy & Preview', 'presshub-social-autopost' ); ?>
                        </h3>
                        <button type="button" class="presshub-modal-close-btn" aria-label="<?php esc_attr_e( 'Close', 'presshub-social-autopost' ); ?>">&times;</button>
                    </div>
                    <div class="presshub-modal-body">
                        <div class="presshub-modal-details-grid">
                            <div><strong><?php esc_html_e( 'Job ID:', 'presshub-social-autopost' ); ?></strong> #<span id="modal-job-id-text"></span></div>
                            <div><strong><?php esc_html_e( 'Platform:', 'presshub-social-autopost' ); ?></strong> <span id="modal-platform-text" class="platform-name"></span></div>
                            <div><strong><?php esc_html_e( 'Status:', 'presshub-social-autopost' ); ?></strong> <span id="modal-status-text"></span></div>
                            <div style="grid-column: 1 / -1;">
                                <strong><?php esc_html_e( 'Article:', 'presshub-social-autopost' ); ?></strong> <span id="modal-title-text"></span>
                            </div>
                        </div>

                        <div class="presshub-modal-editor-section">
                            <div class="presshub-modal-editor-header">
                                <label for="modal-post-copy-input"><strong><?php esc_html_e( 'Post Copy / Preview:', 'presshub-social-autopost' ); ?></strong></label>
                                <span class="presshub-modal-char-count"><span id="modal-char-counter">0</span> <?php esc_html_e( 'chars', 'presshub-social-autopost' ); ?></span>
                            </div>
                            <textarea id="modal-post-copy-input" rows="7" class="widefat" style="font-size:13px; line-height:1.5; font-family:inherit;"></textarea>
                        </div>

                        <div id="modal-feedback-notice" class="presshub-modal-notice" style="display:none;"></div>
                    </div>
                    <div class="presshub-modal-footer">
                        <div class="presshub-modal-footer-left">
                            <button type="button" class="button button-secondary" id="modal-btn-regen-ai">
                                <span class="dashicons dashicons-update" style="vertical-align:middle;"></span>
                                <?php esc_html_e( 'Regenerate with AI', 'presshub-social-autopost' ); ?>
                            </button>
                        </div>
                        <div class="presshub-modal-footer-right">
                            <button type="button" class="button button-secondary" id="modal-btn-save-existing" title="<?php esc_attr_e( 'Save changes to this existing job in the queue', 'presshub-social-autopost' ); ?>">
                                <?php esc_html_e( 'Save to this Job', 'presshub-social-autopost' ); ?>
                            </button>
                            <button type="button" class="button button-primary" id="modal-btn-repost-new" title="<?php esc_attr_e( 'Create a new job with this modified copy and post it to social media', 'presshub-social-autopost' ); ?>">
                                <span class="dashicons dashicons-share-alt" style="vertical-align:middle;"></span>
                                <?php esc_html_e( 'Post Again as New Job', 'presshub-social-autopost' ); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
