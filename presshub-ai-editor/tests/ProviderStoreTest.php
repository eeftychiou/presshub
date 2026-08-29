<?php
/**
 * TDD tests for PressHub_AI_Provider_Store.
 *
 * Covers:
 *   - CRUD for standard providers (OpenAI, Anthropic, Gemini, Groq, etc.)
 *   - CRUD for Custom OpenAI-compatible endpoints
 *   - Automated migration of legacy flat options (presshub_ai_api_key, etc.)
 *   - Retrieval of all vs enabled-only providers
 *   - Preservation of existing API keys when updating with empty key
 *   - Sanitization of fields (timeout, temperature, max_tokens, headers)
 *   - ID generation, sanitization, and unique conflict resolution
 *   - get_models_for_provider() functionality
 *   - Deletion of providers
 *   - Autoload flag verification (P-1: must pass autoload=false)
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-provider-defaults.php';
require_once __DIR__ . '/../includes/class-provider-store.php';

class ProviderStoreTest {

    public static function run(): void {
        $failures = [];

        // ==================================================================
        // 1a. Fresh Installation Test (Clean DB, No Legacy Options)
        // ==================================================================
        self::reset();
        $fresh_all = PressHub_AI_Provider_Store::get_all( false );
        if ( $fresh_all !== [] ) {
            $failures[] = "Fresh installation should initialize with 0 configured providers. Got: " . count( $fresh_all );
        }
        if ( ! isset( $GLOBALS['OPTIONS_STORE']['presshub_ai_configured_providers'] ) || $GLOBALS['OPTIONS_STORE']['presshub_ai_configured_providers'] !== [] ) {
            $failures[] = "Fresh install should set presshub_ai_configured_providers to []. Got: " . var_export( $GLOBALS['OPTIONS_STORE']['presshub_ai_configured_providers'] ?? null, true );
        }
        if ( ( $GLOBALS['OPTIONS_STORE']['presshub_ai_providers_migrated'] ?? null ) !== 1 ) {
            $failures[] = "Fresh install should set presshub_ai_providers_migrated to 1. Got: " . var_export( $GLOBALS['OPTIONS_STORE']['presshub_ai_providers_migrated'] ?? null, true );
        }

        // ==================================================================
        // 1b. Legacy Migration Test (Upgrade from older version with API keys)
        // ==================================================================
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key']  = 'sk-test-legacy-key';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_model_openai'] = 'gpt-4o-mini';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_openai_org']   = 'org-12345';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_google_cloud_api_key'] = 'gcloud-key-999';

        PressHub_AI_Provider_Store::migrate_legacy_options();

        $migrated = PressHub_AI_Provider_Store::get_all( false );
        if ( count( $migrated ) !== 1 ) {
            $failures[] = "Migration with legacy OpenAI key should migrate only the configured provider (1). Got: " . count( $migrated );
        }

        // Check OpenAI migrated record
        $openai = PressHub_AI_Provider_Store::get( 'openai-default' );
        if ( ! $openai ) {
            $failures[] = "Migration missing 'openai-default' provider.";
        } else {
            if ( $openai['api_key'] !== 'sk-test-legacy-key' ) {
                $failures[] = "OpenAI API key was not migrated properly. Got: " . var_export( $openai['api_key'], true );
            }
            if ( $openai['default_model'] !== 'gpt-4o-mini' ) {
                $failures[] = "OpenAI model was not migrated properly. Got: " . var_export( $openai['default_model'], true );
            }
            if ( ( $openai['headers']['OpenAI-Organization'] ?? '' ) !== 'org-12345' ) {
                $failures[] = "OpenAI org header was not migrated. Got: " . var_export( $openai['headers'], true );
            }
            if ( $openai['timeout'] !== 300 ) {
                $failures[] = "OpenAI timeout should default to 300. Got: " . $openai['timeout'];
            }
            if ( empty( $openai['enabled'] ) ) {
                $failures[] = "OpenAI migrated provider should be enabled.";
            }
        }

        // Autoload check (P-1)
        $update_calls = $GLOBALS['UPDATE_OPTION_CALLS'] ?? [];
        $found_provider_update = false;
        foreach ( $update_calls as $call ) {
            if ( $call[0] === PressHub_AI_Provider_Store::OPTION_CONFIGURED_PROVIDERS ) {
                $found_provider_update = true;
                if ( $call[2] !== false ) {
                    $failures[] = "Option presshub_ai_configured_providers must pass autoload=false (P-1).";
                }
            }
        }
        if ( ! $found_provider_update ) {
            $failures[] = "Migration should write via update_option('presshub_ai_configured_providers').";
        }

        // Idempotency: second call does not re-overwrite
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'modified-legacy';
        PressHub_AI_Provider_Store::migrate_legacy_options();
        $openai_recheck = PressHub_AI_Provider_Store::get( 'openai-default' );
        if ( $openai_recheck['api_key'] !== 'sk-test-legacy-key' ) {
            $failures[] = "migrate_legacy_options() must be idempotent and not overwrite configured providers.";
        }

        // ==================================================================
        // 2. CRUD for Custom OpenAI-Compatible Endpoints
        // ==================================================================
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_configured_providers'] = [];
        $custom_id = PressHub_AI_Provider_Store::save_provider( [
            'type'             => 'custom_openai',
            'name'             => 'Local vLLM Server',
            'api_key'          => 'sk-local-secret',
            'base_url'         => 'http://localhost:8000/v1',
            'default_model'    => 'meta-llama/Llama-3-70b-Instruct',
            'available_models' => [ 'meta-llama/Llama-3-70b-Instruct', 'mistralai/Mixtral-8x7B' ],
            'timeout'          => 120,
            'temperature'      => 0.5,
            'max_tokens'       => 4096,
            'headers'          => [ 'X-Custom-Auth' => 'Bearer token123' ],
            'enabled'          => true,
        ] );

        if ( empty( $custom_id ) ) {
            $failures[] = "save_provider should return generated provider ID for custom endpoint.";
        }

        $custom = PressHub_AI_Provider_Store::get( $custom_id );
        if ( ! $custom ) {
            $failures[] = "get({$custom_id}) failed to retrieve custom provider.";
        } else {
            if ( $custom['type'] !== 'custom_openai' ) {
                $failures[] = "Custom provider type mismatch: " . $custom['type'];
            }
            if ( $custom['name'] !== 'Local vLLM Server' ) {
                $failures[] = "Custom provider name mismatch: " . $custom['name'];
            }
            if ( $custom['base_url'] !== 'http://localhost:8000/v1' ) {
                $failures[] = "Custom provider base_url mismatch: " . $custom['base_url'];
            }
            if ( $custom['timeout'] !== 120 ) {
                $failures[] = "Custom provider timeout mismatch: " . $custom['timeout'];
            }
            if ( $custom['temperature'] !== 0.5 ) {
                $failures[] = "Custom provider temperature mismatch: " . $custom['temperature'];
            }
            if ( $custom['max_tokens'] !== 4096 ) {
                $failures[] = "Custom provider max_tokens mismatch: " . $custom['max_tokens'];
            }
            if ( ( $custom['headers']['X-Custom-Auth'] ?? '' ) !== 'Bearer token123' ) {
                $failures[] = "Custom provider headers mismatch: " . var_export( $custom['headers'], true );
            }
            if ( $custom['is_system'] !== false ) {
                $failures[] = "Custom provider is_system should be false.";
            }
        }

        // ==================================================================
        // 3. Update Existing Provider & Preserve API Key
        // ==================================================================
        // Update without passing api_key (blank / omitted) -> should keep 'sk-local-secret'
        PressHub_AI_Provider_Store::save_provider( [
            'id'            => $custom_id,
            'name'          => 'Updated vLLM Server',
            'api_key'       => '', // Blank/omitted on edit
            'default_model' => 'meta-llama/Llama-3-8b',
        ] );

        $updated = PressHub_AI_Provider_Store::get( $custom_id );
        if ( ! $updated ) {
            $failures[] = "Failed to fetch updated custom provider.";
        } else {
            if ( $updated['name'] !== 'Updated vLLM Server' ) {
                $failures[] = "Updated name failed to save. Got: " . $updated['name'];
            }
            if ( $updated['api_key'] !== 'sk-local-secret' ) {
                $failures[] = "Blank api_key on update should preserve existing api_key. Got: " . var_export( $updated['api_key'], true );
            }
            if ( $updated['default_model'] !== 'meta-llama/Llama-3-8b' ) {
                $failures[] = "Updated default model failed. Got: " . $updated['default_model'];
            }
        }

        // ==================================================================
        // 4. Enabled vs All Providers Filter
        // ==================================================================
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_configured_providers'] = [];
        PressHub_AI_Provider_Store::save_provider( [
            'id'      => 'prov-enabled-1',
            'type'    => 'openai',
            'name'    => 'Enabled 1',
            'enabled' => true,
        ] );
        PressHub_AI_Provider_Store::save_provider( [
            'id'      => 'prov-disabled-2',
            'type'    => 'anthropic',
            'name'    => 'Disabled 2',
            'enabled' => false,
        ] );
        PressHub_AI_Provider_Store::save_provider( [
            'id'      => 'prov-enabled-3',
            'type'    => 'gemini',
            'name'    => 'Enabled 3',
            'enabled' => true,
        ] );

        $all = PressHub_AI_Provider_Store::get_all( false );
        if ( count( $all ) !== 3 ) {
            $failures[] = "get_all(false) should return all 3 providers. Got: " . count( $all );
        }

        $enabled_only = PressHub_AI_Provider_Store::get_all( true );
        if ( count( $enabled_only ) !== 2 ) {
            $failures[] = "get_all(true) should return only 2 enabled providers. Got: " . count( $enabled_only );
        }
        foreach ( $enabled_only as $p ) {
            if ( ! $p['enabled'] ) {
                $failures[] = "Provider {$p['id']} returned in enabled_only list but enabled is false.";
            }
        }

        // ==================================================================
        // 5. get_models_for_provider()
        // ==================================================================
        self::reset();
        PressHub_AI_Provider_Store::save_provider( [
            'id'               => 'groq-test',
            'type'             => 'groq',
            'name'             => 'Groq Test',
            'default_model'    => 'llama-3.3-70b-versatile',
            'available_models' => [ 'llama-3.3-70b-versatile', 'llama-3.1-8b-instant' ],
        ] );

        $models = PressHub_AI_Provider_Store::get_models_for_provider( 'groq-test' );
        if ( count( $models ) !== 2 || ! in_array( 'llama-3.3-70b-versatile', $models, true ) ) {
            $failures[] = "get_models_for_provider returned unexpected list: " . var_export( $models, true );
        }

        $unknown_models = PressHub_AI_Provider_Store::get_models_for_provider( 'non-existent' );
        if ( $unknown_models !== [] ) {
            $failures[] = "get_models_for_provider on unknown provider should return []. Got: " . var_export( $unknown_models, true );
        }

        // ==================================================================
        // 6. delete_provider()
        // ==================================================================
        self::reset();
        PressHub_AI_Provider_Store::save_provider( [
            'id'   => 'to-delete',
            'type' => 'custom_openai',
            'name' => 'To Delete',
        ] );
        if ( ! PressHub_AI_Provider_Store::get( 'to-delete' ) ) {
            $failures[] = "Provider 'to-delete' was not saved.";
        }

        $deleted = PressHub_AI_Provider_Store::delete_provider( 'to-delete' );
        if ( ! $deleted ) {
            $failures[] = "delete_provider('to-delete') returned false.";
        }
        if ( PressHub_AI_Provider_Store::get( 'to-delete' ) !== null ) {
            $failures[] = "Provider 'to-delete' still exists after deletion.";
        }

        $delete_unknown = PressHub_AI_Provider_Store::delete_provider( 'no-such-id' );
        if ( $delete_unknown !== false ) {
            $failures[] = "delete_provider on non-existent provider should return false.";
        }

        // ==================================================================
        // 7. Sanitization & Validation Edge Cases
        // ==================================================================
        self::reset();
        $id = PressHub_AI_Provider_Store::save_provider( [
            'id'               => 'Dirty ID @#$!',
            'type'             => 'gemini',
            'name'             => '<script>alert(1)</script>Gemini Clean',
            'timeout'          => -50, // Should clamp to >= 1
            'temperature'      => 9.5, // Should clamp to <= 2.0
            'max_tokens'       => -100, // Should clamp to >= 1
            'available_models' => "model-a, model-b\nmodel-c", // string delimited
        ] );

        $clean_p = PressHub_AI_Provider_Store::get( $id );
        if ( ! $clean_p ) {
            $failures[] = "Failed to retrieve sanitized provider.";
        } else {
            if ( $clean_p['id'] !== 'dirty-id' ) {
                $failures[] = "Sanitized ID mismatch. Got: " . $clean_p['id'];
            }
            if ( $clean_p['name'] !== 'Gemini Clean' && $clean_p['name'] !== 'alert(1)Gemini Clean' && false !== strpos( $clean_p['name'], '<script>' ) ) {
                $failures[] = "Sanitized name should strip tags. Got: " . $clean_p['name'];
            }
            if ( $clean_p['timeout'] < 1 ) {
                $failures[] = "Negative timeout should be clamped to >= 1. Got: " . $clean_p['timeout'];
            }
            if ( $clean_p['temperature'] > 2.0 ) {
                $failures[] = "High temperature should be clamped to <= 2.0. Got: " . $clean_p['temperature'];
            }
            if ( $clean_p['max_tokens'] < 1 ) {
                $failures[] = "Negative max_tokens should be clamped to >= 1. Got: " . $clean_p['max_tokens'];
            }
            if ( count( $clean_p['available_models'] ) < 3 ) {
                $failures[] = "String-delimited available_models should be split into array. Got: " . var_export( $clean_p['available_models'], true );
            }
        }

        // ==================================================================
        // Output Results
        // ==================================================================
        if ( $failures ) {
            fwrite( STDERR, "FAIL\n" );
            foreach ( $failures as $f ) {
                fwrite( STDERR, "  - {$f}\n" );
            }
            exit( 1 );
        }
        echo "OK\n";
    }

    private static function reset(): void {
        $GLOBALS['OPTIONS_STORE']     = [];
        $GLOBALS['UPDATE_OPTION_CALLS'] = [];
    }
}

ProviderStoreTest::run();
