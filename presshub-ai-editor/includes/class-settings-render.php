<?php
/**
 * PressHub AI Editor — Settings render module (Concern #2 split).
 *
 * Extracted from class-settings.php in T3. Contains every public/private
 * method whose responsibility is to produce HTML for the admin settings
 * page, plus the esc_attr_safe / esc_html_safe / mask_key helpers used
 * inside those render methods.
 *
 * Status: dead code in T3. The PressHub_AI_Settings facade still owns
 * the entire render surface; this class is not yet referenced anywhere.
 * T5 will reduce the facade to a thin forwarder shell that delegates
 * render_* calls into this module.
 *
 * @package presshub-ai-editor
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-capabilities.php';
require_once __DIR__ . '/class-settings-sections.php';

require_once __DIR__ . '/class-provider-defaults.php';
require_once __DIR__ . '/class-provider-store.php';
require_once __DIR__ . '/class-settings-storage.php';

class PressHub_AI_Settings_Render {

    /**
     * Capability gate, mirrored from the facade so render_settings_page() can
     * call $this->settings_cap() without depending on the facade.
     * Filterable for sites that delegate settings to a custom role.
     */
    public function settings_cap(): string {
        return PressHub_Capabilities::manage();
    }

    /**
     * Slug sanitizer, mirrored from the facade so render_providers_grid() can
     * call self::sanitize_slug() without depending on the facade.
     */
    public static function sanitize_slug( $value ): string {
        $value = (string) $value;
        $value = strtolower( trim( $value ) );
        return preg_replace( '/[^a-z0-9_\-]/', '', $value );
    }

    public function render_settings_page() {
        if ( ! current_user_can( $this->settings_cap() ) ) {
            wp_die( __( 'You do not have permission to view PressHub AI settings.', 'presshub-ai-editor' ) );
        }

        $this->register_help_tab();

        $section = PressHub_Settings_Sections::current();
        $defs    = PressHub_Settings_Sections::definitions();
        $label   = PressHub_Settings_Sections::label( $section );
        $groups  = PressHub_Settings_Sections::visible_groups( $section );

        if ( function_exists( 'settings_errors' ) ) {
            settings_errors();
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice after the Social prompts reset.
        $prompts_reset = isset( $_GET['message'] ) && 'prompts_reset' === $_GET['message'];
        ?>
        <div class="wrap presshub-ai-settings-wrap presshub-settings-page" id="presshub-settings-page" data-section="<?php echo self::esc_attr_safe( $section ); ?>">
            <h1><?php echo esc_html__( 'PressHub — Settings', 'presshub-ai-editor' ); ?></h1>
            <hr class="wp-header-end" />
            <?php if ( $prompts_reset ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'All prompts and templates have been restored to their defaults.', 'presshub-ai-editor' ); ?></p></div>
            <?php endif; ?>
            <?php $this->render_whats_new_panel(); ?>

            <div class="presshub-settings-layout">
                <?php $this->render_section_nav( $section ); ?>

                <div class="presshub-settings-main" id="presshub-settings-main">
                    <h2 class="presshub-settings-section-title" id="presshub-settings-section-title"><?php echo self::esc_html_safe( $label ); ?></h2>
                    <p class="presshub-settings-section-intro"><?php echo self::esc_html_safe( (string) ( $defs[ $section ]['intro'] ?? '' ) ); ?></p>

                    <div id="presshub-settings-error-summary" class="notice notice-error presshub-error-summary" tabindex="-1" hidden>
                        <h3 class="presshub-error-summary-title"><?php echo esc_html__( 'There is a problem', 'presshub-ai-editor' ); ?></h3>
                        <ul class="presshub-error-summary-list"></ul>
                    </div>

                    <?php if ( count( $groups ) > 1 ) : ?>
                        <nav class="presshub-on-this-page" aria-label="<?php echo esc_attr__( 'On this page', 'presshub-ai-editor' ); ?>">
                            <span class="presshub-on-this-page-label"><?php echo esc_html__( 'On this page:', 'presshub-ai-editor' ); ?></span>
                            <?php foreach ( $groups as $anchor => $group_label ) : ?>
                                <a href="#<?php echo self::esc_attr_safe( $anchor ); ?>" class="presshub-group-link"><?php echo self::esc_html_safe( $group_label ); ?></a>
                            <?php endforeach; ?>
                        </nav>
                    <?php endif; ?>

                    <form method="post" action="options.php" id="presshub-ai-settings-form" novalidate class="presshub-settings-section-form" data-section="<?php echo self::esc_attr_safe( $section ); ?>">
                        <?php
                        // option_page and nonce, so a no-JS submit still reaches options.php's capability check.
                        settings_fields( 'presshub_ai_options' );
                        ?>
                        <div id="presshub-tab-pane-<?php echo self::esc_attr_safe( $section ); ?>" class="presshub-tab-pane presshub-settings-section" data-section="<?php echo self::esc_attr_safe( $section ); ?>">
                            <?php $this->render_section( $section ); ?>
                        </div>

                        <div id="presshub-sticky-save-bar" class="presshub-sticky-save-bar presshub-settings-savebar" data-active-tab="<?php echo self::esc_attr_safe( $section ); ?>" role="region" aria-label="<?php echo esc_attr__( 'Save changes', 'presshub-ai-editor' ); ?>">
                            <button type="submit" id="presshub-sticky-save-btn" class="button button-primary presshub-sticky-save-btn">
                                <?php echo self::esc_html_safe( sprintf( /* translators: %s: section name */ __( 'Save %s', 'presshub-ai-editor' ), $label ) ); ?>
                            </button>
                            <span id="presshub-sticky-save-spinner" class="spinner presshub-sticky-save-spinner"></span>
                            <span id="presshub-sticky-unsaved-badge" class="presshub-sticky-unsaved-badge" role="status" aria-live="polite" hidden></span>
                            <span id="presshub-sticky-status-msg" class="presshub-sticky-status-msg" role="status" aria-live="polite"></span>
                        </div>
                    </form>
                    <?php if ( 'site-system' === $section ) { $this->render_transfer_forms(); } ?>
                </div>
            </div>

            <?php
            if ( 'ai-models' === $section ) {
                $this->render_provider_modal();
            }
            if ( 'daily-briefing' === $section ) {
                $this->render_source_modal();
                $this->render_bulk_sources_modal();
            }
            ?>
        </div>
        <?php
    }

    /**
     * The section list: a search box, one plain link per section (the current
     * one carries aria-current="page") and, on narrow screens, a <select>.
     */
    public function render_section_nav( string $current ) {
        $visible = PressHub_Settings_Sections::visible();
        ?>
        <nav class="presshub-settings-nav" id="presshub-settings-nav" aria-label="<?php echo esc_attr__( 'Settings sections', 'presshub-ai-editor' ); ?>">
            <div class="presshub-settings-search" role="search">
                <label for="presshub-settings-search-input" class="screen-reader-text"><?php echo esc_html__( 'Search settings', 'presshub-ai-editor' ); ?></label>
                <input type="search" id="presshub-settings-search-input" class="presshub-no-dirty" autocomplete="off" placeholder="<?php echo esc_attr__( 'Search settings…', 'presshub-ai-editor' ); ?>" role="combobox" aria-expanded="false" aria-controls="presshub-settings-search-results" aria-autocomplete="list" aria-describedby="presshub-settings-search-help" />
                <p id="presshub-settings-search-help" class="screen-reader-text"><?php echo esc_html__( 'Type a setting name, its help text or an old name.', 'presshub-ai-editor' ); ?></p>
                <ul id="presshub-settings-search-results" class="presshub-settings-search-results" role="listbox" hidden></ul>
                <span id="presshub-settings-search-status" class="screen-reader-text" role="status" aria-live="polite"></span>
            </div>
            <div class="presshub-settings-nav-select-wrap">
                <label for="presshub-settings-section-select"><?php echo esc_html__( 'Section', 'presshub-ai-editor' ); ?></label>
                <select id="presshub-settings-section-select" class="presshub-settings-nav-select presshub-no-dirty">
                    <?php foreach ( $visible as $slug ) : ?>
                        <option value="<?php echo self::esc_attr_safe( PressHub_Settings_Sections::url( $slug ) ); ?>"<?php echo $slug === $current ? ' selected="selected"' : ''; ?>><?php echo self::esc_html_safe( PressHub_Settings_Sections::label( $slug ) ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <ul class="presshub-settings-nav-list">
                <?php foreach ( $visible as $slug ) : ?>
                    <li>
                        <a href="<?php echo self::esc_attr_safe( PressHub_Settings_Sections::url( $slug ) ); ?>" class="presshub-settings-nav-link<?php echo $slug === $current ? ' is-current' : ''; ?>" data-section="<?php echo self::esc_attr_safe( $slug ); ?>"<?php echo $slug === $current ? ' aria-current="page"' : ''; ?>><?php echo self::esc_html_safe( PressHub_Settings_Sections::label( $slug ) ); ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
        <?php
    }

    /**
     * One "What's new in settings" panel for the whole page, until the
     * user dismisses it (remembered per user, #246).
     */
    public function render_whats_new_panel() {
        if ( ! class_exists( 'PressHub_Settings_Registry' ) ) {
            return;
        }
        $payload = PressHub_Settings_Registry::search_payload();
        if ( ! empty( $payload['whats_new_dismissed'] ) ) {
            return;
        }
        ?>
        <div class="notice notice-info is-dismissible presshub-whats-new" id="presshub-whats-new">
            <h2 class="presshub-whats-new-title"><?php echo esc_html__( "What's new in settings", 'presshub-ai-editor' ); ?></h2>
            <ul class="presshub-whats-new-list">
                <?php foreach ( (array) $payload['whats_new'] as $item ) : ?>
                    <li><?php echo self::esc_html_safe( (string) $item ); ?></li>
                <?php endforeach; ?>
            </ul>
            <p><a href="#presshub-settings-search-input" class="presshub-whats-new-search"><?php echo esc_html__( 'Search for a setting', 'presshub-ai-editor' ); ?></a></p>
        </div>
        <?php
    }

    /** Opens a group: a heading the "On this page" bar links to. */
    private function group_start( string $id, string $title, string $intro = '' ) {
        echo '<section id="' . self::esc_attr_safe( $id ) . '" class="presshub-settings-group" aria-labelledby="' . self::esc_attr_safe( $id . '-title' ) . '">';
        echo '<h3 id="' . self::esc_attr_safe( $id . '-title' ) . '" class="presshub-settings-group-title">' . self::esc_html_safe( $title ) . '</h3>';
        if ( '' !== $intro ) {
            echo '<p class="description presshub-settings-group-intro">' . self::esc_html_safe( $intro ) . '</p>';
        }
    }

    private function group_end() {
        echo '</section>';
    }

    /** Renders one section's groups; only that section's fields reach the page. */
    public function render_section( string $section ) {
        $method = 'render_section_' . str_replace( '-', '_', $section );
        if ( method_exists( $this, $method ) ) {
            $this->{$method}();
        }
    }

    /** The Social settings renderer, or null when the Social module is not loaded. */
    private function social_render() {
        if ( ! class_exists( 'PressHub_Social_Settings_Render' ) ) {
            $file = __DIR__ . '/class-social-settings-render.php';
            if ( file_exists( $file ) ) {
                require_once $file;
            }
        }
        return class_exists( 'PressHub_Social_Settings_Render' ) ? new PressHub_Social_Settings_Render() : null;
    }

    // ------------------------------------------------------------------
    // Sections (#246).
    // ------------------------------------------------------------------

    public function render_section_connections() {
        $groups = PressHub_Settings_Sections::visible_groups( 'connections' );
        $social = $this->social_render();
        if ( $social && isset( $groups['social-accounts'] ) ) {
            $this->group_start( 'social-accounts', $groups['social-accounts'], __( 'API credentials for the platforms that use the Official REST API engine.', 'presshub-ai-editor' ) );
            $social->render_settings_sections( 'presshub_social_credentials' );
            $this->group_end();
        }
        if ( $social && isset( $groups['automation-worker'] ) ) {
            $this->group_start( 'automation-worker', $groups['automation-worker'], __( 'The Playwright worker that posts for platforms on the Automation engine.', 'presshub-ai-editor' ) );
            $social->render_settings_sections( 'presshub_social_worker' );
            $this->group_end();
        }
        $this->group_start( 'google-cloud', $groups['google-cloud'] );
        $this->render_section_with_fields( 'presshub_ai_media', '' );
        $this->group_end();

        $this->group_start( 'plugin-updates', $groups['plugin-updates'] );
        $this->render_section_with_fields( 'presshub_ai_github', '' );
        $this->group_end();

        $this->group_start( 'connection-tests', $groups['connection-tests'] );
        ?>
        <p><?php echo __( 'Verify connectivity with any configured provider. Each test uses its own rate-limit budget and logs activity in the Token Logger dashboard.', 'presshub-ai-editor' ); ?></p>
                    <div class="ph-display-flex ph-gap-8px ph-align-items-center ph-flex-wrap-wrap">
                        <button type="button" id="presshub-ai-test-api" class="button presshub-ai-test-api" data-provider=""><?php echo __( 'Test Active Provider', 'presshub-ai-editor' ); ?></button>
                        <button type="button" class="button presshub-ai-test-api" data-provider="openai"><?php echo __( 'Test OpenAI', 'presshub-ai-editor' ); ?></button>
                        <button type="button" class="button presshub-ai-test-api" data-provider="anthropic"><?php echo __( 'Test Anthropic', 'presshub-ai-editor' ); ?></button>
                        <button type="button" class="button presshub-ai-test-api" data-provider="gemini"><?php echo __( 'Test Gemini', 'presshub-ai-editor' ); ?></button>
                        <span id="presshub-ai-test-spinner" class="spinner" role="status"><span class="screen-reader-text"></span></span>
                    </div>
                    <div id="presshub-ai-test-result" class="ph-margin-top-15px ph-font-weight-bold"></div>
        <?php
        $this->group_end();
    }

    public function render_section_ai_models() {
        $groups = PressHub_Settings_Sections::visible_groups( 'ai-models' );
        $this->group_start( 'ai-providers', $groups['ai-providers'] );
        $this->render_providers_grid();
        $this->group_end();

        $this->group_start( 'copilot', $groups['copilot'], __( 'The AI provider, model and parameters behind the sidebar Copilot and the research agents.', 'presshub-ai-editor' ) );
        ?>
        <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="presshub_ai_copilot_provider"><?php echo __( 'Active Copilot Provider', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_copilot_prov = (string) get_option( 'presshub_ai_copilot_provider', '' ); ?>
                                    <select name="presshub_ai_copilot_provider" id="presshub_ai_copilot_provider" class="regular-text">
                                        <?php echo self::get_active_providers_options( $cur_copilot_prov, __( '-- Use Active Provider Default --', 'presshub-ai-editor' ) ); ?>
                                    </select>
                                    <p class="description"><?php echo __( 'Select which AI provider powers sidebar chat and background deep-research tasks.', 'presshub-ai-editor' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_copilot_model"><?php echo __( 'Custom Model Override', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_copilot_model = (string) get_option( 'presshub_ai_copilot_model', '' ); ?>
                                    <input type="text" name="presshub_ai_copilot_model" id="presshub_ai_copilot_model" value="<?php echo self::esc_attr_safe( $cur_copilot_model ); ?>" class="regular-text code" placeholder="<?php echo esc_attr__( 'Leave empty to use provider default model', 'presshub-ai-editor' ); ?>" />
                                    <p class="description"><?php echo __( 'Optional specific model identifier for Copilot chat (e.g. gpt-4o, gemini-2.0-flash).', 'presshub-ai-editor' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_copilot_max_tokens"><?php echo __( 'Maximum Output Tokens', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_copilot_tokens = PressHub_AI_Settings_Storage::get_copilot_max_tokens(); ?>
                                    <input type="number" min="64" max="65536" name="presshub_ai_copilot_max_tokens" id="presshub_ai_copilot_max_tokens" value="<?php echo $cur_copilot_tokens > 0 ? self::esc_attr_safe( (string) $cur_copilot_tokens ) : ''; ?>" class="small-text" placeholder="20000" />
                                    <p class="description"><?php echo __( 'Maximum number of tokens the model can generate in a single response (64 - 65,536). Leave empty to use provider default (e.g. 20,000 / 60,000).', 'presshub-ai-editor' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_copilot_temperature"><?php echo __( 'Sampling Temperature', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_copilot_temp = get_option( 'presshub_ai_copilot_temperature', '0.7' ); ?>
                                    <input type="number" min="0" max="2" step="0.05" name="presshub_ai_copilot_temperature" id="presshub_ai_copilot_temperature" value="<?php echo self::esc_attr_safe( (string) $cur_copilot_temp ); ?>" class="small-text" />
                                    <p class="description"><?php echo __( 'Sampling temperature for assistant chat (0.0 to 2.0). Default 0.7.', 'presshub-ai-editor' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_copilot_timeout"><?php echo __( 'Request Timeout (seconds)', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_copilot_timeout = (int) get_option( 'presshub_ai_copilot_timeout', 300 ); ?>
                                    <input type="number" min="10" max="300" name="presshub_ai_copilot_timeout" id="presshub_ai_copilot_timeout" value="<?php echo self::esc_attr_safe( (string) $cur_copilot_timeout ); ?>" class="small-text" />
                                    <p class="description"><?php echo __( 'HTTP timeout for chat and research calls. Default 300s.', 'presshub-ai-editor' ); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
        <?php
        $this->group_end();
    }

    public function render_section_writing_qa() {
        $groups = PressHub_Settings_Sections::visible_groups( 'writing-qa' );
        $this->group_start( 'drafting', $groups['drafting'], __( 'The AI engine, model and drafting behavior for drafts and article reviews in the editor.', 'presshub-ai-editor' ) );
        ?>

                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><label for="presshub_ai_coauthor_provider"><?php echo __( 'Active Co-Author Provider', 'presshub-ai-editor' ); ?></label></th>
                                    <td>
                                        <?php $cur_coauthor_prov = (string) get_option( 'presshub_ai_coauthor_provider', '' ); ?>
                                        <select name="presshub_ai_coauthor_provider" id="presshub_ai_coauthor_provider" class="regular-text">
                                            <?php echo self::get_active_providers_options( $cur_coauthor_prov, __( '-- Use Active Provider Default --', 'presshub-ai-editor' ) ); ?>
                                        </select>
                                        <p class="description"><?php echo __( 'Select which AI provider powers drafting and scorecard evaluation in the editor metabox.', 'presshub-ai-editor' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="presshub_ai_coauthor_model"><?php echo __( 'Custom Model Override', 'presshub-ai-editor' ); ?></label></th>
                                    <td>
                                        <?php $cur_coauthor_model = (string) get_option( 'presshub_ai_coauthor_model', '' ); ?>
                                        <input type="text" name="presshub_ai_coauthor_model" id="presshub_ai_coauthor_model" value="<?php echo self::esc_attr_safe( $cur_coauthor_model ); ?>" class="regular-text code" placeholder="<?php echo esc_attr__( 'Leave empty to use provider default model', 'presshub-ai-editor' ); ?>" />
                                        <p class="description"><?php echo __( 'Optional specific model identifier for Co-Author (e.g. gpt-4o, claude-3-5-sonnet-20241022).', 'presshub-ai-editor' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="presshub_ai_coauthor_max_tokens"><?php echo __( 'Maximum Output Tokens', 'presshub-ai-editor' ); ?></label></th>
                                    <td>
                                        <?php $cur_coauthor_tokens = PressHub_AI_Settings_Storage::get_coauthor_max_tokens(); ?>
                                        <input type="number" min="64" max="65536" name="presshub_ai_coauthor_max_tokens" id="presshub_ai_coauthor_max_tokens" value="<?php echo $cur_coauthor_tokens > 0 ? self::esc_attr_safe( (string) $cur_coauthor_tokens ) : ''; ?>" class="small-text" placeholder="20000" />
                                        <p class="description"><?php echo __( 'Maximum number of tokens the model can generate in a single response (64 - 65,536). Leave empty to use provider default (e.g. 20,000 / 60,000).', 'presshub-ai-editor' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="presshub_ai_coauthor_temperature"><?php echo __( 'Sampling Temperature', 'presshub-ai-editor' ); ?></label></th>
                                    <td>
                                        <?php $cur_coauthor_temp = get_option( 'presshub_ai_coauthor_temperature', '0.7' ); ?>
                                        <input type="number" min="0" max="2" step="0.05" name="presshub_ai_coauthor_temperature" id="presshub_ai_coauthor_temperature" value="<?php echo self::esc_attr_safe( (string) $cur_coauthor_temp ); ?>" class="small-text" />
                                        <p class="description"><?php echo __( 'Creativity tuning for story drafting (0.0 to 2.0). Scorecards use deterministic 0.0.', 'presshub-ai-editor' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="presshub_ai_coauthor_timeout"><?php echo __( 'Request Timeout (seconds)', 'presshub-ai-editor' ); ?></label></th>
                                    <td>
                                        <?php $cur_coauthor_timeout = (int) get_option( 'presshub_ai_coauthor_timeout', 300 ); ?>
                                        <input type="number" min="10" max="300" name="presshub_ai_coauthor_timeout" id="presshub_ai_coauthor_timeout" value="<?php echo self::esc_attr_safe( (string) $cur_coauthor_timeout ); ?>" class="small-text" />
                                        <p class="description"><?php echo __( 'HTTP timeout for draft generation requests. Default 300s.', 'presshub-ai-editor' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="presshub_ai_coauthor_max_source_chars"><?php echo __( 'Max Source Material Characters', 'presshub-ai-editor' ); ?></label></th>
                                    <td>
                                        <?php $cur_max_chars = PressHub_AI_Settings_Storage::get_coauthor_max_source_chars(); ?>
                                        <input type="number" min="1000" max="1000000" step="1000" name="presshub_ai_coauthor_max_source_chars" id="presshub_ai_coauthor_max_source_chars" value="<?php echo self::esc_attr_safe( (string) $cur_max_chars ); ?>" class="regular-text" />
                                        <p class="description"><?php echo __( 'Maximum character limit allowed for pasted source material and research notes (1,000 – 1,000,000). Default 200,000 characters.', 'presshub-ai-editor' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo __( 'Source Processing', 'presshub-ai-editor' ); ?></th>
                                    <td>
                                        <?php $this->render_fetch_urls_field(); ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
        <?php
        $this->group_end();

        $this->group_start( 'qa-gate', $groups['qa-gate'] );
        ?>

                        <p><?php echo __( 'Configure the automated quality-assurance review gate. When enabled, articles submitted for publication are automatically evaluated against editorial standards and blocked if they do not meet the minimum score.', 'presshub-ai-editor' ); ?></p>
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><?php echo __( 'Enable QA Review System', 'presshub-ai-editor' ); ?></th>
                                    <td>
                                        <?php $this->render_qa_enabled_field(); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo __( 'Include Daily Briefings', 'presshub-ai-editor' ); ?></th>
                                    <td>
                                        <?php $this->render_qa_include_briefings_field(); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="presshub_ai_qa_min_score"><?php echo __( 'Minimum Passing Score', 'presshub-ai-editor' ); ?></label></th>
                                    <td>
                                        <?php $this->render_qa_min_score_field(); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo __( 'Notify Editor on Failures', 'presshub-ai-editor' ); ?></th>
                                    <td>
                                        <?php $this->render_qa_notify_editor_field(); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="presshub_ai_qa_editor_email"><?php echo __( 'Editor Notification Email', 'presshub-ai-editor' ); ?></label></th>
                                    <td>
                                        <?php $this->render_qa_editor_email_field(); ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <details class="presshub-settings-advanced" id="qa-gate-advanced">
                            <summary><?php echo esc_html__( 'Advanced: QA prompt', 'presshub-ai-editor' ); ?></summary>
                            <p><?php echo esc_html__( 'Customize the prompt template the QA Review model uses for author articles.', 'presshub-ai-editor' ); ?></p>
                            <?php $this->render_qa_prompt_studio(); ?>
                        </details>
        <?php
        $this->group_end();

        $this->group_start( 'seo', $groups['seo'] );
        ?>

                        <p><?php echo esc_html__( 'Help search engines and readers find your content: describe generated images properly in the Media Library, tag automated posts, and score SEO during the AI editorial review.', 'presshub-ai-editor' ); ?></p>
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><?php echo esc_html__( 'AI Image Metadata', 'presshub-ai-editor' ); ?></th>
                                    <td>
                                        <?php $this->render_image_metadata_enabled_field(); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo esc_html__( 'Automatic Tags & SEO', 'presshub-ai-editor' ); ?></th>
                                    <td>
                                        <?php $this->render_seo_auto_enabled_field(); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo esc_html__( 'Daily Briefing SEO Structure', 'presshub-ai-editor' ); ?></th>
                                    <td>
                                        <?php $this->render_briefing_seo_enhance_enabled_field(); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="presshub_ai_seo_max_tags"><?php echo esc_html__( 'Maximum Tags per Post', 'presshub-ai-editor' ); ?></label></th>
                                    <td>
                                        <?php $this->render_seo_max_tags_field(); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo esc_html__( 'SEO Score in Editorial Review', 'presshub-ai-editor' ); ?></th>
                                    <td>
                                        <?php $this->render_seo_review_enabled_field(); ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
        <?php
        $this->group_end();

        $this->group_start( 'featured-images', $groups['featured-images'], __( 'Featured images are made from the story: one AI call writes an image prompt from the article, a second one draws it.', 'presshub-ai-editor' ) );
        ?>
        <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="presshub_ai_image_feature_enabled"><?php echo __( 'Enable Feature', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_image_feature_enabled_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_image_prompt_provider"><?php echo __( 'Article Analysis AI Provider', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_image_prompt_provider_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_image_prompt_model"><?php echo __( 'Article Analysis Model Override', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_image_prompt_model_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_image_generation_provider"><?php echo __( 'Image Generation AI Provider', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_image_generation_provider_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_image_aspect_ratio"><?php echo __( 'Default Aspect Ratio', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_image_aspect_ratio_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_image_analysis_prompt"><?php echo __( 'Article Analysis Prompt Template', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_image_analysis_prompt_field(); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
        <?php
        $this->group_end();

        $this->group_start( 'presets', $groups['presets'] );
        $presets_url = class_exists( 'PressHub_Admin_Menu' ) ? PressHub_Admin_Menu::url( 'presshub-ai-presets' ) : admin_url( 'admin.php?page=presshub-ai-presets' );
        ?>
        <p><?php echo esc_html__( 'Presets bundle a prompt, model and tone for the Co-Author. They have their own page because each preset is edited on its own.', 'presshub-ai-editor' ); ?></p>
        <p><a class="button" href="<?php echo self::esc_attr_safe( $presets_url ); ?>"><?php echo esc_html__( 'Open Presets', 'presshub-ai-editor' ); ?></a></p>
        <?php
        $this->group_end();
    }

    public function render_section_daily_briefing() {
        ?>

                    <section id="briefing-schedule" class="presshub-settings-group" aria-labelledby="briefing-schedule-title">
                    <h3 id="briefing-schedule-title" class="presshub-settings-group-title"><?php echo esc_html__( 'Schedule', 'presshub-ai-editor' ); ?></h3>
                    <p class="description"><?php echo esc_html__( 'The morning and noon editions run on their own schedules. Each one harvests first, then generates.', 'presshub-ai-editor' ); ?></p>
                    <div class="presshub-schedule-grid">
                    <div class="presshub-schedule-card">
                    <h4><?php echo esc_html__( 'Morning edition', 'presshub-ai-editor' ); ?></h4>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_schedule_enabled"><?php echo __( 'Enable Scheduled Briefing Hub', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_schedule_enabled_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_harvest_time"><?php echo __( 'Morning Harvest Time', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_harvest_time_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_generation_time"><?php echo __( 'Generation Trigger Time', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_generation_time_field(); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                    <div class="presshub-schedule-card">
                    <h4><?php echo esc_html__( 'Noon edition', 'presshub-ai-editor' ); ?></h4>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_noon_schedule_enabled"><?php echo __( 'Enable Noon Briefing Schedule', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_noon_schedule_enabled_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_noon_harvest_time"><?php echo __( 'Noon Harvest Time', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_noon_harvest_time_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_noon_generation_time"><?php echo __( 'Noon Generation Time', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_noon_generation_time_field(); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                    </div>
                    </section>

                    <section id="briefing-sources" class="presshub-settings-group" aria-labelledby="briefing-sources-title">
                    <h3 id="briefing-sources-title" class="presshub-settings-group-title"><?php echo esc_html__( 'Sources & harvesting', 'presshub-ai-editor' ); ?></h3>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_sources"><?php echo __( 'News Source URLs', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_sources_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_harvest_time_budget"><?php echo __( 'Harvest Execution Time Budget (seconds)', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_harvest_time_budget_field(); ?>
                                </td>
                            </tr>
                            <?php $this->render_social_sources_summary_row(); ?>
                            <?php $this->render_press_inbox_rows(); ?>
                        </tbody>
                    </table>
                    </section>

                    <section id="briefing-text" class="presshub-settings-group" aria-labelledby="briefing-text-title">
                    <h3 id="briefing-text-title" class="presshub-settings-group-title"><?php echo esc_html__( 'Text story', 'presshub-ai-editor' ); ?></h3>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="presshub_ai_curation_max_articles"><?php echo __( 'Maximum Articles Sent to Curation LLM', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_curation_max_articles_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_curation_max_chars_per_article"><?php echo __( 'Maximum Characters per Article (Curation)', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_curation_max_chars_per_article_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_text_preset"><?php echo __( 'Text Instruction Preset', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_text_preset_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_text_prompt"><?php echo __( 'Curator System Prompt', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_text_prompt_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_qa_briefing_prompt"><?php echo esc_html__( 'Briefing QA review prompt', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_qa_briefing_prompt_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo __( 'AI QA Review Gate', 'presshub-ai-editor' ); ?></th>
                                <td>
                                    <?php $this->render_briefing_qa_gate_notice(); ?>
                                </td>
                            </tr>
                            <tr class="presshub-subgroup-row"><th scope="row" colspan="2"><h4 class="ph-margin-1em-0-0"><?php echo esc_html__( 'Curator model', 'presshub-ai-editor' ); ?></h4></th></tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_text_provider"><?php echo __( 'Curator AI Provider', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_text_prov = (string) get_option( 'presshub_ai_briefing_text_provider', '' ); ?>
                                    <select name="presshub_ai_briefing_text_provider" id="presshub_ai_briefing_text_provider" class="regular-text">
                                        <?php echo self::get_active_providers_options( $cur_text_prov, __( '-- Use Active Provider Default --', 'presshub-ai-editor' ) ); ?>
                                    </select>
                                    <p class="description"><?php echo __( 'AI Provider responsible for filtering and summarizing morning news into article drafts.', 'presshub-ai-editor' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_text_model"><?php echo __( 'Curator Model Override', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_text_model = (string) get_option( 'presshub_ai_briefing_text_model', '' ); ?>
                                    <input type="text" name="presshub_ai_briefing_text_model" id="presshub_ai_briefing_text_model" value="<?php echo self::esc_attr_safe( $cur_text_model ); ?>" class="regular-text code" placeholder="<?php echo esc_attr__( 'Leave empty to use provider default', 'presshub-ai-editor' ); ?>" />
                                    <?php self::registry_help( 'presshub_ai_briefing_text_model' ); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_text_max_tokens"><?php echo __( 'Curator Maximum Output Tokens', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_text_tokens = PressHub_AI_Settings_Storage::get_briefing_text_max_tokens(); ?>
                                    <input type="number" min="64" max="65536" name="presshub_ai_briefing_text_max_tokens" id="presshub_ai_briefing_text_max_tokens" value="<?php echo $cur_text_tokens > 0 ? self::esc_attr_safe( (string) $cur_text_tokens ) : ''; ?>" class="small-text" placeholder="20000" />
                                    <p class="description"><?php echo __( 'Maximum output tokens for news story curation (64 - 65,536). Leave empty to use provider default (e.g. 20,000 / 60,000).', 'presshub-ai-editor' ); ?></p>
                                </td>
                            </tr>
                            <?php $this->render_module_tuning_rows( 'briefing_text', __( 'Curator', 'presshub-ai-editor' ) ); ?>
                        </tbody>
                    </table>
                    </section>

                    <section id="briefing-podcast" class="presshub-settings-group" aria-labelledby="briefing-podcast-title">
                    <h3 id="briefing-podcast-title" class="presshub-settings-group-title"><?php echo esc_html__( 'Podcast script', 'presshub-ai-editor' ); ?></h3>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_target_duration"><?php echo __( 'Target Podcast Duration', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_target_duration_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_host_count"><?php echo __( 'Podcast Presenters Count', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_host_count_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_podcast_preset"><?php echo __( 'Podcast dialogue preset', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_podcast_preset_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_podcast_style"><?php echo esc_html__( 'Active Dialogue Style', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_podcast_style_field(); ?>
                                </td>
                            </tr>
                            <tr class="presshub-subgroup-row"><th scope="row" colspan="2"><h4 class="ph-margin-1em-0-0"><?php echo esc_html__( 'Scriptwriter model', 'presshub-ai-editor' ); ?></h4></th></tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_podcast_provider"><?php echo __( 'Scriptwriter AI Provider', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_pod_prov = (string) get_option( 'presshub_ai_briefing_podcast_provider', '' ); ?>
                                    <select name="presshub_ai_briefing_podcast_provider" id="presshub_ai_briefing_podcast_provider" class="regular-text">
                                        <?php echo self::get_active_providers_options( $cur_pod_prov, __( '-- Use Active Provider Default --', 'presshub-ai-editor' ) ); ?>
                                    </select>
                                    <p class="description"><?php echo __( 'AI Provider responsible for writing conversational podcast scripts.', 'presshub-ai-editor' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_podcast_model"><?php echo __( 'Scriptwriter Model Override', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_pod_model = (string) get_option( 'presshub_ai_briefing_podcast_model', '' ); ?>
                                    <input type="text" name="presshub_ai_briefing_podcast_model" id="presshub_ai_briefing_podcast_model" value="<?php echo self::esc_attr_safe( $cur_pod_model ); ?>" class="regular-text code" placeholder="<?php echo esc_attr__( 'Leave empty to use provider default', 'presshub-ai-editor' ); ?>" />
                                    <?php self::registry_help( 'presshub_ai_briefing_podcast_model' ); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_podcast_max_tokens"><?php echo __( 'Scriptwriter Maximum Output Tokens', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_pod_tokens = PressHub_AI_Settings_Storage::get_briefing_podcast_max_tokens(); ?>
                                    <input type="number" min="64" max="65536" name="presshub_ai_briefing_podcast_max_tokens" id="presshub_ai_briefing_podcast_max_tokens" value="<?php echo $cur_pod_tokens > 0 ? self::esc_attr_safe( (string) $cur_pod_tokens ) : ''; ?>" class="small-text" placeholder="20000" />
                                    <p class="description"><?php echo __( 'Maximum output tokens for podcast script generation (64 - 65,536). Leave empty to use provider default (e.g. 20,000 / 60,000).', 'presshub-ai-editor' ); ?></p>
                                </td>
                            </tr>
                            <?php $this->render_module_tuning_rows( 'briefing_podcast', __( 'Scriptwriter', 'presshub-ai-editor' ) ); ?>
                        </tbody>
                    </table>
                    <?php $this->render_briefing_prompt_studio(); ?>
                    </section>

                    <section id="briefing-voices" class="presshub-settings-group" aria-labelledby="briefing-voices-title">
                    <h3 id="briefing-voices-title" class="presshub-settings-group-title"><?php echo esc_html__( 'Voices & audio', 'presshub-ai-editor' ); ?></h3>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <?php
                            $cur_payload_mode = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_tts_payload_mode() : 'structured';
                            $sys_inactive     = in_array( $cur_payload_mode, [ 'structured', 'legacy' ], true );
                            $speed_inactive   = ( 'legacy' === $cur_payload_mode );
                            $pitch_inactive   = ( 'legacy' === $cur_payload_mode );
                            ?>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_tts_engine"><?php echo __( 'Speech engine', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_tts_engine_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_podcast_tts_provider"><?php echo __( 'Speech AI Provider (logosAI)', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_tts_prov = (string) get_option( 'presshub_ai_briefing_podcast_tts_provider', '' ); ?>
                                    <select name="presshub_ai_briefing_podcast_tts_provider" id="presshub_ai_briefing_podcast_tts_provider" class="regular-text">
                                        <?php echo self::get_speech_providers_options( $cur_tts_prov, __( '-- Use Active Gemini Provider --', 'presshub-ai-editor' ) ); ?>
                                    </select>
                                    <p class="description"><?php echo __( 'Select which AI provider powers neural speech generation (Google Gemini / AI Studio configured in the AI Providers tab).', 'presshub-ai-editor' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_tts_model"><?php echo __( 'Voice Generation AI Model', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_tts_model_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                 <th scope="row"><label for="presshub_ai_briefing_tts_timeout"><?php echo __( 'Speech AI Timeout (seconds)', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_tts_timeout_field(); ?>
                                </td>
                            </tr>
                            <?php $cur_host_count = PressHub_AI_Settings_Storage::get_briefing_host_count(); ?>
                            <tr id="presshub-host-female-row">
                                <th scope="row"><label for="presshub_ai_briefing_host_female"><?php echo __( 'Presenter 1 Name', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_host_female_field(); ?>
                                </td>
                            </tr>
                            <tr id="presshub-host-male-row" class="<?php echo ( $cur_host_count >= 2 ) ? '' : ' presshub-is-hidden'; ?>">
                                <th scope="row"><label for="presshub_ai_briefing_host_male"><?php echo __( 'Presenter 2 Name', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_host_male_field(); ?>
                                </td>
                            </tr>
                            <tr id="presshub-host-tertiary-row" class="<?php echo ( $cur_host_count >= 3 ) ? '' : ' presshub-is-hidden'; ?>">
                                <th scope="row"><label for="presshub_ai_briefing_host_tertiary"><?php echo __( 'Presenter 3 Name', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_host_tertiary_field(); ?>
                                </td>
                            </tr>
                            <tr id="presshub-voice-female-row">
                                <th scope="row"><label for="presshub_ai_briefing_voice_female"><?php echo __( 'Presenter 1 Voice (TTS)', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_voice_female_field(); ?>
                                </td>
                            </tr>
                            <tr id="presshub-voice-male-row" class="<?php echo ( $cur_host_count >= 2 ) ? '' : ' presshub-is-hidden'; ?>">
                                <th scope="row"><label for="presshub_ai_briefing_voice_male"><?php echo __( 'Presenter 2 Voice (TTS)', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_voice_male_field(); ?>
                                </td>
                            </tr>
                            <tr id="presshub-voice-tertiary-row" class="<?php echo ( $cur_host_count >= 3 ) ? '' : ' presshub-is-hidden'; ?>">
                                <th scope="row"><label for="presshub_ai_briefing_voice_tertiary"><?php echo __( 'Presenter 3 Voice (TTS)', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_voice_tertiary_field(); ?>
                                </td>
                            </tr>
                            <tr id="presshub-voice-speed-row" class="<?php echo $speed_inactive ? 'presshub-setting-inactive' : ''; ?>">
                                <th scope="row"><label for="presshub_ai_briefing_voice_speed"><?php echo __( 'Speaking Rate / Speed', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <div class="presshub-inactive-mode-badge<?php echo $speed_inactive ? '' : ' presshub-is-hidden'; ?>">
                                        <span class="ph-color-646970 ph-font-size-16px ph-width-16px ph-height-16px ph-vertical-align-text-bottom dashicons dashicons-info"></span>
                                        <?php echo esc_html__( 'Not used in Legacy mode (Legacy mode only prepends plain text instructions before the script).', 'presshub-ai-editor' ); ?>
                                    </div>
                                    <?php $this->render_briefing_voice_speed_field(); ?>
                                </td>
                            </tr>
                            <tr id="presshub-voice-pitch-row" class="<?php echo $pitch_inactive ? 'presshub-setting-inactive' : ''; ?>">
                                <th scope="row"><label for="presshub_ai_briefing_voice_pitch"><?php echo __( 'Voice Pitch Tuning', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <div class="presshub-inactive-mode-badge<?php echo $pitch_inactive ? '' : ' presshub-is-hidden'; ?>">
                                        <span class="ph-color-646970 ph-font-size-16px ph-width-16px ph-height-16px ph-vertical-align-text-bottom dashicons dashicons-info"></span>
                                        <?php echo esc_html__( 'Not used in Legacy mode (Legacy mode only prepends plain text instructions before the script).', 'presshub-ai-editor' ); ?>
                                    </div>
                                    <?php $this->render_briefing_voice_pitch_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_tts_style"><?php echo __( 'Speaking Delivery Style (logosAI)', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_tts_style_field(); ?>
                                </td>
                            </tr>
                            <tr id="presshub-custom-tts-style-row" class="<?php echo ( get_option( 'presshub_ai_briefing_tts_style', 'formal' ) === 'custom' ) ? '' : ' presshub-is-hidden'; ?>">
                                <th scope="row"><label for="presshub_ai_briefing_tts_custom_style"><?php echo __( 'Custom Speaking Style Prompt', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_tts_custom_style_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_tts_turn_pause_ms"><?php echo __( 'Turn Pause Duration (ms)', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_tts_turn_pause_ms_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_tts_topic_pause_ms"><?php echo __( 'Topic Transition Pause (ms)', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_tts_topic_pause_ms_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_audio_split_by_topic"><?php echo __( 'Split Audio Synthesis by Topic', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_audio_split_by_topic_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_audio_intro_sfx"><?php echo __( 'Podcast Intro SFX', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_audio_intro_sfx_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_audio_transition_sfx"><?php echo __( 'Topic Transition SFX', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_audio_transition_sfx_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_audio_outro_sfx"><?php echo __( 'Podcast Outro SFX', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_audio_outro_sfx_field(); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <details class="presshub-group-advanced" id="briefing-voices-advanced">
                    <summary><?php echo esc_html__( 'Advanced: speech payload, throttling and retries', 'presshub-ai-editor' ); ?></summary>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr id="presshub-tts-payload-mode-row">
                                <th scope="row"><label for="presshub_ai_briefing_tts_payload_mode"><?php echo __( 'Gemini Speech Payload Structure', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_tts_payload_mode_field(); ?>
                                </td>
                            </tr>
                            <tr id="presshub-tts-system-instruction-row" class="<?php echo $sys_inactive ? 'presshub-setting-inactive' : ''; ?>">
                                <th scope="row"><label for="presshub_ai_briefing_tts_system_instruction"><?php echo __( 'Gemini Speech System Instruction', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <div class="presshub-inactive-mode-badge<?php echo $sys_inactive ? '' : ' presshub-is-hidden'; ?>">
                                        <span class="ph-color-646970 ph-font-size-16px ph-width-16px ph-height-16px ph-vertical-align-text-bottom dashicons dashicons-info"></span>
                                        <strong><?php echo esc_html__( 'Not used by Official Gemini 3.8 TTS REST Standard:', 'presshub-ai-editor' ); ?></strong>
                                        <?php echo esc_html__( 'Google\'s official Gemini 3.8 TTS specification controls tone, speed, and pitch directly via turn-level speech_metadata.style without top-level system instructions. To pass a top-level system instruction directive, select "Hybrid / System Instruction Override" above.', 'presshub-ai-editor' ); ?>
                                    </div>
                                    <?php $this->render_briefing_tts_system_instruction_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_tts_request_delay_ms"><?php echo __( 'Inter-Turn Throttle Delay (ms)', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_tts_request_delay_ms_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_tts_max_retries"><?php echo __( 'Speech API Max Retries (429/5xx)', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_tts_max_retries_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_tts_retry_backoff_ms"><?php echo __( 'Speech API Retry Backoff (ms)', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_tts_retry_backoff_ms_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_tts_requests_per_minute"><?php echo __( 'Speech API Requests per Minute', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_tts_requests_per_minute_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_tts_max_retry_wait_ms"><?php echo __( 'Speech API Max Retry Wait (ms)', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_tts_max_retry_wait_ms_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_tts_cron_retry_delay_min"><?php echo __( 'Scheduled Synthesis Retry Delay (min)', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_tts_cron_retry_delay_min_field(); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <?php $this->render_gemini_tts_tester(); ?>
                    </details>
                    </section>

                    <section id="briefing-publishing" class="presshub-settings-group" aria-labelledby="briefing-publishing-title">
                    <h3 id="briefing-publishing-title" class="presshub-settings-group-title"><?php echo esc_html__( 'Publishing', 'presshub-ai-editor' ); ?></h3>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_text_category"><?php echo __( 'Text Briefing Category', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_text_category_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_text_status"><?php echo __( 'Text Post Status', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_text_status_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_text_title_prefix"><?php echo __( 'Text Story Title Prefix', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_text_title_prefix_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_text_title_date_format"><?php echo __( 'Text Story Title Date Format', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_text_title_date_format_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_noon_text_title_prefix"><?php echo __( 'Noon Text Story Title Prefix', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_noon_text_title_prefix_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_podcast_category"><?php echo __( 'Podcast Category', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_podcast_category_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_podcast_status"><?php echo __( 'Podcast Post Status', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_podcast_status_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_podcast_show_notes_enabled"><?php echo __( 'Podcast show notes', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_switch_field( 'presshub_ai_podcast_show_notes_enabled', __( 'Add show notes under each podcast post', 'presshub-ai-editor' ), __( 'Chapter times, a link to the written brief and its sources. Default: on.', 'presshub-ai-editor' ) ); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_podcast_title_prefix"><?php echo __( 'Morning Podcast Title Prefix', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_podcast_title_prefix_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_noon_podcast_title_prefix"><?php echo __( 'Noon Podcast Title Prefix', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_noon_podcast_title_prefix_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_featured_image_ids_morning"><?php echo esc_html__( 'Morning Featured Image Pool', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_featured_image_ids_morning_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_featured_image_ids_noon"><?php echo esc_html__( 'Noon Featured Image Pool', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_featured_image_ids_noon_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_featured_image_source"><?php echo __( 'Text Briefing Featured Image Source', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_featured_image_source_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_podcast_featured_image_source"><?php echo __( 'Podcast Featured Image Source', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_podcast_featured_image_source_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_podcast_include_text"><?php echo __( 'Include Transcript in Podcast Post', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_podcast_include_text_field(); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    </section>
        <?php
    }

    public function render_section_editorial_knowledge() {
        $groups = PressHub_Settings_Sections::visible_groups( 'editorial-knowledge' );
        $this->group_start( 'knowledge-fiches', $groups['knowledge-fiches'] );
        $this->render_knowledge_tab();
        $this->group_end();

        $this->group_start( 'knowledge-memory', $groups['knowledge-memory'], __( 'A tiered memory of past coverage keeps story continuity across briefings and podcast episodes.', 'presshub-ai-editor' ) );
        $this->render_editorial_memory_section();
        $this->group_end();
    }

    public function render_section_social_publishing() {
        $social = $this->social_render();
        if ( ! $social ) {
            echo '<p>' . esc_html__( 'The PressHub Social module is not loaded.', 'presshub-ai-editor' ) . '</p>';
            return;
        }
        $groups = PressHub_Settings_Sections::visible_groups( 'social-publishing' );

        $this->group_start( 'platforms', $groups['platforms'] );
        $social->render_settings_sections( 'presshub_social_general' );
        $this->group_end();

        $this->group_start( 'post-copy', $groups['post-copy'] );
        $social->render_settings_sections( 'presshub_social_ai', [ 'presshub_social_ai_sec' ] );
        echo '<details class="presshub-settings-advanced" id="post-copy-advanced"><summary>' . esc_html__( 'Advanced: prompts and templates', 'presshub-ai-editor' ) . '</summary>';
        $social->render_settings_sections( 'presshub_social_prompts' );
        $social->render_prompts_reset_box();
        echo '</details>';
        $this->group_end();

        $this->group_start( 'images-video', $groups['images-video'] );
        $social->render_settings_sections( 'presshub_social_ai', [], [ 'presshub_social_ai_sec' ] );
        $this->group_end();

        $this->group_start( 'shorts', $groups['shorts'] );
        $social->render_shorts_readiness_notice();
        $social->render_settings_sections( 'presshub_social_shorts' );
        echo '<details class="presshub-settings-advanced presshub-shorts-prompts" id="presshub-shorts-prompts"><summary>' . esc_html__( 'Advanced: Shorts prompts (script, image, narration, music)', 'presshub-ai-editor' ) . '</summary>';
        $social->render_settings_sections( 'presshub_social_shorts_prompts' );
        echo '</details>';
        $this->group_end();

        $this->group_start( 'queue', $groups['queue'] );
        $social->render_settings_sections( 'presshub_social_queue' );
        $this->group_end();
    }

    public function render_section_social_listening() {
        $groups = PressHub_Settings_Sections::visible_groups( 'social-listening' );
        ?>
        <section id="social-listening-monitoring" class="presshub-settings-group" aria-labelledby="social-listening-monitoring-title">

                    <?php $this->render_monitored_accounts_list(); ?>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <?php $this->render_social_monitor_rows(); ?>
                        </tbody>
                    </table>
        </section>
        <section id="social-listening-trending" class="presshub-settings-group" aria-label="<?php echo esc_attr__( 'Trending in Cyprus', 'presshub-ai-editor' ); ?>">

                    <table class="form-table" role="presentation">
                        <tbody>
                            <?php $this->render_social_trending_rows(); ?>
                        </tbody>
                    </table>
        </section>
        <?php
    }

    public function render_section_site_system() {
        $groups = PressHub_Settings_Sections::visible_groups( 'site-system' );
        $this->group_start( 'link-previews', $groups['link-previews'] );
        $this->render_section_with_fields( 'presshub_ai_social', '' );
        $this->group_end();

        $this->group_start( 'logging-retention', $groups['logging-retention'] );
        $this->render_logging_section();
        $this->render_retention_table();
        ?>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label for="presshub_ai_log_level"><?php echo esc_html__( 'Diagnostic Log Level', 'presshub-ai-editor' ); ?></label></th>
                    <td><?php $this->render_log_level_field(); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Raw debug dump: prompts', 'presshub-ai-editor' ); ?></th>
                    <td><?php $this->render_debug_prompts_field(); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Raw debug dump: TTS payloads', 'presshub-ai-editor' ); ?></th>
                    <td><?php $this->render_log_tts_payloads_field(); ?></td>
                </tr>
            </tbody>
        </table>
        <?php
        $this->group_end();

        $this->group_start( 'rate-limits', $groups['rate-limits'] );
        $this->render_rate_limits_section();
        ?>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Enable Per-User Rate Limit', 'presshub-ai-editor' ); ?></th>
                    <td><?php $this->render_rate_limit_enabled_field(); ?></td>
                </tr>
                <tr>
                    <th scope="row"><label for="presshub_ai_rate_limit_per_hour"><?php echo esc_html__( 'Requests per Window', 'presshub-ai-editor' ); ?></label></th>
                    <td><?php $this->render_rate_limit_per_hour_field(); ?></td>
                </tr>
                <tr>
                    <th scope="row"><label for="presshub_ai_rate_limit_window_seconds"><?php echo esc_html__( 'Window Length (seconds)', 'presshub-ai-editor' ); ?></label></th>
                    <td><?php $this->render_rate_limit_window_seconds_field(); ?></td>
                </tr>
            </tbody>
        </table>
        <?php
        $this->group_end();

        $this->group_start( 'diagnostics', $groups['diagnostics'] );
        $this->render_diagnostics_section();
        ?>
        <h4><?php echo esc_html__( 'Diagnostic Inspector', 'presshub-ai-editor' ); ?></h4>
        <p><?php echo esc_html__( 'Inspect internal application logs, raw LLM prompt/response JSON lines, and TTS audio synthesis payloads with trace correlation.', 'presshub-ai-editor' ); ?></p>
                    
                    <div class="ph-display-flex ph-gap-8px ph-align-items-center ph-margin-bottom-10px ph-flex-wrap-wrap ph-background-f6f7f7 ph-padding-10px ph-border-radius-4px ph-border-1px-solid-dcdcde presshub-inspector-toolbar">
                        <div>
                            <label for="presshub-ai-log-target" class="ph-font-weight-600 ph-margin-right-4px"><?php esc_html_e( 'Target:', 'presshub-ai-editor' ); ?></label>
                            <select id="presshub-ai-log-target" class="presshub-no-dirty">
                                <option value="app"><?php esc_html_e( 'Application Log (app)', 'presshub-ai-editor' ); ?></option>
                                <option value="prompts"><?php esc_html_e( 'LLM Prompts & Responses (prompts)', 'presshub-ai-editor' ); ?></option>
                                <option value="tts"><?php esc_html_e( 'TTS Audio Payloads (tts)', 'presshub-ai-editor' ); ?></option>
                            </select>
                        </div>

                        <div class="ph-flex-grow-1 ph-min-width-200px">
                            <input type="text" id="presshub-ai-log-search" class="ph-width-100pct presshub-no-dirty regular-text" placeholder="<?php esc_attr_e( 'Filter by search term or Trace ID (e.g. tr_...)...', 'presshub-ai-editor' ); ?>" />
                        </div>

                        <div>
                            <select id="presshub-ai-log-lines" class="ph-width-90px presshub-no-dirty">
                                <option value="25"><?php esc_html_e( '25 lines', 'presshub-ai-editor' ); ?></option>
                                <option value="50" selected="selected"><?php esc_html_e( '50 lines', 'presshub-ai-editor' ); ?></option>
                                <option value="100"><?php esc_html_e( '100 lines', 'presshub-ai-editor' ); ?></option>
                                <option value="200"><?php esc_html_e( '200 lines', 'presshub-ai-editor' ); ?></option>
                            </select>
                        </div>

                        <div class="ph-display-flex ph-gap-6px ph-align-items-center">
                            <button type="button" id="presshub-ai-refresh-logs" class="button button-secondary">
                                <span class="ph-vertical-align-text-top ph-font-size-16px ph-width-16px ph-height-16px dashicons dashicons-update"></span>
                                <?php esc_html_e( 'Refresh', 'presshub-ai-editor' ); ?>
                            </button>
                            <button type="button" id="presshub-ai-download-log" class="button button-secondary">
                                <span class="ph-vertical-align-text-top ph-font-size-16px ph-width-16px ph-height-16px dashicons dashicons-download"></span>
                                <?php esc_html_e( 'Download', 'presshub-ai-editor' ); ?>
                            </button>
                            <select id="presshub-ai-download-target" class="ph-font-size-12px ph-height-30px presshub-no-dirty">
                                <option value="active"><?php esc_html_e( 'Active Target', 'presshub-ai-editor' ); ?></option>
                                <option value="app"><?php esc_html_e( 'Application Log (.log)', 'presshub-ai-editor' ); ?></option>
                                <option value="prompts"><?php esc_html_e( 'Prompts & Responses (.jsonl)', 'presshub-ai-editor' ); ?></option>
                                <option value="tts"><?php esc_html_e( 'TTS Payloads (.jsonl)', 'presshub-ai-editor' ); ?></option>
                                <option value="all"><?php esc_html_e( 'All 3 Logs (ZIP)', 'presshub-ai-editor' ); ?></option>
                            </select>
                            <button type="button" id="presshub-ai-clear-logs" class="ph-color-b32d2e button button-secondary">
                                <?php esc_html_e( 'Clear Log', 'presshub-ai-editor' ); ?>
                            </button>
                            <select id="presshub-ai-clear-target" class="ph-font-size-12px ph-height-30px presshub-no-dirty">
                                <option value="active"><?php esc_html_e( 'Active Target', 'presshub-ai-editor' ); ?></option>
                                <option value="all"><?php esc_html_e( 'All 3 Logs', 'presshub-ai-editor' ); ?></option>
                            </select>
                            <span id="presshub-ai-log-spinner" class="spinner" role="status"><span class="screen-reader-text"></span></span>
                        </div>
                    </div>

                    <div id="presshub-ai-log-meta-bar" class="ph-margin-bottom-8px ph-font-size-12px ph-color-50575e ph-display-flex ph-justify-content-space-between ph-align-items-center">
                        <span id="presshub-ai-log-status"><?php esc_html_e( 'Click "Refresh" to load diagnostic entries...', 'presshub-ai-editor' ); ?></span>
                        <span id="presshub-ai-log-file-info" class="ph-font-family-monospace"></span>
                    </div>

                    <textarea id="presshub-ai-log-viewer" rows="14" class="ph-font-size-12px ph-background-1e1e1e ph-color-d4d4d4 ph-font-family-monospace large-text code presshub-no-dirty" readonly="readonly" placeholder="<?php echo esc_attr__( 'Click "Refresh" to load diagnostic entries...', 'presshub-ai-editor' ); ?>"></textarea>
                    
                    <div id="presshub-ai-log-cards" class="ph-display-none ph-max-height-520px ph-overflow-y-auto ph-border-1px-solid-c3c4c7 ph-background-f0f0f1 ph-padding-10px ph-border-radius-4px presshub-log-cards-container presshub-no-dirty"></div>
        <?php
        $this->group_end();
    }

    /**
     * Site & system → Logging & retention: every retention period in one
     * table (#246), including editorial memory.
     */
    public function render_retention_table() {
        $rows = [
            'presshub_ai_research_retention_days'          => [ __( 'Research log', 'presshub-ai-editor' ), 'render_research_retention_days_field' ],
            'presshub_ai_generation_record_retention_days' => [ __( 'Generation records', 'presshub-ai-editor' ), 'render_generation_record_retention_days_field' ],
            'presshub_ai_log_file_retention_days'          => [ __( 'Log files', 'presshub-ai-editor' ), 'render_log_file_retention_days_field' ],
            'presshub_ai_token_log_retention_days'         => [ __( 'Token log', 'presshub-ai-editor' ), 'render_token_log_retention_days_field' ],
            'presshub_ai_audit_log_retention_days'         => [ __( 'Audit log', 'presshub-ai-editor' ), 'render_audit_log_retention_days_field' ],
            'presshub_ai_memory_retention_days'            => [ __( 'Editorial memory', 'presshub-ai-editor' ), 'render_memory_retention_days_field' ],
        ];
        ?>
        <table class="form-table presshub-retention-table" role="presentation" id="presshub-retention-table">
            <caption class="presshub-retention-caption"><?php echo esc_html__( 'How long PressHub keeps each kind of record (days)', 'presshub-ai-editor' ); ?></caption>
            <tbody>
                <?php foreach ( $rows as $option => $row ) : ?>
                    <tr>
                        <th scope="row"><label for="<?php echo self::esc_attr_safe( $option ); ?>"><?php echo self::esc_html_safe( $row[0] ); ?></label></th>
                        <td><?php $this->{$row[1]}(); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Prints the settings registry's help text for $key as the field's
     * description line (#260). Prints nothing when the key has no help.
     */
    public static function registry_help( string $key ): void {
        $entry = class_exists( 'PressHub_Settings_Registry' ) ? PressHub_Settings_Registry::get( $key ) : null;
        $help  = is_array( $entry ) ? (string) ( $entry['help'] ?? '' ) : '';
        if ( '' === $help ) {
            return;
        }
        echo '<p class="description">' . esc_html( $help ) . '</p>';
    }

    /**
     * Helper to render active configured providers as select options.
     */
    public static function get_active_providers_options( string $current_val = '', string $default_label = '' ): string {
        $providers = class_exists( 'PressHub_AI_Provider_Store' ) ? PressHub_AI_Provider_Store::get_all( true ) : [];
        $html = '';
        if ( '' !== $default_label ) {
            $selected = ( '' === $current_val ) ? ' selected="selected"' : '';
            $html .= '<option value=""' . $selected . '>' . esc_html( $default_label ) . '</option>';
        }
        $has_options = false;
        foreach ( $providers as $prov ) {
            if ( empty( $prov['enabled'] ) || ( empty( $prov['api_key'] ) && 'ollama_local' !== ( $prov['type'] ?? '' ) ) ) {
                continue;
            }
            $has_options = true;
            $id    = $prov['id'] ?? ( $prov['type'] ?? '' );
            $name  = $prov['name'] ?? ucfirst( $id );
            $model = $prov['default_model'] ?? '';
            $label = $name . ( $model ? ' (' . $model . ')' : '' ) . ( empty( $prov['enabled'] ) ? ' [' . __( 'Disabled', 'presshub-ai-editor' ) . ']' : '' );
            $selected = ( $current_val === $id ) ? ' selected="selected"' : '';
            $html .= '<option value="' . esc_attr( $id ) . '"' . $selected . '>' . esc_html( $label ) . '</option>';
        }
        if ( ! $has_options && '' === $default_label ) {
            $html .= '<option value="" selected="selected">' . esc_html__( '-- No Active Providers Configured --', 'presshub-ai-editor' ) . '</option>';
        }
        return $html;
    }

    /**
     * Helper to render active configured speech providers (Gemini) as select options.
     */
    public static function get_speech_providers_options( string $selected_id = '', string $default_label = '' ): string {
        $providers = class_exists( 'PressHub_AI_Provider_Store' ) ? PressHub_AI_Provider_Store::get_all( true ) : [];
        $html = '';
        if ( '' !== $default_label ) {
            $selected = ( '' === $selected_id ) ? ' selected="selected"' : '';
            $html .= '<option value=""' . $selected . '>' . esc_html( $default_label ) . '</option>';
        }
        $has_options = false;
        foreach ( $providers as $prov ) {
            if ( ( $prov['type'] ?? '' ) !== 'gemini' || empty( $prov['enabled'] ) || empty( $prov['api_key'] ) ) {
                continue;
            }
            $has_options = true;
            $id    = $prov['id'] ?? ( $prov['type'] ?? '' );
            $name  = $prov['name'] ?? ucfirst( $id );
            $model = $prov['default_model'] ?? '';
            $label = $name . ( $model ? ' (' . $model . ')' : '' );
            $selected = ( $selected_id === $id ) ? ' selected="selected"' : '';
            $html .= '<option value="' . esc_attr( $id ) . '"' . $selected . '>' . esc_html( $label ) . '</option>';
        }
        if ( ! $has_options && '' === $default_label ) {
            $html .= '<option value="" selected="selected">' . esc_html__( '-- No Gemini Provider Configured --', 'presshub-ai-editor' ) . '</option>';
        }
        return $html;
    }

    /**
     * Render the grid of provider cards.
     */
    public function render_providers_grid(): void {
        require_once __DIR__ . '/class-provider-store.php';
        require_once __DIR__ . '/class-provider-defaults.php';
        $providers = PressHub_AI_Provider_Store::get_all( true );
        ?>
        <div class="ph-display-flex ph-justify-content-space-between ph-align-items-center ph-margin-bottom-20px presshub-providers-header">
            <div>
                <h2><?php echo __( 'Configured AI Providers', 'presshub-ai-editor' ); ?></h2>
                <p><?php echo __( 'Manage standard AI engines (OpenAI, Anthropic, Gemini, Google Cloud TTS, Groq, Mistral, DeepSeek, Local Ollama) and custom OpenAI-compatible endpoints.', 'presshub-ai-editor' ); ?></p>
            </div>
            <button type="button" class="ph-display-inline-flex ph-align-items-center ph-gap-6px button button-primary presshub-add-provider-btn" id="presshub-add-provider-btn">
                <span class="ph-font-size-16px ph-width-16px ph-height-16px dashicons dashicons-plus-alt2"></span>
                <?php echo __( 'Add Provider', 'presshub-ai-editor' ); ?>
            </button>
        </div>

        <div class="presshub-providers-grid" id="presshub-providers-grid">
            <?php if ( empty( $providers ) ) : ?>
                <div class="ph-grid-column-1-1 ph-padding-30px ph-text-align-center ph-background-fff ph-border-1px-dashed-ccd0d4 ph-border-radius-6px presshub-no-providers">
                    <p><?php echo __( 'No AI providers configured yet. Click "Add Provider" above to create one.', 'presshub-ai-editor' ); ?></p>
                </div>
            <?php else : ?>
                <?php foreach ( $providers as $provider ) :
                    $id          = $provider['id'] ?? '';
                    $name        = $provider['name'] ?? ucfirst( $id );
                    $type        = $provider['type'] ?? 'openai';
                    $base_url    = $provider['base_url'] ?? '';
                    $model       = $provider['default_model'] ?? '';
                    $timeout     = $provider['timeout'] ?? 300;
                    $temp        = $provider['temperature'] ?? 0.7;
                    $max_tokens  = $provider['max_tokens'] ?? PressHub_AI_Provider_Defaults::default_max_tokens();
                    $enabled     = ! empty( $provider['enabled'] );
                    $is_system   = ! empty( $provider['is_system'] );
                    $has_key     = ! empty( $provider['api_key'] );
                ?>
                <div class="presshub-provider-card <?php echo $enabled ? 'is-enabled' : 'is-disabled'; ?>" data-provider-id="<?php echo esc_attr( $id ); ?>" data-provider-type="<?php echo esc_attr( $type ); ?>" data-provider-has-key="<?php echo $has_key ? '1' : '0'; ?>" data-provider-masked-key="<?php echo esc_attr( $has_key ? self::mask_key( $provider['api_key'] ) : '' ); ?>">
                    <div class="presshub-card-top">
                        <div class="presshub-card-title-wrap">
                            <span class="presshub-provider-badge presshub-badge-<?php echo esc_attr( function_exists( 'sanitize_html_class' ) ? sanitize_html_class( $type ) : self::sanitize_slug( $type ) ); ?>"><?php echo esc_html( strtoupper( $type ) ); ?></span>
                            <h3 class="presshub-card-name"><?php echo esc_html( $name ); ?></h3>
                        </div>
                        <div class="presshub-card-status">
                            <span class="presshub-status-pill <?php echo $enabled ? 'pill-active' : 'pill-inactive'; ?>">
                                <?php echo $enabled ? __( 'Active', 'presshub-ai-editor' ) : __( 'Disabled', 'presshub-ai-editor' ); ?>
                            </span>
                        </div>
                    </div>

                    <div class="presshub-card-body">
                        <div class="presshub-meta-row">
                            <span class="presshub-meta-label"><?php echo __( 'Default Model:', 'presshub-ai-editor' ); ?></span>
                            <span class="presshub-meta-val code"><strong><?php echo esc_html( $model ?: '-' ); ?></strong></span>
                        </div>
                        <?php if ( '' !== $base_url ) : ?>
                        <div class="presshub-meta-row">
                            <span class="presshub-meta-label"><?php echo __( 'Base URL:', 'presshub-ai-editor' ); ?></span>
                            <span class="presshub-meta-val code" title="<?php echo esc_attr( $base_url ); ?>"><?php echo esc_html( strlen( $base_url ) > 32 ? substr( $base_url, 0, 30 ) . '…' : $base_url ); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="presshub-meta-row">
                            <span class="presshub-meta-label"><?php echo __( 'Tuning:', 'presshub-ai-editor' ); ?></span>
                            <span class="presshub-meta-val"><?php echo sprintf( 'Temp: %s | Max Output: %s | %ss', esc_html( (string) $temp ), esc_html( function_exists( 'number_format_i18n' ) ? number_format_i18n( $max_tokens ) : number_format( $max_tokens ) ), esc_html( (string) $timeout ) ); ?></span>
                        </div>
                        <div class="presshub-meta-row">
                            <span class="presshub-meta-label"><?php echo __( 'Credentials:', 'presshub-ai-editor' ); ?></span>
                            <span class="presshub-meta-val"><?php echo $has_key ? '<span class="code">' . esc_html( self::mask_key( $provider['api_key'] ) ) . '</span>' : ( 'ollama_local' === $type ? esc_html__( 'Not required', 'presshub-ai-editor' ) : '<span class="ph-color-d63638 presshub-missing-key">' . esc_html__( 'No API Key set', 'presshub-ai-editor' ) . '</span>' ); ?></span>
                        </div>
                    </div>

                    <div class="presshub-card-footer">
                        <button type="button" class="button button-secondary presshub-test-provider-btn" data-provider-id="<?php echo esc_attr( $id ); ?>">
                            <span class="ph-vertical-align-middle ph-margin-top-2px dashicons dashicons-update"></span>
                            <?php echo __( 'Test Connection', 'presshub-ai-editor' ); ?>
                        </button>
                        <button type="button" class="button button-secondary presshub-edit-provider-btn" data-provider-id="<?php echo esc_attr( $id ); ?>">
                            <?php echo __( 'Edit', 'presshub-ai-editor' ); ?>
                        </button>
                        <?php if ( ! $is_system ) : ?>
                        <button type="button" class="button button-link-delete presshub-delete-provider-btn" data-provider-id="<?php echo esc_attr( $id ); ?>" data-provider-name="<?php echo esc_attr( $name ); ?>">
                            <?php echo __( 'Delete', 'presshub-ai-editor' ); ?>
                        </button>
                        <?php endif; ?>
                        <span class="spinner presshub-card-spinner" role="status"></span>
                    </div>
                    <div class="ph-display-none ph-margin-top-10px ph-font-size-12px presshub-card-test-result"></div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the Add/Edit Provider Modal Dialog.
     */
    public function render_provider_modal(): void {
        require_once __DIR__ . '/class-provider-defaults.php';
        $templates = PressHub_AI_Provider_Defaults::get_templates();
        ?>
        <div id="presshub-provider-modal" class="ph-display-none presshub-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="presshub-provider-modal-title">
            <div class="presshub-modal presshub-provider-modal-content">
                <div class="ph-display-flex ph-justify-content-space-between ph-align-items-center ph-padding-14px-20px ph-border-bottom-1px-solid-dcdcde presshub-modal-header">
                    <h2 id="presshub-provider-modal-title" class="ph-margin-0 ph-font-size-16px"><?php echo __( 'Add / Edit AI Provider', 'presshub-ai-editor' ); ?></h2>
                    <button type="button" class="ph-background-none ph-border-none ph-font-size-20px ph-cursor-pointer ph-color-666 presshub-modal-close" aria-label="<?php echo esc_attr__( 'Close', 'presshub-ai-editor' ); ?>">&times;</button>
                </div>
                <div class="ph-padding-20px ph-max-height-70vh ph-overflow-y-auto presshub-modal-body">
                    <div id="presshub-provider-discard-notice" class="ph-display-none ph-margin-bottom-15px ph-padding-10px-14px ph-background-fff8e5 ph-border-left-4px-solid-dba617 ph-border-radius-4px presshub-provider-discard-notice">
                        <div class="ph-display-flex ph-align-items-center ph-justify-content-space-between ph-gap-10px">
                            <span><strong><?php echo esc_html__( 'Unsaved changes:', 'presshub-ai-editor' ); ?></strong> <?php echo esc_html__( 'You have unsaved provider changes. Discard them?', 'presshub-ai-editor' ); ?></span>
                            <div class="ph-display-flex ph-gap-8px">
                                <button type="button" class="ph-color-b32d2e button button-link-delete presshub-provider-discard-confirm-btn"><?php echo esc_html__( 'Discard', 'presshub-ai-editor' ); ?></button>
                                <button type="button" class="button button-secondary presshub-provider-discard-cancel-btn"><?php echo esc_html__( 'Keep Editing', 'presshub-ai-editor' ); ?></button>
                            </div>
                        </div>
                    </div>
                    <form id="presshub-provider-form">
                        <input type="hidden" id="provider-form-id" name="id" value="" />

                        <div class="ph-margin-bottom-15px presshub-form-group">
                            <label for="provider-form-template"><strong><?php echo __( 'Provider Preset Template:', 'presshub-ai-editor' ); ?></strong></label>
                            <select id="provider-form-template" class="ph-margin-top-4px widefat">
                                <option value=""><?php echo __( '-- Select Template Preset (Auto-fills defaults) --', 'presshub-ai-editor' ); ?></option>
                                <?php foreach ( $templates as $tmpl_key => $tmpl ) : ?>
                                    <option value="<?php echo esc_attr( $tmpl_key ); ?>"><?php echo esc_html( $tmpl['name'] ); ?> (<?php echo esc_html( $tmpl_key ); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php echo __( 'Selecting a preset template automatically sets the base URL, default model and tuning parameters.', 'presshub-ai-editor' ); ?></p>
                        </div>

                        <div class="ph-display-flex ph-gap-15px ph-margin-bottom-15px presshub-form-row">
                            <div class="ph-flex-1 presshub-form-group">
                                <label for="provider-form-name"><strong><?php echo __( 'Display Name:', 'presshub-ai-editor' ); ?></strong></label>
                                <input type="text" id="provider-form-name" name="name" class="ph-margin-top-4px widefat" required placeholder="e.g. OpenAI Production" />
                            </div>
                            <div class="ph-flex-1 presshub-form-group">
                                <label for="provider-form-type"><strong><?php echo __( 'Provider Type:', 'presshub-ai-editor' ); ?></strong></label>
                                <select id="provider-form-type" name="type" class="ph-margin-top-4px widefat">
                                    <?php foreach ( $templates as $tmpl_key => $tmpl ) : ?>
                                        <option value="<?php echo esc_attr( $tmpl_key ); ?>"><?php echo esc_html( $tmpl['name'] ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="ph-margin-bottom-15px presshub-form-group">
                            <label for="provider-form-base-url"><strong><?php echo __( 'Base URL / Endpoint:', 'presshub-ai-editor' ); ?></strong></label>
                            <input type="url" id="provider-form-base-url" name="base_url" class="ph-margin-top-4px widefat code" placeholder="https://api.openai.com/v1" />
                        </div>

                        <div class="ph-margin-bottom-15px presshub-form-group">
                            <label for="provider-form-api-key"><strong><?php echo __( 'API Key / Secret Token:', 'presshub-ai-editor' ); ?></strong></label>
                            <input type="password" id="provider-form-api-key" name="api_key" class="ph-margin-top-4px widefat" autocomplete="new-password" placeholder="<?php echo esc_attr__( 'Enter API Key (or leave blank to preserve saved)', 'presshub-ai-editor' ); ?>" />
                            <p class="description"><?php echo __( 'Stored securely with autoload disabled. Leave blank when editing to keep current secret.', 'presshub-ai-editor' ); ?></p>
                        </div>

                        <div class="ph-margin-bottom-15px presshub-form-group">
                            <div class="ph-display-flex ph-justify-content-space-between ph-align-items-center ph-margin-bottom-4px">
                                <label for="provider-form-default-model"><strong><?php echo __( 'Model:', 'presshub-ai-editor' ); ?></strong></label>
                                <label class="ph-font-weight-normal ph-font-size-12px ph-cursor-pointer ph-display-inline-flex ph-align-items-center ph-gap-4px">
                                    <input type="checkbox" id="provider-form-toggle-manual" />
                                    <?php echo __( 'Enter model manually', 'presshub-ai-editor' ); ?>
                                </label>
                            </div>
                            <div id="provider-model-select-wrap">
                                <div class="ph-display-flex ph-gap-8px ph-align-items-center">
                                    <select id="provider-form-default-model" name="default_model" class="ph-flex-1 ph-margin-0 widefat code"></select>
                                    <button type="button" class="ph-display-inline-flex ph-align-items-center ph-gap-4px ph-white-space-nowrap button button-secondary" id="provider-form-fetch-models">
                                        <span class="ph-font-size-14px ph-width-14px ph-height-14px ph-margin-top-1px dashicons dashicons-update"></span>
                                        <?php echo __( 'Fetch Models', 'presshub-ai-editor' ); ?>
                                    </button>
                                    <span class="ph-float-none ph-margin-0 spinner" id="provider-form-fetch-spinner" role="status"></span>
                                </div>
                            </div>
                            <div id="provider-model-manual-wrap" class="ph-display-none">
                                <input type="text" id="provider-form-manual-model" class="ph-margin-top-0 widefat code" placeholder="<?php echo esc_attr__( 'e.g. gpt-4o, claude-3-5-sonnet-20241022, fine-tune-xyz', 'presshub-ai-editor' ); ?>" />
                            </div>
                            <input type="hidden" id="provider-form-available-models" name="available_models" value="" />
                            <p class="description"><?php echo __( 'Select the active model for this provider or fetch latest models directly via the provider API.', 'presshub-ai-editor' ); ?></p>
                        </div>

                        <div class="ph-display-flex ph-gap-15px ph-margin-bottom-15px presshub-form-row">
                            <div class="ph-flex-1 presshub-form-group">
                                <label for="provider-form-temperature"><strong><?php echo __( 'Temperature (0.0 - 2.0):', 'presshub-ai-editor' ); ?></strong></label>
                                <input type="number" id="provider-form-temperature" name="temperature" class="ph-margin-top-4px widefat" step="0.05" min="0" max="2" value="0.7" />
                            </div>
                            <div class="ph-flex-1 presshub-form-group">
                                <label for="provider-form-max-tokens"><strong><?php echo __( 'Max Output Tokens:', 'presshub-ai-editor' ); ?></strong></label>
                                <input type="number" id="provider-form-max-tokens" name="max_tokens" class="ph-margin-top-4px widefat" step="100" min="1" max="65536" value="20000" />
                                <p class="ph-margin-top-4px ph-font-size-11px description"><?php echo __( 'Maximum tokens generated in model responses (completion tokens). Does not limit input prompt tokens.', 'presshub-ai-editor' ); ?></p>
                            </div>
                            <div class="ph-flex-1 presshub-form-group">
                                <label for="provider-form-timeout"><strong><?php echo __( 'Timeout (seconds):', 'presshub-ai-editor' ); ?></strong></label>
                                <input type="number" id="provider-form-timeout" name="timeout" class="ph-margin-top-4px widefat" min="10" max="300" value="300" />
                            </div>
                        </div>

                        <div class="ph-margin-bottom-15px presshub-form-group">
                            <label for="provider-form-headers"><strong><?php echo __( 'Custom HTTP Headers (JSON optional):', 'presshub-ai-editor' ); ?></strong></label>
                            <textarea id="provider-form-headers" name="headers" class="ph-margin-top-4px widefat code" rows="2" placeholder='{"HTTP-Referer": "https://mysite.com"}'></textarea>
                        </div>

                        <div class="presshub-form-group">
                            <label>
                                <input type="checkbox" id="provider-form-enabled" name="enabled" value="1" checked="checked" />
                                <strong><?php echo __( 'Enable this provider for module assignment', 'presshub-ai-editor' ); ?></strong>
                            </label>
                        </div>
                    </form>
                    <div id="presshub-provider-form-notice" class="ph-margin-top-10px"></div>
                </div>
                <div class="ph-padding-12px-20px ph-border-top-1px-solid-dcdcde ph-display-flex ph-align-items-center ph-gap-8px presshub-modal-footer">
                    <button type="button" class="button button-secondary" id="presshub-provider-form-test">
                        <span class="ph-vertical-align-middle ph-margin-top-2px dashicons dashicons-update"></span>
                        <?php echo __( 'Test Connection', 'presshub-ai-editor' ); ?>
                    </button>
                    <div class="ph-margin-left-auto ph-display-flex ph-gap-8px">
                        <button type="button" class="button button-secondary presshub-modal-cancel"><?php echo __( 'Cancel', 'presshub-ai-editor' ); ?></button>
                        <button type="button" class="button button-primary" id="presshub-provider-form-save"><?php echo __( 'Save Provider', 'presshub-ai-editor' ); ?></button>
                    </div>
                    <span class="spinner" id="presshub-provider-form-spinner" role="status"></span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Token & Activity Usage Analytics Dashboard.
     */
    /**
     * Translated alignment labels, short (badges) or long (the source form).
     *
     * @return array<string,string>
     */
    public static function alignment_labels( bool $long = false ): array {
        return $long ? [
            'government'     => __( 'Government / pro-government', 'presshub-ai-editor' ),
            'opposition'     => __( 'Opposition-aligned', 'presshub-ai-editor' ),
            'independent'    => __( 'Independent', 'presshub-ai-editor' ),
            'institutional'  => __( 'Institutional (Parliament, Audit Office, courts)', 'presshub-ai-editor' ),
            'social_partner' => __( 'Social partner (unions, employers)', 'presshub-ai-editor' ),
        ] : [
            'government'     => __( 'Pro-government', 'presshub-ai-editor' ),
            'opposition'     => __( 'Opposition', 'presshub-ai-editor' ),
            'independent'    => __( 'Independent', 'presshub-ai-editor' ),
            'institutional'  => __( 'Institutional', 'presshub-ai-editor' ),
            'social_partner' => __( 'Social partner', 'presshub-ai-editor' ),
        ];
    }

    /** Small "Alignment: …" badge for the sources table ('' when not set). */
    public static function alignment_badge_html( string $alignment ): string {
        $alignment = PressHub_AI_Settings_Storage::sanitize_source_alignment( $alignment );
        if ( '' === $alignment ) {
            return '';
        }
        $labels = self::alignment_labels();
        return ' <span class="ph-font-size-11px ph-color-50575e ph-background-f0f0f1 ph-padding-2px-6px ph-border-radius-3px ph-display-inline-block presshub-source-alignment presshub-source-alignment-' . esc_attr( $alignment ) . '">'
            . esc_html( sprintf( /* translators: %s: alignment */ __( 'Alignment: %s', 'presshub-ai-editor' ), $labels[ $alignment ] ) ) . '</span>';
    }

    public function render_token_logs_tab(): void {
        require_once __DIR__ . '/class-token-logger.php';
        ?>
        <div class="presshub-token-dashboard">
            <h2><?php echo __( 'Token & Activity Analytics Dashboard', 'presshub-ai-editor' ); ?></h2>
            <p><?php echo __( 'Monitor live token usage, Text-to-Speech character volumes, news crawling metrics, API latencies, and success rates.', 'presshub-ai-editor' ); ?></p>

            <div class="presshub-token-kpis" id="presshub-token-kpis">
                <div class="presshub-kpi-card">
                    <span class="kpi-title"><?php echo __( 'Total Requests', 'presshub-ai-editor' ); ?></span>
                    <span class="kpi-value" id="kpi-total-requests">-</span>
                    <span class="kpi-subtitle"><?php echo __( 'API executions', 'presshub-ai-editor' ); ?></span>
                </div>
                <div class="presshub-kpi-card">
                    <span class="kpi-title"><?php echo __( 'Total Tokens', 'presshub-ai-editor' ); ?></span>
                    <span class="kpi-value" id="kpi-total-tokens">-</span>
                    <span class="kpi-subtitle"><?php echo __( 'Prompt & completions', 'presshub-ai-editor' ); ?></span>
                </div>
                <div class="presshub-kpi-card">
                    <span class="kpi-title"><?php echo __( 'TTS Characters', 'presshub-ai-editor' ); ?></span>
                    <span class="kpi-value" id="kpi-tts-chars">-</span>
                    <span class="kpi-subtitle"><?php echo __( 'Voice audio synthesized', 'presshub-ai-editor' ); ?></span>
                </div>
                <div class="presshub-kpi-card">
                    <span class="kpi-title"><?php echo __( 'Scraped Articles', 'presshub-ai-editor' ); ?></span>
                    <span class="kpi-value" id="kpi-scraped-articles">-</span>
                    <span class="kpi-subtitle"><?php echo __( 'Harvested from sources', 'presshub-ai-editor' ); ?></span>
                </div>
                <div class="presshub-kpi-card">
                    <span class="kpi-title"><?php echo __( 'Success Rate', 'presshub-ai-editor' ); ?></span>
                    <span class="kpi-value" id="kpi-success-rate">100%</span>
                    <span class="kpi-subtitle"><?php echo __( 'Error-free runs', 'presshub-ai-editor' ); ?></span>
                </div>
            </div>

            <div class="presshub-token-filters">
                <div class="filter-group">
                    <label for="token-filter-range"><?php echo __( 'Date Range:', 'presshub-ai-editor' ); ?></label>
                    <select id="token-filter-range">
                        <option value="today"><?php echo __( 'Today', 'presshub-ai-editor' ); ?></option>
                        <option value="7d"><?php echo __( 'Last 7 Days', 'presshub-ai-editor' ); ?></option>
                        <option value="30d" selected="selected"><?php echo __( 'Last 30 Days', 'presshub-ai-editor' ); ?></option>
                        <option value="90d"><?php echo __( 'Last 90 Days', 'presshub-ai-editor' ); ?></option>
                        <option value="all"><?php echo __( 'All Time', 'presshub-ai-editor' ); ?></option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="token-filter-action"><?php echo __( 'Action:', 'presshub-ai-editor' ); ?></label>
                    <select id="token-filter-action">
                        <option value=""><?php echo __( 'All Actions', 'presshub-ai-editor' ); ?></option>
                        <option value="coauthor_draft"><?php echo __( 'Co-Author Draft', 'presshub-ai-editor' ); ?></option>
                        <option value="coauthor_scorecard"><?php echo __( 'Editorial Scorecard', 'presshub-ai-editor' ); ?></option>
                        <option value="copilot_chat"><?php echo __( 'Copilot Chat', 'presshub-ai-editor' ); ?></option>
                        <option value="copilot_research"><?php echo __( 'Copilot Research', 'presshub-ai-editor' ); ?></option>
                        <option value="briefing_curation"><?php echo __( 'Briefing Curation', 'presshub-ai-editor' ); ?></option>
                        <option value="podcast_script"><?php echo __( 'Podcast Script', 'presshub-ai-editor' ); ?></option>
                        <option value="podcast_audio"><?php echo __( 'Podcast Audio Synthesis', 'presshub-ai-editor' ); ?></option>
                        <option value="scrape_harvest"><?php echo __( 'News Harvester', 'presshub-ai-editor' ); ?></option>
                        <option value="custom_test"><?php echo __( 'Test Connection', 'presshub-ai-editor' ); ?></option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="token-filter-provider"><?php echo __( 'Provider:', 'presshub-ai-editor' ); ?></label>
                    <select id="token-filter-provider">
                        <option value=""><?php echo __( 'All Providers', 'presshub-ai-editor' ); ?></option>
                        <option value="openai">OpenAI</option>
                        <option value="anthropic">Anthropic</option>
                        <option value="gemini">Gemini</option>
                        <option value="google_cloud_tts">Google Cloud TTS</option>
                        <option value="groq">Groq</option>
                        <option value="mistral">Mistral</option>
                        <option value="deepseek">DeepSeek</option>
                        <option value="ollama_local">Ollama / Local</option>
                        <option value="scraper">Web Scraper</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="token-filter-status"><?php echo __( 'Status:', 'presshub-ai-editor' ); ?></label>
                    <select id="token-filter-status">
                        <option value=""><?php echo __( 'All Statuses', 'presshub-ai-editor' ); ?></option>
                        <option value="success"><?php echo __( 'Success', 'presshub-ai-editor' ); ?></option>
                        <option value="error"><?php echo __( 'Error', 'presshub-ai-editor' ); ?></option>
                    </select>
                </div>
                <div class="ph-flex-1 filter-group filter-search">
                    <label for="token-filter-search"><?php echo __( 'Search:', 'presshub-ai-editor' ); ?></label>
                    <input type="text" id="token-filter-search" placeholder="<?php echo esc_attr__( 'Search model, action, metadata...', 'presshub-ai-editor' ); ?>" />
                </div>
                <div class="ph-display-flex ph-gap-6px ph-align-items-flex-end filter-actions">
                    <button type="button" class="button button-secondary" id="token-filter-refresh"><?php echo __( 'Filter', 'presshub-ai-editor' ); ?></button>
                    <button type="button" class="button button-secondary" id="token-export-csv"><?php echo __( 'Export CSV', 'presshub-ai-editor' ); ?></button>
                    <button type="button" class="button button-link-delete" id="token-clear-logs"><?php echo __( 'Clear Logs', 'presshub-ai-editor' ); ?></button>
                    <span class="spinner" id="token-logs-spinner" role="status"></span>
                </div>
            </div>

            <div class="ph-margin-top-15px presshub-table-responsive">
                <table class="wp-list-table widefat fixed striped presshub-token-table">
                    <thead>
                        <tr>
                            <th class="ph-width-140px"><?php echo __( 'Date / Time', 'presshub-ai-editor' ); ?></th>
                            <th class="ph-width-130px"><?php echo __( 'Action', 'presshub-ai-editor' ); ?></th>
                            <th class="ph-width-110px"><?php echo __( 'Provider', 'presshub-ai-editor' ); ?></th>
                            <th class="ph-width-140px"><?php echo __( 'Model', 'presshub-ai-editor' ); ?></th>
                            <th><?php echo __( 'Tokens / Metrics', 'presshub-ai-editor' ); ?></th>
                            <th class="ph-width-90px"><?php echo __( 'Latency', 'presshub-ai-editor' ); ?></th>
                            <th class="ph-width-90px"><?php echo __( 'Status', 'presshub-ai-editor' ); ?></th>
                            <th class="ph-width-80px"><?php echo __( 'Details', 'presshub-ai-editor' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="presshub-token-logs-tbody">
                        <tr><td colspan="8" class="ph-text-align-center ph-padding-20px"><?php echo __( 'Loading token usage logs...', 'presshub-ai-editor' ); ?></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="ph-display-flex ph-justify-content-space-between ph-align-items-center ph-margin-top-12px presshub-pagination-wrap" id="presshub-token-pagination">
                <span class="pagination-info" id="token-pagination-info"><?php echo __( 'Showing 0 items', 'presshub-ai-editor' ); ?></span>
                <div class="ph-display-flex ph-gap-8px pagination-buttons">
                    <button type="button" class="button button-secondary" id="token-page-prev" disabled="disabled">&laquo; <?php echo __( 'Previous', 'presshub-ai-editor' ); ?></button>
                    <span id="token-page-current" class="ph-display-flex ph-align-items-center ph-font-weight-600">1 / 1</span>
                    <button type="button" class="button button-secondary" id="token-page-next" disabled="disabled"><?php echo __( 'Next', 'presshub-ai-editor' ); ?> &raquo;</button>
                </div>
            </div>

            <?php $this->render_audit_trail_section(); ?>
        </div>
        <?php
        $this->render_log_details_modal();
    }

    /**
     * Render the Configuration & Provider Audit Trail section.
     */
    public function render_audit_trail_section(): void {
        ?>
        <hr class="ph-margin-35px-0-25px ph-border-0 ph-border-top-1px-solid-ccd0d4">

        <div class="presshub-audit-dashboard" id="presshub-audit-dashboard">
            <h2><?php echo __( 'Configuration & Provider Audit Trail', 'presshub-ai-editor' ); ?></h2>
            <p><?php echo __( 'Track and audit mutations to AI providers, news sources, and settings with actor details, timestamps, and sanitized changes.', 'presshub-ai-editor' ); ?></p>

            <div class="ph-display-flex ph-gap-12px ph-flex-wrap-wrap ph-align-items-flex-end ph-margin-bottom-15px presshub-token-filters presshub-audit-filters">
                <div class="filter-group">
                    <label for="audit-filter-event"><?php echo __( 'Event Type:', 'presshub-ai-editor' ); ?></label>
                    <select id="audit-filter-event">
                        <option value=""><?php echo __( 'All Events', 'presshub-ai-editor' ); ?></option>
                        <option value="settings_saved"><?php echo __( 'Settings Saved', 'presshub-ai-editor' ); ?></option>
                        <option value="provider_added"><?php echo __( 'Provider Added', 'presshub-ai-editor' ); ?></option>
                        <option value="provider_updated"><?php echo __( 'Provider Updated', 'presshub-ai-editor' ); ?></option>
                        <option value="provider_deleted"><?php echo __( 'Provider Deleted', 'presshub-ai-editor' ); ?></option>
                        <option value="provider_toggled"><?php echo __( 'Provider Toggled', 'presshub-ai-editor' ); ?></option>
                        <option value="news_source_added"><?php echo __( 'News Source Added', 'presshub-ai-editor' ); ?></option>
                        <option value="news_source_updated"><?php echo __( 'News Source Updated', 'presshub-ai-editor' ); ?></option>
                        <option value="news_source_deleted"><?php echo __( 'News Source Deleted', 'presshub-ai-editor' ); ?></option>
                        <option value="news_source_toggled"><?php echo __( 'News Source Toggled', 'presshub-ai-editor' ); ?></option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="audit-filter-entity"><?php echo __( 'Entity Type:', 'presshub-ai-editor' ); ?></label>
                    <select id="audit-filter-entity">
                        <option value=""><?php echo __( 'All Entities', 'presshub-ai-editor' ); ?></option>
                        <option value="provider"><?php echo __( 'AI Provider', 'presshub-ai-editor' ); ?></option>
                        <option value="settings"><?php echo __( 'Settings', 'presshub-ai-editor' ); ?></option>
                        <option value="news_source"><?php echo __( 'News Source', 'presshub-ai-editor' ); ?></option>
                    </select>
                </div>
                <div class="ph-flex-1 ph-min-width-200px filter-group filter-search">
                    <label for="audit-filter-search"><?php echo __( 'Search:', 'presshub-ai-editor' ); ?></label>
                    <input type="text" id="audit-filter-search" placeholder="<?php echo esc_attr__( 'Search actor, entity, details...', 'presshub-ai-editor' ); ?>" />
                </div>
                <div class="ph-display-flex ph-gap-6px ph-align-items-flex-end filter-actions">
                    <button type="button" class="button button-secondary" id="audit-filter-refresh"><?php echo __( 'Filter', 'presshub-ai-editor' ); ?></button>
                    <button type="button" class="button button-secondary" id="audit-export-csv"><?php echo __( 'Export CSV', 'presshub-ai-editor' ); ?></button>
                    <button type="button" class="button button-link-delete" id="audit-clear-logs"><?php echo __( 'Clear Audit Logs', 'presshub-ai-editor' ); ?></button>
                    <span class="spinner" id="audit-logs-spinner" role="status"></span>
                </div>
            </div>

            <div class="ph-margin-top-15px presshub-table-responsive">
                <table class="wp-list-table widefat fixed striped presshub-audit-table">
                    <thead>
                        <tr>
                            <th class="ph-width-140px"><?php echo __( 'Date / Time', 'presshub-ai-editor' ); ?></th>
                            <th class="ph-width-120px"><?php echo __( 'Actor', 'presshub-ai-editor' ); ?></th>
                            <th class="ph-width-150px"><?php echo __( 'Event Type', 'presshub-ai-editor' ); ?></th>
                            <th class="ph-width-110px"><?php echo __( 'Entity Type', 'presshub-ai-editor' ); ?></th>
                            <th class="ph-width-140px"><?php echo __( 'Entity ID', 'presshub-ai-editor' ); ?></th>
                            <th><?php echo __( 'Changes & Details Summary', 'presshub-ai-editor' ); ?></th>
                            <th class="ph-width-110px"><?php echo __( 'IP Address', 'presshub-ai-editor' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="presshub-audit-logs-tbody">
                        <tr><td colspan="7" class="ph-text-align-center ph-padding-20px"><?php echo __( 'Loading configuration audit logs...', 'presshub-ai-editor' ); ?></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="ph-display-flex ph-justify-content-space-between ph-align-items-center ph-margin-top-12px presshub-pagination-wrap" id="presshub-audit-pagination">
                <span class="pagination-info" id="audit-pagination-info"><?php echo __( 'Showing 0 items', 'presshub-ai-editor' ); ?></span>
                <div class="ph-display-flex ph-gap-8px pagination-buttons">
                    <button type="button" class="button button-secondary" id="audit-page-prev" disabled="disabled">&laquo; <?php echo __( 'Previous', 'presshub-ai-editor' ); ?></button>
                    <span id="audit-page-current" class="ph-display-flex ph-align-items-center ph-font-weight-600">1 / 1</span>
                    <button type="button" class="button button-secondary" id="audit-page-next" disabled="disabled"><?php echo __( 'Next', 'presshub-ai-editor' ); ?> &raquo;</button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Alias for render_token_logs_tab.
     */
    public function render_token_usage_tab(): void {
        $this->render_token_logs_tab();
    }

    /**
     * Render the Request Log & Error Details modal.
     */
    public function render_log_details_modal(): void {
        ?>
        <div id="presshub-log-details-modal" class="ph-display-none presshub-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="presshub-log-details-modal-title">
            <div class="ph-max-width-650px ph-width-95pct presshub-modal">
                <div class="ph-display-flex ph-justify-content-space-between ph-align-items-center ph-padding-14px-20px ph-border-bottom-1px-solid-dcdcde presshub-modal-header">
                    <h2 id="presshub-log-details-modal-title" class="ph-margin-0 ph-font-size-16px"><?php echo __( 'Request Log & Error Details', 'presshub-ai-editor' ); ?></h2>
                    <button type="button" class="ph-background-none ph-border-none ph-font-size-20px ph-cursor-pointer ph-color-666 presshub-modal-close" aria-label="<?php echo esc_attr__( 'Close', 'presshub-ai-editor' ); ?>">&times;</button>
                </div>
                <div class="ph-padding-20px ph-max-height-70vh ph-overflow-y-auto presshub-modal-body">
                    <div class="ph-display-grid ph-grid-template-columns-1fr-1fr ph-gap-12px ph-margin-bottom-16px ph-font-size-13px presshub-log-info-grid">
                        <div>
                            <strong><?php echo __( 'Timestamp:', 'presshub-ai-editor' ); ?></strong>
                            <div id="log-detail-timestamp" class="ph-color-555 ph-margin-top-2px">-</div>
                        </div>
                        <div>
                            <strong><?php echo __( 'Status:', 'presshub-ai-editor' ); ?></strong>
                            <div id="log-detail-status" class="ph-margin-top-2px">-</div>
                        </div>
                        <div>
                            <strong><?php echo __( 'Action:', 'presshub-ai-editor' ); ?></strong>
                            <div id="log-detail-action" class="ph-color-555 ph-margin-top-2px">-</div>
                        </div>
                        <div>
                            <strong><?php echo __( 'Provider:', 'presshub-ai-editor' ); ?></strong>
                            <div id="log-detail-provider" class="ph-color-555 ph-margin-top-2px">-</div>
                        </div>
                        <div>
                            <strong><?php echo __( 'Model:', 'presshub-ai-editor' ); ?></strong>
                            <div id="log-detail-model" class="ph-color-555 ph-margin-top-2px"><code class="ph-font-size-12px">-</code></div>
                        </div>
                        <div>
                            <strong><?php echo __( 'Duration:', 'presshub-ai-editor' ); ?></strong>
                            <div id="log-detail-duration" class="ph-color-555 ph-margin-top-2px">-</div>
                        </div>
                        <div class="ph-grid-column-span-2">
                            <strong><?php echo __( 'Tokens / Metrics:', 'presshub-ai-editor' ); ?></strong>
                            <div id="log-detail-metrics" class="ph-color-555 ph-margin-top-2px">-</div>
                        </div>
                    </div>

                    <div id="presshub-log-error-container" class="ph-display-none ph-margin-bottom-16px">
                        <div class="ph-display-flex ph-justify-content-space-between ph-align-items-center ph-margin-bottom-6px">
                            <strong class="ph-color-d63638"><?php echo __( 'Error Message / Diagnostics:', 'presshub-ai-editor' ); ?></strong>
                            <button type="button" class="ph-display-inline-flex ph-align-items-center ph-gap-4px button button-small" id="presshub-copy-log-error">
                                <span class="ph-font-size-14px ph-width-14px ph-height-14px dashicons dashicons-clipboard"></span>
                                <span class="copy-btn-text"><?php echo __( 'Copy Error', 'presshub-ai-editor' ); ?></span>
                            </button>
                        </div>
                        <pre id="log-detail-error" class="ph-background-fcf0f1 ph-border-1px-solid-f5c6cb ph-color-721c24 ph-padding-10px ph-border-radius-4px ph-font-size-12px ph-white-space-pre-wrap ph-word-break-break-word ph-max-height-180px ph-overflow-y-auto ph-margin-0"></pre>
                    </div>

                    <div id="presshub-log-metadata-container" class="ph-display-none ph-margin-bottom-10px">
                        <strong><?php echo __( 'Additional Metadata:', 'presshub-ai-editor' ); ?></strong>
                        <pre id="log-detail-metadata" class="ph-background-f6f7f7 ph-border-1px-solid-dcdcde ph-color-333 ph-padding-10px ph-border-radius-4px ph-font-size-11px ph-white-space-pre-wrap ph-word-break-break-word ph-max-height-180px ph-overflow-y-auto ph-margin-top-6px"></pre>
                    </div>
                </div>
                <div class="ph-padding-12px-20px ph-border-top-1px-solid-dcdcde ph-display-flex ph-justify-content-flex-end presshub-modal-footer">
                    <button type="button" class="button button-secondary presshub-modal-close"><?php echo __( 'Close', 'presshub-ai-editor' ); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * P1/P9: add a help tab to the settings screen. Uses the WP_Screen
     * method when available (real WP); falls back to the global
     * add_help_tab() shim in the unit-test harness.
     */
    private function register_help_tab(): void {
        $args = [
            'id'      => 'presshub-ai-help',
            'title'   => __( 'PressHub AI Help', 'presshub-ai-editor' ),
            'content' => '<p>' . __( 'PressHub AI settings: choose a provider under General, then configure the per-provider model and tuning under Providers. Keys are shown masked — only the last 4 characters are displayed; leave a key field empty to keep the saved key. Media (Google Cloud) configures Imagen/TTS. Rate Limits caps per-user AI costs.', 'presshub-ai-editor' ) . '</p>',
        ];
        if ( function_exists( 'get_current_screen' ) ) {
            $screen = get_current_screen();
            if ( $screen && is_object( $screen ) && method_exists( $screen, 'add_help_tab' ) ) {
                $screen->add_help_tab( $args );
                return;
            }
        }
        if ( function_exists( 'add_help_tab' ) ) {
            add_help_tab( $args );
        }
    }

    // ------------------------------------------------------------------
    // Section descriptions & renderers.
    // ------------------------------------------------------------------

    public function render_section_with_fields( string $section_id, string $title ) {
        if ( '' !== $title ) {
            echo '<h2 id="wp-settings-section-' . esc_attr( $section_id ) . '">' . esc_html( $title ) . '</h2>';
        }
        $callback_suffix = str_replace( 'presshub_ai_', '', $section_id );
        $callback = [ $this, 'render_' . $callback_suffix . '_section' ];
        if ( is_callable( $callback ) ) {
            call_user_func( $callback );
        }
        echo '<table class="form-table" role="presentation"><tbody>';
        if ( function_exists( 'do_settings_fields' ) ) {
            do_settings_fields( 'presshub-ai', $section_id );
        }
        echo '</tbody></table>';
    }

    public function render_general_section() {
        echo '<p>' . __( 'Choose which AI provider powers drafts, scorecards, chat and research.', 'presshub-ai-editor' ) . '</p>';
    }

    public function render_providers_section() {
        echo '<p>' . __( 'Each provider keeps its own model and tuning so switching providers never reuses another provider\'s model name.', 'presshub-ai-editor' ) . '</p>';
    }

    public function render_github_section() {
        echo '<p>' . __( 'Configure GitHub Personal Access Token for private repository plugin updates and release tracking.', 'presshub-ai-editor' ) . '</p>';
    }

    public function render_media_section() {
        echo '<p>' . __( 'Google Cloud credentials used for Imagen image generation and Text-to-Speech.', 'presshub-ai-editor' ) . '</p>';
    }

    public function render_rate_limits_section() {
        echo '<p>' . __( 'Cap the AI endpoints so a single user cannot rack up unbounded API costs. Disabled by default — opt-in only.', 'presshub-ai-editor' ) . '</p>';
    }

    public function render_logging_section() {
        echo '<p>' . esc_html__( 'Configure diagnostic logging verbosity, prompt/response exchange recording, speech synthesis payload captures and how long logs are kept. Logs live in a protected folder (wp-content/uploads/presshub-ai/ by default) with random file names, and can be reviewed or downloaded in the Diagnostic Inspector below. To keep them outside the web root, define PRESSHUB_AI_LOG_DIR in wp-config.php.', 'presshub-ai-editor' ) . '</p>';
        if ( class_exists( 'PressHub_AI_Log_Exposure_Check' ) ) {
            PressHub_AI_Log_Exposure_Check::render_panel();
        }
    }

    public function render_social_section() {
        echo '<p>' . esc_html__( 'Controls automatic OpenGraph (Facebook, LinkedIn, Discord), Twitter Cards (X), meta description tags, and title length optimization (<= 60 chars) to prevent broken cards and truncated snippets on social media.', 'presshub-ai-editor' ) . '</p>';
    }

    // ------------------------------------------------------------------
    // Field renderers.
    // ------------------------------------------------------------------

    public function render_provider_field() {
        $provider = (string) get_option( 'presshub_ai_provider', 'openai' );
        ?>
        <select name="presshub_ai_provider" id="presshub_ai_provider">
            <option value="openai" <?php echo 'openai' === $provider ? 'selected="selected"' : ''; ?>><?php echo __( 'OpenAI', 'presshub-ai-editor' ); ?></option>
            <option value="anthropic" <?php echo 'anthropic' === $provider ? 'selected="selected"' : ''; ?>><?php echo __( 'Anthropic', 'presshub-ai-editor' ); ?></option>
            <option value="gemini" <?php echo 'gemini' === $provider ? 'selected="selected"' : ''; ?>><?php echo __( 'Google Gemini', 'presshub-ai-editor' ); ?></option>
        </select>
        <?php
    }

    /**
     * 1.2.4: fetch source URLs server-side before prompting. Models cannot
     * browse URLs, so each source URL in the draft request is fetched and
     * its article text is injected into the prompt (class-url-fetcher.php).
     */
    public function render_fetch_urls_field() {
        $enabled = get_option( 'presshub_ai_fetch_urls', '1' ) === '1';
        ?>
        <label for="presshub_ai_fetch_urls">
            <input type="checkbox" name="presshub_ai_fetch_urls" id="presshub_ai_fetch_urls" value="1" <?php echo $enabled ? 'checked="checked"' : ''; ?> />
            <?php echo esc_html__( 'Fetch and extract source URLs server-side before prompting (recommended — models cannot browse web pages).', 'presshub-ai-editor' ); ?>
        </label>
        <?php
    }

    /**
     * 1.2.5: prompt inspection toggle ("Raw debug dump: prompts" since #233, "Raw debug dump (developer use)" since #140; generation
     * records already keep this information for editors). Writes the exact SYSTEM and USER
     * prompts to the protected log folder (prompts channel) in JSONL format.
     */
    public function render_debug_prompts_field() {
        $option  = 'presshub_ai_debug_prompts';
        $enabled = PressHub_AI_Settings_Storage::get_debug_prompts();
        ?>
        <input type="hidden" name="<?php echo self::esc_attr_safe( $option ); ?>" value="0" />
        <label for="presshub_ai_debug_prompts">
            <input type="checkbox" name="<?php echo self::esc_attr_safe( $option ); ?>" id="presshub_ai_debug_prompts" value="1" <?php checked( true, $enabled ); ?> />
            <?php echo esc_html__( 'Append every SYSTEM and USER prompt exchange to the protected log folder (prompts channel) as structured JSONL.', 'presshub-ai-editor' ); ?>
        </label>
        <p class="description">
            <?php echo esc_html__( 'Raw debug dump for developers. Generation records already keep every prompt, response, setting and usage figure for editors, so leave this off unless you need the raw file. Captures complete system instructions, user inputs, model configuration, response completions, and trace IDs. Disable in production to conserve storage.', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    /**
     * P4: render a masked key input. The raw key is never placed in the
     * DOM; only the last 4 characters are shown (in both the value and the
     * placeholder). The sanitize callback maps the masked/empty value back
     * to the saved key, so saving the form without touching the field keeps
     * the existing key.
     */
    public function render_api_key_field() {
        $saved = (string) get_option( 'presshub_ai_api_key', '' );
        $mask  = self::mask_key( $saved );
        ?>
        <input type="password" name="presshub_ai_api_key" id="presshub_ai_api_key" value="<?php echo self::esc_attr_safe( $mask ); ?>" placeholder="<?php echo self::esc_attr_safe( $mask ); ?>" class="regular-text" autocomplete="off" />
        <?php if ( '' !== $mask ) : ?>
            <p class="description"><?php echo __( 'Saved key ends in', 'presshub-ai-editor' ); ?> <code><?php echo self::esc_html_safe( $mask ); ?></code>. <?php echo __( 'Leave empty to keep it.', 'presshub-ai-editor' ); ?></p>
            <label>
                <input type="checkbox" name="presshub_ai_remove_api_key" id="presshub_ai_remove_api_key" value="1" />
                <?php echo __( 'Remove stored key', 'presshub-ai-editor' ); ?>
            </label>
        <?php else : ?>
            <p class="description"><?php echo __( 'Used for the active AI provider (OpenAI, Anthropic or Gemini).', 'presshub-ai-editor' ); ?></p>
        <?php endif; ?>
        <?php
    }

    public function render_model_field( $args = [] ) {
        $provider = $args['provider'] ?? 'openai';
        $option   = 'presshub_ai_model_' . $provider;
        $value    = (string) get_option( $option, PressHub_AI_Settings_Migration::default_model( $provider ) );
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" />
        <p class="description"><?php echo __( 'Default:', 'presshub-ai-editor' ); ?> <code><?php echo self::esc_html_safe( PressHub_AI_Settings_Migration::default_model( $provider ) ); ?></code></p>
        <?php
    }

    public function render_temperature_field( $args = [] ) {
        $provider = $args['provider'] ?? 'openai';
        $option   = 'presshub_ai_temperature_' . $provider;
        $value    = get_option( $option, PressHub_AI_Settings_Migration::default_temperature() );
        ?>
        <input type="number" min="0" max="2" step="0.1" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" />
        <p class="description"><?php echo __( 'Sampling temperature (0-2). Default 0.7. Intent classification and audio scripts are locked to 0.0 for determinism.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_max_tokens_field( $args = [] ) {
        $provider = $args['provider'] ?? 'openai';
        $option   = 'presshub_ai_max_tokens_' . $provider;
        $value    = (int) get_option( $option, PressHub_AI_Settings_Migration::default_max_tokens() );
        ?>
        <input type="number" min="1" max="65536" step="1" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" />
        <p class="description"><?php echo __( 'Maximum output tokens per response (1-65536). Default 20000.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_timeout_field( $args = [] ) {
        $provider = $args['provider'] ?? 'openai';
        $option   = 'presshub_ai_timeout_' . $provider;
        $value    = (int) get_option( $option, PressHub_AI_Settings_Migration::default_timeout( $provider ) );
        ?>
        <input type="number" min="10" max="300" step="1" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" />
        <p class="description"><?php echo __( 'Request timeout in seconds (10-300). Default', 'presshub-ai-editor' ); ?> <?php echo (int) PressHub_AI_Settings_Migration::default_timeout( $provider ); ?>.</p>
        <?php
    }

    public function render_openai_org_field() {
        $option = 'presshub_ai_openai_org';
        $value  = (string) get_option( $option, '' );
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" />
        <p class="description"><?php echo __( 'Optional. Sent as the OpenAI-Organization header. Only needed for multi-org accounts.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_anthropic_version_field() {
        $option = 'presshub_ai_anthropic_version';
        $value  = (string) get_option( $option, '2023-06-01' );
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" />
        <p class="description"><?php echo __( 'anthropic-version header. Default 2023-06-01.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_github_token_field() {
        $saved = (string) get_option( 'presshub_ai_github_token', '' );
        $mask  = self::mask_key( $saved );
        ?>
        <input type="password" name="presshub_ai_github_token" id="presshub_ai_github_token" value="<?php echo self::esc_attr_safe( $mask ); ?>" placeholder="<?php echo self::esc_attr_safe( $mask ); ?>" class="regular-text" autocomplete="off" />
        <p class="description"><?php echo __( 'GitHub Personal Access Token (PAT). Only required if the GitHub repository is private to enable automatic updates.', 'presshub-ai-editor' ); ?></p>
        <?php if ( '' !== $mask ) : ?>
            <label>
                <input type="checkbox" name="presshub_ai_remove_github_token" id="presshub_ai_remove_github_token" value="1" />
                <?php echo __( 'Remove stored key', 'presshub-ai-editor' ); ?>
            </label>
        <?php endif; ?>
        <?php
    }

    public function render_google_cloud_api_key_field() {
        $saved = (string) get_option( 'presshub_ai_google_cloud_api_key', '' );
        $mask  = self::mask_key( $saved );
        ?>
        <input type="password" name="presshub_ai_google_cloud_api_key" id="presshub_ai_google_cloud_api_key" value="<?php echo self::esc_attr_safe( $mask ); ?>" placeholder="<?php echo self::esc_attr_safe( $mask ); ?>" class="regular-text" autocomplete="off" />
        <p class="description"><?php echo __( 'Required for Google Cloud Imagen and Text-to-Speech integration.', 'presshub-ai-editor' ); ?></p>
        <?php if ( '' !== $mask ) : ?>
            <label>
                <input type="checkbox" name="presshub_ai_remove_google_cloud_api_key" id="presshub_ai_remove_google_cloud_api_key" value="1" />
                <?php echo __( 'Remove stored key', 'presshub-ai-editor' ); ?>
            </label>
        <?php endif; ?>
        <?php
    }

    public function render_gcloud_project_id_field() {
        $option = 'presshub_ai_gcloud_project_id';
        $value  = (string) get_option( $option, 'presshub-ai' );
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" />
        <p class="description"><?php echo __( 'Google Cloud project ID used in the Vertex AI Imagen endpoint URL. Defaults to presshub-ai.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_imagen_region_field() {
        $option = 'presshub_ai_imagen_region';
        $value  = (string) get_option( $option, 'us-central1' );
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" />
        <p class="description"><?php echo __( 'Vertex AI region for Imagen. Default us-central1. European tenants may use e.g. europe-west4 for data residency.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_rate_limit_enabled_field() {
        $option = 'presshub_ai_rate_limit_enabled';
        $value  = (int) get_option( $option, 0 );
        ?>
        <input type="hidden" name="<?php echo self::esc_attr_safe( $option ); ?>" value="0" />
        <label>
            <input type="checkbox" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="1" <?php echo $value ? 'checked="checked"' : ''; ?> />
            <?php echo __( 'Throttle AI actions when a user exceeds the configured budget', 'presshub-ai-editor' ); ?>
        </label>
        <?php
    }

    public function render_rate_limit_per_hour_field() {
        $option = 'presshub_ai_rate_limit_per_hour';
        $value  = (int) get_option( $option, 30 );
        ?>
        <input type="number" min="1" max="10000" step="1" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" />
        <p class="description"><?php echo __( 'Maximum number of AI actions (draft, review, chat, research) a single user may make within the window. Defaults to 30.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_rate_limit_window_seconds_field() {
        $option = 'presshub_ai_rate_limit_window_seconds';
        $value  = (int) get_option( $option, 3600 );
        ?>
        <input type="number" min="1" max="86400" step="1" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" />
        <p class="description"><?php echo __( 'Length of the rolling window in seconds. Defaults to 3600 (1 hour).', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_research_retention_days_field() {
        $option  = 'presshub_ai_research_retention_days';
        $default = class_exists( 'PressHub_AI_Research_Cleanup' )
            ? PressHub_AI_Research_Cleanup::DEFAULT_RETENTION_DAYS
            : 30;
        $value   = (int) get_option( $option, $default );
        ?>
        <input type="number" min="1" max="3650" step="1" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" />
        <p class="description"><?php echo __( 'How long completed or failed research logs are kept before the daily cleanup deletes them. Defaults to 30 days.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_generation_record_retention_days_field() {
        $option = 'presshub_ai_generation_record_retention_days';
        $value  = PressHub_AI_Settings_Storage::get_generation_record_retention_days();
        ?>
        <input type="number" min="7" max="365" step="1" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" />
        <p class="description"><?php echo esc_html__( 'How long to keep generation records (prompts, settings, inputs and responses for each briefing and podcast generation). 7–365 days, default 90.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_log_file_retention_days_field() {
        $option = 'presshub_ai_log_file_retention_days';
        $value  = PressHub_AI_Settings_Storage::get_log_file_retention_days();
        ?>
        <input type="number" min="1" max="90" step="1" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" />
        <p class="description"><?php echo esc_html__( 'How long rotated log files (application, prompt and TTS logs) are kept before the daily cleanup deletes them. 1-90 days, default 14.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_token_log_retention_days_field() {
        $option = 'presshub_ai_token_log_retention_days';
        $value  = PressHub_AI_Settings_Storage::get_token_log_retention_days();
        ?>
        <input type="number" min="7" max="365" step="1" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" />
        <p class="description"><?php echo esc_html__( 'How long token usage rows are kept before the daily cleanup deletes them. 7-365 days, default 60.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_audit_log_retention_days_field() {
        $option = 'presshub_ai_audit_log_retention_days';
        $value  = PressHub_AI_Settings_Storage::get_audit_log_retention_days();
        ?>
        <input type="number" min="30" max="730" step="1" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" />
        <p class="description"><?php echo esc_html__( 'How long audit trail rows are kept before the daily cleanup deletes them. 30-730 days, default 90.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_log_level_field() {
        $option   = 'presshub_ai_log_level';
        $selected = class_exists( 'PressHub_AI_Logger' ) ? PressHub_AI_Logger::get_configured_level() : 'INFO';
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <option value="DEBUG" <?php echo 'DEBUG' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'DEBUG (Verbose: all API payloads, scheduling, turns & HTTP)', 'presshub-ai-editor' ); ?></option>
            <option value="INFO" <?php echo 'INFO' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'INFO (Standard: milestones, settings updates, jobs)', 'presshub-ai-editor' ); ?></option>
            <option value="WARNING" <?php echo 'WARNING' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'WARNING (Only warnings & failures)', 'presshub-ai-editor' ); ?></option>
            <option value="ERROR" <?php echo 'ERROR' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'ERROR (Only critical failures)', 'presshub-ai-editor' ); ?></option>
            <option value="OFF" <?php echo 'OFF' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'OFF (Disable file logging)', 'presshub-ai-editor' ); ?></option>
        </select>
        <p class="description"><?php echo __( 'Control verbosity of the internal diagnostic log file (stored at the protected log folder (app channel)). When not set explicitly, the default is DEBUG if WP_DEBUG is on and INFO otherwise. Prompt debugging no longer changes the log level.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_section() {
        echo '<p>' . __( 'Automated morning Greek news briefing text curation and multi-voice conversational podcast generation.', 'presshub-ai-editor' ) . '</p>';
    }

    public function render_briefing_sources_field() {
        $option  = 'presshub_ai_briefing_sources';
        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( $option, 'options' );
        }
        $sources      = PressHub_AI_Settings_Storage::get_briefing_sources();
        $total_count  = count( $sources );
        $active_count = count( array_filter( $sources, function( $s ) { return ! empty( $s['enabled'] ); } ) );
        ?>
        <div class="presshub-sources-manager-wrap" id="presshub-sources-manager-wrap">
            <input type="hidden" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo esc_attr( wp_json_encode( $sources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?>" />
            
            <div class="ph-display-flex ph-justify-content-space-between ph-align-items-center ph-margin-bottom-12px ph-flex-wrap-wrap ph-gap-10px presshub-sources-toolbar">
                <div class="ph-display-flex ph-align-items-center ph-gap-8px">
                    <span id="presshub-sources-count-badge" class="presshub-badge">
                        <?php echo sprintf( esc_html__( '%d Sources (%d Active)', 'presshub-ai-editor' ), $total_count, $active_count ); ?>
                    </span>
                    <span class="ph-color-646970 ph-font-size-12px">
                        <?php echo esc_html__( 'Configure editorial outlets, RSS feeds, and multi-modal streams for daily morning harvesting.', 'presshub-ai-editor' ); ?>
                    </span>
                </div>
                <div class="ph-display-flex ph-gap-8px">
                    <button type="button" class="button" id="presshub-bulk-import-sources-btn">
                        <span class="ph-vertical-align-text-bottom ph-margin-right-4px dashicons dashicons-list-view"></span><?php echo esc_html__( 'Bulk Import', 'presshub-ai-editor' ); ?>
                    </button>
                    <button type="button" class="button button-primary" id="presshub-add-source-btn">
                        <span class="ph-vertical-align-text-bottom ph-margin-right-4px dashicons dashicons-plus-alt2"></span><?php echo esc_html__( 'Add News Source', 'presshub-ai-editor' ); ?>
                    </button>
                </div>
            </div>

            <div class="presshub-table-responsive presshub-sources-table-wrap">
                <table class="wp-list-table widefat fixed striped presshub-sources-table" id="presshub-sources-table">
                    <thead>
                        <tr>
                            <th scope="col" class="ph-width-80px ph-text-align-center"><?php echo esc_html__( 'Status', 'presshub-ai-editor' ); ?></th>
                            <th scope="col" class="ph-width-170px"><?php echo esc_html__( 'Source Name', 'presshub-ai-editor' ); ?></th>
                            <th scope="col"><?php echo esc_html__( 'URL / Feed', 'presshub-ai-editor' ); ?></th>
                            <th scope="col" class="ph-width-180px"><?php echo esc_html__( 'Media Type', 'presshub-ai-editor' ); ?></th>
                            <th scope="col" class="ph-width-140px"><?php echo esc_html__( 'Category / Notes', 'presshub-ai-editor' ); ?></th>
                            <th scope="col" class="ph-width-170px ph-text-align-right"><?php echo esc_html__( 'Actions', 'presshub-ai-editor' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="presshub-sources-tbody">
                        <?php
                        if ( empty( $sources ) ) {
                            echo '<tr class="presshub-sources-empty-row"><td colspan="6" class="ph-text-align-center ph-color-646970 ph-padding-20px">' . esc_html__( 'No news sources configured yet. Click "Add News Source" to add one.', 'presshub-ai-editor' ) . '</td></tr>';
                        } else {
                            foreach ( $sources as $source ) {
                                echo $this->render_source_row( $source ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>
            <p class="ph-margin-top-8px description">
                <?php echo esc_html__( 'Active text/RSS sources are crawled every morning during the harvest window. Multi-modal (video/audio) sources are preserved for upcoming ingestion pipelines.', 'presshub-ai-editor' ); ?>
                <strong><?php echo esc_html__( 'Changes to this list are stored only when you click "Save Daily Briefing Settings".', 'presshub-ai-editor' ); ?></strong>
            </p>
        </div>
        <?php
    }

    /**
     * Render a single row of the News Sources table.
     *
     * @param array $source Source configuration array.
     * @return string HTML table row.
     */
    public function render_source_row( array $source ): string {
        $id           = esc_attr( $source['id'] ?? '' );
        $name         = esc_html( $source['name'] ?? '' );
        $url          = esc_url( $source['url'] ?? '' );
        $type         = esc_attr( $source['type'] ?? 'text_news' );
        $enabled      = ! empty( $source['enabled'] );
        $category     = esc_html( $source['category'] ?? 'General' );
        $notes        = esc_html( $source['notes'] ?? '' );
        $max_articles = isset( $source['max_articles'] ) ? max( 1, min( 30, (int) $source['max_articles'] ) ) : 5;

        $types_info = PressHub_AI_Settings_Storage::get_supported_media_types();
        $type_meta  = $types_info[ $type ] ?? $types_info['text_news'];

        $status_class = $enabled ? 'pill-active' : 'pill-inactive';
        $status_text  = $enabled ? __( 'Active', 'presshub-ai-editor' ) : __( 'Disabled', 'presshub-ai-editor' );
        $toggle_title = $enabled ? __( 'Click to disable source', 'presshub-ai-editor' ) : __( 'Click to enable source', 'presshub-ai-editor' );

        $badge_class = $type_meta['badge_class'] ?? 'badge-active';
        $icon_class  = $type_meta['icon'] ?? 'dashicons-admin-site-alt3';
        $type_label  = $type_meta['label'] ?? $type;

        $json_data = esc_attr( wp_json_encode( $source ) );

        $html  = '<tr class="presshub-source-row ' . ( $enabled ? '' : 'is-disabled' ) . '" data-id="' . $id . '" data-source="' . $json_data . '">';
        $html .= '<td class="ph-text-align-center ph-vertical-align-middle">';
        $html .= '<button type="button" class="presshub-source-toggle-status presshub-status-pill ' . $status_class . '" title="' . esc_attr( $toggle_title ) . '" data-id="' . $id . '">';
        $html .= esc_html( $status_text );
        $html .= '</button>';
        $html .= '</td>';

        $html .= '<td class="ph-vertical-align-middle">';
        $html .= '<strong class="presshub-source-name">' . $name . '</strong>';
        if ( '' !== $notes ) {
            $html .= '<div class="ph-color-646970 ph-font-size-11px ph-margin-top-2px presshub-source-subnote">' . $notes . '</div>';
        }
        $html .= '</td>';

        $html .= '<td class="ph-vertical-align-middle">';
        $html .= '<a href="' . $url . '" target="_blank" rel="noopener noreferrer" class="ph-word-break-break-all presshub-source-url code">' . esc_html( $url ) . ' <span class="ph-font-size-12px ph-width-12px ph-height-12px ph-text-decoration-none ph-vertical-align-middle dashicons dashicons-external"></span></a>';
        $html .= '</td>';

        $html .= '<td class="ph-vertical-align-middle">';
        $html .= '<span class="presshub-media-badge ' . esc_attr( $badge_class ) . '">';
        $html .= '<span class="ph-font-size-14px ph-width-14px ph-height-14px ph-margin-right-4px ph-vertical-align-middle dashicons ' . esc_attr( $icon_class ) . '"></span>';
        $html .= esc_html( $type_label );
        $html .= '</span>';
        $html .= '</td>';

        $html .= '<td class="ph-vertical-align-middle">';
        $html .= '<span class="presshub-category-pill">' . $category . '</span>';
        $priority = PressHub_AI_Settings_Storage::sanitize_source_priority( $source['priority'] ?? PressHub_AI_Settings_Storage::SOURCE_PRIORITY_DEFAULT );
        $html .= '<div class="ph-margin-top-4px"><span class="ph-font-size-11px ph-color-50575e ph-background-f0f0f1 ph-padding-2px-6px ph-border-radius-3px ph-display-inline-block presshub-source-quota">' . sprintf( esc_html__( 'Quota: %d articles', 'presshub-ai-editor' ), $max_articles ) . '</span>'
            . ' <span class="ph-font-size-11px ph-color-50575e ph-background-f0f0f1 ph-padding-2px-6px ph-border-radius-3px ph-display-inline-block presshub-source-priority">' . sprintf( esc_html__( 'Priority: %d/5', 'presshub-ai-editor' ), $priority ) . '</span>'
            . self::alignment_badge_html( (string) ( $source['alignment'] ?? '' ) ) . '</div>';
        $html .= '</td>';

        $html .= '<td class="ph-text-align-right ph-vertical-align-middle">';
        $html .= '<div class="ph-display-flex ph-gap-6px ph-justify-content-flex-end presshub-source-actions">';
        $html .= '<button type="button" class="button button-small presshub-source-test-btn" data-url="' . $url . '" data-type="' . $type . '" title="' . esc_attr__( 'Test connectivity', 'presshub-ai-editor' ) . '">';
        $html .= '<span class="ph-font-size-14px ph-width-14px ph-height-14px ph-vertical-align-text-top dashicons dashicons-networking"></span> ' . esc_html__( 'Test', 'presshub-ai-editor' );
        $html .= '</button>';
        $html .= '<button type="button" class="button button-small presshub-source-edit-btn" data-id="' . $id . '" title="' . esc_attr__( 'Edit source', 'presshub-ai-editor' ) . '">';
        $html .= '<span class="ph-font-size-14px ph-width-14px ph-height-14px ph-vertical-align-text-top dashicons dashicons-edit"></span> ' . esc_html__( 'Edit', 'presshub-ai-editor' );
        $html .= '</button>';
        $html .= '<button type="button" class="ph-color-b32d2e button button-small presshub-source-delete-btn" data-id="' . $id . '" title="' . esc_attr__( 'Delete source', 'presshub-ai-editor' ) . '">';
        $html .= '<span class="ph-font-size-14px ph-width-14px ph-height-14px ph-vertical-align-text-top dashicons dashicons-trash"></span>';
        $html .= '</button>';
        $html .= '</div>';
        $html .= '</td>';

        $html .= '</tr>';
        return $html;
    }

    /**
     * Render the Add/Edit News Source modal dialog.
     */
    public function render_source_modal(): void {
        $types = PressHub_AI_Settings_Storage::get_supported_media_types();
        ?>
        <div id="presshub-source-modal" class="ph-display-none presshub-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="presshub-source-modal-title">
            <div class="presshub-modal presshub-source-modal-content">
                <div class="ph-display-flex ph-justify-content-space-between ph-align-items-center ph-padding-14px-20px ph-border-bottom-1px-solid-dcdcde presshub-modal-header">
                    <h2 id="presshub-source-modal-title" class="ph-margin-0 ph-font-size-16px"><?php echo esc_html__( 'Add News Source', 'presshub-ai-editor' ); ?></h2>
                    <button type="button" class="ph-background-none ph-border-none ph-font-size-20px ph-cursor-pointer ph-color-666 presshub-modal-close" aria-label="<?php echo esc_attr__( 'Close', 'presshub-ai-editor' ); ?>">&times;</button>
                </div>
                <div class="ph-padding-20px ph-max-height-70vh ph-overflow-y-auto presshub-modal-body">
                    <form id="presshub-source-form">
                        <input type="hidden" id="source-form-id" name="id" value="" />
                        
                        <div class="ph-display-flex ph-gap-15px ph-margin-bottom-15px presshub-form-row">
                            <div class="ph-flex-1 presshub-form-group">
                                <label for="source-form-name"><strong><?php echo esc_html__( 'Source Name:', 'presshub-ai-editor' ); ?> <span class="ph-color-red">*</span></strong></label>
                                <input type="text" id="source-form-name" name="name" class="ph-margin-top-4px widefat" required placeholder="<?php echo esc_attr__( 'e.g. Η Καθημερινή', 'presshub-ai-editor' ); ?>" />
                            </div>
                            <div class="ph-flex-1 presshub-form-group">
                                <label for="source-form-type"><strong><?php echo esc_html__( 'Media Type:', 'presshub-ai-editor' ); ?></strong></label>
                                <select id="source-form-type" name="type" class="ph-margin-top-4px widefat">
                                    <?php foreach ( $types as $t_key => $t_info ) : ?>
                                        <option value="<?php echo esc_attr( $t_key ); ?>">
                                             <?php echo esc_html( $t_info['label'] ); ?> <?php echo $t_info['active'] ? esc_html__( '[Active Harvester]', 'presshub-ai-editor' ) : esc_html__( '[Planned Multi-Modal]', 'presshub-ai-editor' ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="ph-margin-bottom-15px presshub-form-group">
                            <label for="source-form-url"><strong><?php echo esc_html__( 'Source URL / RSS Feed:', 'presshub-ai-editor' ); ?> <span class="ph-color-red">*</span></strong></label>
                            <input type="url" id="source-form-url" name="url" class="ph-margin-top-4px widefat code" required placeholder="https://www.kathimerini.gr" />
                            <p class="description"><?php echo esc_html__( 'Homepage URL or direct RSS/Atom feed URL to scrape for daily news articles.', 'presshub-ai-editor' ); ?></p>
                        </div>

                        <div class="ph-display-flex ph-gap-15px ph-margin-bottom-15px presshub-form-row">
                            <div class="ph-flex-1 presshub-form-group">
                                <label for="source-form-category"><strong><?php echo esc_html__( 'Category / Beat:', 'presshub-ai-editor' ); ?></strong></label>
                                <input type="text" id="source-form-category" name="category" class="ph-margin-top-4px widefat" placeholder="<?php echo esc_attr__( 'e.g. General, Economy, Tech', 'presshub-ai-editor' ); ?>" />
                            </div>
                            <div class="ph-flex-1 presshub-form-group">
                                <label for="source-form-max-articles"><strong><?php esc_html_e( 'Max Articles to Harvest', 'presshub-ai-editor' ); ?></strong></label>
                                <input type="number" id="source-form-max-articles" name="max_articles" min="1" max="30" value="5" class="ph-margin-top-4px widefat" />
                                <p class="description"><?php esc_html_e( 'Maximum top news articles to scrape from this source during each daily briefing harvest (1–30, default: 5).', 'presshub-ai-editor' ); ?></p>
                            </div>
                            <div class="ph-flex-1 presshub-form-group">
                                <label for="source-form-priority"><strong><?php esc_html_e( 'Priority', 'presshub-ai-editor' ); ?></strong></label>
                                <select id="source-form-priority" name="priority" class="ph-margin-top-4px widefat">
                                    <option value="5"><?php esc_html_e( '5 – Highest', 'presshub-ai-editor' ); ?></option>
                                    <option value="4"><?php esc_html_e( '4 – High', 'presshub-ai-editor' ); ?></option>
                                    <option value="3" selected="selected"><?php esc_html_e( '3 – Normal', 'presshub-ai-editor' ); ?></option>
                                    <option value="2"><?php esc_html_e( '2 – Low', 'presshub-ai-editor' ); ?></option>
                                    <option value="1"><?php esc_html_e( '1 – Lowest', 'presshub-ai-editor' ); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e( 'Higher-priority sources are kept first when the pool is larger than the curation limit, and the AI prefers them for lead stories.', 'presshub-ai-editor' ); ?></p>
                            </div>
                            <div class="ph-flex-1 presshub-form-group">
                                <label for="source-form-alignment"><strong><?php esc_html_e( 'Alignment', 'presshub-ai-editor' ); ?></strong></label>
                                <select id="source-form-alignment" name="alignment" class="ph-margin-top-4px widefat">
                                    <option value=""><?php esc_html_e( 'Not set', 'presshub-ai-editor' ); ?></option>
                                    <?php foreach ( self::alignment_labels( true ) as $alignment_key => $alignment_label ) : ?>
                                        <option value="<?php echo esc_attr( $alignment_key ); ?>"><?php echo esc_html( $alignment_label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'Whose side this source usually reflects. The AI attributes claims from aligned sources and looks for the other side; the Hub warns when the pool has only one side.', 'presshub-ai-editor' ); ?></p>
                            </div>
                        </div>

                        <div class="ph-margin-bottom-15px presshub-form-group">
                            <label class="ph-display-flex ph-align-items-center ph-gap-8px ph-cursor-pointer ph-margin-0">
                                <input type="checkbox" id="source-form-enabled" name="enabled" value="1" checked="checked" />
                                <strong><?php echo esc_html__( 'Enable in Daily Harvest', 'presshub-ai-editor' ); ?></strong>
                            </label>
                        </div>

                        <div class="ph-margin-bottom-15px presshub-form-group">
                            <label for="source-form-notes"><strong><?php echo esc_html__( 'Editorial Notes:', 'presshub-ai-editor' ); ?></strong></label>
                            <textarea id="source-form-notes" name="notes" class="ph-margin-top-4px widefat" rows="2" placeholder="<?php echo esc_attr__( 'Optional editorial notes or scraping context...', 'presshub-ai-editor' ); ?>"></textarea>
                        </div>

                        <div id="presshub-source-form-notice" class="ph-display-none ph-margin-top-10px"></div>
                    </form>
                </div>
                <div class="ph-display-flex ph-justify-content-space-between ph-align-items-center ph-padding-14px-20px ph-border-top-1px-solid-dcdcde ph-background-f6f7f7 presshub-modal-actions">
                    <div>
                        <button type="button" id="presshub-source-form-test" class="button button-secondary">
                            <span class="ph-vertical-align-text-bottom ph-margin-right-2px dashicons dashicons-networking"></span> <?php echo esc_html__( 'Test Connection', 'presshub-ai-editor' ); ?>
                        </button>
                        <span id="presshub-source-form-spinner" class="ph-float-none ph-margin-0-0-0-6px spinner" role="status"><span class="screen-reader-text"></span></span>
                    </div>
                    <div class="ph-display-flex ph-gap-8px">
                        <button type="button" class="button button-secondary presshub-modal-cancel"><?php echo esc_html__( 'Cancel', 'presshub-ai-editor' ); ?></button>
                        <button type="button" id="presshub-source-form-save" class="button button-primary"><?php echo esc_html__( 'Save Source', 'presshub-ai-editor' ); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the modal for pasting a newline-delimited list of news source URLs.
     *
     * Supports three paste shapes per non-empty line:
     *   - "Name | URL" (pipe-delimited)
     *   - "Name,URL"   (CSV-style)
     *   - bare URL     (https?://...)
     *
     * Optional defaults (media type, category, max articles, enabled)
     * are applied to every successfully imported source.
     */
    public function render_bulk_sources_modal(): void {
        $types = PressHub_AI_Settings_Storage::get_supported_media_types();
        ?>
        <div id="presshub-bulk-import-modal" class="ph-display-none presshub-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="presshub-bulk-import-modal-title">
            <div class="presshub-modal presshub-bulk-import-modal-content">
                <div class="ph-display-flex ph-justify-content-space-between ph-align-items-center ph-padding-14px-20px ph-border-bottom-1px-solid-dcdcde presshub-modal-header">
                    <h2 id="presshub-bulk-import-modal-title" class="ph-margin-0 ph-font-size-16px"><?php echo esc_html__( 'Bulk Import News Sources', 'presshub-ai-editor' ); ?></h2>
                    <button type="button" class="ph-background-none ph-border-none ph-font-size-20px ph-cursor-pointer ph-color-666 presshub-modal-close" aria-label="<?php echo esc_attr__( 'Close', 'presshub-ai-editor' ); ?>">&times;</button>
                </div>
                <div class="ph-padding-20px ph-max-height-70vh ph-overflow-y-auto presshub-modal-body">
                    <p class="ph-margin-top-0 description">
                        <?php echo esc_html__( 'Paste up to 100 news source URLs (one per line). Each line may use "Name | URL", "Name,URL", or a bare URL. Names are optional; if omitted, the source hostname is used.', 'presshub-ai-editor' ); ?>
                    </p>

                    <form id="presshub-bulk-import-form">
                        <div class="ph-margin-bottom-15px presshub-form-group">
                            <label for="presshub-bulk-import-urls"><strong><?php echo esc_html__( 'URLs:', 'presshub-ai-editor' ); ?></strong></label>
                            <textarea id="presshub-bulk-import-urls" name="urls" rows="10" class="ph-margin-top-4px ph-font-family-monospace widefat code" placeholder="Η Καθημερινή | https://www.kathimerini.gr&#10;https://www.naftemporiki.gr&#10;CNN,https://www.cnn.com"></textarea>
                        </div>

                        <fieldset class="ph-border-1px-solid-dcdcde ph-padding-12px-14px ph-margin-bottom-12px ph-border-radius-4px">
                            <legend class="ph-font-weight-600 ph-padding-0-6px"><?php echo esc_html__( 'Defaults applied to every imported source:', 'presshub-ai-editor' ); ?></legend>

                            <div class="ph-display-flex ph-gap-15px ph-margin-bottom-12px presshub-form-row">
                                <div class="ph-flex-1 presshub-form-group">
                                    <label for="presshub-bulk-import-type"><strong><?php echo esc_html__( 'Media Type:', 'presshub-ai-editor' ); ?></strong></label>
                                    <select id="presshub-bulk-import-type" name="type" class="ph-margin-top-4px widefat">
                                        <?php foreach ( $types as $t_key => $t_info ) : ?>
                                            <option value="<?php echo esc_attr( $t_key ); ?>" <?php selected( 'text_news', $t_key ); ?>>
                                                <?php echo esc_html( $t_info['label'] ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="ph-flex-1 presshub-form-group">
                                    <label for="presshub-bulk-import-category"><strong><?php echo esc_html__( 'Category:', 'presshub-ai-editor' ); ?></strong></label>
                                    <input type="text" id="presshub-bulk-import-category" name="category" class="ph-margin-top-4px widefat" value="General" placeholder="<?php echo esc_attr__( 'e.g. General, Economy', 'presshub-ai-editor' ); ?>" />
                                </div>
                                <div class="ph-flex-1 presshub-form-group">
                                    <label for="presshub-bulk-import-max-articles"><strong><?php esc_html_e( 'Max Articles:', 'presshub-ai-editor' ); ?></strong></label>
                                    <input type="number" id="presshub-bulk-import-max-articles" name="max_articles" min="1" max="30" value="5" class="ph-margin-top-4px widefat" />
                                </div>
                            </div>

                            <div class="presshub-form-group">
                                <label class="ph-display-flex ph-align-items-center ph-gap-8px ph-cursor-pointer ph-margin-0">
                                    <input type="checkbox" id="presshub-bulk-import-enabled" name="enabled" value="1" checked="checked" />
                                    <strong><?php echo esc_html__( 'Enable in Daily Harvest', 'presshub-ai-editor' ); ?></strong>
                                </label>
                            </div>
                        </fieldset>

                        <div id="presshub-bulk-import-notice" class="ph-display-none ph-margin-top-10px"></div>
                    </form>
                </div>
                <div class="ph-display-flex ph-justify-content-space-between ph-align-items-center ph-padding-14px-20px ph-border-top-1px-solid-dcdcde ph-background-f6f7f7 presshub-modal-actions">
                    <div class="ph-color-646970 ph-font-size-12px">
                        <?php echo esc_html__( 'Duplicates (matching URLs already configured) are skipped silently.', 'presshub-ai-editor' ); ?>
                    </div>
                    <div class="ph-display-flex ph-gap-8px ph-align-items-center">
                        <span id="presshub-bulk-import-spinner" class="ph-float-none ph-margin-0 spinner" role="status"><span class="screen-reader-text"></span></span>
                        <button type="button" class="button button-secondary presshub-modal-cancel"><?php echo esc_html__( 'Cancel', 'presshub-ai-editor' ); ?></button>
                        <button type="button" id="presshub-bulk-import-submit" class="button button-primary">
                            <span class="ph-vertical-align-text-bottom ph-margin-right-4px dashicons dashicons-upload"></span><?php echo esc_html__( 'Import Sources', 'presshub-ai-editor' ); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_briefing_schedule_enabled_field() {
        $option = 'presshub_ai_briefing_schedule_enabled';
        $value  = PressHub_AI_Settings_Storage::get_briefing_schedule_enabled();

        $next_harvest  = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( 'presshub_daily_news_harvest' ) : false;
        $next_generate = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( 'presshub_daily_news_generate' ) : false;
        $cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
        ?>
        <label>
            <input type="hidden" name="<?php echo self::esc_attr_safe( $option ); ?>" value="0" />
            <input type="checkbox" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="1" <?php checked( 1, $value ); ?> />
            <?php echo __( 'Enable automated morning news harvesting and podcast generation crons.', 'presshub-ai-editor' ); ?>
        </label>
        <div class="ph-margin-top-8px ph-font-size-13px ph-color-555">
            <?php if ( $value ) : ?>
                <div class="ph-margin-bottom-4px">
                    <strong><?php echo __( 'Status:', 'presshub-ai-editor' ); ?></strong>
                    <?php if ( $next_harvest ) : ?>
                        <span class="ph-color-46b450 ph-font-weight-600">&#10003; <?php echo __( 'Harvest:', 'presshub-ai-editor' ); ?></span>
                        <code><?php echo esc_html( function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s T', $next_harvest ) : date( 'Y-m-d H:i:s', $next_harvest ) ); ?></code>
                    <?php else : ?>
                        <span class="ph-color-dc3232 ph-font-weight-600">&#10007; <?php echo __( 'Harvest not in cron queue.', 'presshub-ai-editor' ); ?></span>
                    <?php endif; ?>
                    &nbsp;|&nbsp;
                    <?php if ( $next_generate ) : ?>
                        <span class="ph-color-46b450 ph-font-weight-600">&#10003; <?php echo __( 'Generation:', 'presshub-ai-editor' ); ?></span>
                        <code><?php echo esc_html( function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s T', $next_generate ) : date( 'Y-m-d H:i:s', $next_generate ) ); ?></code>
                    <?php else : ?>
                        <span class="ph-color-dc3232 ph-font-weight-600">&#10007; <?php echo __( 'Generation not in cron queue.', 'presshub-ai-editor' ); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ( $cron_disabled ) : ?>
                    <div class="ph-margin-top-6px ph-padding-6px-10px ph-background-fff8e5 ph-border-left-4px-solid-ffb900 ph-color-613e00">
                        <strong><?php echo __( 'Notice:', 'presshub-ai-editor' ); ?></strong>
                        <?php echo __( 'DISABLE_WP_CRON is enabled on your site. Automated jobs will only run if a system cron (e.g. crontab) executes wp-cron.php or WP-CLI.', 'presshub-ai-editor' ); ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_briefing_harvest_time_field() {
        $option = 'presshub_ai_briefing_harvest_time';
        $value  = (string) get_option( $option, PressHub_AI_Settings_Migration::default_briefing_harvest_time() );
        ?>
        <input type="time" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" />
        <p class="description"><?php echo __( 'Time when morning Greek news sources are scraped and snapshot saved. Default 06:30.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_generation_time_field() {
        $option = 'presshub_ai_briefing_generation_time';
        $value  = (string) get_option( $option, PressHub_AI_Settings_Migration::default_briefing_generation_time() );
        ?>
        <input type="time" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" />
        <p class="description"><?php echo __( 'Time when text story curation and podcast synthesis are triggered. Default 07:15.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_noon_schedule_enabled_field() {
        $option = 'presshub_ai_briefing_noon_schedule_enabled';
        $value  = PressHub_AI_Settings_Storage::get_briefing_noon_schedule_enabled();

        $next_harvest  = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( 'presshub_noon_news_harvest' ) : false;
        $next_generate = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( 'presshub_noon_news_generate' ) : false;
        $cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
        ?>
        <label>
            <input type="hidden" name="<?php echo self::esc_attr_safe( $option ); ?>" value="0" />
            <input type="checkbox" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="1" <?php checked( 1, $value ); ?> />
            <?php echo __( 'Enable automated noon news harvesting and podcast generation crons.', 'presshub-ai-editor' ); ?>
        </label>
        <div class="ph-margin-top-8px ph-font-size-13px ph-color-555">
            <?php if ( $value ) : ?>
                <div class="ph-margin-bottom-4px">
                    <strong><?php echo __( 'Status:', 'presshub-ai-editor' ); ?></strong>
                    <?php if ( $next_harvest ) : ?>
                        <span class="ph-color-46b450 ph-font-weight-600">&#10003; <?php echo __( 'Harvest:', 'presshub-ai-editor' ); ?></span>
                        <code><?php echo esc_html( function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s T', $next_harvest ) : date( 'Y-m-d H:i:s', $next_harvest ) ); ?></code>
                    <?php else : ?>
                        <span class="ph-color-dc3232 ph-font-weight-600">&#10007; <?php echo __( 'Harvest not in cron queue.', 'presshub-ai-editor' ); ?></span>
                    <?php endif; ?>
                    &nbsp;|&nbsp;
                    <?php if ( $next_generate ) : ?>
                        <span class="ph-color-46b450 ph-font-weight-600">&#10003; <?php echo __( 'Generation:', 'presshub-ai-editor' ); ?></span>
                        <code><?php echo esc_html( function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s T', $next_generate ) : date( 'Y-m-d H:i:s', $next_generate ) ); ?></code>
                    <?php else : ?>
                        <span class="ph-color-dc3232 ph-font-weight-600">&#10007; <?php echo __( 'Generation not in cron queue.', 'presshub-ai-editor' ); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ( $cron_disabled ) : ?>
                    <div class="ph-margin-top-6px ph-padding-6px-10px ph-background-fff8e5 ph-border-left-4px-solid-ffb900 ph-color-613e00">
                        <strong><?php echo __( 'Notice:', 'presshub-ai-editor' ); ?></strong>
                        <?php echo __( 'DISABLE_WP_CRON is enabled on your site. Automated jobs will only run if a system cron (e.g. crontab) executes wp-cron.php or WP-CLI.', 'presshub-ai-editor' ); ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_briefing_noon_harvest_time_field() {
        $option = 'presshub_ai_briefing_noon_harvest_time';
        $value  = PressHub_AI_Settings_Storage::get_briefing_noon_harvest_time();
        ?>
        <input type="time" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" />
        <p class="description"><?php echo __( 'Time when noon Greek news sources are scraped and noon snapshot saved. Default 12:30.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_noon_generation_time_field() {
        $option = 'presshub_ai_briefing_noon_generation_time';
        $value  = PressHub_AI_Settings_Storage::get_briefing_noon_generation_time();
        ?>
        <input type="time" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" />
        <p class="description"><?php echo __( 'Time when noon text story curation and podcast synthesis are triggered. Default 13:00.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_harvest_time_budget_field() {
        $option = 'presshub_ai_harvest_time_budget';
        $value  = (int) get_option( $option, 60 );
        if ( $value < 10 || $value > 900 ) {
            $value = 60;
        }
        ?>
        <input type="number" min="10" max="900" step="15" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( (string) $value ); ?>" class="small-text" />
        <p class="description"><?php echo __( 'Maximum execution time in seconds allocated for crawling news sources and extracting articles during each briefing cycle (10 to 900 seconds / 15 minutes). Default: 60s.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    /**
     * Issue #61 — Settings-First: maximum number of harvested articles
     * forwarded to the Daily Briefing curation LLM. The News Pool Inspector
     * still shows every harvested article; only the first N (by snapshot
     * order) are included in the curation prompt.
     */
    public function render_curation_max_articles_field() {
        $option = 'presshub_ai_curation_max_articles';
        $value  = PressHub_AI_Settings_Storage::get_curation_max_articles();
        ?>
        <input type="number" min="1" max="200" step="1" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( (string) $value ); ?>" class="small-text" />
        <p class="description"><?php echo __( 'Upper bound on the number of harvested articles forwarded to the Daily Briefing curation LLM. The News Pool Inspector still shows every harvested article; only the first N (by snapshot order) are included in the curation prompt. Range: 1 to 200. Default: 40.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    /**
     * Issue #61 — Settings-First: per-article body character cap inside the
     * curation prompt. Articles longer than this are truncated with the
     * "…[περικομμένο]" marker. The wide upper bound supports very large
     * context models (Gemini Pro 1M, Claude Sonnet 4.5).
     */
    public function render_curation_max_chars_per_article_field() {
        $option = 'presshub_ai_curation_max_chars_per_article';
        $value  = PressHub_AI_Settings_Storage::get_curation_max_chars_per_article();
        ?>
        <input type="number" min="100" max="400000" step="500" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( (string) $value ); ?>" class="small-text" />
        <p class="description"><?php echo __( 'Per-article body character cap inside the curation prompt. Articles longer than this are truncated with the "…[περικομμένο]" marker. Range: 100 to 400,000. Default: 3000.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_text_preset_field() {
        $option   = 'presshub_ai_briefing_text_preset';
        $selected = (string) get_option( $option, '' );
        $presets  = class_exists( 'PressHub_AI_Preset_Store' ) ? PressHub_AI_Preset_Store::get_plugin_defaults() : [];
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <option value="" <?php echo '' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'Standard Editorial (Default)', 'presshub-ai-editor' ); ?></option>
            <?php foreach ( $presets as $preset ) : ?>
                <?php if ( ! empty( $preset['enabled'] ) ) : ?>
                    <option value="<?php echo self::esc_attr_safe( $preset['slug'] ); ?>" <?php echo $selected === $preset['slug'] ? 'selected="selected"' : ''; ?>>
                        <?php echo self::esc_html_safe( $preset['name'] ); ?> (<?php echo self::esc_html_safe( $preset['slug'] ); ?>)
                    </option>
                <?php endif; ?>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php echo __( 'Instruction preset applied to the morning text briefing curation agent.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    /**
     * Temperature and timeout rows for one Daily Briefing model block (#233).
     * Both may be left empty, which keeps the provider's own value.
     *
     * @param string $module Option infix, e.g. "briefing_text".
     * @param string $role   Role label shown to editors, e.g. "Curator".
     */
    /**
     * The import forms live outside the settings form (forms can't nest); the
     * controls inside the Diagnostics section point at them with form="…".
     */
    public function render_transfer_forms() {
        if ( ! class_exists( 'PressHub_Settings_Transfer' ) ) {
            return;
        }
        $nonce = function_exists( 'wp_nonce_field' ) ? wp_nonce_field( PressHub_Settings_Transfer::NONCE, '_wpnonce', true, false ) : '';
        $post  = admin_url( 'admin-post.php' );
        ?>
        <form id="presshub-settings-import-form" method="post" action="<?php echo self::esc_attr_safe( $post ); ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="<?php echo self::esc_attr_safe( PressHub_Settings_Transfer::PREVIEW_ACTION ); ?>" />
            <?php echo $nonce; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core nonce markup. ?>
        </form>
        <form id="presshub-settings-apply-form" method="post" action="<?php echo self::esc_attr_safe( $post ); ?>">
            <input type="hidden" name="action" value="<?php echo self::esc_attr_safe( PressHub_Settings_Transfer::APPLY_ACTION ); ?>" />
            <?php echo $nonce; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core nonce markup. ?>
        </form>
        <?php
    }

    /**
     * Advanced & System → Diagnostics (#233): settings export / import and the
     * list of settings scheduled for removal.
     */
    public function render_diagnostics_section() {
        $T       = 'PressHub_Settings_Transfer';
        $unused  = class_exists( 'PressHub_Settings_Registry' ) ? PressHub_Settings_Registry::unused() : [];
        $pending = class_exists( $T ) ? $T::pending() : null;
        $state   = isset( $_GET['presshub_import'] ) ? sanitize_key( wp_unslash( $_GET['presshub_import'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
        ?>
        <h2 id="presshub-diagnostics"><?php echo esc_html__( 'Diagnostics', 'presshub-ai-editor' ); ?></h2>

        <h3><?php echo esc_html__( 'Export and import settings', 'presshub-ai-editor' ); ?></h3>
        <p class="description"><?php echo esc_html__( 'Download every PressHub setting (every Settings section) as a JSON file before a big change, and import it to go back. API keys, tokens and passwords are never written to the file; it lists them by name only, and import never changes them. The AI providers you set up (with their keys) are not in the file either, so add them again on a new site.', 'presshub-ai-editor' ); ?></p>
        <?php if ( 'done' === $state ) : ?>
            <div class="notice notice-success inline"><p><?php echo self::esc_html_safe( sprintf( __( 'Import finished: %d settings changed.', 'presshub-ai-editor' ), isset( $_GET['presshub_import_count'] ) ? (int) $_GET['presshub_import_count'] : 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification ?></p></div>
        <?php elseif ( 'expired' === $state ) : ?>
            <div class="notice notice-warning inline"><p><?php echo esc_html__( 'Nothing was imported: the preview had expired. Choose the file again.', 'presshub-ai-editor' ); ?></p></div>
        <?php endif; ?>
        <p>
            <?php if ( class_exists( $T ) ) : ?>
                <a class="button" href="<?php echo self::esc_attr_safe( $T::export_url() ); ?>" download><?php echo esc_html__( 'Export settings (JSON, no keys)', 'presshub-ai-editor' ); ?></a>
            <?php endif; ?>
            &nbsp;
            <label for="presshub_settings_file" class="screen-reader-text"><?php echo esc_html__( 'Settings export file', 'presshub-ai-editor' ); ?></label>
            <input type="file" id="presshub_settings_file" name="presshub_settings_file" accept="application/json,.json" form="presshub-settings-import-form" class="presshub-no-dirty" />
            <button type="submit" class="button" form="presshub-settings-import-form"><?php echo esc_html__( 'Preview import', 'presshub-ai-editor' ); ?></button>
        </p>
        <?php if ( $pending && isset( $pending['error'] ) ) : ?>
            <div class="notice notice-error inline"><p><?php echo self::esc_html_safe( (string) $pending['error'] ); ?></p></div>
        <?php elseif ( $pending && isset( $pending['options'] ) && class_exists( $T ) ) : ?>
            <?php $preview = $T::preview( (array) $pending['options'] ); ?>
            <div class="ph-border-1px-solid-c3c4c7 ph-background-fff ph-padding-12px ph-margin-8px-0 presshub-import-preview">
                <p><strong><?php echo self::esc_html_safe( sprintf( __( 'Import preview: %1$d settings would change, %2$d are already the same, %3$d are skipped.', 'presshub-ai-editor' ), count( $preview['changes'] ), $preview['unchanged'], count( $preview['skipped'] ) ) ); ?></strong></p>
                <?php if ( $preview['changes'] ) : ?>
                    <table class="ph-max-width-100pct widefat striped">
                        <thead><tr><th><?php echo esc_html__( 'Setting', 'presshub-ai-editor' ); ?></th><th><?php echo esc_html__( 'Now', 'presshub-ai-editor' ); ?></th><th><?php echo esc_html__( 'After import', 'presshub-ai-editor' ); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ( $preview['changes'] as $key => $change ) : ?>
                            <?php $entry = PressHub_Settings_Registry::get( $key ); ?>
                            <tr>
                                <td><?php echo self::esc_html_safe( (string) ( $entry['label'] ?? $key ) ); ?><br /><code><?php echo self::esc_html_safe( $key ); ?></code></td>
                                <td><code><?php echo self::esc_html_safe( $T::display_value( $change['from'] ) ); ?></code></td>
                                <td><code><?php echo self::esc_html_safe( $T::display_value( $change['to'] ) ); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <?php if ( $preview['skipped'] ) : ?>
                    <p class="description"><?php echo self::esc_html_safe( __( 'Skipped:', 'presshub-ai-editor' ) . ' ' . implode( ', ', array_map( static function ( $k, $why ) { return $k . ' (' . $why . ')'; }, array_keys( $preview['skipped'] ), $preview['skipped'] ) ) ); ?></p>
                <?php endif; ?>
                <p>
                    <button type="submit" class="button button-primary" form="presshub-settings-apply-form" <?php echo $preview['changes'] ? '' : 'disabled="disabled"'; ?>><?php echo esc_html__( 'Apply import', 'presshub-ai-editor' ); ?></button>
                    <span class="description"><?php echo esc_html__( 'Export your current settings first if you may want them back.', 'presshub-ai-editor' ); ?></span>
                </p>
            </div>
        <?php endif; ?>

        <h3 id="presshub-scheduled-for-removal"><?php echo esc_html__( 'Scheduled for removal', 'presshub-ai-editor' ); ?></h3>
        <p class="description"><?php echo esc_html__( 'PressHub no longer reads these settings, so they are no longer shown on their pages. Their saved values are kept until you approve their removal in the clean-up release.', 'presshub-ai-editor' ); ?></p>
        <?php if ( ! $unused ) : ?>
            <p><?php echo esc_html__( 'None.', 'presshub-ai-editor' ); ?></p>
        <?php else : ?>
            <table class="ph-max-width-100pct widefat striped presshub-removal-list">
                <thead><tr>
                    <th><?php echo esc_html__( 'Setting', 'presshub-ai-editor' ); ?></th>
                    <th><?php echo esc_html__( 'Was shown on', 'presshub-ai-editor' ); ?></th>
                    <th><?php echo esc_html__( 'Saved value', 'presshub-ai-editor' ); ?></th>
                    <th><?php echo esc_html__( 'Why it is unused', 'presshub-ai-editor' ); ?></th>
                    <th><?php echo esc_html__( 'Last read', 'presshub-ai-editor' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $unused as $key => $e ) : ?>
                    <?php $stored = get_option( $key, null ); ?>
                    <tr>
                        <td><?php echo self::esc_html_safe( (string) ( $e['label'] ?? $key ) ); ?><br /><code><?php echo self::esc_html_safe( $key ); ?></code></td>
                        <td><?php echo self::esc_html_safe( (string) ( $e['was_shown_on'] ?? '' ) ); ?></td>
                        <td><code><?php echo self::esc_html_safe( class_exists( $T ) ? $T::display_value( $stored, ! empty( $e['secret'] ) ) : '' ); ?></code></td>
                        <td><?php echo self::esc_html_safe( (string) ( $e['unused_reason'] ?? '' ) ); ?></td>
                        <td><?php echo self::esc_html_safe( (string) ( $e['last_read'] ?? '' ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    /** A 0/1 switch whose default is on (#233). */
    public function render_switch_field( string $option, string $label, string $help = '' ) {
        $on = PressHub_AI_Settings_Storage::get_switch( $option );
        ?>
        <label for="<?php echo self::esc_attr_safe( $option ); ?>">
            <input type="checkbox" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="1" <?php checked( true, $on ); ?> />
            <?php echo self::esc_html_safe( $label ); ?>
        </label>
        <?php if ( '' !== $help ) : ?>
            <p class="description"><?php echo self::esc_html_safe( $help ); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Editorial knowledge tab (#233): switches for the context PressHub adds to
     * every AI call, which used to exist only as code filters.
     */
    // ------------------------------------------------------------------
    // Phase 2 layout helpers (#234).
    // ------------------------------------------------------------------

    /** The six Daily Briefing groups, in page order: anchor id => label. */
    public static function briefing_groups(): array {
        return [
            'briefing-schedule'   => __( 'Schedule', 'presshub-ai-editor' ),
            'briefing-sources'    => __( 'Sources & harvesting', 'presshub-ai-editor' ),
            'briefing-text'       => __( 'Text story', 'presshub-ai-editor' ),
            'briefing-podcast'    => __( 'Podcast script', 'presshub-ai-editor' ),
            'briefing-voices'     => __( 'Voices & audio', 'presshub-ai-editor' ),
            'briefing-publishing' => __( 'Publishing', 'presshub-ai-editor' ),
        ];
    }

    /** "On this page" bar of anchor links to the Daily Briefing groups. */
    public function render_briefing_on_this_page_nav() {
        ?>
        <nav class="presshub-on-this-page" aria-label="<?php echo esc_attr__( 'On this page', 'presshub-ai-editor' ); ?>">
            <span class="presshub-on-this-page-label"><?php echo esc_html__( 'On this page:', 'presshub-ai-editor' ); ?></span>
            <?php foreach ( self::briefing_groups() as $anchor => $label ) : ?>
                <a href="#<?php echo esc_attr( $anchor ); ?>" class="presshub-group-link"><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    /** Enabled social accounts in the sources list (types the monitor reads). */
    public static function monitored_accounts(): array {
        $types    = class_exists( 'PressHub_AI_Social_Monitor' ) ? PressHub_AI_Social_Monitor::SOURCE_TYPES : [ 'social_x' => 'x', 'social_tiktok' => 'tiktok', 'social_facebook' => 'facebook' ];
        $accounts = [];
        foreach ( PressHub_AI_Settings_Storage::get_briefing_sources() as $src ) {
            if ( isset( $types[ (string) ( $src['type'] ?? '' ) ] ) ) {
                $accounts[] = $src;
            }
        }
        return $accounts;
    }

    /**
     * Sources & harvesting row that points to Social listening, so the
     * monitor switch has one home (#234).
     */
    public function render_social_sources_summary_row() {
        $enabled  = PressHub_AI_Settings_Storage::get_social_monitor_enabled();
        $accounts = self::monitored_accounts();
        $active   = count( array_filter( $accounts, static function ( $s ) { return ! empty( $s['enabled'] ); } ) );
        ?>
        <tr>
            <th scope="row"><?php echo esc_html__( 'Social posts as sources', 'presshub-ai-editor' ); ?></th>
            <td>
                <p class="presshub-status-line">
                    <strong><?php echo $enabled ? esc_html__( 'On', 'presshub-ai-editor' ) : esc_html__( 'Off', 'presshub-ai-editor' ); ?></strong>
                    &mdash;
                    <?php
                    /* translators: 1: enabled accounts, 2: all social accounts */
                    echo esc_html( sprintf( __( '%1$d of %2$d social accounts enabled.', 'presshub-ai-editor' ), $active, count( $accounts ) ) );
                    ?>
                    <a href="<?php echo esc_url( PressHub_Settings_Sections::url( 'social-listening' ) ); ?>"><?php echo esc_html__( 'Configure in Social listening', 'presshub-ai-editor' ); ?></a>
                </p>
                <p class="description"><?php echo esc_html__( 'Social accounts are added in the source list above with the X, TikTok or Facebook type.', 'presshub-ai-editor' ); ?></p>
            </td>
        </tr>
        <?php
    }

    /** Read-only list of monitored accounts at the top of Social listening. */
    public function render_monitored_accounts_list() {
        $accounts = self::monitored_accounts();
        ?>
        <h3 id="social-listening-monitoring-title" class="presshub-settings-group-title"><?php echo esc_html__( 'Account monitoring', 'presshub-ai-editor' ); ?></h3>
        <h4><?php echo esc_html__( 'Monitored accounts', 'presshub-ai-editor' ); ?></h4>
        <?php if ( empty( $accounts ) ) : ?>
            <p class="description"><?php echo esc_html__( 'No social accounts yet.', 'presshub-ai-editor' ); ?></p>
        <?php else : ?>
            <table class="ph-max-width-900px widefat striped presshub-monitored-accounts">
                <thead>
                    <tr>
                        <th scope="col"><?php echo esc_html__( 'Account', 'presshub-ai-editor' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Platform', 'presshub-ai-editor' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Priority', 'presshub-ai-editor' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Alignment', 'presshub-ai-editor' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Status', 'presshub-ai-editor' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $accounts as $src ) : ?>
                        <tr>
                            <td><a href="<?php echo esc_url( (string) ( $src['url'] ?? '' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) ( $src['name'] ?? $src['url'] ?? '' ) ); ?></a></td>
                            <td><?php echo esc_html( ucfirst( str_replace( 'social_', '', (string) ( $src['type'] ?? '' ) ) ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $src['priority'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( '' !== (string) ( $src['alignment'] ?? '' ) ? (string) $src['alignment'] : '—' ); ?></td>
                            <td><?php echo ! empty( $src['enabled'] ) ? esc_html__( 'Enabled', 'presshub-ai-editor' ) : esc_html__( 'Disabled', 'presshub-ai-editor' ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <p>
            <a href="<?php echo esc_url( PressHub_Settings_Sections::url( 'daily-briefing', 'briefing-sources' ) ); ?>" class="button button-secondary"><?php echo esc_html__( 'Add or edit accounts in Sources & harvesting', 'presshub-ai-editor' ); ?></a>
        </p>
        <?php
    }

    /**
     * Editorial memory settings, shown on the Editorial knowledge tab
     * (#234; they used to be section 4 of Daily Briefing).
     */
    public function render_editorial_memory_section() {
        $links = [];
        if ( class_exists( 'PressHub_AI_Fiches_Admin' ) ) {
            $links[] = [ PressHub_AI_Fiches_Admin::agenda_url(), __( 'Editorial agenda', 'presshub-ai-editor' ) ];
            $links[] = [ admin_url( 'admin.php?page=' . PressHub_AI_Fiches_Admin::POLICY_SLUG ), __( 'Policies', 'presshub-ai-editor' ) ];
            $links[] = [ PressHub_AI_Fiches_Admin::people_url(), __( 'People', 'presshub-ai-editor' ) ];
        }
        ?>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label for="presshub_ai_memory_enabled"><?php echo esc_html__( 'Enable Editorial Memory', 'presshub-ai-editor' ); ?></label></th>
                    <td>
                        <?php $this->render_memory_enabled_field(); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="presshub_ai_memory_daily_days"><?php echo esc_html__( 'Recent Memory Window (days)', 'presshub-ai-editor' ); ?></label></th>
                    <td>
                        <?php $this->render_memory_daily_days_field(); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="presshub_ai_memory_background_days"><?php echo esc_html__( 'Background Memory Window (days)', 'presshub-ai-editor' ); ?></label></th>
                    <td>
                        <?php $this->render_memory_background_days_field(); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="presshub_ai_memory_briefing_budget_chars"><?php echo esc_html__( 'Briefing Memory Budget (chars)', 'presshub-ai-editor' ); ?></label></th>
                    <td>
                        <?php $this->render_memory_briefing_budget_chars_field(); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="presshub_ai_memory_podcast_budget_chars"><?php echo esc_html__( 'Podcast Memory Budget (chars)', 'presshub-ai-editor' ); ?></label></th>
                    <td>
                        <?php $this->render_memory_podcast_budget_chars_field(); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Memory Retention Window (days)', 'presshub-ai-editor' ); ?></th>
                    <td>
                        <p class="description"><?php
                        echo self::esc_html_safe( sprintf(
                            /* translators: %d: days */
                            __( 'Kept for %d days. Every retention period is set in one table under Site & system.', 'presshub-ai-editor' ),
                            (int) PressHub_AI_Settings_Storage::get_memory_retention_days()
                        ) );
                        ?> <a href="<?php echo self::esc_attr_safe( PressHub_Settings_Sections::url( 'site-system', 'logging-retention' ) ); ?>"><?php echo esc_html__( 'Change in Logging & retention', 'presshub-ai-editor' ); ?></a></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="presshub_ai_briefing_memory_provider"><?php echo esc_html__( 'Memory Model Provider', 'presshub-ai-editor' ); ?></label></th>
                    <td>
                        <?php $this->render_briefing_memory_provider_field(); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="presshub_ai_briefing_memory_model"><?php echo esc_html__( 'Memory Model Override', 'presshub-ai-editor' ); ?></label></th>
                    <td>
                        <?php $this->render_briefing_memory_model_field(); ?>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php if ( $links ) : ?>
            <p>
                <?php echo esc_html__( 'Related pages:', 'presshub-ai-editor' ); ?>
                <?php foreach ( $links as $i => $link ) : ?>
                    <?php echo $i ? ' · ' : ''; ?><a href="<?php echo esc_url( $link[0] ); ?>"><?php echo esc_html( $link[1] ); ?></a>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
        <?php
    }

    public function render_knowledge_tab() {
        $S            = 'PressHub_AI_Settings_Storage';
        $memory_on    = $S::get_memory_enabled();
        $budget_names = [
            'presshub_ai_policy_fiches_budgets'    => __( 'Policy fiches', 'presshub-ai-editor' ),
            'presshub_ai_stakeholders_budgets'     => __( 'People fiches', 'presshub-ai-editor' ),
            'presshub_ai_editorial_agenda_budgets' => __( 'Editorial agenda', 'presshub-ai-editor' ),
        ];
        $task_names = [
            'briefing_curation'     => __( 'Briefing curation', 'presshub-ai-editor' ),
            'podcast_script'        => __( 'Podcast script', 'presshub-ai-editor' ),
            'coauthor_draft'        => __( 'Co-Author draft', 'presshub-ai-editor' ),
            'copilot_chat'          => __( 'Copilot chat', 'presshub-ai-editor' ),
            'coauthor_scorecard'    => __( 'QA scorecard', 'presshub-ai-editor' ),
            'social_tiktok_shorts'  => __( 'Shorts script', 'presshub-ai-editor' ),
            'generate_image_prompt' => __( 'Image prompt', 'presshub-ai-editor' ),
            'social_copy_'          => __( 'Social copy', 'presshub-ai-editor' ),
            'seo_'                  => __( 'SEO', 'presshub-ai-editor' ),
        ];
        ?>
        <?php if ( ! $memory_on ) : ?>
            <div class="notice notice-warning inline"><p><?php echo esc_html__( 'Editorial memory is off (see Editorial memory below), so policy and people fiches are not added whatever the switches below say.', 'presshub-ai-editor' ); ?></p></div>
        <?php endif; ?>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label for="presshub_ai_policy_fiches_enabled"><?php echo esc_html__( 'Policy fiches in AI calls', 'presshub-ai-editor' ); ?></label></th>
                    <td><?php $this->render_switch_field( 'presshub_ai_policy_fiches_enabled', __( 'Add the matching policy fiches', 'presshub-ai-editor' ), __( 'Drafts, briefings, the podcast and social copy get the fiches of the policies a story touches, so the AI keeps every side in view. Default: on.', 'presshub-ai-editor' ) ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><label for="presshub_ai_stakeholders_enabled"><?php echo esc_html__( 'People fiches in AI calls', 'presshub-ai-editor' ); ?></label></th>
                    <td><?php $this->render_switch_field( 'presshub_ai_stakeholders_enabled', __( 'Add the fiches of the people named in a story', 'presshub-ai-editor' ), __( 'Briefings and the podcast use their current role and positions. Default: on.', 'presshub-ai-editor' ) ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><label for="presshub_ai_claim_check_enabled"><?php echo esc_html__( 'Claim checks in curation', 'presshub-ai-editor' ); ?></label></th>
                    <td><?php $this->render_switch_field( 'presshub_ai_claim_check_enabled', __( 'Ask the curator to check claims', 'presshub-ai-editor' ), __( 'Disputed claims are attributed and one-sided coverage is flagged in the briefing. Default: on.', 'presshub-ai-editor' ) ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><label for="presshub_ai_pool_cleanup_enabled"><?php echo esc_html__( 'Clean the article pool', 'presshub-ai-editor' ); ?></label></th>
                    <td>
                        <?php $this->render_switch_field( 'presshub_ai_pool_cleanup_enabled', __( 'Drop old and duplicate articles before curation', 'presshub-ai-editor' ), __( 'The curator reads fewer, fresher articles. Default: on.', 'presshub-ai-editor' ) ); ?>
                        <p><label for="presshub_ai_pool_max_age_hours"><?php echo esc_html__( 'Oldest article kept (h)', 'presshub-ai-editor' ); ?></label>
                            <input type="number" min="1" max="720" name="presshub_ai_pool_max_age_hours" id="presshub_ai_pool_max_age_hours" value="<?php echo self::esc_attr_safe( (string) $S::get_pool_max_age_hours() ); ?>" class="small-text" />
                            <span class="description"><?php echo esc_html__( '1 to 720 hours. Default: 48.', 'presshub-ai-editor' ); ?></span></p>
                    </td>
                </tr>
            </tbody>
        </table>
        <details class="presshub-knowledge-advanced">
            <summary><strong><?php echo esc_html__( 'Advanced: context budgets and duplicate detection', 'presshub-ai-editor' ); ?></strong></summary>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="presshub_ai_pool_duplicate_similarity"><?php echo esc_html__( 'Duplicate similarity (0.1–1)', 'presshub-ai-editor' ); ?></label></th>
                        <td>
                            <input type="number" min="0.1" max="1" step="0.05" name="presshub_ai_pool_duplicate_similarity" id="presshub_ai_pool_duplicate_similarity" value="<?php echo self::esc_attr_safe( (string) $S::get_pool_duplicate_similarity() ); ?>" class="small-text" />
                            <p class="description"><?php echo esc_html__( 'How alike two articles must be to count as the same story. Lower drops more articles. Default: 0.6.', 'presshub-ai-editor' ); ?></p>
                        </td>
                    </tr>
                    <?php foreach ( $S::context_budget_options() as $option => $defaults ) : ?>
                        <?php $current = $S::get_context_budgets( $option, $defaults ); ?>
                        <tr>
                            <th scope="row"><?php echo self::esc_html_safe( sprintf( __( '%s context per AI task (characters)', 'presshub-ai-editor' ), $budget_names[ $option ] ?? $option ) ); ?></th>
                            <td>
                                <?php foreach ( $defaults as $task => $default ) : ?>
                                    <?php $field_id = $option . '_' . trim( $task, '_' ); ?>
                                    <label for="<?php echo self::esc_attr_safe( $field_id ); ?>" class="ph-display-inline-block ph-min-width-12em ph-margin-0-12px-6px-0">
                                        <?php echo self::esc_html_safe( $task_names[ $task ] ?? $task ); ?>
                                        <input type="number" min="0" max="20000" step="1" name="<?php echo self::esc_attr_safe( $option . '[' . $task . ']' ); ?>" id="<?php echo self::esc_attr_safe( $field_id ); ?>" value="<?php echo self::esc_attr_safe( (string) ( $current[ $task ] ?? $default ) ); ?>" class="ph-width-6em small-text" />
                                    </label>
                                <?php endforeach; ?>
                                <p class="description"><?php echo self::esc_html_safe( sprintf( __( 'Most characters of %s added to each kind of AI call. 0 leaves that task without them.', 'presshub-ai-editor' ), strtolower( $budget_names[ $option ] ?? $option ) ) ); ?></p>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </details>
        <p class="description"><?php echo esc_html__( 'Developers can still adjust each of these with its code filter, which runs on top of the value saved here.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_module_tuning_rows( string $module, string $role ) {
        $t_opt = 'presshub_ai_' . $module . '_temperature';
        $s_opt = 'presshub_ai_' . $module . '_timeout';
        $t_val = get_option( $t_opt, '' );
        $s_val = get_option( $s_opt, '' );
        ?>
        <tr>
            <th scope="row"><label for="<?php echo self::esc_attr_safe( $t_opt ); ?>"><?php echo self::esc_html_safe( sprintf( __( '%s temperature', 'presshub-ai-editor' ), $role ) ); ?></label></th>
            <td>
                <input type="number" min="0" max="2" step="0.05" name="<?php echo self::esc_attr_safe( $t_opt ); ?>" id="<?php echo self::esc_attr_safe( $t_opt ); ?>" value="<?php echo self::esc_attr_safe( is_scalar( $t_val ) ? (string) $t_val : '' ); ?>" class="small-text" placeholder="<?php echo esc_attr__( 'provider', 'presshub-ai-editor' ); ?>" />
                <p class="description"><?php echo __( 'How freely the model words its output, from 0 (most predictable) to 2. Leave empty to use the provider\'s temperature.', 'presshub-ai-editor' ); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="<?php echo self::esc_attr_safe( $s_opt ); ?>"><?php echo self::esc_html_safe( sprintf( __( '%s timeout (s)', 'presshub-ai-editor' ), $role ) ); ?></label></th>
            <td>
                <input type="number" min="10" max="600" name="<?php echo self::esc_attr_safe( $s_opt ); ?>" id="<?php echo self::esc_attr_safe( $s_opt ); ?>" value="<?php echo self::esc_attr_safe( is_scalar( $s_val ) ? (string) $s_val : '' ); ?>" class="small-text" placeholder="<?php echo esc_attr__( 'provider', 'presshub-ai-editor' ); ?>" />
                <p class="description"><?php echo __( 'Seconds to wait for the model before giving up (10 to 600). Leave empty to use the provider\'s timeout. Raise it if long runs time out.', 'presshub-ai-editor' ); ?></p>
            </td>
        </tr>
        <?php
    }

    public function render_briefing_podcast_preset_field() {
        $option   = 'presshub_ai_briefing_podcast_preset';
        $selected = (string) get_option( $option, '' );
        $presets  = class_exists( 'PressHub_AI_Preset_Store' ) ? PressHub_AI_Preset_Store::get_plugin_defaults() : [];
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <option value="" <?php echo '' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'Standard Conversational (Default)', 'presshub-ai-editor' ); ?></option>
            <?php foreach ( $presets as $preset ) : ?>
                <?php if ( ! empty( $preset['enabled'] ) ) : ?>
                    <option value="<?php echo self::esc_attr_safe( $preset['slug'] ); ?>" <?php echo $selected === $preset['slug'] ? 'selected="selected"' : ''; ?>>
                        <?php echo self::esc_html_safe( $preset['name'] ); ?> (<?php echo self::esc_html_safe( $preset['slug'] ); ?>)
                    </option>
                <?php endif; ?>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php echo __( 'Instruction preset the podcast scriptwriter follows when it writes the dialogue. Default: Standard Conversational.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_target_duration_field() {
        $option   = 'presshub_ai_briefing_target_duration';
        $selected = (string) get_option( $option, PressHub_AI_Settings_Migration::default_briefing_target_duration() );
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <option value="3_min" <?php echo '3_min' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( '3 Minutes (~450 words, 6-8 dialogue turns)', 'presshub-ai-editor' ); ?></option>
            <option value="5_min" <?php echo '5_min' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( '5 Minutes (~750 words, 12-15 dialogue turns)', 'presshub-ai-editor' ); ?></option>
            <option value="10_min" <?php echo '10_min' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( '10 Minutes (~1500 words, 20+ dialogue turns)', 'presshub-ai-editor' ); ?></option>
        </select>
        <p class="description"><?php echo __( 'Target duration and word budget pacing for daily podcast synthesis.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_tts_engine_field() {
        $option = 'presshub_ai_briefing_tts_engine';
        $value  = (string) get_option( $option, PressHub_AI_Settings_Migration::default_briefing_tts_engine() );
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <option value="gemini" <?php echo 'gemini' === $value ? 'selected="selected"' : ''; ?>>
                <?php echo esc_html__( 'Google AI Studio (Gemini Flash Neural Audio / logosAI) — Recommended', 'presshub-ai-editor' ); ?>
            </option>
            <option value="google_cloud" <?php echo 'google_cloud' === $value ? 'selected="selected"' : ''; ?>>
                <?php echo esc_html__( 'Google Cloud Text-to-Speech (Legacy TTS)', 'presshub-ai-editor' ); ?>
            </option>
        </select>
        <p class="description">
            <?php echo esc_html__( 'Which speech service reads the podcast aloud. It also decides which voices the Shorts & Reels narrator can use. Google AI Studio uses your existing Gemini API key and needs no separate setup. Default: Google AI Studio.', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    public function render_briefing_tts_api_key_field() {
        $saved = (string) get_option( 'presshub_ai_briefing_tts_api_key', '' );
        $mask  = self::mask_key( $saved );
        ?>
        <input type="password" name="presshub_ai_briefing_tts_api_key" id="presshub_ai_briefing_tts_api_key" value="<?php echo self::esc_attr_safe( $mask ); ?>" placeholder="<?php echo self::esc_attr_safe( $mask ); ?>" class="regular-text" autocomplete="off" />
        <p class="description"><?php echo __( 'Dedicated Gemini / Google AI Studio API key used specifically for speech generation and podcast audio synthesis. If left empty, falls back to your main Gemini API key from the Providers tab.', 'presshub-ai-editor' ); ?></p>
        <?php if ( '' !== $mask ) : ?>
            <label>
                <input type="checkbox" name="presshub_ai_remove_briefing_tts_api_key" id="presshub_ai_remove_briefing_tts_api_key" value="1" />
                <?php echo __( 'Remove stored key', 'presshub-ai-editor' ); ?>
            </label>
        <?php endif; ?>
        <?php
    }

    /**
     * Press release inbox (IMAP) rows for the Daily Briefing tab.
     */
    public function render_press_inbox_rows() {
        $enabled    = PressHub_AI_Settings_Storage::get_press_inbox_enabled();
        $host       = PressHub_AI_Settings_Storage::get_press_inbox_host();
        $port       = PressHub_AI_Settings_Storage::get_press_inbox_port();
        $encryption = PressHub_AI_Settings_Storage::get_press_inbox_encryption();
        $verify     = PressHub_AI_Settings_Storage::get_press_inbox_verify_ssl();
        $username   = PressHub_AI_Settings_Storage::get_press_inbox_username();
        $has_pass   = '' !== PressHub_AI_Settings_Storage::get_press_inbox_password();
        $folder     = PressHub_AI_Settings_Storage::get_press_inbox_folder();
        $processed  = PressHub_AI_Settings_Storage::get_press_inbox_processed_folder();
        $senders    = implode( "\n", PressHub_AI_Settings_Storage::get_press_inbox_allowed_senders() );
        $max_msgs   = PressHub_AI_Settings_Storage::get_press_inbox_max_messages();
        $max_age    = PressHub_AI_Settings_Storage::get_press_inbox_max_age_hours();
        $max_mb     = PressHub_AI_Settings_Storage::get_press_inbox_max_attachment_mb();
        $timeout    = PressHub_AI_Settings_Storage::get_press_inbox_timeout();
        $extract    = PressHub_AI_Settings_Storage::get_press_inbox_extract_attachments();
        ?>
        <tr class="presshub-press-inbox-heading">
            <th scope="row" colspan="2"><h3 class="ph-margin-1em-0-0"><?php echo esc_html__( 'Press Release Inbox (email)', 'presshub-ai-editor' ); ?></h3>
                <p class="ph-font-weight-normal description"><?php echo esc_html__( 'Before each harvest, emails in this mailbox become press releases in the news pool. Each email is used once and then moved to the processed folder (never deleted). PDF and scanned attachments are read by Gemini; Word files are read directly.', 'presshub-ai-editor' ); ?></p>
            </th>
        </tr>
        <tr>
            <th scope="row"><label for="presshub_ai_press_inbox_enabled"><?php echo esc_html__( 'Read Press Release Inbox', 'presshub-ai-editor' ); ?></label></th>
            <td><label><input type="checkbox" name="presshub_ai_press_inbox_enabled" id="presshub_ai_press_inbox_enabled" value="1" <?php checked( $enabled ); ?> /> <?php echo esc_html__( 'Add emails from this mailbox to every morning and noon harvest', 'presshub-ai-editor' ); ?></label></td>
        </tr>
        <tr>
            <th scope="row"><label for="presshub_ai_press_inbox_host"><?php echo esc_html__( 'Incoming Mail Server (IMAP)', 'presshub-ai-editor' ); ?></label></th>
            <td>
                <input type="text" name="presshub_ai_press_inbox_host" id="presshub_ai_press_inbox_host" value="<?php echo self::esc_attr_safe( $host ); ?>" class="regular-text code" placeholder="presshub.cy" />
                <label for="presshub_ai_press_inbox_port" class="ph-margin-left-8px"><?php echo esc_html__( 'Port', 'presshub-ai-editor' ); ?></label>
                <input type="number" min="1" max="65535" name="presshub_ai_press_inbox_port" id="presshub_ai_press_inbox_port" value="<?php echo self::esc_attr_safe( (string) $port ); ?>" class="small-text" />
                <select name="presshub_ai_press_inbox_encryption" id="presshub_ai_press_inbox_encryption">
                    <option value="ssl" <?php selected( $encryption, 'ssl' ); ?>><?php echo esc_html__( 'SSL/TLS (port 993)', 'presshub-ai-editor' ); ?></option>
                    <option value="tls" <?php selected( $encryption, 'tls' ); ?>><?php echo esc_html__( 'STARTTLS (port 143)', 'presshub-ai-editor' ); ?></option>
                    <option value="none" <?php selected( $encryption, 'none' ); ?>><?php echo esc_html__( 'None (not recommended)', 'presshub-ai-editor' ); ?></option>
                </select>
                <p><label><input type="checkbox" name="presshub_ai_press_inbox_verify_ssl" id="presshub_ai_press_inbox_verify_ssl" value="1" <?php checked( $verify ); ?> /> <?php echo esc_html__( 'Verify the server certificate (turn off only if the mail server uses a self-signed certificate)', 'presshub-ai-editor' ); ?></label></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="presshub_ai_press_inbox_username"><?php echo esc_html__( 'Mailbox Username', 'presshub-ai-editor' ); ?></label></th>
            <td><input type="text" name="presshub_ai_press_inbox_username" id="presshub_ai_press_inbox_username" value="<?php echo self::esc_attr_safe( $username ); ?>" class="regular-text" autocomplete="off" placeholder="press@presshub.cy" />
                <?php self::registry_help( 'presshub_ai_press_inbox_username' ); ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="presshub_ai_press_inbox_password"><?php echo esc_html__( 'Mailbox Password', 'presshub-ai-editor' ); ?></label></th>
            <td>
                <input type="password" name="presshub_ai_press_inbox_password" id="presshub_ai_press_inbox_password" value="<?php echo $has_pass ? '••••••••' : ''; ?>" class="regular-text" autocomplete="new-password" />
                <p class="description"><?php echo esc_html__( 'Stored in the WordPress database and never shown again. Leave as is to keep the saved password.', 'presshub-ai-editor' ); ?></p>
                <?php if ( $has_pass ) : ?>
                    <label><input type="checkbox" name="presshub_ai_remove_press_inbox_password" id="presshub_ai_remove_press_inbox_password" value="1" /> <?php echo esc_html__( 'Remove stored password', 'presshub-ai-editor' ); ?></label>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="presshub_ai_press_inbox_folder"><?php echo esc_html__( 'Folders', 'presshub-ai-editor' ); ?></label></th>
            <td>
                <label for="presshub_ai_press_inbox_folder"><?php echo esc_html__( 'Read from', 'presshub-ai-editor' ); ?></label>
                <input type="text" name="presshub_ai_press_inbox_folder" id="presshub_ai_press_inbox_folder" value="<?php echo self::esc_attr_safe( $folder ); ?>" class="ph-width-12em regular-text code" />
                <label for="presshub_ai_press_inbox_processed_folder" class="ph-margin-left-8px"><?php echo esc_html__( 'Move used emails to', 'presshub-ai-editor' ); ?></label>
                <input type="text" name="presshub_ai_press_inbox_processed_folder" id="presshub_ai_press_inbox_processed_folder" value="<?php echo self::esc_attr_safe( $processed ); ?>" class="ph-width-12em regular-text code" />
                <p class="description"><?php echo esc_html__( 'The processed folder is created if it does not exist. Emails that are skipped (sender not allowed, too old, too large) stay in the inbox, marked as read.', 'presshub-ai-editor' ); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="presshub_ai_press_inbox_allowed_senders"><?php echo esc_html__( 'Allowed Senders', 'presshub-ai-editor' ); ?></label></th>
            <td>
                <textarea name="presshub_ai_press_inbox_allowed_senders" id="presshub_ai_press_inbox_allowed_senders" rows="4" class="large-text code" placeholder="pio.gov.cy&#10;police.gov.cy&#10;someone@example.com"><?php echo self::esc_html_safe( $senders ); ?></textarea>
                <p class="description"><?php echo esc_html__( 'One address or domain per line (a domain also covers its subdomains). Leave empty to accept every sender.', 'presshub-ai-editor' ); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php echo esc_html__( 'Inbox Limits', 'presshub-ai-editor' ); ?></th>
            <td>
                <label><?php echo esc_html__( 'Max emails per harvest', 'presshub-ai-editor' ); ?> <input type="number" min="1" max="100" name="presshub_ai_press_inbox_max_messages" id="presshub_ai_press_inbox_max_messages" value="<?php echo self::esc_attr_safe( (string) $max_msgs ); ?>" class="small-text" /></label>
                &nbsp; <label><?php echo esc_html__( 'Ignore emails older than (hours)', 'presshub-ai-editor' ); ?> <input type="number" min="1" max="720" name="presshub_ai_press_inbox_max_age_hours" id="presshub_ai_press_inbox_max_age_hours" value="<?php echo self::esc_attr_safe( (string) $max_age ); ?>" class="small-text" /></label>
                <br /><label><?php echo esc_html__( 'Max attachment size (MB)', 'presshub-ai-editor' ); ?> <input type="number" min="1" max="25" name="presshub_ai_press_inbox_max_attachment_mb" id="presshub_ai_press_inbox_max_attachment_mb" value="<?php echo self::esc_attr_safe( (string) $max_mb ); ?>" class="small-text" /></label>
                &nbsp; <label><?php echo esc_html__( 'Connection timeout (seconds)', 'presshub-ai-editor' ); ?> <input type="number" min="5" max="120" name="presshub_ai_press_inbox_timeout" id="presshub_ai_press_inbox_timeout" value="<?php echo self::esc_attr_safe( (string) $timeout ); ?>" class="small-text" /></label>
                <p><label><input type="checkbox" name="presshub_ai_press_inbox_extract_attachments" id="presshub_ai_press_inbox_extract_attachments" value="1" <?php checked( $extract ); ?> /> <?php echo esc_html__( 'Read attachments (PDF and images through Gemini, Word directly)', 'presshub-ai-editor' ); ?></label></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php echo esc_html__( 'Test Mailbox', 'presshub-ai-editor' ); ?></th>
            <td>
                <button type="button" class="button" id="presshub-ai-test-press-inbox"><?php echo esc_html__( 'Test connection', 'presshub-ai-editor' ); ?></button>
                <span class="ph-float-none spinner" id="presshub-ai-test-press-inbox-spinner"></span>
                <span id="presshub-ai-test-press-inbox-result" role="status" aria-live="polite"></span>
                <p class="description"><?php echo esc_html__( 'Uses the saved settings: save first if you changed anything. Nothing is moved or marked during the test.', 'presshub-ai-editor' ); ?></p>
            </td>
        </tr>
        <?php
    }

    /**
     * Social media monitoring (X / TikTok) rows for the Daily Briefing tab.
     */
    public function render_social_monitor_rows() {
        $S          = 'PressHub_AI_Settings_Storage';
        $enabled    = $S::get_social_monitor_enabled();
        $interval   = $S::get_social_monitor_interval_min();
        $max_age    = $S::get_social_monitor_max_age_hours();
        $per_acct   = $S::get_social_monitor_max_posts_per_account();
        $per_run    = $S::get_social_monitor_max_posts_per_harvest();
        $list_url   = $S::get_social_monitor_x_list_url();
        $transcribe = $S::get_social_monitor_transcribe_videos();
        $max_secs   = $S::get_social_monitor_max_video_seconds();
        $max_mb     = $S::get_social_monitor_max_video_mb();

        $targets  = class_exists( 'PressHub_AI_Social_Monitor' ) ? PressHub_AI_Social_Monitor::get_targets() : [];
        $store    = class_exists( 'PressHub_AI_Social_Monitor' ) ? PressHub_AI_Social_Monitor::load() : [];
        $waiting  = 0;
        foreach ( $store as $p ) {
            if ( '' === (string) ( $p['used_in'] ?? '' ) ) {
                $waiting++;
            }
        }
        $last_scan = (int) get_option( 'presshub_ai_social_monitor_last_scan', 0 );
        $last_txt  = $last_scan > 0
            ? ( function_exists( 'wp_date' ) ? wp_date( 'j/n/Y H:i', $last_scan ) : gmdate( 'j/n/Y H:i', $last_scan ) )
            : __( 'never', 'presshub-ai-editor' );
        ?>
        <tr class="presshub-social-monitor-heading">
            <th scope="row" colspan="2"><h3 class="ph-margin-1em-0-0"><?php echo esc_html__( 'Social Media Monitoring (X, TikTok and Facebook)', 'presshub-ai-editor' ); ?></h3>
                <p class="ph-font-weight-normal description"><?php echo esc_html__( 'Add accounts as News Sources of type "X (Twitter) Account", "TikTok Account" or "Facebook Page". The social worker (Pull Mode) reads them with its browser and sends new posts here; X and TikTok videos are transcribed by Gemini, Facebook posts are read as text. Each post is used in one edition. Facebook Pages need a Facebook reader session on the worker (npm run login -- facebook --reader).', 'presshub-ai-editor' ); ?></p>
                <p class="ph-font-weight-normal description"><?php echo esc_html( sprintf(
                    /* translators: 1: number of accounts, 2: posts waiting, 3: last scan time */
                    __( 'Monitored accounts: %1$d. Posts waiting for the next harvest: %2$d. Last scan handed to the worker: %3$s.', 'presshub-ai-editor' ),
                    count( $targets ),
                    $waiting,
                    $last_txt
                ) ); ?></p>
            </th>
        </tr>
        <tr>
            <th scope="row"><label for="presshub_ai_social_monitor_enabled"><?php echo esc_html__( 'Monitor Social Accounts', 'presshub-ai-editor' ); ?></label></th>
            <td><label><input type="checkbox" name="presshub_ai_social_monitor_enabled" id="presshub_ai_social_monitor_enabled" value="1" <?php checked( $enabled ); ?> /> <?php echo esc_html__( 'Let the worker scan the X and TikTok sources and add their posts to the harvest', 'presshub-ai-editor' ); ?></label></td>
        </tr>
        <tr>
            <th scope="row"><?php echo esc_html__( 'Scan Schedule', 'presshub-ai-editor' ); ?></th>
            <td>
                <label><?php echo esc_html__( 'Scan every (minutes)', 'presshub-ai-editor' ); ?> <input type="number" min="30" max="1440" name="presshub_ai_social_monitor_interval_min" id="presshub_ai_social_monitor_interval_min" value="<?php echo self::esc_attr_safe( (string) $interval ); ?>" class="small-text" /></label>
                &nbsp; <label><?php echo esc_html__( 'Ignore posts older than (hours)', 'presshub-ai-editor' ); ?> <input type="number" min="1" max="168" name="presshub_ai_social_monitor_max_age_hours" id="presshub_ai_social_monitor_max_age_hours" value="<?php echo self::esc_attr_safe( (string) $max_age ); ?>" class="small-text" /></label>
                <p class="description"><?php echo esc_html__( 'An extra scan is also requested in the 30 minutes before each scheduled harvest. Fewer scans mean fewer page visits and less risk for the reader accounts.', 'presshub-ai-editor' ); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php echo esc_html__( 'Post Limits', 'presshub-ai-editor' ); ?></th>
            <td>
                <label><?php echo esc_html__( 'Max new posts per account per scan', 'presshub-ai-editor' ); ?> <input type="number" min="1" max="50" name="presshub_ai_social_monitor_max_posts_per_account" id="presshub_ai_social_monitor_max_posts_per_account" value="<?php echo self::esc_attr_safe( (string) $per_acct ); ?>" class="small-text" /></label>
                &nbsp; <label><?php echo esc_html__( 'Max posts per harvest', 'presshub-ai-editor' ); ?> <input type="number" min="1" max="100" name="presshub_ai_social_monitor_max_posts_per_harvest" id="presshub_ai_social_monitor_max_posts_per_harvest" value="<?php echo self::esc_attr_safe( (string) $per_run ); ?>" class="small-text" /></label>
                <p class="description"><?php echo esc_html__( 'Newest posts are used first. They are placed before the web articles, so keep this below the "Maximum Articles Sent to Curation LLM" setting.', 'presshub-ai-editor' ); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="presshub_ai_social_monitor_x_list_url"><?php echo esc_html__( 'X List (optional)', 'presshub-ai-editor' ); ?></label></th>
            <td>
                <input type="url" name="presshub_ai_social_monitor_x_list_url" id="presshub_ai_social_monitor_x_list_url" value="<?php echo self::esc_attr_safe( $list_url ); ?>" class="regular-text code" placeholder="https://x.com/i/lists/1234567890" />
                <p class="description"><?php echo esc_html__( 'A private List on the reader account that contains the monitored X accounts. The worker then reads one page instead of one per account. When this is set, profiles are not visited, so every monitored X source must be in the List. Accounts in the List that are not News Sources are ignored.', 'presshub-ai-editor' ); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php echo esc_html__( 'Videos', 'presshub-ai-editor' ); ?></th>
            <td>
                <label><input type="checkbox" name="presshub_ai_social_monitor_transcribe_videos" id="presshub_ai_social_monitor_transcribe_videos" value="1" <?php checked( $transcribe ); ?> /> <?php echo esc_html__( 'Transcribe and describe videos with Gemini', 'presshub-ai-editor' ); ?></label>
                <br /><label><?php echo esc_html__( 'Skip videos longer than (seconds)', 'presshub-ai-editor' ); ?> <input type="number" min="10" max="600" name="presshub_ai_social_monitor_max_video_seconds" id="presshub_ai_social_monitor_max_video_seconds" value="<?php echo self::esc_attr_safe( (string) $max_secs ); ?>" class="small-text" /></label>
                &nbsp; <label><?php echo esc_html__( 'Max video file size (MB)', 'presshub-ai-editor' ); ?> <input type="number" min="1" max="20" name="presshub_ai_social_monitor_max_video_mb" id="presshub_ai_social_monitor_max_video_mb" value="<?php echo self::esc_attr_safe( (string) $max_mb ); ?>" class="small-text" /></label>
                <p class="description"><?php echo esc_html__( 'Needs a Gemini provider. Gemini accepts inline files up to about 20 MB; the worker picks the smallest X video quality that fits.', 'presshub-ai-editor' ); ?></p>
                <p class="description"><?php echo esc_html__( 'Longer videos: a worker set up with local transcription (whisper.cpp, see the worker README) transcribes them on its own machine and sends only the text. The harvest waits up to an hour for those transcripts.', 'presshub-ai-editor' ); ?></p>
            </td>
        </tr>
        <?php
    }

    /**
     * Trending in Cyprus: social roundup post settings.
     */
    public function render_social_trending_rows() {
        $S         = 'PressHub_AI_Settings_Storage';
        $enabled   = $S::get_social_trending_enabled();
        $interval  = $S::get_social_trending_interval_hours();
        $platforms = $S::get_social_trending_platforms();
        $keywords  = implode( "\n", $S::get_social_trending_keywords() );
        $hashtags  = implode( "\n", $S::get_social_trending_hashtags() );
        $fb_pages  = implode( "\n", $S::get_social_trending_facebook_pages() );
        $category  = $S::get_social_trending_category();
        $mode      = $S::get_social_trending_post_mode();
        $status    = $S::get_social_trending_post_status();
        $title     = $S::get_social_trending_title();
        $max_items = $S::get_social_trending_max_items();
        $per_plat  = $S::get_social_trending_max_per_platform();
        $max_age   = $S::get_social_trending_max_age_hours();
        $min_eng   = $S::get_social_trending_min_engagement();
        $excluded  = implode( "\n", $S::get_social_trending_excluded_words() );
        $brief_on  = $S::get_social_trending_briefing_enabled();
        $summary_on = $S::get_social_trending_summary_enabled();
        $brief_n   = $S::get_social_trending_briefing_count();
        $per_acct  = $S::get_trending_max_per_account();
        $after_h   = $S::get_trending_share_after_hour();

        $state    = class_exists( 'PressHub_AI_Social_Trending' ) ? PressHub_AI_Social_Trending::get_state() : [];
        $fmt      = static function ( $ts ) {
            $ts = (int) $ts;
            return $ts > 0 ? ( function_exists( 'wp_date' ) ? wp_date( 'j/n/Y H:i', $ts ) : gmdate( 'j/n/Y H:i', $ts ) ) : __( 'never', 'presshub-ai-editor' );
        };
        $last_post = (int) ( $state['last_post_id'] ?? 0 );
        $run_url   = function_exists( 'wp_nonce_url' ) && class_exists( 'PressHub_AI_Social_Trending' )
            ? wp_nonce_url( admin_url( 'admin-post.php?action=' . PressHub_AI_Social_Trending::RUN_ACTION ), PressHub_AI_Social_Trending::RUN_ACTION )
            : '';
        $labels = [
            'x'         => __( 'X (worker: search, Top tab)', 'presshub-ai-editor' ),
            'tiktok'    => __( 'TikTok (worker: video search)', 'presshub-ai-editor' ),
            'instagram' => __( 'Instagram (Graph API hashtag top posts)', 'presshub-ai-editor' ),
            'facebook'  => __( 'Facebook (worker: the Pages listed below)', 'presshub-ai-editor' ),
        ];
        ?>
        <tr class="presshub-social-trending-heading">
            <th scope="row" colspan="2"><h3 class="ph-margin-1em-0-0"><?php echo esc_html__( 'Trending in Cyprus: Social Roundup Post', 'presshub-ai-editor' ); ?></h3>
                <p class="ph-font-weight-normal description"><?php echo esc_html__( 'Every few hours, collect the most engaging recent posts about Cyprus and publish them as a roundup post in their own category. X, TikTok and Facebook are read by the social worker (Pull Mode, reader sessions); Instagram uses the Instagram account and Meta token from PressHub › Settings › Connections › Social accounts.', 'presshub-ai-editor' ); ?></p>
                <p class="ph-font-weight-normal description"><?php
                    echo esc_html( sprintf(
                        /* translators: 1: last run, 2: posts in last roundup, 3: run status */
                        __( 'Last run: %1$s. Posts in the last roundup: %2$d. %3$s', 'presshub-ai-editor' ),
                        $fmt( $state['last_run'] ?? 0 ),
                        (int) ( $state['last_count'] ?? 0 ),
                        ! empty( $state['open'] ) ? __( 'A run is waiting for the worker.', 'presshub-ai-editor' ) : ''
                    ) );
                    if ( $last_post > 0 && function_exists( 'get_permalink' ) ) {
                        echo ' <a href="' . esc_url( (string) get_permalink( $last_post ) ) . '" target="_blank">' . esc_html__( 'View the roundup', 'presshub-ai-editor' ) . '</a>';
                    }
                    if ( '' !== (string) ( $state['last_note'] ?? '' ) ) {
                        echo '<br />' . esc_html( (string) $state['last_note'] );
                    }
                ?></p>
            </th>
        </tr>
        <tr>
            <th scope="row"><label for="presshub_ai_social_trending_enabled"><?php echo esc_html__( 'Publish Trending Roundup', 'presshub-ai-editor' ); ?></label></th>
            <td>
                <label><input type="checkbox" name="presshub_ai_social_trending_enabled" id="presshub_ai_social_trending_enabled" value="1" <?php checked( $enabled ); ?> /> <?php echo esc_html__( 'Collect trending Cyprus posts and publish the roundup', 'presshub-ai-editor' ); ?></label>
                &nbsp; <label><?php echo esc_html__( 'Refresh every (hours)', 'presshub-ai-editor' ); ?> <input type="number" min="1" max="48" name="presshub_ai_social_trending_interval_hours" id="presshub_ai_social_trending_interval_hours" value="<?php echo self::esc_attr_safe( (string) $interval ); ?>" class="small-text" /></label>
                <?php if ( $enabled && '' !== $run_url ) : ?>
                    &nbsp; <a class="button" href="<?php echo esc_url( $run_url ); ?>"><?php echo esc_html__( 'Refresh now', 'presshub-ai-editor' ); ?></a>
                <?php endif; ?>
                <p class="description"><?php echo esc_html__( 'Each refresh is a handful of page visits per platform on the reader accounts. Keep it at 3 hours or more.', 'presshub-ai-editor' ); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php echo esc_html__( 'Platforms', 'presshub-ai-editor' ); ?></th>
            <td>
                <?php foreach ( $labels as $p => $label ) : ?>
                    <label class="ph-display-block"><input type="checkbox" name="presshub_ai_social_trending_platform_<?php echo self::esc_attr_safe( $p ); ?>" id="presshub_ai_social_trending_platform_<?php echo self::esc_attr_safe( $p ); ?>" value="1" <?php checked( in_array( $p, $platforms, true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
                <?php endforeach; ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="presshub_ai_social_trending_keywords"><?php echo esc_html__( 'Search Terms (X, TikTok)', 'presshub-ai-editor' ); ?></label></th>
            <td>
                <textarea name="presshub_ai_social_trending_keywords" id="presshub_ai_social_trending_keywords" rows="5" class="large-text code"><?php echo esc_textarea( $keywords ); ?></textarea>
                <p class="description"><?php echo esc_html__( 'One per line. X runs two Top searches: the Greek terms (Greek-language posts only, lower like threshold) and the other terms (higher threshold), so global English posts do not crowd out local ones. TikTok also searches the first two terms.', 'presshub-ai-editor' ); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="presshub_ai_social_trending_hashtags"><?php echo esc_html__( 'Hashtags (Instagram, TikTok)', 'presshub-ai-editor' ); ?></label></th>
            <td>
                <textarea name="presshub_ai_social_trending_hashtags" id="presshub_ai_social_trending_hashtags" rows="3" class="large-text code"><?php echo esc_textarea( $hashtags ); ?></textarea>
                <p class="description"><?php echo esc_html__( 'Up to 5, without #. The worker opens the TikTok page of the first three (they show newer videos than search). Instagram allows 30 different hashtags per account per week, so change them rarely. The Meta app needs the instagram_basic permission and Instagram Public Content Access.', 'presshub-ai-editor' ); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="presshub_ai_social_trending_facebook_pages"><?php echo esc_html__( 'Facebook Pages', 'presshub-ai-editor' ); ?></label></th>
            <td>
                <textarea name="presshub_ai_social_trending_facebook_pages" id="presshub_ai_social_trending_facebook_pages" rows="4" class="large-text code" placeholder="https://www.facebook.com/philenews"><?php echo esc_textarea( $fb_pages ); ?></textarea>
                <p class="description"><?php echo esc_html__( 'One Page URL per line. Facebook has no public search, so the worker reads these Cypriot Pages and the roundup keeps their most engaging recent posts. Each Page is one visit per refresh. Needs a Facebook reader session on the worker.', 'presshub-ai-editor' ); ?>
                    <button type="button" class="button-link" onclick="document.getElementById('presshub_ai_social_trending_facebook_pages').value = <?php echo esc_attr( wp_json_encode( PressHub_AI_Settings_Storage::SOCIAL_TRENDING_DEFAULT_FACEBOOK_PAGES ) ); ?>;"><?php echo esc_html__( 'Load the starter list of Cyprus news Pages', 'presshub-ai-editor' ); ?></button></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="presshub_ai_social_trending_excluded_words"><?php echo esc_html__( 'Excluded Words', 'presshub-ai-editor' ); ?></label></th>
            <td>
                <textarea name="presshub_ai_social_trending_excluded_words" id="presshub_ai_social_trending_excluded_words" rows="4" class="large-text code"><?php echo esc_textarea( $excluded ); ?></textarea>
                <p class="description"><?php echo esc_html__( 'One word or phrase per line. Posts that contain one (as a whole word, in the text, name or handle) are left out of the roundup. Useful against crypto, betting and similar spam.', 'presshub-ai-editor' ); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php echo esc_html__( 'Selection', 'presshub-ai-editor' ); ?></th>
            <td>
                <label><?php echo esc_html__( 'Posts in the roundup', 'presshub-ai-editor' ); ?> <input type="number" min="3" max="50" name="presshub_ai_social_trending_max_items" id="presshub_ai_social_trending_max_items" value="<?php echo self::esc_attr_safe( (string) $max_items ); ?>" class="small-text" /></label>
                &nbsp; <label><?php echo esc_html__( 'Max per platform', 'presshub-ai-editor' ); ?> <input type="number" min="1" max="20" name="presshub_ai_social_trending_max_per_platform" id="presshub_ai_social_trending_max_per_platform" value="<?php echo self::esc_attr_safe( (string) $per_plat ); ?>" class="small-text" /></label>
                <br /><label><?php echo esc_html__( 'Only posts from the last (hours)', 'presshub-ai-editor' ); ?> <input type="number" min="1" max="72" name="presshub_ai_social_trending_max_age_hours" id="presshub_ai_social_trending_max_age_hours" value="<?php echo self::esc_attr_safe( (string) $max_age ); ?>" class="small-text" /></label>
                &nbsp; <label><?php echo esc_html__( 'Minimum engagement', 'presshub-ai-editor' ); ?> <input type="number" min="0" max="100000" name="presshub_ai_social_trending_min_engagement" id="presshub_ai_social_trending_min_engagement" value="<?php echo self::esc_attr_safe( (string) $min_eng ); ?>" class="small-text" /></label>
                <br /><label for="presshub_ai_social_trending_max_per_account"><?php echo esc_html__( 'Trending posts per account', 'presshub-ai-editor' ); ?></label> <input type="number" min="1" max="10" name="presshub_ai_social_trending_max_per_account" id="presshub_ai_social_trending_max_per_account" value="<?php echo self::esc_attr_safe( (string) $per_acct ); ?>" class="small-text" />
                <p class="description"><?php echo esc_html__( 'Engagement is likes + 2 × comments + 3 × shares. Posts are ranked within each platform by engagement and freshness, then the platforms take turns so the roundup stays mixed. "Trending posts per account" caps how many posts one account gets on each platform (default 1), and a story that several posts share (same link or same opening text) is shown once.', 'presshub-ai-editor' ); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="presshub_ai_social_trending_share_after_hour"><?php echo esc_html__( 'Publish trending roundups from (hour)', 'presshub-ai-editor' ); ?></label></th>
            <td>
                <input type="number" min="0" max="23" name="presshub_ai_social_trending_share_after_hour" id="presshub_ai_social_trending_share_after_hour" value="<?php echo self::esc_attr_safe( (string) $after_h ); ?>" class="small-text" />
                <p class="description"><?php echo esc_html__( 'A roundup made before this local hour is scheduled for that hour, so its social posts go out when people read. 0 publishes at once. Default: 8.', 'presshub-ai-editor' ); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="presshub_ai_social_trending_summary_enabled"><?php echo esc_html__( 'AI summary', 'presshub-ai-editor' ); ?></label></th>
            <td>
                <label><input type="checkbox" name="presshub_ai_social_trending_summary_enabled" id="presshub_ai_social_trending_summary_enabled" value="1" <?php checked( $summary_on ); ?> /> <?php echo esc_html__( 'Open each roundup with a short AI summary of what the posts are about', 'presshub-ai-editor' ); ?></label>
                <p class="description"><?php echo esc_html__( 'The social AI provider writes a short Greek paragraph on what the posts are about, shown above the posts. It is written again only when the posts in the roundup change. If the AI fails, the roundup is published without it.', 'presshub-ai-editor' ); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="presshub_ai_social_trending_briefing_enabled"><?php echo esc_html__( 'Use in the Briefing', 'presshub-ai-editor' ); ?></label></th>
            <td>
                <label><input type="checkbox" name="presshub_ai_social_trending_briefing_enabled" id="presshub_ai_social_trending_briefing_enabled" value="1" <?php checked( $brief_on ); ?> /> <?php echo esc_html__( 'Add the top trending posts to each briefing edition', 'presshub-ai-editor' ); ?></label>
                &nbsp; <label><?php echo esc_html__( 'Posts per edition', 'presshub-ai-editor' ); ?> <input type="number" min="1" max="15" name="presshub_ai_social_trending_briefing_count" id="presshub_ai_social_trending_briefing_count" value="<?php echo self::esc_attr_safe( (string) $brief_n ); ?>" class="small-text" /></label>
                <p class="description"><?php echo esc_html__( 'The best posts from the roundups since the last edition go into the harvest as one item, "What Cyprus is talking about on social media", marked as public conversation rather than verified news. The brief and the podcast get one short social segment from it. Each post is used once; posts by monitored accounts and by the news sites in the source list are left out, since those already reach the harvest.', 'presshub-ai-editor' ); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php echo esc_html__( 'Roundup Post', 'presshub-ai-editor' ); ?></th>
            <td>
                <label><?php echo esc_html__( 'Category', 'presshub-ai-editor' ); ?> <input type="text" name="presshub_ai_social_trending_category" id="presshub_ai_social_trending_category" value="<?php echo self::esc_attr_safe( $category ); ?>" class="regular-text" /></label>
                <br /><label><?php echo esc_html__( 'Title', 'presshub-ai-editor' ); ?> <input type="text" name="presshub_ai_social_trending_title" id="presshub_ai_social_trending_title" value="<?php echo self::esc_attr_safe( $title ); ?>" class="regular-text" /></label>
                <br /><label><?php echo esc_html__( 'Each refresh', 'presshub-ai-editor' ); ?>
                    <select name="presshub_ai_social_trending_post_mode" id="presshub_ai_social_trending_post_mode">
                        <option value="daily" <?php selected( $mode, 'daily' ); ?>><?php echo esc_html__( 'updates the day\'s post (new post each day)', 'presshub-ai-editor' ); ?></option>
                        <option value="rolling" <?php selected( $mode, 'rolling' ); ?>><?php echo esc_html__( 'updates one single post', 'presshub-ai-editor' ); ?></option>
                        <option value="each" <?php selected( $mode, 'each' ); ?>><?php echo esc_html__( 'creates a new post', 'presshub-ai-editor' ); ?></option>
                    </select>
                </label>
                &nbsp; <label><?php echo esc_html__( 'New posts are', 'presshub-ai-editor' ); ?>
                    <select name="presshub_ai_social_trending_post_status" id="presshub_ai_social_trending_post_status">
                        <option value="publish" <?php selected( $status, 'publish' ); ?>><?php echo esc_html__( 'published', 'presshub-ai-editor' ); ?></option>
                        <option value="draft" <?php selected( $status, 'draft' ); ?>><?php echo esc_html__( 'saved as drafts', 'presshub-ai-editor' ); ?></option>
                    </select>
                </label>
                <p class="description"><?php echo esc_html__( 'The category is created if it does not exist. In the title, {date} and {time} are replaced with the refresh date and time. X and TikTok posts are embedded; Instagram and Facebook posts are quoted with a link.', 'presshub-ai-editor' ); ?></p>
            </td>
        </tr>
        <?php
    }

    public function render_briefing_tts_model_field() {
        $option = 'presshub_ai_briefing_tts_model';
        $value  = PressHub_AI_Settings_Storage::get_briefing_tts_model();
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text code" placeholder="<?php echo esc_attr__( 'Leave empty to use speech provider default', 'presshub-ai-editor' ); ?>" />
        <p class="description">
            <?php echo esc_html__( 'Model ID used for speech synthesis (e.g. gemini-2.5-flash-preview-tts, gemini-3.1-flash-tts-preview). Leave empty to inherit the default model from the selected Speech AI Provider.', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    public function render_briefing_tts_timeout_field() {
        $option = 'presshub_ai_briefing_tts_timeout';
        $value  = PressHub_AI_Settings_Storage::get_briefing_tts_timeout();
        ?>
        <input type="number" min="60" max="900" step="10" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" />
        <p class="description">
            <?php echo esc_html__( 'Maximum time in seconds to wait for speech synthesis response (60 to 900 seconds). Default 300s. Increase for lengthy multi-topic podcast scripts.', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    /**
     * Issue #80 — Settings-First: granular TTS payload debug toggle.
     * When enabled, every Gemini TTS API call appends a JSON entry to
     * the protected log folder (tts channel) via the
     * `presshub_ai_tts_payload_log` action, so operators can diagnose
     * voice drift and unexpected voice allocation without code changes.
     * Default off so production log size is preserved.
     */
    public function render_log_tts_payloads_field() {
        $option = 'presshub_ai_log_tts_payloads';
        $value  = PressHub_AI_Settings_Storage::get_log_tts_payloads();
        ?>
        <input type="hidden" name="<?php echo self::esc_attr_safe( $option ); ?>" value="0" />
        <label>
            <input type="checkbox" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="1" <?php checked( true, $value ); ?> />
            <?php echo esc_html__( 'Log every Gemini TTS API call (endpoint, masked headers, speaker-voice mapping, prompt text, full JSON request body, response metadata) to the protected log folder (tts channel).', 'presshub-ai-editor' ); ?>
        </label>
        <p class="description">
            <?php echo esc_html__( 'Raw debug dump for developers. Generation records already keep every TTS call (model, voices, request body without audio, status, duration, audio length) for editors, so leave this off unless you need the raw file. Use it only when diagnosing voice drift or unexpected voice allocation during podcast synthesis. Disable in production to keep log file size bounded. Mirror events are also fired on the presshub_ai_tts_payload_log action for custom tooling.', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    public function render_social_meta_enabled_field() {
        $option = 'presshub_ai_social_meta_enabled';
        $value  = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_social_meta_enabled() : true;
        ?>
        <input type="hidden" name="<?php echo self::esc_attr_safe( $option ); ?>" value="0" />
        <label>
            <input type="checkbox" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="1" <?php checked( true, $value ); ?> />
            <?php echo esc_html__( 'Output OpenGraph, Twitter Card, and meta description tags in wp_head.', 'presshub-ai-editor' ); ?>
        </label>
        <p class="description">
            <?php echo esc_html__( 'Generates og:title, og:description, og:image, og:site_name, twitter:card (summary_large_image), twitter:title, twitter:description, and twitter:image.', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    public function render_social_meta_all_posts_field() {
        $option = 'presshub_ai_social_meta_all_posts';
        $value  = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_social_meta_all_posts() : true;
        ?>
        <input type="hidden" name="<?php echo self::esc_attr_safe( $option ); ?>" value="0" />
        <label>
            <input type="checkbox" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="1" <?php checked( true, $value ); ?> />
            <?php echo esc_html__( 'Apply to all published single posts (Recommended if you do not use Yoast or RankMath).', 'presshub-ai-editor' ); ?>
        </label>
        <p class="description">
            <?php echo esc_html__( 'When checked, social metadata and title length optimization (<= 60 chars) are output on all single posts. When unchecked, metadata is only emitted for PressHub posts (Daily Briefings, Podcasts, and AI Co-Authored articles).', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    public function render_briefing_host_count_field() {
        $option = 'presshub_ai_briefing_host_count';
        $value  = PressHub_AI_Settings_Storage::get_briefing_host_count();
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <option value="1" <?php echo 1 === $value ? 'selected="selected"' : ''; ?>>
                <?php echo esc_html__( '1 Presenter (Solo Anchor / Monologue)', 'presshub-ai-editor' ); ?>
            </option>
            <option value="2" <?php echo 2 === $value ? 'selected="selected"' : ''; ?>>
                <?php echo esc_html__( '2 Presenters (Co-Hosts Duo - Default)', 'presshub-ai-editor' ); ?>
            </option>
            <option value="3" <?php echo 3 === $value ? 'selected="selected"' : ''; ?>>
                <?php echo esc_html__( '3 Presenters (Roundtable Panel)', 'presshub-ai-editor' ); ?>
            </option>
        </select>
        <p class="description">
            <?php echo esc_html__( 'Select the number of presenters for podcast dialogue synthesis.', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    public function render_briefing_host_female_field() {
        $option = 'presshub_ai_briefing_host_female';
        $value  = PressHub_AI_Settings_Storage::get_briefing_host_female();
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" />
        <p class="description"><?php echo __( 'Name of the lead presenter (Presenter 1). Default: Μαρία.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_host_male_field() {
        $option = 'presshub_ai_briefing_host_male';
        $value  = PressHub_AI_Settings_Storage::get_briefing_host_male();
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" />
        <p class="description"><?php echo __( 'Name of the co-host / commentator (Presenter 2). Default: Νίκος.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_host_tertiary_field() {
        $option = 'presshub_ai_briefing_host_tertiary';
        $value  = PressHub_AI_Settings_Storage::get_briefing_host_tertiary();
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" />
        <p class="description"><?php echo esc_html__( 'Name of the third presenter/commentator (e.g. Κώστας). Default Κώστας.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_audio_split_by_topic_field() {
        $option = 'presshub_ai_briefing_audio_split_by_topic';
        $checked = PressHub_AI_Settings_Storage::get_briefing_audio_split_by_topic();
        ?>
        <label>
            <input type="hidden" name="<?php echo self::esc_attr_safe( $option ); ?>" value="0" />
            <input type="checkbox" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="1" <?php checked( $checked, true ); ?> />
            <?php echo esc_html__( 'Generate audio separately for each topic and stitch seamlessly (Recommended)', 'presshub-ai-editor' ); ?>
        </label>
        <p class="description">
            <?php echo esc_html__( 'Splits the podcast script by topic markers ([TOPIC_START] ... [TOPIC_END]), synthesizes each section with fresh neural context, and stitches them with natural transitions. Prevents voice drift and host persona convergence on longer podcasts.', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    public function render_briefing_audio_intro_sfx_field() {
        $option = 'presshub_ai_briefing_audio_intro_sfx';
        $value  = PressHub_AI_Settings_Storage::get_briefing_audio_intro_sfx();
        $this->render_sfx_dropdown( $option, $value, __( 'Play at the very beginning of the podcast.', 'presshub-ai-editor' ) );
    }

    public function render_briefing_audio_transition_sfx_field() {
        $option = 'presshub_ai_briefing_audio_transition_sfx';
        $value  = PressHub_AI_Settings_Storage::get_briefing_audio_transition_sfx();
        $this->render_sfx_dropdown( $option, $value, __( 'Inject an audio sound effect (instead of just silence) when transitioning between topics.', 'presshub-ai-editor' ) );
    }

    public function render_briefing_audio_outro_sfx_field() {
        $option = 'presshub_ai_briefing_audio_outro_sfx';
        $value  = PressHub_AI_Settings_Storage::get_briefing_audio_outro_sfx();
        $this->render_sfx_dropdown( $option, $value, __( 'Play at the very end of the podcast.', 'presshub-ai-editor' ) );
    }

    private function render_sfx_dropdown( $option, $value, $desc ) {
        $audio_dir = plugin_dir_path( dirname( __FILE__ ) ) . 'assets/audio/';
        $audio_url = plugin_dir_url( dirname( __FILE__ ) ) . 'assets/audio/';
        $available_sfx = [
            'silence' => __( 'None', 'presshub-ai-editor' )
        ];

        if ( is_dir( $audio_dir ) ) {
            $files = array_merge( (array) glob( $audio_dir . '*.wav' ), (array) glob( $audio_dir . '*.mp3' ) );
            if ( $files ) {
                foreach ( $files as $file ) {
                    $basename = basename( $file );
                    $name_only = pathinfo( $file, PATHINFO_FILENAME );
                    $label    = ucwords( str_replace( [ '-', '_' ], ' ', $name_only ) ) . ' (' . strtoupper( pathinfo( $file, PATHINFO_EXTENSION ) ) . ')';
                    $available_sfx[ $basename ] = $label;
                }
            }
        }
        ?>
        <div class="ph-display-flex ph-align-items-center ph-gap-8px">
            <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" class="regular-text presshub-sfx-select" data-audio-url="<?php echo esc_url( $audio_url ); ?>">
                <?php foreach ( $available_sfx as $sfx_key => $sfx_label ) : ?>
                    <option value="<?php echo self::esc_attr_safe( $sfx_key ); ?>" <?php selected( $value, $sfx_key ); ?>>
                        <?php echo self::esc_html_safe( $sfx_label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="button button-secondary presshub-sfx-preview-btn">▶ Preview</button>
        </div>
        <p class="description"><?php echo esc_html( $desc ) . ' ' . esc_html__( 'Note: Custom files must be placed in the plugin assets/audio/ folder.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    /**
     * Keeps a stored voice selectable when the speech engine no longer lists
     * it (#256). Without this the browser shows the engine's first voice and
     * the next save stores it although nobody picked it.
     *
     * @param string $selected      Stored voice name.
     * @param array  $female_voices Voices of the current engine, keyed by name.
     * @param array  $male_voices   Voices of the current engine, keyed by name.
     * @return string Escaped <option> HTML, or '' when the voice is listed.
     */
    public static function unavailable_voice_option( string $selected, array $female_voices, array $male_voices ): string {
        if ( '' === $selected || isset( $female_voices[ $selected ] ) || isset( $male_voices[ $selected ] ) ) {
            return '';
        }
        /* translators: %s: stored voice name */
        $label = sprintf( __( '%s (not available for this engine)', 'presshub-ai-editor' ), $selected );
        return '<option value="' . esc_attr( $selected ) . '" selected="selected">' . esc_html( $label ) . '</option>';
    }

    public function render_briefing_voice_female_field() {
        $option   = 'presshub_ai_briefing_voice_female';
        $selected = PressHub_AI_Settings_Storage::get_voice_female();
        $synthesizer = class_exists( 'PressHub_AI_Audio_Synthesizer' ) ? new PressHub_AI_Audio_Synthesizer() : null;
        $engine   = PressHub_AI_Settings_Storage::get_briefing_tts_engine();
        $engine_id = ( 'gemini' === $engine || '' === $engine ) ? 'gemini-2.5' : $engine;
        $voices    = $synthesizer ? $synthesizer->get_available_voices( $engine_id ) : [];
        $female_voices = $voices['female'] ?? [];
        $male_voices   = $voices['male'] ?? [];
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <?php echo self::unavailable_voice_option( (string) $selected, $female_voices, $male_voices ); ?>
            <?php if ( ! empty( $female_voices ) ) : ?>
                <optgroup label="<?php echo esc_attr__( 'Female Voices', 'presshub-ai-editor' ); ?>">
                    <?php foreach ( $female_voices as $key => $v ) : ?>
                        <option value="<?php echo self::esc_attr_safe( $key ); ?>" <?php selected( $selected, $key ); ?>>
                            <?php echo self::esc_html_safe( $v['label'] ?? $key ); ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endif; ?>
            <?php if ( ! empty( $male_voices ) ) : ?>
                <optgroup label="<?php echo esc_attr__( 'Male Voices', 'presshub-ai-editor' ); ?>">
                    <?php foreach ( $male_voices as $key => $v ) : ?>
                        <option value="<?php echo self::esc_attr_safe( $key ); ?>" <?php selected( $selected, $key ); ?>>
                            <?php echo self::esc_html_safe( $v['label'] ?? $key ); ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endif; ?>
        </select>
        <p class="description"><?php echo esc_html__( 'Voice persona for Presenter 1. Default: Kore.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_voice_male_field() {
        $option   = 'presshub_ai_briefing_voice_male';
        $selected = PressHub_AI_Settings_Storage::get_voice_male();
        $synthesizer = class_exists( 'PressHub_AI_Audio_Synthesizer' ) ? new PressHub_AI_Audio_Synthesizer() : null;
        $engine   = PressHub_AI_Settings_Storage::get_briefing_tts_engine();
        $engine_id = ( 'gemini' === $engine || '' === $engine ) ? 'gemini-2.5' : $engine;
        $voices    = $synthesizer ? $synthesizer->get_available_voices( $engine_id ) : [];
        $female_voices = $voices['female'] ?? [];
        $male_voices   = $voices['male'] ?? [];
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <?php echo self::unavailable_voice_option( (string) $selected, $female_voices, $male_voices ); ?>
            <?php if ( ! empty( $male_voices ) ) : ?>
                <optgroup label="<?php echo esc_attr__( 'Male Voices', 'presshub-ai-editor' ); ?>">
                    <?php foreach ( $male_voices as $key => $v ) : ?>
                        <option value="<?php echo self::esc_attr_safe( $key ); ?>" <?php selected( $selected, $key ); ?>>
                            <?php echo self::esc_html_safe( $v['label'] ?? $key ); ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endif; ?>
            <?php if ( ! empty( $female_voices ) ) : ?>
                <optgroup label="<?php echo esc_attr__( 'Female Voices', 'presshub-ai-editor' ); ?>">
                    <?php foreach ( $female_voices as $key => $v ) : ?>
                        <option value="<?php echo self::esc_attr_safe( $key ); ?>" <?php selected( $selected, $key ); ?>>
                            <?php echo self::esc_html_safe( $v['label'] ?? $key ); ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endif; ?>
        </select>
        <p class="description"><?php echo esc_html__( 'Voice persona for Presenter 2. Default: Fenrir.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_voice_tertiary_field() {
        $option   = 'presshub_ai_briefing_voice_tertiary';
        $selected = PressHub_AI_Settings_Storage::get_voice_tertiary();
        $synthesizer = class_exists( 'PressHub_AI_Audio_Synthesizer' ) ? new PressHub_AI_Audio_Synthesizer() : null;
        $engine   = PressHub_AI_Settings_Storage::get_briefing_tts_engine();
        $engine_id = ( 'gemini' === $engine || '' === $engine ) ? 'gemini-2.5' : $engine;
        $voices    = $synthesizer ? $synthesizer->get_available_voices( $engine_id ) : [];
        $female_voices = $voices['female'] ?? [];
        $male_voices   = $voices['male'] ?? [];
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <?php echo self::unavailable_voice_option( (string) $selected, $female_voices, $male_voices ); ?>
            <?php if ( ! empty( $male_voices ) ) : ?>
                <optgroup label="<?php echo esc_attr__( 'Male Voices', 'presshub-ai-editor' ); ?>">
                    <?php foreach ( $male_voices as $key => $v ) : ?>
                        <option value="<?php echo self::esc_attr_safe( $key ); ?>" <?php selected( $selected, $key ); ?>>
                            <?php echo self::esc_html_safe( $v['label'] ?? $key ); ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endif; ?>
            <?php if ( ! empty( $female_voices ) ) : ?>
                <optgroup label="<?php echo esc_attr__( 'Female Voices', 'presshub-ai-editor' ); ?>">
                    <?php foreach ( $female_voices as $key => $v ) : ?>
                        <option value="<?php echo self::esc_attr_safe( $key ); ?>" <?php selected( $selected, $key ); ?>>
                            <?php echo self::esc_html_safe( $v['label'] ?? $key ); ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endif; ?>
        </select>
        <p class="description"><?php echo esc_html__( 'Voice persona for Presenter 3. Default: Puck.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_voice_speed_field() {
        $option = 'presshub_ai_briefing_voice_speed';
        $value  = (float) get_option( $option, 1.0 );
        ?>
        <input type="number" min="0.85" max="1.25" step="0.05" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" />
        <p class="description"><?php echo __( 'Speaking rate multiplier (0.85 to 1.25). Default 1.00.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_voice_pitch_field() {
        $option = 'presshub_ai_briefing_voice_pitch';
        $value  = (float) get_option( $option, 0.0 );
        ?>
        <input type="number" min="-4.0" max="4.0" step="0.5" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" />
        <p class="description"><?php echo __( 'Voice pitch adjustment (-4.0 to 4.0 semitones). Default 0.0.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_tts_turn_pause_ms_field() {
        $option = 'presshub_ai_briefing_tts_turn_pause_ms';
        $value  = PressHub_AI_Settings_Storage::get_briefing_tts_turn_pause_ms();
        ?>
        <input type="number" min="0" max="2000" step="50" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( (string) $value ); ?>" class="small-text" />
        <p class="description">
            <?php echo esc_html__( 'Silence duration in milliseconds inserted between consecutive dialogue turns (0 to 2000 ms). Default 250 ms.', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    public function render_briefing_tts_topic_pause_ms_field() {
        $option = 'presshub_ai_briefing_tts_topic_pause_ms';
        $value  = PressHub_AI_Settings_Storage::get_briefing_tts_topic_pause_ms();
        ?>
        <input type="number" min="0" max="5000" step="50" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( (string) $value ); ?>" class="small-text" />
        <p class="description">
            <?php echo esc_html__( 'Silence duration in milliseconds inserted between podcast topics during audio stitching (0 to 5000 ms). Default 600 ms.', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    public function render_briefing_tts_request_delay_ms_field() {
        $option = 'presshub_ai_briefing_tts_request_delay_ms';
        $value  = PressHub_AI_Settings_Storage::get_briefing_tts_request_delay_ms();
        ?>
        <input type="number" min="0" max="5000" step="50" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( (string) $value ); ?>" class="small-text" />
        <p class="description">
            <?php echo esc_html__( 'Throttle delay in milliseconds between consecutive TTS API requests to prevent rate limiting (0 to 5000 ms). Default 250 ms.', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    public function render_briefing_tts_max_retries_field() {
        $option = 'presshub_ai_briefing_tts_max_retries';
        $value  = PressHub_AI_Settings_Storage::get_briefing_tts_max_retries();
        ?>
        <input type="number" min="0" max="5" step="1" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( (string) $value ); ?>" class="small-text" />
        <p class="description">
            <?php echo esc_html__( 'Maximum automatic retries upon encountering HTTP 429 rate limit or 5xx server errors during synthesis (0 to 5). Default 2.', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    public function render_briefing_tts_retry_backoff_ms_field() {
        $option = 'presshub_ai_briefing_tts_retry_backoff_ms';
        $value  = PressHub_AI_Settings_Storage::get_briefing_tts_retry_backoff_ms();
        ?>
        <input type="number" min="500" max="15000" step="500" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( (string) $value ); ?>" class="small-text" />
        <p class="description">
            <?php echo esc_html__( 'Initial exponential backoff duration in milliseconds before retrying a failed speech request (500 to 15000 ms). Default 2000 ms. Used for 5xx and connection errors, and for a 429 that carries no retry delay.', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    public function render_briefing_tts_requests_per_minute_field() {
        $option = 'presshub_ai_briefing_tts_requests_per_minute';
        $value  = PressHub_AI_Settings_Storage::get_briefing_tts_requests_per_minute();
        ?>
        <input type="number" min="0" max="600" step="1" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( (string) $value ); ?>" class="small-text" />
        <p class="description">
            <?php echo esc_html__( 'Maximum Gemini speech requests per model per minute, shared by podcast synthesis, TikTok narration and the test button. Synthesis waits instead of exceeding it. Set it just below your Google AI Studio limit (Tier 1: 10). 0 disables pacing. Default 9.', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    public function render_briefing_tts_max_retry_wait_ms_field() {
        $option = 'presshub_ai_briefing_tts_max_retry_wait_ms';
        $value  = PressHub_AI_Settings_Storage::get_briefing_tts_max_retry_wait_ms();
        ?>
        <input type="number" min="1000" max="120000" step="1000" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( (string) $value ); ?>" class="small-text" />
        <p class="description">
            <?php echo esc_html__( 'When Gemini answers 429 with a retry delay ("Please retry in 43s"), synthesis waits that long before retrying, up to this cap (1000 to 120000 ms). Default 60000 ms. Per-day quota errors are never retried.', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    public function render_briefing_tts_cron_retry_delay_min_field() {
        $option = 'presshub_ai_briefing_tts_cron_retry_delay_min';
        $value  = PressHub_AI_Settings_Storage::get_briefing_tts_cron_retry_delay_min();
        ?>
        <input type="number" min="0" max="120" step="1" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( (string) $value ); ?>" class="small-text" />
        <p class="description">
            <?php echo esc_html__( 'If scheduled audio synthesis fails, retry it once after this many minutes. Turns already synthesized are reused from the cache. Not retried for configuration errors or a per-day quota. 0 disables. Default 5.', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    public function render_briefing_tts_style_field() {
        $option      = 'presshub_ai_briefing_tts_style';
        $selected    = (string) get_option( $option, PressHub_AI_Settings_Migration::default_briefing_tts_style() );
        $synthesizer = class_exists( 'PressHub_AI_Audio_Synthesizer' ) ? new PressHub_AI_Audio_Synthesizer() : null;
        $styles      = $synthesizer ? $synthesizer->get_available_styles() : [];
        if ( empty( $styles ) ) {
            $styles = [
                'formal'      => [ 'label' => __( 'Formal & Broadcast (Επίσημο & Επαγγελματικό)', 'presshub-ai-editor' ) ],
                'natural'     => [ 'label' => __( 'Natural & Warm (Φυσικό & Φιλικό)', 'presshub-ai-editor' ) ],
                'cheerful'    => [ 'label' => __( 'Cheerful & Bright (Χαρούμενο & Φωτεινό)', 'presshub-ai-editor' ) ],
                'storyteller' => [ 'label' => __( 'Storyteller & Narrative (Αφήγηση & Παραμύθι)', 'presshub-ai-editor' ) ],
                'calm'        => [ 'label' => __( 'Calm & Soothing (Ήρεμο & Γαλήνιο)', 'presshub-ai-editor' ) ],
                'dramatic'    => [ 'label' => __( 'Dramatic & Intense (Δραματικό & Έντονο)', 'presshub-ai-editor' ) ],
                'poetic'      => [ 'label' => __( 'Poetic & Lyrical (Ποιητικό & Λυρικό)', 'presshub-ai-editor' ) ],
                'epic'        => [ 'label' => __( 'Epic & Classical (Επικό & Αρχαιοπρεπές)', 'presshub-ai-editor' ) ],
                'whisper'     => [ 'label' => __( 'Gentle Whisper (Ψίθυρος)', 'presshub-ai-editor' ) ],
                'energetic'   => [ 'label' => __( 'Energetic & Dynamic (Δυναμικό & Ενθουσιώδες)', 'presshub-ai-editor' ) ],
                'custom'      => [ 'label' => __( 'Custom Prompt Instruction (Προσαρμοσμένη Οδηγία)', 'presshub-ai-editor' ) ],
            ];
        }
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <?php foreach ( $styles as $key => $style ) : ?>
                <option value="<?php echo self::esc_attr_safe( $key ); ?>" <?php echo $selected === $key ? 'selected="selected"' : ''; ?>>
                    <?php echo self::esc_html_safe( $style['label'] ?? ucfirst( $key ) ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php echo __( 'Select emotional tone and delivery cadence for Gemini audio speech synthesis (logosAI system).', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_tts_custom_style_field() {
        $option = 'presshub_ai_briefing_tts_custom_style';
        $value  = (string) get_option( $option, PressHub_AI_Settings_Migration::default_briefing_tts_custom_style() );
        ?>
        <textarea name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" rows="3" class="large-text" placeholder="<?php echo esc_attr__( 'e.g. Say in an energetic, radio-broadcaster style with rapid pace and enthusiasm in Greek:', 'presshub-ai-editor' ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
        <p class="description"><?php echo __( 'Custom prompt prefix prepended before spoken text when Style is set to "Custom Prompt Instruction".', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_tts_payload_mode_field() {
        $option   = 'presshub_ai_briefing_tts_payload_mode';
        $selected = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_tts_payload_mode() : 'structured';
        $modes    = [
            'structured'         => [
                'label' => __( 'Official Gemini 3.8 TTS REST Standard (speech_metadata.style) — Recommended', 'presshub-ai-editor' ),
                'desc'  => __( 'Strictly adheres to Google\'s Gemini 3.8 TTS REST specification. Turn-level speech_metadata controls style, speed, and pitch without top-level system instructions. Verbatim dialogue in parts eliminates prompt narration.', 'presshub-ai-editor' ),
            ],
            'hybrid_instruction' => [
                'label' => __( 'Hybrid / System Instruction Override (speech_metadata + systemInstruction)', 'presshub-ai-editor' ),
                'desc'  => __( 'Sends turn-level speech_metadata AND passes a top-level systemInstruction directive block with {speaker1}, {speaker2}, {speed}, and {pitch} placeholders resolved.', 'presshub-ai-editor' ),
            ],
            'legacy'             => [
                'label' => __( 'Legacy Prepended Instruction (instruction text prepended before script — Deprecated)', 'presshub-ai-editor' ),
                'desc'  => __( 'Old format: prepends prompt instruction directly into script text. (Note: May be read aloud by Gemini 3.8 TTS models).', 'presshub-ai-editor' ),
            ],
        ];
        if ( ! isset( $modes[ $selected ] ) ) {
            $selected = 'structured';
        }
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" class="regular-text">
            <?php foreach ( $modes as $key => $mode ) : ?>
                <option value="<?php echo self::esc_attr_safe( $key ); ?>" <?php echo $selected === $key ? 'selected="selected"' : ''; ?>>
                    <?php echo self::esc_html_safe( $mode['label'] ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php echo esc_html__( 'Controls how prompts, delivery instructions, speaker personas, speed, and pitch are formatted when calling the Gemini Speech API.', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    public function render_briefing_tts_system_instruction_field() {
        $option  = 'presshub_ai_briefing_tts_system_instruction';
        $current = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_tts_system_instruction() : '';
        $default = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::default_briefing_tts_system_instruction() : '';
        ?>
        <textarea name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" rows="5" class="large-text code" placeholder="<?php echo esc_attr( $default ); ?>"><?php echo esc_textarea( $current ); ?></textarea>
        <p class="description">
            <?php echo esc_html__( 'System instruction template passed to the Gemini model for speech synthesis. Leave empty to use the built-in external default template.', 'presshub-ai-editor' ); ?>
            <br />
            <strong><?php echo esc_html__( 'Supported placeholders:', 'presshub-ai-editor' ); ?></strong> <code>{style_instruction}</code>, <code>{speed}</code>, <code>{pitch}</code>, <code>{speaker1}</code>, <code>{speaker2}</code>.
        </p>
        <p>
            <button type="button" class="button button-secondary" id="presshub-restore-default-tts-instruction" data-default="<?php echo esc_attr( $default ); ?>">
                <?php echo esc_html__( 'Restore Default System Instruction', 'presshub-ai-editor' ); ?>
            </button>
        </p>
        <?php
    }

    public function render_gemini_tts_tester() {
        require_once __DIR__ . '/class-api-client.php';
        $client       = new PressHub_AI_API_Client();
        $initial_text = "[Presenter 1]: Καλημέρα σας από το PressHub AI. Αυτή είναι μια ζωντανή δοκιμή παραγωγής φωνής.\n\n[Presenter 2]: Επιβεβαιώνουμε τη φυσική ροή του διαλόγου, το ρυθμό εκφώνησης και τον επαγγελματικό τόνο.";
        $p1_voice     = PressHub_AI_Settings_Storage::get_voice_female();
        $p2_voice     = PressHub_AI_Settings_Storage::get_voice_male();
        $multi_speakers = [
            [
                'speaker'     => 'Presenter 1',
                'voiceConfig' => [
                    'prebuiltVoiceConfig' => [ 'voiceName' => $p1_voice ],
                ],
            ],
            [
                'speaker'     => 'Presenter 2',
                'voiceConfig' => [
                    'prebuiltVoiceConfig' => [ 'voiceName' => $p2_voice ],
                ],
            ],
        ];
        $initial_payload = $client->build_gemini_tts_request_body(
            $initial_text,
            $p1_voice,
            true,
            $multi_speakers,
            PressHub_AI_Settings_Storage::get_briefing_tts_style(),
            PressHub_AI_Settings_Storage::get_voice_speed(),
            PressHub_AI_Settings_Storage::get_voice_pitch(),
            PressHub_AI_Settings_Storage::get_briefing_tts_payload_mode(),
            PressHub_AI_Settings_Storage::get_briefing_tts_system_instruction()
        );
        $initial_json = wp_json_encode( $initial_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        ?>
        <div class="ph-margin-top-25px ph-padding-20px ph-background-fff ph-border-1px-solid-ccd0d4 ph-border-radius-6px ph-box-shadow-0-1px-3px-rgba-0-0-0-0_05 presshub-tts-tester-card" id="presshub-tts-tester-card">
            <div class="ph-display-flex ph-align-items-center ph-justify-content-space-between ph-margin-bottom-15px ph-border-bottom-1px-solid-e2e4e7 ph-padding-bottom-12px ph-flex-wrap-wrap ph-gap-10px">
                <div>
                    <h3 class="ph-margin-0-0-5px-0 ph-font-size-16px ph-display-flex ph-align-items-center ph-gap-8px">
                        <span class="ph-color-2271b1 dashicons dashicons-format-audio"></span>
                        <?php echo esc_html__( 'Gemini Speech API JSON Payload Inspector & Live Audio Tester', 'presshub-ai-editor' ); ?>
                    </h3>
                    <p class="ph-margin-0 description">
                        <?php echo esc_html__( 'Inspect the exact JSON request sent to Gemini (gemini-3.8-flash-lite-tts / native audio) and test speech synthesis with the selected voice personas, speed, pitch, and delivery style.', 'presshub-ai-editor' ); ?>
                    </p>
                </div>
                <div class="ph-display-flex ph-align-items-center ph-gap-8px">
                    <button type="button" class="button button-secondary" id="presshub-preview-tts-payload-btn">
                        <span class="ph-vertical-align-text-top dashicons dashicons-visibility"></span>
                        <?php echo esc_html__( 'Update JSON Preview', 'presshub-ai-editor' ); ?>
                    </button>
                    <button type="button" class="button button-primary" id="presshub-test-tts-btn">
                        <span class="ph-vertical-align-text-top dashicons dashicons-controls-play"></span>
                        <?php echo esc_html__( 'Test Speech Synthesis', 'presshub-ai-editor' ); ?>
                    </button>
                </div>
            </div>

            <!-- Test Options & Dialogue Editor -->
            <div class="ph-display-grid ph-grid-template-columns-1fr-1fr ph-gap-20px ph-margin-bottom-15px">
                <div>
                    <label for="presshub-tts-test-text" class="ph-font-weight-600 ph-display-block ph-margin-bottom-5px">
                        <?php echo esc_html__( 'Test Dialogue Script:', 'presshub-ai-editor' ); ?>
                    </label>
                    <textarea id="presshub-tts-test-text" rows="4" class="ph-font-family-monospace ph-font-size-13px large-text" placeholder="<?php echo esc_attr__( 'Enter test script or leave empty for default Greek dialogue...', 'presshub-ai-editor' ); ?>"><?php echo esc_textarea( $initial_text ); ?></textarea>
                    <div class="ph-display-flex ph-gap-15px ph-margin-top-8px ph-align-items-center">
                        <label>
                            <input type="radio" name="presshub_tts_test_hosts" value="2" checked="checked" />
                            <?php echo esc_html__( '2 Hosts (Presenter 1 & 2)', 'presshub-ai-editor' ); ?>
                        </label>
                        <label>
                            <input type="radio" name="presshub_tts_test_hosts" value="1" />
                            <?php echo esc_html__( 'Single Host (Presenter 1)', 'presshub-ai-editor' ); ?>
                        </label>
                    </div>
                </div>

                <div>
                    <label class="ph-font-weight-600 ph-display-block ph-margin-bottom-5px">
                        <?php echo esc_html__( 'Live Synthesis Audio Player & Status:', 'presshub-ai-editor' ); ?>
                    </label>
                    <div id="presshub-tts-player-wrap" class="ph-background-f6f7f7 ph-border-1px-solid-dcdcde ph-border-radius-4px ph-padding-15px ph-min-height-90px ph-display-flex ph-flex-direction-column ph-justify-content-center">
                        <div id="presshub-tts-test-idle" class="ph-color-646970 ph-font-size-13px ph-text-align-center">
                            <?php echo esc_html__( 'Click "Test Speech Synthesis" above to generate sample audio using the configured Gemini model, voices, speed, pitch, and style.', 'presshub-ai-editor' ); ?>
                        </div>
                        <div id="presshub-tts-test-loading" class="ph-display-none ph-text-align-center">
                            <span class="ph-float-none ph-margin-0-5px-0-0 spinner is-active"></span>
                            <span class="ph-font-weight-500 ph-color-2271b1"><?php echo esc_html__( 'Synthesizing speech via Gemini API...', 'presshub-ai-editor' ); ?></span>
                        </div>
                        <div id="presshub-tts-test-result" class="ph-display-none">
                            <audio id="presshub-tts-test-audio-player" controls class="ph-width-100pct ph-margin-bottom-8px"></audio>
                            <div class="ph-display-flex ph-justify-content-space-between ph-align-items-center ph-font-size-12px ph-color-50575e">
                                <span id="presshub-tts-test-metrics"></span>
                                <a id="presshub-tts-download-link" href="#" download="gemini-tts-test.wav" class="ph-display-none button button-small">
                                    <?php echo esc_html__( 'Download Audio (WAV)', 'presshub-ai-editor' ); ?>
                                </a>
                            </div>
                        </div>
                        <div id="presshub-tts-test-error" class="ph-display-none ph-margin-8px-0-0-0 notice notice-error inline">
                            <p class="ph-margin-4px-0 ph-font-size-12px"></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Full JSON Request Body Inspection -->
            <div>
                <div class="ph-display-flex ph-justify-content-space-between ph-align-items-center ph-margin-bottom-6px">
                    <label for="presshub-tts-payload-preview" class="ph-font-weight-600 ph-font-size-13px">
                        <?php echo esc_html__( 'Complete JSON Message Sent to Gemini Speech Endpoint:', 'presshub-ai-editor' ); ?>
                    </label>
                    <button type="button" class="button button-small" id="presshub-copy-tts-json-btn">
                        <?php echo esc_html__( 'Copy JSON', 'presshub-ai-editor' ); ?>
                    </button>
                </div>
                <pre id="presshub-tts-payload-preview" class="ph-background-1e1e1e ph-color-d4d4d4 ph-padding-12px-15px ph-border-radius-4px ph-max-height-380px ph-overflow-y-auto ph-font-family-consolas-monaco-monospace ph-font-size-12px ph-line-height-1_5 ph-margin-0 ph-white-space-pre-wrap ph-word-break-break-all"><?php echo esc_html( $initial_json ); ?></pre>
            </div>
        </div>
        <?php
    }

    public function render_briefing_text_category_field() {
        $option   = 'presshub_ai_briefing_text_category';
        $selected = (int) get_option( $option, 0 );
        if ( function_exists( 'wp_dropdown_categories' ) ) {
            wp_dropdown_categories( [
                'taxonomy'          => 'category',
                'hide_empty'        => false,
                'name'              => $option,
                'id'                => $option,
                'selected'          => $selected,
                'hierarchical'      => true,
                'show_option_none'  => __( 'None (Default Category)', 'presshub-ai-editor' ),
                'class'             => 'presshub-category-dropdown',
            ] );
        } else {
            // Simple fallback for unit tests where WordPress functions are unavailable
            echo '<select name="' . esc_attr( $option ) . '" id="' . esc_attr( $option ) . '" class="presshub-category-dropdown">';
            echo '<option value="0">' . esc_html__( 'None (Default Category)', 'presshub-ai-editor' ) . '</option>';
            echo '</select>';
        }
        echo '<p class="description">' . esc_html__( 'WordPress post category assigned to text briefing posts.', 'presshub-ai-editor' ) . '</p>';
    }

    public function render_briefing_podcast_category_field() {
        $option   = 'presshub_ai_briefing_podcast_category';
        $selected = (int) get_option( $option, 0 );
        if ( function_exists( 'wp_dropdown_categories' ) ) {
            wp_dropdown_categories( [
                'taxonomy'          => 'category',
                'hide_empty'        => false,
                'name'              => $option,
                'id'                => $option,
                'selected'          => $selected,
                'hierarchical'      => true,
                'show_option_none'  => __( 'None (Default Category)', 'presshub-ai-editor' ),
                'class'             => 'presshub-category-dropdown',
            ] );
        } else {
            // Simple fallback for unit tests where WordPress functions are unavailable
            echo '<select name="' . esc_attr( $option ) . '" id="' . esc_attr( $option ) . '" class="presshub-category-dropdown">';
            echo '<option value="0">' . esc_html__( 'None (Default Category)', 'presshub-ai-editor' ) . '</option>';
            echo '</select>';
        }
        echo '<p class="description">' . esc_html__( 'WordPress post category assigned to synthesized podcast posts.', 'presshub-ai-editor' ) . '</p>';
    }


    public function render_briefing_text_status_field() {
        $option   = 'presshub_ai_briefing_text_status';
        $selected = (string) get_option( $option, 'pending' );
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <option value="pending" <?php echo 'pending' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'Pending Review', 'presshub-ai-editor' ); ?></option>
            <option value="draft" <?php echo 'draft' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'Draft', 'presshub-ai-editor' ); ?></option>
            <option value="publish" <?php echo 'publish' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'Publish Immediately', 'presshub-ai-editor' ); ?></option>
        </select>
        <p class="description"><?php echo __( 'Default status for newly generated morning text briefing posts.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    /**
     * Issue #65 — Settings-First: editor for the title prefix prepended to
     * the generated Text Story post title. The prefix is read via
     * PressHub_AI_Settings_Storage::get_briefing_text_title_prefix() in
     * PressHub_AI_News_Curator::compose_text_story_title().
     */
    public function render_briefing_text_title_prefix_field() {
        $option = 'presshub_ai_briefing_text_title_prefix';
        $value  = PressHub_AI_Settings_Storage::get_briefing_text_title_prefix();
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" maxlength="60" placeholder="<?php echo esc_attr__( 'e.g. Πρωινή Ενημέρωση:', 'presshub-ai-editor' ); ?>" />
        <!-- Issue #92 — translatable description strings must not contain raw HTML tags.
             The previous wording mentioned the curator's &lt;h1&gt; tag literally, which the
             browser parsed as a real heading and which then consumed the rest of the
             &lt;p&gt; paragraph (rendering "already contains it." as an oversized bold
             heading). The fix uses the safe inline-code form so the literal renders
             as documentation, not as a parsed element. -->
        <p class="description"><?php echo __( 'Editorial prefix prepended to the generated Text Story post title (e.g. "Πρωινή Ενημέρωση:"). Leave blank to use the curator\'s headline verbatim. Max 60 characters. Default: "Πρωινή Ενημέρωση:". A duplicate-prefix collision guard prevents the same prefix from appearing twice when the curator\'s <code>&lt;h1&gt;</code> headline already contains it.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    /**
     * Issue #65 — Settings-First: editor for the date() format token
     * appended after the title prefix and headline. Empty string disables
     * the date suffix.
     */
    public function render_briefing_text_title_date_format_field() {
        $option = 'presshub_ai_briefing_text_title_date_format';
        $value  = PressHub_AI_Settings_Storage::get_briefing_text_title_date_format();
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" maxlength="30" placeholder="<?php echo esc_attr__( 'e.g. d/m/Y', 'presshub-ai-editor' ); ?>" />
        <p class="description"><?php echo __( 'date() format appended after the title prefix and headline (e.g. "d/m/Y" → 26/08/2026). Leave blank to omit the date. Default: "d/m/Y". Tokens are validated by PHP\'s date() function before being saved.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_noon_text_title_prefix_field() {
        $option = 'presshub_ai_briefing_noon_text_title_prefix';
        $value  = PressHub_AI_Settings_Storage::get_briefing_noon_text_title_prefix();
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" maxlength="60" placeholder="<?php echo esc_attr__( 'e.g. Μεσημβρινή Ενημέρωση:', 'presshub-ai-editor' ); ?>" />
        <p class="description"><?php echo __( 'Editorial prefix prepended to the generated Noon Text Story post title (e.g. "Μεσημβρινή Ενημέρωση:"). Leave blank to use the curator\'s headline verbatim. Max 60 characters. Default: "Μεσημβρινή Ενημέρωση:".', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_podcast_title_prefix_field() {
        $option = 'presshub_ai_briefing_podcast_title_prefix';
        $value  = PressHub_AI_Settings_Storage::get_briefing_podcast_title_prefix();
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" maxlength="60" placeholder="<?php echo esc_attr__( 'e.g. Πρωινό Podcast Ενημέρωσης - ', 'presshub-ai-editor' ); ?>" />
        <p class="description"><?php echo __( 'Editorial prefix prepended to the generated Morning Podcast post title (e.g. "Πρωινό Podcast Ενημέρωσης - "). Default: "Πρωινό Podcast Ενημέρωσης - ".', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_noon_podcast_title_prefix_field() {
        $option = 'presshub_ai_briefing_noon_podcast_title_prefix';
        $value  = PressHub_AI_Settings_Storage::get_briefing_noon_podcast_title_prefix();
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" maxlength="60" placeholder="<?php echo esc_attr__( 'e.g. Μεσημβρινό Podcast Ενημέρωσης - ', 'presshub-ai-editor' ); ?>" />
        <p class="description"><?php echo __( 'Editorial prefix prepended to the generated Noon Podcast post title (e.g. "Μεσημβρινό Podcast Ενημέρωσης - "). Default: "Μεσημβρινό Podcast Ενημέρωσης - ".', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    /**
     * Render an interactive media library image pool manager.
     *
     * @param string $option        WordPress option name storing comma-separated attachment IDs.
     * @param string $edition_label Label for the edition (e.g. 'Morning', 'Noon').
     * @param string $description   Help text describing the pool.
     */
    public function render_image_pool_manager( string $option, string $edition_label, string $description ) {
        $raw_value  = (string) get_option( $option, '' );
        $is_morning = 'presshub_ai_briefing_featured_image_ids_morning' === $option;
        $is_noon    = 'presshub_ai_briefing_featured_image_ids_noon' === $option;

        // Support fallback from legacy presshub_ai_briefing_featured_image_ids for morning if empty
        if ( '' === trim( $raw_value ) && $is_morning ) {
            $raw_value = (string) get_option( 'presshub_ai_briefing_featured_image_ids', '' );
        }

        $sanitized_csv = PressHub_AI_Settings_Storage::sanitize_briefing_featured_image_ids( $raw_value );
        $ids           = '' !== $sanitized_csv ? array_map( 'intval', explode( ',', $sanitized_csv ) ) : [];
        $has_images    = ! empty( $ids );
        ?>
        <div class="presshub-image-pool-manager" data-option="<?php echo esc_attr( $option ); ?>">
            <input type="hidden" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $sanitized_csv ); ?>" />

            <div class="presshub-image-pool-grid">
                <?php
                foreach ( $ids as $id ) :
                    $thumb_url = function_exists( 'wp_get_attachment_image_url' )
                        ? wp_get_attachment_image_url( $id, 'thumbnail' )
                        : false;
                    if ( ! $thumb_url && function_exists( 'wp_get_attachment_url' ) ) {
                        $thumb_url = wp_get_attachment_url( $id );
                    }
                    if ( ! $thumb_url ) {
                        $thumb_url = 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><rect width="100" height="100" fill="%23eee"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="%23999" font-size="12">#' . esc_attr( $id ) . '</text></svg>';
                    }
                    ?>
                    <div class="presshub-image-pool-item" data-id="<?php echo esc_attr( $id ); ?>">
                        <img src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php echo esc_attr( sprintf( __( 'Image #%d', 'presshub-ai-editor' ), $id ) ); ?>" />
                        <span class="presshub-image-pool-badge">#<?php echo esc_html( $id ); ?></span>
                        <button type="button" class="presshub-image-pool-remove" title="<?php echo esc_attr__( 'Remove image from pool', 'presshub-ai-editor' ); ?>" aria-label="<?php echo esc_attr__( 'Remove image', 'presshub-ai-editor' ); ?>">&times;</button>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="presshub-image-pool-empty<?php echo $has_images ? ' presshub-is-hidden' : ''; ?>">
                <p class="description"><?php echo esc_html__( 'No images in pool. Click below to select images from the Media Library.', 'presshub-ai-editor' ); ?></p>
            </div>

            <?php if ( $is_noon ) : ?>
                <p class="presshub-image-pool-fallback description<?php echo $has_images ? ' presshub-is-hidden' : ''; ?>">
                    <em><?php echo esc_html__( 'Note: If the Noon pool is empty, PressHub AI automatically falls back to the Morning pool.', 'presshub-ai-editor' ); ?></em>
                </p>
            <?php endif; ?>

            <div class="ph-margin-top-10px presshub-image-pool-actions">
                <button type="button" class="button button-secondary presshub-select-pool-images-btn">
                    <span class="ph-vertical-align-middle ph-margin-right-4px dashicons dashicons-images-alt2"></span>
                    <?php echo esc_html__( 'Select Images from Media Library', 'presshub-ai-editor' ); ?>
                </button>
                <button type="button" class="ph-margin-left-10px button button-link-delete presshub-clear-pool-images-btn<?php echo ! $has_images ? ' presshub-is-hidden' : ''; ?>">
                    <?php echo esc_html__( 'Clear All', 'presshub-ai-editor' ); ?>
                </button>
            </div>

            <p class="ph-margin-top-6px description"><?php echo esc_html( $description ); ?></p>
        </div>
        <?php
    }

    public function render_briefing_featured_image_ids_morning_field() {
        $this->render_image_pool_manager(
            'presshub_ai_briefing_featured_image_ids_morning',
            __( 'Morning', 'presshub-ai-editor' ),
            __( 'Media Library images for Morning daily briefings and audio podcasts. PressHub AI will randomly cycle through this pool to assign featured images (post thumbnails).', 'presshub-ai-editor' )
        );
    }

    public function render_briefing_featured_image_ids_noon_field() {
        $this->render_image_pool_manager(
            'presshub_ai_briefing_featured_image_ids_noon',
            __( 'Noon', 'presshub-ai-editor' ),
            __( 'Media Library images for Noon updates. If empty, PressHub AI automatically falls back to the Morning pool.', 'presshub-ai-editor' )
        );
    }

    public function render_briefing_featured_image_ids_field() {
        $this->render_briefing_featured_image_ids_morning_field();
    }

    public function render_briefing_featured_image_source_field() {
        $option   = 'presshub_ai_briefing_featured_image_source';
        $selected = PressHub_AI_Settings_Storage::get_briefing_featured_image_source();
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" class="regular-text">
            <option value="pool" <?php selected( $selected, 'pool' ); ?>><?php echo __( 'Featured Image Pool (Random Selection with Collision Avoidance)', 'presshub-ai-editor' ); ?></option>
            <option value="auto_generate" <?php selected( $selected, 'auto_generate' ); ?>><?php echo __( 'Automatic AI Image Generation (analyzing article content)', 'presshub-ai-editor' ); ?></option>
        </select>
        <p class="description"><?php echo __( 'Choose whether text briefings pick an image randomly from the Featured Image Pool or automatically synthesize a custom editorial image using the configured AI image generator (with automatic fallback to the pool if generation fails).', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_podcast_featured_image_source_field() {
        $option   = 'presshub_ai_briefing_podcast_featured_image_source';
        $selected = PressHub_AI_Settings_Storage::get_briefing_podcast_featured_image_source();
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" class="regular-text">
            <option value="pool" <?php selected( $selected, 'pool' ); ?>><?php echo __( 'Featured Image Pool (Random Selection with Collision Avoidance)', 'presshub-ai-editor' ); ?></option>
            <option value="auto_generate" <?php selected( $selected, 'auto_generate' ); ?>><?php echo __( 'Automatic AI Image Generation (analyzing podcast transcript)', 'presshub-ai-editor' ); ?></option>
        </select>
        <p class="description"><?php echo __( 'Choose whether audio podcast posts pick an image randomly from the Featured Image Pool or automatically synthesize a custom editorial image using the configured AI image generator (with automatic fallback to the pool if generation fails).', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_images_section() {
        echo '<p>' . __( 'Configure article analysis and AI image generation for producing authentic, realistic featured images based on story content.', 'presshub-ai-editor' ) . '</p>';
    }

    public function render_image_feature_enabled_field() {
        $option  = 'presshub_ai_image_feature_enabled';
        $checked = PressHub_AI_Settings_Storage::get_image_feature_enabled();
        ?>
        <label>
            <input type="checkbox" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="1" <?php checked( $checked, true ); ?> />
            <?php echo __( 'Enable AI Featured Image generation studio across post editor metaboxes and Daily Briefing Hub.', 'presshub-ai-editor' ); ?>
        </label>
        <?php
    }

    public function render_image_prompt_provider_field() {
        $option  = 'presshub_ai_image_prompt_provider';
        $current = PressHub_AI_Settings_Storage::get_image_prompt_provider();
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" class="regular-text">
            <?php echo self::get_active_providers_options( $current, __( '-- Default Text Provider --', 'presshub-ai-editor' ) ); ?>
        </select>
        <p class="description"><?php echo __( 'Select which AI provider analyzes the article content and drafts the descriptive visual prompt for the image generator.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_image_prompt_model_field() {
        $option = 'presshub_ai_image_prompt_model';
        $value  = PressHub_AI_Settings_Storage::get_image_prompt_model();
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text code" placeholder="<?php echo esc_attr__( 'Leave empty to use provider default model', 'presshub-ai-editor' ); ?>" />
        <p class="description"><?php echo __( 'Optional specific model identifier for prompt drafting (e.g. gemini-2.5-flash, gpt-4o).', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_image_generation_provider_field() {
        $option  = 'presshub_ai_image_generation_provider';
        $current = PressHub_AI_Settings_Storage::get_image_generation_provider();
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" class="regular-text">
            <?php echo self::get_active_providers_options( $current, __( '-- Auto-detect (Google Gemini or OpenAI) --', 'presshub-ai-editor' ) ); ?>
        </select>
        <p class="description"><?php echo __( 'Select the AI provider for image generation. The model configured in the chosen AI Provider record (e.g. gemini-3.1-flash-image or dall-e-3) will be used.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_image_aspect_ratio_field() {
        $option   = 'presshub_ai_image_aspect_ratio';
        $selected = PressHub_AI_Settings_Storage::get_image_aspect_ratio();
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" class="regular-text">
            <option value="16:9" <?php selected( $selected, '16:9' ); ?>><?php echo __( '16:9 Landscape (Recommended for News Featured Images)', 'presshub-ai-editor' ); ?></option>
            <option value="1:1" <?php selected( $selected, '1:1' ); ?>><?php echo __( '1:1 Square (1024x1024)', 'presshub-ai-editor' ); ?></option>
            <option value="4:3" <?php selected( $selected, '4:3' ); ?>><?php echo __( '4:3 Standard', 'presshub-ai-editor' ); ?></option>
        </select>
        <p class="description"><?php echo __( 'Target aspect ratio for synthesized featured images.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_image_analysis_prompt_field() {
        $option = 'presshub_ai_image_analysis_prompt';
        $value  = PressHub_AI_Settings_Storage::get_image_analysis_prompt();
        ?>
        <textarea name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" rows="14" class="ph-font-family-monospace ph-font-size-13px ph-line-height-1_5 large-text code"><?php echo esc_textarea( $value ); ?></textarea>
        <p class="description"><?php echo __( 'Prompt instructions used to analyze article text and compose the visual image description. Available placeholders: {title}, {content}. Leave empty to restore plugin default template.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_image_metadata_enabled_field() {
        $option  = 'presshub_ai_image_metadata_enabled';
        $enabled = PressHub_AI_Settings_Storage::get_image_metadata_enabled();
        ?>
        <input type="hidden" name="<?php echo self::esc_attr_safe( $option ); ?>" value="0" />
        <label for="presshub_ai_image_metadata_enabled">
            <input type="checkbox" name="<?php echo self::esc_attr_safe( $option ); ?>" id="presshub_ai_image_metadata_enabled" value="1" <?php checked( true, $enabled ); ?> />
            <?php echo esc_html__( 'Write descriptive Title, Alternative Text, Caption and Description for every AI-generated image.', 'presshub-ai-editor' ); ?>
        </label>
        <p class="description"><?php echo esc_html__( 'Default: Enabled. Uses the Article Analysis AI Provider to describe the image in the article\'s language and gives the file a descriptive name, so images are easy to find and reuse in the Media Library.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_seo_auto_enabled_field() {
        $option  = 'presshub_ai_seo_auto_enabled';
        $enabled = PressHub_AI_Settings_Storage::get_seo_auto_enabled();
        ?>
        <input type="hidden" name="<?php echo self::esc_attr_safe( $option ); ?>" value="0" />
        <label for="presshub_ai_seo_auto_enabled">
            <input type="checkbox" name="<?php echo self::esc_attr_safe( $option ); ?>" id="presshub_ai_seo_auto_enabled" value="1" <?php checked( true, $enabled ); ?> />
            <?php echo esc_html__( 'Automatically fill tags, meta description and focus keyphrase for Daily Briefing posts, podcasts and AI Co-Author drafts.', 'presshub-ai-editor' ); ?>
        </label>
        <p class="description"><?php echo esc_html__( 'Default: Enabled. Briefing and podcast posts get tags and an SEO excerpt when they are created. For co-author drafts the tags and excerpt are added to the editor when you insert the draft, so you can still review them before saving.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_seo_enhance_enabled_field() {
        $option  = 'presshub_ai_briefing_seo_enhance_enabled';
        $enabled = PressHub_AI_Settings_Storage::get_briefing_seo_enhance_enabled();
        ?>
        <input type="hidden" name="<?php echo self::esc_attr_safe( $option ); ?>" value="0" />
        <label for="presshub_ai_briefing_seo_enhance_enabled">
            <input type="checkbox" name="<?php echo self::esc_attr_safe( $option ); ?>" id="presshub_ai_briefing_seo_enhance_enabled" value="1" <?php checked( true, $enabled ); ?> />
            <?php echo esc_html__( 'Shape Daily Briefing posts for search: a title of at most 65 characters, short paragraphs, links to the source articles, and links to related earlier posts.', 'presshub-ai-editor' ); ?>
        </label>
        <p class="description"><?php echo esc_html__( 'Default: Enabled. Source links that do not match a harvested article are removed, a "Πηγές" list is added, the first mention of each tag links to its tag page, and a "Διαβάστε επίσης" list links up to 4 related posts.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_seo_max_tags_field() {
        $option = 'presshub_ai_seo_max_tags';
        $value  = PressHub_AI_Settings_Storage::get_seo_max_tags();
        ?>
        <input type="number" min="1" max="15" step="1" name="<?php echo self::esc_attr_safe( $option ); ?>" id="presshub_ai_seo_max_tags" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" />
        <p class="description"><?php echo esc_html__( 'Upper limit of tags assigned to a post automatically (1 - 15). Default 8. Existing site tags are reused where they fit.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_seo_review_enabled_field() {
        $option  = 'presshub_ai_seo_review_enabled';
        $enabled = PressHub_AI_Settings_Storage::get_seo_review_enabled();
        ?>
        <input type="hidden" name="<?php echo self::esc_attr_safe( $option ); ?>" value="0" />
        <label for="presshub_ai_seo_review_enabled">
            <input type="checkbox" name="<?php echo self::esc_attr_safe( $option ); ?>" id="presshub_ai_seo_review_enabled" value="1" <?php checked( true, $enabled ); ?> />
            <?php echo esc_html__( 'Report a separate SEO Score with improvement suggestions when running the AI Editorial Review.', 'presshub-ai-editor' ); ?>
        </label>
        <p class="description"><?php echo esc_html__( 'Default: Enabled. Checks headline, excerpt, tags, category, images and alt text, structure and links. The SEO score is advisory and never blocks publishing.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_podcast_include_text_field() {
        $option  = 'presshub_ai_briefing_podcast_include_text';
        $checked = PressHub_AI_Settings_Storage::get_briefing_podcast_include_text();
        ?>
        <label>
            <input type="checkbox" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="1" <?php checked( $checked, true ); ?> />
            <?php echo __( 'Include full dialogue text in podcast post content (Default: Disabled, audio player only with dialogue managed via the Podcast Editor metabox).', 'presshub-ai-editor' ); ?>
        </label>
        <?php
    }

    public function render_briefing_podcast_status_field() {
        $option   = 'presshub_ai_briefing_podcast_status';
        $selected = (string) get_option( $option, 'pending' );
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <option value="pending" <?php echo 'pending' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'Pending Review', 'presshub-ai-editor' ); ?></option>
            <option value="draft" <?php echo 'draft' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'Draft', 'presshub-ai-editor' ); ?></option>
            <option value="publish" <?php echo 'publish' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'Publish Immediately', 'presshub-ai-editor' ); ?></option>
        </select>
        <p class="description"><?php echo __( 'Default status for newly generated podcast posts.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_text_prompt_field() {
        $option  = 'presshub_ai_briefing_text_prompt';
        $default = class_exists( 'PressHub_AI_News_Curator' )
            ? ( new PressHub_AI_News_Curator() )->get_default_curation_prompt()
            : '';
        $value   = (string) get_option( $option, '' );
        ?>
        <textarea name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" rows="6" class="large-text code" placeholder="<?php echo esc_attr( __( 'Leave empty to use standard Greek editorial curation prompt...', 'presshub-ai-editor' ) ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
        <p>
            <button type="button" class="button button-secondary presshub-reset-prompt" data-target="<?php echo self::esc_attr_safe( $option ); ?>" data-default="">
                <?php echo __( 'Clear / Reset Custom Prompt', 'presshub-ai-editor' ); ?>
            </button>
            <button type="button" class="button button-secondary presshub-show-default-prompt" data-target="<?php echo self::esc_attr_safe( $option ); ?>" data-default="<?php echo self::esc_attr_safe( $default ); ?>">
                <?php echo __( 'Load Default Template for Editing', 'presshub-ai-editor' ); ?>
            </button>
        </p>
        <p class="description"><?php echo __( 'Custom system prompt for Greek text story curation. Leave empty to use standard prompt. Supports placeholders: {date}, {sources_list}, {articles_count}. (Optional: {articles_context} if you prefer articles placed inside the system prompt rather than the user prompt).', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_podcast_prompt_1_field() {
        $option  = 'presshub_ai_briefing_podcast_prompt_1';
        $value   = PressHub_AI_Settings_Storage::get_podcast_prompt_1();
        $this->render_prompt_textarea( $option, $value, 1 );
    }

    public function render_briefing_podcast_prompt_2_field() {
        $option  = 'presshub_ai_briefing_podcast_prompt_2';
        $value   = PressHub_AI_Settings_Storage::get_podcast_prompt_2();
        $this->render_prompt_textarea( $option, $value, 2 );
    }

    public function render_briefing_podcast_prompt_3_field() {
        $option  = 'presshub_ai_briefing_podcast_prompt_3';
        $value   = PressHub_AI_Settings_Storage::get_podcast_prompt_3();
        $this->render_prompt_textarea( $option, $value, 3 );
    }

    private function render_prompt_textarea( $option, $value, $host_count ) {
        // Issue #90 — default template is style-aware: we load the active style's
        // default so operators can edit it from the right baseline.
        $active_style = PressHub_AI_Settings_Storage::get_podcast_style();
        $default = class_exists( 'PressHub_AI_Podcast_Producer' )
            ? ( new PressHub_AI_Podcast_Producer() )->get_default_dialogue_prompt( $host_count, $active_style )
            : '';
        ?>
        <textarea name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" rows="6" class="large-text code" placeholder="<?php echo esc_attr( __( 'Leave empty to use the active style\'s default podcast prompt...', 'presshub-ai-editor' ) ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
        <p>
            <button type="button" class="button button-secondary presshub-reset-prompt" data-target="<?php echo self::esc_attr_safe( $option ); ?>" data-default="">
                <?php echo __( 'Clear / Reset Custom Prompt', 'presshub-ai-editor' ); ?>
            </button>
            <button type="button" class="button button-secondary presshub-show-default-prompt" data-target="<?php echo self::esc_attr_safe( $option ); ?>" data-default="<?php echo self::esc_attr_safe( $default ); ?>">
                <?php echo __( 'Load Default Template for Editing', 'presshub-ai-editor' ); ?>
            </button>
        </p>
        <p class="description"><?php
            // Issue #90 — host-name placeholders removed; reference the new [SPEAKER_N]: generic labels.
            echo __( 'Custom system prompt for the podcast dialogue generator. Leave empty to use the active style\'s default template. Supports placeholders: {date}, {sources_list}, {articles_context}, {duration_text}, {word_budget}. The script uses generic [SPEAKER_1]: / [SPEAKER_2]: / [SPEAKER_3]: speaker tags.', 'presshub-ai-editor' );
        ?></p>
        <?php
    }

    /**
     * Issue #90 — Render the podcast dialogue style selector.
     */
    public function render_briefing_podcast_style_field() {
        $option = 'presshub_ai_briefing_podcast_style';
        $value  = PressHub_AI_Settings_Storage::get_podcast_style();
        $styles = [
            'default_greek_chat'             => __( 'Default Greek Chat (NotebookLM-style, conversational)', 'presshub-ai-editor' ),
            'bbc_broadcasting_standards'     => __( 'BBC Broadcasting Standards (formal, neutral, third-person)', 'presshub-ai-editor' ),
            'conversational_news_reporting'  => __( 'Conversational News Reporting (informal, first-person, opinionated)', 'presshub-ai-editor' ),
        ];
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" class="regular-text">
            <?php foreach ( $styles as $key => $label ) : ?>
                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php
            echo __( 'Selects the dialogue style template that the podcast producer will use. The per-style override textareas below let you customize each style\'s prompts (3 hosts × 3 styles = 9 override fields).', 'presshub-ai-editor' );
        ?></p>
        <?php
    }

    /**
     * Issue #90 — Render the per-style prompt override block. Renders 3
     * collapsible textareas (1-host, 2-host, 3-host variants) for the given style.
     */
    public function render_briefing_podcast_prompts_for_style_field( string $style ) {
        if ( ! class_exists( 'PressHub_AI_Podcast_Producer' ) ) {
            return;
        }
        $producer = new PressHub_AI_Podcast_Producer();
        ?>
        <details class="presshub-podcast-style-block" data-style="<?php echo esc_attr( $style ); ?>" open>
            <summary class="ph-cursor-pointer ph-font-weight-600">
                <?php
                /* translators: %s: style key */
                echo esc_html( sprintf( __( '%s style overrides', 'presshub-ai-editor' ), $style ) );
                ?>
            </summary>
            <p class="description"><?php
                echo __( 'Leave each textarea empty to use that style\'s built-in default template. Saving will create a per-style override that supersedes the built-in template.', 'presshub-ai-editor' );
            ?></p>
            <?php foreach ( [ 1, 2, 3 ] as $host_count_n ) :
                $option = 'presshub_ai_briefing_podcast_prompt_' . $host_count_n . '_' . $style;
                $value  = (string) get_option( $option, '' );
                $default = $producer->get_default_dialogue_prompt( $host_count_n, $style );
            ?>
                <h4 class="ph-margin-top-1em ph-margin-bottom-_25em">
                    <?php
                    /* translators: %d: host count */
                    echo esc_html( sprintf( __( '%d host(s)', 'presshub-ai-editor' ), $host_count_n ) );
                    ?>
                </h4>
                <textarea name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" rows="6" class="large-text code" placeholder="<?php echo esc_attr__( 'Leave empty to use the built-in default...', 'presshub-ai-editor' ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
                <p>
                    <button type="button" class="button button-secondary presshub-reset-prompt" data-target="<?php echo self::esc_attr_safe( $option ); ?>" data-default="">
                        <?php echo esc_html__( 'Clear / Reset', 'presshub-ai-editor' ); ?>
                    </button>
                    <button type="button" class="button button-secondary presshub-show-default-prompt" data-target="<?php echo self::esc_attr_safe( $option ); ?>" data-default="<?php echo self::esc_attr_safe( $default ); ?>">
                        <?php echo esc_html__( 'Load Default Template for Editing', 'presshub-ai-editor' ); ?>
                    </button>
                </p>
            <?php endforeach; ?>
        </details>
        <?php
    }

    public function render_briefing_podcast_prompts_default_greek_chat_field() {
        $this->render_briefing_podcast_prompts_for_style_field( 'default_greek_chat' );
    }

    public function render_briefing_podcast_prompts_bbc_broadcasting_standards_field() {
        $this->render_briefing_podcast_prompts_for_style_field( 'bbc_broadcasting_standards' );
    }

    public function render_briefing_podcast_prompts_conversational_news_reporting_field() {
        $this->render_briefing_podcast_prompts_for_style_field( 'conversational_news_reporting' );
    }

    /**
     * Issue #108 — Section 3: Podcast Dialogue Styles & Prompt Studio
     * Tabbed Prompt Studio with primary Style Tabs, Presenter Subtabs, pre-filled textareas,
     * template load/clear actions, and empty prompt warning states.
     */
    public function render_briefing_prompt_studio() {
        $styles = [
            'default_greek_chat'             => __( 'Default Greek Chat', 'presshub-ai-editor' ),
            'bbc_broadcasting_standards'     => __( 'BBC Broadcasting Standards', 'presshub-ai-editor' ),
            'conversational_news_reporting' => __( 'Conversational News Reporting', 'presshub-ai-editor' ),
        ];
        ?>
        <div class="ph-margin-top-15px ph-margin-bottom-15px presshub-prompt-style-tabs nav-tab-wrapper">
            <a href="#presshub-style-default_greek_chat" class="nav-tab presshub-style-tab nav-tab-active" data-style="default_greek_chat"><?php echo esc_html__( 'Default Greek Chat', 'presshub-ai-editor' ); ?></a>
            <a href="#presshub-style-bbc_broadcasting_standards" class="nav-tab presshub-style-tab" data-style="bbc_broadcasting_standards"><?php echo esc_html__( 'BBC Broadcasting Standards', 'presshub-ai-editor' ); ?></a>
            <a href="#presshub-style-conversational_news_reporting" class="nav-tab presshub-style-tab" data-style="conversational_news_reporting"><?php echo esc_html__( 'Conversational News Reporting', 'presshub-ai-editor' ); ?></a>
        </div>

        <?php foreach ( $styles as $style_key => $style_title ) : ?>
            <div id="presshub-style-<?php echo esc_attr( $style_key ); ?>" class="presshub-style-pane<?php echo ( 'default_greek_chat' === $style_key ) ? '' : ' presshub-is-hidden'; ?>">
                <div class="ph-display-flex ph-gap-8px ph-margin-bottom-12px presshub-prompt-host-tabs">
                    <button type="button" class="button button-primary presshub-host-tab" data-target="presshub-pane-<?php echo esc_attr( $style_key ); ?>-1"><?php echo esc_html__( '1 Presenter (Solo)', 'presshub-ai-editor' ); ?></button>
                    <button type="button" class="button button-secondary presshub-host-tab" data-target="presshub-pane-<?php echo esc_attr( $style_key ); ?>-2"><?php echo esc_html__( '2 Presenters (Duo)', 'presshub-ai-editor' ); ?></button>
                    <button type="button" class="button button-secondary presshub-host-tab" data-target="presshub-pane-<?php echo esc_attr( $style_key ); ?>-3"><?php echo esc_html__( '3 Presenters (Panel)', 'presshub-ai-editor' ); ?></button>
                </div>

                <?php
                foreach ( [ 1, 2, 3 ] as $host_count_n ) :
                    $option  = 'presshub_ai_briefing_podcast_prompt_' . $host_count_n . '_' . $style_key;
                    $val     = get_option( $option, null );
                    $default = class_exists( 'PressHub_AI_Podcast_Producer' ) ? PressHub_AI_Podcast_Producer::get_default_dialogue_prompt( $host_count_n, $style_key ) : '';
                    if ( null === $val || ( is_string( $val ) && str_starts_with( trim( $val ), 'You are' ) ) ) {
                        $val = $default;
                        update_option( $option, $val );
                    }
                    $val_str = (string) $val;
                    ?>
                    <div id="presshub-pane-<?php echo esc_attr( $style_key ); ?>-<?php echo $host_count_n; ?>" class="presshub-host-pane<?php echo ( 1 === $host_count_n ) ? '' : ' presshub-is-hidden'; ?>">
                        <textarea name="<?php echo esc_attr( $option ); ?>" id="<?php echo esc_attr( $option ); ?>" rows="10" class="large-text code presshub-prompt-textarea"><?php echo esc_textarea( $val_str ); ?></textarea>

                        <p class="ph-margin-top-8px">
                            <button type="button" class="button button-secondary presshub-show-default-prompt" data-target="<?php echo esc_attr( $option ); ?>" data-default="<?php echo esc_attr( $default ); ?>">
                                <?php echo esc_html__( 'Load Built-in Template for Editing', 'presshub-ai-editor' ); ?>
                            </button>
                            <button type="button" class="button button-secondary presshub-reset-prompt" data-target="<?php echo esc_attr( $option ); ?>" data-default="">
                                <?php echo esc_html__( 'Clear Prompt', 'presshub-ai-editor' ); ?>
                            </button>
                        </p>

                        <div class="ph-margin-top-8px presshub-empty-prompt-warning notice notice-warning inline<?php echo empty( trim( $val_str ) ) ? '' : ' presshub-is-hidden'; ?>">
                            <p>⚠️ <strong><?php echo esc_html__( 'Warning:', 'presshub-ai-editor' ); ?></strong> <?php echo esc_html__( 'This prompt is currently empty. Dialogue generation for this style and presenter count will fail with an error unless a prompt is saved.', 'presshub-ai-editor' ); ?></p>
                        </div>

                        <p class="ph-margin-top-8px description">
                            <?php
                            if ( 1 === $host_count_n ) {
                                echo esc_html__( 'Available placeholders: {date}, {sources_list}, {articles_context}, {duration_text}, {word_budget}. Speaker label: [SPEAKER_1]:', 'presshub-ai-editor' );
                            } elseif ( 2 === $host_count_n ) {
                                echo esc_html__( 'Available placeholders: {date}, {sources_list}, {articles_context}, {duration_text}, {word_budget}. Speaker labels: [SPEAKER_1]:, [SPEAKER_2]:', 'presshub-ai-editor' );
                            } else {
                                echo esc_html__( 'Available placeholders: {date}, {sources_list}, {articles_context}, {duration_text}, {word_budget}. Speaker labels: [SPEAKER_1]:, [SPEAKER_2]:, [SPEAKER_3]:', 'presshub-ai-editor' );
                            }
                            ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <?php
    }

    public function render_briefing_podcast_live_preview_field() {
        // Obsolete in Issue #108: Effective Prompt Preview removed.
    }

    /**
     * Render the QA Enabled checkbox.
     */
    public function render_qa_enabled_field() {
        $option  = 'presshub_ai_qa_enabled';
        $enabled = PressHub_AI_Settings_Storage::get_qa_enabled();
        ?>
        <input type="hidden" name="<?php echo self::esc_attr_safe( $option ); ?>" value="0" />
        <label for="presshub_ai_qa_enabled">
            <input type="checkbox" name="<?php echo self::esc_attr_safe( $option ); ?>" id="presshub_ai_qa_enabled" value="1" <?php checked( true, $enabled ); ?> />
            <?php echo esc_html__( 'Enable automated pre-publication AI editorial QA review gate.', 'presshub-ai-editor' ); ?>
        </label>
        <p class="description">
            <?php echo esc_html__( 'When enabled, articles submitted for publication by authors or automated pipelines are scored before publishing. If the article fails to meet the minimum score, publication is blocked and reverted to Pending Review.', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    /**
     * Render the Include Daily Briefings in QA review checkbox.
     */
    public function render_qa_include_briefings_field() {
        $option  = 'presshub_ai_qa_include_briefings';
        $enabled = PressHub_AI_Settings_Storage::get_qa_include_briefings();
        ?>
        <input type="hidden" name="<?php echo self::esc_attr_safe( $option ); ?>" value="0" />
        <label for="presshub_ai_qa_include_briefings">
            <input type="checkbox" name="<?php echo self::esc_attr_safe( $option ); ?>" id="presshub_ai_qa_include_briefings" value="1" <?php checked( true, $enabled ); ?> />
            <?php echo esc_html__( 'Subject automated Daily Briefing posts to AI QA evaluation before publishing.', 'presshub-ai-editor' ); ?>
        </label>
        <p class="description">
            <?php echo esc_html__( 'Default: Enabled. When enabled, even if the briefing post status is set to "Publish immediately", it will only publish if it passes the QA review standard; otherwise it is held in Pending Review for editor inspection.', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    /**
     * Render the QA Minimum Passing Score input.
     */
    public function render_qa_min_score_field() {
        $option = 'presshub_ai_qa_min_score';
        $value  = PressHub_AI_Settings_Storage::get_qa_min_score();
        ?>
        <input type="number" min="50" max="100" step="1" name="<?php echo self::esc_attr_safe( $option ); ?>" id="presshub_ai_qa_min_score" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" />
        <span class="description"><?php echo esc_html__( '/ 100', 'presshub-ai-editor' ); ?></span>
        <p class="description">
            <?php echo esc_html__( 'Minimum score (50-100) required for publication. Articles scoring below this threshold are held in Pending Review. Default: 80.', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    /**
     * Render the QA Notify Editor checkbox.
     */
    public function render_qa_notify_editor_field() {
        $option  = 'presshub_ai_qa_notify_editor';
        $enabled = PressHub_AI_Settings_Storage::get_qa_notify_editor();
        ?>
        <input type="hidden" name="<?php echo self::esc_attr_safe( $option ); ?>" value="0" />
        <label for="presshub_ai_qa_notify_editor">
            <input type="checkbox" name="<?php echo self::esc_attr_safe( $option ); ?>" id="presshub_ai_qa_notify_editor" value="1" <?php checked( true, $enabled ); ?> />
            <?php echo esc_html__( 'Email the Editor when an article fails QA evaluation and is blocked.', 'presshub-ai-editor' ); ?>
        </label>
        <p class="description">
            <?php echo esc_html__( 'Sends an email with the score, AI critique feedback, author name, and direct post-edit link.', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    /**
     * Render the QA Editor Notification Email input.
     */
    public function render_qa_editor_email_field() {
        $option = 'presshub_ai_qa_editor_email';
        $value  = (string) get_option( $option, '' );
        ?>
        <input type="email" name="<?php echo self::esc_attr_safe( $option ); ?>" id="presshub_ai_qa_editor_email" value="<?php echo self::esc_attr_safe( $value ); ?>" placeholder="<?php echo self::esc_attr_safe( get_option( 'admin_email' ) ); ?>" class="regular-text" />
        <p class="description">
            <?php echo esc_html__( 'Recipient email address for QA failure alerts. Leave blank to default to site admin email (', 'presshub-ai-editor' ); ?><code><?php echo self::esc_html_safe( get_option( 'admin_email' ) ); ?></code><?php echo esc_html__( ').', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    /**
     * Render the Article QA Review prompt template textarea.
     */
    public function render_qa_article_prompt_field() {
        $option  = 'presshub_ai_qa_article_prompt';
        $value   = (string) get_option( $option, '' );
        if ( ! class_exists( 'PressHub_AI_Prompt_Loader' ) ) {
            require_once __DIR__ . '/class-prompt-loader.php';
        }
        $default = PressHub_AI_Prompt_Loader::get_article_qa_prompt();
        ?>
        <textarea name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" rows="8" class="large-text code" placeholder="<?php echo esc_attr( __( 'Leave empty to use the built-in default Article QA review prompt...', 'presshub-ai-editor' ) ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
        <p>
            <button type="button" class="button button-secondary presshub-reset-prompt" data-target="<?php echo self::esc_attr_safe( $option ); ?>" data-default="">
                <?php echo __( 'Clear / Reset Custom Prompt', 'presshub-ai-editor' ); ?>
            </button>
            <button type="button" class="button button-secondary presshub-show-default-prompt" data-target="<?php echo self::esc_attr_safe( $option ); ?>" data-default="<?php echo self::esc_attr_safe( $default ); ?>">
                <?php echo __( 'Load Default Template for Editing', 'presshub-ai-editor' ); ?>
            </button>
        </p>
        <p class="description">
            <?php echo __( 'User prompt template sent to the QA model when evaluating author articles. Supports <code>{content}</code> placeholder.', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    /**
     * Render the Daily Briefing QA Review prompt template textarea.
     */
    public function render_qa_briefing_prompt_field() {
        $option  = 'presshub_ai_qa_briefing_prompt';
        $value   = (string) get_option( $option, '' );
        if ( ! class_exists( 'PressHub_AI_Prompt_Loader' ) ) {
            require_once __DIR__ . '/class-prompt-loader.php';
        }
        $default = PressHub_AI_Prompt_Loader::get_briefing_qa_prompt();
        ?>
        <textarea name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" rows="8" class="large-text code" placeholder="<?php echo esc_attr( __( 'Leave empty to use the built-in default Daily Briefing QA review prompt...', 'presshub-ai-editor' ) ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
        <p>
            <button type="button" class="button button-secondary presshub-reset-prompt" data-target="<?php echo self::esc_attr_safe( $option ); ?>" data-default="">
                <?php echo __( 'Clear / Reset Custom Prompt', 'presshub-ai-editor' ); ?>
            </button>
            <button type="button" class="button button-secondary presshub-show-default-prompt" data-target="<?php echo self::esc_attr_safe( $option ); ?>" data-default="<?php echo self::esc_attr_safe( $default ); ?>">
                <?php echo __( 'Load Default Template for Editing', 'presshub-ai-editor' ); ?>
            </button>
        </p>
        <p class="description">
            <?php echo __( 'User prompt template sent to the QA model when evaluating automated Daily Briefing roundups. Supports <code>{content}</code> placeholder.', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    /**
     * Render the Tabbed Editorial QA Prompt Studio.
     * Provides primary tabs for Article QA Review Prompt and Daily Briefing QA Review Prompt,
     * pre-filled textareas, template load/clear actions, and empty prompt warning states.
     */
    public function render_qa_prompt_studio() {
        if ( ! class_exists( 'PressHub_AI_Prompt_Loader' ) ) {
            require_once __DIR__ . '/class-prompt-loader.php';
        }

        $default_article_prompt = PressHub_AI_Prompt_Loader::get_article_qa_prompt();
        $article_val            = (string) get_option( 'presshub_ai_qa_article_prompt', '' );
        ?>
        <p class="description"><?php echo esc_html__( 'The Daily Briefing QA prompt now sits with the briefing:', 'presshub-ai-editor' ); ?> <a href="<?php echo esc_url( PressHub_Settings_Sections::url( 'daily-briefing', 'briefing-text' ) ); ?>"><?php echo esc_html__( 'Daily Briefing Hub → Text story', 'presshub-ai-editor' ); ?></a></p>

        <!-- PANE 1: Article QA Prompt -->
        <div id="presshub-qa-pane-article" class="presshub-qa-pane">
            <textarea name="presshub_ai_qa_article_prompt" id="presshub_ai_qa_article_prompt" rows="9" class="large-text code presshub-qa-prompt-textarea" placeholder="<?php echo esc_attr__( 'Leave empty to use the built-in default Article QA review prompt...', 'presshub-ai-editor' ); ?>"><?php echo esc_textarea( $article_val ); ?></textarea>

            <p class="ph-margin-top-8px">
                <button type="button" class="button button-secondary presshub-show-default-prompt" data-target="presshub_ai_qa_article_prompt" data-default="<?php echo self::esc_attr_safe( $default_article_prompt ); ?>">
                    <?php echo esc_html__( 'Load Default Template for Editing', 'presshub-ai-editor' ); ?>
                </button>
                <button type="button" class="button button-secondary presshub-reset-prompt" data-target="presshub_ai_qa_article_prompt" data-default="">
                    <?php echo esc_html__( 'Clear / Reset Custom Prompt', 'presshub-ai-editor' ); ?>
                </button>
            </p>

            <div class="ph-margin-top-8px presshub-empty-prompt-warning notice notice-warning inline<?php echo empty( trim( $article_val ) ) ? '' : ' presshub-is-hidden'; ?>">
                <p>⚠️ <strong><?php echo esc_html__( 'Note:', 'presshub-ai-editor' ); ?></strong> <?php echo esc_html__( 'When left empty, PressHub AI automatically uses the built-in default prompt asset from assets/prompts/scorecard/article_qa_user_prompt.txt.', 'presshub-ai-editor' ); ?></p>
            </div>

            <p class="ph-margin-top-8px description">
                <?php echo __( 'User prompt template sent to the QA model when evaluating author articles. Evaluates factual clarity, grammar, structure, attribution, and balance. Supports <code>{content}</code> placeholder.', 'presshub-ai-editor' ); ?>
            </p>
        </div>

        <?php
    }

    /**
     * Render the Daily Briefing QA Review gate notice & direct link in Tab 3.
     */
    public function render_briefing_qa_gate_notice() {
        $qa_enabled   = PressHub_AI_Settings_Storage::get_qa_enabled();
        $qa_briefings = PressHub_AI_Settings_Storage::get_qa_include_briefings();
        $min_score    = PressHub_AI_Settings_Storage::get_qa_min_score();
        ?>
        <p class="ph-margin-bottom-8px description">
            <?php
            if ( $qa_enabled && $qa_briefings ) {
                echo '<span class="ph-color-46b450 ph-vertical-align-middle dashicons dashicons-yes-alt"></span> <strong>' . esc_html__( 'Active', 'presshub-ai-editor' ) . '</strong> &mdash; ' . sprintf( esc_html__( 'Automated Daily Briefing posts are evaluated by the QA Reviewer and require a minimum score of %d/100 to publish.', 'presshub-ai-editor' ), (int) $min_score );
            } elseif ( $qa_enabled ) {
                echo '<span class="ph-color-dba617 ph-vertical-align-middle dashicons dashicons-info"></span> <strong>' . esc_html__( 'Exempt from QA Gate', 'presshub-ai-editor' ) . '</strong> &mdash; ' . esc_html__( 'QA Gate is enabled for author posts, but Daily Briefings are currently set to bypass the gate.', 'presshub-ai-editor' );
            } else {
                echo '<span class="ph-color-72777c ph-vertical-align-middle dashicons dashicons-marker"></span> <strong>' . esc_html__( 'Disabled Globally', 'presshub-ai-editor' ) . '</strong> &mdash; ' . esc_html__( 'The AI Editorial QA Review System is currently disabled.', 'presshub-ai-editor' );
            }
            ?>
        </p>
        <p>
            <a href="<?php echo esc_url( PressHub_Settings_Sections::url( 'writing-qa', 'qa-gate' ) ); ?>" class="button button-secondary presshub-qa-gate-link">
                <?php echo esc_html__( 'Configure the QA review gate →', 'presshub-ai-editor' ); ?>
            </a>
        </p>
        <?php
    }



    // ------------------------------------------------------------------
    // P4 helpers.
    // ------------------------------------------------------------------

    /**
     * Mask a secret for display: prefix + '••••••••' + last 4 characters.
     */
    public static function mask_key( $key ): string {
        require_once __DIR__ . '/class-provider-store.php';
        return PressHub_AI_Provider_Store::mask_key( $key );
    }

    private static function esc_attr_safe( $text ) {
        if ( function_exists( 'esc_attr' ) ) {
            return esc_attr( $text );
        }
        return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
    }

    private static function esc_html_safe( $text ) {
        if ( function_exists( 'esc_html' ) ) {
            return esc_html( $text );
        }
        return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
    }
    // ------------------------------------------------------------------
    // Issue #132: Editorial Memory Render Fields
    // ------------------------------------------------------------------

    public function render_memory_section() {
        echo '<p>' . esc_html__( 'Configure tiered editorial memory stream to maintain long-term story continuity across briefings and podcast episodes.', 'presshub-ai-editor' ) . '</p>';
    }

    public function render_memory_enabled_field() {
        $option = 'presshub_ai_memory_enabled';
        $value  = PressHub_AI_Settings_Storage::get_memory_enabled();
        ?>
        <label>
            <input type="hidden" name="presshub_ai_memory_enabled_present" value="1" />
            <input type="checkbox" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="1" <?php checked( true, $value ); ?> />
            <?php echo esc_html__( 'Enable editorial memory stream (recent daily digests and background weekly rollups).', 'presshub-ai-editor' ); ?>
        </label>
        <p class="description"><?php echo esc_html__( 'When disabled, generations use only that day&#39;s harvested articles.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_memory_daily_days_field() {
        $option = 'presshub_ai_memory_daily_days';
        $value  = PressHub_AI_Settings_Storage::get_memory_daily_days();
        ?>
        <input type="number" min="0" max="14" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( (string) $value ); ?>" class="small-text" />
        <p class="description"><?php echo esc_html__( 'Number of recent days covered as daily story digests (0 - 14 days, default: 7).', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_memory_background_days_field() {
        $option = 'presshub_ai_memory_background_days';
        $value  = PressHub_AI_Settings_Storage::get_memory_background_days();
        ?>
        <input type="number" min="0" max="90" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( (string) $value ); ?>" class="small-text" />
        <p class="description"><?php echo esc_html__( 'Background window covered as weekly rollups (0 - 90 days, >= recent days, default: 30).', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_memory_briefing_budget_chars_field() {
        $option = 'presshub_ai_memory_briefing_budget_chars';
        $value  = PressHub_AI_Settings_Storage::get_memory_briefing_budget_chars();
        ?>
        <input type="number" min="0" max="60000" step="100" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( (string) $value ); ?>" class="regular-text" />
        <p class="description"><?php echo esc_html__( 'Maximum character budget for memory context injected into daily briefing prompt (0 - 60,000, default: 12,000).', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_memory_podcast_budget_chars_field() {
        $option = 'presshub_ai_memory_podcast_budget_chars';
        $value  = PressHub_AI_Settings_Storage::get_memory_podcast_budget_chars();
        ?>
        <input type="number" min="0" max="60000" step="100" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( (string) $value ); ?>" class="regular-text" />
        <p class="description"><?php echo esc_html__( 'Maximum character budget for memory context injected into podcast prompt (0 - 60,000, default: 6,000).', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_memory_retention_days_field() {
        $option = 'presshub_ai_memory_retention_days';
        $value  = PressHub_AI_Settings_Storage::get_memory_retention_days();
        ?>
        <input type="number" min="30" max="730" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( (string) $value ); ?>" class="small-text" />
        <p class="description"><?php echo esc_html__( 'Retention period before old memory entries and links are pruned (30 - 730 days, default: 180).', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_memory_provider_field() {
        $option  = 'presshub_ai_briefing_memory_provider';
        $current = (string) get_option( $option, '' );
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" class="regular-text">
            <?php echo self::get_active_providers_options( $current, __( '-- Use Active Provider Default --', 'presshub-ai-editor' ) ); ?>
        </select>
        <p class="description"><?php echo esc_html__( 'AI Provider for memory digests and weekly rollups. Can point to a cheaper model.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_memory_model_field() {
        $option = 'presshub_ai_briefing_memory_model';
        $value  = (string) get_option( $option, '' );
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text code" placeholder="<?php echo esc_attr__( 'Leave empty to use provider default', 'presshub-ai-editor' ); ?>" />
        <p class="description"><?php echo esc_html__( 'Model override for memory digests and rollups (e.g. gpt-4o-mini, gemini-2.0-flash).', 'presshub-ai-editor' ); ?></p>
        <?php
    }

}
