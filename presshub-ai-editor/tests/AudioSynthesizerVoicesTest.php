<?php
/**
 * TDD regression test for Issue #89:
 *   "enhancement(workflow): Voice persona dropdown is hardcoded to Gemini 2.5
 *    voices; dynamically discover available TTS voices for the active
 *    model/engine and add explicit gender selection"
 *
 * Scope (per implementation_plan.md):
 *   - Gemini engines (gemini-2.5, gemini-3.1) get engine-aware manifests
 *     + dynamic discovery (live Gemini API call, 24h transient cache,
 *     manifest fallback on failure).
 *   - Other engines (google_cloud, openai-tts, elevenlabs) ship empty
 *     bundled voice lists for now and show a "best-effort manifest"
 *     notice — they remain on the legacy hardcoded path inside
 *     get_voice_for_speaker() until follow-up PRs extend the discovery
 *     layer per provider.
 *
 * This file covers acceptance criteria (a), (b), (c), (d) from the issue:
 *   (a) per-engine lookup returns the right slice from the manifest.
 *   (b) cache hit / miss — second call uses the transient, not the API.
 *   (c) API failure → fall back to bundled manifest, return WP_Error-free.
 *   (d) live discovery filters for TTS models (excludes text-only models).
 *
 * Test infrastructure mirrors DynamicModelDiscoveryTest.php:
 *   - wordpress-stubs.php provides wp_remote_get + GET_RESPONSE_FILTER.
 *   - This file adds minimal set_transient / get_transient / delete_transient
 *     stubs so we can exercise the 24h cache without a full WP runtime.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';

if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( $file ) { return 'http://example.test/wp-content/plugins/presshub-ai-editor/'; }
}

// Minimal transient stubs for the 24h cache layer. Self-contained here so
// we don't pollute the shared wordpress-stubs.php (which is loaded by
// dozens of unrelated tests).
if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $key, $value, $expiration = 0 ) {
        $GLOBALS['TRANSIENT_STORE'][ $key ] = $value;
        return true;
    }
}
if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $key ) {
        return $GLOBALS['TRANSIENT_STORE'][ $key ] ?? false;
    }
}
if ( ! function_exists( 'delete_transient' ) ) {
    function delete_transient( $key ) {
        unset( $GLOBALS['TRANSIENT_STORE'][ $key ] );
        return true;
    }
}

defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );

require_once dirname( __DIR__ ) . '/presshub-ai-editor.php';

class AudioSynthesizerVoicesTest {

    public static function run(): void {
        $failures = [];

        // ==================================================================
        // 1. Bundled manifest is loadable
        // ==================================================================
        $manifest_path = dirname( __DIR__ ) . '/assets/data/tts-voice-catalog.json';
        if ( ! is_file( $manifest_path ) ) {
            $failures[] = 'Bundled voice catalog missing at: ' . $manifest_path;
            self::finish( $failures );
            return;
        }
        $catalog = json_decode( (string) file_get_contents( $manifest_path ), true );
        if ( ! is_array( $catalog ) || ! isset( $catalog['engines'] ) ) {
            $failures[] = 'Bundled voice catalog is not a valid manifest with an "engines" key.';
            self::finish( $failures );
            return;
        }
        if ( ! isset( $catalog['engines']['gemini-2.5'] )
            || ! isset( $catalog['engines']['gemini-3.1'] )
            || ! isset( $catalog['engines']['google_cloud'] ) ) {
            $failures[] = 'Bundled voice catalog must define gemini-2.5, gemini-3.1, and google_cloud engines.';
            self::finish( $failures );
            return;
        }

        // ==================================================================
        // 2. (a) Per-engine lookup
        //    get_available_voices('gemini-2.5') returns a grouped array
        //    with female/male buckets, each voice entry exposing name,
        //    label, gender, language, engine_version.
        // ==================================================================
        self::reset_world();
        $synthesizer = new PressHub_AI_Audio_Synthesizer();

        $gemini25 = $synthesizer->get_available_voices( 'gemini-2.5' );
        if ( ! is_array( $gemini25 ) || ! isset( $gemini25['female'], $gemini25['male'] ) ) {
            $failures[] = 'get_available_voices("gemini-2.5") must return array with female + male buckets.';
        } else {
            if ( ! isset( $gemini25['female']['Kore'] ) ) {
                $failures[] = 'gemini-2.5 female bucket must contain Kore (backward-compat).';
            }
            if ( ! isset( $gemini25['male']['Fenrir'] ) ) {
                $failures[] = 'gemini-2.5 male bucket must contain Fenrir (backward-compat).';
            }
            $kore = $gemini25['female']['Kore'] ?? [];
            foreach ( [ 'name', 'label', 'gender', 'language', 'engine_version' ] as $required_key ) {
                if ( ! array_key_exists( $required_key, $kore ) ) {
                    $failures[] = "gemini-2.5 Kore entry must expose '$required_key' (schema contract).";
                }
            }
            if ( ! in_array( strtolower( (string) ( $kore['gender'] ?? '' ) ), [ 'female', 'f' ], true ) ) {
                $failures[] = 'gemini-2.5 Kore entry must have gender=female (downstream render relies on this).';
            }
        }

        // (a) gemini-3.1 must also be a valid engine key.
        $gemini31 = $synthesizer->get_available_voices( 'gemini-3.1' );
        if ( ! isset( $gemini31['female']['Kore'] ) || ! isset( $gemini31['male']['Fenrir'] ) ) {
            $failures[] = 'gemini-3.1 must bundle Kore + Fenrir (the canonical Gemini voice names per Google docs).';
        }

        // (a) google_cloud is in-scope but ships empty voice list for now.
        $gc = $synthesizer->get_available_voices( 'google_cloud' );
        if ( ! is_array( $gc ) ) {
            $failures[] = 'get_available_voices("google_cloud") must return an array (even if empty).';
        }
        // GC MUST keep backward-compat with the historical Chirp3/Wavenet names that
        // are already stored in operator wp_options rows — without these in the
        // manifest the sanitizer would start rejecting them and silently reset to
        // defaults (data loss). Verify the historical names are present.
        $gc_required = [ 'el-GR-Wavenet-A', 'el-GR-Chirp3-HD-Achird' ];
        foreach ( $gc_required as $gc_voice ) {
            $found = false;
            foreach ( $gc as $bucket => $voices ) {
                if ( isset( $voices[ $gc_voice ] ) ) {
                    $found = true;
                    break;
                }
            }
            if ( ! $found ) {
                $failures[] = "google_cloud manifest must contain legacy voice '$gc_voice' (backward-compat).";
            }
        }

        // ==================================================================
        // 3. Backward-compat with AudioSynthesizerTest.php invariants
        //    The legacy test file at tests/AudioSynthesizerTest.php
        //    asserts:
        //      isset( $voices['female']['Aoede'] ) === true
        //      isset( $voices['male']['Fenrir'] ) === true
        //      $voices['male']['Fenrir']['type'] === 'Gemini-Neural'
        //    These MUST continue to hold so the existing test passes.
        // ==================================================================
        if ( ! isset( $gemini25['female']['Aoede'] ) ) {
            $failures[] = 'Backward-compat regression: get_available_voices("gemini")["female"]["Aoede"] must still be present.';
        }
        if ( ! isset( $gemini25['male']['Fenrir'] ) ) {
            $failures[] = 'Backward-compat regression: get_available_voices("gemini")["male"]["Fenrir"] must still be present.';
        }
        $fenrir_type = $gemini25['male']['Fenrir']['type'] ?? null;
        if ( 'Gemini-Neural' !== $fenrir_type ) {
            $failures[] = "Backward-compat regression: male Fenrir entry must keep type='Gemini-Neural' (got: " . var_export( $fenrir_type, true ) . ')';
        }

        // ==================================================================
        // 4. (b) Cache hit / miss
        //    First call to discover_gemini_voices() must hit the network
        //    and populate the 24h transient. Second call must NOT hit the
        //    network.
        // ==================================================================
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_gemini_api_key'] = 'sk-test-gemini-key';

        $call_count = 0;
        $GLOBALS['GET_RESPONSE_FILTER'] = function ( $url, $args ) use ( &$call_count ) {
            $call_count++;
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [
                    'models' => [
                        [ 'name' => 'models/gemini-2.5-flash-preview-tts' ],
                        [ 'name' => 'models/gemini-3.1-flash-tts-preview' ],
                        [ 'name' => 'models/gemini-2.0-flash' ], // NOT a TTS model — must be filtered
                    ],
                ] ),
            ];
        };

        $synth = new PressHub_AI_Audio_Synthesizer();
        if ( ! method_exists( $synth, 'discover_gemini_voices' ) ) {
            $failures[] = 'PressHub_AI_Audio_Synthesizer must expose discover_gemini_voices() for dynamic catalog refresh (Issue #89 v2).';
        } else {
            $first  = $synth->discover_gemini_voices( 'sk-test-gemini-key' );
            $second = $synth->discover_gemini_voices( 'sk-test-gemini-key' );

            if ( 0 === $call_count ) {
                $failures[] = 'First discover_gemini_voices() call must hit the network.';
            }
            if ( is_wp_error( $first ) ) {
                $failures[] = 'First discover_gemini_voices() call returned WP_Error: ' . $first->get_error_message();
            } elseif ( ! is_array( $first ) ) {
                $failures[] = 'First discover_gemini_voices() call must return an array.';
            } else {
                // (d) live discovery must filter to TTS models only.
                $names = array_keys( $first );
                if ( ! in_array( 'gemini-2.5-flash-preview-tts', $names, true ) ) {
                    $failures[] = 'discover_gemini_voices() must include gemini-2.5-flash-preview-tts in the discovered model list.';
                }
                if ( ! in_array( 'gemini-3.1-flash-tts-preview', $names, true ) ) {
                    $failures[] = 'discover_gemini_voices() must include gemini-3.1-flash-tts-preview in the discovered model list.';
                }
                if ( in_array( 'gemini-2.0-flash', $names, true ) ) {
                    $failures[] = 'discover_gemini_voices() must EXCLUDE non-TTS models (gemini-2.0-flash leaked through).';
                }
            }

            // (b) Second call must hit the cache, not the network.
            $call_count_after_first = $call_count;
            $second = $synth->discover_gemini_voices( 'sk-test-gemini-key' );
            if ( $call_count > $call_count_after_first ) {
                $failures[] = 'Second discover_gemini_voices() call must hit the transient cache, not the network.';
            }
        }

        // ==================================================================
        // 5. (c) Fallback to manifest on API failure
        //    If the Gemini API returns a 5xx / WP_Error, the catalog
        //    function must still return the bundled manifest slice —
        //    not throw, not return WP_Error, not return an empty array.
        // ==================================================================
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_gemini_api_key'] = 'sk-test-gemini-key';

        $GLOBALS['GET_RESPONSE_FILTER'] = function ( $url, $args ) {
            return new WP_Error( 'http_500', 'simulated upstream failure' );
        };

        $synth = new PressHub_AI_Audio_Synthesizer();
        if ( method_exists( $synth, 'get_voice_catalog' ) ) {
            $catalog = $synth->get_voice_catalog( 'gemini-2.5' );
            if ( ! is_array( $catalog ) ) {
                $failures[] = 'get_voice_catalog() must return an array (never WP_Error or null) so the UI never breaks.';
            } elseif ( ! isset( $catalog['female']['Kore'] ) ) {
                $failures[] = 'get_voice_catalog("gemini-2.5") on API failure must fall back to bundled manifest (Kore missing).';
            }
        } else {
            $failures[] = 'PressHub_AI_Audio_Synthesizer must expose get_voice_catalog() to orchestrate discovery + cache + fallback (Issue #89 v2).';
        }

        // ==================================================================
        // 6. get_voice_for_speaker() with new option keys
        //    Sanity-check the consumer still works for all three speakers
        //    with engine defaults after the catalog refactor.
        // ==================================================================
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_tts_engine'] = 'gemini-2.5';
        $synth = new PressHub_AI_Audio_Synthesizer();
        if ( 'Kore' !== $synth->get_voice_for_speaker( 'female' ) ) {
            $failures[] = 'get_voice_for_speaker("female") must still default to Kore for gemini-2.5 engine.';
        }
        if ( 'Fenrir' !== $synth->get_voice_for_speaker( 'male' ) ) {
            $failures[] = 'get_voice_for_speaker("male") must still default to Fenrir for gemini-2.5 engine.';
        }

        // ==================================================================
        // 7. Settings_Storage::get_voice_female() / _male() / _tertiary() exist
        //    AGENTS.md §Settings-First: business logic must read voice
        //    choices via a static helper, not via get_option() + filter
        //    defaults. We require the helpers to exist as static methods.
        // ==================================================================
        foreach ( [ 'get_voice_female', 'get_voice_male' ] as $helper ) {
            if ( ! class_exists( 'PressHub_AI_Settings_Storage' ) ) {
                $failures[] = 'PressHub_AI_Settings_Storage class must be loadable for Settings_Storage helpers.';
                break;
            }
            if ( ! method_exists( 'PressHub_AI_Settings_Storage', $helper ) ) {
                $failures[] = "PressHub_AI_Settings_Storage::$helper() must exist (AGENTS.md §Settings-First).";
            }
        }

        self::finish( $failures );
    }

    private static function reset_world(): void {
        $GLOBALS['OPTIONS_STORE']       = [];
        $GLOBALS['REGISTERED_SETTINGS'] = [];
        $GLOBALS['SANITIZE_CALLBACKS']  = [];
        $GLOBALS['CAPTURED_GETS']       = [];
        $GLOBALS['GET_RESPONSE_FILTER'] = null;
        $GLOBALS['TRANSIENT_STORE']     = [];
        $_POST                          = [];
        $_REQUEST                       = [];
    }

    /**
     * @param array<string> $failures
     */
    private static function finish( array $failures ): void {
        if ( $failures ) {
            fwrite( STDERR, "FAIL\n" );
            foreach ( $failures as $f ) {
                $indented = preg_replace( '/^/m', '    ', $f );
                fwrite( STDERR, "  - {$indented}\n" );
            }
            exit( 1 );
        }
        echo "OK\n";
    }
}

AudioSynthesizerVoicesTest::run();
