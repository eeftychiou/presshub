<?php
/**
 * Setup check (#247): what stops PressHub from working right now, each with
 * a link to its fix. Shown at the top of Settings → Connections and as a
 * small box on the Daily Briefing Hub.
 *
 * Built only from stored settings, the worker's last heartbeat and the
 * cached test results in PressHub_Connection_Status, so it makes no remote
 * request.
 *
 * @package presshub-ai-editor
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-connection-status.php';
require_once __DIR__ . '/class-settings-sections.php';

class PressHub_Setup_Check {

    /** A pull worker heard from within this many seconds is running (as in the worker client). */
    const WORKER_ONLINE_SECONDS = 180;

    /**
     * @return array<int,array{text:string,url:string,link:string,level:string}>
     */
    public static function problems( int $now = 0 ): array {
        $now      = $now ?: time();
        $problems = [];
        $add      = static function ( string $text, string $anchor, string $link, string $level = 'error' ) use ( &$problems ) {
            $problems[ $text ] = [
                'text'  => $text,
                'url'   => PressHub_Settings_Sections::url( 'connections', $anchor ),
                'link'  => $link,
                'level' => $level,
            ];
        };

        // AI providers: at least one enabled provider that can be called.
        $providers = [];
        if ( ! class_exists( 'PressHub_AI_Provider_Store' ) && file_exists( __DIR__ . '/class-provider-store.php' ) ) {
            require_once __DIR__ . '/class-provider-store.php';
        }
        if ( class_exists( 'PressHub_AI_Provider_Store' ) ) {
            foreach ( PressHub_AI_Provider_Store::get_all( false ) as $p ) {
                $providers[ (string) ( $p['id'] ?? '' ) ] = $p;
            }
            $usable = array_filter( $providers, static function ( $p ) {
                return ! empty( $p['enabled'] ) && ( ! empty( $p['api_key'] ) || 'ollama_local' === ( $p['type'] ?? '' ) );
            } );
            if ( ! $usable ) {
                $add( __( 'No AI provider has a key, so drafting, QA and the Daily Briefing cannot run.', 'presshub-ai-editor' ), 'ai-providers', __( 'Add a provider key', 'presshub-ai-editor' ) );
            }
        }

        // Press inbox: switched on but the login is incomplete.
        $inbox_on = class_exists( 'PressHub_AI_Settings_Storage' ) && PressHub_AI_Settings_Storage::get_press_inbox_enabled();
        if ( $inbox_on && ( '' === PressHub_AI_Settings_Storage::get_press_inbox_host() || '' === PressHub_AI_Settings_Storage::get_press_inbox_username() || '' === PressHub_AI_Settings_Storage::get_press_inbox_password() ) ) {
            $add( __( 'The press inbox is switched on, but its server, user name or password is missing.', 'presshub-ai-editor' ), 'press-inbox', __( 'Finish the press inbox login', 'presshub-ai-editor' ) );
        }

        // Social platforms and the worker.
        $social     = class_exists( 'PressHub_Social_Settings_Storage' ) && PressHub_Settings_Sections::can_social();
        $pull       = $social && 'pull' === PressHub_Social_Settings_Storage::get_worker_mode();
        $automation = [];
        if ( $social ) {
            $sessions = get_option( 'presshub_social_worker_sessions', [] );
            $sessions = is_array( $sessions ) ? $sessions : [];
            foreach ( PressHub_Social_Settings_Storage::get_enabled_platforms() as $p ) {
                $label = self::platform_label( $p );
                $auto  = 'automation' === PressHub_Social_Settings_Storage::get_platform_engine( $p );
                if ( $auto ) {
                    $automation[] = $p;
                }
                if ( 'youtube' === $p ) {
                    $yt        = class_exists( 'PressHub_Social_YouTube' );
                    $videos_on = PressHub_Social_Settings_Storage::get_youtube_shorts_enabled() || PressHub_Social_Settings_Storage::get_youtube_podcast_enabled();
                    if ( $yt && '' !== PressHub_Social_YouTube::reconnect_reason() ) {
                        /* translators: %s: Google's error */
                        $add( sprintf( __( 'YouTube uploads stopped: Google refused the stored sign-in (%s). Connect YouTube again.', 'presshub-ai-editor' ), PressHub_Social_YouTube::reconnect_reason() ), 'social-accounts', __( 'Connect YouTube', 'presshub-ai-editor' ) );
                    } elseif ( ! ( $yt && PressHub_Social_YouTube::is_connected() ) ) {
                        // With a YouTube video switch on, the Shorts check below says this.
                        if ( ! $videos_on ) {
                            $add( __( 'YouTube is switched on, but no YouTube channel is connected.', 'presshub-ai-editor' ), 'social-accounts', __( 'Connect YouTube', 'presshub-ai-editor' ) );
                        }
                    } elseif ( ! $pull ) {
                        $add( __( 'YouTube is switched on, but uploads need the worker in Pull mode.', 'presshub-ai-editor' ), 'automation-worker', __( 'Change worker mode', 'presshub-ai-editor' ) );
                    } elseif ( ! PressHub_Social_Settings_Storage::is_platform_configured( $p ) ) {
                        $add( __( 'YouTube is switched on, but the worker has not said it can upload to YouTube yet. Update and restart the worker.', 'presshub-ai-editor' ), 'automation-worker', __( 'Check the worker', 'presshub-ai-editor' ) );
                    }
                    if ( $yt && PressHub_Social_YouTube::paused_until() > 0 ) {
                        /* translators: %s: UTC date and time */
                        $add( sprintf( __( 'The daily YouTube upload quota is used up. YouTube jobs wait until %s UTC.', 'presshub-ai-editor' ), gmdate( 'Y-m-d H:i', PressHub_Social_YouTube::paused_until() ) ), 'social-accounts', __( 'See YouTube', 'presshub-ai-editor' ), 'warning' );
                    }
                    if ( ! $videos_on ) {
                        $add( __( 'YouTube is on in the platform list, but YouTube Shorts and podcast episodes are both off, so nothing is uploaded.', 'presshub-ai-editor' ), 'social-accounts', __( 'See YouTube', 'presshub-ai-editor' ), 'warning' );
                    }
                } elseif ( ! PressHub_Social_Settings_Storage::is_platform_configured( $p ) ) {
                    if ( $auto ) {
                        /* translators: %s: platform name */
                        $add( sprintf( __( '%s is switched on, but the worker has not reported a login for it.', 'presshub-ai-editor' ), $label ), 'automation-worker', __( 'Check the worker', 'presshub-ai-editor' ) );
                    } else {
                        /* translators: %s: platform name */
                        $add( sprintf( __( '%s is switched on, but its account details are missing.', 'presshub-ai-editor' ), $label ), 'social-accounts', __( 'Add the account details', 'presshub-ai-editor' ) );
                    }
                } elseif ( $auto && $pull && array_key_exists( $p, $sessions ) && empty( $sessions[ $p ] ) ) {
                    /* translators: %s: platform name */
                    $add( sprintf( __( '%s needs a new login on the worker. Its posts wait until then.', 'presshub-ai-editor' ), $label ), 'automation-worker', __( 'See the worker logins', 'presshub-ai-editor' ) );
                }
            }
            if ( $pull && $automation ) {
                $last = (int) get_option( 'presshub_social_worker_last_seen', 0 );
                if ( $last <= 0 || ( $now - $last ) > self::WORKER_ONLINE_SECONDS ) {
                    $text = $last > 0
                        /* translators: %s: time the worker was last heard from */
                        ? sprintf( __( 'The worker is not running (last heard from %s). Posts on the automation engine wait until it is back.', 'presshub-ai-editor' ), PressHub_Connection_Status::time_label( $last, $now ) )
                        : __( 'The worker has never reported in. Posts on the automation engine wait until it runs.', 'presshub-ai-editor' );
                    $add( $text, 'automation-worker', __( 'Check the worker', 'presshub-ai-editor' ) );
                }
            }
            if ( ! class_exists( 'PressHub_Social_Settings_Render' ) && file_exists( __DIR__ . '/class-social-settings-render.php' ) ) {
                require_once __DIR__ . '/class-social-settings-render.php';
            }
            if ( class_exists( 'PressHub_Social_Settings_Render' ) ) {
                foreach ( ( new PressHub_Social_Settings_Render() )->shorts_unmet_requirements() as $issue ) {
                    $problems[ $issue['text'] ] = [ 'text' => $issue['text'], 'url' => $issue['url'], 'link' => $issue['link'], 'level' => 'error' ];
                }
            }
        }

        // The last result of each test that still matters.
        foreach ( PressHub_Connection_Status::all() as $id => $entry ) {
            $target = self::check_target( (string) $id, $providers, $social, $pull, $automation, $inbox_on );
            if ( ! $target ) {
                continue;
            }
            $d = PressHub_Connection_Status::describe( $entry, $now );
            if ( 'error' === $d['state'] ) {
                /* translators: 1: connection name, 2: status, 3: "checked HH:MM" */
                $add( sprintf( __( '%1$s: %2$s (%3$s).', 'presshub-ai-editor' ), $target['label'], $d['label'], $d['checked'] ), $target['anchor'], __( 'Fix and test again', 'presshub-ai-editor' ) );
            } elseif ( 'warning' === $d['state'] ) {
                /* translators: 1: connection name, 2: e.g. "Valid, 3 days left" */
                $add( sprintf( __( '%1$s: the token expires soon (%2$s).', 'presshub-ai-editor' ), $target['label'], $d['label'] ), $target['anchor'], __( 'Replace the token', 'presshub-ai-editor' ), 'warning' );
            }
        }

        return array_values( $problems );
    }

    /**
     * Name and anchor of a cached check, or null when it no longer matters
     * (provider deleted, platform switched off, inbox off).
     */
    private static function check_target( string $id, array $providers, bool $social, bool $pull, array $automation, bool $inbox_on ): ?array {
        if ( 0 === strpos( $id, 'provider:' ) ) {
            $pid = substr( $id, 9 );
            foreach ( $providers as $key => $p ) {
                if ( sanitize_key( (string) $key ) === $pid && ! empty( $p['enabled'] ) ) {
                    return [ 'label' => (string) ( $p['name'] ?? $key ), 'anchor' => 'ai-providers' ];
                }
            }
            return null;
        }
        if ( 'press_inbox' === $id ) {
            return $inbox_on ? [ 'label' => __( 'Press inbox', 'presshub-ai-editor' ), 'anchor' => 'press-inbox' ] : null;
        }
        if ( 'worker' === $id ) {
            // In Pull mode the heartbeat above is the better signal.
            return $social && ! $pull && $automation ? [ 'label' => __( 'Automation worker', 'presshub-ai-editor' ), 'anchor' => 'automation-worker' ] : null;
        }
        if ( 0 === strpos( $id, 'social:' ) && $social ) {
            $p = substr( $id, 7 );
            if ( PressHub_Social_Settings_Storage::is_platform_enabled( $p ) ) {
                // YouTube's sign-in is in Social platform accounts, not on the worker.
                $auto = 'youtube' !== $p && 'automation' === PressHub_Social_Settings_Storage::get_platform_engine( $p );
                return [ 'label' => self::platform_label( $p ), 'anchor' => $auto ? 'automation-worker' : 'social-accounts' ];
            }
        }
        return null;
    }

    private static function platform_label( string $p ): string {
        return class_exists( 'PressHub_Social_Settings_Render' ) ? PressHub_Social_Settings_Render::platform_label( $p ) : ucfirst( $p );
    }

    /**
     * The setup check box. A static notice with a heading (not an alert),
     * so it is read in page order.
     *
     * @param bool $compact The Daily Briefing Hub box: at most three items and a link to the rest.
     */
    public static function render( bool $compact = false ): string {
        $problems = self::problems();
        $id       = $compact ? 'presshub-setup-check-hub' : 'presshub-setup-check';
        // On Connections: one button that runs every Test on the page.
        $recheck  = $compact ? '' : '<p class="presshub-conn-recheck"><button type="button" class="button presshub-conn-recheck-all">' . esc_html__( 'Recheck all connections', 'presshub-ai-editor' ) . '</button> '
            . '<span class="presshub-conn-recheck-status" role="status" aria-live="polite"></span></p>';
        if ( ! $problems ) {
            return '<div class="notice notice-success inline presshub-setup-check is-ok" id="' . esc_attr( $id ) . '"><h2 class="presshub-setup-check-title">' . esc_html__( 'Setup check', 'presshub-ai-editor' ) . '</h2><p>'
                . esc_html__( 'Everything is connected.', 'presshub-ai-editor' ) . '</p>' . $recheck . '</div>';
        }
        $shown = $compact ? array_slice( $problems, 0, 3 ) : $problems;
        $out   = '<div class="notice notice-warning inline presshub-setup-check" id="' . esc_attr( $id ) . '">';
        $out  .= '<h2 class="presshub-setup-check-title">' . esc_html__( 'Setup check', 'presshub-ai-editor' ) . '</h2>';
        /* translators: %d: number of problems */
        $out  .= '<p>' . esc_html( sprintf( _n( '%d thing needs attention:', '%d things need attention:', count( $problems ), 'presshub-ai-editor' ), count( $problems ) ) ) . '</p><ul class="presshub-setup-check-list">';
        foreach ( $shown as $p ) {
            $out .= '<li class="is-' . esc_attr( $p['level'] ) . '">' . esc_html( $p['text'] ) . ' <a href="' . esc_url( $p['url'] ) . '">' . esc_html( $p['link'] ) . '</a></li>';
        }
        $out .= '</ul>' . $recheck;
        if ( $compact ) {
            $out .= '<p><a href="' . esc_url( PressHub_Settings_Sections::url( 'connections' ) ) . '">' . esc_html__( 'Open Connections', 'presshub-ai-editor' ) . '</a></p>';
        }
        $out .= '</div>';
        return $out;
    }
}
