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

            <nav class="nav-tab-wrapper" style="margin-bottom: 20px;">
                <?php foreach ( $valid_tabs as $tab_id => $tab_title ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'presshub-social-settings', 'tab' => $tab_id ], admin_url( 'admin.php' ) ) ); ?>"
                       class="nav-tab <?php echo $active_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $tab_title ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <form method="post" action="options.php">
                <?php
                settings_fields( PressHub_Social_Settings_Storage::OPTION_GROUP );
                do_settings_sections( 'presshub_social_' . $active_tab );
                submit_button();
                ?>
            </form>

            <?php if ( 'prompts' === $active_tab ) : ?>
                <hr style="margin: 30px 0 20px;" />
                <div class="presshub-social-reset-box" style="background:#fff; border:1px solid #ccd0d4; padding:15px; border-radius:4px; max-width:800px;">
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
        <?php
    }

    // ------------------------------------------------------------------
    // Field Renderers: General & Engines
    // ------------------------------------------------------------------

    public function render_auto_publish_enabled_field(): void {
        $enabled = PressHub_Social_Settings_Storage::get_auto_publish_enabled();
        ?>
        <label>
            <input type="checkbox" name="presshub_social_auto_publish_enabled" value="1" <?php checked( $enabled, true ); ?> />
            <?php echo esc_html__( 'Automatically queue social posts when a standard WordPress post is published.', 'presshub-social-autopost' ); ?>
        </label>
        <?php
    }

    public function render_enabled_platforms_field(): void {
        $enabled = PressHub_Social_Settings_Storage::get_enabled_platforms();
        foreach ( PressHub_Social_Settings_Storage::PLATFORMS as $platform ) {
            $is_checked = in_array( $platform, $enabled, true );
            echo '<label style="display:inline-block; margin-right:18px;">';
            echo '<input type="checkbox" name="presshub_social_enabled_platforms[]" value="' . esc_attr( $platform ) . '" ' . checked( $is_checked, true, false ) . ' /> ';
            echo esc_html( ucfirst( $platform ) );
            echo '</label>';
        }
        echo '<p class="description">' . esc_html__( 'Only checked platforms will be queued when an article is published.', 'presshub-social-autopost' ) . '</p>';
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
        $this->render_secret_field(
            'presshub_social_github_token',
            (string) get_option( 'presshub_social_github_token', '' ),
            __( 'Optional GitHub Personal Access Token (PAT) for automatic plugin updates if the repository is private. Automatically inherits the token from PressHub AI Editor if left blank.', 'presshub-social-autopost' )
        );
    }

    public function render_platform_engine_field( array $args ): void {
        $platform = $args['platform'] ?? 'twitter';
        $current  = PressHub_Social_Settings_Storage::get_platform_engine( $platform );
        $opt_name = 'presshub_social_engine_' . $platform;
        ?>
        <select id="<?php echo esc_attr( $opt_name ); ?>" name="<?php echo esc_attr( $opt_name ); ?>">
            <option value="api" <?php selected( $current, 'api' ); ?>><?php echo esc_html__( 'Official REST API', 'presshub-social-autopost' ); ?></option>
            <option value="automation" <?php selected( $current, 'automation' ); ?>><?php echo esc_html__( 'Automation Worker (Playwright Headless)', 'presshub-social-autopost' ); ?></option>
        </select>
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
                $providers = PressHub_AI_Provider_Store::get_all( false );
                foreach ( $providers as $prov ) {
                    $id      = $prov['id'] ?? '';
                    $name    = $prov['name'] ?? $id;
                    $type    = $prov['type'] ?? '';
                    $enabled = ! empty( $prov['enabled'] ) ? '' : ' (' . __( 'Disabled in AI Editor', 'presshub-social-autopost' ) . ')';
                    echo '<option value="' . esc_attr( $id ) . '" ' . selected( $current, $id, false ) . '>' . esc_html( $name . ' [' . $type . ']' . $enabled ) . '</option>';
                }
            }
            ?>
        </select>
        <p class="description"><?php echo esc_html__( 'Select which configured provider generates social copy, or leave empty to use the active default provider.', 'presshub-social-autopost' ); ?></p>
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
        <input type="number" step="0.05" min="0" max="2" id="presshub_social_ai_temperature" name="presshub_social_ai_temperature" value="<?php echo esc_attr( $temp ); ?>" class="small-text" />
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
        $val    = (int) get_option( $option, $args['default'] ?? 0 );
        $min    = $args['min'] ?? 0;
        $max    = $args['max'] ?? 10000;
        $step   = $args['step'] ?? 1;
        $desc   = $args['desc'] ?? '';
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

    public function render_alert_email_enabled_field(): void {
        $enabled = PressHub_Social_Settings_Storage::get_alert_email_enabled();
        ?>
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
