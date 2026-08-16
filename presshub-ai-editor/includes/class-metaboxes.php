<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PressHub_AI_Metaboxes {
    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add_coauthor_metabox' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function enqueue_assets( $hook ) {
        if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'presshub-ai-admin-css', PRESSHUB_AI_URL . 'assets/admin.css', [], PRESSHUB_AI_VERSION );
        wp_enqueue_script( 'presshub-ai-admin-js', PRESSHUB_AI_URL . 'assets/admin.js', [ 'jquery', 'wp-i18n' ], PRESSHUB_AI_VERSION, true );

        wp_localize_script( 'presshub-ai-admin-js', 'presshubAI', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'presshub_ai_nonce' )
        ] );
    }

    public function add_coauthor_metabox() {
        add_meta_box(
            'presshub_ai_coauthor',
            __( 'AI Co-Author & Editorial Review', 'presshub-ai-editor' ),
            [ $this, 'render_metabox' ],
            'post',
            'normal',
            'high'
        );
    }

    public function render_metabox( $post ) {
        $scorecard = get_post_meta( $post->ID, '_presshub_ai_scorecard', true );
        ?>
        <div class="presshub-ai-container">
            <h3><?php echo esc_html__( 'Multi-Modal Source Material', 'presshub-ai-editor' ); ?></h3>
            <p class="description"><?php echo esc_html__( 'Attach research files (PDF, DOCX, MP3, MP4) or paste URLs/notes.', 'presshub-ai-editor' ); ?></p>
            <input type="file" id="presshub-ai-files" multiple accept=".pdf,.docx,.mp3,.mp4,.wav,.m4a" style="margin-bottom: 10px; display: block;" />
            <textarea id="presshub-ai-sources" rows="4" maxlength="20000" style="width:100%;" placeholder="<?php echo esc_attr__( 'Paste notes or URLs here...', 'presshub-ai-editor' ); ?>"></textarea>

            <h3><?php echo esc_html__( 'Journalist Instructions', 'presshub-ai-editor' ); ?></h3>
            <p class="description"><?php echo esc_html__( 'What should the AI focus on in this draft?', 'presshub-ai-editor' ); ?></p>
            <textarea id="presshub-ai-instructions" rows="2" maxlength="5000" style="width:100%;"></textarea>

            <?php $this->render_preset_selector(); ?>

            <button type="button" id="presshub-ai-generate-draft" class="button button-primary" data-post-id="<?php echo esc_attr( $post->ID ); ?>" style="margin-top: 10px;">
                <?php echo esc_html__( 'Generate Initial Draft', 'presshub-ai-editor' ); ?>
            </button>
            <span id="presshub-ai-draft-spinner" class="spinner"></span>

            <hr />

            <h3><?php echo esc_html__( 'Editorial Scorecard', 'presshub-ai-editor' ); ?></h3>
            <button type="button" id="presshub-ai-run-review" class="button" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
                <?php echo esc_html__( 'Run AI Editorial Review', 'presshub-ai-editor' ); ?>
            </button>
            <span id="presshub-ai-review-spinner" class="spinner"></span>

            <div id="presshub-ai-scorecard-results" style="margin-top: 15px;">
                <?php if ( is_array( $scorecard ) && isset( $scorecard['score'] ) && is_numeric( $scorecard['score'] ) ) : ?>
                    <div class="scorecard-box">
                        <strong><?php
                            /* translators: %s: numeric score 0-100. */
                            echo esc_html( sprintf( __( 'Score: %s/100', 'presshub-ai-editor' ), $scorecard['score'] ) );
                        ?></strong>
                        <?php if ( isset( $scorecard['feedback'] ) && is_string( $scorecard['feedback'] ) ) : ?>
                            <p><?php echo esc_html( $scorecard['feedback'] ); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the per-request "Author Style Preset" dropdown (design doc
     * §4.1). Options: a sentinel first option, then the author's enabled
     * presets, then enabled plugin defaults the author hasn't disabled
     * (labelled " (default)"). Preselects the author's default preset
     * slug. The plugin-default library is seeded lazily on first load.
     */
    private function render_preset_selector(): void {
        $user_id = (int) get_current_user_id();

        if ( ! class_exists( 'PressHub_AI_Preset_Store' ) ) {
            require_once __DIR__ . '/class-preset-sanitizer.php';
            require_once __DIR__ . '/class-preset-store.php';
        }

        // Seed the curated plugin defaults lazily when the option has
        // never been written (idempotent — existing data is untouched).
        if ( false === get_option( PressHub_AI_Preset_Store::OPTION_DEFAULT_PRESETS, false ) ) {
            PressHub_AI_Preset_Store::seed_plugin_defaults();
        }

        $author_presets  = PressHub_AI_Preset_Store::get_author_presets( $user_id );
        $plugin_defaults = PressHub_AI_Preset_Store::get_plugin_defaults();
        $disabled        = PressHub_AI_Preset_Store::get_disabled_defaults( $user_id );
        $default_slug    = PressHub_AI_Preset_Store::get_author_default_slug( $user_id );

        $options = [];
        $seen    = [];

        foreach ( $author_presets as $preset ) {
            if ( empty( $preset['enabled'] ) ) {
                continue;
            }
            $seen[ $preset['slug'] ] = true;
            $options[] = [ 'value' => $preset['slug'], 'label' => $preset['name'] ];
        }

        foreach ( $plugin_defaults as $preset ) {
            if ( empty( $preset['enabled'] ) ) {
                continue;
            }
            if ( in_array( $preset['slug'], $disabled, true ) ) {
                continue;
            }
            // On a slug collision the author's own preset wins.
            if ( isset( $seen[ $preset['slug'] ] ) ) {
                continue;
            }
            $seen[ $preset['slug'] ] = true;
            $options[] = [
                'value' => $preset['slug'],
                /* translators: appended to a plugin-default preset label. */
                'label' => $preset['name'] . __( ' (default)', 'presshub-ai-editor' ),
            ];
        }

        $selected = ( $default_slug !== '' && isset( $seen[ $default_slug ] ) )
            ? $default_slug
            : '__plugin_default__';
        ?>
        <h3><?php echo esc_html__( 'Author Style Preset', 'presshub-ai-editor' ); ?></h3>
        <select id="presshub-ai-preset" name="instruction_preset_id" style="width:100%;">
            <option value="__plugin_default__"<?php echo $selected === '__plugin_default__' ? ' selected="selected"' : ''; ?>><?php echo esc_html__( '— Use my default —', 'presshub-ai-editor' ); ?></option>
            <?php foreach ( $options as $option ) : ?>
                <option value="<?php echo esc_attr( $option['value'] ); ?>"<?php echo $selected === $option['value'] ? ' selected="selected"' : ''; ?>><?php echo esc_html( $option['label'] ); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php echo esc_html__( 'Pick a preset to influence the system prompt. The per-article instructions above still take precedence in the user prompt.', 'presshub-ai-editor' ); ?></p>
        <?php
    }
}
