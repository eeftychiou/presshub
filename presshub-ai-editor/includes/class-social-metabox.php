<?php
/**
 * Post edit screen metabox for PressHub Social Auto-Post.
 *
 * @package PressHub_Social_Autopost
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-capabilities.php';

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
            __( 'PressHub Social Auto-Post', 'presshub-ai-editor' ),
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
        $ready_platforms  = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_ready_platforms' )
            ? PressHub_Social_Settings_Storage::get_ready_platforms()
            : ( class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_enabled_platforms' )
                ? PressHub_Social_Settings_Storage::get_enabled_platforms()
                : [ 'twitter', 'facebook', 'instagram', 'tiktok' ] );

        $enabled_platforms = is_array( $saved_platforms )
            ? array_values( array_intersect( $saved_platforms, $ready_platforms ) )
            : $ready_platforms;

        $platforms = [
            'twitter'   => [ 'label' => 'Twitter / X', 'char_limit' => 280 ],
            'facebook'  => [ 'label' => 'Facebook Page', 'char_limit' => 2000 ],
            'instagram' => [ 'label' => 'Instagram', 'char_limit' => 2200 ],
            'tiktok'    => [ 'label' => 'TikTok', 'char_limit' => 2200 ],
            // The YouTube copy is the video description (its own limit is 5000 bytes).
            'youtube'   => [ 'label' => 'YouTube', 'char_limit' => 4500 ],
        ];

        // Nonce for security
        wp_nonce_field( 'presshub_social_save_metabox', 'presshub_social_metabox_nonce' );
        ?>
        <div class="presshub-social-metabox-wrapper">
            <div class="presshub-social-metabox-header">
                <p class="description">
                    <?php esc_html_e( 'Configure automated multi-platform social sharing for this article. Leave custom copy empty to generate automatically upon publication.', 'presshub-ai-editor' ); ?>
                </p>
                <div class="presshub-social-bulk-actions">
                    <button type="button" class="button button-secondary" id="presshub-generate-all-social" data-post-id="<?php echo esc_attr( $post_id ); ?>">
                        <span class="dashicons dashicons-superhero"></span>
                        <?php esc_html_e( 'Generate All Social Copy with AI', 'presshub-ai-editor' ); ?>
                    </button>
                    <button type="button" class="button button-primary" id="presshub-enqueue-social-now" data-post-id="<?php echo esc_attr( $post_id ); ?>">
                        <span class="dashicons dashicons-share" style="vertical-align:middle;"></span>
                        <?php esc_html_e( 'Queue Social Posts Now', 'presshub-ai-editor' ); ?>
                    </button>
                    <label style="display:inline-flex; align-items:center; gap:5px; margin-left:12px; font-weight:normal; font-size:13px; cursor:pointer;" title="<?php esc_attr_e( 'Check this box before updating the post to enqueue it into the social publishing queue.', 'presshub-ai-editor' ); ?>">
                        <input type="checkbox" name="presshub_social_requeue_on_save" value="1" />
                        <span><?php esc_html_e( 'Queue on Update', 'presshub-ai-editor' ); ?></span>
                    </label>
                    <span class="spinner" id="presshub-social-global-spinner"></span>
                </div>
                <?php if ( class_exists( 'PressHub_Social_TikTok_Shorts' ) && '' !== PressHub_Social_TikTok_Shorts::source_type( (int) $post_id ) && current_user_can( PressHub_Capabilities::social() ) ) : ?>
                    <div class="presshub-social-test-shorts" style="margin-top:8px;">
                        <button type="button" class="button button-secondary" id="presshub-render-test-shorts" data-post-id="<?php echo esc_attr( $post_id ); ?>">
                            <span class="dashicons dashicons-video-alt3" style="vertical-align:middle;"></span>
                            <?php esc_html_e( 'Render Test TikTok Shorts', 'presshub-ai-editor' ); ?>
                        </button>
                        <span class="spinner" id="presshub-test-shorts-spinner"></span>
                        <p class="description" id="presshub-test-shorts-status">
                            <?php esc_html_e( 'Makes this post\'s shorts (one per top story of a podcast, one for an article) with the real prompts, voices and images, and saves the videos in the worker\'s output folder (tiktok-short-tests). Nothing is posted.', 'presshub-ai-editor' ); ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="presshub-social-platforms-grid">
                <?php foreach ( $platforms as $slug => $data ) :
                    $is_enabled    = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'is_platform_enabled' )
                        ? PressHub_Social_Settings_Storage::is_platform_enabled( $slug )
                        : true;
                    $is_configured = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'is_platform_configured' )
                        ? PressHub_Social_Settings_Storage::is_platform_configured( $slug )
                        : true;
                    $is_ready      = $is_enabled && $is_configured;
                    $is_checked    = $is_ready && in_array( $slug, $enabled_platforms, true );
                    $engine        = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_platform_engine' )
                        ? PressHub_Social_Settings_Storage::get_platform_engine( $slug )
                        : 'api';
                    $copy          = (string) get_post_meta( $post_id, '_presshub_social_copy_' . $slug, true );
                    $badge_text    = 'youtube' === $slug ? __( 'Videos only (YouTube API)', 'presshub-social-autopost' ) : ( ( 'automation' === $engine ) ? __( 'Browser Automation', 'presshub-social-autopost' ) : __( 'Official API', 'presshub-social-autopost' ) );
                    $badge_cls     = ( 'automation' === $engine ) ? 'badge-engine-automation' : 'badge-engine-api';
                    $card_style    = ! $is_ready ? 'opacity: 0.65;' : '';
                    $disabled_attr = ! $is_ready ? 'disabled="disabled"' : '';
                ?>
                    <div class="presshub-social-platform-card" data-platform="<?php echo esc_attr( $slug ); ?>" style="<?php echo esc_attr( $card_style ); ?>">
                        <div class="platform-card-header">
                            <label class="platform-toggle-label">
                                <input type="checkbox" name="presshub_social_platforms[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $is_checked ); ?> <?php echo $disabled_attr; ?> />
                                <strong><?php echo esc_html( $data['label'] ); ?></strong>
                            </label>
                            <div style="display:flex; align-items:center; gap:6px;">
                                <?php if ( ! $is_enabled ) : ?>
                                    <span class="presshub-badge badge-engine-disabled" style="background:#6c757d; color:#fff; font-size:11px; padding:2px 6px; border-radius:3px;">
                                        <?php esc_html_e( 'Disabled in Settings', 'presshub-ai-editor' ); ?>
                                    </span>
                                <?php elseif ( ! $is_configured ) : ?>
                                    <span class="presshub-badge badge-engine-unconfigured" style="background:#d9534f; color:#fff; font-size:11px; padding:2px 6px; border-radius:3px;">
                                        <?php esc_html_e( 'Not Configured', 'presshub-ai-editor' ); ?>
                                    </span>
                                <?php elseif ( 'youtube' === $slug && ! PressHub_Social_Settings_Storage::get_youtube_shorts_enabled() && ! PressHub_Social_Settings_Storage::get_youtube_podcast_enabled() ) : ?>
                                    <span class="presshub-badge badge-engine-unconfigured" style="background:#f0ad4e; color:#fff; font-size:11px; padding:2px 6px; border-radius:3px;">
                                        <?php esc_html_e( 'Shorts and episodes are off: nothing is uploaded', 'presshub-social-autopost' ); ?>
                                    </span>
                                <?php elseif ( method_exists( 'PressHub_Social_Settings_Storage', 'is_platform_session_active' ) && ! PressHub_Social_Settings_Storage::is_platform_session_active( $slug ) ) : ?>
                                    <span class="presshub-badge badge-engine-session-inactive" style="background:#f0ad4e; color:#fff; font-size:11px; padding:2px 6px; border-radius:3px;">
                                        <?php esc_html_e( 'Session inactive — will post when the worker is back', 'presshub-ai-editor' ); ?>
                                    </span>
                                <?php endif; ?>
                                <span class="presshub-badge <?php echo esc_attr( $badge_cls ); ?>">
                                    <?php echo esc_html( $badge_text ); ?>
                                </span>
                            </div>
                        </div>

                        <div class="platform-card-body">
                            <div class="platform-copy-header">
                                <label for="presshub_social_copy_<?php echo esc_attr( $slug ); ?>" class="screen-reader-text">
                                    <?php echo esc_html( $data['label'] ); ?> <?php esc_html_e( 'Copy', 'presshub-ai-editor' ); ?>
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
                                placeholder="<?php echo esc_attr( sprintf( /* translators: %s: social platform label */ __( 'Custom %s copy... (Leave blank for AI generation on publish)', 'presshub-ai-editor' ), $data['label'] ) ); ?>"
                            ><?php echo esc_textarea( $copy ); ?></textarea>

                            <div class="platform-card-footer">
                                <button type="button" class="button button-small presshub-generate-single-copy" data-platform="<?php echo esc_attr( $slug ); ?>" data-post-id="<?php echo esc_attr( $post_id ); ?>">
                                    <span class="dashicons dashicons-admin-generic"></span>
                                    <?php esc_html_e( 'Generate Copy', 'presshub-ai-editor' ); ?>
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
                    <h4><?php esc_html_e( 'Queue & Dispatch History', 'presshub-ai-editor' ); ?></h4>
                    <table class="widefat striped presshub-jobs-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'ID', 'presshub-ai-editor' ); ?></th>
                                <th><?php esc_html_e( 'Platform', 'presshub-ai-editor' ); ?></th>
                                <th><?php esc_html_e( 'Engine', 'presshub-ai-editor' ); ?></th>
                                <th><?php esc_html_e( 'Status', 'presshub-ai-editor' ); ?></th>
                                <th><?php esc_html_e( 'Attempts', 'presshub-ai-editor' ); ?></th>
                                <th><?php esc_html_e( 'Scheduled / Dispatched', 'presshub-ai-editor' ); ?></th>
                                <th><?php esc_html_e( 'Link / Error', 'presshub-ai-editor' ); ?></th>
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
                                    <td><strong><?php echo esc_html( class_exists( 'PressHub_Social_YouTube' ) ? PressHub_Social_YouTube::platform_label( $job ) : ucfirst( $job['platform'] ) ); ?></strong></td>
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
                                                <?php esc_html_e( 'View Post ↗', 'presshub-ai-editor' ); ?>
                                            </a>
                                            <?php $yt_playlist_error = class_exists( 'PressHub_Social_YouTube' ) ? PressHub_Social_YouTube::playlist_error( $job ) : ''; ?>
                                            <?php if ( '' !== $yt_playlist_error ) : ?>
                                                <span class="presshub-error-preview" title="<?php echo esc_attr( $yt_playlist_error ); ?>">
                                                    <?php esc_html_e( 'Not in the podcast playlist', 'presshub-social-autopost' ); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php $yt_episode_note = class_exists( 'PressHub_Social_YouTube' ) ? PressHub_Social_YouTube::episode_note( $job ) : ''; ?>
                                            <?php if ( '' !== $yt_episode_note ) : ?>
                                                <span class="presshub-error-preview" title="<?php echo esc_attr( $yt_episode_note ); ?>">
                                                    <?php esc_html_e( 'Episode video incomplete', 'presshub-social-autopost' ); ?>
                                                </span>
                                            <?php endif; ?>
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
            <?php self::render_language_status( (int) $post->ID ); ?>
        </div>
        <?php
    }

    /**
     * English and Turkish versions of a podcast post: where each one stands, and why it
     * failed, so a language that never reached the Queue is not missed.
     */
    private static function render_language_status( int $post_id ): void {
        if ( ! class_exists( 'PressHub_AI_Podcast_Translations' ) || 'podcast' !== (string) get_post_meta( $post_id, '_presshub_briefing_type', true ) ) {
            return;
        }
        $entries = PressHub_AI_Podcast_Translations::get_all( $post_id );
        $enabled = PressHub_AI_Podcast_Translations::enabled_languages();
        if ( empty( $entries ) && empty( $enabled ) ) {
            return;
        }
        $labels = [
            'translated' => __( 'translated, being voiced', 'presshub-social-autopost' ),
            'voiced'     => __( 'voiced, ready for YouTube', 'presshub-social-autopost' ),
            'failed'     => __( 'failed', 'presshub-social-autopost' ),
        ];
        ?>
        <div class="presshub-language-status" style="margin-top:12px;">
            <h4 style="margin:0 0 6px;"><?php esc_html_e( 'Other languages (YouTube only)', 'presshub-social-autopost' ); ?></h4>
            <ul style="margin:0;">
                <?php foreach ( PressHub_AI_Podcast_Translations::LANGUAGES as $lang => $info ) :
                    $entry = is_array( $entries[ $lang ] ?? null ) ? $entries[ $lang ] : [];
                    if ( empty( $entry ) && ! in_array( $lang, $enabled, true ) ) {
                        continue;
                    }
                    $status = (string) ( $entry['status'] ?? '' );
                    $text   = '' === $status ? __( 'not made yet', 'presshub-social-autopost' ) : ( $labels[ $status ] ?? $status );
                    ?>
                    <li>
                        <strong><?php echo esc_html( $info['name'] ); ?>:</strong>
                        <span class="<?php echo 'failed' === $status ? 'presshub-text-error' : ''; ?>" style="<?php echo 'failed' === $status ? 'color:#b32d2e;' : ''; ?>"><?php echo esc_html( $text ); ?></span>
                        <?php if ( 'failed' === $status && '' !== (string) ( $entry['error'] ?? '' ) ) : ?>
                            <br /><small><?php echo esc_html( sprintf( /* translators: 1: step, 2: error */ __( 'At the %1$s step: %2$s', 'presshub-social-autopost' ), (string) ( $entry['step'] ?? '' ), wp_trim_words( (string) $entry['error'], 40 ) ) ); ?></small>
                        <?php elseif ( '' !== (string) ( $entry['title'] ?? '' ) ) : ?>
                            <br /><small><?php echo esc_html( (string) $entry['title'] ); ?></small>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="description"><?php esc_html_e( 'Made again when the Greek audio is made again (Daily Briefing Hub, or a corrected script).', 'presshub-social-autopost' ); ?></p>
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
                    if ( class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'is_platform_ready' ) ) {
                        if ( ! PressHub_Social_Settings_Storage::is_platform_ready( $sanitized ) ) {
                            continue;
                        }
                    }
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
                    if ( 'twitter' === $slug && class_exists( 'PressHub_Social_AI_Copywriter' ) && method_exists( 'PressHub_Social_AI_Copywriter', 'sanitize_twitter_copy' ) ) {
                        $article_url = function_exists( 'get_permalink' ) ? ( get_permalink( $post_id ) ?: '' ) : '';
                        $copy        = PressHub_Social_AI_Copywriter::sanitize_twitter_copy( $copy, $article_url );
                    }
                    update_post_meta( $post_id, '_' . $field_name, $copy );
                } else {
                    delete_post_meta( $post_id, '_' . $field_name );
                }
            }
        }

        // Auto-enqueue or explicit re-queue upon saving a published post
        $is_published = ( $post instanceof WP_Post && 'publish' === $post->post_status );
        if ( ! $is_published && function_exists( 'get_post_status' ) ) {
            $is_published = ( 'publish' === get_post_status( $post_id ) );
        }

        if ( $is_published ) {
            $explicit_requeue = ! empty( $_POST['presshub_social_requeue_on_save'] );
            $auto_publish     = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_auto_publish_enabled' )
                ? PressHub_Social_Settings_Storage::get_auto_publish_enabled()
                : true;
            // Test renders of the post's shorts are never posted, so they don't count.
            $has_jobs         = PressHub_Social_Dispatcher::has_publish_jobs( $post_id );

            // If explicitly requested OR if auto-publish is active and post currently has zero queue jobs:
            if ( $explicit_requeue || ( $auto_publish && ! $has_jobs ) ) {
                PressHub_Social_Dispatcher::enqueue_for_post( $post_id, $platforms );
            }
        }
    }
}
