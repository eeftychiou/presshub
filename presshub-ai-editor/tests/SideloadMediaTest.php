<?php
/**
 * TDD test for the sideload_media() helper extracted from class-api-client.
 *
 * RED phase: this test should fail because generate_image_via_imagen(),
 * mock_image_generation(), generate_audio_report(), and mock_audio_generation()
 * each duplicate the same "write tmp file → require wp-admin includes →
 * media_handle_sideload → unlink → return [id,url]" pattern.
 *
 * GREEN phase expectation: a single private/protected sideload_media() method
 * exists on PressHub_AI_API_Client and is used by all four call sites. The
 * helper handles the common boilerplate and each call site only supplies
 * its own filename and the raw bytes to write.
 *
 * Strategy: we can't observe private method invocation directly from outside,
 * so the test exercises the public surface (generate_image_via_imagen,
 * generate_audio_report) and verifies that:
 *   (a) the unified helper exists and is invokable
 *   (b) the public methods produce identical output shape when refactored
 *   (c) the public methods leave NO tmp file behind on success
 *   (d) the wp_remote_post path inside generate_image_via_imagen can be
 *       short-circuited via the capture filter so we don't actually need
 *       network access
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-api-client.php';

class SideloadMediaTest
{
    public static function run(): void {
        $failures = [];

        // Case 1: the helper exists as a method on the client.
        self::reset_world();
        if ( ! method_exists( 'PressHub_AI_API_Client', 'sideload_media' ) ) {
            $failures[] = "PressHub_AI_API_Client should expose a sideload_media() helper method.";
        }

        // Case 2: helper is invokable and returns the [id,url] shape for raw bytes.
        self::reset_world();
        $client = new PressHub_AI_API_Client();
        $invoke_sideload = function ( $client, ...$args ) {
            $r = new ReflectionMethod( $client, 'sideload_media' );
            $r->setAccessible( true );
            return $r->invoke( $client, ...$args );
        };

        if ( method_exists( $client, 'sideload_media' ) ) {
            $reflection = new ReflectionMethod( $client, 'sideload_media' );
            if ( ! $reflection->isPrivate() && ! $reflection->isProtected() ) {
                $failures[] = "sideload_media() should be private/protected (internal helper, not API).";
            }

            // Install a capture filter that injects a successful sideload by
            // overriding wp_remote_post responses — actually sideload_media
            // doesn't talk to network, it just writes the tmp file. So we
            // need media_handle_sideload to be stubbed. Use a closure in the
            // GLOBALS hook to capture the file_array and return a fake media id.
            $captured_files = [];
            $GLOBALS['SIDELOAD_CAPTURE'] = &$captured_files;
            self::stub_media_handle_sideload( 999 );

            $result = $invoke_sideload( $client, 'unit-test-image.jpg', 'BINARYDATA', 0, 'Test Image' );

            if ( ! is_array( $result ) || ! isset( $result['id'], $result['url'] ) ) {
                $failures[] = "sideload_media() should return ['id' => int, 'url' => string]; got: " . var_export( $result, true );
            } elseif ( 999 !== $result['id'] ) {
                $failures[] = "sideload_media() should propagate the media id from media_handle_sideload; got: {$result['id']}";
            }
            if ( empty( $captured_files ) ) {
                $failures[] = "sideload_media() should call media_handle_sideload with a file_array containing the provided filename.";
            } elseif ( 'unit-test-image.jpg' !== ( $captured_files[0]['name'] ?? null ) ) {
                $failures[] = "sideload_media() should pass the provided filename through to media_handle_sideload; got: " . var_export( $captured_files[0]['name'] ?? null, true );
            }

            // Verify the tmp file was cleaned up after success.
            $expected_tmp = self::tmp_path_for_filename( 'unit-test-image.jpg' );
            if ( file_exists( $expected_tmp ) ) {
                $failures[] = "sideload_media() should @unlink the tmp file on success; leftover: {$expected_tmp}";
                @unlink( $expected_tmp );
            }

            // Verify the post_id and title were propagated correctly.
            if ( ! empty( $captured_files ) ) {
                $GLOBALS['SIDELOAD_LAST_POST_ID'] = null;
                $GLOBALS['SIDELOAD_LAST_TITLE']  = null;
                self::capture_media_handle_sideload_args();
                $invoke_sideload( $client, 'unit-test-image-2.jpg', 'BIN2', 42, 'Custom Title' );
                if ( 42 !== ( $GLOBALS['SIDELOAD_LAST_POST_ID'] ?? null ) ) {
                    $failures[] = "sideload_media() should pass post_id through to media_handle_sideload; got: " . var_export( $GLOBALS['SIDELOAD_LAST_POST_ID'] ?? null, true );
                }
                if ( 'Custom Title' !== ( $GLOBALS['SIDELOAD_LAST_TITLE'] ?? null ) ) {
                    $failures[] = "sideload_media() should pass title through to media_handle_sideload; got: " . var_export( $GLOBALS['SIDELOAD_LAST_TITLE'] ?? null, true );
                }
            }
        }

        // Case 3: WP_Error from media_handle_sideload must propagate, not be swallowed.
        self::reset_world();
        if ( method_exists( $client, 'sideload_media' ) ) {
            self::stub_media_handle_sideload_error( 'sideload_failed', 'disk full' );
            $err = $invoke_sideload( $client, 'broken.jpg', 'X', 0, 'Broken' );
            if ( ! is_wp_error( $err ) ) {
                $failures[] = "sideload_media() should return WP_Error when media_handle_sideload fails; got: " . var_export( $err, true );
            } elseif ( 'sideload_failed' !== $err->get_error_code() ) {
                $failures[] = "sideload_media() should preserve the original WP_Error code; got: " . $err->get_error_code();
            }
            // Tmp file still cleaned up even on error.
            $expected_tmp = self::tmp_path_for_filename( 'broken.jpg' );
            if ( file_exists( $expected_tmp ) ) {
                $failures[] = "sideload_media() should still clean up tmp file on WP_Error; leftover: {$expected_tmp}";
                @unlink( $expected_tmp );
            }
        }

        // Case 4: end-to-end — generate_image_via_imagen() with the capture filter
        // returning a successful predictions payload must sideload via the helper
        // and return ['id','url'] without leaving tmp files.
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_google_cloud_api_key'] = 'test-gc-key';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'test-ai-key';
        self::stub_media_handle_sideload( 777 );
        $GLOBALS['CAPTURE_FILTER'] = function ( $existing, $req ) {
            // Provide a base64-encoded JPEG-like payload.
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [
                    'predictions' => [
                        [ 'bytesBase64Encoded' => base64_encode( 'JPEG_BYTES_HERE' ) ],
                    ],
                ] ),
            ];
        };
        $client = new PressHub_AI_API_Client();
        $result = $client->generate_image_via_imagen( 'a red fox' );
        if ( ! is_array( $result ) || ! isset( $result['id'], $result['url'] ) ) {
            $failures[] = "generate_image_via_imagen() should return ['id','url'] on success; got: " . var_export( $result, true );
        } elseif ( 777 !== ( $result['id'] ?? null ) ) {
            $failures[] = "generate_image_via_imagen() should use sideload_media() which calls media_handle_sideload; got id={$result['id']}";
        }
        // No leftover ai-image-* tmp files.
        foreach ( glob( sys_get_temp_dir() . '/ai-image-*.jpg' ) ?: [] as $leftover ) {
            $failures[] = "generate_image_via_imagen() leaked a tmp file: {$leftover}";
            @unlink( $leftover );
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

    private static function reset_world(): void {
        $GLOBALS['POST_META_STORE'] = [];
        $GLOBALS['OPTIONS_STORE'] = [];
        $GLOBALS['CURRENT_USER_CAPS'] = [];
        $GLOBALS['POST_STATUSES'] = [];
        $GLOBALS['HOOK_INVOCATION_COUNT'] = 0;
        $GLOBALS['WP_UPDATE_POST_CALLS'] = 0;
        $GLOBALS['HOOK_INVOCATION_LOG'] = [];
        $GLOBALS['DO_ACTION_LOG'] = [];
        $GLOBALS['TRANSITION_HANDLERS'] = [];
        $GLOBALS['CAPTURED_REQUESTS'] = [];
        $GLOBALS['CAPTURE_FILTER'] = null;
        $GLOBALS['SIDELOAD_CAPTURE'] = null;
        $GLOBALS['SIDELOAD_LAST_POST_ID'] = null;
        $GLOBALS['SIDELOAD_LAST_TITLE'] = null;
    }

    /**
     * Configure the global media_handle_sideload stub for a successful
     * sideload returning $media_id. The function is defined in
     * wordpress-stubs.php — we just configure its return value here.
     */
    private static function stub_media_handle_sideload( int $media_id ): void {
        $GLOBALS['SIDELOAD_FIXED_ID'] = $media_id;
        $GLOBALS['SIDELOAD_RETURN_ID'] = null;
        $GLOBALS['SIDELOAD_FAIL'] = null;
    }

    private static function stub_media_handle_sideload_error( string $code, string $msg ): void {
        $GLOBALS['SIDELOAD_FAIL'] = [ 'code' => $code, 'msg' => $msg ];
    }

    private static function capture_media_handle_sideload_args(): void {
        $GLOBALS['SIDELOAD_LAST_POST_ID_OVERRIDE'] = true;
        $GLOBALS['SIDELOAD_LAST_TITLE_OVERRIDE']  = true;
    }

    private static function tmp_path_for_filename( string $filename ): string {
        return sys_get_temp_dir() . '/' . $filename;
    }
}

SideloadMediaTest::run();