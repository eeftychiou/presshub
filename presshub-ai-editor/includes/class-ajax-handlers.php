<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-rate-limiter.php';
require_once __DIR__ . '/class-preset-sanitizer.php';
require_once __DIR__ . '/class-preset-store.php';
require_once __DIR__ . '/class-preset-resolver.php';

class PressHub_AI_Ajax_Handlers {
    public function __construct() {
        add_action( 'wp_ajax_presshub_ai_generate_draft', [ $this, 'generate_draft' ] );
        add_action( 'wp_ajax_presshub_ai_run_review', [ $this, 'run_review' ] );
        add_action( 'wp_ajax_presshub_ai_test_api', [ $this, 'test_api_connection' ] );
        add_action( 'wp_ajax_presshub_ai_chat', [ $this, 'handle_chat_routing' ] );
        add_action( 'wp_ajax_presshub_ai_check_research', [ $this, 'check_research_status' ] );
        // Per-author instruction presets (2026-08-15 design §6.1).
        add_action( 'wp_ajax_presshub_ai_list_presets', [ $this, 'list_presets' ] );
        add_action( 'wp_ajax_presshub_ai_save_preset', [ $this, 'save_preset' ] );
        add_action( 'wp_ajax_presshub_ai_delete_preset', [ $this, 'delete_preset' ] );
        add_action( 'wp_ajax_presshub_ai_set_default_preset', [ $this, 'set_default_preset' ] );
        add_action( 'wp_ajax_presshub_ai_copy_default_preset', [ $this, 'copy_default_preset' ] );
    }

    /**
     * Per-user rate-limit bucket key.
     */
    private function rate_limit_key(): string {
        return 'presshub_ai_rl_' . (int) get_current_user_id();
    }

    /**
     * The configured per-window limit. Defaults to 30/hour if the
     * setting hasn't been saved yet.
     */
    private function rate_limit_per_window(): int {
        return (int) get_option( 'presshub_ai_rate_limit_per_hour', 30 );
    }

    /**
     * The configured window length (seconds). Defaults to 3600 (1 hour).
     */
    private function rate_limit_window_seconds(): int {
        return (int) get_option( 'presshub_ai_rate_limit_window_seconds', 3600 );
    }

    /**
     * Check the rate limiter; if blocked, send a JSON error and abort
     * the caller via wp_send_json_error's die semantics.
     */
    private function enforce_rate_limit(): void {
        $limiter = new PressHub_AI_Rate_Limiter();
        $result  = $limiter->check(
            $this->rate_limit_key(),
            $this->rate_limit_per_window(),
            $this->rate_limit_window_seconds()
        );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
    }

    /**
     * Record that a request just happened (only after the API call
     * succeeded — failed API calls don't burn the user's budget).
     */
    private function record_rate_limit(): void {
        $limiter = new PressHub_AI_Rate_Limiter();
        $limiter->record( $this->rate_limit_key() );
    }

