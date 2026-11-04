<?php
/**
 * PressHub Social AI Copywriter
 *
 * Generates platform-tailored social media copy and captions for published
 * WordPress posts, supporting both dynamic LLM generation and static template substitution.
 *
 * Adheres strictly to the Zero-Silent-Fallback and Clean Slate Architecture principles.
 *
 * @package PressHub_Social_AutoPost
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-social-logger.php';
require_once __DIR__ . '/class-social-prompt-loader.php';
require_once __DIR__ . '/class-social-settings-storage.php';

class PressHub_Social_AI_Copywriter {

    /**
     * Optional callable override for isolated unit testing.
     * Signature: callable( string $sys_prompt, string $user_prompt, array $resolved_config ): string|WP_Error
     *
     * @var callable|null
     */
    private static $custom_ai_caller = null;

    /**
     * Set a custom AI caller for testing or interception.
     *
     * @param callable|null $caller Callable or null to reset.
     * @return void
     */
    public static function set_ai_caller( ?callable $caller ): void {
        self::$custom_ai_caller = $caller;
    }

    /**
     * Retrieve the active custom AI caller, if any.
     *
     * @return callable|null
     */
    public static function get_ai_caller(): ?callable {
        return self::$custom_ai_caller;
    }

    /**
     * Generate social copy for a WordPress post on the specified platform.
     *
     * @param int    $post_id WordPress post ID.
     * @param string $platform Target platform (twitter, facebook, instagram, tiktok).
     * @return string|WP_Error Generated social text or WP_Error on failure.
     */
    public static function generate_for_post( int $post_id, string $platform ) {
        $article_data = self::build_article_data( $post_id );
        if ( is_wp_error( $article_data ) ) {
            return $article_data;
        }

        return self::generate_from_data( $article_data, $platform );
    }

    /**
     * Generate social copy for a WordPress post across all specified (or enabled) platforms.
     *
     * @param int   $post_id WordPress post ID.
     * @param array $platforms Optional list of platforms; defaults to enabled platforms.
     * @return array|WP_Error Associative array of platform => copy string, or WP_Error on failure.
     */
    public static function generate_all_for_post( int $post_id, array $platforms = [] ) {
        $article_data = self::build_article_data( $post_id );
        if ( is_wp_error( $article_data ) ) {
            return $article_data;
        }

        if ( empty( $platforms ) ) {
            $platforms = PressHub_Social_Settings_Storage::get_enabled_platforms();
        }
        if ( empty( $platforms ) ) {
            $platforms = PressHub_Social_Settings_Storage::PLATFORMS;
        }

        $results = [];
        $errors  = [];

        foreach ( $platforms as $platform ) {
            $platform = sanitize_key( $platform );
            if ( ! in_array( $platform, PressHub_Social_Settings_Storage::PLATFORMS, true ) ) {
                continue;
            }
            $res = self::generate_from_data( $article_data, $platform );
            if ( is_wp_error( $res ) ) {
                $errors[ $platform ] = $res->get_error_message();
            } else {
                $results[ $platform ] = $res;
            }
        }

        if ( empty( $results ) && ! empty( $errors ) ) {
            $first_err = reset( $errors );
            return new WP_Error( 'all_platforms_failed', $first_err );
        }

        return $results;
    }

    /**
     * Generate social copy from an article data array for the specified platform.
     *
     * @param array  $article_data Article metadata array (title, excerpt, content, url, site_name, hashtags).
     * @param string $platform Target platform (twitter, facebook, instagram, tiktok).
     * @return string|WP_Error Generated social copy or WP_Error on failure.
     */
    public static function generate_from_data( array $article_data, string $platform ) {
        $platform = sanitize_key( $platform );

        if ( ! in_array( $platform, PressHub_Social_Settings_Storage::PLATFORMS, true ) ) {
            $msg = sprintf( __( 'Unsupported social platform: %s', 'presshub-social-autopost' ), $platform );
            PressHub_Social_Logger::error( $msg, [ 'platform' => $platform ] );
            return new WP_Error( 'invalid_platform', $msg );
        }

        $copy_mode = PressHub_Social_Settings_Storage::get_copy_mode();

        // ------------------------------------------------------------------
        // Mode 1: Static Template Substitution
        // ------------------------------------------------------------------
        if ( 'template' === $copy_mode ) {
            $template = PressHub_Social_Settings_Storage::get_platform_template( $platform );

            // Zero-Silent-Fallback: Fail loudly if template is empty.
            if ( '' === trim( $template ) ) {
                $msg = sprintf( __( 'No template configured for platform "%s".', 'presshub-social-autopost' ), $platform );
                PressHub_Social_Logger::error( $msg, [ 'platform' => $platform ] );
                return new WP_Error( 'missing_social_template', $msg );
            }

            $site_name = ! empty( $article_data['site_name'] ) ? $article_data['site_name'] : ( function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : '' );
            $url       = $article_data['url'] ?? '';

            $replacements = [
                '{title}'     => $article_data['title'] ?? '',
                '{excerpt}'   => $article_data['excerpt'] ?? '',
                '{content}'   => $article_data['content'] ?? '',
                '{url}'       => $url,
                '{site_name}' => $site_name,
                '{hashtags}'  => $article_data['hashtags'] ?? '',
            ];

            $rendered = str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
            $rendered = trim( $rendered );

            if ( 'twitter' === $platform ) {
                $max_chars = PressHub_Social_Settings_Storage::get_twitter_max_chars();
                $rendered  = self::truncate_twitter_text( $rendered, $max_chars, $url );
            }

            return $rendered;
        }

        // ------------------------------------------------------------------
        // Mode 2: Dynamic AI Copywriter (LLM)
        // ------------------------------------------------------------------
        $sys_prompt    = PressHub_Social_Settings_Storage::get_system_prompt();
        $user_template = PressHub_Social_Settings_Storage::get_platform_prompt( $platform );

        // Zero-Silent-Fallback: Never substitute hidden inline code strings or switch to template mode silently.
        if ( '' === trim( $sys_prompt ) || '' === trim( $user_template ) ) {
            $missing = [];
            if ( '' === trim( $sys_prompt ) ) {
                $missing[] = 'presshub_social_prompt_system';
            }
            if ( '' === trim( $user_template ) ) {
                $missing[] = 'presshub_social_prompt_' . $platform;
            }

            $msg = sprintf(
                __( 'Missing required prompt configuration for %s: %s is empty. Please configure prompt in Settings.', 'presshub-social-autopost' ),
                $platform,
                implode( ', ', $missing )
            );
            PressHub_Social_Logger::error( $msg, [ 'platform' => $platform, 'missing' => $missing ] );
            return new WP_Error( 'missing_social_prompt', $msg );
        }

        // Resolve AI provider configuration strictly following Clean Slate Precedence.
        $resolved_config = self::resolve_ai_provider_config();
        if ( is_wp_error( $resolved_config ) ) {
            return $resolved_config;
        }

        $site_name = ! empty( $article_data['site_name'] ) ? $article_data['site_name'] : ( function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : '' );
        $url       = $article_data['url'] ?? '';
        $max_chars = ( 'twitter' === $platform ) ? PressHub_Social_Settings_Storage::get_twitter_max_chars() : 280;

        $replacements = [
            '{title}'     => $article_data['title'] ?? '',
            '{excerpt}'   => $article_data['excerpt'] ?? '',
            '{content}'   => $article_data['content'] ?? '',
            '{url}'       => $url,
            '{site_name}' => $site_name,
            '{max_chars}' => (string) $max_chars,
            '{hashtags}'  => $article_data['hashtags'] ?? '',
        ];

        $composed_user_prompt = str_replace( array_keys( $replacements ), array_values( $replacements ), $user_template );

        // Call AI provider.
        $raw_result = null;
        if ( null !== self::$custom_ai_caller ) {
            $raw_result = call_user_func( self::$custom_ai_caller, $sys_prompt, $composed_user_prompt, $resolved_config );
        } else {
            if ( ! class_exists( 'PressHub_AI_API_Client' ) ) {
                $msg = __( 'PressHub AI API Client not found. Please ensure PressHub AI Editor plugin is active.', 'presshub-social-autopost' );
                PressHub_Social_Logger::error( $msg );
                return new WP_Error( 'missing_api_client', $msg );
            }

            try {
                $client = new PressHub_AI_API_Client( $resolved_config );
                if ( method_exists( $client, 'set_action' ) ) {
                    $client->set_action( 'social_copy_' . $platform );
                }

                $metadata = [
                    'platform'        => $platform,
                    'provider_source' => $resolved_config['provider_source'] ?? '',
                    'model_source'    => $resolved_config['model_source'] ?? '',
                ];

                $raw_result = $client->call_provider(
                    $sys_prompt,
                    $composed_user_prompt,
                    false,
                    [],
                    $resolved_config['temperature'],
                    $metadata
                );
            } catch ( Exception $e ) {
                $msg = sprintf( __( 'AI copywriter exception for %s: %s', 'presshub-social-autopost' ), $platform, $e->getMessage() );
                PressHub_Social_Logger::error( $msg, [ 'platform' => $platform, 'exception' => $e->getMessage() ] );
                return new WP_Error( 'ai_dispatch_exception', $msg );
            }
        }

        // Verify result (Zero-Silent-Fallback).
        if ( is_wp_error( $raw_result ) ) {
            PressHub_Social_Logger::error(
                sprintf( 'AI copy generation failed for %s: %s', $platform, $raw_result->get_error_message() ),
                [ 'platform' => $platform, 'error_code' => $raw_result->get_error_code() ]
            );
            return $raw_result;
        }

        if ( ! is_string( $raw_result ) || '' === trim( $raw_result ) ) {
            $msg = sprintf( __( 'AI provider returned an empty response for %s.', 'presshub-social-autopost' ), $platform );
            PressHub_Social_Logger::error( $msg, [ 'platform' => $platform ] );
            return new WP_Error( 'empty_ai_response', $msg );
        }

        $copy = trim( $raw_result );

        // Strip enclosing quotes if model wrapped the whole response in quotation marks.
        if ( ( str_starts_with( $copy, '"' ) && str_ends_with( $copy, '"' ) ) ||
             ( str_starts_with( $copy, '“' ) && str_ends_with( $copy, '”' ) ) ) {
            $copy = trim( mb_substr( $copy, 1, -1, 'UTF-8' ) );
        }

        // For Twitter, enforce character cap cleanly while preserving article URL.
        if ( 'twitter' === $platform ) {
            $copy = self::truncate_twitter_text( $copy, $max_chars, $url );
        }

        return $copy;
    }

    /**
     * Resolve AI provider configuration strictly following Clean Slate Architecture precedence:
     * 1. Per-module explicit override option (presshub_social_ai_provider / presshub_social_ai_*)
     * 2. Configured provider record from PressHub_AI_Provider_Store (first enabled if none specified)
     * 3. Class defaults from PressHub_AI_Provider_Defaults
     *
     * @return array|WP_Error Resolved provider configuration array or WP_Error.
     */
    public static function resolve_ai_provider_config() {
        $module_provider_id = PressHub_Social_Settings_Storage::get_ai_provider();
        $provider_record    = null;
        $provider_source    = '';

        if ( class_exists( 'PressHub_AI_Provider_Store' ) ) {
            if ( '' !== $module_provider_id ) {
                $provider_record = PressHub_AI_Provider_Store::get( $module_provider_id );
                if ( null !== $provider_record ) {
                    $provider_source = 'module_override:presshub_social_ai_provider';
                }
            }

            if ( null === $provider_record ) {
                $enabled = PressHub_AI_Provider_Store::get_all( true );
                if ( ! empty( $enabled ) && is_array( $enabled ) ) {
                    foreach ( $enabled as $ep ) {
                        $ep_type = $ep['type'] ?? '';
                        if ( ! empty( $ep['enabled'] ) && ( ! empty( $ep['api_key'] ) || 'ollama_local' === $ep_type ) ) {
                            $provider_record = $ep;
                            $provider_source = 'store_fallback:first_enabled_provider';
                            break;
                        }
                    }
                    if ( null === $provider_record ) {
                        $provider_record = $enabled[0];
                        $provider_source = 'store_fallback:first_enabled_provider';
                    }
                }
            }
        }

        if ( null === $provider_record ) {
            $msg = __( 'No AI provider is configured or enabled in PressHub AI Editor.', 'presshub-social-autopost' );
            PressHub_Social_Logger::error( $msg, [ 'requested_id' => $module_provider_id ] );
            return new WP_Error( 'missing_ai_provider', $msg );
        }

        $type        = $provider_record['type'] ?? 'openai';
        $provider_id = $provider_record['id'] ?? $type;
        $name        = $provider_record['name'] ?? ucwords( str_replace( [ '_', '-' ], ' ', $type ) );
        $base_url    = $provider_record['base_url'] ?? '';
        $headers     = $provider_record['headers'] ?? [];
        $api_key     = (string) ( $provider_record['api_key'] ?? '' );

        // API Key verification.
        if ( 'ollama_local' !== $type && '' === trim( $api_key ) ) {
            $msg = sprintf( __( 'AI provider "%s" (ID: %s) does not have an API key configured.', 'presshub-social-autopost' ), $name, $provider_id );
            PressHub_Social_Logger::error( $msg, [ 'provider_id' => $provider_id, 'type' => $type ] );
            return new WP_Error( 'missing_ai_api_key', $msg );
        }

        // Model resolution: Module override -> Provider default -> Class defaults.
        $model        = '';
        $model_source = '';
        $module_model = PressHub_Social_Settings_Storage::get_ai_model();

        if ( '' !== $module_model ) {
            $model        = $module_model;
            $model_source = 'module_override:presshub_social_ai_model';
        } elseif ( ! empty( $provider_record['default_model'] ) ) {
            $model        = (string) $provider_record['default_model'];
            $model_source = 'provider_store_default:' . $provider_id;
        } elseif ( class_exists( 'PressHub_AI_Provider_Defaults' ) ) {
            $model        = PressHub_AI_Provider_Defaults::default_model( $type );
            $model_source = 'class_defaults:PressHub_AI_Provider_Defaults';
        } else {
            $model        = 'gpt-4o';
            $model_source = 'fallback:gpt-4o';
        }
        $model = preg_replace( '#^models/#', '', $model );

        // Temperature resolution.
        $module_temp = PressHub_Social_Settings_Storage::get_ai_temperature();
        if ( null !== $module_temp ) {
            $temperature = $module_temp;
        } elseif ( isset( $provider_record['temperature'] ) && '' !== (string) $provider_record['temperature'] ) {
            $temperature = max( 0.0, min( 2.0, (float) $provider_record['temperature'] ) );
        } elseif ( class_exists( 'PressHub_AI_Provider_Defaults' ) ) {
            $temperature = PressHub_AI_Provider_Defaults::default_temperature();
        } else {
            $temperature = 0.7;
        }

        // Max Tokens resolution.
        $module_tokens = PressHub_Social_Settings_Storage::get_ai_max_tokens();
        if ( $module_tokens >= 64 ) {
            $max_tokens = min( 65536, $module_tokens );
        } elseif ( ! empty( $provider_record['max_tokens'] ) && (int) $provider_record['max_tokens'] >= 64 ) {
            $max_tokens = min( 65536, (int) $provider_record['max_tokens'] );
        } elseif ( class_exists( 'PressHub_AI_Provider_Defaults' ) ) {
            $max_tokens = PressHub_AI_Provider_Defaults::default_max_tokens();
        } else {
            $max_tokens = 2000;
        }

        // Timeout resolution.
        $module_timeout = PressHub_Social_Settings_Storage::get_ai_timeout();
        if ( $module_timeout >= 10 ) {
            $timeout = min( 600, $module_timeout );
        } elseif ( ! empty( $provider_record['timeout'] ) && (int) $provider_record['timeout'] >= 10 ) {
            $timeout = min( 600, (int) $provider_record['timeout'] );
        } elseif ( class_exists( 'PressHub_AI_Provider_Defaults' ) ) {
            $timeout = PressHub_AI_Provider_Defaults::default_timeout( $type );
        } else {
            $timeout = 120;
        }

        PressHub_Social_Logger::info(
            sprintf(
                '[Social AI Resolution] Module: "social_copy" | Provider: "%s" (%s, ID: %s, source: %s) | Model: "%s" (source: %s)',
                $name,
                $type,
                $provider_id,
                $provider_source,
                $model,
                $model_source
            ),
            [
                'provider_source' => $provider_source,
                'model_source'    => $model_source,
                'model'           => $model,
                'temperature'     => $temperature,
                'max_tokens'      => $max_tokens,
                'timeout'         => $timeout,
            ]
        );

        return [
            'module'           => 'social_copy',
            'id'               => $provider_id,
            'provider'         => $provider_id,
            'type'             => $type,
            'name'             => $name,
            'api_key'          => $api_key,
            'base_url'         => $base_url,
            'model'            => $model,
            'default_model'    => $model,
            'available_models' => $provider_record['available_models'] ?? [ $model ],
            'temperature'      => $temperature,
            'max_tokens'       => $max_tokens,
            'timeout'          => $timeout,
            'headers'          => $headers,
            'enabled'          => true,
            'is_system'        => $provider_record['is_system'] ?? false,
            'provider_source'  => $provider_source,
            'model_source'     => $model_source,
        ];
    }

    /**
     * Clean podcast dialogue script by stripping topic markers and speaker tags.
     *
     * @param string $script Raw podcast script text.
     * @return string Cleaned plain text script.
     */
    public static function clean_podcast_script( string $script ): string {
        if ( '' === trim( $script ) ) {
            return '';
        }

        // Delegate to Audio Synthesizer strip_topic_markers if loaded
        if ( class_exists( 'PressHub_AI_Audio_Synthesizer' ) ) {
            $synth = new PressHub_AI_Audio_Synthesizer();
            if ( method_exists( $synth, 'strip_topic_markers' ) ) {
                $script = $synth->strip_topic_markers( $script );
            }
        }

        // Strip line-level topic markers
        $lines = preg_split( '/\r?\n/', $script );
        $out   = [];
        foreach ( $lines as $line ) {
            $trimmed = trim( $line );
            if ( '' === $trimmed ) {
                continue;
            }
            if ( preg_match( '/^(?:\[|\<\!\-\-|\={2,3}|\#{2,3})?\s*TOPIC(?:_?START)?\s*(?::[^\n\]\>\=]*)?(?:\]|\-\-\>|\={2,3})?$/iu', $trimmed ) ||
                 preg_match( '/^(?:\[|\<\!\-\-)?\s*TOPIC_?END\s*(?:\]|\-\-\>)?$/iu', $trimmed ) ) {
                continue;
            }
            $out[] = $line;
        }
        $script = implode( "\n", $out );

        // Strip inline topic markers
        $script = preg_replace( '/\[\s*TOPIC(?:_?START)?\s*(?::[^\]]*)?\]/iu', '', $script );
        $script = preg_replace( '/\[\s*TOPIC_?END\s*\]/iu', '', $script );
        $script = preg_replace( '/\<\!\-\-\s*TOPIC(?:_?START)?\s*(?::[^\>]*)?\-\-\>/iu', '', $script );
        $script = preg_replace( '/\<\!\-\-\s*TOPIC_?END\s*\-\-\>/iu', '', $script );
        $script = preg_replace( '/(?:\={2,3}|\#{2,3})\s*TOPIC(?:_?START)?\s*(?::[^\=\#\n]*)?(?:\={2,3}|\#{2,3})/iu', '', $script );

        // Strip speaker tags:
        // Bracketed tags: [SPEAKER_0]:, **[SPEAKER_1]:**, [Presenter 1]:, [Host 1]:, etc.
        $script = preg_replace( '/(?:\*{1,2})?\[\s*(?:SPEAKER_\d+|Presenter\s*\d+|Host\s*\d+|Speaker\s*\d+)(?:\s*\([^)]*\))?\s*\](?:\*{1,2})?\s*:\s*(?:\*{1,2})?/iu', '', $script );
        // Unbracketed tags: SPEAKER_0:, Presenter 1:, Host 1:, etc.
        $script = preg_replace( '/\b(?:SPEAKER_\d+|Presenter\s*\d+|Host\s*\d+)(?:\s*\([^)]*\))?\s*:\s*/iu', '', $script );

        // Strip shortcodes if WordPress function is present
        if ( function_exists( 'strip_shortcodes' ) ) {
            $script = strip_shortcodes( $script );
        }

        // Strip HTML tags and decode entities
        $clean = function_exists( 'wp_strip_all_tags' )
            ? wp_strip_all_tags( html_entity_decode( $script, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) )
            : strip_tags( html_entity_decode( $script, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

        // Collapse multiple whitespace/newlines into a single space
        $clean = preg_replace( '/\s+/', ' ', $clean );

        return trim( $clean );
    }

    /**
     * Build normalized article data from a WordPress post.
     *
     * @param int $post_id WordPress post ID.
     * @return array|WP_Error Article metadata array or WP_Error.
     */
    public static function build_article_data( int $post_id ) {
        $post = get_post( $post_id );
        if ( ! is_object( $post ) ) {
            $msg = sprintf( __( 'Post ID %d not found.', 'presshub-social-autopost' ), $post_id );
            PressHub_Social_Logger::error( $msg, [ 'post_id' => $post_id ] );
            return new WP_Error( 'invalid_post', $msg );
        }

        $title = function_exists( 'get_the_title' ) ? get_the_title( $post ) : (string) ( $post->post_title ?? '' );

        // Excerpt resolution.
        $raw_content = (string) ( $post->post_content ?? '' );
        $raw_excerpt = (string) ( $post->post_excerpt ?? '' );
        $excerpt     = ( function_exists( 'has_excerpt' ) && has_excerpt( $post ) ) ? $raw_excerpt : $raw_excerpt;
        if ( '' === trim( $excerpt ) ) {
            $stripped_body = wp_strip_all_tags( function_exists( 'strip_shortcodes' ) ? strip_shortcodes( $raw_content ) : $raw_content );
            $excerpt       = wp_trim_words( $stripped_body, 50, '...' );
        }

        // Clean plain text content clamped to 3000 chars for context.
        $content = wp_strip_all_tags( function_exists( 'strip_shortcodes' ) ? strip_shortcodes( $raw_content ) : $raw_content );
        $content = preg_replace( '/\s+/', ' ', $content );
        $content = mb_substr( trim( $content ), 0, 3000, 'UTF-8' );

        // Podcast script ingestion:
        // When content is empty or if post is a podcast (_presshub_briefing_type === 'podcast'),
        // look up dialogue script from postmeta (_presshub_podcast_script) or snapshot files.
        $briefing_type = function_exists( 'get_post_meta' ) ? (string) get_post_meta( $post_id, '_presshub_briefing_type', true ) : '';
        $is_podcast    = ( 'podcast' === $briefing_type );

        if ( '' === trim( $content ) || $is_podcast ) {
            // 1. Check canonical postmeta _presshub_podcast_script
            $podcast_script = function_exists( 'get_post_meta' ) ? (string) get_post_meta( $post_id, '_presshub_podcast_script', true ) : '';

            // 2. Check alternative common podcast script meta keys
            if ( '' === trim( $podcast_script ) && function_exists( 'get_post_meta' ) ) {
                $alt_keys = [ '_presshub_script', 'presshub_podcast_script', 'podcast_script' ];
                foreach ( $alt_keys as $alt_key ) {
                    $alt_val = (string) get_post_meta( $post_id, $alt_key, true );
                    if ( '' !== trim( $alt_val ) ) {
                        $podcast_script = $alt_val;
                        break;
                    }
                }
            }

            // 3. Fallback to Harvester/Producer snapshot files on disk
            if ( '' === trim( $podcast_script ) ) {
                $date    = function_exists( 'get_post_meta' ) ? (string) get_post_meta( $post_id, '_presshub_briefing_date', true ) : '';
                if ( '' === trim( $date ) && ! empty( $post->post_date ) ) {
                    $date = substr( (string) $post->post_date, 0, 10 );
                }
                $edition = function_exists( 'get_post_meta' ) ? (string) get_post_meta( $post_id, '_presshub_briefing_edition', true ) : 'morning';
                if ( '' === trim( $edition ) ) {
                    $edition = 'morning';
                }

                if ( ! empty( $date ) ) {
                    if ( class_exists( 'PressHub_AI_Podcast_Producer' ) ) {
                        $producer = new PressHub_AI_Podcast_Producer();
                        if ( method_exists( $producer, 'get_script' ) ) {
                            $file_script = $producer->get_script( $date, $edition );
                            if ( ! empty( $file_script ) ) {
                                $podcast_script = (string) $file_script;
                            }
                        }
                    }

                    $snapshot_dir = '';
                    if ( class_exists( 'PressHub_AI_News_Harvester' ) ) {
                        $harvester = new PressHub_AI_News_Harvester();
                        if ( method_exists( $harvester, 'get_snapshot_dir' ) ) {
                            $snapshot_dir = $harvester->get_snapshot_dir( $date );
                        }
                    }
                    if ( empty( $snapshot_dir ) && function_exists( 'wp_upload_dir' ) ) {
                        $uploads = wp_upload_dir();
                        if ( ! empty( $uploads['basedir'] ) ) {
                            $clean_date   = preg_replace( '/[^0-9\-]/', '', $date );
                            $snapshot_dir = rtrim( $uploads['basedir'], '/\\' ) . '/presshub-briefings/' . $clean_date;
                        }
                    }

                    if ( ! empty( $snapshot_dir ) ) {
                        $slash_dir = function_exists( 'trailingslashit' ) ? trailingslashit( $snapshot_dir ) : rtrim( $snapshot_dir, '/\\' ) . '/';
                        $filenames = ( 'noon' === $edition )
                            ? [ 'podcast-script-noon.txt', 'podcast-script.txt' ]
                            : [ 'podcast-script.txt', 'podcast-script-noon.txt' ];

                        foreach ( $filenames as $fn ) {
                            $fpath = $slash_dir . $fn;
                            if ( file_exists( $fpath ) ) {
                                $fcontent = @file_get_contents( $fpath );
                                if ( false !== $fcontent && '' !== trim( $fcontent ) ) {
                                    $podcast_script = $fcontent;
                                    break;
                                }
                            }
                        }
                    }
                }
            }

            // 4. Ingest and clean podcast script
            if ( '' !== trim( $podcast_script ) ) {
                $cleaned = self::clean_podcast_script( $podcast_script );
                if ( '' !== $cleaned ) {
                    if ( '' === trim( $content ) || $is_podcast ) {
                        $content = mb_substr( $cleaned, 0, 3000, 'UTF-8' );
                    }
                    if ( '' === trim( $excerpt ) || false !== strpos( $excerpt, 'TOPIC' ) || false !== strpos( $excerpt, 'SPEAKER_' ) ) {
                        $excerpt = function_exists( 'wp_trim_words' ) ? wp_trim_words( $cleaned, 50, '...' ) : mb_substr( $cleaned, 0, 250, 'UTF-8' );
                    }
                }
            }
        }

        $url       = function_exists( 'get_permalink' ) ? ( get_permalink( $post_id ) ?: '' ) : '';
        $site_name = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : 'PressHub';
        $hashtags  = self::build_hashtags_for_post( $post_id );

        return [
            'post_id'   => $post_id,
            'title'     => $title,
            'excerpt'   => trim( $excerpt ),
            'content'   => $content,
            'url'       => $url,
            'site_name' => $site_name,
            'hashtags'  => $hashtags,
        ];
    }

    /**
     * Generate formatted hashtags for a post from its tags and categories.
     *
     * @param int $post_id WordPress post ID.
     * @return string Space-separated hashtags (e.g. "#Tech #WordPress").
     */
    public static function build_hashtags_for_post( int $post_id ): string {
        $tags      = function_exists( 'wp_get_post_tags' ) ? wp_get_post_tags( $post_id ) : [];
        $tags_list = [];

        if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) {
            foreach ( $tags as $tag ) {
                $tag_name = preg_replace( '/[^\p{L}\p{N}]+/u', '', $tag->name );
                if ( '' !== $tag_name ) {
                    $tags_list[] = '#' . $tag_name;
                }
                if ( count( $tags_list ) >= 5 ) {
                    break;
                }
            }
        }

        if ( empty( $tags_list ) ) {
            $categories = function_exists( 'wp_get_post_categories' ) ? wp_get_post_categories( $post_id, [ 'fields' => 'all' ] ) : [];
            if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
                foreach ( $categories as $cat ) {
                    if ( 'uncategorized' === strtolower( $cat->slug ) ) {
                        continue;
                    }
                    $cat_name = preg_replace( '/[^\p{L}\p{N}]+/u', '', $cat->name );
                    if ( '' !== $cat_name ) {
                        $tags_list[] = '#' . $cat_name;
                    }
                    if ( count( $tags_list ) >= 3 ) {
                        break;
                    }
                }
            }
        }

        if ( empty( $tags_list ) ) {
            return '#News';
        }

        return implode( ' ', array_unique( $tags_list ) );
    }

    /**
     * Truncate text strictly to max_chars for Twitter/X while ensuring the article URL
     * is fully preserved without truncation.
     *
     * @param string $text Raw tweet text.
     * @param int    $max_chars Maximum allowed characters.
     * @param string $url Article URL to preserve.
     * @return string Truncated tweet text.
     */
    public static function truncate_twitter_text( string $text, int $max_chars, string $url = '' ): string {
        $text   = trim( $text );
        $length = mb_strlen( $text, 'UTF-8' );

        if ( $length <= $max_chars ) {
            return $text;
        }

        // If no URL is provided or URL is not found in text, simple ellipsis truncation.
        if ( '' === $url || false === mb_strpos( $text, $url, 0, 'UTF-8' ) ) {
            $clamp_target = max( 10, $max_chars - 3 );
            $truncated    = mb_substr( $text, 0, $clamp_target, 'UTF-8' );
            $last_space   = mb_strrpos( $truncated, ' ', 0, 'UTF-8' );
            if ( false !== $last_space && $last_space > 20 ) {
                $truncated = mb_substr( $truncated, 0, $last_space, 'UTF-8' );
            }
            return rtrim( $truncated ) . '...';
        }

        // URL is present in the text: isolate the URL and truncate prefix text.
        $url_len = mb_strlen( $url, 'UTF-8' );
        // Allow room for "... \n" and the URL.
        $available_prefix = $max_chars - $url_len - 5;

        if ( $available_prefix <= 20 ) {
            // URL takes up almost all characters; return URL alone.
            return $url;
        }

        $parts        = explode( $url, $text, 2 );
        $prefix       = trim( $parts[0] );
        $suffix       = isset( $parts[1] ) ? trim( $parts[1] ) : '';

        // If suffix has hashtags, see if suffix fits.
        $suffix_len = mb_strlen( $suffix, 'UTF-8' );
        if ( $suffix_len > 0 && ( $available_prefix - $suffix_len ) > 20 ) {
            $available_prefix -= ( $suffix_len + 1 );
            $truncated_prefix = mb_substr( $prefix, 0, $available_prefix, 'UTF-8' );
            $last_space       = mb_strrpos( $truncated_prefix, ' ', 0, 'UTF-8' );
            if ( false !== $last_space && $last_space > 15 ) {
                $truncated_prefix = mb_substr( $truncated_prefix, 0, $last_space, 'UTF-8' );
            }
            return rtrim( $truncated_prefix ) . '... ' . $url . ( '' !== $suffix ? ' ' . $suffix : '' );
        }

        $truncated_prefix = mb_substr( $prefix, 0, $available_prefix, 'UTF-8' );
        $last_space       = mb_strrpos( $truncated_prefix, ' ', 0, 'UTF-8' );
        if ( false !== $last_space && $last_space > 15 ) {
            $truncated_prefix = mb_substr( $truncated_prefix, 0, $last_space, 'UTF-8' );
        }

        return rtrim( $truncated_prefix ) . '... ' . $url;
    }
}
