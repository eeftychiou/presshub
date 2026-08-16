<?php
/**
 * TDD tests for upload validation in generate_draft (Low-15 from the
 * 2026-08-16 review synthesis: upload type/size validation).
 *
 * Only whitelisted extensions (.pdf/.docx/.mp3/.mp4/.wav/.m4a) up to
 * 50 MB per file may reach wp_handle_upload; anything else is rejected
 * with wp_send_json_error BEFORE any file is moved (so a mixed batch
 * never leaves partially-moved files behind).
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-api-client.php';
require_once __DIR__ . '/../includes/class-ajax-handlers.php';

class UploadValidationTest
{
    public static function run(): void {
        $failures = [];

        // --- Case 1: disallowed extension rejected before any upload ---
        self::reset();
        $_FILES = [ 'files' => self::files_array( [ [ 'evil.exe', 1024 ] ] ) ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->generate_draft();
        } );
        if ( false === strpos( $msg, 'Unsupported file type' ) ) {
            $failures[] = "Disallowed extension should be rejected; got: {$msg}";
        }
        if ( ( $GLOBALS['HANDLE_UPLOAD_CALLS'] ?? 0 ) !== 0 ) {
            $failures[] = 'wp_handle_upload must not be called for a disallowed extension.';
        }

        // --- Case 2: oversized file rejected before any upload ---
        self::reset();
        $_FILES = [ 'files' => self::files_array( [ [ 'big.pdf', 51 * 1024 * 1024 ] ] ) ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->generate_draft();
        } );
        if ( false === strpos( $msg, '50 MB' ) ) {
            $failures[] = "Oversized file should be rejected; got: {$msg}";
        }
        if ( ( $GLOBALS['HANDLE_UPLOAD_CALLS'] ?? 0 ) !== 0 ) {
            $failures[] = 'wp_handle_upload must not be called for an oversized file.';
        }

        // --- Case 3: whitelisted extension under the cap is uploaded ---
        self::reset();
        $tmpfile = tempnam( sys_get_temp_dir(), 'phu' );
        $GLOBALS['HANDLE_UPLOAD_RESULT'] = [ 'file' => $tmpfile, 'url' => 'http://example.test/report.pdf' ];
        $_FILES = [ 'files' => self::files_array( [ [ 'report.pdf', 2048 ] ] ) ];
        $data = self::drive_success( function () {
            ( new PressHub_AI_Ajax_Handlers() )->generate_draft();
        } );
        if ( ( $GLOBALS['HANDLE_UPLOAD_CALLS'] ?? 0 ) !== 1 ) {
            $failures[] = 'Valid file should reach wp_handle_upload exactly once; calls=' . ( $GLOBALS['HANDLE_UPLOAD_CALLS'] ?? 0 );
        }
        if ( ! is_array( $data ) || ! isset( $data['draft'] ) ) {
            $failures[] = 'Valid upload should still produce a draft; got: ' . var_export( $data, true );
        }
        @unlink( $tmpfile );

        // --- Case 4: every whitelisted extension is accepted ---
        foreach ( [ 'pdf', 'docx', 'mp3', 'mp4', 'wav', 'm4a' ] as $ext ) {
            self::reset();
            $tmpfile = tempnam( sys_get_temp_dir(), 'phu' );
            $GLOBALS['HANDLE_UPLOAD_RESULT'] = [ 'file' => $tmpfile, 'url' => "http://example.test/x.{$ext}" ];
            $_FILES = [ 'files' => self::files_array( [ [ "audio.{$ext}", 1024 ] ] ) ];
            try {
                ( new PressHub_AI_Ajax_Handlers() )->generate_draft();
            } catch ( RuntimeException $e ) {
                // terminal wp_send_json_success — expected
            }
            if ( ( $GLOBALS['HANDLE_UPLOAD_CALLS'] ?? 0 ) !== 1 ) {
                $failures[] = "Allowed extension .{$ext} should reach wp_handle_upload.";
            }
            @unlink( $tmpfile );
        }

        // --- Case 5: a mixed batch (one bad file) rejects the whole
        //             request before ANY file is moved ---
        self::reset();
        $_FILES = [ 'files' => self::files_array( [ [ 'ok.pdf', 1024 ], [ 'bad.sh', 1024 ] ] ) ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->generate_draft();
        } );
        if ( false === strpos( $msg, 'Unsupported file type' ) ) {
            $failures[] = "Mixed valid/invalid uploads should reject the request; got: {$msg}";
        }
        if ( ( $GLOBALS['HANDLE_UPLOAD_CALLS'] ?? 0 ) !== 0 ) {
            $failures[] = 'No file should be uploaded when any file in the batch is invalid.';
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

    private static function files_array( array $rows ): array {
        $out = [ 'name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => [] ];
        foreach ( $rows as $i => $row ) {
            [ $name, $size ] = $row;
            $out['name'][$i]     = $name;
            $out['type'][$i]     = 'application/octet-stream';
            $out['tmp_name'][$i] = '/tmp/php' . $i;
            $out['error'][$i]    = 0;
            $out['size'][$i]     = $size;
        }
        return $out;
    }

    private static function expect_json_error( callable $fn ): string {
        try {
            $fn();
        } catch ( RuntimeException $e ) {
            return $e->getMessage();
        }
        return '(no error thrown)';
    }

    private static function drive_success( callable $fn ) {
        try {
            $fn();
        } catch ( RuntimeException $e ) {
            $responses = $GLOBALS['JSON_RESPONSES'] ?? [];
            $last      = end( $responses );
            if ( is_array( $last ) && ( $last['success'] ?? null ) === true ) {
                return $last['data'];
            }
            throw $e;
        }
        return null;
    }

    private static function reset(): void {
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['JSON_RESPONSES'] = [];
        $GLOBALS['CAPTURE_FILTER'] = null;
        $GLOBALS['CAPTURED_REQUESTS'] = [];
        $GLOBALS['OPTIONS_STORE'] = [
            'presshub_ai_api_key'  => 'k',
            'presshub_ai_provider' => 'gemini', // OpenAI rejects direct uploads
        ];
        $GLOBALS['NONCE_VALID'] = true;
        $GLOBALS['HANDLE_UPLOAD_CALLS'] = 0;
        unset( $GLOBALS['HANDLE_UPLOAD_RESULT'] );
        $_POST = [ 'sources' => 'src', 'instructions' => 'instr', 'nonce' => 'valid' ];
        $_FILES = [];
        $GLOBALS['CAPTURE_FILTER'] = function ( $prev, $ctx ) {
            [ $url ] = $ctx;
            if ( str_contains( $url, 'generativelanguage.googleapis.com' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => json_encode( [
                        'candidates' => [ [ 'content' => [ 'parts' => [ [ 'text' => 'draft text' ] ] ] ] ],
                    ] ),
                ];
            }
            return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
        };
    }
}

UploadValidationTest::run();