    public function test_api_connection() {
        check_ajax_referer( 'presshub_ai_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $api = new PressHub_AI_API_Client();
        $result = $api->test_connection();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( 'API Connection Successful!' );
    }

    public function generate_draft() {
        check_ajax_referer( 'presshub_ai_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        if ( isset( $_POST['post_id'] ) ) {
            $post_id = intval( $_POST['post_id'] );
            if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
                wp_send_json_error( 'Permission denied.' );
            }
        }

        $sources = isset( $_POST['sources'] ) ? sanitize_textarea_field( $_POST['sources'] ) : '';
        $instructions = isset( $_POST['instructions'] ) ? sanitize_textarea_field( $_POST['instructions'] ) : '';

        // Per-request preset selection (2026-08-15 design §4.2): slug or
        // sentinel picked in the metabox; '' means "use the author's
        // default". Passed through to generate_draft() as the 4th arg.
        $preset_slug = sanitize_text_field( wp_unslash( $_POST['instruction_preset_id'] ?? '' ) );

        // Input length limits: guard the AI endpoints against oversized
        // payloads that would waste tokens / cost money.
        if ( strlen( $sources ) > 20000 ) {
            wp_send_json_error( 'Sources exceed the 20,000 character limit.' );
        }
        if ( strlen( $instructions ) > 5000 ) {
            wp_send_json_error( 'Instructions exceed the 5,000 character limit.' );
        }

        $uploaded_files = [];
        if ( ! empty( $_FILES['files'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $files = $_FILES['files'];
            foreach ( $files['name'] as $key => $value ) {
                if ( $files['name'][$key] ) {
                    $file = [
                        'name'     => $files['name'][$key],
                        'type'     => $files['type'][$key],
                        'tmp_name' => $files['tmp_name'][$key],
                        'error'    => $files['error'][$key],
                        'size'     => $files['size'][$key]
                    ];
                    $movefile = wp_handle_upload( $file, [ 'test_form' => false ] );
                    if ( $movefile && ! isset( $movefile['error'] ) ) {
                        $uploaded_files[] = $movefile['file']; // Absolute path
                    }
                }
            }
        }

        $this->enforce_rate_limit();
        $api = new PressHub_AI_API_Client();
        $draft = $api->generate_draft( $sources, $instructions, $uploaded_files, $preset_slug );

        // Clean up temporary uploads so we don't clutter the server unnecessarily
        foreach ( $uploaded_files as $file_path ) {
            @unlink( $file_path );
        }

        if ( is_wp_error( $draft ) ) {
            wp_send_json_error( $draft->get_error_message() );
        }

        $this->record_rate_limit();
        wp_send_json_success( [ 'draft' => wp_kses_post( $draft ) ] );
    }

    public function run_review() {
        check_ajax_referer( 'presshub_ai_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $content = isset( $_POST['content'] ) ? wp_kses_post( $_POST['content'] ) : '';
        if ( strlen( $content ) > 100000 ) {
            wp_send_json_error( 'Content exceeds the 100,000 character limit.' );
        }
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;

        if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $this->enforce_rate_limit();
        $api = new PressHub_AI_API_Client();
        $scorecard = $api->generate_scorecard( $content );

        if ( is_wp_error( $scorecard ) ) {
            wp_send_json_error( $scorecard->get_error_message() );
        }

        if ( $post_id ) {
            update_post_meta( $post_id, '_presshub_ai_scorecard', $scorecard );

            if ( isset( $scorecard['score'] ) && intval( $scorecard['score'] ) >= 80 ) {
                wp_update_post( [ 'ID' => $post_id, 'post_status' => 'pending' ] );
            }
        }

        $this->record_rate_limit();
        wp_send_json_success( $scorecard );
    }

    public function handle_chat_routing() {
        check_ajax_referer( 'presshub_ai_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $prompt = isset( $_POST['prompt'] ) ? sanitize_textarea_field( $_POST['prompt'] ) : '';
        if ( strlen( $prompt ) > 5000 ) {
            wp_send_json_error( 'Prompt exceeds the 5,000 character limit.' );
        }
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;

        if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        // Gate paid media generation (Imagen / Cloud TTS) to admins only.
        // Authors can still use chat + research. This prevents a low-priv
        // user from racking up Google Cloud costs without an admin's
        // explicit configuration of the project + keys.
        $pre_intent = isset( $_POST['intent'] ) ? sanitize_textarea_field( $_POST['intent'] ) : '';
        if ( in_array( $pre_intent, [ 'image', 'report' ], true ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        // Per-user rate limit for the AI-costly chat + research paths.
        // Image / report are admin-only above, so the rate limiter is
        // intentionally not consulted for those branches — the admin
        // gate is already the first line of defence.
        $this->enforce_rate_limit();

        $api = new PressHub_AI_API_Client();
        $intent = $api->classify_intent( $prompt );

        // Defence in depth: even if the LLM classifier resolves to an
        // image/report intent, an author must not be able to trigger the
        // paid call. (Belt-and-suspenders in case the client somehow
        // bypasses the pre_intent hint above.)
        if ( in_array( $intent, [ 'image', 'report' ], true ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        if ( 'chat' === $intent ) {
            // Per-author presets apply to chat too (design §3.3): the
            // author's preset (or per-request selection) is appended to the
            // chat system prompt before the new filter runs.
            $preset_slug = sanitize_text_field( wp_unslash( $_POST['instruction_preset_id'] ?? '' ) );
            $sys = 'You are a helpful AI journalist assistant.';
            $preset = PressHub_AI_Preset_Resolver::resolve_for_user( get_current_user_id(), 'chat', $preset_slug );
            if ( $preset !== null ) {
                $sys .= "\n\n" . $preset;
            }
            $sys = apply_filters( 'presshub_ai_chat_system_prompt', $sys );
            $result = $api->call_provider( $sys, $prompt, false, [] );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( $result->get_error_message() );
            }
            $this->record_rate_limit();
            wp_send_json_success( [ 'type' => 'chat', 'content' => $result ] );
        } elseif ( 'research' === $intent ) {
            $research_id = wp_insert_post( [
                'post_type' => 'presshub_research',
                'post_title' => 'Research for post #' . $post_id . ': ' . wp_html_excerpt( $prompt, 50, '...' ),
                'post_status' => 'publish'
            ] );
            if ( ! $research_id || is_wp_error( $research_id ) ) {
                $error_msg = is_wp_error( $research_id ) ? $research_id->get_error_message() : 'Failed to create research post.';
                wp_send_json_error( $error_msg );
            }
            update_post_meta( $research_id, '_research_status', 'pending' );
            update_post_meta( $research_id, '_research_prompt', $prompt );
            update_post_meta( $research_id, '_associated_post_id', $post_id );
            // Remember who initiated the research so the cron job can apply
            // that author's preset when composing the research prompt.
            update_post_meta( $research_id, '_research_user_id', get_current_user_id() );

            wp_schedule_single_event( time(), 'presshub_ai_do_research', [ $research_id ] );

            $this->record_rate_limit();
            wp_send_json_success( [ 'type' => 'research', 'status' => 'pending', 'research_id' => $research_id, 'scheduled_at' => time() ] );
        } elseif ( 'image' === $intent ) {
            $img = $api->generate_image_via_imagen( $prompt );
            if ( is_wp_error( $img ) ) {
                wp_send_json_error( $img->get_error_message() );
            }
            wp_send_json_success( [ 'type' => 'image', 'url' => $img['url'], 'id' => $img['id'] ] );
        } elseif ( 'report' === $intent ) {
            $audio = $api->generate_audio_report( $prompt, $post_id );
            if ( is_wp_error( $audio ) ) {
                wp_send_json_error( $audio->get_error_message() );
            }
            wp_send_json_success( [ 'type' => 'report', 'url' => $audio['url'], 'id' => $audio['id'] ] );
        }
    }

    public function check_research_status() {
        check_ajax_referer( 'presshub_ai_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }
        
        $research_id = isset( $_POST['research_id'] ) ? intval( $_POST['research_id'] ) : 0;
        if ( ! current_user_can( 'edit_post', $research_id ) ) {
            wp_send_json_error( 'Permission denied.' );
        }
        
        $post = get_post( $research_id );
        
        if ( ! $post || 'presshub_research' !== $post->post_type ) {
            wp_send_json_error( 'Invalid research post ID.' );
        }
        
        $status = get_post_meta( $research_id, '_research_status', true );

        if ( 'completed' === $status ) {
            wp_send_json_success( [ 'status' => 'completed', 'content' => $post->post_content ] );
        } elseif ( 'failed' === $status ) {
            $err = get_post_meta( $research_id, '_error_message', true );
            wp_send_json_success( [ 'status' => 'failed', 'error' => $err ] );
        } else {
            wp_send_json_success( [ 'status' => $status ? $status : 'pending' ] );
        }
    }

    // ------------------------------------------------------------------
    // Per-author instruction presets — AJAX CRUD (2026-08-15 design §6).
    //
    // All five handlers share the same nonce ('presshub_ai_nonce' /
    // 'nonce'), the same JSON envelope, and the same per-user coarse
    // throttle: max 60 preset mutations per minute (checked before the
    // mutation, recorded after it succeeds). The throttle reuses
    // PressHub_AI_Rate_Limiter with a dedicated bucket key so preset CRUD
    // never consumes the AI-call rate limit.
    // ------------------------------------------------------------------

    /**
     * Coarse per-user throttle bucket for preset mutations.
     */
    private function preset_throttle_key(): string {
        return 'presshub_ai_preset_' . (int) get_current_user_id();
    }

    /**
     * Check the preset throttle; wp_send_json_error when blocked.
     */
    private function enforce_preset_throttle(): void {
        $limiter = new PressHub_AI_Rate_Limiter();
        $result  = $limiter->check( $this->preset_throttle_key(), 60, 60 );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
    }

    /**
     * Record a successful preset mutation against the throttle bucket.
     */
    private function record_preset_throttle(): void {
        $limiter = new PressHub_AI_Rate_Limiter();
        $limiter->record( $this->preset_throttle_key() );
    }

    /**
     * AJAX: presshub_ai_list_presets — read-only listing for the metabox
     * dropdown and the profile/admin preset screens.
     *
     * Response: {
     *   own:          all of the author's presets (incl. disabled rows),
     *   defaults:     enabled plugin-default presets minus the slugs the
     *                 author has disabled,
     *   default_slug: the author's default preset slug ('' when unset).
     * }
     */
    public function list_presets() {
        check_ajax_referer( 'presshub_ai_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $user_id  = (int) get_current_user_id();
        $disabled = PressHub_AI_Preset_Store::get_disabled_defaults( $user_id );

        $defaults = [];
        foreach ( PressHub_AI_Preset_Store::get_plugin_defaults() as $preset ) {
            if ( ! $preset['enabled'] || in_array( $preset['slug'], $disabled, true ) ) {
                continue;
            }
            $defaults[] = $preset;
        }

        wp_send_json_success( [
            'own'          => PressHub_AI_Preset_Store::get_author_presets( $user_id ),
            'defaults'     => $defaults,
            'default_slug' => PressHub_AI_Preset_Store::get_author_default_slug( $user_id ),
        ] );
    }

    /**
     * AJAX: presshub_ai_save_preset — create or update one preset.
     *
     * POST params: scope ('author' default | 'plugin'), slug, name,
     * instruction_text, enabled ('1'/'0'; when omitted on an update the
     * existing enabled state is preserved, new presets default to enabled).
     *
     * Scope rules: 'author' requires edit_posts and always targets the
     * current user's own library; 'plugin' requires manage_options and
     * targets the plugin-default option.
     */
    public function save_preset() {
        check_ajax_referer( 'presshub_ai_nonce', 'nonce' );

        $scope = isset( $_POST['scope'] ) ? sanitize_text_field( wp_unslash( $_POST['scope'] ) ) : 'author';
        if ( 'plugin' === $scope ) {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'Permission denied.' );
            }
        } elseif ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $slug            = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
        $name            = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $instruction_text = isset( $_POST['instruction_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['instruction_text'] ) ) : '';

        if ( ! preg_match( PressHub_AI_Preset_Sanitizer::SLUG_REGEX, $slug ) ) {
            wp_send_json_error( 'Invalid preset slug. Use 1-40 lowercase letters, numbers, or hyphens.' );
        }
        if ( strlen( $name ) > PressHub_AI_Preset_Sanitizer::MAX_NAME_LENGTH ) {
            wp_send_json_error( 'Preset name exceeds the 80 character limit.' );
        }
        if ( strlen( $instruction_text ) > PressHub_AI_Preset_Sanitizer::MAX_INSTRUCTION_LEN ) {
            wp_send_json_error( 'Preset instructions exceed the 4,000 character limit.' );
        }

        $this->enforce_preset_throttle();

        $user_id = 0;
        if ( 'plugin' === $scope ) {
            $list = PressHub_AI_Preset_Store::get_plugin_defaults();
            $max  = 50;
        } else {
            $user_id = (int) get_current_user_id();
            $list    = PressHub_AI_Preset_Store::get_author_presets( $user_id );
            $max     = 25;
        }

        $exists      = false;
        $prev_enabled = true;
        foreach ( $list as $row ) {
            if ( $row['slug'] === $slug ) {
                $exists       = true;
                $prev_enabled = $row['enabled'];
                break;
            }
        }
        if ( ! $exists && count( $list ) >= $max ) {
            wp_send_json_error( "Preset limit reached ({$max} max)." );
        }

        $enabled = isset( $_POST['enabled'] )
            ? (bool) $_POST['enabled']
            : ( $exists ? $prev_enabled : true );

        $clean = PressHub_AI_Preset_Sanitizer::sanitize_preset( [
            'slug'             => $slug,
            'name'             => $name,
            'instruction_text' => $instruction_text,
            'enabled'          => $enabled,
        ] );
        if ( $clean === null ) {
            wp_send_json_error( 'Invalid preset data.' );
        }

        $rows    = [];
        $updated = false;
        foreach ( $list as $row ) {
            if ( $row['slug'] === $slug ) {
                $rows[]  = $clean;
                $updated = true;
            } else {
                $rows[] = $row;
            }
        }
        if ( ! $updated ) {
            $rows[] = $clean;
        }

        if ( 'plugin' === $scope ) {
            PressHub_AI_Preset_Store::save_plugin_defaults( $rows );
        } else {
            PressHub_AI_Preset_Store::save_author_presets( $user_id, $rows );
        }

        $this->record_preset_throttle();
        wp_send_json_success( $clean );
    }

    /**
     * AJAX: presshub_ai_delete_preset.
     *
     * Author scope: soft delete — sets enabled=false but keeps the row so
     * existing selections don't break. Plugin scope (manage_options):
     * hard delete — the row is removed from the plugin-default option.
     */
    public function delete_preset() {
        check_ajax_referer( 'presshub_ai_nonce', 'nonce' );

        $scope = isset( $_POST['scope'] ) ? sanitize_text_field( wp_unslash( $_POST['scope'] ) ) : 'author';
        if ( 'plugin' === $scope ) {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'Permission denied.' );
            }
        } elseif ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
        if ( ! preg_match( PressHub_AI_Preset_Sanitizer::SLUG_REGEX, $slug ) ) {
            wp_send_json_error( 'Invalid preset slug.' );
        }

        $this->enforce_preset_throttle();

        if ( 'plugin' === $scope ) {
            $list  = PressHub_AI_Preset_Store::get_plugin_defaults();
            $rows  = [];
            $found = false;
            foreach ( $list as $row ) {
                if ( $row['slug'] === $slug ) {
                    $found = true;
                    continue;
                }
                $rows[] = $row;
            }
            if ( ! $found ) {
                wp_send_json_error( 'Preset not found.' );
            }
            PressHub_AI_Preset_Store::save_plugin_defaults( $rows );
        } else {
            $user_id = (int) get_current_user_id();
            $list    = PressHub_AI_Preset_Store::get_author_presets( $user_id );
            $rows    = [];
            $found   = false;
            foreach ( $list as $row ) {
                if ( $row['slug'] === $slug ) {
                    $found          = true;
                    $row['enabled'] = false;
                }
                $rows[] = $row;
            }
            if ( ! $found ) {
                wp_send_json_error( 'Preset not found.' );
            }
            PressHub_AI_Preset_Store::save_author_presets( $user_id, $rows );
        }

        $this->record_preset_throttle();
        wp_send_json_success( [ 'slug' => $slug ] );
    }

    /**
     * AJAX: presshub_ai_set_default_preset — update the author's default
     * preset slug (user meta 'presshub_ai_default_preset_id').
     *
     * POST param: slug. '' clears the default. Any non-empty slug must
     * name an enabled author preset, or an enabled plugin-default preset
     * the author has not disabled — exactly the entries the dropdown
     * offers, so the stored default can never silently resolve to nothing.
     */
    public function set_default_preset() {
        check_ajax_referer( 'presshub_ai_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $slug    = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
        $user_id = (int) get_current_user_id();

        if ( $slug !== '' ) {
            if ( ! preg_match( PressHub_AI_Preset_Sanitizer::SLUG_REGEX, $slug ) ) {
                wp_send_json_error( 'Invalid preset slug.' );
            }
            if ( ! $this->preset_slug_is_selectable( $user_id, $slug ) ) {
                wp_send_json_error( 'Preset not found or not enabled.' );
            }
        }

        $this->enforce_preset_throttle();
        PressHub_AI_Preset_Store::set_author_default_slug( $user_id, $slug );
        $this->record_preset_throttle();
        wp_send_json_success( [ 'default_slug' => $slug ] );
    }

    /**
     * AJAX: presshub_ai_copy_default_preset — copy a plugin-default preset
     * into the author's own library (copy-on-edit model, design §2.2).
     *
     * POST param: slug (must name an enabled plugin-default preset). On
     * slug collision in the author's library the copy is stored as
     * '<slug>-copy-<n>'. Enforces the 25-preset author quota.
     */
    public function copy_default_preset() {
        check_ajax_referer( 'presshub_ai_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
        if ( ! preg_match( PressHub_AI_Preset_Sanitizer::SLUG_REGEX, $slug ) ) {
            wp_send_json_error( 'Invalid preset slug.' );
        }

        $source = null;
        foreach ( PressHub_AI_Preset_Store::get_plugin_defaults() as $preset ) {
            if ( $preset['slug'] === $slug ) {
                $source = $preset;
                break;
            }
        }
        if ( $source === null || ! $source['enabled'] ) {
            wp_send_json_error( 'Plugin default preset not found.' );
        }

        $this->enforce_preset_throttle();

        $user_id = (int) get_current_user_id();
        $list    = PressHub_AI_Preset_Store::get_author_presets( $user_id );

        $new_slug = $slug;
        $n        = 1;
        while ( $this->list_has_slug( $list, $new_slug ) ) {
            // Truncate the base so the '-copy-<n>' suffix keeps the slug
            // within the 40-char limit even for maximal-length slugs.
            $new_slug = substr( $slug, 0, 32 ) . '-copy-' . $n;
            $n++;
            if ( $n > 999 ) {
                wp_send_json_error( 'Too many preset copies.' );
            }
        }

        if ( count( $list ) >= 25 ) {
            wp_send_json_error( 'Preset limit reached (25 max).' );
        }

        $copy = [
            'slug'             => $new_slug,
            'name'             => $source['name'],
            'instruction_text' => $source['instruction_text'],
            'enabled'          => true,
        ];
        $list[] = $copy;
        PressHub_AI_Preset_Store::save_author_presets( $user_id, $list );

        $this->record_preset_throttle();
        wp_send_json_success( [ 'slug' => $new_slug, 'preset' => $copy ] );
    }

    /**
     * Whether a slug names an enabled, selectable preset for this author.
     */
    private function preset_slug_is_selectable( int $user_id, string $slug ): bool {
        foreach ( PressHub_AI_Preset_Store::get_author_presets( $user_id ) as $preset ) {
            if ( $preset['slug'] === $slug && $preset['enabled'] ) {
                return true;
            }
        }
        $disabled = PressHub_AI_Preset_Store::get_disabled_defaults( $user_id );
        foreach ( PressHub_AI_Preset_Store::get_plugin_defaults() as $preset ) {
            if ( $preset['slug'] === $slug && $preset['enabled'] && ! in_array( $slug, $disabled, true ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a preset list contains the given slug.
     */
    private function list_has_slug( array $list, string $slug ): bool {
        foreach ( $list as $row ) {
            if ( $row['slug'] === $slug ) {
                return true;
            }
        }
        return false;
    }
}
