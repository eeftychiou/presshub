<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-rate-limiter.php';

class PressHub_AI_Ajax_Handlers {
    public function __construct() {
        add_action( 'wp_ajax_presshub_ai_generate_draft', [ $this, 'generate_draft' ] );
        add_action( 'wp_ajax_presshub_ai_run_review', [ $this, 'run_review' ] );
        add_action( 'wp_ajax_presshub_ai_test_api', [ $this, 'test_api_connection' ] );
        add_action( 'wp_ajax_presshub_ai_chat', [ $this, 'handle_chat_routing' ] );
        add_action( 'wp_ajax_presshub_ai_check_research', [ $this, 'check_research_status' ] );
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
        $draft = $api->generate_draft( $sources, $instructions, $uploaded_files );

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
            $result = $api->call_provider( 'You are a helpful AI journalist assistant.', $prompt, false, [] );
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

            wp_schedule_single_event( time(), 'presshub_ai_do_research', [ $research_id ] );

            $this->record_rate_limit();
            wp_send_json_success( [ 'type' => 'research', 'status' => 'pending', 'research_id' => $research_id ] );
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
}
