<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Small UI helpers shared by the presets screens (architect A-5).
 *
 * class-admin-presets.php and class-author-presets.php used to carry
 * identical private excerpt() copies; the table-cell truncation now
 * lives here so both pages render presets identically.
 */
class PressHub_AI_Preset_UI {

    /**
     * Plain-text excerpt for table cells (no WP formatting dependency).
     */
    public static function excerpt( string $text, int $length = 120 ): string {
        $text = trim( $text );
        if ( strlen( $text ) <= $length ) {
            return $text;
        }
        return substr( $text, 0, $length ) . '…';
    }
}
