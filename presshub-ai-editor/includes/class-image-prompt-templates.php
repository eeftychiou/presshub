<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Prompt templates for the AI Featured Image Studio.
 *
 * A template is the instruction the text model receives, together with the
 * article, when it drafts the visual image prompt ("Draft Image Prompt from
 * Content"). Templates may use the {title} and {content} placeholders.
 *
 * Built-in templates live in code and assets/prompts/image_templates/. The
 * "Editorial photo" built-in is the Article Analysis Prompt Template from
 * Settings, so an admin edit there still drives the default. Templates that
 * editors save from the studio are shared site-wide in one option.
 */
class PressHub_AI_Image_Prompt_Templates {

    const OPTION         = 'presshub_ai_image_prompt_templates';
    const DEFAULT_ID     = 'editorial_photo';
    const POST_META      = '_presshub_ai_image_template';
    const MAX_CUSTOM     = 50;
    const MAX_NAME_LEN   = 80;
    const MAX_TEMPLATE_LEN = 20000;

    /**
     * System prompt used with the default editorial-photo template. Kept
     * verbatim from the original single-template flow.
     */
    const PHOTO_SYSTEM_PROMPT = 'You are a professional editorial photographer and visual director. You output only detailed, realistic image prompts with zero preamble. Your prompts build images from symbols, places and objects and never depict identifiable people or public figures.';

    /**
     * System prompt for every other template: it must not force photo realism
     * onto illustration or cartoon styles.
     */
    const GENERIC_SYSTEM_PROMPT = 'You are a professional visual director for a news publication. You output only one detailed image-generation prompt with zero preamble, in the visual style the instructions ask for. Your prompts never depict identifiable people or public figures and never include text in the image.';

    /**
     * Built-in template definitions: id => [ name, file ]. A null file means
     * the template text comes from the Settings analysis prompt.
     */
    private static function builtin_definitions(): array {
        return [
            self::DEFAULT_ID          => [ __( 'Editorial photo (default)', 'presshub-ai-editor' ), null ],
            'editorial_illustration'  => [ __( 'Editorial illustration', 'presshub-ai-editor' ), 'image_templates/editorial_illustration.txt' ],
            'editorial_cartoon'       => [ __( 'Editorial cartoon', 'presshub-ai-editor' ), 'image_templates/editorial_cartoon.txt' ],
            'conceptual_still_life'   => [ __( 'Conceptual still life (objects)', 'presshub-ai-editor' ), 'image_templates/conceptual_still_life.txt' ],
            'landscape_aerial'        => [ __( 'Place: landscape or aerial view', 'presshub-ai-editor' ), 'image_templates/landscape_aerial.txt' ],
        ];
    }

    /**
     * @return array<int,array{id:string,name:string,template:string,builtin:bool}>
     */
    public static function get_builtin_templates(): array {
        $out = [];
        foreach ( self::builtin_definitions() as $id => $def ) {
            list( $name, $file ) = $def;
            $text = '';
            if ( null === $file ) {
                $text = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_image_analysis_prompt() : '';
                if ( '' === trim( $text ) ) {
                    $text = self::load_file( 'article_image_analysis_prompt.txt' );
                }
            } else {
                $text = self::load_file( $file );
            }
            if ( '' === trim( $text ) ) {
                continue;
            }
            $out[] = [
                'id'       => $id,
                'name'     => $name,
                'template' => $text,
                'builtin'  => true,
            ];
        }
        return $out;
    }

    /**
     * Templates saved by editors, oldest first.
     *
     * @return array<int,array{id:string,name:string,template:string,builtin:bool,author:int,created:int}>
     */
    public static function get_custom_templates(): array {
        $raw = get_option( self::OPTION, [] );
        if ( ! is_array( $raw ) ) {
            return [];
        }
        $out = [];
        foreach ( $raw as $row ) {
            if ( ! is_array( $row ) || empty( $row['id'] ) || ! isset( $row['template'] ) || '' === trim( (string) $row['template'] ) ) {
                continue;
            }
            $out[] = [
                'id'       => (string) $row['id'],
                'name'     => (string) ( $row['name'] ?? $row['id'] ),
                'template' => (string) $row['template'],
                'builtin'  => false,
                'author'   => (int) ( $row['author'] ?? 0 ),
                'created'  => (int) ( $row['created'] ?? 0 ),
            ];
        }
        return $out;
    }

    /** Built-ins first, then saved templates. */
    public static function get_all(): array {
        return array_merge( self::get_builtin_templates(), self::get_custom_templates() );
    }

    public static function get( string $id ): ?array {
        foreach ( self::get_all() as $tpl ) {
            if ( $tpl['id'] === $id ) {
                return $tpl;
            }
        }
        return null;
    }

    public static function is_builtin_id( string $id ): bool {
        return array_key_exists( $id, self::builtin_definitions() );
    }

    /**
     * Save a new template. Names must be unique (case-insensitive) across
     * built-in and saved templates.
     *
     * @return array|WP_Error The stored template.
     */
    public static function add( string $name, string $template, int $author = 0 ) {
        $name     = trim( preg_replace( '/\s+/', ' ', sanitize_text_field( $name ) ) );
        $template = trim( str_replace( "\r\n", "\n", $template ) );

        if ( '' === $name ) {
            return new WP_Error( 'missing_name', __( 'Give the template a name.', 'presshub-ai-editor' ) );
        }
        if ( mb_strlen( $name ) > self::MAX_NAME_LEN ) {
            $name = mb_substr( $name, 0, self::MAX_NAME_LEN );
        }
        if ( '' === $template ) {
            return new WP_Error( 'missing_template', __( 'The template text is empty.', 'presshub-ai-editor' ) );
        }
        if ( mb_strlen( $template ) > self::MAX_TEMPLATE_LEN ) {
            return new WP_Error( 'template_too_long', __( 'The template text is too long.', 'presshub-ai-editor' ) );
        }

        foreach ( self::get_all() as $existing ) {
            if ( 0 === strcasecmp( $existing['name'], $name ) ) {
                return new WP_Error( 'duplicate_name', __( 'A template with this name already exists. Choose another name.', 'presshub-ai-editor' ) );
            }
        }

        $custom = self::get_custom_templates();
        if ( count( $custom ) >= self::MAX_CUSTOM ) {
            return new WP_Error( 'too_many', __( 'The template list is full. Delete a saved template first.', 'presshub-ai-editor' ) );
        }

        $base = sanitize_key( str_replace( ' ', '_', $name ) );
        if ( '' === $base ) {
            $base = 'template';
        }
        $id = 'custom_' . $base;
        $n  = 2;
        while ( null !== self::get( $id ) ) {
            $id = 'custom_' . $base . '_' . $n++;
        }

        $row = [
            'id'       => $id,
            'name'     => $name,
            'template' => $template,
            'author'   => $author,
            'created'  => time(),
        ];
        $stored   = array_map( [ __CLASS__, 'to_storage' ], $custom );
        $stored[] = $row;
        update_option( self::OPTION, $stored, false );

        return array_merge( $row, [ 'builtin' => false ] );
    }

    /**
     * Delete a saved template. Built-ins cannot be deleted.
     *
     * @return true|WP_Error
     */
    public static function delete( string $id ) {
        if ( self::is_builtin_id( $id ) ) {
            return new WP_Error( 'builtin', __( 'Built-in templates cannot be deleted.', 'presshub-ai-editor' ) );
        }
        $custom = self::get_custom_templates();
        $kept   = array_values( array_filter( $custom, static function ( $tpl ) use ( $id ) {
            return $tpl['id'] !== $id;
        } ) );
        if ( count( $kept ) === count( $custom ) ) {
            return new WP_Error( 'not_found', __( 'That template no longer exists.', 'presshub-ai-editor' ) );
        }
        update_option( self::OPTION, array_map( [ __CLASS__, 'to_storage' ], $kept ), false );
        return true;
    }

    /**
     * System prompt to pair with a template.
     */
    public static function system_prompt_for( string $id ): string {
        return self::DEFAULT_ID === $id ? self::PHOTO_SYSTEM_PROMPT : self::GENERIC_SYSTEM_PROMPT;
    }

    /**
     * Fill {title} and {content}. A template without {content} still gets
     * the article appended, so a hand-written template can never draft blind.
     */
    public static function render( string $template, string $title, string $content ): string {
        if ( false === strpos( $template, '{content}' ) ) {
            $template = rtrim( $template ) . "\n\nArticle Title:\n{title}\n\nArticle Content:\n{content}";
        }
        return str_replace( [ '{title}', '{content}' ], [ trim( $title ), trim( $content ) ], $template );
    }

    private static function to_storage( array $tpl ): array {
        return [
            'id'       => $tpl['id'],
            'name'     => $tpl['name'],
            'template' => $tpl['template'],
            'author'   => (int) ( $tpl['author'] ?? 0 ),
            'created'  => (int) ( $tpl['created'] ?? 0 ),
        ];
    }

    private static function load_file( string $relative ): string {
        if ( ! class_exists( 'PressHub_AI_Prompt_Loader' ) ) {
            require_once __DIR__ . '/class-prompt-loader.php';
        }
        try {
            return (string) PressHub_AI_Prompt_Loader::load( $relative );
        } catch ( Throwable $t ) {
            return '';
        }
    }
}
