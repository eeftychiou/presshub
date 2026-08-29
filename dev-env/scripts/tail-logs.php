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
$wp_log_file = $dev_env_dir . '/wordpress/wp-content/debug.log';
$ph_log_file = $dev_env_dir . '/wordpress/wp-content/uploads/presshub-ai/presshub-debug.log';

$options = getopt( 'f', [ 'lines::', 'source::', 'level::', 'search::', 'follow', 'clear', 'help' ] );

if ( isset( $options['help'] ) ) {
    echo "PressHub Dev Log Monitor\n";
    echo "------------------------\n";
    echo "Options:\n";
    echo "  --lines=N        Number of recent lines to display (default: 50)\n";
    echo "  --source=all|wp|presshub  Filter by log source (default: all)\n";
    echo "  --level=NAME     Filter by log level (DEBUG, INFO, WARNING, ERROR)\n";
    echo "  --search=TEXT    Filter lines matching text\n";
    echo "  --follow, -f     Stream logs continuously\n";
    echo "  --clear          Clear log files\n";
    exit( 0 );
}

if ( isset( $options['clear'] ) ) {
    if ( file_exists( $wp_log_file ) ) {
        file_put_contents( $wp_log_file, '' );
        echo "Cleared {$wp_log_file}\n";
    }
    if ( file_exists( $ph_log_file ) ) {
        file_put_contents( $ph_log_file, '' );
        echo "Cleared {$ph_log_file}\n";
    }
    exit( 0 );
}

$lines_count = isset( $options['lines'] ) ? (int) $options['lines'] : 50;
$source      = isset( $options['source'] ) ? strtolower( (string) $options['source'] ) : 'all';
$level_filter= isset( $options['level'] ) ? strtoupper( (string) $options['level'] ) : null;
$search      = isset( $options['search'] ) ? (string) $options['search'] : null;
$is_follow   = isset( $options['follow'] ) || isset( $options['f'] );

echo "=================================================================\n";
echo "PressHub AI Development Log Monitor\n";
echo "=================================================================\n";
echo "WP Debug Log:    " . ( file_exists( $wp_log_file ) ? $wp_log_file . " (" . filesize( $wp_log_file ) . " bytes)" : "No log file yet" ) . "\n";
echo "PressHub Log:    " . ( file_exists( $ph_log_file ) ? $ph_log_file . " (" . filesize( $ph_log_file ) . " bytes)" : "No log file yet" ) . "\n";
echo "Source Filter:   {$source}\n";
if ( $level_filter ) {
    echo "Level Filter:    {$level_filter}\n";
}
if ( $search ) {
    echo "Search Filter:   {$search}\n";
}
echo "=================================================================\n\n";

$read_entries = function() use ( $wp_log_file, $ph_log_file, $source, $level_filter, $search ): array {
    $entries = [];

    if ( in_array( $source, [ 'all', 'wp' ], true ) && file_exists( $wp_log_file ) ) {
        $lines = file( $wp_log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( is_array( $lines ) ) {
            foreach ( $lines as $line ) {
                $entries[] = [
                    'source' => 'WP',
                    'raw'    => $line,
                ];
            }
        }
    }

    if ( in_array( $source, [ 'all', 'presshub' ], true ) && file_exists( $ph_log_file ) ) {
        $lines = file( $ph_log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( is_array( $lines ) ) {
            foreach ( $lines as $line ) {
                $entries[] = [
                    'source' => 'PressHub',
                    'raw'    => $line,
                ];
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

$wp_pos = file_exists( $wp_log_file ) ? filesize( $wp_log_file ) : 0;
$ph_pos = file_exists( $ph_log_file ) ? filesize( $ph_log_file ) : 0;

while ( true ) {
    clearstatcache();

    // Check WP log
    if ( file_exists( $wp_log_file ) ) {
        $cur_size = filesize( $wp_log_file );
        if ( $cur_size > $wp_pos ) {
            $fp = fopen( $wp_log_file, 'r' );
            fseek( $fp, $wp_pos );
            while ( false !== ( $line = fgets( $fp ) ) ) {
                $trimmed = trim( $line );
                if ( '' !== $trimmed ) {
                    if ( ( ! $level_filter || stripos( $trimmed, $level_filter ) !== false ) &&
                         ( ! $search || stripos( $trimmed, $search ) !== false ) ) {
                        format_log_line( 'WP', $trimmed );
                    }
                }
            }
            $wp_pos = ftell( $fp );
            fclose( $fp );
        } elseif ( $cur_size < $wp_pos ) {
            $wp_pos = 0;
        }
    }

    // Check PressHub log
    if ( file_exists( $ph_log_file ) ) {
        $cur_size = filesize( $ph_log_file );
        if ( $cur_size > $ph_pos ) {
            $fp = fopen( $ph_log_file, 'r' );
            fseek( $fp, $ph_pos );
            while ( false !== ( $line = fgets( $fp ) ) ) {
                $trimmed = trim( $line );
                if ( '' !== $trimmed ) {
                    if ( ( ! $level_filter || stripos( $trimmed, $level_filter ) !== false ) &&
                         ( ! $search || stripos( $trimmed, $search ) !== false ) ) {
                        format_log_line( 'PressHub', $trimmed );
                    }
                }
            }
            $ph_pos = ftell( $fp );
            fclose( $fp );
        } elseif ( $cur_size < $ph_pos ) {
            $ph_pos = 0;
        }
    }

    usleep( 300000 );
}

function format_log_line( string $source, string $line ): void {
    $prefix = $source === 'PressHub' ? '[PH-AI]' : '[WP-CORE]';
    echo "{$prefix} {$line}\n";
}
