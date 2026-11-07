<?php
/**
 * Log folder exposure check (issue #138).
 *
 * Verifies whether the plugin's log folder can be opened from the web by
 * writing a canary file with random content, requesting its public URL and
 * deleting the canary again. The result is cached for 12 hours.
 *
 * Zero-Silent-Fallback: the panel never reports "protected" unless a real
 * HTTP 401/403/404 came back. Every other outcome is "unverified".
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PressHub_AI_Log_Exposure_Check {

    const TRANSIENT = 'presshub_ai_log_exposure_status';
    const AJAX_ACTION = 'presshub_ai_recheck_log_exposure';

    /**
     * Register the AJAX handler and the admin notice for a misconfigured
     * PRESSHUB_AI_LOG_DIR.
     */
    public static function register(): void {
        add_action( 'wp_ajax_' . self::AJAX_ACTION, [ self::class, 'ajax_recheck' ] );
        add_action( 'admin_notices', [ self::class, 'maybe_render_notice' ] );
    }

    /**
     * Run (or return the cached) exposure check.
     *
     * @return array{state:string,checked_at:string,detail:string}
     */
    public static function get_status( bool $force = false ): array {
        if ( ! $force ) {
            $cached = get_transient( self::TRANSIENT );
            if ( is_array( $cached ) && isset( $cached['state'] ) ) {
                return $cached;
            }
        }

        $result = self::run_check();
        $status = [
            'state'      => $result[0],
            'checked_at' => current_time( 'mysql' ),
            'detail'     => $result[1],
        ];
        set_transient( self::TRANSIENT, $status, 12 * 3600 );

        if ( class_exists( 'PressHub_AI_Logger' ) ) {
            $context = [ 'state' => $status['state'], 'detail' => $status['detail'] ];
            if ( 'exposed' === $status['state'] ) {
                PressHub_AI_Logger::warning( 'Log folder is publicly accessible over HTTP', $context );
            } else {
                PressHub_AI_Logger::info( 'Log folder exposure check finished', $context );
            }
        }
        return $status;
    }

    /**
     * @return array{0:string,1:string} [ state, detail ]
     */
    private static function run_check(): array {
        if ( ! class_exists( 'PressHub_AI_Log_Paths' ) ) {
            return [ 'unverified', 'Log path helper is not available.' ];
        }

        $dir = PressHub_AI_Log_Paths::get_dir();
        if ( is_wp_error( $dir ) ) {
            return [ 'unverified', 'The log folder is not usable. Check the PRESSHUB_AI_LOG_DIR constant in wp-config.php: ' . $dir->get_error_message() ];
        }

        if ( ! PressHub_AI_Log_Paths::is_web_accessible_dir( $dir ) ) {
            return [ 'not_applicable', 'The log folder is outside the web root.' ];
        }

        $random = bin2hex( random_bytes( 16 ) );
        $canary = rtrim( $dir, '/\\' ) . '/exposure-check-' . bin2hex( random_bytes( 6 ) ) . '.txt';
        $url    = PressHub_AI_Log_Paths::get_url_for( $canary );

        if ( '' === $url ) {
            return [ 'unverified', 'No public URL could be derived for the log folder.' ];
        }

        try {
            if ( false === @file_put_contents( $canary, 'presshub-canary-' . $random ) ) {
                return [ 'unverified', 'Could not write the canary file into the log folder.' ];
            }

            $response = wp_remote_get( $url, [
                'timeout'     => 10,
                'sslverify'   => false,
                'redirection' => 0,
            ] );

            if ( is_wp_error( $response ) ) {
                return [ 'unverified', 'The loopback request failed: ' . $response->get_error_message() ];
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            $body = (string) wp_remote_retrieve_body( $response );

            if ( false !== strpos( $body, $random ) ) {
                return [ 'exposed', 'The canary file was downloaded over HTTP (status ' . $code . ').' ];
            }
            if ( in_array( $code, [ 401, 403, 404 ], true ) ) {
                return [ 'protected', 'The canary file request was refused with HTTP ' . $code . '.' ];
            }
            return [ 'unverified', 'Unexpected HTTP status ' . $code . ' for the canary file.' ];
        } finally {
            if ( file_exists( $canary ) ) {
                @unlink( $canary );
            }
        }
    }

    /**
     * Echo the status box. Everything dynamic is escaped.
     *
     * @param bool $include_script Include the inline re-check script (omitted for AJAX refreshes).
     */
    public static function render_panel( bool $include_script = true ): void {
        $status = self::get_status();
        $state  = $status['state'];
        $detail = (string) $status['detail'];

        $classes = [
            'exposed'        => 'notice-error',
            'protected'      => 'notice-success',
            'unverified'     => 'notice-warning',
            'not_applicable' => 'notice-info',
        ];
        $class = $classes[ $state ] ?? 'notice-warning';

        echo '<div id="presshub-log-exposure" class="presshub-log-exposure notice inline ' . esc_attr( $class ) . '" style="padding:10px 12px;margin:15px 0;">';
        echo '<p><strong>' . esc_html__( 'Log folder exposure check', 'presshub-ai-editor' ) . '</strong></p>';

        if ( 'exposed' === $state ) {
            echo '<p><strong>' . esc_html__( 'Your log files can be downloaded by anyone on the internet.', 'presshub-ai-editor' ) . '</strong> ';
            echo esc_html__( 'They contain prompts, drafts and generated content. Block web access to the folder now.', 'presshub-ai-editor' ) . '</p>';
            echo '<p>' . esc_html__( 'Nginx ignores .htaccess files. Add a rule like this to your server block and reload nginx:', 'presshub-ai-editor' ) . '</p>';
            echo '<pre style="background:#f6f7f7;padding:8px;overflow:auto;">' . esc_html( 'location ~* /wp-content/uploads/presshub-ai/ { deny all; }' ) . '</pre>';
            echo '<p>' . esc_html__( 'Alternatively, define PRESSHUB_AI_LOG_DIR in wp-config.php to store logs in a folder outside the web root.', 'presshub-ai-editor' ) . '</p>';
        } elseif ( 'protected' === $state ) {
            echo '<p>' . esc_html__( 'Log files are not reachable from the web.', 'presshub-ai-editor' ) . '</p>';
        } elseif ( 'not_applicable' === $state ) {
            echo '<p>' . esc_html__( 'Logs are stored outside the web root.', 'presshub-ai-editor' ) . '</p>';
        } else {
            echo '<p>' . esc_html__( 'Could not verify whether the log folder is publicly accessible.', 'presshub-ai-editor' ) . '</p>';
        }

        if ( '' !== $detail ) {
            echo '<p class="description">' . esc_html( $detail ) . '</p>';
        }
        if ( ! empty( $status['checked_at'] ) ) {
            echo '<p class="description">' . esc_html( sprintf( 'Last checked: %s', $status['checked_at'] ) ) . '</p>';
        }

        echo '<p><button type="button" class="button" id="presshub-log-exposure-recheck"'
            . ' data-ajax-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '"'
            . ' data-action="' . esc_attr( self::AJAX_ACTION ) . '"'
            . ' data-nonce="' . esc_attr( wp_create_nonce( 'presshub_ai_nonce' ) ) . '">'
            . esc_html__( 'Re-check now', 'presshub-ai-editor' ) . '</button></p>';
        echo '</div>';

        if ( $include_script ) {
            echo self::inline_script(); // phpcs:ignore WordPress.Security.EscapeOutput -- static string.
        }
    }

    private static function inline_script(): string {
        return <<<'HTML'
<script>
(function () {
    if (window.presshubLogExposureBound) { return; }
    window.presshubLogExposureBound = true;
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('#presshub-log-exposure-recheck') : null;
        if (!btn) { return; }
        e.preventDefault();
        btn.disabled = true;
        var body = new URLSearchParams();
        body.append('action', btn.getAttribute('data-action'));
        body.append('nonce', btn.getAttribute('data-nonce'));
        fetch(btn.getAttribute('data-ajax-url'), { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                var box = document.getElementById('presshub-log-exposure');
                if (json && json.success && json.data && json.data.html && box) {
                    box.outerHTML = json.data.html;
                } else {
                    btn.disabled = false;
                }
            })
            .catch(function () { btn.disabled = false; });
    });
})();
</script>
HTML;
    }

    /**
     * AJAX: force a fresh check and return the refreshed panel HTML.
     */
    public static function ajax_recheck(): void {
        check_ajax_referer( 'presshub_ai_nonce', 'nonce' );
        if ( ! current_user_can( apply_filters( 'presshub_ai_settings_cap', 'manage_options' ) ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
            return;
        }

        $status = self::get_status( true );
        ob_start();
        self::render_panel( false );
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html, 'state' => $status['state'] ] );
    }

    /**
     * admin_notices callback: PressHub screens only, only when
     * PRESSHUB_AI_LOG_DIR is set but unusable.
     */
    public static function maybe_render_notice(): void {
        if ( ! defined( 'PRESSHUB_AI_LOG_DIR' ) || ! class_exists( 'PressHub_AI_Log_Paths' ) ) {
            return;
        }
        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || false === strpos( (string) $screen->id, 'presshub' ) ) {
            return;
        }
        $dir = PressHub_AI_Log_Paths::get_dir();
        if ( ! is_wp_error( $dir ) ) {
            return;
        }
        echo '<div class="notice notice-error"><p>'
            . esc_html( sprintf(
                'PressHub AI: the PRESSHUB_AI_LOG_DIR constant points to "%s", which is missing or not writable. File logging is disabled until this is fixed.',
                (string) PRESSHUB_AI_LOG_DIR
            ) )
            . '</p></div>';
    }
}
