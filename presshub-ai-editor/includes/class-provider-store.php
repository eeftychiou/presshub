<?php
/**
 * Storage and management for configured AI providers.
 *
 * Backs the dynamic AI Provider Registry ('presshub_ai_configured_providers')
 * supporting standard providers (OpenAI, Anthropic, Gemini, Google Cloud TTS,
 * Groq, Mistral, DeepSeek, Ollama/Local) and Custom OpenAI-compatible endpoints.
 *
 * Also provides automated migration from legacy single-provider options so
 * existing setups continue working seamlessly.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-provider-defaults.php';

class PressHub_AI_Provider_Store {

    const OPTION_CONFIGURED_PROVIDERS = 'presshub_ai_configured_providers';
    const OPTION_MIGRATED             = 'presshub_ai_providers_migrated';

    /**
     * Retrieve all configured providers.
     *
     * @param bool $enabled_only If true, returns only enabled providers.
     * @return array List of provider records.
     */
    public static function get_all( bool $enabled_only = false ): array {
        $raw = get_option( self::OPTION_CONFIGURED_PROVIDERS, null );

        if ( null === $raw || false === $raw ) {
            self::migrate_legacy_options();
            $raw = get_option( self::OPTION_CONFIGURED_PROVIDERS, [] );
        }

        if ( ! is_array( $raw ) ) {
            return [];
        }

        $list = [];
        foreach ( $raw as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            if ( ( $item['type'] ?? '' ) === 'google_cloud_tts' ) {
                continue;
            }
            $clean = self::sanitize_provider( $item );
            if ( null === $clean ) {
                continue;
            }
            if ( $enabled_only && empty( $clean['enabled'] ) ) {
                continue;
            }
            $list[] = $clean;
        }

        return $list;
    }

    /**
     * Retrieve a specific provider by its unique ID.
     *
     * @param string $provider_id Provider slug/ID.
     * @return array|null Provider record or null if not found.
     */
    public static function get( string $provider_id ): ?array {
        $providers = self::get_all( false );
        foreach ( $providers as $provider ) {
            if ( ( $provider['id'] ?? '' ) === $provider_id ) {
                return $provider;
            }
        }
        return null;
    }

    /**
     * Save (create or update) a provider record.
     *
     * @param array $data Provider configuration data.
     * @return string The provider ID.
     */
    public static function save_provider( array $data ): string {
        $providers = self::get_all( false );

        // Find existing record if updating.
        $existing_index = null;
        $existing_record = null;
        $raw_id = isset( $data['id'] ) ? trim( (string) $data['id'] ) : '';

        if ( '' !== $raw_id ) {
            $clean_id = self::sanitize_id( $raw_id );
            foreach ( $providers as $index => $provider ) {
                if ( $provider['id'] === $clean_id ) {
                    $existing_index  = $index;
                    $existing_record = $provider;
                    break;
                }
            }
        }

        // Clean & sanitize provider data.
        $clean = self::sanitize_provider( $data, $existing_record );
        if ( null === $clean ) {
            // In fallback cases, generate minimal valid record.
            $type = isset( $data['type'] ) ? sanitize_key( (string) $data['type'] ) : 'custom_openai';
            $clean = self::get_default_provider_record( $type );
        }

        // If no ID assigned or generating a new ID.
        if ( empty( $clean['id'] ) ) {
            $existing_ids = array_column( $providers, 'id' );
            $clean['id']   = self::generate_unique_id( $clean['type'], $clean['name'], $existing_ids );
        }

        if ( null !== $existing_index ) {
            $providers[ $existing_index ] = $clean;
        } else {
            $providers[] = $clean;
        }

        update_option( self::OPTION_CONFIGURED_PROVIDERS, $providers, false );

        return $clean['id'];
    }

    /**
     * Delete a provider by ID.
     *
     * @param string $provider_id Provider slug/ID.
     * @return bool True if deleted, false if not found.
     */
    public static function delete_provider( string $provider_id ): bool {
        $providers = self::get_all( false );
        $found     = false;
        $updated   = [];

        foreach ( $providers as $provider ) {
            if ( ( $provider['id'] ?? '' ) === $provider_id ) {
                $found = true;
                continue;
            }
            $updated[] = $provider;
        }

        if ( ! $found ) {
            return false;
        }

        update_option( self::OPTION_CONFIGURED_PROVIDERS, $updated, false );
        return true;
    }

    /**
     * Get available models list for a specific provider.
     *
     * @param string $provider_id Provider slug/ID.
     * @return array List of model name strings.
     */
    public static function get_models_for_provider( string $provider_id ): array {
        $provider = self::get( $provider_id );
        if ( null === $provider ) {
            return [];
        }

        $models = $provider['available_models'] ?? [];
        if ( is_array( $models ) && ! empty( $models ) ) {
            return array_values( array_unique( array_filter( array_map( 'trim', $models ) ) ) );
        }

        if ( ! empty( $provider['default_model'] ) ) {
            return [ trim( $provider['default_model'] ) ];
        }

        return [];
    }

    /**
     * Migrate legacy flat options into the configured providers option.
     *
     * Reads legacy options like presshub_ai_api_key, presshub_ai_provider,
     * presshub_ai_google_cloud_api_key, presshub_ai_model_*, etc., and
     * seeds initial active providers if presshub_ai_configured_providers is empty.
     */
    public static function migrate_legacy_options(): void {
        $existing = get_option( self::OPTION_CONFIGURED_PROVIDERS, null );
        if ( is_array( $existing ) ) {
            return;
        }

        $legacy_provider_raw = get_option( 'presshub_ai_provider', null );
        $legacy_api_key_raw  = get_option( 'presshub_ai_api_key', null );
        $legacy_gcloud_key   = get_option( 'presshub_ai_google_cloud_api_key', null );
        $legacy_tts_key      = get_option( 'presshub_ai_briefing_tts_api_key', null );
        $openai_org_raw      = get_option( 'presshub_ai_openai_org', null );
        $anthropic_ver_raw   = get_option( 'presshub_ai_anthropic_version', null );

        $has_legacy_config = ( null !== $legacy_provider_raw ) ||
                             ( null !== $legacy_api_key_raw ) ||
                             ( null !== $legacy_gcloud_key ) ||
                             ( null !== $legacy_tts_key ) ||
                             ( null !== $openai_org_raw ) ||
                             ( null !== $anthropic_ver_raw );

        if ( ! $has_legacy_config ) {
            foreach ( [ 'openai', 'anthropic', 'gemini' ] as $prov_type ) {
                if ( null !== get_option( 'presshub_ai_model_' . $prov_type, null ) ||
                     null !== get_option( 'presshub_ai_temperature_' . $prov_type, null ) ||
                     null !== get_option( 'presshub_ai_max_tokens_' . $prov_type, null ) ||
                     null !== get_option( 'presshub_ai_timeout_' . $prov_type, null ) ) {
                    $has_legacy_config = true;
                    break;
                }
            }
        }

        if ( ! $has_legacy_config ) {
            // Fresh installation: initialize empty configured providers and mark as migrated.
            update_option( self::OPTION_CONFIGURED_PROVIDERS, [], false );
            update_option( self::OPTION_MIGRATED, 1, false );
            return;
        }

        $legacy_provider   = (string) ( $legacy_provider_raw ?? 'gemini' );
        $legacy_api_key    = trim( (string) ( $legacy_api_key_raw ?? '' ) );
        $openai_org        = trim( (string) ( $openai_org_raw ?? '' ) );
        $anthropic_version = trim( (string) ( $anthropic_ver_raw ?? '' ) );

        $templates = PressHub_AI_Provider_Defaults::get_templates();
        $providers = [];

        // 1. Google Gemini
        $gemini_key          = ( 'gemini' === $legacy_provider ) ? $legacy_api_key : '';
        $gemini_custom_model = get_option( 'presshub_ai_model_gemini', null );
        if ( '' !== $gemini_key || ( null !== $gemini_custom_model && '' !== trim( (string) $gemini_custom_model ) ) ) {
            $providers[] = [
                'id'               => 'gemini-main',
                'type'             => 'gemini',
                'name'             => 'Google Gemini',
                'api_key'          => $gemini_key,
                'base_url'         => $templates['gemini']['base_url'],
                'default_model'    => (string) ( $gemini_custom_model ?? $templates['gemini']['default_model'] ),
                'available_models' => $templates['gemini']['available_models'],
                'timeout'          => (int) get_option( 'presshub_ai_timeout_gemini', 300 ),
                'temperature'      => (float) get_option( 'presshub_ai_temperature_gemini', 0.7 ),
                'max_tokens'       => (int) get_option( 'presshub_ai_max_tokens_gemini', 10000 ),
                'headers'          => [],
                'enabled'          => true,
                'is_system'        => true,
            ];
        }

        // 2. OpenAI
        $openai_key          = ( 'openai' === $legacy_provider ) ? $legacy_api_key : '';
        $openai_custom_model = get_option( 'presshub_ai_model_openai', null );
        if ( '' !== $openai_key || '' !== $openai_org || ( null !== $openai_custom_model && '' !== trim( (string) $openai_custom_model ) ) ) {
            $openai_headers = [];
            if ( '' !== $openai_org ) {
                $openai_headers['OpenAI-Organization'] = $openai_org;
            }
            $providers[] = [
                'id'               => 'openai-default',
                'type'             => 'openai',
                'name'             => 'OpenAI',
                'api_key'          => $openai_key,
                'base_url'         => $templates['openai']['base_url'],
                'default_model'    => (string) ( $openai_custom_model ?? $templates['openai']['default_model'] ),
                'available_models' => $templates['openai']['available_models'],
                'timeout'          => (int) get_option( 'presshub_ai_timeout_openai', 300 ),
                'temperature'      => (float) get_option( 'presshub_ai_temperature_openai', 0.7 ),
                'max_tokens'       => (int) get_option( 'presshub_ai_max_tokens_openai', 10000 ),
                'headers'          => $openai_headers,
                'enabled'          => true,
                'is_system'        => true,
            ];
        }

        // 3. Anthropic Claude
        $anthropic_key          = ( 'anthropic' === $legacy_provider ) ? $legacy_api_key : '';
        $anthropic_custom_model = get_option( 'presshub_ai_model_anthropic', null );
        if ( '' !== $anthropic_key || '' !== $anthropic_version || ( null !== $anthropic_custom_model && '' !== trim( (string) $anthropic_custom_model ) ) ) {
            $anthropic_headers = [];
            if ( '' !== $anthropic_version ) {
                $anthropic_headers['anthropic-version'] = $anthropic_version;
            }
            $providers[] = [
                'id'               => 'anthropic-default',
                'type'             => 'anthropic',
                'name'             => 'Anthropic Claude',
                'api_key'          => $anthropic_key,
                'base_url'         => $templates['anthropic']['base_url'],
                'default_model'    => (string) ( $anthropic_custom_model ?? $templates['anthropic']['default_model'] ),
                'available_models' => $templates['anthropic']['available_models'],
                'timeout'          => (int) get_option( 'presshub_ai_timeout_anthropic', 300 ),
                'temperature'      => (float) get_option( 'presshub_ai_temperature_anthropic', 0.7 ),
                'max_tokens'       => (int) get_option( 'presshub_ai_max_tokens_anthropic', 10000 ),
                'headers'          => $anthropic_headers,
                'enabled'          => true,
                'is_system'        => true,
            ];
        }

        // Sanitize all items before saving
        $clean_providers = [];
        foreach ( $providers as $p ) {
            $clean = self::sanitize_provider( $p );
            if ( $clean ) {
                $clean_providers[] = $clean;
            }
        }

        update_option( self::OPTION_CONFIGURED_PROVIDERS, $clean_providers, false );
        update_option( self::OPTION_MIGRATED, 1, false );
    }

    /**
     * Sanitize and validate a provider record.
     *
     * @param array      $data     Incoming provider data.
     * @param array|null $existing Optional existing record for preserving masked/omitted fields.
     * @return array|null Sanitized provider record or null if invalid.
     */
    public static function sanitize_provider( array $data, ?array $existing = null ): ?array {
        $type = isset( $data['type'] ) ? sanitize_key( (string) $data['type'] ) : ( $existing['type'] ?? 'custom_openai' );
        if ( empty( $type ) ) {
            $type = 'custom_openai';
        }

        $template = PressHub_AI_Provider_Defaults::get_template( $type );

        // ID
        $id = '';
        if ( isset( $data['id'] ) && '' !== trim( (string) $data['id'] ) ) {
            $id = self::sanitize_id( (string) $data['id'] );
        } elseif ( ! empty( $existing['id'] ) ) {
            $id = $existing['id'];
        }

        // Name
        $name = '';
        if ( isset( $data['name'] ) && '' !== trim( (string) $data['name'] ) ) {
            $name = sanitize_text_field( (string) $data['name'] );
        } elseif ( ! empty( $existing['name'] ) ) {
            $name = $existing['name'];
        } elseif ( $template && ! empty( $template['name'] ) ) {
            $name = $template['name'];
        } else {
            $name = ucwords( str_replace( [ '_', '-' ], ' ', $type ) );
        }

        // API Key
        $api_key = '';
        if ( isset( $data['api_key'] ) && '' !== trim( (string) $data['api_key'] ) ) {
            $api_key = trim( (string) $data['api_key'] );
        } elseif ( ! empty( $existing['api_key'] ) ) {
            $api_key = $existing['api_key'];
        }

        // Base URL
        $base_url = '';
        if ( isset( $data['base_url'] ) && '' !== trim( (string) $data['base_url'] ) ) {
            $base_url = trim( (string) $data['base_url'] );
        } elseif ( isset( $existing['base_url'] ) && '' !== $existing['base_url'] ) {
            $base_url = $existing['base_url'];
        } elseif ( $template && ! empty( $template['base_url'] ) ) {
            $base_url = $template['base_url'];
        }

        // Default Model
        $default_model = '';
        if ( isset( $data['default_model'] ) && '' !== trim( (string) $data['default_model'] ) ) {
            $default_model = sanitize_text_field( (string) $data['default_model'] );
        } elseif ( ! empty( $existing['default_model'] ) ) {
            $default_model = $existing['default_model'];
        } elseif ( $template && ! empty( $template['default_model'] ) ) {
            $default_model = $template['default_model'];
        } else {
            $default_model = 'default';
        }

        // Available Models
        $available_models = [];
        $raw_models       = $data['available_models'] ?? ( $existing['available_models'] ?? ( $template['available_models'] ?? [ $default_model ] ) );
        if ( is_string( $raw_models ) ) {
            $raw_models = preg_split( '/[\r\n,]+/', $raw_models );
        }
        if ( is_array( $raw_models ) ) {
            foreach ( $raw_models as $m ) {
                if ( is_string( $m ) || is_numeric( $m ) ) {
                    $clean_m = sanitize_text_field( (string) $m );
                    if ( '' !== $clean_m && ! in_array( $clean_m, $available_models, true ) ) {
                        $available_models[] = $clean_m;
                    }
                }
            }
        }
        if ( empty( $available_models ) ) {
            $available_models = [ $default_model ];
        } elseif ( ! in_array( $default_model, $available_models, true ) ) {
            array_unshift( $available_models, $default_model );
        }

        // Timeout
        $timeout = 300;
        if ( isset( $data['timeout'] ) ) {
            $timeout = max( 1, (int) $data['timeout'] );
        } elseif ( isset( $existing['timeout'] ) ) {
            $timeout = max( 1, (int) $existing['timeout'] );
        }

        // Temperature
        $temperature = 0.7;
        if ( isset( $data['temperature'] ) ) {
            $temperature = max( 0.0, min( 2.0, (float) $data['temperature'] ) );
        } elseif ( isset( $existing['temperature'] ) ) {
            $temperature = max( 0.0, min( 2.0, (float) $existing['temperature'] ) );
        }

        // Max Tokens
        $max_tokens = 10000;
        if ( isset( $data['max_tokens'] ) ) {
            $max_tokens = max( 1, (int) $data['max_tokens'] );
        } elseif ( isset( $existing['max_tokens'] ) ) {
            $max_tokens = max( 1, (int) $existing['max_tokens'] );
        }

        // Headers
        $headers = [];
        $raw_headers = $data['headers'] ?? ( $existing['headers'] ?? [] );
        if ( is_array( $raw_headers ) ) {
            foreach ( $raw_headers as $hk => $hv ) {
                $clean_k = sanitize_text_field( (string) $hk );
                $clean_v = sanitize_text_field( (string) $hv );
                if ( '' !== $clean_k ) {
                    $headers[ $clean_k ] = $clean_v;
                }
            }
        }

        // Enabled
        $enabled = true;
        if ( array_key_exists( 'enabled', $data ) ) {
            $enabled = (bool) $data['enabled'];
        } elseif ( isset( $existing['enabled'] ) ) {
            $enabled = (bool) $existing['enabled'];
        }

        // Is System
        $is_system = false;
        if ( array_key_exists( 'is_system', $data ) ) {
            $is_system = (bool) $data['is_system'];
        } elseif ( isset( $existing['is_system'] ) ) {
            $is_system = (bool) $existing['is_system'];
        } elseif ( $template && isset( $template['is_system'] ) ) {
            $is_system = (bool) $template['is_system'];
        }

        return [
            'id'               => $id,
            'type'             => $type,
            'name'             => $name,
            'api_key'          => $api_key,
            'base_url'         => $base_url,
            'default_model'    => $default_model,
            'available_models' => array_values( $available_models ),
            'timeout'          => $timeout,
            'temperature'      => $temperature,
            'max_tokens'       => $max_tokens,
            'headers'          => $headers,
            'enabled'          => $enabled,
            'is_system'        => $is_system,
        ];
    }

    /**
     * Sanitize an ID slug.
     *
     * @param string $id
     * @return string
     */
    public static function sanitize_id( string $id ): string {
        $id = strtolower( trim( $id ) );
        $id = preg_replace( '/[^a-z0-9_-]/', '-', $id );
        $id = preg_replace( '/-+/', '-', $id );
        return trim( substr( $id, 0, 64 ), '-_' );
    }

    /**
     * Generate a unique ID for a new provider.
     *
     * @param string $type
     * @param string $name
     * @param array  $existing_ids
     * @return string
     */
    public static function generate_unique_id( string $type, string $name, array $existing_ids ): string {
        $base = self::sanitize_id( $type . '-' . $name );
        if ( empty( $base ) ) {
            $base = self::sanitize_id( $type );
        }
        if ( empty( $base ) ) {
            $base = 'provider';
        }

        $id = $base;
        $counter = 1;
        while ( in_array( $id, $existing_ids, true ) ) {
            $id = $base . '-' . $counter;
            $counter++;
        }

        return $id;
    }

    /**
     * Get a default template record for a provider type.
     *
     * @param string $type
     * @return array
     */
    public static function get_default_provider_record( string $type ): array {
        $template = PressHub_AI_Provider_Defaults::get_template( $type );
        if ( $template ) {
            return array_merge( [
                'id'      => '',
                'api_key' => '',
            ], $template );
        }

        return [
            'id'               => '',
            'type'             => $type,
            'name'             => ucwords( str_replace( [ '_', '-' ], ' ', $type ) ),
            'api_key'          => '',
            'base_url'         => '',
            'default_model'    => 'default',
            'available_models' => [ 'default' ],
            'timeout'          => 300,
            'temperature'      => 0.7,
            'max_tokens'       => 10000,
            'headers'          => [],
            'enabled'          => true,
            'is_system'        => false,
        ];
    }
}
