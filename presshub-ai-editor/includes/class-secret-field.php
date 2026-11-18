<?php
/**
 * One way to show and replace a stored secret (#247).
 *
 * The saved value is never printed. The field shows its masked form (or
 * "Not set") and a "Replace key" button that opens an empty password input.
 * Leaving that input empty keeps the saved value, because every secret
 * sanitizer keeps the stored value on an empty or masked post. The existing
 * "Remove" checkbox, when the caller names one, still clears the value.
 *
 * Without JavaScript the input is simply shown, so the field still works.
 *
 * @package presshub-ai-editor
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_Secret_Field {

    /**
     * @param array{
     *   name:string,
     *   id?:string,
     *   masked:string,
     *   remove_name?:string,
     *   remove_label?:string,
     *   placeholder?:string,
     *   replace_label?:string,
     *   add_label?:string,
     *   class?:string,
     *   describedby?:string,
     * } $args
     */
    public static function render( array $args ): void {
        echo self::html( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped below.
    }

    public static function html( array $args ): string {
        $name        = (string) $args['name'];
        $id          = (string) ( $args['id'] ?? $name );
        $masked      = (string) ( $args['masked'] ?? '' );
        $has         = '' !== $masked;
        $remove_name = (string) ( $args['remove_name'] ?? '' );
        $placeholder = (string) ( $args['placeholder'] ?? ( $has ? __( 'Paste the new key', 'presshub-ai-editor' ) : __( 'Paste the key', 'presshub-ai-editor' ) ) );
        $class       = trim( 'regular-text ' . (string) ( $args['class'] ?? '' ) );
        $edit_id     = $id . '-edit';
        $replace     = (string) ( $args['replace_label'] ?? __( 'Replace key', 'presshub-ai-editor' ) );
        $add         = (string) ( $args['add_label'] ?? __( 'Add key', 'presshub-ai-editor' ) );

        $out  = '<div class="presshub-secret" data-secret-for="' . esc_attr( $name ) . '">';
        $out .= '<span class="presshub-secret-current' . ( $has ? ' code' : '' ) . '" data-empty-label="' . esc_attr__( 'Not set', 'presshub-ai-editor' ) . '">' . esc_html( $has ? $masked : __( 'Not set', 'presshub-ai-editor' ) ) . '</span> ';
        $out .= '<button type="button" class="button presshub-secret-replace" aria-expanded="false" aria-controls="' . esc_attr( $edit_id ) . '" data-replace-label="' . esc_attr( $replace ) . '" data-add-label="' . esc_attr( $add ) . '" hidden>'
            . esc_html( $has ? $replace : $add ) . '</button>';
        $out .= '<span class="presshub-secret-edit" id="' . esc_attr( $edit_id ) . '">';
        $out .= '<input type="password" name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" value="" class="' . esc_attr( $class ) . '" autocomplete="new-password" spellcheck="false" placeholder="' . esc_attr( $placeholder ) . '"'
            . ( ! empty( $args['describedby'] ) ? ' aria-describedby="' . esc_attr( (string) $args['describedby'] ) . '"' : '' ) . ' /> ';
        $out .= '<button type="button" class="button-link presshub-secret-cancel" hidden>' . esc_html__( 'Cancel', 'presshub-ai-editor' ) . '</button>';
        if ( $has ) {
            $out .= '<span class="description presshub-secret-keep">' . esc_html__( 'Leave empty to keep the saved key.', 'presshub-ai-editor' ) . '</span>';
        }
        $out .= '</span>';
        // A key added later on this page can be removed without a reload, so
        // the box is always there, hidden while nothing is saved.
        if ( '' !== $remove_name ) {
            $out .= '<label class="presshub-secret-remove"' . ( $has ? '' : ' hidden' ) . '><input type="checkbox" name="' . esc_attr( $remove_name ) . '" id="' . esc_attr( $remove_name ) . '" value="1" /> '
                . esc_html( (string) ( $args['remove_label'] ?? __( 'Remove the saved key', 'presshub-ai-editor' ) ) ) . '</label>';
        }
        $out .= '</div>';
        return $out;
    }
}
