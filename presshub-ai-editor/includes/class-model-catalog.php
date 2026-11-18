<?php
/**
 * Known AI models and what each one can do (#236).
 *
 * One list, kept in the plugin and updated with releases. The AI models
 * table offers these per provider and task, plus "Other model ID…" for a
 * model that is not listed yet.
 *
 *  - A model known to be of the wrong kind for a task (a speech model on a
 *    text task, #169) is refused.
 *  - An unknown model ID is accepted with a warning, so a model can be used
 *    the day it ships.
 *
 * @package presshub-ai-editor
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_Model_Catalog {

    const TEXT   = 'text';
    const SPEECH = 'speech';
    const IMAGE  = 'image';
    const MUSIC  = 'music';
    const VIDEO  = 'video';
    const EMBED  = 'embedding';

    /**
     * Curated models per provider type => kind. Text models first, the
     * provider's best general model at the top.
     */
    const MODELS = [
        'gemini'        => [
            'gemini-3.1-pro'               => self::TEXT,
            'gemini-3.1-flash'             => self::TEXT,
            'gemini-2.5-pro'               => self::TEXT,
            'gemini-2.5-flash'             => self::TEXT,
            'gemini-2.5-flash-lite'        => self::TEXT,
            'gemini-2.0-flash'             => self::TEXT,
            'gemini-1.5-pro-latest'        => self::TEXT,
            'gemini-1.5-flash-latest'      => self::TEXT,
            'gemini-3.1-flash-tts-preview' => self::SPEECH,
            'gemini-2.5-flash-preview-tts' => self::SPEECH,
            'gemini-2.5-pro-preview-tts'   => self::SPEECH,
            'gemini-3.1-flash-image'       => self::IMAGE,
            'gemini-2.5-flash-image'       => self::IMAGE,
            'imagen-4.0-generate-001'      => self::IMAGE,
            'lyria-3-clip-preview'         => self::MUSIC,
            'lyria-3.5'                    => self::MUSIC,
        ],
        'openai'        => [
            'gpt-4.1'         => self::TEXT,
            'gpt-4.1-mini'    => self::TEXT,
            'gpt-4o'          => self::TEXT,
            'gpt-4o-mini'     => self::TEXT,
            'gpt-4-turbo'     => self::TEXT,
            'o1'              => self::TEXT,
            'o3-mini'         => self::TEXT,
            'dall-e-3'        => self::IMAGE,
            'gpt-image-1'     => self::IMAGE,
            'tts-1'           => self::SPEECH,
            'tts-1-hd'        => self::SPEECH,
            'gpt-4o-mini-tts' => self::SPEECH,
        ],
        'anthropic'     => [
            'claude-3-5-sonnet-20241022' => self::TEXT,
            'claude-3-5-sonnet-20240620' => self::TEXT,
            'claude-3-5-haiku-20241022'  => self::TEXT,
            'claude-3-opus-20240229'     => self::TEXT,
        ],
        'groq'          => [
            'llama-3.3-70b-versatile' => self::TEXT,
            'llama-3.1-8b-instant'    => self::TEXT,
            'mixtral-8x7b-32768'      => self::TEXT,
        ],
        'mistral'       => [
            'mistral-large-latest'  => self::TEXT,
            'mistral-medium-latest' => self::TEXT,
            'mistral-small-latest'  => self::TEXT,
            'codestral-latest'      => self::TEXT,
        ],
        'deepseek'      => [
            'deepseek-chat'     => self::TEXT,
            'deepseek-reasoner' => self::TEXT,
        ],
        'ollama_local'  => [
            'llama3:latest'  => self::TEXT,
            'mistral:latest' => self::TEXT,
            'qwen2.5:latest' => self::TEXT,
            'phi3:latest'    => self::TEXT,
        ],
    ];

    /** "a speech model", "an image model": a kind as a phrase, for messages. */
    public static function kind_phrase( string $kind ): string {
        $phrases = [
            self::TEXT   => __( 'a text model', 'presshub-ai-editor' ),
            self::SPEECH => __( 'a speech model', 'presshub-ai-editor' ),
            self::IMAGE  => __( 'an image model', 'presshub-ai-editor' ),
            self::MUSIC  => __( 'a music model', 'presshub-ai-editor' ),
            self::VIDEO  => __( 'a video model', 'presshub-ai-editor' ),
            self::EMBED  => __( 'an embedding model', 'presshub-ai-editor' ),
        ];
        return $phrases[ $kind ] ?? $kind;
    }

    /** Name of each kind, for messages. */
    public static function kind_label( string $kind ): string {
        $labels = [
            self::TEXT   => __( 'text', 'presshub-ai-editor' ),
            self::SPEECH => __( 'speech', 'presshub-ai-editor' ),
            self::IMAGE  => __( 'image', 'presshub-ai-editor' ),
            self::MUSIC  => __( 'music', 'presshub-ai-editor' ),
            self::VIDEO  => __( 'video', 'presshub-ai-editor' ),
            self::EMBED  => __( 'embedding', 'presshub-ai-editor' ),
        ];
        return $labels[ $kind ] ?? $kind;
    }

    /** Strip the "models/" prefix some APIs return. */
    public static function normalize( string $model ): string {
        return (string) preg_replace( '#^models/#', '', trim( $model ) );
    }

    /**
     * Kind of a model: the curated list first, then the model ID's own
     * pattern. Null when nothing is known about it.
     */
    public static function kind_of( string $model ): ?string {
        $model = strtolower( self::normalize( $model ) );
        if ( '' === $model ) {
            return null;
        }
        foreach ( self::MODELS as $models ) {
            if ( isset( $models[ $model ] ) ) {
                return $models[ $model ];
            }
        }
        return self::kind_by_pattern( $model );
    }

    /** Kind read from the model ID alone (the #169 rule), or null. */
    public static function kind_by_pattern( string $model ): ?string {
        $model = strtolower( self::normalize( $model ) );
        $b     = '(^|[-_.\/:])';
        $e     = '([-_.\/:]|$)';
        if ( preg_match( "/{$b}tts{$e}/", $model ) || 0 === strpos( $model, 'tts-' ) ) {
            return self::SPEECH;
        }
        if ( preg_match( "/{$b}(image|imagen)/", $model ) || 0 === strpos( $model, 'dall-e' ) || 0 === strpos( $model, 'gpt-image' ) ) {
            return self::IMAGE;
        }
        if ( preg_match( "/{$b}lyria/", $model ) ) {
            return self::MUSIC;
        }
        if ( preg_match( "/{$b}veo{$e}/", $model ) || 0 === strpos( $model, 'veo-' ) ) {
            return self::VIDEO;
        }
        if ( preg_match( "/{$b}(embedding|embed){$e}/", $model ) || false !== strpos( $model, 'embedding' ) ) {
            return self::EMBED;
        }
        return null;
    }

    /** Whether the catalogue (or the provider's own model list) names this model. */
    public static function is_known( string $model, ?array $provider = null ): bool {
        $model = strtolower( self::normalize( $model ) );
        foreach ( self::MODELS as $models ) {
            if ( isset( $models[ $model ] ) ) {
                return true;
            }
        }
        if ( $provider ) {
            foreach ( (array) ( $provider['available_models'] ?? [] ) as $m ) {
                if ( strtolower( self::normalize( (string) $m ) ) === $model ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Models of one kind for a provider record: the curated ones for its type,
     * then the provider's own list and default model when they are of that
     * kind (or, for text, when nothing marks them as another kind).
     *
     * @return string[]
     */
    public static function models_for( array $provider, string $kind ): array {
        $type = (string) ( $provider['type'] ?? '' );
        $out  = [];
        foreach ( self::MODELS[ $type ] ?? [] as $model => $k ) {
            if ( $k === $kind ) {
                $out[] = $model;
            }
        }
        $own = array_merge( [ (string) ( $provider['default_model'] ?? '' ) ], (array) ( $provider['available_models'] ?? [] ) );
        foreach ( $own as $m ) {
            $m = self::normalize( (string) $m );
            if ( '' === $m || 'default' === $m || in_array( $m, $out, true ) ) {
                continue;
            }
            $k = self::kind_of( $m );
            if ( $k === $kind || ( null === $k && self::TEXT === $kind ) ) {
                $out[] = $m;
            }
        }
        return $out;
    }

    /**
     * Check a model against the kind a task needs.
     *
     * @return array{status:string,message:string} status: ok | warning | blocked
     */
    public static function check( string $kind, string $model, ?array $provider = null ): array {
        $model = self::normalize( $model );
        if ( '' === $model ) {
            return [ 'status' => 'ok', 'message' => '' ];
        }
        $found = self::kind_of( $model );
        if ( null !== $found && $found !== $kind ) {
            return [
                'status'  => 'blocked',
                /* translators: 1: model ID, 2: its kind (e.g. "a speech model"), 3: the kind the task needs (e.g. "a text model") */
                'message' => sprintf( __( '%1$s is %2$s. Pick %3$s.', 'presshub-ai-editor' ), $model, self::kind_phrase( $found ), self::kind_phrase( $kind ) ),
            ];
        }
        if ( ! self::is_known( $model, $provider ) ) {
            return [
                'status'  => 'warning',
                /* translators: 1: model ID, 2: kind (e.g. "text") */
                'message' => sprintf( __( 'PressHub doesn\'t know %1$s yet. Check that it supports %2$s.', 'presshub-ai-editor' ), $model, self::kind_label( $kind ) ),
            ];
        }
        return [ 'status' => 'ok', 'message' => '' ];
    }
}
