<?php
/**
 * Log Monitor & Viewer for PressHub Local Development Environment.
 *
 * Inspects and streams:
 * 1. WordPress Core debug log (wp-content/debug.log)
 * 2. PressHub AI structured log (wp-content/uploads/presshub-ai/presshub-debug.log)
 *
 * Usage:
 *   php dev-env/scripts/tail-logs.php                 # Show recent 50 lines
 *   php dev-env/scripts/tail-logs.php --lines=100     # Show 100 lines
 *   php dev-env/scripts/tail-logs.php --source=presshub # Only PressHub log
 *   php dev-env/scripts/tail-logs.php --source=wp     # Only WordPress debug.log
 *   php dev-env/scripts/tail-logs.php --level=ERROR   # Filter by level
 *   php dev-env/scripts/tail-logs.php --search=token  # Search keyword
 *   php dev-env/scripts/tail-logs.php --follow        # Live stream logs
 *   php dev-env/scripts/tail-logs.php --clear         # Wipe current log files
 */

declare(strict_types=1);

$dev_env_dir = dirname(__DIR__);
$wp_log_file      = $dev_env_dir . '/wordpress/wp-content/debug.log';
$app_log_file     = $dev_env_dir . '/wordpress/wp-content/uploads/presshub-ai/presshub-debug.log';
$prompts_log_file = $dev_env_dir . '/wordpress/wp-content/uploads/presshub-ai/presshub-ai-debug.log';
$tts_log_file     = $dev_env_dir . '/wordpress/wp-content/uploads/presshub-ai/presshub-ai-tts-debug.log';

$options = getopt( 'f', [ 'lines::', 'source::', 'level::', 'search::', 'trace::', 'follow', 'clear', 'help' ] );

if ( isset( $options['help'] ) ) {
    echo "PressHub Dev Log Monitor\n";
    echo "------------------------\n";
    echo "Options:\n";
    echo "  --lines=N        Number of recent lines to display (default: 50)\n";
    echo "  --source=NAME    Filter by log source: all|wp|app|presshub|prompts|tts (default: all)\n";
    echo "  --level=NAME     Filter by log level (DEBUG, INFO, WARNING, ERROR)\n";
    echo "  --search=TEXT    Filter lines matching text\n";
    echo "  --trace=ID       Filter lines matching Trace ID\n";
    echo "  --follow, -f     Stream logs continuously\n";
    echo "  --clear          Clear log files\n";
    exit( 0 );
}

if ( isset( $options['clear'] ) ) {
    $files_to_clear = [
        'WordPress Debug' => $wp_log_file,
        'PressHub App'    => $app_log_file,
        'PressHub Prompts'=> $prompts_log_file,
        'PressHub TTS'    => $tts_log_file,
    ];
    foreach ( $files_to_clear as $label => $file_path ) {
        if ( file_exists( $file_path ) ) {
            file_put_contents( $file_path, '' );
            echo "Cleared {$label} log: {$file_path}\n";
        }
    }
    echo "All specified logs cleared successfully.\n";
    exit( 0 );
}

$lines_count  = isset( $options['lines'] ) ? (int) $options['lines'] : 50;
$source       = isset( $options['source'] ) ? strtolower( (string) $options['source'] ) : 'all';
$level_filter = isset( $options['level'] ) ? strtoupper( (string) $options['level'] ) : null;
$search       = isset( $options['search'] ) ? (string) $options['search'] : null;
$trace_filter = isset( $options['trace'] ) ? (string) $options['trace'] : null;
$is_follow    = isset( $options['follow'] ) || isset( $options['f'] );

echo "=================================================================\n";
echo "PressHub AI Development Log Monitor\n";
echo "=================================================================\n";
echo "WP Debug Log:        " . ( file_exists( $wp_log_file ) ? $wp_log_file . " (" . filesize( $wp_log_file ) . " bytes)" : "No log file yet" ) . "\n";
echo "PressHub App Log:    " . ( file_exists( $app_log_file ) ? $app_log_file . " (" . filesize( $app_log_file ) . " bytes)" : "No log file yet" ) . "\n";
echo "PressHub Prompts Log:" . ( file_exists( $prompts_log_file ) ? $prompts_log_file . " (" . filesize( $prompts_log_file ) . " bytes)" : "No log file yet" ) . "\n";
echo "PressHub TTS Log:    " . ( file_exists( $tts_log_file ) ? $tts_log_file . " (" . filesize( $tts_log_file ) . " bytes)" : "No log file yet" ) . "\n";
echo "Source Filter:       {$source}\n";
if ( $level_filter ) {
    echo "Level Filter:        {$level_filter}\n";
}
if ( $search ) {
    echo "Search Filter:       {$search}\n";
}
if ( $trace_filter ) {
    echo "Trace Filter:        {$trace_filter}\n";
}
echo "=================================================================\n\n";

