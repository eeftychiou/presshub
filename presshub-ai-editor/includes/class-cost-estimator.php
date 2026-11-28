<?php
/**
 * PressHub_AI_Cost_Estimator — estimated USD cost of a token-log row (2.90.0).
 *
 * Costs are worked out when the log is read, from the tokens, characters and images
 * already stored, so every row logged before this class existed gets a cost too.
 * Nothing is stored and nothing is sent anywhere.
 *
 * Prices are the Gemini Developer API paid tier, Standard (not Batch or Flex), from
 * https://ai.google.dev/gemini-api/docs/pricing as last updated on 2026-06-02, and the
 * Google Cloud Text-to-Speech per-character prices. They are list prices: free-tier
 * allowances, credits and taxes are not taken into account, so the provider's billing
 * page stays the real bill. Change or add prices with the `presshub_ai_cost_prices`
 * filter (same shape as default_prices()).
 *
 * What is counted:
 * - Text calls: prompt tokens at the input price, cached prompt tokens
 *   (metadata.cached_tokens) at the cache price, completion tokens plus thinking tokens
 *   (metadata.thoughts_tokens, which Gemini bills as output but does not include in
 *   candidatesTokenCount) at the output price. Prompts over 200k tokens use the long
 *   prices where the model has them. Social video transcription prompts are mostly audio
 *   and use the audio input price.
 * - Gemini TTS: from the response's token counts (metadata.usage, logged from 2.90.0);
 *   older rows only have characters and are estimated (CHARS_PER_TEXT_TOKEN,
 *   AUDIO_TOKENS_PER_CHAR) and flagged as estimates. Failed TTS requests cost nothing.
 * - Google Cloud TTS: characters at the voice type's price (Chirp 3 HD, Studio, Neural2,
 *   WaveNet, Standard).
 * - Images: a price per successful image for the model (1K for Gemini image models,
 *   the size the plugin asks for; Imagen calls are logged from 2.90.1).
 * - TTS from a provider other than Gemini or Google Cloud is unpriced.
 * - A Gemini model that is not on Google's price page yet (e.g. gemini-3.8-flash) is
 *   priced like the listed model of its family (FAMILY_FALLBACK) and marked ≈ (2.91.1).
 *   A prefix only matches the same variant, so a -lite, -image or -tts model is never
 *   priced as the full model.
 * - Podcast audio rows logged under a Gemini voice name (Kore, Charon...) before 2.72.0
 *   repeat calls already logged under the model, and cost nothing here.
 * - Free: the web scraper, the podcast_audio_run summary row and the "stitched" podcast
 *   audio rows (their characters are already on the per-turn rows).
 *
 * @package PressHub_AI_Editor
 * @since 2.90.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Cost_Estimator {

    /** Date of the price list in default_prices(). */
    const PRICES_AS_OF = '2026-06-02';

    /** Prompt size above which models with long-context prices cost more. */
    const LONG_CONTEXT_TOKENS = 200000;

    /** Greek text in Gemini's tokenizer, about three characters per token. */
    const CHARS_PER_TEXT_TOKEN = 3.0;

    /**
     * Audio tokens per character of script: Gemini TTS audio is 25 tokens per second and
     * a Greek newsreader speaks about 17.5 characters per second.
     */
    const AUDIO_TOKENS_PER_CHAR = 1.43;

    /**
     * Listed models that price a Gemini model Google's page does not list yet (2.91.1),
     * by family. The cost is then marked as an estimate (≈).
     */
    const FAMILY_FALLBACK = [
        'image'     => 'gemini-3.1-flash-image',
        'tts'       => 'gemini-3.1-flash-tts',
        'pro'       => 'gemini-3.1-pro',
        'flash-lite' => 'gemini-3.1-flash-lite',
        'flash'     => 'gemini-3.5-flash',
    ];

    /** Gemini TTS model assumed for a TTS row whose model field holds a voice name. */
    const DEFAULT_TTS_MODEL = 'gemini-3.1-flash-tts';

    /** Google Cloud TTS price for a voice whose type is not recognised (Neural2/WaveNet). */
    const DEFAULT_CLOUD_TTS_PER_M_CHARS = 16.0;

    /** @var array|null Cached price table. */
    private static $prices = null;

    /**
     * The price table, in USD.
     *
     * - text:  model prefix => [ in, out, cache, in_long?, out_long?, cache_long?, audio_in? ] per 1M tokens.
     * - tts:   model prefix => [ in (text), out (audio) ] per 1M tokens.
     * - image: model prefix => price per image.
     * - cloud_tts: voice type (matched in the voice name, in this order) => per 1M characters.
     *
     * The longest matching prefix wins, so `gemini-2.5-flash-lite` beats `gemini-2.5-flash`.
     */
    public static function default_prices(): array {
        return [
            'text'  => [
                'gemini-3.5-flash'      => [ 'in' => 1.50, 'out' => 9.00, 'cache' => 0.15 ],
                'gemini-3.1-pro'        => [ 'in' => 2.00, 'out' => 12.00, 'cache' => 0.20, 'in_long' => 4.00, 'out_long' => 18.00, 'cache_long' => 0.40 ],
                'gemini-3-pro'          => [ 'in' => 2.00, 'out' => 12.00, 'cache' => 0.20, 'in_long' => 4.00, 'out_long' => 18.00, 'cache_long' => 0.40 ],
                'gemini-3.1-flash-lite' => [ 'in' => 0.25, 'out' => 1.50, 'cache' => 0.025, 'audio_in' => 0.50 ],
                // Not on Google's list as a text model; priced like Gemini 3 Flash.
                'gemini-3.1-flash'      => [ 'in' => 0.50, 'out' => 3.00, 'cache' => 0.05, 'audio_in' => 1.00 ],
                'gemini-3-flash'        => [ 'in' => 0.50, 'out' => 3.00, 'cache' => 0.05, 'audio_in' => 1.00 ],
                'gemini-2.5-pro'        => [ 'in' => 1.25, 'out' => 10.00, 'cache' => 0.125, 'in_long' => 2.50, 'out_long' => 15.00, 'cache_long' => 0.25 ],
                'gemini-2.5-flash-lite' => [ 'in' => 0.10, 'out' => 0.40, 'cache' => 0.01, 'audio_in' => 0.30 ],
                'gemini-2.5-flash'      => [ 'in' => 0.30, 'out' => 2.50, 'cache' => 0.03, 'audio_in' => 1.00 ],
                'gemini-2.0-flash-lite' => [ 'in' => 0.075, 'out' => 0.30, 'cache' => 0.075 ],
                'gemini-2.0-flash'      => [ 'in' => 0.10, 'out' => 0.40, 'cache' => 0.025, 'audio_in' => 0.70 ],
            ],
            'tts'   => [
                'gemini-3.1-flash-tts'        => [ 'in' => 1.00, 'out' => 20.00 ],
                'gemini-2.5-flash-preview-tts' => [ 'in' => 0.50, 'out' => 10.00 ],
                'gemini-2.5-flash-tts'        => [ 'in' => 0.50, 'out' => 10.00 ],
                'gemini-2.5-pro-preview-tts'  => [ 'in' => 1.00, 'out' => 20.00 ],
                'gemini-2.5-pro-tts'          => [ 'in' => 1.00, 'out' => 20.00 ],
            ],
            'image' => [
                'gemini-3.1-flash-image' => 0.067,
                'gemini-3-pro-image'     => 0.134,
                'gemini-2.5-flash-image' => 0.039,
                'imagen-4.0-fast'        => 0.02,
                'imagen-4.0-ultra'       => 0.06,
                'imagen-4.0'             => 0.04,
                'imagen-3.0'             => 0.04,
            ],
            'cloud_tts' => [
                'chirp3-hd' => 30.0,
                'chirp-hd'  => 30.0,
                'studio'    => 160.0,
                'neural2'   => 16.0,
                'wavenet'   => 16.0,
                'polyglot'  => 16.0,
                'news'      => 16.0,
                'standard'  => 4.0,
            ],
        ];
    }

    /**
     * The price table after the `presshub_ai_cost_prices` filter.
     */
    public static function prices(): array {
        if ( null === self::$prices ) {
            $prices = self::default_prices();
            if ( function_exists( 'apply_filters' ) ) {
                $filtered = apply_filters( 'presshub_ai_cost_prices', $prices );
                if ( is_array( $filtered ) ) {
                    $prices = $filtered;
                }
            }
            self::$prices = $prices;
        }
        return self::$prices;
    }

    /** Forget the cached price table (tests). */
    public static function reset(): void {
        self::$prices = null;
    }

    /**
     * Estimated cost of one token-log row.
     *
     * @param array $row Row of the token-log table (metadata as JSON text or array).
     * @return array{usd: float, priced: bool, estimated: bool, basis: string, thoughts_tokens: int}
     *               basis: text | tts | cloud_tts | image | free | none (nothing billed) | unpriced.
     *               thoughts_tokens: Gemini thinking tokens billed on a text row (0 otherwise).
     */
    public static function estimate( array $row ): array {
        $action    = (string) ( $row['action_trigger'] ?? '' );
        $provider  = strtolower( (string) ( $row['provider'] ?? '' ) );
        $model     = self::normalize_model( (string) ( $row['model'] ?? '' ) );
        $prompt    = max( 0, (int) ( $row['prompt_tokens'] ?? 0 ) );
        $output    = max( 0, (int) ( $row['completion_tokens'] ?? 0 ) );
        $units     = max( 0, (int) ( $row['metric_units'] ?? 0 ) );
        $is_ok     = 'error' !== strtolower( (string) ( $row['status'] ?? 'success' ) );
        $note      = (string) ( $row['error_message'] ?? '' );
        $meta      = self::metadata( $row['metadata'] ?? null );

        // Free work and summary rows whose cost is already on other rows.
        if ( 'scraper' === $provider || 'run' === $model || '_run' === substr( $action, -4 )
            || 'topic_stitched' === $model || 'whole_script_stitched' === $note || preg_match( '/^\d+_topics$/', $note ) ) {
            return self::result( 0.0, true, false, 'free' );
        }

        // Images: one price per image that came back.
        if ( 'generate_featured_image' === $action || self::is_image_model( $model ) ) {
            if ( ! $is_ok ) {
                return self::result( 0.0, true, false, 'none' );
            }
            $price = self::match( self::prices()['image'] ?? [], $model );
            $guess = false;
            if ( null === $price && null !== ( $family = self::family_model( $model ) ) ) {
                $price = self::match( self::prices()['image'] ?? [], $family );
                $guess = null !== $price;
            }
            return null === $price ? self::result( 0.0, false, false, 'unpriced' ) : self::result( (float) $price, true, $guess, 'image' );
        }

        // Text-to-speech: rows with characters and no tokens, or a TTS model.
        $is_tts = false !== strpos( $model, 'tts' )
            || ( 0 === $prompt && 0 === $output && $units > 0
                && ( false !== stripos( $action, 'audio' ) || false !== stripos( $action, 'tts' ) || false !== stripos( $action, 'narrat' )
                    || in_array( $provider, [ 'gemini', 'google_cloud', 'google_cloud_tts', 'gemini_tts' ], true ) ) );
        if ( $is_tts ) {
            if ( ! $is_ok ) {
                return self::result( 0.0, true, false, 'none' );
            }
            // Before 2.72.0 the podcast synthesizer logged every Gemini turn a second time,
            // under the voice name (Kore, Charon...), next to the API client's row under the
            // model. Those rows repeat calls already counted.
            if ( 'podcast_audio' === $action && in_array( $provider, [ 'gemini', 'gemini_tts' ], true ) && 0 !== strpos( $model, 'gemini' ) ) {
                return self::result( 0.0, true, false, 'duplicate' );
            }
            if ( in_array( $provider, [ 'google_cloud', 'google_cloud_tts' ], true ) ) {
                return self::cloud_tts( $model, $units );
            }
            if ( in_array( $provider, [ '', 'gemini', 'gemini_tts' ], true ) ) {
                return self::gemini_tts( $model, $units, $meta );
            }
            // TTS from another provider (e.g. OpenAI tts-1): no price known.
            return self::result( 0.0, false, false, 'unpriced' );
        }

        if ( 0 === $prompt && 0 === $output ) {
            return self::result( 0.0, true, false, 'none' );
        }

        $price = self::match( self::prices()['text'] ?? [], $model );
        $guess = false;
        if ( null === $price && null !== ( $family = self::family_model( $model ) ) ) {
            $price = self::match( self::prices()['text'] ?? [], $family );
            $guess = null !== $price;
        }
        if ( null === $price ) {
            return self::result( 0.0, false, false, 'unpriced' );
        }
        $cached   = min( $prompt, max( 0, (int) ( $meta['cached_tokens'] ?? 0 ) ) );
        $thoughts = max( 0, (int) ( $meta['thoughts_tokens'] ?? 0 ) );
        $long     = $prompt > self::LONG_CONTEXT_TOKENS;
        $in_rate  = (float) ( $long && isset( $price['in_long'] ) ? $price['in_long'] : ( $price['in'] ?? 0 ) );
        if ( 'social_video_transcribe' === $action && isset( $price['audio_in'] ) ) {
            $in_rate = (float) $price['audio_in'];
        }
        $out_rate   = (float) ( $long && isset( $price['out_long'] ) ? $price['out_long'] : ( $price['out'] ?? 0 ) );
        $cache_rate = (float) ( $long && isset( $price['cache_long'] ) ? $price['cache_long'] : ( $price['cache'] ?? $in_rate ) );

        $usd = ( ( $prompt - $cached ) * $in_rate + $cached * $cache_rate + ( $output + $thoughts ) * $out_rate ) / 1000000;
        return self::result( $usd, true, $guess, 'text', $thoughts );
    }

    /**
     * Gemini TTS: the response's token counts when logged, else an estimate from characters.
     */
    private static function gemini_tts( string $model, int $chars, array $meta ): array {
        $table = self::prices()['tts'] ?? [];
        $price = self::match( $table, $model );
        $guess = false;
        if ( null === $price ) {
            // A TTS model Google does not list yet, or a voice name in the model field: assume the default model.
            $price = self::match( $table, self::DEFAULT_TTS_MODEL );
            $guess = true;
        }
        if ( null === $price ) {
            return self::result( 0.0, false, false, 'unpriced' );
        }
        $usage = is_array( $meta['usage'] ?? null ) ? $meta['usage'] : [];
        if ( isset( $usage['prompt_tokens'] ) || isset( $usage['output_tokens'] ) ) {
            $in  = max( 0, (int) ( $usage['prompt_tokens'] ?? 0 ) );
            $out = max( 0, (int) ( $usage['output_tokens'] ?? 0 ) );
        } else {
            $in    = (int) round( $chars / self::CHARS_PER_TEXT_TOKEN );
            $out   = (int) round( $chars * self::AUDIO_TOKENS_PER_CHAR );
            $guess = true;
        }
        $usd = ( $in * (float) ( $price['in'] ?? 0 ) + $out * (float) ( $price['out'] ?? 0 ) ) / 1000000;
        return self::result( $usd, true, $guess, 'tts' );
    }

    /**
     * Google Cloud TTS: characters at the voice type's price.
     */
    private static function cloud_tts( string $voice, int $chars ): array {
        $rate  = null;
        foreach ( (array) ( self::prices()['cloud_tts'] ?? [] ) as $type => $per_m ) {
            if ( '' !== (string) $type && false !== stripos( $voice, (string) $type ) ) {
                $rate = (float) $per_m;
                break;
            }
        }
        $guess = null === $rate;
        $rate  = $rate ?? self::DEFAULT_CLOUD_TTS_PER_M_CHARS;
        return self::result( $chars * $rate / 1000000, true, $guess, 'cloud_tts' );
    }

    /**
     * Value of the longest key of $table that $model starts with, or null.
     */
    private static function match( array $table, string $model ) {
        $best  = null;
        $len   = 0;
        $flags = self::variant_flags( $model );
        foreach ( $table as $prefix => $value ) {
            $prefix = strtolower( (string) $prefix );
            // A prefix of another variant does not match: gemini-3.5-flash is not gemini-3.5-flash-lite.
            if ( '' !== $prefix && 0 === strpos( $model, $prefix ) && strlen( $prefix ) > $len && self::variant_flags( $prefix ) === $flags ) {
                $best = $value;
                $len  = strlen( $prefix );
            }
        }
        return $best;
    }

    /**
     * The variant words of a model id that change its price.
     */
    private static function variant_flags( string $model ): array {
        return [
            'lite'  => false !== strpos( $model, '-lite' ),
            'image' => false !== strpos( $model, '-image' ),
            'tts'   => false !== strpos( $model, '-tts' ),
        ];
    }

    /**
     * The listed model that prices an unlisted Gemini model of the same family, or null.
     */
    public static function family_model( string $model ): ?string {
        $model = self::normalize_model( $model );
        if ( 0 !== strpos( $model, 'gemini' ) ) {
            return null;
        }
        $f = self::FAMILY_FALLBACK;
        if ( false !== strpos( $model, '-image' ) ) {
            return $f['image'];
        }
        if ( false !== strpos( $model, '-tts' ) ) {
            return $f['tts'];
        }
        if ( false !== strpos( $model, '-pro' ) ) {
            return $f['pro'];
        }
        if ( false !== strpos( $model, 'flash-lite' ) ) {
            return $f['flash-lite'];
        }
        if ( false !== strpos( $model, 'flash' ) ) {
            return $f['flash'];
        }
        return null;
    }

    private static function is_image_model( string $model ): bool {
        return 0 === strpos( $model, 'imagen' ) || ( false !== strpos( $model, '-image' ) && 0 === strpos( $model, 'gemini' ) );
    }

    private static function normalize_model( string $model ): string {
        return strtolower( trim( (string) preg_replace( '#^models/#', '', trim( $model ) ) ) );
    }

    /**
     * @param mixed $raw JSON text or array.
     */
    private static function metadata( $raw ): array {
        if ( is_array( $raw ) ) {
            return $raw;
        }
        if ( is_string( $raw ) && '' !== $raw ) {
            $decoded = json_decode( $raw, true );
            return is_array( $decoded ) ? $decoded : [];
        }
        return [];
    }

    private static function result( float $usd, bool $priced, bool $estimated, string $basis, int $thoughts = 0 ): array {
        return [
            'usd'             => round( max( 0.0, $usd ), 6 ),
            'priced'          => $priced,
            'estimated'       => $estimated,
            'basis'           => $basis,
            'thoughts_tokens' => $thoughts,
        ];
    }
}
