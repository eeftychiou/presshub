<?php
/**
 * TDD tests for the editorial scorecard render guards (Batch B, Low-18):
 *
 *   - the scorecard box only renders when the meta is an array with a
 *     numeric 'score' key;
 *   - 'feedback' is only echoed when it is a string;
 *   - legacy/malformed meta (string, missing score, non-numeric score,
 *     non-string feedback) renders nothing instead of PHP 8 warnings.
 *
 * Also smoke-tests enqueue_assets() on the post edit screens.
 */

// Extra stubs not provided by the shared harness.
if ( ! function_exists( 'wp_enqueue_script' ) ) {
    function wp_enqueue_script( $handle, $src = '', $deps = [], $ver = false, $in_footer = false ) {
        $GLOBALS['ENQUEUED_SCRIPTS'][ $handle ] = [ 'src' => $src, 'deps' => $deps, 'ver' => $ver, 'in_footer' => $in_footer ];
    }
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
    function wp_enqueue_style( $handle, $src = '', $deps = [], $ver = false, $media = 'all' ) {
        $GLOBALS['ENQUEUED_STYLES'][ $handle ] = [ 'src' => $src, 'deps' => $deps, 'ver' => $ver ];
    }
}
if ( ! function_exists( 'wp_localize_script' ) ) {
    function wp_localize_script( $handle, $object_name, $data ) {
        $GLOBALS['LOCALIZED_SCRIPTS'][ $handle ] = [ 'object_name' => $object_name, 'data' => $data ];
    }
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
    function wp_create_nonce( $action = -1 ) {
        return 'test-nonce';
    }
}
if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $text ) {
        return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
    }
}
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) {
        return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
    }
}
if ( ! function_exists( 'esc_textarea' ) ) {
    function esc_textarea( $text ) {
        return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
    }
}

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
defined( 'PRESSHUB_AI_VERSION' ) || define( 'PRESSHUB_AI_VERSION', '1.1.0' );
defined( 'PRESSHUB_AI_URL' ) || define( 'PRESSHUB_AI_URL', 'http://example.test/wp-content/plugins/presshub-ai-editor/' );
require_once __DIR__ . '/../includes/class-preset-sanitizer.php';
require_once __DIR__ . '/../includes/class-preset-store.php';
require_once __DIR__ . '/../includes/class-metaboxes.php';

class MetaboxesRenderTest
{
    public static function run(): void {
        $failures = [];

        // --- Case 1: valid scorecard renders score + feedback ---
        self::reset();
        $GLOBALS['POST_META_STORE'][42]['_presshub_ai_scorecard'] = [ 'score' => 85, 'feedback' => 'Solid draft. Expand the lede.' ];
        $html = self::render( 42 );
        if ( false === strpos( $html, 'Score: 85/100' ) || false === strpos( $html, 'Solid draft. Expand the lede.' ) ) {
            $failures[] = 'A valid scorecard should render score + feedback; got: ' . $html;
        }

        // --- Case 2: legacy string meta renders nothing (no warnings) ---
        self::reset();
        $GLOBALS['POST_META_STORE'][42]['_presshub_ai_scorecard'] = 'legacy-string-meta';
        $html = self::render( 42 );
        if ( false !== strpos( $html, 'Score:' ) ) {
            $failures[] = 'String scorecard meta must not render a score box; got: ' . $html;
        }

        // --- Case 3: array without a score key renders nothing ---
        self::reset();
        $GLOBALS['POST_META_STORE'][42]['_presshub_ai_scorecard'] = [ 'feedback' => 'No score here.' ];
        $html = self::render( 42 );
        if ( false !== strpos( $html, 'Score:' ) ) {
            $failures[] = 'Scorecard without a score key must not render a score box; got: ' . $html;
        }

        // --- Case 4: non-numeric score renders nothing ---
        self::reset();
        $GLOBALS['POST_META_STORE'][42]['_presshub_ai_scorecard'] = [ 'score' => 'not-a-number', 'feedback' => 'x' ];
        $html = self::render( 42 );
        if ( false !== strpos( $html, 'Score:' ) ) {
            $failures[] = 'Non-numeric score must not render a score box; got: ' . $html;
        }

        // --- Case 5: non-string feedback renders the score but no feedback ---
        self::reset();
        $GLOBALS['POST_META_STORE'][42]['_presshub_ai_scorecard'] = [ 'score' => 72, 'feedback' => [ 'nested' ] ];
        $html = self::render( 42 );
        if ( false === strpos( $html, 'Score: 72/100' ) ) {
            $failures[] = 'A numeric score should render even when feedback is malformed; got: ' . $html;
        }
        if ( false !== strpos( $html, 'Array' ) ) {
            $failures[] = 'Non-string feedback must not be echoed; got: ' . $html;
        }

        // --- Case 6: no scorecard meta at all renders nothing ---
        self::reset();
        $html = self::render( 42 );
        if ( false !== strpos( $html, 'Score:' ) ) {
            $failures[] = 'Missing scorecard meta must not render a score box; got: ' . $html;
        }

        // --- Case 7: enqueue_assets fires on post edit screens with localize ---
        self::reset();
        $meta = new PressHub_AI_Metaboxes();
        $meta->enqueue_assets( 'post.php' );
        if ( empty( $GLOBALS['ENQUEUED_SCRIPTS']['presshub-ai-admin-js'] ) ) {
            $failures[] = 'enqueue_assets( post.php ) should enqueue presshub-ai-admin-js; got: ' . json_encode( array_keys( $GLOBALS['ENQUEUED_SCRIPTS'] ?? [] ) );
        }
        $localized = $GLOBALS['LOCALIZED_SCRIPTS']['presshub-ai-admin-js'] ?? null;
        if ( ! $localized || ( $localized['data']['nonce'] ?? '' ) !== 'test-nonce' ) {
            $failures[] = 'enqueue_assets should localize presshubAI with the nonce; got: ' . json_encode( $localized );
        }
        self::reset();
        $meta->enqueue_assets( 'dashboard.php' );
        if ( ! empty( $GLOBALS['ENQUEUED_SCRIPTS'] ) ) {
            $failures[] = 'enqueue_assets must not fire outside post edit screens.';
        }

        if ( $failures ) {
            fwrite( STDERR, "FAIL\n" );
            foreach ( $failures as $f ) {
                fwrite( STDERR, "  - {$f}\n" );
            }
            exit( 1 );
        }
        echo "OK\n";
    }

    private static function render( int $post_id ): string {
        $meta = new PressHub_AI_Metaboxes();
        $post = (object) [ 'ID' => $post_id ];
        ob_start();
        $meta->render_metabox( $post );
        return (string) ob_get_clean();
    }

    private static function reset(): void {
        unset( $GLOBALS['POST_META_STORE'], $GLOBALS['ENQUEUED_SCRIPTS'], $GLOBALS['ENQUEUED_STYLES'], $GLOBALS['LOCALIZED_SCRIPTS'] );
        $GLOBALS['POST_META_STORE']   = [];
        $GLOBALS['ENQUEUED_SCRIPTS']  = [];
        $GLOBALS['ENQUEUED_STYLES']   = [];
        $GLOBALS['LOCALIZED_SCRIPTS'] = [];
        $GLOBALS['CURRENT_USER_ID']   = 0;
        $GLOBALS['OPTIONS_STORE']     = [];
    }
}

MetaboxesRenderTest::run();
