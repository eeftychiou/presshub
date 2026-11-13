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

require_once __DIR__ . '/class-social-logger.php';
require_once __DIR__ . '/class-social-settings-storage.php';

class PressHub_Social_Settings_Render {

    /**
     * Get settings capability.
     *
     * @return string
     */
    private function settings_cap(): string {
        return (string) apply_filters( 'presshub_social_settings_cap', 'manage_options' );
    }

    /**
     * Render the complete settings admin page.
     *
     * @return void
     */
    public function render_page(): void {
        if ( ! current_user_can( $this->settings_cap() ) ) {
            wp_die( esc_html__( 'You do not have permission to access PressHub Social settings.', 'presshub-social-autopost' ) );
        }

        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $valid_tabs = [
            'general'     => __( 'General & Engines', 'presshub-social-autopost' ),
            'ai'          => __( 'AI Copywriter', 'presshub-social-autopost' ),
            'credentials' => __( 'API Credentials', 'presshub-social-autopost' ),
            'worker'      => __( 'Automation Worker', 'presshub-social-autopost' ),
            'queue'       => __( 'Queue & Alerts', 'presshub-social-autopost' ),
            'prompts'     => __( 'Prompts & Templates', 'presshub-social-autopost' ),
        ];

        if ( ! array_key_exists( $active_tab, $valid_tabs ) ) {
            $active_tab = 'general';
        }

        ?>
        <div class="wrap presshub-social-settings-wrap">
            <h1><?php echo esc_html__( 'PressHub Social AutoPost Settings', 'presshub-social-autopost' ); ?></h1>

            <?php
            if ( isset( $_GET['message'] ) && 'prompts_reset' === $_GET['message'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'All prompts and templates have been successfully restored to asset defaults.', 'presshub-social-autopost' ) . '</p></div>';
            }
            if ( function_exists( 'settings_errors' ) ) {
                settings_errors( 'presshub_social_options' );
            }
            ?>

            <nav class="nav-tab-wrapper presshub-social-nav-tabs" style="margin-bottom: 20px;">
                <?php foreach ( $valid_tabs as $tab_id => $tab_title ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'presshub-social-settings', 'tab' => $tab_id ], admin_url( 'admin.php' ) ) ); ?>"
                       data-tab="<?php echo esc_attr( $tab_id ); ?>"
                       class="nav-tab presshub-social-tab-link <?php echo $active_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $tab_title ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <form method="post" action="options.php" id="presshub-social-settings-form">
                <input type="hidden" name="presshub_social_form_full" value="1" />
                <?php settings_fields( PressHub_Social_Settings_Storage::OPTION_GROUP ); ?>

                <?php foreach ( $valid_tabs as $tab_id => $tab_title ) : ?>
                    <div id="presshub-social-tab-pane-<?php echo esc_attr( $tab_id ); ?>"
                         class="presshub-social-tab-pane"
                         style="<?php echo $active_tab === $tab_id ? '' : 'display:none;'; ?>">
                        <?php do_settings_sections( 'presshub_social_' . $tab_id ); ?>

                        <?php if ( 'prompts' === $tab_id ) : ?>
                            <hr style="margin: 30px 0 20px;" />
                            <div class="presshub-social-reset-box" style="background:#fff; border:1px solid #ccd0d4; padding:15px; border-radius:4px; max-width:800px; margin-bottom:20px;">
                                <h3><?php echo esc_html__( 'Restore Prompts & Templates', 'presshub-social-autopost' ); ?></h3>
                                <p class="description">
                                    <?php echo esc_html__( 'Reset all prompt templates and static formats back to the official default files from assets/prompts/. Any customizations made in the fields above will be overwritten.', 'presshub-social-autopost' ); ?>
                                </p>
                                <p>
                                    <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'page' => 'presshub-social-settings', 'action' => 'reset_prompts' ], admin_url( 'admin.php' ) ), 'presshub_social_reset_prompts' ) ); ?>"
                                       class="button button-secondary"
                                       onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to restore all prompts to defaults? Your customized prompt changes will be lost.', 'presshub-social-autopost' ) ); ?>');">
                                        <?php echo esc_html__( 'Reset All Prompts to Defaults', 'presshub-social-autopost' ); ?>
                                    </a>
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php submit_button(); ?>
            </form>
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
        echo '<div style="display:flex; flex-direction:column; gap:8px; max-width:650px;">';
        foreach ( PressHub_Social_Settings_Storage::PLATFORMS as $platform ) {
            $is_checked    = in_array( $platform, $enabled, true );
            $is_configured = PressHub_Social_Settings_Storage::is_platform_configured( $platform );
            $engine        = PressHub_Social_Settings_Storage::get_platform_engine( $platform );
            $engine_label  = ( 'automation' === $engine ) ? __( 'Automation Worker', 'presshub-social-autopost' ) : __( 'Official REST API', 'presshub-social-autopost' );

            $session_ok    = PressHub_Social_Settings_Storage::is_platform_session_active( $platform );
            $badge = ( $is_configured && ! $session_ok )
                ? '<span style="display:inline-block; padding:2px 8px; font-size:11px; font-weight:600; border-radius:3px; background:#fff3cd; color:#856404;">' . esc_html__( '⏸ Session inactive — jobs will wait', 'presshub-social-autopost' ) . '</span>'
                : ( $is_configured
                ? '<span style="display:inline-block; padding:2px 8px; font-size:11px; font-weight:600; border-radius:3px; background:#d4edda; color:#155724;">' . esc_html__( '✓ Configured', 'presshub-social-autopost' ) . '</span>'
                : '<span style="display:inline-block; padding:2px 8px; font-size:11px; font-weight:600; border-radius:3px; background:#f8d7da; color:#721c24;">' . esc_html__( '⚠ Not Configured', 'presshub-social-autopost' ) . '</span>' );

            echo '<div style="display:flex; align-items:center; justify-content:space-between; padding:8px 12px; background:#fff; border:1px solid #ccd0d4; border-radius:4px;">';
            echo '<label style="font-weight:600; font-size:13px; cursor:pointer; display:flex; align-items:center; gap:8px;">';
            echo '<input type="checkbox" name="presshub_social_enabled_platforms[]" value="' . esc_attr( $platform ) . '" ' . checked( $is_checked, true, false ) . ' /> ';
            echo esc_html( ucfirst( $platform ) );
            echo '</label>';
            echo '<div style="display:flex; align-items:center; gap:10px;">';
            echo '<span style="font-size:12px; color:#666;">' . esc_html( $engine_label ) . '</span>';
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

    public function render_github_token_field(): void {
        $this->render_secret_field( [
            'option' => 'presshub_social_github_token',
            'desc'   => __( 'Optional GitHub Personal Access Token (PAT) for automatic plugin updates if the repository is private. Automatically inherits the token from PressHub AI Editor if left blank.', 'presshub-social-autopost' ),
        ] );
    }

    public function render_platform_engine_field( array $args ): void {
        $platform      = $args['platform'] ?? 'twitter';
        $current       = PressHub_Social_Settings_Storage::get_platform_engine( $platform );
        $is_configured = PressHub_Social_Settings_Storage::is_platform_configured( $platform );
        $opt_name      = 'presshub_social_engine_' . $platform;

        $session_ok    = PressHub_Social_Settings_Storage::is_platform_session_active( $platform );
        $badge_html = ( $is_configured && ! $session_ok )
            ? '<span style="display:inline-block; margin-left:10px; padding:2px 8px; font-size:11px; font-weight:600; border-radius:3px; background:#fff3cd; color:#856404;">' . esc_html__( '⏸ Session inactive — jobs will wait', 'presshub-social-autopost' ) . '</span>'
            : ( $is_configured
            ? '<span style="display:inline-block; margin-left:10px; padding:2px 8px; font-size:11px; font-weight:600; border-radius:3px; background:#d4edda; color:#155724;">' . esc_html__( '✓ Configured', 'presshub-social-autopost' ) . '</span>'
            : '<span style="display:inline-block; margin-left:10px; padding:2px 8px; font-size:11px; font-weight:600; border-radius:3px; background:#f8d7da; color:#721c24;">' . esc_html__( '⚠ Not Configured — posts will not be queued', 'presshub-social-autopost' ) . '</span>' );
        ?>
        <div style="display:flex; align-items:center;">
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

    public function render_ai_provider_field(): void {
        $current = PressHub_Social_Settings_Storage::get_ai_provider();
        ?>
        <select id="presshub_social_ai_provider" name="presshub_social_ai_provider">
            <option value="" <?php selected( $current, '' ); ?>><?php echo esc_html__( '— Automatic / First Enabled Provider —', 'presshub-social-autopost' ); ?></option>
            <?php
            if ( class_exists( 'PressHub_AI_Provider_Store' ) ) {
                $providers = PressHub_AI_Provider_Store::get_all( true );
                foreach ( $providers as $prov ) {
                    $type = $prov['type'] ?? '';
                    if ( empty( $prov['enabled'] ) || ( empty( $prov['api_key'] ) && 'ollama_local' !== $type ) ) {
                        continue;
                    }
                    $id    = $prov['id'] ?? $type;
                    $name  = $prov['name'] ?? ucfirst( $id );
                    $model = $prov['default_model'] ?? '';
                    $label = $name . ( '' !== $model ? ' (' . $model . ')' : '' );
                    echo '<option value="' . esc_attr( $id ) . '" ' . selected( $current, $id, false ) . '>' . esc_html( $label ) . '</option>';
                }
            }
            ?>
        </select>
        <p class="description"><?php echo esc_html__( 'Select which configured and enabled provider generates social copy, or leave empty to use the active default provider.', 'presshub-social-autopost' ); ?></p>
        <?php
    }

    public function render_ai_model_field(): void {
        $model = PressHub_Social_Settings_Storage::get_ai_model();
        ?>
        <input type="text" id="presshub_social_ai_model" name="presshub_social_ai_model" value="<?php echo esc_attr( $model ); ?>" class="regular-text" placeholder="e.g. gpt-4o, claude-3-5-sonnet-20240620" />
        <p class="description"><?php echo esc_html__( 'Optional model override for social copy. Leave blank to use the provider record default model.', 'presshub-social-autopost' ); ?></p>
        <?php
    }

    public function render_ai_temperature_field(): void {
        $temp = get_option( 'presshub_social_ai_temperature', '0.7' );
        ?>
        <input type="number" step="0.01" min="0" max="2" id="presshub_social_ai_temperature" name="presshub_social_ai_temperature" value="<?php echo esc_attr( $temp ); ?>" class="small-text" />
        <p class="description"><?php echo esc_html__( 'Creativity vs determinism (0.0 to 2.0). Default: 0.7. Leave blank to defer to provider default.', 'presshub-social-autopost' ); ?></p>
        <?php
    }

    public function render_ai_max_tokens_field(): void {
        $tokens = PressHub_Social_Settings_Storage::get_ai_max_tokens();
        ?>
        <input type="number" step="1" min="0" max="65536" id="presshub_social_ai_max_tokens" name="presshub_social_ai_max_tokens" value="<?php echo esc_attr( $tokens > 0 ? $tokens : '' ); ?>" class="small-text" placeholder="Default" />
        <p class="description"><?php echo esc_html__( 'Maximum tokens for generated copy (64 to 65536). Enter 0 or leave empty to defer to provider default.', 'presshub-social-autopost' ); ?></p>
        <?php
    }

    public function render_ai_timeout_field(): void {
        $timeout = PressHub_Social_Settings_Storage::get_ai_timeout();
        ?>
        <input type="number" step="1" min="0" max="600" id="presshub_social_ai_timeout" name="presshub_social_ai_timeout" value="<?php echo esc_attr( $timeout > 0 ? $timeout : '' ); ?>" class="small-text" placeholder="Default" />
        <p class="description"><?php echo esc_html__( 'Timeout in seconds for AI copy generation requests (10 to 600). Enter 0 or leave empty to defer to provider default.', 'presshub-social-autopost' ); ?></p>
        <?php
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
        $option      = $args['option'];
        $raw         = (string) get_option( $option, '' );
        $masked      = PressHub_Social_Logger::mask_secret( $raw );
        $desc        = $args['desc'] ?? '';
        $placeholder = $args['placeholder'] ?? '';
        ?>
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <input type="password" id="<?php echo esc_attr( $option ); ?>" name="<?php echo esc_attr( $option ); ?>" value="<?php echo esc_attr( $masked ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $placeholder ); ?>" autocomplete="new-password" />
            <?php if ( '' !== $raw ) : ?>
                <label style="color:#a00; font-size:12px;">
                    <input type="checkbox" name="<?php echo esc_attr( $option . '_remove' ); ?>" value="1" />
                    <?php echo esc_html__( 'Clear / Revoke Stored Secret', 'presshub-social-autopost' ); ?>
                </label>
            <?php endif; ?>
        </div>
        <?php if ( '' !== $desc ) : ?>
            <p class="description"><?php echo esc_html( $desc ); ?></p>
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
        <input type="number" id="<?php echo esc_attr( $option ); ?>" name="<?php echo esc_attr( $option ); ?>" value="<?php echo esc_attr( $val ); ?>" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>" step="<?php echo esc_attr( $step ); ?>" class="small-text" />
        <?php if ( ! empty( $args['unit'] ) ) : ?>
            <span style="margin-left:4px;"><?php echo esc_html( $args['unit'] ); ?></span>
        <?php endif; ?>
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
        <textarea id="<?php echo esc_attr( $option ); ?>" name="<?php echo esc_attr( $option ); ?>" rows="<?php echo esc_attr( $rows ); ?>" class="large-text code" style="width:100%; max-width:800px;"><?php echo esc_textarea( $val ); ?></textarea>
        <?php if ( '' !== $desc ) : ?>
            <p class="description"><?php echo esc_html( $desc ); ?></p>
        <?php endif; ?>
        <?php
    }

    public function render_worker_mode_field(): void {
        $mode     = PressHub_Social_Settings_Storage::get_worker_mode();
        $rest_url = function_exists( 'rest_url' )
            ? rest_url( 'presshub-social/v1/worker/claim' )
            : ( function_exists( 'home_url' ) ? home_url( '/wp-json/presshub-social/v1/worker/claim' ) : '/wp-json/presshub-social/v1/worker/claim' );
        ?>
        <select id="presshub_social_worker_mode" name="presshub_social_worker_mode">
            <option value="pull" <?php selected( $mode, 'pull' ); ?>><?php echo esc_html__( 'Pull Mode — Local Worker Polls WordPress via REST API (Recommended for Local PC)', 'presshub-social-autopost' ); ?></option>
            <option value="push" <?php selected( $mode, 'push' ); ?>><?php echo esc_html__( 'Push Mode — WordPress Connects Directly to Worker URL', 'presshub-social-autopost' ); ?></option>
        </select>
        <p class="description">
            <?php
            printf(
                /* translators: %s: REST claim endpoint URL */
                esc_html__( 'In Pull Mode, your local worker polls WordPress for jobs and sends heartbeats. Endpoint URL: %s', 'presshub-social-autopost' ),
                '<code>' . esc_html( $rest_url ) . '</code>'
            );
            ?>
        </p>
        <?php
    }

    public function render_worker_url_field(): void {
        $url = PressHub_Social_Settings_Storage::get_worker_url();
        ?>
        <input type="url" id="presshub_social_worker_url" name="presshub_social_worker_url" value="<?php echo esc_attr( $url ); ?>" class="regular-text code" placeholder="http://127.0.0.1:3456" />
        <p class="description"><?php echo esc_html__( 'HTTP endpoint of the local or remote Playwright / Puppeteer automation worker service.', 'presshub-social-autopost' ); ?></p>
        <?php
        $this->render_test_connection_button( 'worker' );
    }

    public function render_worker_secret_field(): void {
        $secret = PressHub_Social_Settings_Storage::get_worker_secret();
        $masked = PressHub_Social_Logger::mask_secret( $secret );
        ?>
        <div style="display:flex; align-items:center; gap:10px;">
            <input type="password" id="presshub_social_worker_secret" name="presshub_social_worker_secret" value="<?php echo esc_attr( $masked ); ?>" class="regular-text code" autocomplete="new-password" />
        </div>
        <p class="description"><?php echo esc_html__( 'Bearer secret token sent in X-Worker-Secret header to authenticate against the automation worker.', 'presshub-social-autopost' ); ?></p>
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
        <div class="presshub-social-test-row" style="margin-top:8px;">
            <button type="button" class="button button-secondary presshub-social-test-btn" data-platform="<?php echo esc_attr( $platform ); ?>">
                <span class="dashicons dashicons-update" style="line-height:1.4; vertical-align:middle; font-size:16px;"></span>
                <?php echo esc_html( sprintf( __( 'Test %s Connection', 'presshub-social-autopost' ), ucfirst( $platform ) ) ); ?>
            </button>
            <span class="spinner" style="float:none; margin:0 0 0 6px;"></span>
            <span class="presshub-social-test-status" data-platform="<?php echo esc_attr( $platform ); ?>" style="margin-left:8px; font-weight:600;"></span>
        </div>
        <?php
    }
}
