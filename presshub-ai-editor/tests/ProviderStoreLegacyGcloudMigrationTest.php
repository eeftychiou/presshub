<?php
/**
 * TDD tests for Issue #43: Deprecate legacy Google Cloud Media & Vision credentials.
 *
 * Covers the Provider Store legacy migration extension:
 *   - When presshub_ai_google_cloud_api_key is set and no Gemini provider
 *     is configured, migrate_legacy_options() MUST seed a `gemini-main`
 *     provider with the legacy key as its API credential.
 *   - After successful seeding, the legacy option MUST be deleted so the
 *     deprecated flat option is removed from the database.
 *   - If a Gemini provider is already configured, the legacy gcloud key
 *     MUST NOT overwrite it (newer structured config wins).
 *
 * Backward compatibility is preserved: `presshub_ai_google_cloud_api_key`
 * remains a registered legacy option; it is only deleted after migration.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-provider-defaults.php';
require_once __DIR__ . '/../includes/class-provider-store.php';

class ProviderStoreLegacyGcloudMigrationTest {

    public static function run(): void {
        $failures = [];

        // ==================================================================
        // 1. Legacy Google Cloud API key seeds a Gemini provider
        // ==================================================================
        self::reset();
        // Simulated prior install: legacy gcloud key with no other legacy data.
        $GLOBALS['OPTIONS_STORE']['presshub_ai_google_cloud_api_key'] = 'legacy-gcloud-key-xyz';

        PressHub_AI_Provider_Store::migrate_legacy_options();

        $gemini = PressHub_AI_Provider_Store::get( 'gemini-main' );

        if ( ! $gemini ) {
            $failures[] = "Migration with legacy presshub_ai_google_cloud_api_key should seed a 'gemini-main' provider.";
        } else {
            if ( ( $gemini['type'] ?? '' ) !== 'gemini' ) {
                $failures[] = "Seeded gemini provider has wrong type. Got: " . var_export( $gemini['type'] ?? null, true );
            }
            if ( ( $gemini['api_key'] ?? '' ) !== 'legacy-gcloud-key-xyz' ) {
                $failures[] = "Seeded gemini provider api_key should equal the legacy gcloud key. Got: " . var_export( $gemini['api_key'] ?? null, true );
            }
            if ( empty( $gemini['enabled'] ) ) {
                $failures[] = "Seeded gemini provider should be enabled by default.";
            }
            if ( empty( $gemini['base_url'] ) ) {
                $failures[] = "Seeded gemini provider should have a non-empty base_url.";
            }
            if ( empty( $gemini['default_model'] ) ) {
                $failures[] = "Seeded gemini provider should have a non-empty default_model.";
            }
        }

        // ==================================================================
        // 2. Legacy Google Cloud API key is deleted after migration
        // ==================================================================
        // OPTIONS_STORE no longer contains the key (delete_option unsets it).
        if ( isset( $GLOBALS['OPTIONS_STORE']['presshub_ai_google_cloud_api_key'] ) ) {
            $failures[] = "Legacy presshub_ai_google_cloud_api_key should be deleted after seeding Gemini provider. Still present: " . var_export( $GLOBALS['OPTIONS_STORE']['presshub_ai_google_cloud_api_key'], true );
        }

        // get_option() should now return its default (null) for the deleted key.
        $post_delete_value = get_option( 'presshub_ai_google_cloud_api_key', null );
        if ( null !== $post_delete_value && false !== $post_delete_value ) {
            $failures[] = "get_option('presshub_ai_google_cloud_api_key') should return null/false after migration delete. Got: " . var_export( $post_delete_value, true );
        }

        // ==================================================================
        // 3. Legacy gcloud key does NOT overwrite an existing Gemini provider
        // ==================================================================
        self::reset();

        // Operator already configured Gemini with a different (newer) key.
        $existing_gemini_id = PressHub_AI_Provider_Store::save_provider( [
            'type'          => 'gemini',
            'name'          => 'My Gemini',
            'api_key'       => 'newer-structured-key-abc',
            'default_model' => 'gemini-2.0-flash',
            'enabled'       => true,
        ] );

        // Then they later paste a legacy gcloud key directly into the
        // legacy option (e.g. via an old WP-CLI import or downgrade).
        $GLOBALS['OPTIONS_STORE']['presshub_ai_google_cloud_api_key'] = 'should-not-overwrite';

        // Re-running migration must not stomp on the existing Gemini config.
        PressHub_AI_Provider_Store::migrate_legacy_options();

        $existing_gemini = PressHub_AI_Provider_Store::get( $existing_gemini_id );
        if ( ! $existing_gemini ) {
            $failures[] = "Existing Gemini provider disappeared after legacy gcloud migration re-run.";
        } else {
            if ( ( $existing_gemini['api_key'] ?? '' ) !== 'newer-structured-key-abc' ) {
                $failures[] = "Legacy gcloud migration must not overwrite existing Gemini api_key. Got: " . var_export( $existing_gemini['api_key'] ?? null, true );
            }
            if ( ( $existing_gemini['default_model'] ?? '' ) !== 'gemini-2.0-flash' ) {
                $failures[] = "Existing Gemini default_model must be preserved. Got: " . var_export( $existing_gemini['default_model'] ?? null, true );
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
        $GLOBALS['OPTIONS_STORE']       = [];
        $GLOBALS['UPDATE_OPTION_CALLS'] = [];
    }
}

ProviderStoreLegacyGcloudMigrationTest::run();