$read_entries = function() use (
    $wp_log_file,
    $app_log_file,
    $prompts_log_file,
    $tts_log_file,
    $source,
    $level_filter,
    $search,
    $trace_filter
): array {
    $entries = [];

    $sources_config = [
        'wp'      => [ 'file' => $wp_log_file, 'tag' => 'WP-CORE', 'match' => [ 'all', 'wp' ] ],
        'app'     => [ 'file' => $app_log_file, 'tag' => 'PH-APP', 'match' => [ 'all', 'app', 'presshub' ] ],
        'prompts' => [ 'file' => $prompts_log_file, 'tag' => 'PH-PROMPT', 'match' => [ 'all', 'prompts' ] ],
        'tts'     => [ 'file' => $tts_log_file, 'tag' => 'PH-TTS', 'match' => [ 'all', 'tts' ] ],
    ];

    foreach ( $sources_config as $cfg ) {
        if ( in_array( $source, $cfg['match'], true ) && file_exists( $cfg['file'] ) ) {
            $lines = file( $cfg['file'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
            if ( is_array( $lines ) ) {
                foreach ( $lines as $line ) {
                    $entries[] = [
                        'source' => $cfg['tag'],
                        'raw'    => $line,
                    ];
                }
            }
        }
    }

    $filtered = [];
    foreach ( $entries as $item ) {
        $text = $item['raw'];

        if ( $level_filter && false === stripos( $text, '[' . $level_filter . ']' ) && false === stripos( $text, $level_filter . ':' ) ) {
            continue;
        }

        if ( $search && false === stripos( $text, $search ) ) {
            continue;
        }

        if ( $trace_filter && false === stripos( $text, $trace_filter ) ) {
            continue;
        }

        $filtered[] = $item;
    }

    return $filtered;
};

$all_logs = $read_entries();
$slice = array_slice( $all_logs, -$lines_count );

if ( empty( $slice ) ) {
    echo "[No log entries found matching criteria]\n";
} else {
    foreach ( $slice as $entry ) {
        format_log_line( $entry['source'], $entry['raw'] );
    }
}

if ( ! $is_follow ) {
    exit( 0 );
}

echo "\n--- Live streaming logs (Press Ctrl+C to exit) ---\n";

$targets = [];
if ( in_array( $source, [ 'all', 'wp' ], true ) ) {
    $targets[] = [ 'tag' => 'WP-CORE', 'file' => $wp_log_file, 'pos' => file_exists( $wp_log_file ) ? filesize( $wp_log_file ) : 0 ];
}
if ( in_array( $source, [ 'all', 'app', 'presshub' ], true ) ) {
    $targets[] = [ 'tag' => 'PH-APP', 'file' => $app_log_file, 'pos' => file_exists( $app_log_file ) ? filesize( $app_log_file ) : 0 ];
}
if ( in_array( $source, [ 'all', 'prompts' ], true ) ) {
    $targets[] = [ 'tag' => 'PH-PROMPT', 'file' => $prompts_log_file, 'pos' => file_exists( $prompts_log_file ) ? filesize( $prompts_log_file ) : 0 ];
}
if ( in_array( $source, [ 'all', 'tts' ], true ) ) {
    $targets[] = [ 'tag' => 'PH-TTS', 'file' => $tts_log_file, 'pos' => file_exists( $tts_log_file ) ? filesize( $tts_log_file ) : 0 ];
}

while ( true ) {
    clearstatcache();

    foreach ( $targets as &$tgt ) {
        $f = $tgt['file'];
        if ( file_exists( $f ) ) {
            $cur_size = filesize( $f );
            if ( $cur_size > $tgt['pos'] ) {
                $fp = fopen( $f, 'r' );
                fseek( $fp, $tgt['pos'] );
                while ( false !== ( $line = fgets( $fp ) ) ) {
                    $trimmed = trim( $line );
                    if ( '' !== $trimmed ) {
                        if ( ( ! $level_filter || stripos( $trimmed, $level_filter ) !== false ) &&
                             ( ! $search || stripos( $trimmed, $search ) !== false ) &&
                             ( ! $trace_filter || stripos( $trimmed, $trace_filter ) !== false ) ) {
                            format_log_line( $tgt['tag'], $trimmed );
                        }
                    }
                }
                $tgt['pos'] = ftell( $fp );
                fclose( $fp );
            } elseif ( $cur_size < $tgt['pos'] ) {
                $tgt['pos'] = 0;
            }
        }
    }
    unset( $tgt );

    usleep( 300000 );
}

function format_log_line( string $tag, string $line ): void {
    echo "[{$tag}] {$line}\n";
}
