<?php
/**
 * PressHub Social Settings Render
 *
 * Renders the HTML inputs, forms, and tabs for the PressHub Social settings page.
 * Enforces strict HTML escaping and masks secret credentials.
 *
 * @package PressHub_Social_AutoPost
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-capabilities.php';
require_once __DIR__ . '/class-settings-sections.php';
require_once __DIR__ . '/class-secret-field.php';
require_once __DIR__ . '/class-connection-status.php';
require_once __DIR__ . '/class-setup-check.php';

require_once __DIR__ . '/class-social-logger.php';
require_once __DIR__ . '/class-social-settings-storage.php';

class PressHub_Social_Settings_Render {

    /**
     * Get settings capability.
     *
     * @return string
     */
    private function settings_cap(): string {
        return PressHub_Capabilities::social();
    }

    /**
     * The Social settings page became sections of PressHub → Settings (#246).
     * Kept for callers of the old page: it renders the Settings page, which
     * opens the section named in the request.
     *
     * @return void
     */
    public function render_page(): void {
        if ( ! current_user_can( $this->settings_cap() ) ) {
            wp_die( esc_html__( 'You do not have permission to access PressHub Social settings.', 'presshub-social-autopost' ) );
        }
        if ( ! class_exists( 'PressHub_AI_Settings_Render' ) ) {
            require_once __DIR__ . '/class-settings-render.php';
        }
        ( new PressHub_AI_Settings_Render() )->render_settings_page();
    }

    /**
     * Prints the Settings API sections registered on one Social settings
     * page ($wp_page, e.g. presshub_social_general), each with its title as a
     * sub-heading, its intro callback and its fields. $only limits the output
     * to those section ids; $except leaves those out.
     *
     * @param string   $wp_page Settings API page id.
     * @param string[] $only    Section ids to print (empty: all).
     * @param string[] $except  Section ids to skip.
     */
    public function render_settings_sections( string $wp_page, array $only = [], array $except = [] ): void {
        global $wp_settings_sections;
        $sections = isset( $wp_settings_sections[ $wp_page ] ) && is_array( $wp_settings_sections[ $wp_page ] ) ? $wp_settings_sections[ $wp_page ] : [];
        foreach ( $sections as $id => $section ) {
            if ( ( $only && ! in_array( $id, $only, true ) ) || in_array( $id, $except, true ) ) {
                continue;
            }
            echo '<div class="presshub-settings-subgroup" id="' . esc_attr( 'wp-section-' . $id ) . '">';
            if ( ! empty( $section['title'] ) ) {
                echo '<h4 class="presshub-settings-subgroup-title">' . esc_html( (string) $section['title'] ) . '</h4>';
            }
            if ( ! empty( $section['callback'] ) && is_callable( $section['callback'] ) ) {
                call_user_func( $section['callback'], $section );
            }
            echo '<table class="form-table" role="presentation"><tbody>';
            if ( function_exists( 'do_settings_fields' ) ) {
                do_settings_fields( $wp_page, $id );
            }
            echo '</tbody></table>';
            echo '</div>';
        }
    }

    /** Box that restores every Social prompt and template to its default file. */
    public function render_prompts_reset_box(): void {
        $url = wp_nonce_url(
            add_query_arg( [ 'page' => PressHub_Settings_Sections::PAGE_SLUG, 'section' => 'social-publishing', 'action' => 'reset_prompts' ], admin_url( 'admin.php' ) ),
            'presshub_social_reset_prompts'
        );
        ?>
        <div class="presshub-social-reset-box">
            <h4><?php echo esc_html__( 'Restore Prompts & Templates', 'presshub-social-autopost' ); ?></h4>
            <p class="description">
                <?php echo esc_html__( 'Reset all prompt templates and static formats back to the official default files from assets/prompts/. Any customizations made in the fields above will be overwritten.', 'presshub-social-autopost' ); ?>
            </p>
            <p>
                <a href="<?php echo esc_url( $url ); ?>" class="button button-secondary presshub-confirm-link" data-confirm="<?php echo esc_attr__( 'Are you sure you want to restore all prompts to defaults? Your customized prompt changes will be lost.', 'presshub-social-autopost' ); ?>">
                    <?php echo esc_html__( 'Reset All Prompts to Defaults', 'presshub-social-autopost' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    // ------------------------------------------------------------------
    // Field Renderers: General & Engines
    // ------------------------------------------------------------------

    public function render_auto_publish_enabled_field(): void {
        $enabled = PressHub_Social_Settings_Storage::get_auto_publish_enabled();
        ?>
        <input type="hidden" name="presshub_social_auto_publish_enabled_present" value="1" />
        <label>
            <input type="checkbox" name="presshub_social_auto_publish_enabled" value="1" <?php checked( $enabled, true ); ?> />
            <?php echo esc_html__( 'Automatically queue social posts when a standard WordPress post is published.', 'presshub-social-autopost' ); ?>
        </label>
        <?php
    }

    public function render_enabled_platforms_field(): void {
        $enabled = PressHub_Social_Settings_Storage::get_enabled_platforms();
        echo '<input type="hidden" name="presshub_social_enabled_platforms_present" value="1" />';
        echo '<div class="ph-display-flex ph-flex-direction-column ph-gap-8px ph-max-width-650px">';
        foreach ( PressHub_Social_Settings_Storage::PLATFORMS as $platform ) {
            $is_checked    = in_array( $platform, $enabled, true );
            $is_configured = PressHub_Social_Settings_Storage::is_platform_configured( $platform );
            $engine        = PressHub_Social_Settings_Storage::get_platform_engine( $platform );
            $engine_label  = 'youtube' === $platform
                ? __( 'Worker + YouTube API (videos only)', 'presshub-social-autopost' )
                : ( ( 'automation' === $engine ) ? __( 'Automation Worker', 'presshub-social-autopost' ) : __( 'Official REST API', 'presshub-social-autopost' ) );

            $session_ok    = PressHub_Social_Settings_Storage::is_platform_session_active( $platform );
            $badge = ( $is_configured && ! $session_ok )
                ? '<span class="ph-display-inline-block ph-padding-2px-8px ph-font-size-11px ph-font-weight-600 ph-border-radius-3px ph-background-fff3cd ph-color-856404">' . esc_html__( '⏸ Session inactive — jobs will wait', 'presshub-social-autopost' ) . '</span>'
                : ( $is_configured
                ? '<span class="ph-display-inline-block ph-padding-2px-8px ph-font-size-11px ph-font-weight-600 ph-border-radius-3px ph-background-d4edda ph-color-155724">' . esc_html__( '✓ Configured', 'presshub-social-autopost' ) . '</span>'
                : '<span class="ph-display-inline-block ph-padding-2px-8px ph-font-size-11px ph-font-weight-600 ph-border-radius-3px ph-background-f8d7da ph-color-721c24">' . esc_html__( '⚠ Not Configured', 'presshub-social-autopost' ) . '</span>' );

            echo '<div class="ph-display-flex ph-align-items-center ph-justify-content-space-between ph-padding-8px-12px ph-background-fff ph-border-1px-solid-ccd0d4 ph-border-radius-4px">';
            echo '<label class="ph-font-weight-600 ph-font-size-13px ph-cursor-pointer ph-display-flex ph-align-items-center ph-gap-8px">';
            echo '<input type="checkbox" name="presshub_social_enabled_platforms[]" value="' . esc_attr( $platform ) . '" ' . checked( $is_checked, true, false ) . ' /> ';
            echo esc_html( self::platform_label( $platform ) );
            echo '</label>';
            echo '<div class="ph-display-flex ph-align-items-center ph-gap-10px">';
            echo '<span class="ph-font-size-12px ph-color-666">' . esc_html( $engine_label ) . '</span>';
            echo $badge;
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
        echo '<p class="description">' . esc_html__( 'Enable or disable social media platforms. Posts will only be queued for platforms that are both enabled and configured.', 'presshub-social-autopost' ) . '</p>';
    }

    public function render_copy_mode_field(): void {
        $current = PressHub_Social_Settings_Storage::get_copy_mode();
        ?>
        <select id="presshub_social_copy_mode" name="presshub_social_copy_mode">
            <option value="ai" <?php selected( $current, 'ai' ); ?>><?php echo esc_html__( 'AI Copywriter (Dynamic LLM Generation)', 'presshub-social-autopost' ); ?></option>
            <option value="template" <?php selected( $current, 'template' ); ?>><?php echo esc_html__( 'Static Template Placeholder Substitution', 'presshub-social-autopost' ); ?></option>
        </select>
        <p class="description"><?php echo esc_html__( 'In AI mode, each platform receives uniquely tailored editorial copy. In template mode, placeholders like {title} and {url} are replaced statically.', 'presshub-social-autopost' ); ?></p>
        <?php
    }

    public function render_platform_engine_field( array $args ): void {
        $platform      = $args['platform'] ?? 'twitter';
        $current       = PressHub_Social_Settings_Storage::get_platform_engine( $platform );
        $is_configured = PressHub_Social_Settings_Storage::is_platform_configured( $platform );
        $opt_name      = 'presshub_social_engine_' . $platform;

        $session_ok    = PressHub_Social_Settings_Storage::is_platform_session_active( $platform );
        $badge_html = ( $is_configured && ! $session_ok )
            ? '<span class="ph-display-inline-block ph-margin-left-10px ph-padding-2px-8px ph-font-size-11px ph-font-weight-600 ph-border-radius-3px ph-background-fff3cd ph-color-856404">' . esc_html__( '⏸ Session inactive — jobs will wait', 'presshub-social-autopost' ) . '</span>'
            : ( $is_configured
            ? '<span class="ph-display-inline-block ph-margin-left-10px ph-padding-2px-8px ph-font-size-11px ph-font-weight-600 ph-border-radius-3px ph-background-d4edda ph-color-155724">' . esc_html__( '✓ Configured', 'presshub-social-autopost' ) . '</span>'
            : '<span class="ph-display-inline-block ph-margin-left-10px ph-padding-2px-8px ph-font-size-11px ph-font-weight-600 ph-border-radius-3px ph-background-f8d7da ph-color-721c24">' . esc_html__( '⚠ Not Configured — posts will not be queued', 'presshub-social-autopost' ) . '</span>' );
        ?>
        <div class="ph-display-flex ph-align-items-center">
            <select id="<?php echo esc_attr( $opt_name ); ?>" name="<?php echo esc_attr( $opt_name ); ?>">
                <option value="api" <?php selected( $current, 'api' ); ?>><?php echo esc_html__( 'Official REST API', 'presshub-social-autopost' ); ?></option>
                <option value="automation" <?php selected( $current, 'automation' ); ?>><?php echo esc_html__( 'Automation Worker (Playwright Headless)', 'presshub-social-autopost' ); ?></option>
            </select>
            <?php echo $badge_html; ?>
        </div>
        <p class="description">
            <?php
            if ( in_array( $platform, [ 'twitter', 'tiktok' ], true ) ) {
                echo esc_html__( 'Default is Automation Worker to avoid prohibitive API paywalls / rate limits.', 'presshub-social-autopost' );
            } else {
                echo esc_html__( 'Default is Official REST API (Graph API).', 'presshub-social-autopost' );
            }
            ?>
        </p>
        <?php
    }

    // ------------------------------------------------------------------
    // Field Renderers: AI Copywriter
    // ------------------------------------------------------------------

    /** The read-only "Model: …" line of a task, linking to its row in AI models (#236). */
    public function render_model_line( array $args = [] ): void {
        require_once __DIR__ . '/class-model-tasks.php';
        echo PressHub_Model_Tasks::feature_line( (string) ( $args['task'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in feature_line().
    }

    // ------------------------------------------------------------------
    // Generic Field Renderers
    // ------------------------------------------------------------------

    public function render_text_field( array $args ): void {
        $option      = $args['option'];
        $val         = (string) get_option( $option, $args['default'] ?? '' );
        $placeholder = $args['placeholder'] ?? '';
        $desc        = $args['desc'] ?? '';
        ?>
        <input type="text" id="<?php echo esc_attr( $option ); ?>" name="<?php echo esc_attr( $option ); ?>" value="<?php echo esc_attr( $val ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $placeholder ); ?>" />
        <?php if ( '' !== $desc ) : ?>
            <p class="description"><?php echo esc_html( $desc ); ?></p>
        <?php endif; ?>
        <?php
        if ( ! empty( $args['test_platform'] ) ) {
            $this->render_test_connection_button( $args['test_platform'] );
        }
    }

    public function render_secret_field( array $args ): void {
        $option = $args['option'];
        $raw    = (string) get_option( $option, '' );
        $desc   = $args['desc'] ?? '';
        // #247: one "Replace key" pattern for every secret. Empty keeps the
        // saved value; "<option>_remove" still clears it.
        PressHub_Secret_Field::render( [
            'name'         => $option,
            'masked'       => '' !== $raw ? PressHub_Social_Logger::mask_secret( $raw ) : '',
            'remove_name'  => $option . '_remove',
            'placeholder'  => (string) ( $args['placeholder'] ?? '' ) ?: null,
            'describedby'  => '' !== $desc ? $option . '-desc' : '',
        ] );
        ?>
        <?php if ( '' !== $desc ) : ?>
            <p class="description" id="<?php echo esc_attr( $option . '-desc' ); ?>"><?php echo esc_html( $desc ); ?></p>
        <?php endif; ?>
        <?php
        if ( ! empty( $args['test_platform'] ) ) {
            $this->render_test_connection_button( $args['test_platform'] );
        }
    }

    public function render_number_field( array $args ): void {
        $option = $args['option'];
        $min    = $args['min'] ?? 0;
        $max    = $args['max'] ?? 10000;
        $step   = $args['step'] ?? 1;
        $desc   = $args['desc'] ?? '';

        // Without an explicit default, read the option with a single argument so the
        // default from register_setting() applies. Passing 0 here rendered 0 for any
        // option that was never stored, which is below the field's min.
        $raw = array_key_exists( 'default', $args ) ? get_option( $option, $args['default'] ) : get_option( $option );
        $val = (int) $raw;

        // Every tab lives in one form and the inactive tabs are display:none. A value
        // outside min/max in a hidden tab makes the browser refuse the whole submit
        // with no visible message, so nothing on the page is saved. Clamp what we show
        // to the range the field accepts.
        $val = max( (int) $min, min( (int) $max, $val ) );
        ?>
        <?php $push_only = ! empty( $args['push_only'] ); ?>
        <input type="number" id="<?php echo esc_attr( $option ); ?>" name="<?php echo esc_attr( $option ); ?>" value="<?php echo esc_attr( $val ); ?>" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>" step="<?php echo esc_attr( $step ); ?>" class="small-text<?php echo $push_only ? ' presshub-push-only' : ''; ?>"<?php echo $push_only ? $this->push_only_attrs( $option ) : ''; ?> />
        <?php if ( ! empty( $args['unit'] ) ) : ?>
            <span class="ph-margin-left-4px"><?php echo esc_html( $args['unit'] ); ?></span>
        <?php endif; ?>
        <?php if ( $push_only ) { $this->render_push_only_reason( $option ); } ?>
        <?php if ( '' !== $desc ) : ?>
            <p class="description"><?php echo esc_html( $desc ); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Voice of a short's narrator. With the Gemini engine, a dropdown of every Gemini
     * TTS voice (a text list with suggestions only shows the names matching what is
     * already typed); with Google Cloud, a text field for a Cloud voice name.
     */
    public function render_tiktok_shorts_voice_field( array $args ): void {
        $option   = $args['option'];
        $narrator = (int) ( $args['narrator'] ?? 1 );
        $val      = PressHub_Social_Settings_Storage::get_tiktok_shorts_voice( $narrator );
        $desc     = $args['desc'] ?? '';
        $engine   = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_tts_engine() : 'gemini';

        if ( 'google_cloud' === $engine ) {
            $this->render_text_field( $args );
            return;
        }

        $voices = PressHub_Social_Settings_Storage::gemini_tts_voices();
        ksort( $voices );
        ?>
        <select id="<?php echo esc_attr( $option ); ?>" name="<?php echo esc_attr( $option ); ?>">
            <option value="" <?php selected( $val, '' ); ?>><?php echo esc_html( sprintf( __( 'Daily Briefing presenter %d voice', 'presshub-social-autopost' ), $narrator ) ); ?></option>
            <?php if ( '' !== $val && ! isset( $voices[ $val ] ) ) : ?>
                <option value="<?php echo esc_attr( $val ); ?>" selected="selected"><?php echo esc_html( $val ); ?></option>
            <?php endif; ?>
            <?php foreach ( $voices as $voice => $character ) : ?>
                <option value="<?php echo esc_attr( $voice ); ?>" <?php selected( $val, $voice ); ?>><?php echo esc_html( $voice . ' (' . $character . ')' ); ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ( '' !== $desc ) : ?>
            <p class="description"><?php echo esc_html( $desc ); ?></p>
        <?php endif; ?>
        <?php
    }

    public function render_textarea_field( array $args ): void {
        $option = $args['option'];
        $val    = (string) get_option( $option, '' );
        $rows   = $args['rows'] ?? 6;
        $desc   = $args['desc'] ?? '';
        ?>
        <textarea id="<?php echo esc_attr( $option ); ?>" name="<?php echo esc_attr( $option ); ?>" rows="<?php echo esc_attr( $rows ); ?>" class="ph-width-100pct ph-max-width-800px large-text code"><?php echo esc_textarea( $val ); ?></textarea>
        <?php if ( '' !== $desc ) : ?>
            <p class="description"><?php echo esc_html( $desc ); ?></p>
        <?php endif; ?>
        <?php
    }

    public function render_worker_mode_field(): void {
        $mode     = PressHub_Social_Settings_Storage::get_worker_mode();
        ?>
        <select id="presshub_social_worker_mode" name="presshub_social_worker_mode">
            <option value="pull" <?php selected( $mode, 'pull' ); ?>><?php echo esc_html__( 'Pull Mode — Local Worker Polls WordPress via REST API (Recommended for Local PC)', 'presshub-social-autopost' ); ?></option>
            <option value="push" <?php selected( $mode, 'push' ); ?>><?php echo esc_html__( 'Push Mode — WordPress Connects Directly to Worker URL', 'presshub-social-autopost' ); ?></option>
        </select>
        <p class="description"><?php echo esc_html__( 'Pull: the worker on your PC asks this site for jobs and reports when it is running. Push: this site sends each job to the worker address below.', 'presshub-social-autopost' ); ?></p>
        <?php
    }

    // ------------------------------------------------------------------
    // Phase 2 (#234): Shorts & Reels tab and disabled-with-reason fields.
    // ------------------------------------------------------------------

    /**
     * readonly attributes for a field used only in Push mode. readonly (not
     * disabled) so the value still posts and is saved unchanged.
     */
    private function push_only_attrs( string $field_id ): string {
        return 'pull' === PressHub_Social_Settings_Storage::get_worker_mode() ? ' readonly="readonly" aria-describedby="' . esc_attr( $field_id ) . '-push-reason"' : '';
    }

    private function render_push_only_reason( string $field_id ): void {
        $pull = 'pull' === PressHub_Social_Settings_Storage::get_worker_mode();
        ?>
        <span id="<?php echo esc_attr( $field_id ); ?>-push-reason" class="ph-font-style-italic presshub-push-only-reason description<?php echo $pull ? '' : ' presshub-is-hidden'; ?>"><?php echo esc_html__( 'Used only in Push mode.', 'presshub-social-autopost' ); ?></span>
        <?php
    }

    /**
     * Link to where an old Social settings tab lives now (#246), optionally
     * to a field in it.
     */
    private function social_tab_url( string $tab, string $anchor = '' ): string {
        $target = PressHub_Settings_Sections::legacy_target( 'social', $tab );
        $slug   = $target ? $target['section'] : 'social-publishing';
        return PressHub_Settings_Sections::url( $slug, '' !== $anchor ? $anchor : ( $target ? $target['anchor'] : '' ) );
    }

    /**
     * Every unmet requirement of the enabled Shorts and Reels, each with a
     * link to its fix (#234). Empty when nothing is enabled or all is ready.
     *
     * @return array<int,array{text:string,url:string,link:string}>
     */
    public function shorts_unmet_requirements(): array {
        // Article shorts only run where that platform's shorts switch is on, so
        // they are not a switch of their own here (#234 review).
        $tiktok    = PressHub_Social_Settings_Storage::get_tiktok_shorts_enabled();
        $instagram = PressHub_Social_Settings_Storage::get_instagram_shorts_enabled();
        $youtube   = PressHub_Social_Settings_Storage::get_youtube_shorts_enabled() || PressHub_Social_Settings_Storage::get_youtube_podcast_enabled();
        if ( ! $tiktok && ! $instagram && ! $youtube ) {
            return [];
        }
        $issues = [];
        if ( 'pull' !== PressHub_Social_Settings_Storage::get_worker_mode() ) {
            $issues[] = [
                'text' => __( 'The worker is in Push mode. Shorts and Reels need Pull mode.', 'presshub-social-autopost' ),
                'url'  => $this->social_tab_url( 'worker', 'presshub_social_worker_mode' ),
                'link' => __( 'Change worker mode', 'presshub-social-autopost' ),
            ];
        }
        if ( $tiktok && 'automation' !== PressHub_Social_Settings_Storage::get_platform_engine( 'tiktok' ) ) {
            $issues[] = [
                'text' => __( 'TikTok shorts need the automation engine, but TikTok uses another engine.', 'presshub-social-autopost' ),
                'url'  => $this->social_tab_url( 'general', 'presshub_social_engine_tiktok' ),
                'link' => __( 'Change TikTok engine', 'presshub-social-autopost' ),
            ];
        }
        if ( $instagram && 'automation' !== PressHub_Social_Settings_Storage::get_platform_engine( 'instagram' ) ) {
            $issues[] = [
                'text' => __( 'Instagram Reels need the automation engine, but Instagram uses another engine.', 'presshub-social-autopost' ),
                'url'  => $this->social_tab_url( 'general', 'presshub_social_engine_instagram' ),
                'link' => __( 'Change Instagram engine', 'presshub-social-autopost' ),
            ];
        }
        if ( $youtube && class_exists( 'PressHub_Social_YouTube' ) && '' !== PressHub_Social_YouTube::reconnect_reason() ) {
            $issues[] = [
                'text' => __( 'YouTube is on, but Google refused the stored sign-in. Connect YouTube again.', 'presshub-social-autopost' ),
                'url'  => PressHub_Settings_Sections::url( 'connections', 'presshub-youtube-account' ),
                'link' => __( 'Connect YouTube', 'presshub-social-autopost' ),
            ];
        } elseif ( $youtube && ! ( class_exists( 'PressHub_Social_YouTube' ) && PressHub_Social_YouTube::is_connected() ) ) {
            $issues[] = [
                'text' => __( 'YouTube is on, but no YouTube channel is connected.', 'presshub-social-autopost' ),
                'url'  => PressHub_Settings_Sections::url( 'connections', 'presshub-youtube-account' ),
                'link' => __( 'Connect YouTube', 'presshub-social-autopost' ),
            ];
        }
        if ( $youtube && ! PressHub_Social_Settings_Storage::is_platform_enabled( 'youtube' ) ) {
            $issues[] = [
                'text' => __( 'YouTube is off in the platform list, so nothing is queued for it.', 'presshub-social-autopost' ),
                'url'  => $this->social_tab_url( 'general' ),
                'link' => __( 'Turn on YouTube', 'presshub-social-autopost' ),
            ];
        }
        return $issues;
    }

    public function render_shorts_readiness_notice(): void {
        $issues = $this->shorts_unmet_requirements();
        if ( ! $issues ) {
            return;
        }
        ?>
        <div class="ph-margin-10px-0-20px notice notice-error inline presshub-shorts-readiness">
            <h3 class="ph-margin-0_8em-0-0_4em">
                <?php
                /* translators: %d: number of things to fix */
                $only_episodes = ! PressHub_Social_Settings_Storage::get_tiktok_shorts_enabled() && ! PressHub_Social_Settings_Storage::get_instagram_shorts_enabled() && ! PressHub_Social_Settings_Storage::get_youtube_shorts_enabled();
                echo esc_html( $only_episodes
                    /* translators: %d: number of things to fix */
                    ? sprintf( _n( 'YouTube podcast episodes can\'t be uploaded yet. %d thing to fix:', 'YouTube podcast episodes can\'t be uploaded yet. %d things to fix:', count( $issues ), 'presshub-social-autopost' ), count( $issues ) )
                    /* translators: %d: number of things to fix */
                    : sprintf( _n( 'Shorts can\'t run yet. %d thing to fix:', 'Shorts can\'t run yet. %d things to fix:', count( $issues ), 'presshub-social-autopost' ), count( $issues ) ) );
                ?>
            </h3>
            <ol>
                <?php foreach ( $issues as $issue ) : ?>
                    <li><?php echo esc_html( $issue['text'] ); ?> <a href="<?php echo esc_url( $issue['url'] ); ?>"><?php echo esc_html( $issue['link'] ); ?></a></li>
                <?php endforeach; ?>
            </ol>
        </div>
        <?php
    }

    /** "Where to post" intro: whether each platform is ready, in words. */
    public function render_shorts_where_intro(): void {
        $pull  = 'pull' === PressHub_Social_Settings_Storage::get_worker_mode();
        $rows  = [
            'TikTok'          => 'tiktok',
            'Instagram Reels' => 'instagram',
        ];
        echo '<ul class="ph-margin-0-0-8px presshub-shorts-ready-list">';
        foreach ( $rows as $label => $platform ) {
            $engine = PressHub_Social_Settings_Storage::get_platform_engine( $platform );
            $ready  = $pull && 'automation' === $engine;
            echo '<li>' . esc_html( $label ) . ': ' . ( $ready
                ? '<strong>' . esc_html__( 'Ready', 'presshub-social-autopost' ) . '</strong> ' . esc_html__( '(automation engine, worker in Pull mode)', 'presshub-social-autopost' )
                : '<strong>' . esc_html__( 'Not ready', 'presshub-social-autopost' ) . '</strong> ' . esc_html( sprintf(
                    /* translators: 1: engine, 2: worker mode */
                    __( '(engine: %1$s, worker: %2$s mode)', 'presshub-social-autopost' ),
                    $engine,
                    $pull ? 'Pull' : 'Push'
                ) ) ) . '</li>';
        }
        $connected = class_exists( 'PressHub_Social_YouTube' ) && PressHub_Social_YouTube::is_connected();
        $channel   = $connected ? (string) ( PressHub_Social_YouTube::channel()['title'] ?? '' ) : '';
        $reported  = PressHub_Social_Settings_Storage::is_platform_configured( 'youtube' );
        if ( ! $connected ) {
            $why = class_exists( 'PressHub_Social_YouTube' ) && '' !== PressHub_Social_YouTube::reconnect_reason()
                ? __( '(Google refused the sign-in: connect YouTube again in Connections)', 'presshub-social-autopost' )
                : __( '(connect a YouTube channel in Connections)', 'presshub-social-autopost' );
        } elseif ( ! $pull ) {
            $why = __( '(the worker must be in Pull mode)', 'presshub-social-autopost' );
        } elseif ( ! $reported ) {
            $why = __( '(the worker has not reported YouTube yet: update and restart it)', 'presshub-social-autopost' );
        } else {
            $why = '';
        }
        echo '<li>YouTube: ' . ( '' === $why
            ? '<strong>' . esc_html__( 'Ready', 'presshub-social-autopost' ) . '</strong> ' . esc_html( sprintf( /* translators: %s: channel name */ __( '(channel %s, worker in Pull mode)', 'presshub-social-autopost' ), $channel ) )
            : '<strong>' . esc_html__( 'Not ready', 'presshub-social-autopost' ) . '</strong> ' . esc_html( $why ) ) . '</li>';
        echo '</ul>';
    }

    /** Narration intro: the speech engine, shown read-only with its one home. */
    public function render_shorts_narration_intro(): void {
        $engine = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_tts_engine() : 'gemini';
        $label  = ( '' === $engine || 'gemini' === $engine || 0 === strpos( $engine, 'gemini' ) ) ? 'Gemini TTS' : 'Google Cloud TTS';
        $url    = PressHub_Settings_Sections::url( 'ai-models', 'model-speech' );
        ?>
        <p>
            <?php echo esc_html__( 'Speech engine:', 'presshub-social-autopost' ); ?>
            <strong><?php echo esc_html( $label ); ?></strong>
            &mdash; <a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html__( 'change in AI models → Speech', 'presshub-social-autopost' ); ?></a>
        </p>
        <?php
    }

    /** Names of the platforms as people know them. */
    public static function platform_label( string $platform ): string {
        $labels = [
            'twitter'   => 'X',
            'facebook'  => 'Facebook',
            'instagram' => 'Instagram',
            'tiktok'    => 'TikTok',
            'youtube'   => 'YouTube',
        ];
        return $labels[ $platform ] ?? ucfirst( $platform );
    }

    /**
     * Worker status from what the plugin already stores (#247): the last
     * heartbeat and the login the worker reported for each platform. No
     * request is made to the worker.
     */
    public function render_worker_status(): void {
        $mode      = PressHub_Social_Settings_Storage::get_worker_mode();
        $last_seen = (int) get_option( 'presshub_social_worker_last_seen', 0 );
        $sessions  = get_option( 'presshub_social_worker_sessions', [] );
        $sessions  = is_array( $sessions ) ? $sessions : [];
        $online    = $last_seen > 0 && ( time() - $last_seen ) <= PressHub_Setup_Check::WORKER_ONLINE_SECONDS;
        ?>
        <div class="presshub-worker-status" id="presshub-worker-status">
            <h3><?php echo esc_html__( 'Worker status', 'presshub-social-autopost' ); ?></h3>
            <?php if ( 'pull' === $mode ) : ?>
                <p>
                    <span class="presshub-conn-pill <?php echo $online ? 'is-ok' : 'is-error'; ?>"><?php echo $online ? esc_html__( 'Running', 'presshub-social-autopost' ) : esc_html__( 'Not running', 'presshub-social-autopost' ); ?></span>
                    <?php
                    if ( $last_seen > 0 ) {
                        /* translators: %s: time of the last heartbeat */
                        echo ' ' . esc_html( sprintf( __( 'last heard from %s', 'presshub-social-autopost' ), PressHub_Connection_Status::time_label( $last_seen ) ) );
                    } else {
                        echo ' ' . esc_html__( 'never heard from yet', 'presshub-social-autopost' );
                    }
                    ?>
                </p>
            <?php else : ?>
                <p><?php echo esc_html__( 'In Push mode this site calls the worker when it has a job. Use Test below to check that it answers.', 'presshub-social-autopost' ); ?></p>
            <?php endif; ?>
            <?php
            $automation = array_values( array_filter( PressHub_Social_Settings_Storage::ENGINE_PLATFORMS, static function ( $p ) {
                return 'automation' === PressHub_Social_Settings_Storage::get_platform_engine( $p );
            } ) );
            if ( $automation ) :
                ?>
                <table class="widefat striped presshub-worker-logins">
                    <caption class="screen-reader-text"><?php echo esc_html__( 'Logins on the worker', 'presshub-social-autopost' ); ?></caption>
                    <thead><tr><th scope="col"><?php echo esc_html__( 'Platform', 'presshub-social-autopost' ); ?></th><th scope="col"><?php echo esc_html__( 'Login on the worker', 'presshub-social-autopost' ); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ( $automation as $p ) :
                        $known  = array_key_exists( $p, $sessions );
                        $active = ! empty( $sessions[ $p ] );
                        ?>
                        <tr>
                            <td><?php echo esc_html( self::platform_label( $p ) ); ?></td>
                            <td><span class="presshub-conn-pill <?php echo $active ? 'is-ok' : ( $known ? 'is-error' : 'is-unknown' ); ?>"><?php echo $active ? esc_html__( 'Logged in', 'presshub-social-autopost' ) : ( $known ? esc_html__( 'Login needed', 'presshub-social-autopost' ) : esc_html__( 'Not reported yet', 'presshub-social-autopost' ) ); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description"><?php echo esc_html__( 'The worker reports its logins with every heartbeat. To log in again, run the login step on the PC that runs the worker.', 'presshub-social-autopost' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_worker_url_field(): void {
        $url = PressHub_Social_Settings_Storage::get_worker_url();
        ?>
        <input type="url" id="presshub_social_worker_url" name="presshub_social_worker_url" value="<?php echo esc_attr( $url ); ?>" class="regular-text code presshub-push-only" placeholder="http://127.0.0.1:3456"<?php echo $this->push_only_attrs( 'presshub_social_worker_url' ); ?> />
        <?php $this->render_push_only_reason( 'presshub_social_worker_url' ); ?>
        <p class="description"><?php echo esc_html__( 'HTTP endpoint of the local or remote Playwright / Puppeteer automation worker service.', 'presshub-social-autopost' ); ?></p>
        <?php
        $this->render_test_connection_button( 'worker' );
    }

    public function render_worker_secret_field(): void {
        $secret = PressHub_Social_Settings_Storage::get_worker_secret();
        // No Remove box: without a secret the worker cannot sign in at all.
        PressHub_Secret_Field::render( [
            'name'        => 'presshub_social_worker_secret',
            'masked'      => '' !== $secret ? PressHub_Social_Logger::mask_secret( $secret ) : '',
            'class'       => 'code',
            'describedby' => 'presshub_social_worker_secret-desc',
        ] );
        ?>
        <p class="description" id="presshub_social_worker_secret-desc"><?php echo esc_html__( 'The worker signs in with this secret. It must match PRESSHUB_WORKER_SECRET in the worker’s .env file.', 'presshub-social-autopost' ); ?></p>
        <p class="presshub-worker-regenerate">
            <button type="button" class="button" id="presshub-social-regenerate-secret"
                data-confirm="<?php echo esc_attr__( 'Make a new worker secret? The worker stops posting until you paste the new secret into PRESSHUB_WORKER_SECRET in its .env file and restart it.', 'presshub-social-autopost' ); ?>">
                <?php echo esc_html__( 'Regenerate secret', 'presshub-social-autopost' ); ?>
            </button>
            <span class="spinner ph-float-none"></span>
            <span class="presshub-worker-new-secret" role="status" aria-live="polite"></span>
        </p>
        <?php
    }

    public function render_tiktok_narration_enabled_field(): void {
        $enabled = PressHub_Social_Settings_Storage::get_tiktok_narration_enabled();
        ?>
        <input type="hidden" name="presshub_social_tiktok_narration_enabled_present" value="1" />
        <label>
            <input type="checkbox" name="presshub_social_tiktok_narration_enabled" value="1" <?php checked( $enabled, true ); ?> />
            <?php echo esc_html__( 'For articles without podcast audio, read the TikTok copy aloud with Gemini TTS (hashtags, emojis, links and the presshub.cy sentence are skipped) and end with the outro below.', 'presshub-social-autopost' ); ?>
        </label>
        <?php
    }

    public function render_post_images_enabled_field(): void {
        $enabled = PressHub_Social_Settings_Storage::get_post_images_enabled();
        ?>
        <input type="hidden" name="presshub_social_post_images_enabled_present" value="1" />
        <label>
            <input type="checkbox" name="presshub_social_post_images_enabled" value="1" <?php checked( $enabled, true ); ?> />
            <?php echo esc_html__( 'Post AI-generated images with each article instead of its featured image. The images are made in the background right after publishing with the image generator set in PressHub → Settings → AI Featured Images, and are shared between platforms. A post waits up to 20 minutes for them; if they fail, it goes out with the featured image. Podcast shorts are not affected.', 'presshub-social-autopost' ); ?>
        </label>
        <?php
    }

    public function render_tiktok_shorts_enabled_field(): void {
        $enabled = PressHub_Social_Settings_Storage::get_tiktok_shorts_enabled();
        ?>
        <input type="hidden" name="presshub_social_tiktok_shorts_enabled_present" value="1" />
        <label>
            <input type="checkbox" name="presshub_social_tiktok_shorts_enabled" value="1" <?php checked( $enabled, true ); ?> />
            <?php echo esc_html__( 'Instead of posting the whole podcast to TikTok, post one 30-60 second video per top story: a hook-first script read by the briefing presenters, with the hook on screen, captions that follow the voice, AI story images and its own caption. Needs the automation engine in Pull Mode.', 'presshub-social-autopost' ); ?>
        </label>
        <?php
    }

    public function render_instagram_shorts_enabled_field(): void {
        $enabled = PressHub_Social_Settings_Storage::get_instagram_shorts_enabled();
        ?>
        <input type="hidden" name="presshub_social_instagram_shorts_enabled_present" value="1" />
        <label>
            <input type="checkbox" name="presshub_social_instagram_shorts_enabled" value="1" <?php checked( $enabled, true ); ?> />
            <?php echo esc_html__( 'Also post each podcast short as an Instagram Reel (reusing the same generated script, voiceover, and story images). Needs Instagram enabled with the automation engine in Pull Mode.', 'presshub-social-autopost' ); ?>
        </label>
        <?php
    }

    public function render_youtube_shorts_enabled_field(): void {
        $enabled = PressHub_Social_Settings_Storage::get_youtube_shorts_enabled();
        ?>
        <input type="hidden" name="presshub_social_youtube_shorts_enabled_present" value="1" />
        <label>
            <input type="checkbox" name="presshub_social_youtube_shorts_enabled" value="1" <?php checked( $enabled, true ); ?> />
            <?php echo esc_html__( 'Also upload each short (podcast stories and, with Article Shorts on, articles) to YouTube. YouTube makes every vertical video of three minutes or less a Short by itself. The title is the short\'s hook and the description is its caption. Needs a connected YouTube channel and the worker in Pull Mode.', 'presshub-social-autopost' ); ?>
        </label>
        <?php
    }

    public function render_youtube_podcast_enabled_field(): void {
        $enabled = PressHub_Social_Settings_Storage::get_youtube_podcast_enabled();
        ?>
        <input type="hidden" name="presshub_social_youtube_podcast_enabled_present" value="1" />
        <label>
            <input type="checkbox" name="presshub_social_youtube_podcast_enabled" value="1" <?php checked( $enabled, true ); ?> />
            <?php echo esc_html__( 'Upload every Daily Briefing podcast to YouTube as a full episode: a 16:9 video of the whole audio over the podcast\'s image, titled like the post, with the YouTube description from the YouTube Caption Prompt, added to the podcast playlist below.', 'presshub-social-autopost' ); ?>
        </label>
        <?php
    }

    public function render_youtube_podcast_language_playlists_field(): void {
        $option = 'presshub_social_youtube_podcast_language_playlists';
        $langs  = class_exists( 'PressHub_AI_Podcast_Translations' ) ? PressHub_AI_Podcast_Translations::LANGUAGES : [];
        $on     = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_podcast_languages() : [];
        foreach ( $langs as $lang => $info ) :
            $id = $option . '_' . $lang;
            ?>
            <p>
                <label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $info['name'] ); ?></label><br />
                <input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $option . '[' . $lang . ']' ); ?>" value="<?php echo esc_attr( PressHub_Social_Settings_Storage::get_youtube_podcast_playlist_id_for( $lang ) ); ?>" class="regular-text" placeholder="PLxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" />
                <?php if ( ! in_array( $lang, $on, true ) ) : ?>
                    <span class="description"><?php echo esc_html__( '(not made: turn this language on under Settings, Daily Briefing, Other languages)', 'presshub-social-autopost' ); ?></span>
                <?php endif; ?>
            </p>
            <?php
        endforeach;
        ?>
        <p class="description"><?php echo esc_html__( 'The playlist each language\'s episodes are added to. Make one playlist per language in YouTube Studio, set it as a podcast there, then paste its link or ID. Empty uploads that language\'s episodes without a playlist.', 'presshub-social-autopost' ); ?></p>
        <?php
    }

    public function render_youtube_episode_enhanced_field(): void {
        $enabled = PressHub_Social_Settings_Storage::get_youtube_episode_enhanced();
        ?>
        <input type="hidden" name="presshub_social_youtube_episode_enhanced_present" value="1" />
        <label>
            <input type="checkbox" name="presshub_social_youtube_episode_enhanced" value="1" <?php checked( $enabled, true ); ?> />
            <?php echo esc_html__( 'Show the story images of the podcast\'s shorts, one topic at a time, with captions like the shorts, a title card per topic and a chapter list in the description. Off: the whole audio over the podcast\'s image.', 'presshub-social-autopost' ); ?>
        </label>
        <?php
    }

    public function render_youtube_privacy_field(): void {
        $current = PressHub_Social_Settings_Storage::get_youtube_privacy_status();
        $labels  = [
            'public'   => __( 'Public', 'presshub-social-autopost' ),
            'unlisted' => __( 'Unlisted (only people with the link)', 'presshub-social-autopost' ),
            'private'  => __( 'Private (only you, for testing)', 'presshub-social-autopost' ),
        ];
        ?>
        <select id="presshub_social_youtube_privacy_status" name="presshub_social_youtube_privacy_status">
            <?php foreach ( PressHub_Social_Settings_Storage::YOUTUBE_PRIVACY as $value ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $labels[ $value ] ?? $value ); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php echo esc_html__( 'Who can watch the shorts and episodes once they are uploaded. Use Private for a first test.', 'presshub-social-autopost' ); ?></p>
        <?php
    }

    public function render_youtube_category_field(): void {
        $current = PressHub_Social_Settings_Storage::get_youtube_category_id();
        $labels  = [
            25 => __( 'News & Politics', 'presshub-social-autopost' ),
            22 => __( 'People & Blogs', 'presshub-social-autopost' ),
            24 => __( 'Entertainment', 'presshub-social-autopost' ),
            27 => __( 'Education', 'presshub-social-autopost' ),
            17 => __( 'Sports', 'presshub-social-autopost' ),
            28 => __( 'Science & Technology', 'presshub-social-autopost' ),
        ];
        if ( ! isset( $labels[ $current ] ) ) {
            /* translators: %d: YouTube category number */
            $labels[ $current ] = sprintf( __( 'Category %d', 'presshub-social-autopost' ), $current );
        }
        ?>
        <select id="presshub_social_youtube_category_id" name="presshub_social_youtube_category_id">
            <?php foreach ( $labels as $id => $label ) : ?>
                <option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $current, $id ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php echo esc_html__( 'YouTube category of every upload.', 'presshub-social-autopost' ); ?></p>
        <?php
    }

    /**
     * Connections → YouTube account: the address to paste in Google Cloud, the channel
     * that is connected, and Connect / Disconnect / Test. Reads stored data only.
     */
    public function render_youtube_account_field(): void {
        $notice    = PressHub_Social_YouTube::take_notice();
        $connected = PressHub_Social_YouTube::is_connected();
        $channel   = PressHub_Social_YouTube::channel();
        ?>
        <div id="presshub-youtube-account">
            <?php if ( $notice ) : ?>
                <div class="notice inline <?php echo ! empty( $notice['ok'] ) ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html( (string) $notice['text'] ); ?></p></div>
            <?php endif; ?>
            <p>
                <label for="presshub-youtube-redirect-uri"><?php echo esc_html__( 'Authorized redirect URI (paste it into your Google OAuth client):', 'presshub-social-autopost' ); ?></label><br />
                <input type="text" id="presshub-youtube-redirect-uri" class="large-text code" readonly="readonly" value="<?php echo esc_attr( PressHub_Social_YouTube::redirect_uri() ); ?>" />
            </p>
            <p>
                <?php if ( $connected ) : ?>
                    <?php
                    /* translators: %s: YouTube channel name */
                    echo esc_html( sprintf( __( 'Connected channel: %s', 'presshub-social-autopost' ), (string) ( $channel['title'] ?? __( 'unknown', 'presshub-social-autopost' ) ) ) );
                    ?>
                    <a class="button button-secondary ph-margin-left-8px" href="<?php echo esc_url( PressHub_Social_YouTube::disconnect_link() ); ?>"><?php echo esc_html__( 'Disconnect', 'presshub-social-autopost' ); ?></a>
                    <a class="button button-secondary" href="<?php echo esc_url( PressHub_Social_YouTube::connect_link() ); ?>"><?php echo esc_html__( 'Connect another channel', 'presshub-social-autopost' ); ?></a>
                <?php elseif ( PressHub_Social_YouTube::has_client() && '' !== PressHub_Social_YouTube::reconnect_reason() ) : ?>
                    <?php
                    /* translators: 1: channel name, 2: Google's error */
                    echo esc_html( sprintf( __( 'Uploads to %1$s stopped: Google refused the sign-in (%2$s).', 'presshub-social-autopost' ), (string) ( $channel['title'] ?? 'YouTube' ), PressHub_Social_YouTube::reconnect_reason() ) );
                    ?>
                    <a class="button button-primary ph-margin-left-8px" href="<?php echo esc_url( PressHub_Social_YouTube::connect_link() ); ?>"><?php echo esc_html__( 'Connect YouTube again', 'presshub-social-autopost' ); ?></a>
                    <a class="button button-secondary" href="<?php echo esc_url( PressHub_Social_YouTube::disconnect_link() ); ?>"><?php echo esc_html__( 'Disconnect', 'presshub-social-autopost' ); ?></a>
                <?php elseif ( PressHub_Social_YouTube::has_client() ) : ?>
                    <?php echo esc_html__( 'No channel connected.', 'presshub-social-autopost' ); ?>
                    <a class="button button-primary ph-margin-left-8px" href="<?php echo esc_url( PressHub_Social_YouTube::connect_link() ); ?>"><?php echo esc_html__( 'Connect YouTube', 'presshub-social-autopost' ); ?></a>
                <?php else : ?>
                    <?php echo esc_html__( 'Save the client ID and client secret above, then Connect YouTube appears here.', 'presshub-social-autopost' ); ?>
                <?php endif; ?>
            </p>
            <p class="description"><?php echo esc_html__( 'Connect signs in with Google as the YouTube channel. Use the Google account that owns the channel; for a Brand Account channel, pick the channel when Google asks.', 'presshub-social-autopost' ); ?></p>
            <?php if ( $connected && PressHub_Social_YouTube::paused_until() > 0 ) : ?>
                <p><strong><?php
                /* translators: %s: UTC date and time */
                echo esc_html( sprintf( __( 'The daily YouTube upload quota is used up. Uploads resume at %s UTC.', 'presshub-social-autopost' ), gmdate( 'Y-m-d H:i', PressHub_Social_YouTube::paused_until() ) ) );
                ?></strong></p>
            <?php endif; ?>
            <?php if ( $connected ) { $this->render_test_connection_button( 'youtube' ); } ?>
        </div>
        <?php
    }

    public function render_tiktok_article_shorts_enabled_field(): void {
        $enabled = PressHub_Social_Settings_Storage::get_tiktok_article_shorts_enabled();
        ?>
        <input type="hidden" name="presshub_social_tiktok_article_shorts_enabled_present" value="1" />
        <label>
            <input type="checkbox" name="presshub_social_tiktok_article_shorts_enabled" value="1" <?php checked( $enabled, true ); ?> />
            <?php echo esc_html__( 'Also turn every article and opinion piece into one short, made like the podcast shorts (narration, captions that follow the voice, AI images, music), on TikTok and, when Instagram Reels are on, on Instagram. The post text is the usual AI caption. If the short cannot be made, the article is posted the classic way.', 'presshub-social-autopost' ); ?>
        </label>
        <?php
    }

    public function render_tiktok_shorts_music_enabled_field(): void {
        $enabled = PressHub_Social_Settings_Storage::get_tiktok_shorts_music_enabled();
        ?>
        <input type="hidden" name="presshub_social_tiktok_shorts_music_enabled_present" value="1" />
        <label>
            <input type="checkbox" name="presshub_social_tiktok_shorts_music_enabled" value="1" <?php checked( $enabled, true ); ?> />
            <?php echo esc_html__( 'Play quiet background music under each short. The Script Prompt picks a mood for every story (neutral, serious, upbeat, urgent, or none for sad news) and the short uses one of that mood\'s tracks. Generate the tracks below first.', 'presshub-social-autopost' ); ?>
        </label>
        <?php
    }

    /**
     * Music library: the tracks of each mood with a player, and the buttons that generate
     * the missing tracks or a whole new set (one Lyria call per AJAX request).
     */
    public function render_tiktok_shorts_music_library_field(): void {
        $status  = PressHub_Social_TikTok_Music::status();
        $missing = PressHub_Social_TikTok_Music::missing_count();
        ?>
        <div class="presshub-social-music-library">
            <?php if ( empty( $status ) ) : ?>
                <p><?php echo esc_html__( 'No moods: the TikTok Shorts: Music Prompts setting (Social publishing › Shorts) has no "mood: prompt" lines.', 'presshub-social-autopost' ); ?></p>
            <?php else : ?>
                <table class="ph-max-width-800px widefat striped">
                    <tbody>
                    <?php foreach ( $status as $mood => $row ) : ?>
                        <tr>
                            <td class="ph-width-110px"><strong><?php echo esc_html( $mood ); ?></strong><br /><span class="description"><?php echo esc_html( sprintf( '%d/%d', $row['have'], $row['need'] ) ); ?></span></td>
                            <td>
                                <?php foreach ( $row['tracks'] as $track ) : ?>
                                    <audio controls preload="none" src="<?php echo esc_url( $track['url'] ); ?>" class="ph-height-32px ph-margin-2px-6px-2px-0 ph-vertical-align-middle"></audio>
                                <?php endforeach; ?>
                                <?php if ( empty( $row['tracks'] ) ) : ?>
                                    <span class="description"><?php echo esc_html__( 'Not generated yet.', 'presshub-social-autopost' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <div class="ph-margin-top-8px presshub-social-music-row">
                <button type="button" class="button button-secondary presshub-social-music-btn" data-reset="0">
                    <?php echo esc_html( $missing > 0 ? sprintf( __( 'Generate Music Tracks (%d missing)', 'presshub-social-autopost' ), $missing ) : __( 'Generate Music Tracks', 'presshub-social-autopost' ) ); ?>
                </button>
                <button type="button" class="button presshub-social-music-btn" data-reset="1">
                    <?php echo esc_html__( 'Replace All Tracks', 'presshub-social-autopost' ); ?>
                </button>
                <span class="ph-float-none ph-margin-0-0-0-6px spinner"></span>
                <span class="ph-margin-left-8px ph-font-weight-600 presshub-social-music-status"></span>
            </div>
            <p class="description"><?php echo esc_html__( 'Makes the instrumental tracks of each mood with Google Lyria (Gemini API key), one at a time, about 30 seconds each. Save changed prompts or settings first. Replace All Tracks deletes the current tracks and makes a new set. Lyria audio carries Google\'s SynthID watermark.', 'presshub-social-autopost' ); ?></p>
        </div>
        <?php
    }

    public function render_alert_email_enabled_field(): void {
        $enabled = PressHub_Social_Settings_Storage::get_alert_email_enabled();
        ?>
        <input type="hidden" name="presshub_social_alert_email_enabled_present" value="1" />
        <label>
            <input type="checkbox" name="presshub_social_alert_email_enabled" value="1" <?php checked( $enabled, true ); ?> />
            <?php echo esc_html__( 'Send email alert notifications when a social post permanently fails after maximum retry attempts.', 'presshub-social-autopost' ); ?>
        </label>
        <?php
    }

    /**
     * Render interactive Test Connection button and status container for a platform or worker.
     *
     * @param string $platform Platform or worker identifier.
     * @return void
     */
    public function render_test_connection_button( string $platform ): void {
        ?>
        <div class="ph-margin-top-8px presshub-social-test-row">
            <?php echo PressHub_Connection_Status::render_pill( 'worker' === $platform ? 'worker' : 'social:' . sanitize_key( $platform ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <button type="button" class="button button-secondary presshub-social-test-btn" data-platform="<?php echo esc_attr( $platform ); ?>">
                <span class="ph-line-height-1_4 ph-vertical-align-middle ph-font-size-16px dashicons dashicons-update"></span>
                <?php echo esc_html( 'worker' === $platform ? __( 'Test the worker', 'presshub-social-autopost' ) : sprintf( /* translators: %s: platform name */ __( 'Test %s', 'presshub-social-autopost' ), self::platform_label( $platform ) ) ); ?>
            </button>
            <span class="ph-float-none ph-margin-0-0-0-6px spinner"></span>
            <span class="ph-margin-left-8px ph-font-weight-600 presshub-social-test-status" data-platform="<?php echo esc_attr( $platform ); ?>"></span>
        </div>
        <?php
    }
}
