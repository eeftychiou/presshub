<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PressHub_AI_Settings {
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
    }

    public function enqueue_scripts( $hook ) {
        if ( 'settings_page_presshub-ai' === $hook ) {
            wp_enqueue_script( 'presshub-ai-admin-js', PRESSHUB_AI_URL . 'assets/admin.js', [ 'jquery' ], PRESSHUB_AI_VERSION, true );
            wp_localize_script( 'presshub-ai-admin-js', 'presshubAI', [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'presshub_ai_nonce' )
            ] );
        }
    }

    public function add_settings_page() {
        add_options_page(
            'PressHub AI Settings',
            'PressHub AI',
            'manage_options',
            'presshub-ai',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'presshub_ai_options', 'presshub_ai_provider' );
        register_setting( 'presshub_ai_options', 'presshub_ai_model' );
        register_setting( 'presshub_ai_options', 'presshub_ai_api_key' );
        register_setting( 'presshub_ai_options', 'presshub_ai_google_cloud_api_key' );
    }

    public function render_settings_page() {
        $provider = get_option( 'presshub_ai_provider', 'openai' );
        ?>
        <div class="wrap">
            <h1>PressHub AI Settings</h1>
            <form method="post" action="options.php" id="presshub-ai-settings-form">
                <?php settings_fields( 'presshub_ai_options' ); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">AI Provider</th>
                        <td>
                            <select name="presshub_ai_provider" id="presshub_ai_provider">
                                <option value="openai" <?php selected( $provider, 'openai' ); ?>>OpenAI</option>
                                <option value="anthropic" <?php selected( $provider, 'anthropic' ); ?>>Anthropic</option>
                                <option value="gemini" <?php selected( $provider, 'gemini' ); ?>>Google Gemini</option>
                            </select>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Model Name</th>
                        <td>
                            <input type="text" name="presshub_ai_model" id="presshub_ai_model" value="<?php echo esc_attr( get_option( 'presshub_ai_model', 'gpt-4o' ) ); ?>" class="regular-text" />
                            <p class="description">Examples: <code>gpt-4o</code>, <code>claude-3-5-sonnet-20240620</code>, <code>gemini-1.5-pro-latest</code></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">API Key</th>
                        <td>
                            <input type="password" name="presshub_ai_api_key" id="presshub_ai_api_key" value="<?php echo esc_attr( get_option( 'presshub_ai_api_key' ) ); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Google Cloud API Key (Imagen/TTS)</th>
                        <td>
                            <input type="password" name="presshub_ai_google_cloud_api_key" id="presshub_ai_google_cloud_api_key" value="<?php echo esc_attr( get_option( 'presshub_ai_google_cloud_api_key' ) ); ?>" class="regular-text" />
                            <p class="description">Required for Google Cloud Imagen and Text-to-Speech integration.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr />
            <h2>Test Connection</h2>
            <p>Save your settings first, then click below to verify the API key and model.</p>
            <button type="button" id="presshub-ai-test-api" class="button">Test API Connection</button>
            <span id="presshub-ai-test-spinner" class="spinner"></span>
            <div id="presshub-ai-test-result" style="margin-top: 10px; font-weight: bold;"></div>
        </div>
        <?php
    }
}
