<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-settings-storage.php';

class PressHub_AI_Metaboxes {
    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add_coauthor_metabox' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_podcast_metabox' ], 10, 2 );
        add_action( 'save_post', [ $this, 'save_podcast_metabox_data' ] );
        add_action( 'save_post', [ $this, 'save_post_data' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function enqueue_assets( $hook ) {
        if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'presshub-ai-admin-css', PRESSHUB_AI_URL . 'assets/admin.css', [], PRESSHUB_AI_VERSION );
        wp_enqueue_script( 'presshub-ai-admin-js', PRESSHUB_AI_URL . 'assets/admin.js', [ 'jquery', 'wp-i18n' ], PRESSHUB_AI_VERSION, true );

        wp_localize_script( 'presshub-ai-admin-js', 'presshubAI', [
            'ajax_url'     => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'presshub_ai_nonce' ),
            'qa_min_score' => class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_qa_min_score() : 80,
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
            <label for="presshub-ai-files"><?php echo esc_html__( 'Attach research files (PDF, DOCX, MP3, MP4, WAV, M4A)', 'presshub-ai-editor' ); ?></label>
            <input type="file" id="presshub-ai-files" multiple accept=".pdf,.docx,.mp3,.mp4,.wav,.m4a" style="margin-bottom: 10px; display: block;" />
            <label for="presshub-ai-sources"><?php echo esc_html__( 'Or paste URLs / notes', 'presshub-ai-editor' ); ?></label>
            <textarea id="presshub-ai-sources" rows="4" maxlength="20000" style="width:100%;" placeholder="<?php echo esc_attr__( 'Paste notes or URLs here...', 'presshub-ai-editor' ); ?>"></textarea>

            <h3><label for="presshub-ai-instructions"><?php echo esc_html__( 'Journalist Instructions', 'presshub-ai-editor' ); ?></label></h3>
            <p class="description"><?php echo esc_html__( 'What should the AI focus on in this draft?', 'presshub-ai-editor' ); ?></p>
            <textarea id="presshub-ai-instructions" rows="2" maxlength="5000" style="width:100%;"></textarea>

            <?php $this->render_preset_selector(); ?>

            <button type="button" id="presshub-ai-generate-draft" class="button button-primary" data-post-id="<?php echo esc_attr( $post->ID ); ?>" style="margin-top: 10px;">
                <?php echo esc_html__( 'Generate Initial Draft', 'presshub-ai-editor' ); ?>
            </button>
            <span id="presshub-ai-draft-spinner" class="spinner" role="status"><span class="screen-reader-text"></span></span>

            <hr />

            <h3><?php echo esc_html__( 'Editorial Scorecard', 'presshub-ai-editor' ); ?></h3>
            <button type="button" id="presshub-ai-run-review" class="button" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
                <?php echo esc_html__( 'Run AI Editorial Review', 'presshub-ai-editor' ); ?>
            </button>
            <span id="presshub-ai-review-spinner" class="spinner" role="status"><span class="screen-reader-text"></span></span>

            <div id="presshub-ai-scorecard-results" style="margin-top: 15px;">
                <?php if ( is_array( $scorecard ) && isset( $scorecard['score'] ) && is_numeric( $scorecard['score'] ) ) : ?>
                    <?php
                    // ME-4 / F-23: color-coded tone classes mirror the
                    // admin.js renderer (red < 50, yellow 50-min_score, green >= min_score).
                    $score     = (int) $scorecard['score'];
                    $min_score = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_qa_min_score() : 80;
                    $tone      = $score < 50 ? 'presshub-score-low' : ( $score < $min_score ? 'presshub-score-mid' : 'presshub-score-high' );
                    $libel_risk = isset( $scorecard['libel_risk'] ) && is_string( $scorecard['libel_risk'] ) && '' !== trim( $scorecard['libel_risk'] ) ? trim( $scorecard['libel_risk'] ) : '';
                    $legal_concerns = isset( $scorecard['legal_concerns'] ) && is_string( $scorecard['legal_concerns'] ) ? trim( $scorecard['legal_concerns'] ) : '';
                    ?>
                    <div class="scorecard-box <?php echo esc_attr( $tone ); ?>">
                        <span class="presshub-score-badge" aria-hidden="true"><?php echo esc_html( $score . '/100' ); ?></span>
                        <strong><?php
                            /* translators: %s: numeric score 0-100. */
                            echo esc_html( sprintf( __( 'Score: %s/100', 'presshub-ai-editor' ), $score ) );
                        ?></strong>
                        <?php if ( '' !== $libel_risk ) : ?>
                            <?php $libel_class = 'presshub-libel-' . strtolower( $libel_risk ); ?>
                            <span class="presshub-libel-badge <?php echo esc_attr( $libel_class ); ?>">
                                <?php
                                /* translators: %s: libel risk rating (Low, Medium, High). */
                                echo esc_html( sprintf( __( 'Libel Risk: %s', 'presshub-ai-editor' ), $libel_risk ) );
                                ?>
                            </span>
                        <?php endif; ?>
                        <?php if ( isset( $scorecard['feedback'] ) && is_string( $scorecard['feedback'] ) ) : ?>
                            <p><?php echo esc_html( $scorecard['feedback'] ); ?></p>
                        <?php endif; ?>
                        <?php if ( '' !== $legal_concerns && 'None identified' !== $legal_concerns ) : ?>
                            <div class="presshub-legal-concerns">
                                <strong><?php echo esc_html__( 'Legal Concerns:', 'presshub-ai-editor' ); ?></strong>
                                <p><?php echo esc_html( $legal_concerns ); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <hr />
            <h3><?php echo esc_html__( 'AI Featured Image Studio', 'presshub-ai-editor' ); ?></h3>
            <p class="description">
                <?php echo esc_html__( 'Analyze the article text to draft an authentic photojournalistic prompt, synthesize a realistic image, and assign it as the featured image.', 'presshub-ai-editor' ); ?>
            </p>

            <div class="presshub-image-studio-actions" style="margin-bottom: 12px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <button type="button" id="presshub-ai-draft-image-prompt" class="button" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
                    <span class="dashicons dashicons-admin-customizer" style="vertical-align: middle; margin-right: 4px;"></span>
                    <?php echo esc_html__( 'Draft Image Prompt from Content', 'presshub-ai-editor' ); ?>
                </button>
                <button type="button" id="presshub-ai-generate-image-btn" class="button button-primary" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
                    <span class="dashicons dashicons-format-image" style="vertical-align: middle; margin-right: 4px;"></span>
                    <?php echo esc_html__( 'Generate Image', 'presshub-ai-editor' ); ?>
                </button>
                <span id="presshub-ai-image-spinner" class="spinner" role="status" style="float: none; margin: 0;"><span class="screen-reader-text"></span></span>
            </div>

            <div class="presshub-image-studio-prompt-wrap" style="margin-bottom: 15px;">
                <label for="presshub-ai-image-prompt" style="display: block; font-weight: 600; margin-bottom: 5px;">
                    <?php echo esc_html__( 'Visual Image Prompt (editable):', 'presshub-ai-editor' ); ?>
                </label>
                <?php $current_img_prompt = (string) get_post_meta( $post->ID, '_presshub_ai_image_prompt', true ); ?>
                <textarea id="presshub-ai-image-prompt" name="presshub_ai_image_prompt" rows="3" style="width: 100%; font-size: 13px; line-height: 1.4;" placeholder="<?php echo esc_attr__( 'Click "Draft Image Prompt from Content" or enter a custom prompt...', 'presshub-ai-editor' ); ?>"><?php echo esc_textarea( $current_img_prompt ); ?></textarea>
            </div>

            <?php
            $thumb_id  = (int) get_post_thumbnail_id( $post->ID );
            $thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'medium_large' ) : '';
            ?>
            <div id="presshub-ai-image-preview-container" style="display: <?php echo $thumb_url ? 'block' : 'none'; ?>; margin-top: 15px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                    <?php echo esc_html__( 'Featured Image Preview:', 'presshub-ai-editor' ); ?>
                </label>
                <div class="presshub-image-studio-card" style="border: 1px solid #ccd0d4; background: #fff; padding: 12px; border-radius: 4px; max-width: 500px;">
                    <img id="presshub-ai-image-preview-img" src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php echo esc_attr__( 'Featured Image Preview', 'presshub-ai-editor' ); ?>" style="max-width: 100%; height: auto; display: block; border-radius: 3px; margin-bottom: 10px;" />
                    
                    <div class="presshub-image-studio-card-actions" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        <button type="button" id="presshub-ai-set-featured-btn" class="button button-secondary" data-post-id="<?php echo esc_attr( $post->ID ); ?>" data-attachment-id="<?php echo esc_attr( $thumb_id ); ?>" <?php echo $thumb_id ? '' : 'disabled'; ?>>
                            <?php echo esc_html__( 'Set as Featured Image', 'presshub-ai-editor' ); ?>
                        </button>
                        <span id="presshub-ai-image-status" style="font-weight: 600; font-size: 13px;"></span>
                    </div>
                </div>
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
        // slug => bare display name, used to render the effective default
        // in the label (ME-7 / F-05).
        $name_map = [];

        foreach ( $author_presets as $preset ) {
            if ( empty( $preset['enabled'] ) ) {
                continue;
            }
            $seen[ $preset['slug'] ] = true;
            $name_map[ $preset['slug'] ] = $preset['name'];
            $options[] = [ 'value' => $preset['slug'], 'label' => $preset['name'] ];
        }

        // First enabled plugin default the author hasn't disabled — the
        // fallback that applies when no author default slug is set.
        $first_plugin_default_name = '';
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
            if ( $first_plugin_default_name === '' ) {
                $first_plugin_default_name = $preset['name'];
            }
            $name_map[ $preset['slug'] ] = $preset['name'];
            $options[] = [
                'value' => $preset['slug'],
                /* translators: appended to a plugin-default preset label. */
                'label' => $preset['name'] . __( ' (default)', 'presshub-ai-editor' ),
            ];
        }

        $selected = ( $default_slug !== '' && isset( $seen[ $default_slug ] ) )
            ? $default_slug
            : '__plugin_default__';

        // The name of the preset that applies when the journalist does not
        // pick one explicitly: the author's default when set, else the
        // first enabled plugin default (mirrors the resolver's fallback).
        $default_name = '';
        if ( $default_slug !== '' && isset( $name_map[ $default_slug ] ) ) {
            $default_name = $name_map[ $default_slug ];
        } elseif ( $first_plugin_default_name !== '' ) {
            $default_name = $first_plugin_default_name;
        }
        ?>
        <h3><label for="presshub-ai-preset"><?php
            if ( $default_name !== '' ) {
                /* translators: %s: display name of the effective default preset. */
                echo esc_html( sprintf( __( 'Author Style Preset (default: %s)', 'presshub-ai-editor' ), $default_name ) );
            } else {
                echo esc_html__( 'Author Style Preset', 'presshub-ai-editor' );
            }
        ?></label></h3>
        <select id="presshub-ai-preset" name="instruction_preset_id" style="width:100%;">
            <option value="__plugin_default__"<?php echo $selected === '__plugin_default__' ? ' selected="selected"' : ''; ?>><?php echo esc_html__( '— Use my default —', 'presshub-ai-editor' ); ?></option>
            <?php foreach ( $options as $option ) : ?>
                <option value="<?php echo esc_attr( $option['value'] ); ?>"<?php echo $selected === $option['value'] ? ' selected="selected"' : ''; ?>><?php echo esc_html( $option['label'] ); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php echo esc_html__( 'Pick a preset to influence the system prompt. The per-article instructions above still take precedence in the user prompt.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    /**
     * Add podcast script and audio editor metabox for podcast briefing posts.
     *
     * @param string   $post_type Current post type.
     * @param \WP_Post $post      Current post object.
     */
    public function add_podcast_metabox( $post_type, $post ) {
        if ( 'post' !== $post_type || ! is_object( $post ) ) {
            return;
        }
        $type = get_post_meta( $post->ID, '_presshub_briefing_type', true );
        if ( 'podcast' !== $type ) {
            return;
        }

        add_meta_box(
            'presshub_ai_podcast_editor',
            __( 'PressHub Podcast Audio & Script Editor', 'presshub-ai-editor' ),
            [ $this, 'render_podcast_metabox' ],
            'post',
            'normal',
            'high'
        );
    }

    /**
     * Render the podcast dialogue script editor metabox.
     *
     * @param \WP_Post $post Current post object.
     */
    public function render_podcast_metabox( $post ) {
        $audio_url = (string) get_post_meta( $post->ID, '_presshub_audio_url', true );
        $script    = (string) get_post_meta( $post->ID, '_presshub_podcast_script', true );
        $date      = (string) get_post_meta( $post->ID, '_presshub_briefing_date', true );
        $edition   = (string) get_post_meta( $post->ID, '_presshub_briefing_edition', true );

        // If meta script is missing, try loading from saved snapshot
        if ( empty( $script ) && class_exists( 'PressHub_AI_Podcast_Producer' ) ) {
            $producer = new PressHub_AI_Podcast_Producer();
            $script = (string) $producer->get_script( $date, $edition ?: 'morning' );
        }

        wp_nonce_field( 'presshub_podcast_save_script', 'presshub_podcast_save_nonce' );
        ?>
        <div class="presshub-podcast-editor-container" style="padding: 10px 0;">
            <?php if ( ! empty( $audio_url ) ) : ?>
                <div style="margin-bottom: 15px;">
                    <label style="display:block; font-weight: 600; margin-bottom: 5px;"><?php echo esc_html__( 'Current Podcast Audio Preview:', 'presshub-ai-editor' ); ?></label>
                    <audio id="presshub-podcast-audio-player" controls src="<?php echo esc_url( $audio_url ); ?>" style="width: 100%; max-width: 600px;"></audio>
                </div>
            <?php endif; ?>

            <label for="presshub_podcast_script" style="display:block; font-weight: 600; margin-bottom: 5px;">
                <?php echo esc_html__( 'Podcast Dialogue Script:', 'presshub-ai-editor' ); ?>
            </label>
            <p class="description" style="margin-bottom: 10px;">
                <?php echo esc_html__( 'You can edit the dialogue script below. Once edited, click "Regenerate Audio from Script" to re-synthesize the voice audio and update this post.', 'presshub-ai-editor' ); ?>
            </p>
            <textarea id="presshub_podcast_script" name="presshub_podcast_script" rows="14" style="width: 100%; font-family: monospace; font-size: 13px; line-height: 1.5; padding: 10px;"><?php echo esc_textarea( $script ); ?></textarea>

            <div style="margin-top: 15px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <button type="button" id="presshub-regenerate-podcast-btn" class="button button-primary" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
                    <?php echo esc_html__( 'Regenerate Audio from Script', 'presshub-ai-editor' ); ?>
                </button>
                <span id="presshub-regenerate-podcast-spinner" class="spinner" role="status" style="float:none; margin:0;"><span class="screen-reader-text"></span></span>
                <span id="presshub-regenerate-podcast-status" style="font-weight: 600;"></span>
            </div>
        </div>
        <?php
    }

    /**
     * Save dialogue script changes when saving a podcast post.
     *
     * @param int $post_id Post ID.
     */
    public function save_podcast_metabox_data( $post_id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! isset( $_POST['presshub_podcast_save_nonce'] ) || ! wp_verify_nonce( $_POST['presshub_podcast_save_nonce'], 'presshub_podcast_save_script' ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        if ( isset( $_POST['presshub_podcast_script'] ) ) {
            $script = wp_unslash( $_POST['presshub_podcast_script'] );
            update_post_meta( $post_id, '_presshub_podcast_script', $script );
        }
    }

    /**
     * Save AI Featured Image prompt when saving a post.
     *
     * @param int $post_id Post ID.
     */
    public function save_post_data( $post_id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        if ( isset( $_POST['presshub_ai_image_prompt'] ) ) {
            $prompt = sanitize_textarea_field( wp_unslash( $_POST['presshub_ai_image_prompt'] ) );
            update_post_meta( $post_id, '_presshub_ai_image_prompt', $prompt );
        }
    }
}
