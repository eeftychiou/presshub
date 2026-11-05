<?php
/**
 * Uninstallation cleanup for legacy PressHub Social Auto-Post shim.
 *
 * NOTE: The social auto-posting subsystem is now natively incorporated into
 * PressHub AI Co-Pilot (presshub-ai-editor v2.7.2+). When the legacy shim is deleted,
 * we must PRESERVE all queue items, database tables, options, and cron schedules
 * so that PressHub AI Co-Pilot continues managing social publishing seamlessly.
 *
 * @package PressHub_Social_Autopost
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Preserve all database records, options, and cron schedules.
return;
