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

require_once __DIR__ . '/class-provider-defaults.php';
require_once __DIR__ . '/class-provider-store.php';

class PressHub_AI_Settings_Render {

    /**
     * Capability gate, mirrored from the facade so render_settings_page() can
     * call $this->settings_cap() without depending on the facade.
     * Filterable for sites that delegate settings to a custom role.
     */
    public function settings_cap(): string {
        return (string) apply_filters( 'presshub_ai_settings_cap', 'manage_options' );
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

        if ( function_exists( 'settings_errors' ) ) {
            settings_errors();
        }
        ?>
        <div class="wrap presshub-ai-settings-wrap">
            <h1><?php echo __( 'PressHub AI Settings', 'presshub-ai-editor' ); ?></h1>

            <nav class="nav-tab-wrapper wp-clearfix" id="presshub-ai-settings-tabs" style="margin-bottom: 20px;">
                <a href="#providers" class="nav-tab nav-tab-active" data-tab="providers"><?php echo __( 'AI Providers', 'presshub-ai-editor' ); ?></a>
                <a href="#coauthor" class="nav-tab" data-tab="coauthor"><?php echo __( 'AI Co-Author & Review', 'presshub-ai-editor' ); ?></a>
                <a href="#briefing" class="nav-tab" data-tab="briefing"><?php echo __( 'Daily Briefing Hub', 'presshub-ai-editor' ); ?></a>
                <a href="#copilot" class="nav-tab" data-tab="copilot"><?php echo __( 'AI Copilot & Assistant', 'presshub-ai-editor' ); ?></a>
                <a href="#token_logs" class="nav-tab" data-tab="token_logs"><?php echo __( 'Token & Usage Logs', 'presshub-ai-editor' ); ?></a>
                <a href="#advanced" class="nav-tab" data-tab="advanced"><?php echo __( 'Advanced & System', 'presshub-ai-editor' ); ?></a>
            </nav>

            <form method="post" action="options.php" id="presshub-ai-settings-form">
                <?php
                settings_fields( 'presshub_ai_options' );
                $GLOBALS['RENDERED_SECTIONS']['presshub-ai'] = true;
                ?>

                <!-- TAB 1: AI Providers Manager -->
                <div id="presshub-tab-pane-providers" class="presshub-tab-pane">
                    <?php $this->render_providers_grid(); ?>
                </div>

                <!-- TAB 2: AI Co-Author & Editorial Review -->
                <div id="presshub-tab-pane-coauthor" class="presshub-tab-pane" style="display: none;">
                    <h2><?php echo __( 'AI Co-Author & Editorial Review', 'presshub-ai-editor' ); ?></h2>
                    <p><?php echo __( 'Configure the AI engine, default model, and drafting behavior for draft generation and article reviews.', 'presshub-ai-editor' ); ?></p>
                    
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="presshub_ai_coauthor_provider"><?php echo __( 'Active Co-Author Provider', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_coauthor_prov = (string) get_option( 'presshub_ai_coauthor_provider', get_option( 'presshub_ai_provider', 'openai' ) ); ?>
                                    <select name="presshub_ai_coauthor_provider" id="presshub_ai_coauthor_provider" class="regular-text">
                                        <?php echo self::get_active_providers_options( $cur_coauthor_prov ); ?>
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
                                    <?php $cur_coauthor_tokens = (int) get_option( 'presshub_ai_coauthor_max_tokens', PressHub_AI_Provider_Defaults::default_max_tokens() ); ?>
                                    <input type="number" min="1" max="65536" name="presshub_ai_coauthor_max_tokens" id="presshub_ai_coauthor_max_tokens" value="<?php echo self::esc_attr_safe( (string) $cur_coauthor_tokens ); ?>" class="small-text" />
                                    <p class="description"><?php echo __( 'Maximum number of tokens the model can generate in a single response (1 - 65,536). Default: 16,384.', 'presshub-ai-editor' ); ?></p>
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
                                    <input type="number" min="5" max="300" name="presshub_ai_coauthor_timeout" id="presshub_ai_coauthor_timeout" value="<?php echo self::esc_attr_safe( (string) $cur_coauthor_timeout ); ?>" class="small-text" />
                                    <p class="description"><?php echo __( 'HTTP timeout for draft generation requests. Default 300s.', 'presshub-ai-editor' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo __( 'Source Processing & Debugging', 'presshub-ai-editor' ); ?></th>
                                <td>
                                    <?php $this->render_fetch_urls_field(); ?>
                                    <div style="margin-top: 8px;">
                                        <?php $this->render_debug_prompts_field(); ?>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- TAB 3: Daily Briefing Hub -->
                <div id="presshub-tab-pane-briefing" class="presshub-tab-pane" style="display: none;">
                    <h2><?php echo __( 'Daily Briefing & AI Podcast Hub', 'presshub-ai-editor' ); ?></h2>
                    <p><?php echo __( 'Configure automated morning Greek news scraping, AI text story curation, and multi-host conversational podcast production.', 'presshub-ai-editor' ); ?></p>

                    <h3><?php echo __( '1. Text Story Curator Settings', 'presshub-ai-editor' ); ?></h3>
                    <table class="form-table" role="presentation">
                        <tbody>
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
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_sources"><?php echo __( 'News Source URLs', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_sources_field(); ?>
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
                        </tbody>
                    </table>

                    <hr style="margin: 25px 0;">
                    <h3><?php echo __( '2. AI Podcast Producer & Audio Synthesis Settings', 'presshub-ai-editor' ); ?></h3>
                    <table class="form-table" role="presentation">
                        <tbody>
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
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_target_duration"><?php echo __( 'Target Podcast Duration', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_target_duration_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_host_female"><?php echo __( 'Female Host Name', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_host_female_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_host_male"><?php echo __( 'Male Host Name', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_host_male_field(); ?>
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
                                <th scope="row"><label for="presshub_ai_briefing_tts_style"><?php echo __( 'Speaking Delivery Style (logosAI)', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_tts_style_field(); ?>
                                </td>
                            </tr>
                            <tr id="presshub-custom-tts-style-row" style="<?php echo ( get_option( 'presshub_ai_briefing_tts_style', 'formal' ) === 'custom' ) ? '' : 'display: none;'; ?>">
                                <th scope="row"><label for="presshub_ai_briefing_tts_custom_style"><?php echo __( 'Custom Speaking Style Prompt', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_tts_custom_style_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_voice_female"><?php echo __( 'Lead Host Voice Persona (Female)', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_voice_female_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_voice_male"><?php echo __( 'Secondary Host Voice Persona (Male)', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_voice_male_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_voice_speed"><?php echo __( 'Speaking Rate / Speed', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_voice_speed_field(); ?>
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
                                <th scope="row"><label for="presshub_ai_briefing_podcast_preset"><?php echo __( 'Podcast Dialogue Preset', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_podcast_preset_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_podcast_prompt"><?php echo __( 'Podcast System Prompt', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_podcast_prompt_field(); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- TAB 4: AI Copilot & Assistant -->
                <div id="presshub-tab-pane-copilot" class="presshub-tab-pane" style="display: none;">
                    <h2><?php echo __( 'AI Copilot & Research Assistant', 'presshub-ai-editor' ); ?></h2>
                    <p><?php echo __( 'Configure the AI provider, model, and parameters that power the interactive sidebar Copilot and autonomous research agents.', 'presshub-ai-editor' ); ?></p>
                    
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
                                    <?php $cur_copilot_tokens = (int) get_option( 'presshub_ai_copilot_max_tokens', PressHub_AI_Provider_Defaults::default_max_tokens() ); ?>
                                    <input type="number" min="1" max="65536" name="presshub_ai_copilot_max_tokens" id="presshub_ai_copilot_max_tokens" value="<?php echo self::esc_attr_safe( (string) $cur_copilot_tokens ); ?>" class="small-text" />
                                    <p class="description"><?php echo __( 'Maximum number of tokens the model can generate in a single response (1 - 65,536). Default: 16,384.', 'presshub-ai-editor' ); ?></p>
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
                                    <input type="number" min="5" max="300" name="presshub_ai_copilot_timeout" id="presshub_ai_copilot_timeout" value="<?php echo self::esc_attr_safe( (string) $cur_copilot_timeout ); ?>" class="small-text" />
                                    <p class="description"><?php echo __( 'HTTP timeout for chat and research calls. Default 300s.', 'presshub-ai-editor' ); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- TAB 5: Token & Usage Analytics Logs -->
                <div id="presshub-tab-pane-token_logs" class="presshub-tab-pane" style="display: none;">
                    <?php $this->render_token_logs_tab(); ?>
                </div>

                <!-- TAB 6: Advanced & System Settings -->
                <div id="presshub-tab-pane-advanced" class="presshub-tab-pane" style="display: none;">
                    <?php $this->render_section_with_fields( 'presshub_ai_github', __( 'Plugin Updates & GitHub Integration', 'presshub-ai-editor' ) ); ?>

                    <hr style="margin: 25px 0;">
                    <?php $this->render_section_with_fields( 'presshub_ai_media', __( 'Media & Vision Credentials (Google Cloud)', 'presshub-ai-editor' ) ); ?>
                    
                    <hr style="margin: 25px 0;">
                    <?php $this->render_section_with_fields( 'presshub_ai_rate_limits', __( 'Rate Limits, Research Retention & Logging', 'presshub-ai-editor' ) ); ?>

                    <hr style="margin: 25px 0;">
                    <h2><?php echo __( 'Connection Testing & Verification', 'presshub-ai-editor' ); ?></h2>
                    <p><?php echo __( 'Verify connectivity with any configured provider. Each test uses its own rate-limit budget and logs activity in the Token Logger dashboard.', 'presshub-ai-editor' ); ?></p>
                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        <button type="button" id="presshub-ai-test-api" class="button presshub-ai-test-api" data-provider=""><?php echo __( 'Test Active Provider', 'presshub-ai-editor' ); ?></button>
                        <button type="button" class="button presshub-ai-test-api" data-provider="openai"><?php echo __( 'Test OpenAI', 'presshub-ai-editor' ); ?></button>
                        <button type="button" class="button presshub-ai-test-api" data-provider="anthropic"><?php echo __( 'Test Anthropic', 'presshub-ai-editor' ); ?></button>
                        <button type="button" class="button presshub-ai-test-api" data-provider="gemini"><?php echo __( 'Test Gemini', 'presshub-ai-editor' ); ?></button>
                        <span id="presshub-ai-test-spinner" class="spinner" role="status"><span class="screen-reader-text"></span></span>
                    </div>
                    <div id="presshub-ai-test-result" style="margin-top: 15px; font-weight: bold;"></div>

                    <hr style="margin: 25px 0;">
                    <h2><?php echo __( 'Internal Diagnostic Log Viewer', 'presshub-ai-editor' ); ?></h2>
                    <p><?php echo __( 'View recent internal diagnostic logs for debugging scraping, API calls, prompt hydration, and background jobs.', 'presshub-ai-editor' ); ?></p>
                    <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 10px;">
                        <button type="button" id="presshub-ai-refresh-logs" class="button button-secondary"><?php echo __( 'Refresh Logs', 'presshub-ai-editor' ); ?></button>
                        <button type="button" id="presshub-ai-clear-logs" class="button button-secondary"><?php echo __( 'Clear Logs', 'presshub-ai-editor' ); ?></button>
                        <span id="presshub-ai-log-spinner" class="spinner" role="status"><span class="screen-reader-text"></span></span>
                        <span id="presshub-ai-log-status" style="margin-left: 10px; color: #666;"></span>
                    </div>
                    <textarea id="presshub-ai-log-viewer" rows="14" class="large-text code" readonly="readonly" style="font-size: 12px; background: #1e1e1e; color: #d4d4d4; font-family: monospace;" placeholder="<?php echo esc_attr__( 'Click "Refresh Logs" to load diagnostic entries...', 'presshub-ai-editor' ); ?>"></textarea>
                </div>

                <div class="presshub-settings-submit-wrap" id="presshub-settings-submit-wrap" style="margin-top: 20px; display: flex; align-items: center; gap: 10px;">
                    <?php if ( function_exists( 'submit_button' ) ) { submit_button( __( 'Save Changes', 'presshub-ai-editor' ), 'primary', 'submit', false ); } ?>
                    <span id="presshub-ai-save-spinner" class="spinner" role="status" style="float: none; margin: 0;"><span class="screen-reader-text"></span></span>
                </div>
            </form>

            <?php $this->render_provider_modal(); ?>
            <?php $this->render_source_modal(); ?>
        </div>
        <?php
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
        foreach ( $providers as $prov ) {
            if ( empty( $prov['enabled'] ) || ( empty( $prov['api_key'] ) && 'ollama_local' !== ( $prov['type'] ?? '' ) ) ) {
                continue;
            }
            $id    = $prov['id'] ?? ( $prov['type'] ?? '' );
            $name  = $prov['name'] ?? ucfirst( $id );
            $model = $prov['default_model'] ?? '';
            $label = $name . ( $model ? ' (' . $model . ')' : '' ) . ( empty( $prov['enabled'] ) ? ' [' . __( 'Disabled', 'presshub-ai-editor' ) . ']' : '' );
            $selected = ( $current_val === $id ) ? ' selected="selected"' : '';
            $html .= '<option value="' . esc_attr( $id ) . '"' . $selected . '>' . esc_html( $label ) . '</option>';
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
        foreach ( $providers as $prov ) {
            if ( ( $prov['type'] ?? '' ) !== 'gemini' || empty( $prov['enabled'] ) || empty( $prov['api_key'] ) ) {
                continue;
            }
            $id    = $prov['id'] ?? ( $prov['type'] ?? '' );
            $name  = $prov['name'] ?? ucfirst( $id );
            $model = $prov['default_model'] ?? '';
            $label = $name . ( $model ? ' (' . $model . ')' : '' );
            $selected = ( $selected_id === $id ) ? ' selected="selected"' : '';
            $html .= '<option value="' . esc_attr( $id ) . '"' . $selected . '>' . esc_html( $label ) . '</option>';
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
        <div class="presshub-providers-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div>
                <h2><?php echo __( 'Configured AI Providers', 'presshub-ai-editor' ); ?></h2>
                <p><?php echo __( 'Manage standard AI engines (OpenAI, Anthropic, Gemini, Google Cloud TTS, Groq, Mistral, DeepSeek, Local Ollama) and custom OpenAI-compatible endpoints.', 'presshub-ai-editor' ); ?></p>
            </div>
            <button type="button" class="button button-primary presshub-add-provider-btn" id="presshub-add-provider-btn" style="display: inline-flex; align-items: center; gap: 6px;">
                <span class="dashicons dashicons-plus-alt2" style="font-size: 16px; width: 16px; height: 16px;"></span>
                <?php echo __( 'Add Provider', 'presshub-ai-editor' ); ?>
            </button>
        </div>

        <div class="presshub-providers-grid" id="presshub-providers-grid">
            <?php if ( empty( $providers ) ) : ?>
                <div class="presshub-no-providers" style="grid-column: 1 / -1; padding: 30px; text-align: center; background: #fff; border: 1px dashed #ccd0d4; border-radius: 6px;">
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
                            <span class="presshub-meta-val"><?php echo sprintf( 'Temp: %s | Max: %s | %ss', esc_html( (string) $temp ), esc_html( function_exists( 'number_format_i18n' ) ? number_format_i18n( $max_tokens ) : number_format( $max_tokens ) ), esc_html( (string) $timeout ) ); ?></span>
                        </div>
                        <div class="presshub-meta-row">
                            <span class="presshub-meta-label"><?php echo __( 'Credentials:', 'presshub-ai-editor' ); ?></span>
                            <span class="presshub-meta-val"><?php echo $has_key ? '<span class="code">' . esc_html( self::mask_key( $provider['api_key'] ) ) . '</span>' : ( 'ollama_local' === $type ? esc_html__( 'Not required', 'presshub-ai-editor' ) : '<span class="presshub-missing-key" style="color: #d63638;">' . esc_html__( 'No API Key set', 'presshub-ai-editor' ) . '</span>' ); ?></span>
                        </div>
                    </div>

                    <div class="presshub-card-footer">
                        <button type="button" class="button button-secondary presshub-test-provider-btn" data-provider-id="<?php echo esc_attr( $id ); ?>">
                            <span class="dashicons dashicons-update" style="vertical-align: middle; margin-top: -2px;"></span>
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
                    <div class="presshub-card-test-result" style="display:none; margin-top: 10px; font-size: 12px;"></div>
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
        <div id="presshub-provider-modal" class="presshub-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="presshub-provider-modal-title" style="display:none;">
            <div class="presshub-modal presshub-provider-modal-content">
                <div class="presshub-modal-header" style="display: flex; justify-content: space-between; align-items: center; padding: 14px 20px; border-bottom: 1px solid #dcdcde;">
                    <h2 id="presshub-provider-modal-title" style="margin:0; font-size: 16px;"><?php echo __( 'Add / Edit AI Provider', 'presshub-ai-editor' ); ?></h2>
                    <button type="button" class="presshub-modal-close" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #666;" aria-label="<?php echo esc_attr__( 'Close', 'presshub-ai-editor' ); ?>">&times;</button>
                </div>
                <div class="presshub-modal-body" style="padding: 20px; max-height: 70vh; overflow-y: auto;">
                    <form id="presshub-provider-form">
                        <input type="hidden" id="provider-form-id" name="id" value="" />

                        <div class="presshub-form-group" style="margin-bottom: 15px;">
                            <label for="provider-form-template"><strong><?php echo __( 'Provider Preset Template:', 'presshub-ai-editor' ); ?></strong></label>
                            <select id="provider-form-template" class="widefat" style="margin-top: 4px;">
                                <option value=""><?php echo __( '-- Select Template Preset (Auto-fills defaults) --', 'presshub-ai-editor' ); ?></option>
                                <?php foreach ( $templates as $tmpl_key => $tmpl ) : ?>
                                    <option value="<?php echo esc_attr( $tmpl_key ); ?>"><?php echo esc_html( $tmpl['name'] ); ?> (<?php echo esc_html( $tmpl_key ); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php echo __( 'Selecting a preset template automatically sets the base URL, default model and tuning parameters.', 'presshub-ai-editor' ); ?></p>
                        </div>

                        <div class="presshub-form-row" style="display: flex; gap: 15px; margin-bottom: 15px;">
                            <div class="presshub-form-group" style="flex: 1;">
                                <label for="provider-form-name"><strong><?php echo __( 'Display Name:', 'presshub-ai-editor' ); ?></strong></label>
                                <input type="text" id="provider-form-name" name="name" class="widefat" required placeholder="e.g. OpenAI Production" style="margin-top: 4px;" />
                            </div>
                            <div class="presshub-form-group" style="flex: 1;">
                                <label for="provider-form-type"><strong><?php echo __( 'Provider Type:', 'presshub-ai-editor' ); ?></strong></label>
                                <select id="provider-form-type" name="type" class="widefat" style="margin-top: 4px;">
                                    <?php foreach ( $templates as $tmpl_key => $tmpl ) : ?>
                                        <option value="<?php echo esc_attr( $tmpl_key ); ?>"><?php echo esc_html( $tmpl['name'] ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="presshub-form-group" style="margin-bottom: 15px;">
                            <label for="provider-form-base-url"><strong><?php echo __( 'Base URL / Endpoint:', 'presshub-ai-editor' ); ?></strong></label>
                            <input type="url" id="provider-form-base-url" name="base_url" class="widefat code" placeholder="https://api.openai.com/v1" style="margin-top: 4px;" />
                        </div>

                        <div class="presshub-form-group" style="margin-bottom: 15px;">
                            <label for="provider-form-api-key"><strong><?php echo __( 'API Key / Secret Token:', 'presshub-ai-editor' ); ?></strong></label>
                            <input type="password" id="provider-form-api-key" name="api_key" class="widefat" autocomplete="new-password" placeholder="<?php echo esc_attr__( 'Enter API Key (or leave blank to preserve saved)', 'presshub-ai-editor' ); ?>" style="margin-top: 4px;" />
                            <p class="description"><?php echo __( 'Stored securely with autoload disabled. Leave blank when editing to keep current secret.', 'presshub-ai-editor' ); ?></p>
                        </div>

                        <div class="presshub-form-group" style="margin-bottom: 15px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                <label for="provider-form-default-model"><strong><?php echo __( 'Model:', 'presshub-ai-editor' ); ?></strong></label>
                                <label style="font-weight: normal; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;">
                                    <input type="checkbox" id="provider-form-toggle-manual" />
                                    <?php echo __( 'Enter model manually', 'presshub-ai-editor' ); ?>
                                </label>
                            </div>
                            <div id="provider-model-select-wrap">
                                <div style="display: flex; gap: 8px; align-items: center;">
                                    <select id="provider-form-default-model" name="default_model" class="widefat code" style="flex: 1; margin: 0;"></select>
                                    <button type="button" class="button button-secondary" id="provider-form-fetch-models" style="display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;">
                                        <span class="dashicons dashicons-update" style="font-size: 14px; width: 14px; height: 14px; margin-top: 1px;"></span>
                                        <?php echo __( 'Fetch Models', 'presshub-ai-editor' ); ?>
                                    </button>
                                    <span class="spinner" id="provider-form-fetch-spinner" style="float: none; margin: 0;" role="status"></span>
                                </div>
                            </div>
                            <div id="provider-model-manual-wrap" style="display: none;">
                                <input type="text" id="provider-form-manual-model" class="widefat code" placeholder="<?php echo esc_attr__( 'e.g. gpt-4o, claude-3-5-sonnet-20241022, fine-tune-xyz', 'presshub-ai-editor' ); ?>" style="margin-top: 0;" />
                            </div>
                            <input type="hidden" id="provider-form-available-models" name="available_models" value="" />
                            <p class="description"><?php echo __( 'Select the active model for this provider or fetch latest models directly via the provider API.', 'presshub-ai-editor' ); ?></p>
                        </div>

                        <div class="presshub-form-row" style="display: flex; gap: 15px; margin-bottom: 15px;">
                            <div class="presshub-form-group" style="flex: 1;">
                                <label for="provider-form-temperature"><strong><?php echo __( 'Temperature (0.0 - 2.0):', 'presshub-ai-editor' ); ?></strong></label>
                                <input type="number" id="provider-form-temperature" name="temperature" class="widefat" step="0.05" min="0" max="2" value="0.7" style="margin-top: 4px;" />
                            </div>
                            <div class="presshub-form-group" style="flex: 1;">
                                <label for="provider-form-max-tokens"><strong><?php echo __( 'Max Tokens:', 'presshub-ai-editor' ); ?></strong></label>
                                <input type="number" id="provider-form-max-tokens" name="max_tokens" class="widefat" step="100" min="1" max="32768" value="10000" style="margin-top: 4px;" />
                            </div>
                            <div class="presshub-form-group" style="flex: 1;">
                                <label for="provider-form-timeout"><strong><?php echo __( 'Timeout (seconds):', 'presshub-ai-editor' ); ?></strong></label>
                                <input type="number" id="provider-form-timeout" name="timeout" class="widefat" min="5" max="300" value="300" style="margin-top: 4px;" />
                            </div>
                        </div>

                        <div class="presshub-form-group" style="margin-bottom: 15px;">
                            <label for="provider-form-headers"><strong><?php echo __( 'Custom HTTP Headers (JSON optional):', 'presshub-ai-editor' ); ?></strong></label>
                            <textarea id="provider-form-headers" name="headers" class="widefat code" rows="2" placeholder='{"HTTP-Referer": "https://mysite.com"}' style="margin-top: 4px;"></textarea>
                        </div>

                        <div class="presshub-form-group">
                            <label>
                                <input type="checkbox" id="provider-form-enabled" name="enabled" value="1" checked="checked" />
                                <strong><?php echo __( 'Enable this provider for module assignment', 'presshub-ai-editor' ); ?></strong>
                            </label>
                        </div>
                    </form>
                    <div id="presshub-provider-form-notice" style="margin-top: 10px;"></div>
                </div>
                <div class="presshub-modal-footer" style="padding: 12px 20px; border-top: 1px solid #dcdcde; display: flex; align-items: center; gap: 8px;">
                    <button type="button" class="button button-secondary" id="presshub-provider-form-test">
                        <span class="dashicons dashicons-update" style="vertical-align: middle; margin-top: -2px;"></span>
                        <?php echo __( 'Test Connection', 'presshub-ai-editor' ); ?>
                    </button>
                    <div style="margin-left: auto; display: flex; gap: 8px;">
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
                <div class="filter-group filter-search" style="flex: 1;">
                    <label for="token-filter-search"><?php echo __( 'Search:', 'presshub-ai-editor' ); ?></label>
                    <input type="text" id="token-filter-search" placeholder="<?php echo esc_attr__( 'Search model, action, metadata...', 'presshub-ai-editor' ); ?>" />
                </div>
                <div class="filter-actions" style="display: flex; gap: 6px; align-items: flex-end;">
                    <button type="button" class="button button-secondary" id="token-filter-refresh"><?php echo __( 'Filter', 'presshub-ai-editor' ); ?></button>
                    <button type="button" class="button button-secondary" id="token-export-csv"><?php echo __( 'Export CSV', 'presshub-ai-editor' ); ?></button>
                    <button type="button" class="button button-link-delete" id="token-clear-logs"><?php echo __( 'Clear Logs', 'presshub-ai-editor' ); ?></button>
                    <span class="spinner" id="token-logs-spinner" role="status"></span>
                </div>
            </div>

            <div class="presshub-table-responsive" style="margin-top: 15px;">
                <table class="wp-list-table widefat fixed striped presshub-token-table">
                    <thead>
                        <tr>
                            <th style="width: 140px;"><?php echo __( 'Date / Time', 'presshub-ai-editor' ); ?></th>
                            <th style="width: 130px;"><?php echo __( 'Action', 'presshub-ai-editor' ); ?></th>
                            <th style="width: 110px;"><?php echo __( 'Provider', 'presshub-ai-editor' ); ?></th>
                            <th style="width: 140px;"><?php echo __( 'Model', 'presshub-ai-editor' ); ?></th>
                            <th><?php echo __( 'Tokens / Metrics', 'presshub-ai-editor' ); ?></th>
                            <th style="width: 90px;"><?php echo __( 'Latency', 'presshub-ai-editor' ); ?></th>
                            <th style="width: 90px;"><?php echo __( 'Status', 'presshub-ai-editor' ); ?></th>
                            <th style="width: 80px;"><?php echo __( 'Details', 'presshub-ai-editor' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="presshub-token-logs-tbody">
                        <tr><td colspan="8" style="text-align: center; padding: 20px;"><?php echo __( 'Loading token usage logs...', 'presshub-ai-editor' ); ?></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="presshub-pagination-wrap" id="presshub-token-pagination" style="display: flex; justify-content: space-between; align-items: center; margin-top: 12px;">
                <span class="pagination-info" id="token-pagination-info"><?php echo __( 'Showing 0 items', 'presshub-ai-editor' ); ?></span>
                <div class="pagination-buttons" style="display: flex; gap: 8px;">
                    <button type="button" class="button button-secondary" id="token-page-prev" disabled="disabled">&laquo; <?php echo __( 'Previous', 'presshub-ai-editor' ); ?></button>
                    <span id="token-page-current" style="display: flex; align-items: center; font-weight: 600;">1 / 1</span>
                    <button type="button" class="button button-secondary" id="token-page-next" disabled="disabled"><?php echo __( 'Next', 'presshub-ai-editor' ); ?> &raquo;</button>
                </div>
            </div>
        </div>
        <?php
        $this->render_log_details_modal();
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
        <div id="presshub-log-details-modal" class="presshub-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="presshub-log-details-modal-title" style="display:none;">
            <div class="presshub-modal" style="max-width: 650px; width: 95%;">
                <div class="presshub-modal-header" style="display: flex; justify-content: space-between; align-items: center; padding: 14px 20px; border-bottom: 1px solid #dcdcde;">
                    <h2 id="presshub-log-details-modal-title" style="margin:0; font-size: 16px;"><?php echo __( 'Request Log & Error Details', 'presshub-ai-editor' ); ?></h2>
                    <button type="button" class="presshub-modal-close" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #666;" aria-label="<?php echo esc_attr__( 'Close', 'presshub-ai-editor' ); ?>">&times;</button>
                </div>
                <div class="presshub-modal-body" style="padding: 20px; max-height: 70vh; overflow-y: auto;">
                    <div class="presshub-log-info-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; font-size: 13px;">
                        <div>
                            <strong><?php echo __( 'Timestamp:', 'presshub-ai-editor' ); ?></strong>
                            <div id="log-detail-timestamp" style="color: #555; margin-top: 2px;">-</div>
                        </div>
                        <div>
                            <strong><?php echo __( 'Status:', 'presshub-ai-editor' ); ?></strong>
                            <div id="log-detail-status" style="margin-top: 2px;">-</div>
                        </div>
                        <div>
                            <strong><?php echo __( 'Action:', 'presshub-ai-editor' ); ?></strong>
                            <div id="log-detail-action" style="color: #555; margin-top: 2px;">-</div>
                        </div>
                        <div>
                            <strong><?php echo __( 'Provider:', 'presshub-ai-editor' ); ?></strong>
                            <div id="log-detail-provider" style="color: #555; margin-top: 2px;">-</div>
                        </div>
                        <div>
                            <strong><?php echo __( 'Model:', 'presshub-ai-editor' ); ?></strong>
                            <div id="log-detail-model" style="color: #555; margin-top: 2px;"><code style="font-size: 12px;">-</code></div>
                        </div>
                        <div>
                            <strong><?php echo __( 'Duration:', 'presshub-ai-editor' ); ?></strong>
                            <div id="log-detail-duration" style="color: #555; margin-top: 2px;">-</div>
                        </div>
                        <div style="grid-column: span 2;">
                            <strong><?php echo __( 'Tokens / Metrics:', 'presshub-ai-editor' ); ?></strong>
                            <div id="log-detail-metrics" style="color: #555; margin-top: 2px;">-</div>
                        </div>
                    </div>

                    <div id="presshub-log-error-container" style="display: none; margin-bottom: 16px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <strong style="color: #d63638;"><?php echo __( 'Error Message / Diagnostics:', 'presshub-ai-editor' ); ?></strong>
                            <button type="button" class="button button-small" id="presshub-copy-log-error" style="display: inline-flex; align-items: center; gap: 4px;">
                                <span class="dashicons dashicons-clipboard" style="font-size: 14px; width: 14px; height: 14px;"></span>
                                <span class="copy-btn-text"><?php echo __( 'Copy Error', 'presshub-ai-editor' ); ?></span>
                            </button>
                        </div>
                        <pre id="log-detail-error" style="background: #fcf0f1; border: 1px solid #f5c6cb; color: #721c24; padding: 10px; border-radius: 4px; font-size: 12px; white-space: pre-wrap; word-break: break-word; max-height: 180px; overflow-y: auto; margin: 0;"></pre>
                    </div>

                    <div id="presshub-log-metadata-container" style="display: none; margin-bottom: 10px;">
                        <strong><?php echo __( 'Additional Metadata:', 'presshub-ai-editor' ); ?></strong>
                        <pre id="log-detail-metadata" style="background: #f6f7f7; border: 1px solid #dcdcde; color: #333; padding: 10px; border-radius: 4px; font-size: 11px; white-space: pre-wrap; word-break: break-word; max-height: 180px; overflow-y: auto; margin-top: 6px;"></pre>
                    </div>
                </div>
                <div class="presshub-modal-footer" style="padding: 12px 20px; border-top: 1px solid #dcdcde; display: flex; justify-content: flex-end;">
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
        add_help_tab( $args );
    }

    // ------------------------------------------------------------------
    // Section descriptions & renderers.
    // ------------------------------------------------------------------

    public function render_section_with_fields( string $section_id, string $title ) {
        echo '<h2 id="wp-settings-section-' . esc_attr( $section_id ) . '">' . esc_html( $title ) . '</h2>';
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
     * 1.2.5: prompt inspection toggle. Writes the exact SYSTEM and USER
     * prompts to wp-content/uploads/presshub-ai-debug.log (findable via
     * the host file manager — no wp-config or PHP error log hunting).
     */
    public function render_debug_prompts_field() {
        $enabled = get_option( 'presshub_ai_debug_prompts', '0' ) === '1';
        ?>
        <label for="presshub_ai_debug_prompts">
            <input type="checkbox" name="presshub_ai_debug_prompts" id="presshub_ai_debug_prompts" value="1" <?php echo $enabled ? 'checked="checked"' : ''; ?> />
            <?php echo esc_html__( 'Append every SYSTEM and USER prompt to wp-content/uploads/presshub-ai-debug.log (debugging only — disable in production).', 'presshub-ai-editor' ); ?>
        </label>
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
        <input type="number" min="1" max="32768" step="1" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" />
        <p class="description"><?php echo __( 'Maximum tokens per response (1-32768). Default 10000.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_timeout_field( $args = [] ) {
        $provider = $args['provider'] ?? 'openai';
        $option   = 'presshub_ai_timeout_' . $provider;
        $value    = (int) get_option( $option, PressHub_AI_Settings_Migration::default_timeout( $provider ) );
        ?>
        <input type="number" min="5" max="300" step="1" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" />
        <p class="description"><?php echo __( 'Request timeout in seconds (5-300). Default', 'presshub-ai-editor' ); ?> <?php echo (int) PressHub_AI_Settings_Migration::default_timeout( $provider ); ?>.</p>
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
        <p class="description"><?php echo __( 'Control verbosity of the internal diagnostic log file (stored at wp-content/uploads/presshub-ai/presshub-debug.log).', 'presshub-ai-editor' ); ?></p>
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
            <input type="hidden" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo esc_attr( wp_json_encode( $sources ) ); ?>" />
            
            <div class="presshub-sources-toolbar" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 10px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span id="presshub-sources-count-badge" class="presshub-badge">
                        <?php echo sprintf( esc_html__( '%d Sources (%d Active)', 'presshub-ai-editor' ), $total_count, $active_count ); ?>
                    </span>
                    <span style="color: #646970; font-size: 12px;">
                        <?php echo esc_html__( 'Configure editorial outlets, RSS feeds, and multi-modal streams for daily morning harvesting.', 'presshub-ai-editor' ); ?>
                    </span>
                </div>
                <div>
                    <button type="button" class="button button-primary" id="presshub-add-source-btn">
                        <span class="dashicons dashicons-plus-alt2" style="vertical-align: text-bottom; margin-right: 4px;"></span><?php echo esc_html__( 'Add News Source', 'presshub-ai-editor' ); ?>
                    </button>
                </div>
            </div>

            <div class="presshub-table-responsive presshub-sources-table-wrap">
                <table class="wp-list-table widefat fixed striped presshub-sources-table" id="presshub-sources-table">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 80px; text-align: center;"><?php echo esc_html__( 'Status', 'presshub-ai-editor' ); ?></th>
                            <th scope="col" style="width: 170px;"><?php echo esc_html__( 'Source Name', 'presshub-ai-editor' ); ?></th>
                            <th scope="col"><?php echo esc_html__( 'URL / Feed', 'presshub-ai-editor' ); ?></th>
                            <th scope="col" style="width: 180px;"><?php echo esc_html__( 'Media Type', 'presshub-ai-editor' ); ?></th>
                            <th scope="col" style="width: 140px;"><?php echo esc_html__( 'Category / Notes', 'presshub-ai-editor' ); ?></th>
                            <th scope="col" style="width: 170px; text-align: right;"><?php echo esc_html__( 'Actions', 'presshub-ai-editor' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="presshub-sources-tbody">
                        <?php
                        if ( empty( $sources ) ) {
                            echo '<tr class="presshub-sources-empty-row"><td colspan="6" style="text-align: center; color: #646970; padding: 20px;">' . esc_html__( 'No news sources configured yet. Click "Add News Source" to add one.', 'presshub-ai-editor' ) . '</td></tr>';
                        } else {
                            foreach ( $sources as $source ) {
                                echo $this->render_source_row( $source ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>
            <p class="description" style="margin-top: 8px;">
                <?php echo esc_html__( 'Active text/RSS sources are crawled every morning during the harvest window. Multi-modal (video/audio) sources are preserved for upcoming ingestion pipelines.', 'presshub-ai-editor' ); ?>
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
        $id       = esc_attr( $source['id'] ?? '' );
        $name     = esc_html( $source['name'] ?? '' );
        $url      = esc_url( $source['url'] ?? '' );
        $type     = esc_attr( $source['type'] ?? 'text_news' );
        $enabled  = ! empty( $source['enabled'] );
        $category = esc_html( $source['category'] ?? 'General' );
        $notes    = esc_html( $source['notes'] ?? '' );

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
        $html .= '<td style="text-align: center; vertical-align: middle;">';
        $html .= '<button type="button" class="presshub-source-toggle-status presshub-status-pill ' . $status_class . '" title="' . esc_attr( $toggle_title ) . '" data-id="' . $id . '">';
        $html .= esc_html( $status_text );
        $html .= '</button>';
        $html .= '</td>';

        $html .= '<td style="vertical-align: middle;">';
        $html .= '<strong class="presshub-source-name">' . $name . '</strong>';
        if ( '' !== $notes ) {
            $html .= '<div class="presshub-source-subnote" style="color: #646970; font-size: 11px; margin-top: 2px;">' . $notes . '</div>';
        }
        $html .= '</td>';

        $html .= '<td style="vertical-align: middle;">';
        $html .= '<a href="' . $url . '" target="_blank" rel="noopener noreferrer" class="presshub-source-url code" style="word-break: break-all;">' . esc_html( $url ) . ' <span class="dashicons dashicons-external" style="font-size: 12px; width: 12px; height: 12px; text-decoration: none; vertical-align: middle;"></span></a>';
        $html .= '</td>';

        $html .= '<td style="vertical-align: middle;">';
        $html .= '<span class="presshub-media-badge ' . esc_attr( $badge_class ) . '">';
        $html .= '<span class="dashicons ' . esc_attr( $icon_class ) . '" style="font-size: 14px; width: 14px; height: 14px; margin-right: 4px; vertical-align: middle;"></span>';
        $html .= esc_html( $type_label );
        $html .= '</span>';
        $html .= '</td>';

        $html .= '<td style="vertical-align: middle;">';
        $html .= '<span class="presshub-category-pill">' . $category . '</span>';
        $html .= '</td>';

        $html .= '<td style="text-align: right; vertical-align: middle;">';
        $html .= '<div class="presshub-source-actions" style="display: flex; gap: 6px; justify-content: flex-end;">';
        $html .= '<button type="button" class="button button-small presshub-source-test-btn" data-url="' . $url . '" data-type="' . $type . '" title="' . esc_attr__( 'Test connectivity', 'presshub-ai-editor' ) . '">';
        $html .= '<span class="dashicons dashicons-networking" style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-top;"></span> ' . esc_html__( 'Test', 'presshub-ai-editor' );
        $html .= '</button>';
        $html .= '<button type="button" class="button button-small presshub-source-edit-btn" data-id="' . $id . '" title="' . esc_attr__( 'Edit source', 'presshub-ai-editor' ) . '">';
        $html .= '<span class="dashicons dashicons-edit" style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-top;"></span> ' . esc_html__( 'Edit', 'presshub-ai-editor' );
        $html .= '</button>';
        $html .= '<button type="button" class="button button-small presshub-source-delete-btn" data-id="' . $id . '" title="' . esc_attr__( 'Delete source', 'presshub-ai-editor' ) . '" style="color: #b32d2e;">';
        $html .= '<span class="dashicons dashicons-trash" style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-top;"></span>';
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
        <div id="presshub-source-modal" class="presshub-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="presshub-source-modal-title" style="display:none;">
            <div class="presshub-modal presshub-source-modal-content">
                <div class="presshub-modal-header" style="display: flex; justify-content: space-between; align-items: center; padding: 14px 20px; border-bottom: 1px solid #dcdcde;">
                    <h2 id="presshub-source-modal-title" style="margin:0; font-size: 16px;"><?php echo esc_html__( 'Add News Source', 'presshub-ai-editor' ); ?></h2>
                    <button type="button" class="presshub-modal-close" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #666;" aria-label="<?php echo esc_attr__( 'Close', 'presshub-ai-editor' ); ?>">&times;</button>
                </div>
                <div class="presshub-modal-body" style="padding: 20px; max-height: 70vh; overflow-y: auto;">
                    <form id="presshub-source-form">
                        <input type="hidden" id="source-form-id" name="id" value="" />
                        
                        <div class="presshub-form-row" style="display: flex; gap: 15px; margin-bottom: 15px;">
                            <div class="presshub-form-group" style="flex: 1;">
                                <label for="source-form-name"><strong><?php echo esc_html__( 'Source Name:', 'presshub-ai-editor' ); ?> <span style="color:red;">*</span></strong></label>
                                <input type="text" id="source-form-name" name="name" class="widefat" required placeholder="<?php echo esc_attr__( 'e.g. Η Καθημερινή', 'presshub-ai-editor' ); ?>" style="margin-top: 4px;" />
                            </div>
                            <div class="presshub-form-group" style="flex: 1;">
                                <label for="source-form-type"><strong><?php echo esc_html__( 'Media Type:', 'presshub-ai-editor' ); ?></strong></label>
                                <select id="source-form-type" name="type" class="widefat" style="margin-top: 4px;">
                                    <?php foreach ( $types as $t_key => $t_info ) : ?>
                                        <option value="<?php echo esc_attr( $t_key ); ?>">
                                            <?php echo esc_html( $t_info['label'] ); ?> <?php echo $t_info['active'] ? esc_html__( '[Active Harvester]', 'presshub-ai-editor' ) : esc_html__( '[Planned Multi-Modal]', 'presshub-ai-editor' ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="presshub-form-group" style="margin-bottom: 15px;">
                            <label for="source-form-url"><strong><?php echo esc_html__( 'Source URL / RSS Feed:', 'presshub-ai-editor' ); ?> <span style="color:red;">*</span></strong></label>
                            <input type="url" id="source-form-url" name="url" class="widefat code" required placeholder="https://www.kathimerini.gr" style="margin-top: 4px;" />
                            <p class="description"><?php echo esc_html__( 'Homepage URL or direct RSS/Atom feed URL to scrape for daily news articles.', 'presshub-ai-editor' ); ?></p>
                        </div>

                        <div class="presshub-form-row" style="display: flex; gap: 15px; margin-bottom: 15px;">
                            <div class="presshub-form-group" style="flex: 1;">
                                <label for="source-form-category"><strong><?php echo esc_html__( 'Category / Beat:', 'presshub-ai-editor' ); ?></strong></label>
                                <input type="text" id="source-form-category" name="category" class="widefat" placeholder="<?php echo esc_attr__( 'e.g. General, Economy, Tech', 'presshub-ai-editor' ); ?>" style="margin-top: 4px;" />
                            </div>
                            <div class="presshub-form-group" style="flex: 1; display: flex; align-items: flex-end; padding-bottom: 4px;">
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin: 0;">
                                    <input type="checkbox" id="source-form-enabled" name="enabled" value="1" checked="checked" />
                                    <strong><?php echo esc_html__( 'Enable in Daily Harvest', 'presshub-ai-editor' ); ?></strong>
                                </label>
                            </div>
                        </div>

                        <div class="presshub-form-group" style="margin-bottom: 15px;">
                            <label for="source-form-notes"><strong><?php echo esc_html__( 'Editorial Notes:', 'presshub-ai-editor' ); ?></strong></label>
                            <textarea id="source-form-notes" name="notes" class="widefat" rows="2" placeholder="<?php echo esc_attr__( 'Optional editorial notes or scraping context...', 'presshub-ai-editor' ); ?>" style="margin-top: 4px;"></textarea>
                        </div>

                        <div id="presshub-source-form-notice" style="display: none; margin-top: 10px;"></div>
                    </form>
                </div>
                <div class="presshub-modal-actions" style="display: flex; justify-content: space-between; align-items: center; padding: 14px 20px; border-top: 1px solid #dcdcde; background: #f6f7f7;">
                    <div>
                        <button type="button" id="presshub-source-form-test" class="button button-secondary">
                            <span class="dashicons dashicons-networking" style="vertical-align:text-bottom; margin-right:2px;"></span> <?php echo esc_html__( 'Test Connection', 'presshub-ai-editor' ); ?>
                        </button>
                        <span id="presshub-source-form-spinner" class="spinner" role="status" style="float: none; margin: 0 0 0 6px;"><span class="screen-reader-text"></span></span>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="button button-secondary presshub-modal-cancel"><?php echo esc_html__( 'Cancel', 'presshub-ai-editor' ); ?></button>
                        <button type="button" id="presshub-source-form-save" class="button button-primary"><?php echo esc_html__( 'Save Source', 'presshub-ai-editor' ); ?></button>
                    </div>
                </div>
            </div>
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
        <p class="description"><?php echo __( 'Instruction preset applied to the podcast producer dialogue agent.', 'presshub-ai-editor' ); ?></p>
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
            <?php echo esc_html__( 'Google AI Studio produces natural, expressive Greek conversational speech using your existing Gemini API key. No separate TTS configuration needed.', 'presshub-ai-editor' ); ?>
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

    public function render_briefing_tts_model_field() {
        $option = 'presshub_ai_briefing_tts_model';
        $value  = (string) get_option( $option, PressHub_AI_Settings_Migration::default_briefing_tts_model() );
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text code" placeholder="gemini-3.1-flash-tts-preview" />
        <p class="description">
            <?php echo esc_html__( 'Model ID used for speech synthesis (e.g. gemini-3.1-flash-tts-preview, gemini-2.5-flash-preview-tts, or custom endpoint).', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    public function render_briefing_host_female_field() {
        $option = 'presshub_ai_briefing_host_female';
        $value  = (string) get_option( $option, PressHub_AI_Settings_Migration::default_briefing_host_female() );
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" />
        <p class="description"><?php echo __( 'Name of the lead female presenter (e.g. Μαρία). Default Μαρία.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_host_male_field() {
        $option = 'presshub_ai_briefing_host_male';
        $value  = (string) get_option( $option, PressHub_AI_Settings_Migration::default_briefing_host_male() );
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" />
        <p class="description"><?php echo __( 'Name of the co-host / male commentator (e.g. Νίκος). Default Νίκος.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_voice_female_field() {
        $option   = 'presshub_ai_briefing_voice_female';
        $selected = (string) get_option( $option, PressHub_AI_Settings_Migration::default_briefing_voice_female() );
        $synthesizer = class_exists( 'PressHub_AI_Audio_Synthesizer' ) ? new PressHub_AI_Audio_Synthesizer() : null;
        $gemini_voices = $synthesizer ? ( $synthesizer->get_available_voices( 'gemini' )['female'] ?? [] ) : [];
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <?php foreach ( $gemini_voices as $key => $v ) : ?>
                <option value="<?php echo self::esc_attr_safe( $key ); ?>" <?php echo $selected === $key ? 'selected="selected"' : ''; ?>>
                    <?php echo self::esc_html_safe( $v['label'] ?? $key ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php echo __( 'Voice model used for the lead presenter (Μαρία). Default: Kore / Κόρη.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_voice_male_field() {
        $option   = 'presshub_ai_briefing_voice_male';
        $selected = (string) get_option( $option, PressHub_AI_Settings_Migration::default_briefing_voice_male() );
        $synthesizer = class_exists( 'PressHub_AI_Audio_Synthesizer' ) ? new PressHub_AI_Audio_Synthesizer() : null;
        $gemini_voices = $synthesizer ? ( $synthesizer->get_available_voices( 'gemini' )['male'] ?? [] ) : [];
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <?php foreach ( $gemini_voices as $key => $v ) : ?>
                <option value="<?php echo self::esc_attr_safe( $key ); ?>" <?php echo $selected === $key ? 'selected="selected"' : ''; ?>>
                    <?php echo self::esc_html_safe( $v['label'] ?? $key ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php echo __( 'Voice model used for the co-host / commentator (Νίκος). Default: Fenrir / Φένριρ.', 'presshub-ai-editor' ); ?></p>
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

    public function render_briefing_text_category_field() {
        $option   = 'presshub_ai_briefing_text_category';
        $selected = (int) get_option( $option, 0 );
        $terms    = function_exists( 'get_terms' ) ? get_terms( [ 'taxonomy' => 'category', 'hide_empty' => false ] ) : [];
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <option value="0" <?php echo 0 === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'None (Default Category)', 'presshub-ai-editor' ); ?></option>
            <?php if ( ! is_wp_error( $terms ) && is_array( $terms ) ) : ?>
                <?php foreach ( $terms as $term ) : ?>
                    <?php if ( is_object( $term ) && isset( $term->term_id ) ) : ?>
                        <option value="<?php echo (int) $term->term_id; ?>" <?php echo $selected === (int) $term->term_id ? 'selected="selected"' : ''; ?>>
                            <?php echo self::esc_html_safe( $term->name ); ?>
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
        <p class="description"><?php echo __( 'WordPress post category assigned to text briefing posts.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_podcast_category_field() {
        $option   = 'presshub_ai_briefing_podcast_category';
        $selected = (int) get_option( $option, 0 );
        $terms    = function_exists( 'get_terms' ) ? get_terms( [ 'taxonomy' => 'category', 'hide_empty' => false ] ) : [];
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <option value="0" <?php echo 0 === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'None (Default Category)', 'presshub-ai-editor' ); ?></option>
            <?php if ( ! is_wp_error( $terms ) && is_array( $terms ) ) : ?>
                <?php foreach ( $terms as $term ) : ?>
                    <?php if ( is_object( $term ) && isset( $term->term_id ) ) : ?>
                        <option value="<?php echo (int) $term->term_id; ?>" <?php echo $selected === (int) $term->term_id ? 'selected="selected"' : ''; ?>>
                            <?php echo self::esc_html_safe( $term->name ); ?>
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
        <p class="description"><?php echo __( 'WordPress post category assigned to synthesized podcast posts.', 'presshub-ai-editor' ); ?></p>
        <?php
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
        <p class="description"><?php echo __( 'Custom system prompt for Greek text story curation. Leave empty to use standard prompt. Supports placeholders: {date}, {sources_list}, {articles_count}, {articles_context}.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_podcast_prompt_field() {
        $option  = 'presshub_ai_briefing_podcast_prompt';
        $default = class_exists( 'PressHub_AI_Podcast_Producer' )
            ? ( new PressHub_AI_Podcast_Producer() )->get_default_dialogue_prompt()
            : '';
        $value   = (string) get_option( $option, '' );
        ?>
        <textarea name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" rows="6" class="large-text code" placeholder="<?php echo esc_attr( __( 'Leave empty to use standard Greek conversational podcast prompt...', 'presshub-ai-editor' ) ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
        <p>
            <button type="button" class="button button-secondary presshub-reset-prompt" data-target="<?php echo self::esc_attr_safe( $option ); ?>" data-default="">
                <?php echo __( 'Clear / Reset Custom Prompt', 'presshub-ai-editor' ); ?>
            </button>
            <button type="button" class="button button-secondary presshub-show-default-prompt" data-target="<?php echo self::esc_attr_safe( $option ); ?>" data-default="<?php echo self::esc_attr_safe( $default ); ?>">
                <?php echo __( 'Load Default Template for Editing', 'presshub-ai-editor' ); ?>
            </button>
        </p>
        <p class="description"><?php echo __( 'Custom system prompt for Greek podcast dialogue generation. Leave empty to use standard prompt. Supports placeholders: {date}, {sources_list}, {articles_context}, {duration_text}, {word_budget}, {host1_name}, {host2_name}.', 'presshub-ai-editor' ); ?></p>
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
}
