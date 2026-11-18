<?php
/**
 * The eight sections of the one PressHub Settings page (#246, settings
 * overhaul phase 3b).
 *
 *   PressHub ▸ Settings                 admin.php?page=presshub-ai&section=<slug>
 *   ├─ Connections          connections
 *   ├─ AI models            ai-models
 *   ├─ Writing & QA         writing-qa
 *   ├─ Daily Briefing       daily-briefing
 *   ├─ Editorial knowledge  editorial-knowledge
 *   ├─ Social publishing    social-publishing
 *   ├─ Social listening     social-listening
 *   └─ Site & system        site-system
 *
 * Each section is its own page load with its own Save. This class says which
 * section holds every registry option (section_of()), so the section's Save
 * writes only its own options, and where every old tab of the two former
 * settings pages went (legacy_redirects()). Stored option names and their
 * option groups do not change.
 *
 * @package presshub-ai-editor
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-capabilities.php';

class PressHub_Settings_Sections {

    /** Admin page slug of the one Settings page (unchanged from 3a). */
    const PAGE_SLUG = 'presshub-ai';

    /** Section shown when the URL names none. */
    const DEFAULT_SECTION = 'connections';

    /** Old tab of each former settings page => section that holds its options. */
    const TAB_SECTIONS = [
        'ai'     => [
            'providers'        => 'connections',
            'copilot'          => 'ai-models',
            'coauthor'         => 'writing-qa',
            'images'           => 'writing-qa',
            'briefing'         => 'daily-briefing',
            'knowledge'        => 'editorial-knowledge',
            'social_listening' => 'social-listening',
            'advanced'         => 'site-system',
        ],
        'social' => [
            'general'     => 'social-publishing',
            'ai'          => 'social-publishing',
            'shorts'      => 'social-publishing',
            'queue'       => 'social-publishing',
            'prompts'     => 'social-publishing',
            'credentials' => 'connections',
            'worker'      => 'connections',
        ],
    ];

    /** WordPress settings sections (registry 'section') that moved on their own. */
    const WP_SECTION_SECTIONS = [
        'presshub_ai_github' => 'connections',
        'presshub_ai_media'  => 'connections',
    ];

    /** Single options whose home is not their old tab's section. */
    const KEY_SECTIONS = [
        'presshub_ai_remove_github_token'          => 'connections',
        'presshub_ai_remove_google_cloud_api_key'  => 'connections',
        'presshub_social_github_token'             => 'connections',
        // #247: every secret is in Connections.
        'presshub_ai_briefing_tts_api_key'         => 'connections',
        'presshub_ai_remove_briefing_tts_api_key'  => 'connections',
        // #247: the press inbox login is in Connections; folders and filters stay in Daily Briefing.
        'presshub_ai_press_inbox_host'             => 'connections',
        'presshub_ai_press_inbox_port'             => 'connections',
        'presshub_ai_press_inbox_encryption'       => 'connections',
        'presshub_ai_press_inbox_verify_ssl'       => 'connections',
        'presshub_ai_press_inbox_username'         => 'connections',
        'presshub_ai_press_inbox_password'         => 'connections',
        'presshub_ai_remove_press_inbox_password'  => 'connections',
        'presshub_ai_press_inbox_timeout'          => 'connections',
        // Site & system → Logging & retention keeps all six retention periods in one table.
        'presshub_ai_memory_retention_days'        => 'site-system',
    ];

    /**
     * Where each old tab lands: section and the group anchor inside it.
     * Used by the 302 redirects (server) and the old #tab hashes (browser).
     */
    const LEGACY_TARGETS = [
        'ai'     => [
            'providers'        => [ 'connections', 'ai-providers' ],
            'copilot'          => [ 'ai-models', 'copilot' ],
            'coauthor'         => [ 'writing-qa', '' ],
            'coauthor-drafting' => [ 'writing-qa', 'drafting' ],
            'coauthor-qa'      => [ 'writing-qa', 'qa-gate' ],
            'coauthor-seo'     => [ 'writing-qa', 'seo' ],
            'qa'               => [ 'writing-qa', 'qa-gate' ],
            'images'           => [ 'writing-qa', 'featured-images' ],
            'briefing'         => [ 'daily-briefing', '' ],
            'social_listening' => [ 'social-listening', '' ],
            'knowledge'        => [ 'editorial-knowledge', '' ],
            'advanced'         => [ 'site-system', '' ],
            'general'          => [ 'writing-qa', '' ],
            'media'            => [ 'connections', 'google-cloud' ],
            'rate_limits'      => [ 'site-system', 'rate-limits' ],
            'logging'          => [ 'site-system', 'logging-retention' ],
            'diagnostics'      => [ 'site-system', 'diagnostics' ],
            'social'           => [ 'site-system', 'link-previews' ],
        ],
        'social' => [
            'general'     => [ 'social-publishing', 'platforms' ],
            'ai'          => [ 'social-publishing', 'post-copy' ],
            'prompts'     => [ 'social-publishing', 'post-copy' ],
            'shorts'      => [ 'social-publishing', 'shorts' ],
            'queue'       => [ 'social-publishing', 'queue' ],
            'credentials' => [ 'connections', 'social-accounts' ],
            'worker'      => [ 'connections', 'automation-worker' ],
        ],
    ];

    /** Other #hashes the former AI page understood (tab aliases) => [ section, anchor ]. */
    const LEGACY_HASH_ALIASES = [
        'scorecard'        => [ 'writing-qa', 'qa-gate' ],
        'scorecards'       => [ 'writing-qa', 'qa-gate' ],
        'editorial-qa'     => [ 'writing-qa', 'qa-gate' ],
        'editorial_qa'     => [ 'writing-qa', 'qa-gate' ],
        'seo'              => [ 'writing-qa', 'seo' ],
        'social-listening' => [ 'social-listening', '' ],
        'memory'           => [ 'editorial-knowledge', 'knowledge-memory' ],
    ];

    /**
     * The sections, in menu order: label, one-line intro and the groups shown
     * in the "On this page" bar (group anchor => label). 'social' marks a
     * section or group that needs the Social capability as well.
     *
     * @return array<string,array{label:string,intro:string,groups:array<string,string>,social?:bool,social_groups?:string[]}>
     */
    public static function definitions(): array {
        return [
            'connections'         => [
                'label'         => __( 'Connections', 'presshub-ai-editor' ),
                'intro'         => __( 'Every key, password and account PressHub signs in with, whether each one works, and what to fix first.', 'presshub-ai-editor' ),
                'groups'        => [
                    'ai-providers'      => __( 'AI providers', 'presshub-ai-editor' ),
                    'google-cloud'      => __( 'Google Cloud', 'presshub-ai-editor' ),
                    'press-inbox'       => __( 'Press inbox', 'presshub-ai-editor' ),
                    'social-accounts'   => __( 'Social platform accounts', 'presshub-ai-editor' ),
                    'automation-worker' => __( 'Automation worker', 'presshub-ai-editor' ),
                    'plugin-updates'    => __( 'Plugin updates', 'presshub-ai-editor' ),
                ],
                'social_groups' => [ 'social-accounts', 'automation-worker' ],
            ],
            'ai-models'           => [
                'label'  => __( 'AI models', 'presshub-ai-editor' ),
                'intro'  => __( 'The model behind the Copilot and the research assistant. AI provider keys are in Connections.', 'presshub-ai-editor' ),
                'groups' => [
                    'copilot' => __( 'Copilot & research assistant', 'presshub-ai-editor' ),
                ],
            ],
            'writing-qa'          => [
                'label'  => __( 'Writing & QA', 'presshub-ai-editor' ),
                'intro'  => __( 'How the Co-Author drafts, how the QA gate reviews articles before publication, SEO and tags, and featured images.', 'presshub-ai-editor' ),
                'groups' => [
                    'drafting'        => __( 'Co-author drafting', 'presshub-ai-editor' ),
                    'qa-gate'         => __( 'QA gate', 'presshub-ai-editor' ),
                    'seo'             => __( 'SEO & tags', 'presshub-ai-editor' ),
                    'featured-images' => __( 'Featured images', 'presshub-ai-editor' ),
                    'presets'         => __( 'Presets', 'presshub-ai-editor' ),
                ],
            ],
            'daily-briefing'      => [
                'label'  => __( 'Daily Briefing', 'presshub-ai-editor' ),
                'intro'  => __( 'The morning and noon editions: when they run, what they read, the text story, the podcast and how both are published.', 'presshub-ai-editor' ),
                'groups' => [
                    'briefing-schedule'   => __( 'Schedule', 'presshub-ai-editor' ),
                    'briefing-sources'    => __( 'Sources & harvesting', 'presshub-ai-editor' ),
                    'briefing-text'       => __( 'Text story', 'presshub-ai-editor' ),
                    'briefing-podcast'    => __( 'Podcast script', 'presshub-ai-editor' ),
                    'briefing-voices'     => __( 'Voices & audio', 'presshub-ai-editor' ),
                    'briefing-publishing' => __( 'Publishing', 'presshub-ai-editor' ),
                ],
            ],
            'editorial-knowledge' => [
                'label'  => __( 'Editorial knowledge', 'presshub-ai-editor' ),
                'intro'  => __( 'What PressHub adds to every AI call on top of the prompt: policy and people fiches, claim checks, the article pool and editorial memory.', 'presshub-ai-editor' ),
                'groups' => [
                    'knowledge-fiches' => __( 'Fiches and the article pool', 'presshub-ai-editor' ),
                    'knowledge-memory' => __( 'Editorial memory', 'presshub-ai-editor' ),
                ],
            ],
            'social-publishing'   => [
                'label'  => __( 'Social publishing', 'presshub-ai-editor' ),
                'intro'  => __( 'Which platforms get a post when an article is published, how the post copy is written, its images and video, Shorts & Reels, and the dispatch queue.', 'presshub-ai-editor' ),
                'groups' => [
                    'platforms'    => __( 'Platforms', 'presshub-ai-editor' ),
                    'post-copy'    => __( 'Post copy', 'presshub-ai-editor' ),
                    'images-video' => __( 'Images & video', 'presshub-ai-editor' ),
                    'shorts'       => __( 'Shorts & Reels', 'presshub-ai-editor' ),
                    'queue'        => __( 'Queue & alerts', 'presshub-ai-editor' ),
                ],
                'social' => true,
            ],
            'social-listening'    => [
                'label'  => __( 'Social listening', 'presshub-ai-editor' ),
                'intro'  => __( 'Read posts from monitored social accounts into the briefing, and publish the Trending in Cyprus roundup.', 'presshub-ai-editor' ),
                'groups' => [
                    'social-listening-monitoring' => __( 'Account monitoring', 'presshub-ai-editor' ),
                    'social-listening-trending'   => __( 'Trending in Cyprus', 'presshub-ai-editor' ),
                ],
            ],
            'site-system'         => [
                'label'  => __( 'Site & system', 'presshub-ai-editor' ),
                'intro'  => __( 'Link previews for shared articles, how long logs and records are kept, rate limits and diagnostics.', 'presshub-ai-editor' ),
                'groups' => [
                    'link-previews'     => __( 'Link previews', 'presshub-ai-editor' ),
                    'logging-retention' => __( 'Logging & retention', 'presshub-ai-editor' ),
                    'rate-limits'       => __( 'Rate limits', 'presshub-ai-editor' ),
                    'diagnostics'       => __( 'Diagnostics', 'presshub-ai-editor' ),
                ],
            ],
        ];
    }

    /** @return string[] Section slugs in menu order. */
    public static function slugs(): array {
        return array_keys( self::definitions() );
    }

    public static function exists( string $slug ): bool {
        return in_array( $slug, self::slugs(), true );
    }

    public static function label( string $slug ): string {
        $defs = self::definitions();
        return (string) ( $defs[ $slug ]['label'] ?? $slug );
    }

    /** Whether the current user can use the Social settings (the Social capability). */
    public static function can_social(): bool {
        return function_exists( 'current_user_can' ) && current_user_can( PressHub_Capabilities::social() );
    }

    /** Whether the current user can open a section. */
    public static function can_view( string $slug ): bool {
        if ( ! self::exists( $slug ) ) {
            return false;
        }
        if ( ! PressHub_Capabilities::current_user_can_manage() ) {
            return false;
        }
        $defs = self::definitions();
        return empty( $defs[ $slug ]['social'] ) || self::can_social();
    }

    /** @return string[] Sections the current user can open, in menu order. */
    public static function visible(): array {
        return array_values( array_filter( self::slugs(), [ __CLASS__, 'can_view' ] ) );
    }

    /**
     * Groups of a section that the current user sees (Social groups need the
     * Social capability).
     *
     * @return array<string,string>
     */
    public static function visible_groups( string $slug ): array {
        $defs = self::definitions();
        if ( ! isset( $defs[ $slug ] ) ) {
            return [];
        }
        $groups = $defs[ $slug ]['groups'];
        if ( ! self::can_social() ) {
            foreach ( (array) ( $defs[ $slug ]['social_groups'] ?? [] ) as $g ) {
                unset( $groups[ $g ] );
            }
        }
        return $groups;
    }

    /** The section named in the request, or the first one the user can open. */
    public static function current(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
        $slug = isset( $_GET['section'] ) && is_scalar( $_GET['section'] ) ? strtolower( sanitize_key( wp_unslash( (string) $_GET['section'] ) ) ) : '';
        $slug = preg_replace( '/[^a-z0-9\-]/', '', $slug );
        if ( '' !== $slug && self::can_view( $slug ) ) {
            return $slug;
        }
        $visible = self::visible();
        return $visible[0] ?? self::DEFAULT_SECTION;
    }

    /** admin.php URL of a section, with an optional group or field anchor. */
    public static function url( string $slug, string $anchor = '' ): string {
        $url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&section=' . rawurlencode( $slug ) );
        return '' !== $anchor ? $url . '#' . rawurlencode( $anchor ) : $url;
    }

    // ------------------------------------------------------------------
    // Which section holds each option.
    // ------------------------------------------------------------------

    /**
     * Section that holds a registry option, or '' when nothing maps it (an
     * orphan, which SettingsSectionsTest refuses).
     */
    public static function section_of( string $key, ?array $entry = null ): string {
        if ( null === $entry ) {
            $entry = class_exists( 'PressHub_Settings_Registry' ) ? PressHub_Settings_Registry::get( $key ) : null;
        }
        if ( ! is_array( $entry ) ) {
            return '';
        }
        // Add-ons can name their section in their registry entry.
        if ( ! empty( $entry['settings_section'] ) && self::exists( (string) $entry['settings_section'] ) ) {
            return (string) $entry['settings_section'];
        }
        if ( isset( self::KEY_SECTIONS[ $key ] ) ) {
            return self::KEY_SECTIONS[ $key ];
        }
        $wp_section = (string) ( $entry['section'] ?? '' );
        if ( isset( self::WP_SECTION_SECTIONS[ $wp_section ] ) ) {
            return self::WP_SECTION_SECTIONS[ $wp_section ];
        }
        $page = (string) ( $entry['page'] ?? '' );
        $tab  = (string) ( $entry['tab'] ?? '' );
        return self::TAB_SECTIONS[ $page ][ $tab ] ?? '';
    }

    /**
     * Registry entries held by a section, keyed by option name.
     *
     * @return array<string,array>
     */
    public static function entries( string $slug ): array {
        if ( ! class_exists( 'PressHub_Settings_Registry' ) ) {
            return [];
        }
        $out = [];
        foreach ( PressHub_Settings_Registry::all() as $key => $entry ) {
            if ( self::section_of( (string) $key, $entry ) === $slug ) {
                $out[ $key ] = $entry;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Old URLs.
    // ------------------------------------------------------------------

    /** [ section, anchor ] for an old tab of a former page, or null. */
    public static function legacy_target( string $page, string $tab ): ?array {
        $tab = strtolower( trim( $tab ) );
        $hit = self::LEGACY_TARGETS[ $page ][ $tab ] ?? null;
        return is_array( $hit ) ? [ 'section' => $hit[0], 'anchor' => $hit[1] ] : null;
    }

    /**
     * 302 rules for PressHub_Admin_Menu: every old Social tab and every old
     * AI ?tab= goes to its section. Each rule: file, page, optional tab, to,
     * section and anchor.
     *
     * @return array<int,array<string,string>>
     */
    public static function legacy_redirects(): array {
        $rules = [];
        foreach ( [ 'admin.php', 'options-general.php' ] as $file ) {
            foreach ( self::LEGACY_TARGETS['social'] as $tab => $target ) {
                $rules[] = [ 'file' => $file, 'page' => 'presshub-social-settings', 'tab' => $tab, 'to' => self::PAGE_SLUG, 'section' => $target[0], 'anchor' => $target[1] ];
            }
            // No tab, or a tab that no longer exists: the page opened on General & Engines.
            $rules[] = [ 'file' => $file, 'page' => 'presshub-social-settings', 'to' => self::PAGE_SLUG, 'section' => 'social-publishing', 'anchor' => '', 'drop_tab' => '1' ];
        }
        foreach ( self::LEGACY_TARGETS['ai'] as $tab => $target ) {
            $rules[] = [ 'file' => 'admin.php', 'page' => self::PAGE_SLUG, 'tab' => $tab, 'to' => self::PAGE_SLUG, 'section' => $target[0], 'anchor' => $target[1] ];
        }
        return $rules;
    }

    /**
     * Old #hash of the former AI page (tabs and sub-tabs) => new section URL,
     * for the browser: fragments never reach the server.
     *
     * @return array<string,string>
     */
    public static function legacy_hash_urls(): array {
        $out = [];
        // Group anchors of phase 2 (#briefing-sources, #featured-images…), now in one section each.
        foreach ( self::definitions() as $slug => $def ) {
            foreach ( array_keys( (array) ( $def['groups'] ?? [] ) ) as $group ) {
                $out[ (string) $group ] = self::url( $slug, (string) $group );
            }
        }
        foreach ( self::LEGACY_TARGETS['ai'] as $tab => $target ) {
            $out[ $tab ] = self::url( $target[0], $target[1] );
        }
        foreach ( self::LEGACY_HASH_ALIASES as $hash => $target ) {
            $out[ $hash ] = self::url( $target[0], $target[1] );
        }
        // Token & Usage Logs moved to PressHub → Usage & logs (#235).
        $usage = function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=presshub-ai-usage' ) : '';
        foreach ( [ 'token_logs', 'token_usage', 'usage' ] as $hash ) {
            $out[ $hash ] = $usage;
        }
        return $out;
    }
}
