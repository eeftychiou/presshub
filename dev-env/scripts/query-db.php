<?php
/**
 * SQLite Database Query & Table Inspection Tool.
 *
 * Usage:
 *   php dev-env/scripts/query-db.php "SELECT * FROM wp_presshub_ai_token_logs LIMIT 10"
 *   php dev-env/scripts/query-db.php --tables
 *   php dev-env/scripts/query-db.php --schema wp_presshub_ai_token_logs
 *   php dev-env/scripts/query-db.php "SELECT * FROM wp_options WHERE option_name LIKE 'presshub%'" --json
 */

declare(strict_types=1);

$dev_env_dir = dirname(__DIR__);
$db_file     = $dev_env_dir . '/wordpress/wp-content/database/.ht.sqlite';

if ( ! file_exists( $db_file ) ) {
    exit( "Error: SQLite database file not found at {$db_file}. Please run setup.php first.\n" );
}

$pdo = new PDO( 'sqlite:' . $db_file );
$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

$args = $argv;
array_shift( $args );

$is_json = false;
$query   = null;
$action  = null;
$target  = null;

foreach ( $args as $arg ) {
    if ( $arg === '--json' ) {
        $is_json = true;
    } elseif ( $arg === '--tables' ) {
        $action = 'tables';
    } elseif ( strpos( $arg, '--schema' ) === 0 ) {
        $action = 'schema';
        if ( strpos( $arg, '=' ) !== false ) {
            $target = explode( '=', $arg, 2 )[1];
        }
    } elseif ( $action === 'schema' && empty( $target ) && strpos( $arg, '--' ) !== 0 ) {
        $target = $arg;
    } elseif ( strpos( $arg, '--' ) !== 0 && empty( $query ) ) {
        $query = $arg;
    }
}

if ( $action === 'tables' ) {
    $stmt = $pdo->query( "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name ASC" );
    $tables = $stmt->fetchAll( PDO::FETCH_COLUMN );
    
    $data = [];
    foreach ( $tables as $table ) {
        $cnt_stmt = $pdo->query( "SELECT COUNT(*) FROM \"{$table}\"" );
        $count = $cnt_stmt->fetchColumn();
        $data[] = [
            'Table Name' => $table,
            'Rows'       => (int) $count,
        ];
    }

    if ( $is_json ) {
        echo json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
    } else {
        echo "\n=== Database Tables in PressHub Dev SQLite Database ===\n\n";
        print_table( $data );
    }
    exit( 0 );
}

if ( $action === 'schema' ) {
    if ( empty( $target ) ) {
        exit( "Error: Please specify table name for --schema (e.g. --schema wp_presshub_ai_token_logs)\n" );
    }

    $stmt = $pdo->query( "PRAGMA table_info(\"{$target}\")" );
    $cols = $stmt->fetchAll( PDO::FETCH_ASSOC );

    if ( empty( $cols ) ) {
        exit( "Table '{$target}' not found.\n" );
    }

    if ( $is_json ) {
        echo json_encode( $cols, JSON_PRETTY_PRINT ) . "\n";
    } else {
        echo "\n=== Schema for Table: {$target} ===\n\n";
        print_table( $cols );
    }
    exit( 0 );
}

if ( empty( $query ) ) {
    echo "Usage:\n";
    echo "  php query-db.php \"<SQL QUERY>\" [--json]\n";
    echo "  php query-db.php --tables [--json]\n";
    echo "  php query-db.php --schema <table_name> [--json]\n";
    exit( 1 );
}

try {
    $stmt = $pdo->query( $query );
    $is_select = ( stripos( ltrim( $query ), 'SELECT' ) === 0 || stripos( ltrim( $query ), 'PRAGMA' ) === 0 || stripos( ltrim( $query ), 'EXPLAIN' ) === 0 );

    if ( $is_select ) {
        $rows = $stmt->fetchAll( PDO::FETCH_ASSOC );
        if ( $is_json ) {
            echo json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
        } else {
            if ( empty( $rows ) ) {
                echo "(0 rows returned)\n";
            } else {
                print_table( $rows );
                echo "(" . count( $rows ) . " row(s) returned)\n";
            }
        }
    } else {
        $affected = $stmt->rowCount();
        echo "Query executed successfully. Affected rows: {$affected}\n";
    }
} catch ( Exception $e ) {
    echo "SQL Error: " . $e->getMessage() . "\n";
    exit( 1 );
}

function print_table( array $rows ): void {
    if ( empty( $rows ) ) {
        return;
    }

    $headers = array_keys( $rows[0] );
    $widths  = [];

    foreach ( $headers as $h ) {
        $widths[ $h ] = strlen( (string) $h );
    }

    foreach ( $rows as $row ) {
        foreach ( $headers as $h ) {
            $val = isset( $row[ $h ] ) ? (string) $row[ $h ] : 'NULL';
            if ( strlen( $val ) > 60 ) {
                $val = substr( $val, 0, 57 ) . '...';
            }
            $widths[ $h ] = max( $widths[ $h ], strlen( $val ) );
        }
    }

    $border = '+';
    foreach ( $headers as $h ) {
        $border .= str_repeat( '-', $widths[ $h ] + 2 ) . '+';
    }

    echo $border . "\n";
    echo '|';
    foreach ( $headers as $h ) {
        echo ' ' . str_pad( (string) $h, $widths[ $h ] ) . ' |';
    }
    echo "\n" . $border . "\n";

    foreach ( $rows as $row ) {
        echo '|';
        foreach ( $headers as $h ) {
            $val = isset( $row[ $h ] ) ? (string) $row[ $h ] : 'NULL';
            if ( strlen( $val ) > 60 ) {
                $val = substr( $val, 0, 57 ) . '...';
            }
            echo ' ' . str_pad( $val, $widths[ $h ] ) . ' |';
        }
        echo "\n";
    }
    echo $border . "\n";
}
