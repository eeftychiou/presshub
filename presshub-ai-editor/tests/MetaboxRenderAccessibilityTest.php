<?php
/**
 * Accessibility + UI review batch tests (2026-08-16 Antigravity UI review):
 *
 *   - QW-2 / F-13: every metabox input has a <label for=...> element
 *     (file input, sources textarea, instructions textarea, preset select);
 *   - QW-9 / F-04: the file-input label/description mentions WAV and M4A;
 *   - QW-6 / F-24: both metabox spinners carry role="status";
 *   - ME-4 / F-23: the server-side scorecard render emits the colour-coded
 *     tone classes (low < 50, mid 50-79, high >= 80) and the score badge,
 *     while keeping the "Score: X/100" text as the primary indicator;
 *   - ME-7 / F-05: the preset label names the effective default preset
 *     (author default when set, else the first enabled plugin default).
 */

// Extra stubs not provided by the shared harness.
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) {
        return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
    }
}
if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $text ) {
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

class MetaboxRenderAccessibilityTest
{
    public static function run(): void {
        $failures = [];

        // --- Case 1: every input has a <label for=...> (QW-2 / F-13) ---
        self::reset();
        $html = self::render( 42 );
        foreach ( [ 'presshub-ai-files', 'presshub-ai-sources', 'presshub-ai-instructions', 'presshub-ai-preset' ] as $id ) {
            if ( false === strpos( $html, 'label for="' . $id . '"' ) ) {
                $failures[] = "Missing <label for=\"{$id}\"> in metabox render.";
            }
            if ( false === strpos( $html, 'id="' . $id . '"' ) ) {
                $failures[] = "Missing control id=\"{$id}\" in metabox render.";
            }
        }

        // --- Case 2: file-input label mentions WAV and M4A (QW-9 / F-04) ---
        if ( false === strpos( $html, 'WAV' ) || false === strpos( $html, 'M4A' ) ) {
            $failures[] = 'File input label must mention WAV and M4A; got: ' . $html;
        }

        // --- Case 3: spinners carry role="status" (QW-6 / F-24) ---
        foreach ( [ 'presshub-ai-draft-spinner', 'presshub-ai-review-spinner' ] as $id ) {
            if ( false === strpos( $html, 'id="' . $id . '" class="spinner" role="status"' ) ) {
                $failures[] = "Spinner {$id} must carry role=\"status\".";
            }
        }

        // --- Case 4: scorecard tone classes + badge (ME-4 / F-23) ---
        self::reset();
        $GLOBALS['POST_META_STORE'][42]['_presshub_ai_scorecard'] = [ 'score' => 85, 'feedback' => 'Great.' ];
        $html = self::render( 42 );
        if ( false === strpos( $html, 'scorecard-box presshub-score-high' ) ) {
            $failures[] = 'Score 85 must render presshub-score-high; got: ' . $html;
        }
        if ( false === strpos( $html, 'presshub-score-badge' ) || false === strpos( $html, '85/100' ) ) {
            $failures[] = 'Scorecard must include the score badge; got: ' . $html;
        }
        if ( false === strpos( $html, 'Score: 85/100' ) ) {
            $failures[] = 'Score text must stay the primary indicator; got: ' . $html;
        }

        self::reset();
        $GLOBALS['POST_META_STORE'][42]['_presshub_ai_scorecard'] = [ 'score' => 60, 'feedback' => 'Okay.' ];
        $html = self::render( 42 );
        if ( false === strpos( $html, 'scorecard-box presshub-score-mid' ) ) {
            $failures[] = 'Score 60 must render presshub-score-mid; got: ' . $html;
        }

        self::reset();
        $GLOBALS['POST_META_STORE'][42]['_presshub_ai_scorecard'] = [ 'score' => 30, 'feedback' => 'Weak.' ];
        $html = self::render( 42 );
        if ( false === strpos( $html, 'scorecard-box presshub-score-low' ) ) {
            $failures[] = 'Score 30 must render presshub-score-low; got: ' . $html;
        }

        // --- Case 5: preset label shows the effective default (ME-7 / F-05) ---
        // 5a: no author default -> first enabled plugin default (seeded
        //     lazily when the option has never been written).
        self::reset();
        $html = self::render( 42 );
        if ( false === strpos( $html, 'Author Style Preset (default: Wire service concise)' ) ) {
            $failures[] = 'Preset label must name the fallback plugin default; got: ' . $html;
        }

        // 5b: author default set -> that preset's name is shown.
        self::reset();
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'interview-focus', 'name' => 'Interview-driven', 'instruction_text' => 'Anchor on quotes.', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'interview-focus';
        $html = self::render( 42 );
        if ( false === strpos( $html, 'Author Style Preset (default: Interview-driven)' ) ) {
            $failures[] = 'Preset label must name the author default; got: ' . $html;
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
        unset( $GLOBALS['POST_META_STORE'], $GLOBALS['OPTIONS_STORE'], $GLOBALS['USER_META_STORE'] );
        $GLOBALS['POST_META_STORE'] = [];
        $GLOBALS['OPTIONS_STORE']   = [];
        $GLOBALS['USER_META_STORE'] = [];
        $GLOBALS['CURRENT_USER_ID'] = 0;
    }
}

MetaboxRenderAccessibilityTest::run();
