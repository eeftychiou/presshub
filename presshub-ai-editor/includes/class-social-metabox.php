<?php
/**
 * Post edit screen metabox for PressHub Social Auto-Post.
 *
 * @package PressHub_Social_Autopost
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_Social_Metabox {

    /**
     * Register WordPress hooks.
     *
     * @return void
     */
    public static function init(): void {
        add_action( 'add_meta_boxes', [ __CLASS__, 'register_metabox' ] );
        add_action( 'save_post', [ __CLASS__, 'save_metabox' ], 10, 2 );
    }

    /**
     * Register the social auto-post metabox on post types.
     *
     * @return void
     */
    public static function register_metabox(): void {
        add_meta_box(
            'presshub_social_metabox',
            __( 'PressHub Social Auto-Post', 'presshub-social-autopost' ),
            [ __CLASS__, 'render_metabox' ],
            'post',
            'normal',
            'high'
        );
    }

    /**
     * Render the metabox HTML.
     *
     * @param WP_Post $post
     * @return void
     */
    public static function render_metabox( $post ): void {
        if ( ! ( $post instanceof WP_Post ) ) {
            return;
        }

        $post_id          = $post->ID;
        $saved_platforms  = get_post_meta( $post_id, '_presshub_social_platforms', true );
        $default_platforms = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_enabled_platforms' )
            ? PressHub_Social_Settings_Storage::get_enabled_platforms()
            : [ 'twitter', 'facebook', 'instagram', 'tiktok' ];

        $enabled_platforms = is_array( $saved_platforms ) ? $saved_platforms : $default_platforms;

        $platforms = [
            'twitter'   => [ 'label' => 'Twitter / X', 'char_limit' => 280 ],
            'facebook'  => [ 'label' => 'Facebook Page', 'char_limit' => 2000 ],
            'instagram' => [ 'label' => 'Instagram', 'char_limit' => 2200 ],
            'tiktok'    => [ 'label' => 'TikTok', 'char_limit' => 2200 ],
        ];

        // Nonce for security
        wp_nonce_field( 'presshub_social_save_metabox', 'presshub_social_metabox_nonce' );
        ?>
        <div class="presshub-social-metabox-wrapper">
            <div class="presshub-social-metabox-header">
                <p class="description">
                    <?php esc_html_e( 'Configure automated multi-platform social sharing for this article. Leave custom copy empty to generate automatically upon publication.', 'presshub-social-autopost' ); ?>
                </p>
                <div class="presshub-social-bulk-actions">
                    <button type="button" class="button button-secondary" id="presshub-generate-all-social" data-post-id="<?php echo esc_attr( $post_id ); ?>">
                        <span class="dashicons dashicons-superhero"></span>
                        <?php esc_html_e( 'Generate All Social Copy with AI', 'presshub-social-autopost' ); ?>
                    </button>
                    <button type="button" class="button button-primary" id="presshub-enqueue-social-now" data-post-id="<?php echo esc_attr( $post_id ); ?>">
                        <span class="dashicons dashicons-share" style="vertical-align:middle;"></span>
                        <?php esc_html_e( 'Queue Social Posts Now', 'presshub-social-autopost' ); ?>
                    </button>
                    <span class="spinner" id="presshub-social-global-spinner"></span>
                </div>
            </div>

            <div class="presshub-social-platforms-grid">
                <?php foreach ( $platforms as $slug => $data ) :
                    $is_checked = in_array( $slug, $enabled_platforms, true );
                    $engine     = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_platform_engine' )
                        ? PressHub_Social_Settings_Storage::get_platform_engine( $slug )
                        : 'api';
                    $copy       = (string) get_post_meta( $post_id, '_presshub_social_copy_' . $slug, true );
                    $badge_text = ( 'automation' === $engine ) ? __( 'Browser Automation', 'presshub-social-autopost' ) : __( 'Official API', 'presshub-social-autopost' );
                    $badge_cls  = ( 'automation' === $engine ) ? 'badge-engine-automation' : 'badge-engine-api';
                ?>
                    <div class="presshub-social-platform-card" data-platform="<?php echo esc_attr( $slug ); ?>">
                        <div class="platform-card-header">
                            <label class="platform-toggle-label">
                                <input type="checkbox" name="presshub_social_platforms[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $is_checked ); ?> />
                                <strong><?php echo esc_html( $data['label'] ); ?></strong>
                            </label>
                            <span class="presshub-badge <?php echo esc_attr( $badge_cls ); ?>">
                                <?php echo esc_html( $badge_text ); ?>
                            </span>
                        </div>

                        <div class="platform-card-body">
                            <div class="platform-copy-header">
                                <label for="presshub_social_copy_<?php echo esc_attr( $slug ); ?>" class="screen-reader-text">
                                    <?php echo esc_html( $data['label'] ); ?> <?php esc_html_e( 'Copy', 'presshub-social-autopost' ); ?>
                                </label>
                                <span class="platform-char-count">
                                    <span class="current-chars"><?php echo esc_html( mb_strlen( $copy ) ); ?></span> / <?php echo esc_html( $data['char_limit'] ); ?>
                                </span>
                            </div>

                            <textarea
                                id="presshub_social_copy_<?php echo esc_attr( $slug ); ?>"
                                name="presshub_social_copy_<?php echo esc_attr( $slug ); ?>"
                                rows="4"
                                class="large-text presshub-copy-textarea"
                                placeholder="<?php echo esc_attr( sprintf( __( 'Custom %s copy... (Leave blank for AI generation on publish)', 'presshub-social-autopost' ), $data['label'] ) ); ?>"
                            ><?php echo esc_textarea( $copy ); ?></textarea>

                            <div class="platform-card-footer">
                                <button type="button" class="button button-small presshub-generate-single-copy" data-platform="<?php echo esc_attr( $slug ); ?>" data-post-id="<?php echo esc_attr( $post_id ); ?>">
                                    <span class="dashicons dashicons-admin-generic"></span>
                                    <?php esc_html_e( 'Generate Copy', 'presshub-social-autopost' ); ?>
                                </button>
                                <span class="spinner single-spinner-<?php echo esc_attr( $slug ); ?>"></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Existing Queue Jobs for this post -->
            <?php
            $jobs = PressHub_Social_Queue::get_jobs_for_post( $post_id );
            if ( ! empty( $jobs ) ) :
            ?>
                <div class="presshub-social-post-history">
                    <h4><?php esc_html_e( 'Queue & Dispatch History', 'presshub-social-autopost' ); ?></h4>
                    <table class="widefat striped presshub-jobs-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'ID', 'presshub-social-autopost' ); ?></th>
                                <th><?php esc_html_e( 'Platform', 'presshub-social-autopost' ); ?></th>
                                <th><?php esc_html_e( 'Engine', 'presshub-social-autopost' ); ?></th>
                                <th><?php esc_html_e( 'Status', 'presshub-social-autopost' ); ?></th>
                                <th><?php esc_html_e( 'Attempts', 'presshub-social-autopost' ); ?></th>
                                <th><?php esc_html_e( 'Scheduled / Dispatched', 'presshub-social-autopost' ); ?></th>
                                <th><?php esc_html_e( 'Link / Error', 'presshub-social-autopost' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $jobs as $job ) :
                                $status = (string) ( $job['status'] ?? 'pending' );
                                $status_badge_class = match ( $status ) {
                                    'published'  => 'badge-published',
                                    'processing' => 'badge-processing',
                                    'failed'     => 'badge-failed',
                                    'cancelled'  => 'badge-cancelled',
                                    default      => 'badge-pending',
                                };
                            ?>
                                <tr>
                                    <td>#<?php echo esc_html( $job['id'] ); ?></td>
                                    <td><strong><?php echo esc_html( ucfirst( $job['platform'] ) ); ?></strong></td>
                                    <td><span class="badge-engine"><?php echo esc_html( strtoupper( $job['engine'] ) ); ?></span></td>
                                    <td><span class="presshub-status-badge <?php echo esc_attr( $status_badge_class ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span></td>
                                    <td><?php echo esc_html( $job['attempts'] . '/' . $job['max_attempts'] ); ?></td>
                                    <td>
                                        <?php if ( 'published' === $status && ! empty( $job['dispatched_at'] ) ) : ?>
                                            <code><?php echo esc_html( $job['dispatched_at'] ); ?></code>
                                        <?php else : ?>
                                            <code><?php echo esc_html( $job['scheduled_at'] ); ?></code>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ( ! empty( $job['external_post_url'] ) ) : ?>
                                            <a href="<?php echo esc_url( $job['external_post_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="button button-small">
                                                <?php esc_html_e( 'View Post ↗', 'presshub-social-autopost' ); ?>
                                            </a>
                                        <?php elseif ( ! empty( $job['last_error'] ) ) : ?>
                                            <span class="presshub-error-preview" title="<?php echo esc_attr( $job['last_error'] ); ?>">
                                                <?php echo esc_html( wp_trim_words( $job['last_error'], 8 ) ); ?>
                                            </span>
                                        <?php else : ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Save metabox data upon post save.
     *
     * @param int     $post_id
     * @param WP_Post $post
     * @return void
     */
    public static function save_metabox( int $post_id, $post ): void {
        // Verify nonce
        if ( ! isset( $_POST['presshub_social_metabox_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['presshub_social_metabox_nonce'] ) ), 'presshub_social_save_metabox' ) ) {
            return;
        }

        // Skip autosave
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        // Capability check
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Save selected platforms
        $platforms = [];
        if ( isset( $_POST['presshub_social_platforms'] ) && is_array( $_POST['presshub_social_platforms'] ) ) {
            foreach ( $_POST['presshub_social_platforms'] as $p ) {
                $sanitized = sanitize_key( $p );
                if ( in_array( $sanitized, PressHub_Social_Queue::VALID_PLATFORMS, true ) ) {
                    $platforms[] = $sanitized;
                }
            }
        }
        update_post_meta( $post_id, '_presshub_social_platforms', $platforms );

        // Save per-platform copy overrides
        foreach ( PressHub_Social_Queue::VALID_PLATFORMS as $slug ) {
            $field_name = 'presshub_social_copy_' . $slug;
            if ( isset( $_POST[ $field_name ] ) ) {
                $copy = sanitize_textarea_field( wp_unslash( $_POST[ $field_name ] ) );
                if ( '' !== trim( $copy ) ) {
                    update_post_meta( $post_id, '_' . $field_name, $copy );
                } else {
                    delete_post_meta( $post_id, '_' . $field_name );
                }
            }
        }
    }
}
