<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PressHub_AI_Markdown — converts model Markdown output into clean HTML
 * suitable for WordPress insertion.
 *
 * Models frequently return Markdown (## headings, **bold**, - lists)
 * even when asked for prose; inserting it raw shows literal ** and ##
 * in the editor. This lightweight, dependency-free converter normalises
 * the common subset (ATX headings, bold/italic, inline code, links,
 * ul/ol lists, blockquotes, paragraphs) and strips code fences. Output
 * that already contains block-level HTML is passed through untouched.
 *
 * @since 1.2.9
 */
class PressHub_AI_Markdown {

    /**
     * Convert Markdown text to HTML (wp_kses_post-sanitised).
     */
    public static function to_html( $text ): string {
        $text = (string) $text;

        // Strip code fences (```html, ```markdown, bare ```) FIRST so a
        // fenced HTML block isn't passed through with fences intact.
        $text = preg_replace( '/^\s*```[a-zA-Z0-9_-]*\s*$/m', '', $text );

        // Already HTML (block-level tags present)? Pass through.
        if ( preg_match( '#<(p|h[1-6]|ul|ol|div|blockquote|table)[\s>]#i', $text ) ) {
            return wp_kses_post( $text );
        }

        $lines   = preg_split( '/\r?\n/', $text );
        $html    = '';
        $paras   = [];
        $in_ul   = false;
        $in_ol   = false;
        $in_quote = false;

        $close_list = function () use ( &$html, &$in_ul, &$in_ol ) {
            if ( $in_ul ) { $html .= '</ul>'; $in_ul = false; }
            if ( $in_ol ) { $html .= '</ol>'; $in_ol = false; }
        };
        $flush_paras = function () use ( &$html, &$paras, &$in_quote ) {
            if ( $in_quote ) { $html .= '</blockquote>'; $in_quote = false; }
            if ( $paras ) {
                $html .= '<p>' . implode( '</p><p>', $paras ) . '</p>';
                $paras = [];
            }
        };

        foreach ( $lines as $line ) {
            $line = rtrim( $line );
            $trim = trim( $line );

            if ( '' === $trim ) {
                $close_list();
                $flush_paras();
                continue;
            }

            // Headings: # .. ######
            if ( preg_match( '/^(#{1,6})\s+(.*)$/', $trim, $m ) ) {
                $close_list();
                $flush_paras();
                $level = strlen( $m[1] );
                $html .= '<h' . $level . '>' . self::inline( $m[2] ) . '</h' . $level . '>';
                continue;
            }

            // Blockquote.
            if ( preg_match( '/^>\s?(.*)$/', $trim, $m ) ) {
                $flush_paras();
                if ( ! $in_quote ) { $html .= '<blockquote>'; $in_quote = true; }
                $html .= self::inline( $m[1] ) . '<br />';
                continue;
            }

            // Unordered list.
            if ( preg_match( '/^[-*]\s+(.+)$/', $trim, $m ) && ! self::looks_like_hr( $trim ) ) {
                $flush_paras();
                if ( $in_ol ) { $html .= '</ol>'; $in_ol = false; }
                if ( ! $in_ul ) { $html .= '<ul>'; $in_ul = true; }
                $html .= '<li>' . self::inline( $m[1] ) . '</li>';
                continue;
            }

            // Ordered list.
            if ( preg_match( '/^\d+[.)]\s+(.+)$/', $trim, $m ) ) {
                $flush_paras();
                if ( $in_ul ) { $html .= '</ul>'; $in_ul = false; }
                if ( ! $in_ol ) { $html .= '<ol>'; $in_ol = true; }
                $html .= '<li>' . self::inline( $m[1] ) . '</li>';
                continue;
            }

            // Plain paragraph line (accumulate until a blank line).
            $close_list();
            $paras[] = self::inline( $trim );
        }

        $close_list();
        $flush_paras();

        $html = trim( $html );
        // Tidy <br /> at the end of the last blockquote line.
        $html = preg_replace( '#<br />\s*</blockquote>#', '</blockquote>', $html );

        return wp_kses_post( $html );
    }

    /**
     * Inline conversions: bold, italic, inline code, links.
     */
    private static function inline( $text ): string {
        // Inline code first so `wp_insert_post`-style identifiers are
        // protected from the emphasis rules below.
        $text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
        $text = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text );
        // Underscore variants only around word boundaries so
        // `wp_insert_post` (outside backticks) is never split into
        // emphasis.
        $text = preg_replace( '/(?<![A-Za-z0-9_])__(.+?)__(?![A-Za-z0-9_])/s', '<strong>$1</strong>', $text );
        // Italic only when not part of a word boundary pair already used.
        $text = preg_replace( '/(?<!\*)\*([^*\s][^*]*?)\*(?!\*)/s', '<em>$1</em>', $text );
        $text = preg_replace( '/(?<![A-Za-z0-9_])_([^_\s][^_]*?)_(?![A-Za-z0-9_])/s', '<em>$1</em>', $text );
        $text = preg_replace(
            '#\[([^\]]+)\]\((https?://[^)\s]+)\)#',
            '<a href="$2">$1</a>',
            $text
        );
        return $text;
    }

    /**
     * A "- - -" / "***" line is an HR, not a list item.
     */
    private static function looks_like_hr( $trim ): bool {
        return (bool) preg_match( '/^(-{3,}|\*{3,}|_{3,})$/', $trim );
    }
}
