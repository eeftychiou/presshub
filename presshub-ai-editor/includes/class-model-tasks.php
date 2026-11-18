<?php
/**
 * The AI models table (#236): one row per AI task, each reading and writing
 * the options that already exist for it.
 *
 * Adding an AI task means adding one row to rows(). The table on Settings →
 * AI models, the read-only "Model: …" line on each feature, the type check
 * on save and the search index all read the same rows.
 *
 * @package presshub-ai-editor
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-model-catalog.php';
require_once __DIR__ . '/class-settings-sections.php';

class PressHub_Model_Tasks {

    /** Each row's model option and the kind of model it needs (matches rows(); ModelsTableTest checks). */
    const MODEL_OPTIONS = [
        'presshub_ai_coauthor_model'                => 'text',
        'presshub_ai_qa_model'                      => 'text',
        'presshub_ai_copilot_model'                 => 'text',
        'presshub_ai_briefing_text_model'           => 'text',
        'presshub_ai_briefing_podcast_model'        => 'text',
        'presshub_ai_briefing_memory_model'         => 'text',
        'presshub_ai_image_prompt_model'            => 'text',
        'presshub_social_ai_model'                  => 'text',
        'presshub_ai_briefing_tts_model'            => 'speech',
        'presshub_social_tiktok_shorts_music_model' => 'music',
    ];

    /** Group anchor of the table on AI models. */
    const GROUP = 'task-models';

    /**
     * The rows, in table order.
     *
     *  provider    option holding the provider ID ('' = none)
     *  providers   which providers it offers: text | speech | image | none
     *  model       option holding the model ID ('' = the provider's default model)
     *  engine      option holding the speech engine (Speech only)
     *  params      option => [ label, min, max, step, placeholder ]
     *  module      the PressHub_AI_API_Client module it resolves through
     *  feature     [ section, anchor ] of the feature that shows the read-only line
     *  social      the row needs the Social capability
     *
     * @return array<string,array>
     */
    public static function rows(): array {
        $temp    = static function ( string $opt, string $placeholder = '' ): array {
            return [ $opt => [ 'label' => __( 'Temperature', 'presshub-ai-editor' ), 'min' => '0', 'max' => '2', 'step' => '0.05', 'placeholder' => $placeholder, 'help' => __( 'How freely the model words its output, from 0 (most predictable) to 2.', 'presshub-ai-editor' ) ] ];
        };
        $tokens  = static function ( string $opt, string $min = '64' ): array {
            return [ $opt => [ 'label' => __( 'Max tokens', 'presshub-ai-editor' ), 'min' => $min, 'max' => '65536', 'step' => '1', 'placeholder' => __( 'provider', 'presshub-ai-editor' ), 'help' => __( 'Longest answer the model may write (64 to 65,536). Leave empty to use the provider\'s limit.', 'presshub-ai-editor' ) ] ];
        };
        $timeout = static function ( string $opt, string $min, string $max, string $placeholder = '' ): array {
            /* translators: 1: minimum seconds, 2: maximum seconds */
            return [ $opt => [ 'label' => __( 'Timeout (s)', 'presshub-ai-editor' ), 'min' => $min, 'max' => $max, 'step' => '1', 'placeholder' => $placeholder, 'help' => sprintf( __( 'Seconds to wait for the model before giving up (%1$s to %2$s).', 'presshub-ai-editor' ), $min, $max ) ] ];
        };

        return [
            'coauthor'         => [
                'label'     => __( 'Co-author', 'presshub-ai-editor' ),
                'used_for'  => __( 'Drafts from sources in the editor', 'presshub-ai-editor' ),
                'kind'      => PressHub_Model_Catalog::TEXT,
                'provider'  => 'presshub_ai_coauthor_provider',
                'providers' => 'text',
                'model'     => 'presshub_ai_coauthor_model',
                'params'    => $temp( 'presshub_ai_coauthor_temperature', '0.7' ) + $tokens( 'presshub_ai_coauthor_max_tokens' ) + $timeout( 'presshub_ai_coauthor_timeout', '10', '300', '300' ),
                'module'    => 'coauthor',
                'feature'   => [ 'writing-qa', 'drafting' ],
            ],
            'qa'               => [
                'label'     => __( 'Editorial QA', 'presshub-ai-editor' ),
                'used_for'  => __( 'QA gate score and libel check', 'presshub-ai-editor' ),
                'kind'      => PressHub_Model_Catalog::TEXT,
                'provider'  => 'presshub_ai_qa_provider',
                'providers' => 'text',
                'model'     => 'presshub_ai_qa_model',
                'params'    => [],
                'params_note' => __( 'Uses the Co-author temperature, max tokens and timeout.', 'presshub-ai-editor' ),
                'module'    => 'qa',
                'same_as'   => 'coauthor',
                'feature'   => [ 'writing-qa', 'qa-gate' ],
            ],
            'copilot'          => [
                'label'     => __( 'Copilot', 'presshub-ai-editor' ),
                'used_for'  => __( 'Editor sidebar chat and research', 'presshub-ai-editor' ),
                'kind'      => PressHub_Model_Catalog::TEXT,
                'provider'  => 'presshub_ai_copilot_provider',
                'providers' => 'text',
                'model'     => 'presshub_ai_copilot_model',
                'params'    => $temp( 'presshub_ai_copilot_temperature', '0.7' ) + $tokens( 'presshub_ai_copilot_max_tokens' ) + $timeout( 'presshub_ai_copilot_timeout', '10', '300', '300' ),
                'module'    => 'copilot',
                'feature'   => [],
            ],
            'briefing_text'    => [
                'label'     => __( 'Briefing curator', 'presshub-ai-editor' ),
                'used_for'  => __( 'Daily Briefing text story', 'presshub-ai-editor' ),
                'kind'      => PressHub_Model_Catalog::TEXT,
                'provider'  => 'presshub_ai_briefing_text_provider',
                'providers' => 'text',
                'model'     => 'presshub_ai_briefing_text_model',
                'params'    => $temp( 'presshub_ai_briefing_text_temperature', __( 'provider', 'presshub-ai-editor' ) ) + $tokens( 'presshub_ai_briefing_text_max_tokens' ) + $timeout( 'presshub_ai_briefing_text_timeout', '10', '600', __( 'provider', 'presshub-ai-editor' ) ),
                'module'    => 'briefing_text',
                'feature'   => [ 'daily-briefing', 'briefing-text' ],
            ],
            'briefing_podcast' => [
                'label'     => __( 'Podcast script', 'presshub-ai-editor' ),
                'used_for'  => __( 'Daily Briefing podcast dialogue', 'presshub-ai-editor' ),
                'kind'      => PressHub_Model_Catalog::TEXT,
                'provider'  => 'presshub_ai_briefing_podcast_provider',
                'providers' => 'text',
                'model'     => 'presshub_ai_briefing_podcast_model',
                'params'    => $temp( 'presshub_ai_briefing_podcast_temperature', __( 'provider', 'presshub-ai-editor' ) ) + $tokens( 'presshub_ai_briefing_podcast_max_tokens' ) + $timeout( 'presshub_ai_briefing_podcast_timeout', '10', '600', __( 'provider', 'presshub-ai-editor' ) ),
                'module'    => 'briefing_podcast',
                'feature'   => [ 'daily-briefing', 'briefing-podcast' ],
            ],
            'briefing_memory'  => [
                'label'     => __( 'Memory', 'presshub-ai-editor' ),
                'used_for'  => __( 'Memory digests and rollups, people fiches, policy fiche drafts', 'presshub-ai-editor' ),
                'kind'      => PressHub_Model_Catalog::TEXT,
                'provider'  => 'presshub_ai_briefing_memory_provider',
                'providers' => 'text',
                'model'     => 'presshub_ai_briefing_memory_model',
                'params'    => [],
                'module'    => 'briefing_memory',
                'feature'   => [ 'editorial-knowledge', 'knowledge-memory' ],
            ],
            'image_prompt'     => [
                'label'     => __( 'Image prompt', 'presshub-ai-editor' ),
                'used_for'  => __( 'Reads the article and writes the image prompt', 'presshub-ai-editor' ),
                'kind'      => PressHub_Model_Catalog::TEXT,
                'provider'  => 'presshub_ai_image_prompt_provider',
                'providers' => 'text',
                'model'     => 'presshub_ai_image_prompt_model',
                'params'    => [],
                'module'    => 'image_prompt',
                'feature'   => [ 'writing-qa', 'featured-images' ],
            ],
            'image_generation' => [
                'label'     => __( 'Image generation', 'presshub-ai-editor' ),
                'used_for'  => __( 'Featured, Shorts and post images', 'presshub-ai-editor' ),
                'kind'      => PressHub_Model_Catalog::IMAGE,
                'provider'  => 'presshub_ai_image_generation_provider',
                'providers' => 'image',
                'model'     => '',
                'params'    => [],
                'module'    => 'image_generation',
                'feature'   => [ 'writing-qa', 'featured-images' ],
            ],
            'social_copy'      => [
                'label'     => __( 'Social copy', 'presshub-ai-editor' ),
                'used_for'  => __( 'Social post captions', 'presshub-ai-editor' ),
                'kind'      => PressHub_Model_Catalog::TEXT,
                'provider'  => 'presshub_social_ai_provider',
                'providers' => 'text',
                'model'     => 'presshub_social_ai_model',
                'params'    => [
                    'presshub_social_ai_temperature' => [ 'label' => __( 'Temperature', 'presshub-ai-editor' ), 'min' => '0', 'max' => '2', 'step' => '0.01', 'placeholder' => '0.7', 'help' => __( 'How freely the model words its output, from 0 (most predictable) to 2. Leave empty to use the provider\'s temperature.', 'presshub-ai-editor' ) ],
                ] + $tokens( 'presshub_social_ai_max_tokens', '0' ) + $timeout( 'presshub_social_ai_timeout', '0', '600', __( 'provider', 'presshub-ai-editor' ) ),
                'module'    => 'social',
                'feature'   => [ 'social-publishing', 'post-copy' ],
                'social'    => true,
            ],
            'speech'           => [
                'label'     => __( 'Speech', 'presshub-ai-editor' ),
                'used_for'  => __( 'Podcast audio and Shorts narration', 'presshub-ai-editor' ),
                'kind'      => PressHub_Model_Catalog::SPEECH,
                'provider'  => 'presshub_ai_briefing_podcast_tts_provider',
                'providers' => 'speech',
                'engine'    => 'presshub_ai_briefing_tts_engine',
                'model'     => 'presshub_ai_briefing_tts_model',
                'params'    => $timeout( 'presshub_ai_briefing_tts_timeout', '60', '900', '300' ),
                'module'    => 'tts',
                'feature'   => [ 'daily-briefing', 'briefing-voices' ],
            ],
            'music'            => [
                'label'     => __( 'Music', 'presshub-ai-editor' ),
                'used_for'  => __( 'Shorts background music', 'presshub-ai-editor' ),
                'kind'      => PressHub_Model_Catalog::MUSIC,
                'provider'  => '',
                'providers' => 'none',
                'model'     => 'presshub_social_tiktok_shorts_music_model',
                'params'    => [],
                'module'    => 'music',
                'feature'   => [ 'social-publishing', 'shorts' ],
                'social'    => true,
            ],
        ];
    }

    /** Rows the current user can see. */
    public static function visible_rows(): array {
        $social = PressHub_Settings_Sections::can_social();
        return array_filter( self::rows(), static function ( $row ) use ( $social ) {
            return empty( $row['social'] ) || $social;
        } );
    }

    /** Every option a row reads or writes, in row order. @return string[] */
    public static function options( ?array $row = null ): array {
        $rows = null === $row ? self::rows() : [ $row ];
        $out  = [];
        foreach ( $rows as $r ) {
            foreach ( [ 'provider', 'engine', 'model' ] as $k ) {
                if ( ! empty( $r[ $k ] ) ) {
                    $out[] = $r[ $k ];
                }
            }
            foreach ( array_keys( (array) ( $r['params'] ?? [] ) ) as $p ) {
                $out[] = $p;
            }
        }
        return $out;
    }

    /** Anchor of a row on AI models. */
    public static function anchor( string $id ): string {
        return 'model-' . str_replace( '_', '-', $id );
    }

    public static function row_url( string $id ): string {
        return PressHub_Settings_Sections::url( 'ai-models', self::anchor( $id ) );
    }

    // ------------------------------------------------------------------
    // Providers and what a row resolves to.
    // ------------------------------------------------------------------

    /** Enabled providers that can be called (a key, or a local Ollama). */
    public static function usable_providers(): array {
        if ( ! class_exists( 'PressHub_AI_Provider_Store' ) ) {
            return [];
        }
        return array_values( array_filter( PressHub_AI_Provider_Store::get_all( true ), static function ( $p ) {
            return ! empty( $p['enabled'] ) && ( ! empty( $p['api_key'] ) || 'ollama_local' === ( $p['type'] ?? '' ) );
        } ) );
    }

    /** Providers a row offers. */
    public static function providers_for( array $row ): array {
        $all = self::usable_providers();
        if ( 'speech' === $row['providers'] ) {
            return array_values( array_filter( $all, static function ( $p ) {
                return 'gemini' === ( $p['type'] ?? '' );
            } ) );
        }
        if ( 'image' === $row['providers'] ) {
            // run_featured_image_generation() draws with Gemini or OpenAI only.
            return array_values( array_filter( $all, static function ( $p ) {
                return in_array( $p['type'] ?? '', [ 'gemini', 'openai' ], true );
            } ) );
        }
        return 'none' === $row['providers'] ? [] : $all;
    }

    /** A provider record by ID (or by type, as resolve_module_config() matches it). */
    public static function provider_record( string $id ): ?array {
        $id = trim( $id );
        if ( '' === $id || ! class_exists( 'PressHub_AI_Provider_Store' ) ) {
            return null;
        }
        $rec = PressHub_AI_Provider_Store::get( $id );
        if ( $rec ) {
            return $rec;
        }
        foreach ( PressHub_AI_Provider_Store::get_all( false ) as $p ) {
            if ( ( $p['type'] ?? '' ) === $id ) {
                return $p;
            }
        }
        return null;
    }

    /** The default model: the first enabled provider and its default model. */
    public static function default_model(): array {
        $enabled = class_exists( 'PressHub_AI_Provider_Store' ) ? PressHub_AI_Provider_Store::get_all( true ) : [];
        if ( ! $enabled ) {
            return [ 'provider' => '', 'model' => '' ];
        }
        $p     = $enabled[0];
        $model = (string) ( $p['default_model'] ?? '' );
        if ( '' === $model && class_exists( 'PressHub_AI_Provider_Defaults' ) ) {
            $model = PressHub_AI_Provider_Defaults::default_model( (string) ( $p['type'] ?? '' ) );
        }
        return [ 'provider' => (string) ( $p['name'] ?? ( $p['id'] ?? '' ) ), 'model' => PressHub_Model_Catalog::normalize( $model ) ];
    }

    /**
     * Provider name and model a row uses now.
     *
     * @return array{provider:string,model:string}
     */
    public static function resolve( string $id ): array {
        $rows = self::rows();
        $row  = $rows[ $id ] ?? null;
        if ( ! $row ) {
            return [ 'provider' => '', 'model' => '' ];
        }
        try {
            if ( 'music' === $row['module'] ) {
                $model = class_exists( 'PressHub_Social_Settings_Storage' ) ? PressHub_Social_Settings_Storage::get_tiktok_shorts_music_model() : (string) get_option( $row['model'], 'lyria-3-clip-preview' );
                return [ 'provider' => __( 'Gemini', 'presshub-ai-editor' ), 'model' => $model ];
            }
            if ( 'social' === $row['module'] ) {
                if ( ! class_exists( 'PressHub_Social_AI_Copywriter' ) || ! class_exists( 'PressHub_Social_Settings_Storage' ) ) {
                    return [ 'provider' => '', 'model' => (string) get_option( $row['model'], '' ) ];
                }
                $cfg = PressHub_Social_AI_Copywriter::resolve_ai_provider_config( true );
                if ( is_wp_error( $cfg ) ) {
                    return [ 'provider' => '', 'model' => '' ];
                }
                return [ 'provider' => (string) ( $cfg['name'] ?? '' ), 'model' => (string) ( $cfg['model'] ?? '' ), 'id' => (string) ( $cfg['id'] ?? '' ) ];
            }
            if ( ! class_exists( 'PressHub_AI_API_Client' ) ) {
                return [ 'provider' => '', 'model' => '' ];
            }
            $cfg   = PressHub_AI_API_Client::resolve_module_config( $row['module'], true );
            $model = (string) ( $cfg['model'] ?? '' );
            if ( 'tts' === $row['module'] && '' === $model && class_exists( 'PressHub_AI_Settings_Storage' ) ) {
                $model = PressHub_AI_Settings_Storage::default_briefing_tts_model();
            }
            return [ 'provider' => (string) ( $cfg['name'] ?? '' ), 'model' => $model, 'id' => (string) ( $cfg['id'] ?? '' ) ];
        } catch ( Throwable $e ) {
            return [ 'provider' => '', 'model' => '' ];
        }
    }

    // ------------------------------------------------------------------
    // Type check on save.
    // ------------------------------------------------------------------

    /**
     * Check the model rows in a settings post.
     *
     * Only a value that changes is checked, so a stored value that is now
     * known to be wrong is shown on its row but never stops other rows from
     * saving. A provider chosen with no model of its own is checked by the
     * provider's default model.
     *
     * @return array{errors:array<string,string>,warnings:array<string,string>}
     */
    public static function validate( array $post ): array {
        $errors   = [];
        $warnings = [];
        foreach ( self::rows() as $row ) {
            $p_opt = (string) $row['provider'];
            $m_opt = (string) $row['model'];
            $p_in  = '' !== $p_opt && array_key_exists( $p_opt, $post );
            $m_in  = '' !== $m_opt && array_key_exists( $m_opt, $post );
            if ( ! $p_in && ! $m_in ) {
                continue;
            }
            $p_old = '' !== $p_opt ? trim( (string) get_option( $p_opt, '' ) ) : '';
            $m_old = '' !== $m_opt ? PressHub_Model_Catalog::normalize( (string) get_option( $m_opt, '' ) ) : '';
            if ( ( $p_in && ! is_scalar( $post[ $p_opt ] ) ) || ( $m_in && ! is_scalar( $post[ $m_opt ] ) ) ) {
                $errors[ $m_in && ! is_scalar( $post[ $m_opt ] ) ? $m_opt : $p_opt ] = __( 'Enter a single model ID.', 'presshub-ai-editor' );
                continue;
            }
            $p_new = $p_in ? trim( (string) self::unslash( $post[ $p_opt ] ) ) : $p_old;
            $m_new = $m_in ? PressHub_Model_Catalog::normalize( (string) self::unslash( $post[ $m_opt ] ) ) : $m_old;
            if ( $p_new === $p_old && $m_new === $m_old ) {
                continue;
            }
            $record = self::provider_record( $p_new );

            if ( '' !== $m_new ) {
                $check = PressHub_Model_Catalog::check( $row['kind'], $m_new, $record );
                if ( 'blocked' === $check['status'] ) {
                    $errors[ $m_opt ] = $check['message'];
                } elseif ( 'warning' === $check['status'] && $m_new !== $m_old ) {
                    $warnings[ $m_opt ] = $row['label'] . ': ' . $check['message'];
                }
                continue;
            }
            // No model of its own: a newly chosen provider runs its default model.
            if ( $record && $p_new !== $p_old && 'speech' !== $row['providers'] ) {
                $default = PressHub_Model_Catalog::normalize( (string) ( $record['default_model'] ?? '' ) );
                $kind    = '' !== $default ? PressHub_Model_Catalog::kind_of( $default ) : null;
                if ( null !== $kind && $kind !== $row['kind'] ) {
                    $errors[ $p_opt ] = sprintf(
                        /* translators: 1: provider name, 2: model ID, 3: its kind (e.g. "a speech model"), 4: the kind the task needs */
                        __( '%1$s runs %2$s, which is %3$s. Pick %4$s for it, or another provider.', 'presshub-ai-editor' ),
                        (string) ( $record['name'] ?? $p_new ),
                        $default,
                        PressHub_Model_Catalog::kind_phrase( $kind ),
                        PressHub_Model_Catalog::kind_phrase( $row['kind'] )
                    );
                }
            }
        }
        return [ 'errors' => $errors, 'warnings' => $warnings ];
    }

    /**
     * Refuse a known wrong-type model on every save path (options.php, the
     * settings transfer, update_option() from code): the stored value stays.
     */
    public static function register_hooks(): void {
        if ( ! function_exists( 'add_filter' ) ) {
            return;
        }
        // Runs at plugin load: option names and kinds only, nothing translated
        // (labels before "init" load the text domain too early).
        foreach ( self::MODEL_OPTIONS as $option => $kind ) {
            add_filter( 'pre_update_option_' . $option, static function ( $value, $old_value ) use ( $kind ) {
                $model = is_scalar( $value ) ? PressHub_Model_Catalog::normalize( (string) $value ) : '';
                if ( '' === $model ) {
                    return $value;
                }
                $found = PressHub_Model_Catalog::kind_of( $model );
                return ( null !== $found && $found !== $kind ) ? $old_value : $value;
            }, 10, 2 );
        }
    }

    private static function unslash( $v ) {
        return function_exists( 'wp_unslash' ) ? wp_unslash( $v ) : $v;
    }

    // ------------------------------------------------------------------
    // Rendering.
    // ------------------------------------------------------------------

    /** The read-only "Model: …" line a feature shows, linking to its row. */
    public static function feature_line( string $id ): string {
        $rows = self::rows();
        if ( ! isset( $rows[ $id ] ) ) {
            return '';
        }
        $row = $rows[ $id ];
        $r   = self::resolve( $id );
        $use = trim( $r['provider'] . ( '' !== $r['provider'] && '' !== $r['model'] ? ' · ' : '' ) . $r['model'] );
        if ( '' === $use ) {
            $use = __( 'not set', 'presshub-ai-editor' );
        }
        $link = PressHub_Settings_Sections::can_view( 'ai-models' )
            ? ' <a href="' . esc_url( self::row_url( $id ) ) . '">' . esc_html__( 'Change in AI models', 'presshub-ai-editor' ) . '</a>'
            : '';
        return '<p class="presshub-model-line" data-model-task="' . esc_attr( $id ) . '">'
            /* translators: 1: task name (e.g. "Co-author"), 2: provider and model */
            . esc_html( sprintf( __( '%1$s model: %2$s.', 'presshub-ai-editor' ), $row['label'], $use ) )
            . $link . '</p>';
    }

    /** The AI models table. */
    public static function render_table(): string {
        $rows = self::visible_rows();
        $def  = self::default_model();

        $out  = '<p class="presshub-model-default">';
        if ( '' !== $def['provider'] ) {
            /* translators: 1: provider name, 2: model ID */
            $out .= esc_html( sprintf( __( 'Default model: %1$s · %2$s.', 'presshub-ai-editor' ), $def['provider'], $def['model'] ) ) . ' '
                . esc_html__( 'Tasks set to Default use it. It is the first enabled provider and its default model.', 'presshub-ai-editor' ) . ' ';
        } else {
            $out .= esc_html__( 'No AI provider is enabled yet, so no task can run.', 'presshub-ai-editor' ) . ' ';
        }
        $out .= '<a href="' . esc_url( PressHub_Settings_Sections::url( 'connections', 'ai-providers' ) ) . '">' . esc_html__( 'Change it in Connections → AI providers', 'presshub-ai-editor' ) . '</a></p>';

        $out .= '<table class="widefat striped presshub-models-table">';
        $out .= '<caption class="screen-reader-text">' . esc_html__( 'AI models by task', 'presshub-ai-editor' ) . '</caption>';
        $out .= '<thead><tr><th scope="col">' . esc_html__( 'Task', 'presshub-ai-editor' ) . '</th><th scope="col">' . esc_html__( 'Provider', 'presshub-ai-editor' ) . '</th><th scope="col">' . esc_html__( 'Model', 'presshub-ai-editor' ) . '</th></tr></thead><tbody>';
        foreach ( $rows as $id => $row ) {
            $out .= self::render_row( (string) $id, $row );
        }
        $out .= '</tbody></table>';
        return $out;
    }

    private static function render_row( string $id, array $row ): string {
        $anchor    = self::anchor( $id );
        $label_id  = $anchor . '-label';
        $providers = self::providers_for( $row );
        $resolved  = self::resolve( $id );
        $p_opt     = (string) $row['provider'];
        $m_opt     = (string) $row['model'];
        $p_cur     = '' !== $p_opt ? trim( (string) get_option( $p_opt, '' ) ) : '';
        $m_cur     = '' !== $m_opt ? PressHub_Model_Catalog::normalize( 'music' === $row['module'] ? $resolved['model'] : (string) get_option( $m_opt, '' ) ) : '';
        $same_as   = ! empty( $row['same_as'] );

        $out  = '<tr id="' . esc_attr( $anchor ) . '" class="presshub-model-row" data-kind="' . esc_attr( $row['kind'] ) . '">';
        $out .= '<th scope="row"><span id="' . esc_attr( $label_id ) . '" class="presshub-model-task">' . esc_html( $row['label'] ) . '</span>'
            . '<span class="description presshub-model-used-for">' . esc_html( $row['used_for'] ) . '</span></th>';

        // Provider.
        $out .= '<td class="presshub-model-provider">';
        if ( '' === $p_opt ) {
            $out .= '<span>' . esc_html__( 'Gemini', 'presshub-ai-editor' ) . '</span><span class="description">' . esc_html__( 'Uses the Gemini key in Connections.', 'presshub-ai-editor' ) . '</span>';
        } else {
            $default_label = $same_as ? __( 'Same as Co-author', 'presshub-ai-editor' ) : __( 'Default', 'presshub-ai-editor' );
            if ( 'speech' === $row['providers'] ) {
                $default_label = __( 'Default Gemini provider', 'presshub-ai-editor' );
            } elseif ( 'image_generation' === $id ) {
                $default_label = __( 'Default (Gemini or OpenAI)', 'presshub-ai-editor' );
            }
            $out .= '<label class="screen-reader-text" for="' . esc_attr( $p_opt ) . '">'
                /* translators: %s: task name */
                . esc_html( sprintf( __( '%s provider', 'presshub-ai-editor' ), $row['label'] ) ) . '</label>';
            $out .= '<select name="' . esc_attr( $p_opt ) . '" id="' . esc_attr( $p_opt ) . '" class="presshub-model-provider-select">';
            $out .= '<option value=""' . ( '' === $p_cur ? ' selected="selected"' : '' ) . '>' . esc_html( $default_label ) . '</option>';
            $listed = false;
            foreach ( $providers as $p ) {
                $pid    = (string) ( $p['id'] ?? ( $p['type'] ?? '' ) );
                $listed = $listed || $pid === $p_cur;
                $out   .= '<option value="' . esc_attr( $pid ) . '"' . ( $pid === $p_cur ? ' selected="selected"' : '' ) . '>' . esc_html( (string) ( $p['name'] ?? $pid ) ) . '</option>';
            }
            if ( '' !== $p_cur && ! $listed ) {
                // A saved provider that is now off or has no key stays selected, marked.
                $out .= '<option value="' . esc_attr( $p_cur ) . '" selected="selected">' . esc_html( sprintf( /* translators: %s: provider ID */ __( '%s (off or no key)', 'presshub-ai-editor' ), $p_cur ) ) . '</option>';
            }
            $out .= '</select>';
        }
        $out .= '</td>';

        // Model.
        $out .= '<td class="presshub-model-model">';
        if ( ! empty( $row['engine'] ) ) {
            $engine = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_tts_engine() : (string) get_option( $row['engine'], 'gemini' );
            $out   .= '<label for="' . esc_attr( $row['engine'] ) . '">' . esc_html__( 'Engine', 'presshub-ai-editor' ) . '</label> '
                . '<select name="' . esc_attr( $row['engine'] ) . '" id="' . esc_attr( $row['engine'] ) . '" aria-describedby="' . esc_attr( $row['engine'] ) . '-desc">'
                . '<option value="gemini"' . ( 'gemini' === $engine ? ' selected="selected"' : '' ) . '>' . esc_html__( 'Gemini speech (Google AI Studio)', 'presshub-ai-editor' ) . '</option>'
                . '<option value="google_cloud"' . ( 'google_cloud' === $engine ? ' selected="selected"' : '' ) . '>' . esc_html__( 'Google Cloud Text-to-Speech', 'presshub-ai-editor' ) . '</option>'
                . '</select>'
                . '<span class="description" id="' . esc_attr( $row['engine'] ) . '-desc">' . esc_html__( 'It also decides which voices the podcast and the Shorts narrator can use (Daily Briefing → Voices & audio).', 'presshub-ai-editor' ) . '</span>';
        }
        if ( '' === $m_opt ) {
            $out .= '<span class="code">' . esc_html( '' !== $resolved['model'] ? $resolved['model'] : __( 'not set', 'presshub-ai-editor' ) ) . '</span>'
                . '<span class="description">' . esc_html__( 'The chosen provider\'s default model. Set it on the provider in Connections.', 'presshub-ai-editor' ) . '</span>';
            $kind = '' !== $resolved['model'] ? PressHub_Model_Catalog::kind_of( $resolved['model'] ) : null;
            if ( null !== $kind && $kind !== $row['kind'] ) {
                $out .= self::note( $anchor . '-model-error', sprintf(
                    /* translators: 1: model ID, 2: its kind (e.g. "a text model"), 3: the kind the task needs */
                    __( '%1$s is %2$s. Pick a provider whose default model is %3$s.', 'presshub-ai-editor' ),
                    $resolved['model'],
                    PressHub_Model_Catalog::kind_phrase( $kind ),
                    PressHub_Model_Catalog::kind_phrase( $row['kind'] )
                ), 'error' );
            }
        } else {
            $out .= self::model_picker( $id, $row, $providers, $p_cur, $m_cur, (string) ( $resolved['id'] ?? '' ) );
        }
        $use = trim( $resolved['provider'] . ( '' !== $resolved['provider'] && '' !== $resolved['model'] ? ' · ' : '' ) . $resolved['model'] );
        if ( '' !== $use ) {
            /* translators: %s: provider and model the task uses now */
            $out .= '<span class="description presshub-model-uses">' . esc_html( sprintf( __( 'Uses now: %s', 'presshub-ai-editor' ), $use ) ) . '</span>';
        }

        // Parameters: one expander per row.
        if ( ! empty( $row['params'] ) ) {
            $out .= '<details class="presshub-model-params"><summary>' . esc_html__( 'Temperature, max tokens and timeout', 'presshub-ai-editor' ) . '</summary><div class="presshub-model-params-fields">';
            foreach ( $row['params'] as $opt => $p ) {
                $val  = self::param_value( (string) $opt );
                $out .= '<p><label for="' . esc_attr( $opt ) . '">' . esc_html( $p['label'] ) . '</label> '
                    . '<input type="number" name="' . esc_attr( $opt ) . '" id="' . esc_attr( $opt ) . '" value="' . esc_attr( $val ) . '" min="' . esc_attr( $p['min'] ) . '" max="' . esc_attr( $p['max'] ) . '" step="' . esc_attr( $p['step'] ) . '" class="small-text"'
                    . ( '' !== $p['placeholder'] ? ' placeholder="' . esc_attr( $p['placeholder'] ) . '"' : '' )
                    . ' aria-describedby="' . esc_attr( $opt ) . '-desc" />'
                    . ' <span class="description" id="' . esc_attr( $opt ) . '-desc">' . esc_html( $p['help'] ) . '</span></p>';
            }
            $out .= '</div></details>';
        } elseif ( ! empty( $row['params_note'] ) ) {
            $out .= '<span class="description">' . esc_html( $row['params_note'] ) . '</span>';
        }
        $out .= '</td></tr>';
        return $out;
    }

    /** The value a parameter field shows: what is stored, empty when it defers to the provider. */
    private static function param_value( string $opt ): string {
        $raw = get_option( $opt, '' );
        if ( ! is_scalar( $raw ) ) {
            return '';
        }
        $raw = (string) $raw;
        if ( false !== strpos( $opt, '_max_tokens' ) ) {
            return (int) $raw >= 64 ? (string) min( 65536, (int) $raw ) : '';
        }
        if ( 'presshub_social_ai_timeout' === $opt ) {
            return (int) $raw >= 10 ? (string) (int) $raw : '';
        }
        if ( 'presshub_ai_briefing_tts_timeout' === $opt && class_exists( 'PressHub_AI_Settings_Storage' ) ) {
            return (string) PressHub_AI_Settings_Storage::get_briefing_tts_timeout();
        }
        return $raw;
    }

    /**
     * The model choice: a list of the row's models per provider plus "Other
     * model ID…", kept in step with a text field that holds the stored value.
     * Without JavaScript only the text field is shown.
     */
    private static function model_picker( string $id, array $row, array $providers, string $p_cur, string $m_cur, string $default_provider ): string {
        $m_opt     = (string) $row['model'];
        $kind      = $row['kind'];
        $same_as   = ! empty( $row['same_as'] );
        $choice_id = $m_opt . '-choice';
        $listed    = false;

        $groups = [];
        if ( 'music' === $row['module'] ) {
            $models = [];
            foreach ( PressHub_Model_Catalog::MODELS['gemini'] as $m => $k ) {
                if ( $k === $kind ) {
                    $models[] = $m;
                }
            }
            $groups[] = [ 'id' => '', 'label' => __( 'Gemini', 'presshub-ai-editor' ), 'models' => $models ];
        } else {
            foreach ( $providers as $p ) {
                $groups[] = [
                    'id'     => (string) ( $p['id'] ?? ( $p['type'] ?? '' ) ),
                    'label'  => (string) ( $p['name'] ?? ( $p['id'] ?? '' ) ),
                    'models' => PressHub_Model_Catalog::models_for( $p, $kind ),
                ];
            }
        }

        $empty_label = 'music' === $row['module'] ? '' : ( $same_as && '' === $p_cur ? __( 'Same as Co-author', 'presshub-ai-editor' ) : __( 'Provider default', 'presshub-ai-editor' ) );

        /* translators: %s: task name */
        $sr    = sprintf( __( '%s model', 'presshub-ai-editor' ), $row['label'] );
        $html  = '<label class="screen-reader-text" for="' . esc_attr( $choice_id ) . '">' . esc_html( $sr ) . '</label>';
        $html .= '<select id="' . esc_attr( $choice_id ) . '" class="presshub-model-choice" data-model-input="' . esc_attr( $m_opt ) . '"'
            . ( '' !== (string) $row['provider'] ? ' data-provider-select="' . esc_attr( $row['provider'] ) . '" data-default-provider="' . esc_attr( $default_provider ) . '"' : '' ) . ' hidden>';
        if ( '' !== $empty_label ) {
            $html .= '<option value=""' . ( '' === $m_cur ? ' selected="selected"' : '' ) . '>' . esc_html( $empty_label ) . '</option>';
        }
        // The stored model is selected only in the group of the provider the
        // row runs on, so the browser's provider filter never hides it.
        $active = 'music' === $row['module'] ? '' : ( '' !== $p_cur ? $p_cur : $default_provider );
        foreach ( $groups as $g ) {
            if ( ! $g['models'] ) {
                continue;
            }
            $html .= '<optgroup label="' . esc_attr( $g['label'] ) . '" data-provider="' . esc_attr( $g['id'] ) . '">';
            foreach ( $g['models'] as $m ) {
                $sel    = ! $listed && $m === $m_cur && $g['id'] === $active;
                $listed = $listed || $sel;
                $html  .= '<option value="' . esc_attr( $m ) . '"' . ( $sel ? ' selected="selected"' : '' ) . '>' . esc_html( $m ) . '</option>';
            }
            $html .= '</optgroup>';
        }
        $other = '' !== $m_cur && ! $listed;
        $html .= '<option value="__other"' . ( $other ? ' selected="selected"' : '' ) . '>' . esc_html__( 'Other model ID…', 'presshub-ai-editor' ) . '</option>';
        $html .= '</select>';

        $describe = $m_opt . '-desc';
        $html .= '<span class="presshub-model-other">';
        /* translators: %s: task name */
        $html .= '<label for="' . esc_attr( $m_opt ) . '">' . esc_html( sprintf( __( '%s model ID', 'presshub-ai-editor' ), $row['label'] ) ) . '</label> ';
        $html .= '<input type="text" name="' . esc_attr( $m_opt ) . '" id="' . esc_attr( $m_opt ) . '" value="' . esc_attr( $m_cur ) . '" class="regular-text code" spellcheck="false" autocomplete="off"'
            . ( '' !== $empty_label ? ' placeholder="' . esc_attr( $empty_label ) . '"' : '' );
        $note  = '';
        if ( '' !== $m_cur ) {
            $record = self::provider_record( $p_cur );
            $check  = PressHub_Model_Catalog::check( $kind, $m_cur, $record );
            if ( 'blocked' === $check['status'] ) {
                $note = self::note( $m_opt . '-model-error', $check['message'], 'error' );
            } elseif ( 'warning' === $check['status'] ) {
                $note = self::note( $m_opt . '-model-error', $check['message'], 'warning' );
            }
        }
        $html .= ' aria-describedby="' . esc_attr( $describe . ( '' !== $note ? ' ' . $m_opt . '-model-error' : '' ) ) . '"'
            . ( '' !== $note && false !== strpos( $note, 'is-error' ) ? ' aria-invalid="true" data-stored-invalid="1"' : '' ) . ' />';
        /* translators: %s: kind of model, e.g. "a text model" */
        $html .= ' <span class="description" id="' . esc_attr( $describe ) . '">' . esc_html( sprintf( __( 'Needs %s.', 'presshub-ai-editor' ), PressHub_Model_Catalog::kind_phrase( $kind ) ) )
            . ( '' !== $empty_label ? ' ' . esc_html__( 'Leave empty for the default.', 'presshub-ai-editor' ) : '' ) . '</span>';
        $html .= '</span>';
        return $html . $note;
    }

    private static function note( string $id, string $message, string $level ): string {
        $prefix = 'error' === $level ? __( 'Error:', 'presshub-ai-editor' ) : __( 'Warning:', 'presshub-ai-editor' );
        return '<p class="presshub-model-note is-' . esc_attr( $level ) . '" id="' . esc_attr( $id ) . '">' . esc_html( $prefix . ' ' . $message ) . '</p>';
    }
}
