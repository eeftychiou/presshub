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
        // Org defaults (2026-08-15 design §9 Q3 / Antigravity A-6).
        add_action( 'wp_ajax_presshub_ai_save_org_default', [ $this, 'save_org_default' ] );
        // Daily News Briefing & AI Podcast AJAX endpoints (Task 6).
        add_action( 'wp_ajax_presshub_ai_briefing_get_status', [ $this, 'briefing_get_status' ] );
        add_action( 'wp_ajax_presshub_ai_briefing_run_harvest', [ $this, 'briefing_run_harvest' ] );
        add_action( 'wp_ajax_presshub_ai_briefing_run_curation', [ $this, 'briefing_run_curation' ] );
        add_action( 'wp_ajax_presshub_ai_briefing_run_script', [ $this, 'briefing_run_script' ] );
        add_action( 'wp_ajax_presshub_ai_briefing_save_script', [ $this, 'briefing_save_script' ] );
        add_action( 'wp_ajax_presshub_ai_briefing_generate_audio', [ $this, 'briefing_generate_audio' ] );
        add_action( 'wp_ajax_presshub_ai_briefing_upload', [ $this, 'briefing_upload' ] );
        // AJAX Settings Save
        add_action( 'wp_ajax_presshub_ai_save_settings', [ $this, 'save_settings' ] );
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
            wp_send_json_error( __( 'Permission denied.', 'presshub-ai-editor' ) );
        }

        // P6: the per-provider Test Connection buttons send the target
        // provider; fall back to the active provider when absent.
        $provider = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : null;

        $api = new PressHub_AI_API_Client();
        $result = $api->test_connection( $provider );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( __( 'API Connection Successful!', 'presshub-ai-editor' ) );
    }

    public function generate_draft() {
        check_ajax_referer( 'presshub_ai_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'presshub-ai-editor' ) );
        }

        if ( isset( $_POST['post_id'] ) ) {
            $post_id = intval( $_POST['post_id'] );
            if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
                wp_send_json_error( __( 'Permission denied.', 'presshub-ai-editor' ) );
            }
        }

        $sources = isset( $_POST['sources'] ) ? sanitize_textarea_field( wp_unslash( $_POST['sources'] ) ) : '';
        $instructions = isset( $_POST['instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['instructions'] ) ) : '';

        // Per-request preset selection (2026-08-15 design §4.2): slug or
        // sentinel picked in the metabox; '' means "use the author's
        // default". Passed through to generate_draft() as the 4th arg.
        $preset_slug = sanitize_text_field( wp_unslash( $_POST['instruction_preset_id'] ?? '' ) );

        // Input length limits: guard the AI endpoints against oversized
        // payloads that would waste tokens / cost money.
        if ( strlen( $sources ) > 20000 ) {
            wp_send_json_error( __( 'Sources exceed the 20,000 character limit.', 'presshub-ai-editor' ) );
        }
        if ( strlen( $instructions ) > 5000 ) {
            wp_send_json_error( __( 'Instructions exceed the 5,000 character limit.', 'presshub-ai-editor' ) );
        }

        // Enforce the rate limit BEFORE touching any file: a blocked
        // request must not leave orphaned uploads behind (Medium-9).
        $this->enforce_rate_limit();

        $uploaded_files = [];
        if ( ! empty( $_FILES['files'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $files = $_FILES['files'];

            // Upload validation (Low-15): whitelist the extensions the AI
            // providers actually accept, and cap each file at 50 MB. The
            // WHOLE batch is validated before ANY file is moved, so a
            // mixed batch never leaves partially-moved files behind.
            $allowed_extensions = [ 'pdf', 'docx', 'mp3', 'mp4', 'wav', 'm4a' ];
            $max_file_size      = 50 * 1024 * 1024; // 50 MB

            $valid_files = [];
            foreach ( $files['name'] as $key => $value ) {
                if ( $files['name'][$key] ) {
                    $file = [
                        'name'     => $files['name'][$key],
                        'type'     => $files['type'][$key],
                        'tmp_name' => $files['tmp_name'][$key],
                        'error'    => $files['error'][$key],
                        'size'     => $files['size'][$key]
                    ];
                    $filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
                    if ( ! in_array( strtolower( (string) ( $filetype['ext'] ?? '' ) ), $allowed_extensions, true ) ) {
                        wp_send_json_error( __( 'Unsupported file type. Allowed: PDF, DOCX, MP3, MP4, WAV, M4A.', 'presshub-ai-editor' ) );
                    }
                    if ( (int) $file['size'] > $max_file_size ) {
                        wp_send_json_error( __( 'File exceeds the 50 MB size limit.', 'presshub-ai-editor' ) );
                    }
                    $valid_files[] = $file;
                }
            }
            foreach ( $valid_files as $file ) {
                $movefile = wp_handle_upload( $file, [ 'test_form' => false ] );
                if ( $movefile && ! isset( $movefile['error'] ) ) {
                    $uploaded_files[] = $movefile['file']; // Absolute path
                }
            }
        }

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
            wp_send_json_error( __( 'Permission denied.', 'presshub-ai-editor' ) );
        }

        $content = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '';
        if ( strlen( $content ) > 100000 ) {
            wp_send_json_error( __( 'Content exceeds the 100,000 character limit.', 'presshub-ai-editor' ) );
        }
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;

        if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( __( 'Permission denied.', 'presshub-ai-editor' ) );
        }

        $this->enforce_rate_limit();
        $api = new PressHub_AI_API_Client();
        $scorecard = $api->generate_scorecard( $content );

        if ( is_wp_error( $scorecard ) ) {
            wp_send_json_error( $scorecard->get_error_message() );
        }

        $status_after = null;
        if ( $post_id ) {
            update_post_meta( $post_id, '_presshub_ai_scorecard', $scorecard );

            $post = get_post( $post_id );
            if ( $post ) {
                $status_after = $post->post_status;

                // High-1 guard: run_review() must never demote an
                // already-published post. Only non-published posts are
                // transitioned to 'pending' for editorial review.
                if ( isset( $scorecard['score'] ) && intval( $scorecard['score'] ) >= 80
                    && 'publish' !== $post->post_status ) {
                    wp_update_post( [ 'ID' => $post_id, 'post_status' => 'pending' ] );
                    $status_after = 'pending';
                }
            }
        }

        $this->record_rate_limit();
        // ME-8 / F-29: report the post status after the review so the UI
        // can tell the journalist what happened ('pending' = moved to
        // editorial review, 'publish' = left alone, null = no post).
        wp_send_json_success( array_merge( $scorecard, [ 'status_after' => $status_after ] ) );
    }

    public function handle_chat_routing() {
        check_ajax_referer( 'presshub_ai_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'presshub-ai-editor' ) );
        }

        $prompt = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
        if ( strlen( $prompt ) > 5000 ) {
            wp_send_json_error( __( 'Prompt exceeds the 5,000 character limit.', 'presshub-ai-editor' ) );
        }
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;

        if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( __( 'Permission denied.', 'presshub-ai-editor' ) );
        }

        // Gate paid media generation (Imagen / Cloud TTS) to admins only.
        // Authors can still use chat + research. This prevents a low-priv
        // user from racking up Google Cloud costs without an admin's
        // explicit configuration of the project + keys.
        $pre_intent = isset( $_POST['intent'] ) ? sanitize_text_field( wp_unslash( $_POST['intent'] ) ) : '';
        if ( in_array( $pre_intent, [ 'image', 'report' ], true ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'presshub-ai-editor' ) );
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
            wp_send_json_error( __( 'Permission denied.', 'presshub-ai-editor' ) );
        }

        if ( 'chat' === $intent ) {
            // Per-author presets apply to chat too (design §3.3). C-3
            // composition order: the filter below runs on the BASE prompt
            // FIRST (so full-replacement hooks no longer drop the preset),
            // the resolved preset is appended AFTER it, and the
            // presshub_ai_composed_chat_system_prompt filter sees the
            // final composed string. Legacy contract for
            // presshub_ai_chat_system_prompt: hooks receive the base
            // prompt; use string concatenation (or the composed filter)
            // to affect the preset-augmented prompt.
            $preset_slug = sanitize_text_field( wp_unslash( $_POST['instruction_preset_id'] ?? '' ) );
            $sys = apply_filters( 'presshub_ai_chat_system_prompt', __( 'You are a helpful AI journalist assistant.', 'presshub-ai-editor' ) );
            $preset = PressHub_AI_Preset_Resolver::resolve_for_user( get_current_user_id(), 'chat', $preset_slug );
            if ( $preset !== null ) {
                $sys .= "\n\n" . $preset;
            }
            $sys = apply_filters( 'presshub_ai_composed_chat_system_prompt', $sys );
            $result = $api->call_provider( $sys, $prompt, false, [] );
            presshub_ai_log_prompts( 'chat', $sys, $prompt, is_wp_error( $result ) ? 'ERROR: ' . $result->get_error_message() : $result, PressHub_AI_API_Client::current_request_meta() );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( $result->get_error_message() );
            }
            $this->record_rate_limit();
            wp_send_json_success( [ 'type' => 'chat', 'content' => $result ] );
        } elseif ( 'research' === $intent ) {
            $research_id = wp_insert_post( [
                'post_type' => 'presshub_research',
                'post_title' => sprintf(
                    /* translators: 1: associated post id, 2: truncated user prompt. */
                    __( 'Research for post #%1$d: %2$s', 'presshub-ai-editor' ),
                    (int) $post_id,
                    wp_html_excerpt( $prompt, 50, '...' )
                ),
                // Low-16: insert as 'pending' directly — no publish-then-
                // revert dance through the editorial workflow guard.
                'post_status' => 'pending'
            ] );
            if ( ! $research_id || is_wp_error( $research_id ) ) {
                $error_msg = is_wp_error( $research_id ) ? $research_id->get_error_message() : __( 'Failed to create research post.', 'presshub-ai-editor' );
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
            wp_send_json_error( __( 'Permission denied.', 'presshub-ai-editor' ) );
        }

        $research_id = isset( $_POST['research_id'] ) ? intval( $_POST['research_id'] ) : 0;
        if ( ! current_user_can( 'edit_post', $research_id ) ) {
            wp_send_json_error( __( 'Permission denied.', 'presshub-ai-editor' ) );
        }

        $post = get_post( $research_id );

        if ( ! $post || 'presshub_research' !== $post->post_type ) {
            wp_send_json_error( __( 'Invalid research post ID.', 'presshub-ai-editor' ) );
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
    // mutation, recorded after it succeeds). The throttle uses a
    // dedicated, FORCE-ENABLED limiter (Medium-5) with its own bucket key
    // so preset CRUD is always throttled at 60/min and never consumes
    // the AI-call rate limit.
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
        $limiter = new PressHub_AI_Rate_Limiter( true );
        $result  = $limiter->check( $this->preset_throttle_key(), 60, 60 );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
    }

    /**
     * Record a successful preset mutation against the throttle bucket.
     * The explicit limit/window args keep record() in sync with the
     * check() above.
     */
    private function record_preset_throttle(): void {
        $limiter = new PressHub_AI_Rate_Limiter( true );
        $limiter->record( $this->preset_throttle_key(), 60, 60 );
    }

    /**
     * AJAX: presshub_ai_list_presets — read-only listing for the metabox
     * dropdown and the profile/admin preset screens.
     *
     * Response: {
     *   own:          all of the author's presets (incl. disabled rows),
     *   defaults:     enabled plugin-default presets minus the slugs the
     *                 author has disabled,
     *   default_slug: the author's default preset slug ('' when unset),
     *   org:          { taxonomy: term slug => preset slug map,
     *                  role: role => preset slug map } — admin-curated
     *                 org defaults (2026-08-15 design §9 Q3), so the UI
     *                 can display the current assignments.
     * }
     */
    public function list_presets() {
        check_ajax_referer( 'presshub_ai_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'presshub-ai-editor' ) );
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
            'org'          => [
                'taxonomy' => PressHub_AI_Preset_Store::get_taxonomy_presets(),
                'role'     => PressHub_AI_Preset_Store::get_role_presets(),
            ],
        ] );
    }

    /**
     * AJAX: presshub_ai_save_preset — create or update one preset.
     *
     * Thin wrapper (architect A-2): auth + sanitize input, then delegate
     * quota/upsert semantics to PressHub_AI_Preset_Store::upsert().
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
                wp_send_json_error( __( 'Permission denied.', 'presshub-ai-editor' ) );
            }
        } elseif ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'presshub-ai-editor' ) );
        }

        $slug            = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
        $name            = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $instruction_text = isset( $_POST['instruction_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['instruction_text'] ) ) : '';

        if ( ! preg_match( PressHub_AI_Preset_Sanitizer::SLUG_REGEX, $slug ) ) {
            wp_send_json_error( __( 'Invalid preset slug. Use 1-40 lowercase letters, numbers, or hyphens.', 'presshub-ai-editor' ) );
        }
        if ( strlen( $name ) > PressHub_AI_Preset_Sanitizer::MAX_NAME_LENGTH ) {
            wp_send_json_error( __( 'Preset name exceeds the 80 character limit.', 'presshub-ai-editor' ) );
        }
        if ( strlen( $instruction_text ) > PressHub_AI_Preset_Sanitizer::MAX_INSTRUCTION_LEN ) {
            wp_send_json_error( __( 'Preset instructions exceed the 4,000 character limit.', 'presshub-ai-editor' ) );
        }

        $this->enforce_preset_throttle();

        $row = [
            'slug'             => $slug,
            'name'             => $name,
            'instruction_text' => $instruction_text,
        ];
        if ( isset( $_POST['enabled'] ) ) {
            $row['enabled'] = (bool) $_POST['enabled'];
        }

        $max = ( 'plugin' === $scope )
            ? PressHub_AI_Preset_Store::PLUGIN_MAX_PRESETS
            : PressHub_AI_Preset_Store::AUTHOR_MAX_PRESETS;

        $result = PressHub_AI_Preset_Store::upsert( $scope, (int) get_current_user_id(), $row, $max );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $this->record_preset_throttle();
        wp_send_json_success( $result );
    }

    /**
     * AJAX: presshub_ai_delete_preset.
     *
     * Thin wrapper (architect A-2): auth + sanitize input, then delegate
     * to PressHub_AI_Preset_Store::remove().
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
                wp_send_json_error( __( 'Permission denied.', 'presshub-ai-editor' ) );
            }
        } elseif ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'presshub-ai-editor' ) );
        }

        $slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
        if ( ! preg_match( PressHub_AI_Preset_Sanitizer::SLUG_REGEX, $slug ) ) {
            wp_send_json_error( __( 'Invalid preset slug.', 'presshub-ai-editor' ) );
        }

        $this->enforce_preset_throttle();

        $removed = PressHub_AI_Preset_Store::remove(
            $scope,
            (int) get_current_user_id(),
            $slug,
            'plugin' === $scope
        );

        if ( ! $removed ) {
            wp_send_json_error( __( 'Preset not found.', 'presshub-ai-editor' ) );
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
            wp_send_json_error( __( 'Permission denied.', 'presshub-ai-editor' ) );
        }

        $slug    = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
        $user_id = (int) get_current_user_id();

        if ( $slug !== '' ) {
            if ( ! preg_match( PressHub_AI_Preset_Sanitizer::SLUG_REGEX, $slug ) ) {
                wp_send_json_error( __( 'Invalid preset slug.', 'presshub-ai-editor' ) );
            }
            if ( ! $this->preset_slug_is_selectable( $user_id, $slug ) ) {
                wp_send_json_error( __( 'Preset not found or not enabled.', 'presshub-ai-editor' ) );
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
     * Thin wrapper (architect A-2): auth + sanitize input, then delegate
     * collision-suffix + quota semantics to
     * PressHub_AI_Preset_Store::copy_default_to_author().
     *
     * POST param: slug (must name an enabled plugin-default preset). On
     * slug collision in the author's library the copy is stored as
     * '<slug>-copy-<n>'. Enforces the 25-preset author quota.
     */
    public function copy_default_preset() {
        check_ajax_referer( 'presshub_ai_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'presshub-ai-editor' ) );
        }

        $slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
        if ( ! preg_match( PressHub_AI_Preset_Sanitizer::SLUG_REGEX, $slug ) ) {
            wp_send_json_error( __( 'Invalid preset slug.', 'presshub-ai-editor' ) );
        }

        $this->enforce_preset_throttle();

        $result = PressHub_AI_Preset_Store::copy_default_to_author( (int) get_current_user_id(), $slug );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $this->record_preset_throttle();
        wp_send_json_success( [ 'slug' => $result['slug'], 'preset' => $result ] );
    }

    /**
     * AJAX: presshub_ai_save_org_default — set or clear one admin-curated
     * org default (2026-08-15 design §9 Q3 / Antigravity A-6).
     *
     * Admin-only (manage_options). POST params:
     *   scope: 'taxonomy' | 'role',
     *   key:   term slug (taxonomy scope) or role name (role scope),
     *   value: preset slug | '__none__' (disable presets at this layer)
     *          | '' (clear the assignment).
     *
     * Non-empty values must name an ENABLED plugin-default preset — the
     * same selectability contract as set_default_preset, so an org
     * default can never silently resolve to nothing. Shares the coarse
     * preset throttle (60 mutations/min/user).
     */
    public function save_org_default() {
        check_ajax_referer( 'presshub_ai_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'presshub-ai-editor' ) );
        }

        $scope = isset( $_POST['scope'] ) ? sanitize_text_field( wp_unslash( $_POST['scope'] ) ) : '';
        $key   = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
        $value = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';

        if ( 'taxonomy' !== $scope && 'role' !== $scope ) {
            wp_send_json_error( __( 'Invalid scope.', 'presshub-ai-editor' ) );
        }
        if ( ! preg_match( PressHub_AI_Preset_Sanitizer::SLUG_REGEX, $key ) ) {
            wp_send_json_error( __( 'Invalid key.', 'presshub-ai-editor' ) );
        }
        if ( $value !== '' && $value !== PressHub_AI_Preset_Store::ORG_NONE ) {
            if ( ! preg_match( PressHub_AI_Preset_Sanitizer::SLUG_REGEX, $value ) ) {
                wp_send_json_error( __( 'Invalid preset slug.', 'presshub-ai-editor' ) );
            }
            if ( ! $this->plugin_default_is_enabled( $value ) ) {
                wp_send_json_error( __( 'Preset not found or not enabled.', 'presshub-ai-editor' ) );
            }
        }

        $this->enforce_preset_throttle();

        if ( 'taxonomy' === $scope ) {
            $map = PressHub_AI_Preset_Store::get_taxonomy_presets();
            if ( $value === '' ) {
                unset( $map[ $key ] );
            } else {
                $map[ $key ] = $value;
            }
            $saved = PressHub_AI_Preset_Store::save_taxonomy_presets( $map );
        } else {
            $map = PressHub_AI_Preset_Store::get_role_presets();
            if ( $value === '' ) {
                unset( $map[ $key ] );
            } else {
                $map[ $key ] = $value;
            }
            $saved = PressHub_AI_Preset_Store::save_role_presets( $map );
        }

        $this->record_preset_throttle();
        wp_send_json_success( [
            'scope' => $scope,
            'key'   => $key,
            'value' => $saved[ $key ] ?? '',
        ] );
    }

    /**
     * Whether a slug names an enabled plugin-default preset.
     */
    private function plugin_default_is_enabled( string $slug ): bool {
        foreach ( PressHub_AI_Preset_Store::get_plugin_defaults() as $preset ) {
            if ( $preset['slug'] === $slug ) {
                return (bool) $preset['enabled'];
            }
        }
        return false;
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

    // ------------------------------------------------------------------
    // Daily News Briefing & AI Podcast — AJAX Handlers (Task 6)
    // ------------------------------------------------------------------

    /**
     * Capability required for Daily Briefing Hub actions.
     */
    private function briefing_capability(): string {
        return (string) apply_filters( 'presshub_ai_briefing_cap', 'edit_posts' );
    }

    /**
     * AJAX: presshub_ai_briefing_get_status — returns status for all 4 pipeline stages.
     */
    public function briefing_get_status() {
        check_ajax_referer( 'presshub_ai_nonce', 'nonce' );

        if ( ! current_user_can( $this->briefing_capability() ) ) {
            wp_send_json_error( __( 'Permission denied.', 'presshub-ai-editor' ) );
        }

        $date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : gmdate( 'Y-m-d' );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            $date = gmdate( 'Y-m-d' );
        }

        require_once __DIR__ . '/class-briefing-admin.php';
        $admin  = new PressHub_AI_Briefing_Admin();
        $status = $admin->get_briefing_status( $date );

        wp_send_json_success( $status );
    }

    /**
     * AJAX: presshub_ai_briefing_run_harvest — triggers morning Greek news scraping.
     */
    public function briefing_run_harvest() {
        check_ajax_referer( 'presshub_ai_nonce', 'nonce' );

        if ( ! current_user_can( $this->briefing_capability() ) ) {
            wp_send_json_error( __( 'Permission denied.', 'presshub-ai-editor' ) );
        }

        $date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : gmdate( 'Y-m-d' );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            $date = gmdate( 'Y-m-d' );
        }

        require_once __DIR__ . '/class-news-harvester.php';
        require_once __DIR__ . '/class-settings.php';

        $raw_sources = get_option( 'presshub_ai_briefing_sources', '' );
        if ( is_array( $raw_sources ) ) {
            $sources = $raw_sources;
        } else {
            $sources = preg_split( '/[\r\n,]+/', (string) $raw_sources );
        }
        $sources = array_values( array_filter( array_map( 'trim', (array) $sources ) ) );

        if ( empty( $sources ) && class_exists( 'PressHub_AI_Settings' ) ) {
            $sources = PressHub_AI_Settings::default_briefing_sources();
        }

        $harvester = new PressHub_AI_News_Harvester();
        $result    = $harvester->harvest_all( $sources, $date );

        wp_send_json_success( $result );
    }

    /**
     * AJAX: presshub_ai_briefing_run_curation — triggers text story curation agent.
     */
    public function briefing_run_curation() {
        check_ajax_referer( 'presshub_ai_nonce', 'nonce' );

        if ( ! current_user_can( $this->briefing_capability() ) ) {
            wp_send_json_error( __( 'Permission denied.', 'presshub-ai-editor' ) );
        }

        $date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : gmdate( 'Y-m-d' );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            $date = gmdate( 'Y-m-d' );
        }

        $preset_id = isset( $_POST['preset_id'] ) ? sanitize_text_field( wp_unslash( $_POST['preset_id'] ) ) : (string) get_option( 'presshub_ai_briefing_text_preset', '' );

        $this->enforce_rate_limit();

        require_once __DIR__ . '/class-news-curator.php';
        $curator = new PressHub_AI_News_Curator();
        $result  = $curator->generate_story( $date, null, $preset_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $this->record_rate_limit();
        wp_send_json_success( $result );
    }

    /**
     * AJAX: presshub_ai_briefing_run_script — triggers podcast dialogue script generation.
     */
    public function briefing_run_script() {
        check_ajax_referer( 'presshub_ai_nonce', 'nonce' );

        if ( ! current_user_can( $this->briefing_capability() ) ) {
            wp_send_json_error( __( 'Permission denied.', 'presshub-ai-editor' ) );
        }

        $date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : gmdate( 'Y-m-d' );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            $date = gmdate( 'Y-m-d' );
        }

        $preset_id = isset( $_POST['preset_id'] ) ? sanitize_text_field( wp_unslash( $_POST['preset_id'] ) ) : (string) get_option( 'presshub_ai_briefing_podcast_preset', '' );
        $duration  = isset( $_POST['duration'] ) ? sanitize_text_field( wp_unslash( $_POST['duration'] ) ) : (string) get_option( 'presshub_ai_briefing_target_duration', '5_min' );

        $this->enforce_rate_limit();

        require_once __DIR__ . '/class-podcast-producer.php';
        $producer = new PressHub_AI_Podcast_Producer();
        $result   = $producer->generate_dialogue_script( $date, null, $preset_id, $duration );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $this->record_rate_limit();
        wp_send_json_success( $result );
    }

    /**
     * AJAX: presshub_ai_briefing_save_script — saves edited podcast script text.
     */
    public function briefing_save_script() {
        check_ajax_referer( 'presshub_ai_nonce', 'nonce' );

        if ( ! current_user_can( $this->briefing_capability() ) ) {
            wp_send_json_error( __( 'Permission denied.', 'presshub-ai-editor' ) );
        }

        $date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : gmdate( 'Y-m-d' );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            $date = gmdate( 'Y-m-d' );
        }

        $script = isset( $_POST['script'] ) ? sanitize_textarea_field( wp_unslash( $_POST['script'] ) ) : '';
        if ( empty( trim( $script ) ) ) {
            wp_send_json_error( __( 'Script cannot be empty.', 'presshub-ai-editor' ) );
        }

        require_once __DIR__ . '/class-podcast-producer.php';
        $producer = new PressHub_AI_Podcast_Producer();
        $saved    = $producer->save_script( $date, $script );

        if ( ! $saved ) {
            wp_send_json_error( __( 'Failed to save podcast script to disk.', 'presshub-ai-editor' ) );
        }

        $turns = $producer->parse_script_turns( $script );

        wp_send_json_success( [
            'saved'       => true,
            'date'        => $date,
            'turns_count' => count( $turns ),
        ] );
    }

    /**
     * AJAX: presshub_ai_briefing_generate_audio — synthesizes podcast audio using Google Cloud TTS.
     */
    public function briefing_generate_audio() {
        check_ajax_referer( 'presshub_ai_nonce', 'nonce' );

        if ( ! current_user_can( $this->briefing_capability() ) ) {
            wp_send_json_error( __( 'Permission denied.', 'presshub-ai-editor' ) );
        }

        $date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : gmdate( 'Y-m-d' );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            $date = gmdate( 'Y-m-d' );
        }

        $script = isset( $_POST['script'] ) ? sanitize_textarea_field( wp_unslash( $_POST['script'] ) ) : '';

        require_once __DIR__ . '/class-audio-synthesizer.php';
        $synthesizer = new PressHub_AI_Audio_Synthesizer();
        $result      = $synthesizer->synthesize_podcast( $date, $script );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: presshub_ai_briefing_upload — merges uploaded documents or pasted notes into daily pool.
     */
    public function briefing_upload() {
        check_ajax_referer( 'presshub_ai_nonce', 'nonce' );

        if ( ! current_user_can( $this->briefing_capability() ) ) {
            wp_send_json_error( __( 'Permission denied.', 'presshub-ai-editor' ) );
        }

        $date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : gmdate( 'Y-m-d' );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            $date = gmdate( 'Y-m-d' );
        }

        $source  = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : __( 'Manual Upload', 'presshub-ai-editor' );
        $title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $content = isset( $_POST['content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['content'] ) ) : '';
        $url     = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

        $articles_to_merge = [];

        // 1. Text notes if present
        if ( ! empty( trim( $content ) ) || ! empty( trim( $title ) ) ) {
            $articles_to_merge[] = [
                'title'   => ! empty( trim( $title ) ) ? $title : __( 'Χειροκίνητη Σημείωση', 'presshub-ai-editor' ),
                'content' => $content,
                'source'  => ! empty( trim( $source ) ) ? $source : __( 'Manual Upload', 'presshub-ai-editor' ),
                'url'     => $url,
            ];
        }

        // 2. Uploaded files (PDF, DOCX, TXT)
        if ( ! empty( $_FILES['files'] ) && is_array( $_FILES['files']['name'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $files = $_FILES['files'];
            $allowed_extensions = [ 'pdf', 'docx', 'txt' ];
            $max_file_size      = 25 * 1024 * 1024; // 25 MB

            foreach ( $files['name'] as $key => $filename ) {
                if ( empty( $filename ) ) {
                    continue;
                }
                $file = [
                    'name'     => $files['name'][ $key ],
                    'type'     => $files['type'][ $key ],
                    'tmp_name' => $files['tmp_name'][ $key ],
                    'error'    => $files['error'][ $key ],
                    'size'     => $files['size'][ $key ],
                ];

                $filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
                $ext      = strtolower( (string) ( $filetype['ext'] ?? pathinfo( $filename, PATHINFO_EXTENSION ) ) );
                if ( ! in_array( $ext, $allowed_extensions, true ) ) {
                    wp_send_json_error( sprintf( __( 'Unsupported file type "%s". Allowed: PDF, DOCX, TXT.', 'presshub-ai-editor' ), $ext ) );
                }
                if ( (int) $file['size'] > $max_file_size ) {
                    wp_send_json_error( __( 'File exceeds the 25 MB limit.', 'presshub-ai-editor' ) );
                }

                $file_title = pathinfo( $filename, PATHINFO_FILENAME );
                $extracted_text = '';
                if ( 'txt' === $ext && file_exists( $file['tmp_name'] ) ) {
                    $extracted_text = (string) @file_get_contents( $file['tmp_name'] );
                }

                $articles_to_merge[] = [
                    'title'   => $file_title,
                    'content' => $extracted_text ?: sprintf( __( 'Attached file: %s', 'presshub-ai-editor' ), $filename ),
                    'source'  => ! empty( trim( $source ) ) ? $source : __( 'Manual Upload', 'presshub-ai-editor' ),
                    'url'     => '',
                ];
            }
        }

        if ( empty( $articles_to_merge ) ) {
            wp_send_json_error( __( 'No article content or files provided.', 'presshub-ai-editor' ) );
        }

        require_once __DIR__ . '/class-news-harvester.php';
        $harvester = new PressHub_AI_News_Harvester();
        $result    = $harvester->handle_manual_upload( $articles_to_merge, $date );

        wp_send_json_success( $result );
    }

    /**
     * AJAX handler: save all PressHub AI settings cleanly and securely.
     */
    public function save_settings(): void {
        check_ajax_referer( 'presshub_ai_nonce', 'nonce' );

        $cap = (string) apply_filters( 'presshub_ai_settings_cap', 'manage_options' );
        if ( ! current_user_can( $cap ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions to manage PressHub AI settings.', 'presshub-ai-editor' ) ], 403 );
        }

        $post_data = $_POST;
        if ( isset( $_POST['settings'] ) && is_string( $_POST['settings'] ) ) {
            parse_str( $_POST['settings'], $parsed );
            if ( is_array( $parsed ) ) {
                $post_data = array_merge( $post_data, $parsed );
            }
        }

        require_once __DIR__ . '/class-settings.php';

        $options_map = [
            'presshub_ai_provider'                    => [ 'PressHub_AI_Settings', 'sanitize_provider' ],
            'presshub_ai_fetch_urls'                  => [ 'PressHub_AI_Settings', 'sanitize_fetch_urls' ],
            'presshub_ai_debug_prompts'               => [ 'PressHub_AI_Settings', 'sanitize_fetch_urls' ],
            'presshub_ai_api_key'                     => [ 'PressHub_AI_Settings', 'sanitize_api_key' ],
            'presshub_ai_remove_api_key'              => [ 'PressHub_AI_Settings', 'sanitize_remove_api_key' ],
            'presshub_ai_remove_google_cloud_api_key' => [ 'PressHub_AI_Settings', 'sanitize_remove_google_cloud_api_key' ],
            'presshub_ai_remove_github_token'         => [ 'PressHub_AI_Settings', 'sanitize_remove_github_token' ],
            'presshub_ai_model_openai'                => function( $v ) { return PressHub_AI_Settings::sanitize_model( $v, 'openai' ); },
            'presshub_ai_temperature_openai'          => [ 'PressHub_AI_Settings', 'sanitize_temperature' ],
            'presshub_ai_max_tokens_openai'           => [ 'PressHub_AI_Settings', 'sanitize_max_tokens' ],
            'presshub_ai_timeout_openai'              => [ 'PressHub_AI_Settings', 'sanitize_timeout' ],
            'presshub_ai_model_anthropic'             => function( $v ) { return PressHub_AI_Settings::sanitize_model( $v, 'anthropic' ); },
            'presshub_ai_temperature_anthropic'       => [ 'PressHub_AI_Settings', 'sanitize_temperature' ],
            'presshub_ai_max_tokens_anthropic'        => [ 'PressHub_AI_Settings', 'sanitize_max_tokens' ],
            'presshub_ai_timeout_anthropic'           => [ 'PressHub_AI_Settings', 'sanitize_timeout' ],
            'presshub_ai_model_gemini'                => function( $v ) { return PressHub_AI_Settings::sanitize_model( $v, 'gemini' ); },
            'presshub_ai_temperature_gemini'          => [ 'PressHub_AI_Settings', 'sanitize_temperature' ],
            'presshub_ai_max_tokens_gemini'           => [ 'PressHub_AI_Settings', 'sanitize_max_tokens' ],
            'presshub_ai_timeout_gemini'              => [ 'PressHub_AI_Settings', 'sanitize_timeout' ],
            'presshub_ai_openai_org'                  => [ 'PressHub_AI_Settings', 'sanitize_openai_org' ],
            'presshub_ai_anthropic_version'           => [ 'PressHub_AI_Settings', 'sanitize_anthropic_version' ],
            'presshub_ai_github_token'                => [ 'PressHub_AI_Settings', 'sanitize_github_token' ],
            'presshub_ai_google_cloud_api_key'        => [ 'PressHub_AI_Settings', 'sanitize_google_cloud_api_key' ],
            'presshub_ai_gcloud_project_id'           => [ 'PressHub_AI_Settings', 'sanitize_gcloud_project_id' ],
            'presshub_ai_imagen_region'               => [ 'PressHub_AI_Settings', 'sanitize_imagen_region' ],
            'presshub_ai_rate_limit_enabled'          => [ 'PressHub_AI_Settings', 'sanitize_boolean' ],
            'presshub_ai_rate_limit_per_hour'         => [ 'PressHub_AI_Settings', 'sanitize_rate_limit_per_hour' ],
            'presshub_ai_rate_limit_window_seconds'   => [ 'PressHub_AI_Settings', 'sanitize_rate_limit_window_seconds' ],
            'presshub_ai_research_retention_days'     => [ 'PressHub_AI_Settings', 'sanitize_research_retention_days' ],
            'presshub_ai_briefing_sources'            => [ 'PressHub_AI_Settings', 'sanitize_briefing_sources' ],
            'presshub_ai_briefing_harvest_time'       => [ 'PressHub_AI_Settings', 'sanitize_harvest_time' ],
            'presshub_ai_briefing_generation_time'    => [ 'PressHub_AI_Settings', 'sanitize_generation_time' ],
            'presshub_ai_briefing_text_preset'        => [ 'PressHub_AI_Settings', 'sanitize_preset_slug' ],
            'presshub_ai_briefing_podcast_preset'     => [ 'PressHub_AI_Settings', 'sanitize_preset_slug' ],
            'presshub_ai_briefing_target_duration'    => [ 'PressHub_AI_Settings', 'sanitize_briefing_duration' ],
            'presshub_ai_briefing_host_female'        => [ 'PressHub_AI_Settings', 'sanitize_briefing_host_female' ],
            'presshub_ai_briefing_host_male'          => [ 'PressHub_AI_Settings', 'sanitize_briefing_host_male' ],
            'presshub_ai_briefing_voice_female'       => [ 'PressHub_AI_Settings', 'sanitize_voice_female' ],
            'presshub_ai_briefing_voice_male'         => [ 'PressHub_AI_Settings', 'sanitize_voice_male' ],
            'presshub_ai_briefing_voice_speed'        => [ 'PressHub_AI_Settings', 'sanitize_voice_speed' ],
            'presshub_ai_briefing_voice_pitch'        => [ 'PressHub_AI_Settings', 'sanitize_voice_pitch' ],
            'presshub_ai_briefing_text_category'      => [ 'PressHub_AI_Settings', 'sanitize_category_id' ],
            'presshub_ai_briefing_podcast_category'   => [ 'PressHub_AI_Settings', 'sanitize_category_id' ],
            'presshub_ai_briefing_text_status'        => [ 'PressHub_AI_Settings', 'sanitize_briefing_status' ],
            'presshub_ai_briefing_podcast_status'     => [ 'PressHub_AI_Settings', 'sanitize_briefing_status' ],
            'presshub_ai_briefing_text_prompt'        => [ 'PressHub_AI_Settings', 'sanitize_briefing_prompt' ],
            'presshub_ai_briefing_podcast_prompt'     => [ 'PressHub_AI_Settings', 'sanitize_briefing_prompt' ],
        ];

        foreach ( $options_map as $option => $sanitizer ) {
            if ( isset( $post_data[ $option ] ) ) {
                $clean = call_user_func( $sanitizer, $post_data[ $option ] );
                update_option( $option, $clean );
            } elseif ( in_array( $option, [ 'presshub_ai_fetch_urls', 'presshub_ai_debug_prompts', 'presshub_ai_rate_limit_enabled' ], true ) ) {
                update_option( $option, 0 );
            }
        }

        $saved_key    = (string) get_option( 'presshub_ai_api_key', '' );
        $saved_gcloud = (string) get_option( 'presshub_ai_google_cloud_api_key', '' );
        $saved_github = (string) get_option( 'presshub_ai_github_token', '' );

        wp_send_json_success( [
            'message' => __( 'Settings saved successfully.', 'presshub-ai-editor' ),
            'masks'   => [
                'api_key'          => PressHub_AI_Settings::mask_key( $saved_key ),
                'google_cloud_key' => PressHub_AI_Settings::mask_key( $saved_gcloud ),
                'github_token'     => PressHub_AI_Settings::mask_key( $saved_github ),
            ],
        ] );
    }
}
