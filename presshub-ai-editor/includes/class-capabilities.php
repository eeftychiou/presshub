<?php
/**
 * One PressHub capability for every PressHub page and admin action (#235).
 *
 * Editors and administrators see and use everything in the plugin (Q9,
 * decided 16 Nov). Every capability check in the plugin's admin code calls
 * PressHub_Capabilities::manage() instead of naming a WordPress capability,
 * so the site owner can narrow access in one place:
 *
 *     add_filter( 'presshub_ai_settings_cap', fn() => 'manage_options' );
 *
 * restores the pre-2.41 admin-only access for Settings, Social settings,
 * Presets, Social Queue and Usage & logs.
 *
 * @package presshub-ai-editor
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_Capabilities {

    /** Default capability: Editors and Administrators both have it. */
    const DEFAULT_CAP = 'edit_others_posts';

    /**
     * The capability for every PressHub settings page, admin page and admin
     * AJAX action. Filterable through `presshub_ai_settings_cap`.
     */
    public static function manage(): string {
        $cap = function_exists( 'apply_filters' )
            ? (string) apply_filters( 'presshub_ai_settings_cap', self::DEFAULT_CAP )
            : self::DEFAULT_CAP;
        return '' !== $cap ? $cap : self::DEFAULT_CAP;
    }

    /**
     * The capability for the Daily Briefing Hub and the PressHub menu itself.
     * Filterable through `presshub_ai_briefing_cap`; defaults to the same
     * value as manage() so both stay in step unless the owner splits them.
     */
    public static function briefing(): string {
        $cap = function_exists( 'apply_filters' )
            ? (string) apply_filters( 'presshub_ai_briefing_cap', self::DEFAULT_CAP )
            : self::DEFAULT_CAP;
        return '' !== $cap ? $cap : self::DEFAULT_CAP;
    }

    /** Whether the current user may use PressHub pages and actions. */
    public static function current_user_can_manage(): bool {
        return function_exists( 'current_user_can' ) && current_user_can( self::manage() );
    }

    /**
     * options.php checks `manage_options` unless a group says otherwise. Each
     * PressHub option group returns the PressHub capability, so an editor's
     * Save on a settings page is accepted like an administrator's.
     */
    public static function register_option_group( string $group ): void {
        if ( '' === $group || ! function_exists( 'add_filter' ) ) {
            return;
        }
        add_filter( 'option_page_capability_' . $group, [ __CLASS__, 'option_page_capability' ] );
    }

    /** Filter callback for option_page_capability_<group>. */
    public static function option_page_capability( $cap = '' ): string {
        return self::manage();
    }
}
