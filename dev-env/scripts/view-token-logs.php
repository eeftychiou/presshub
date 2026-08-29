<?php
/**
 * Token & Activity Log Inspector for PressHub AI Editor.
 *
 * Inspects `wp_presshub_ai_token_logs` table directly from SQLite DB.
 *
 * Usage:
 *   php dev-env/scripts/view-token-logs.php                  # Recent 25 logs
 *   php dev-env/scripts/view-token-logs.php --limit=50      # Recent 50 logs
 *   php dev-env/scripts/view-token-logs.php --provider=openai
 *   php dev-env/scripts/view-token-logs.php --status=error
 *   php dev-env/scripts/view-token-logs.php --stats         # Summary metrics
 *   php dev-env/scripts/view-token-logs.php --detail=12     # View record #12
 *   php dev-env/scripts/view-token-logs.php --json
 */

declare(strict_types=1);

$dev_env_dir = dirname(__DIR__);
$db_file     = $dev_env_dir . '/wordpress/wp-content/database/.ht.sqlite';

if ( ! file_exists( $db_file ) ) {
    exit( "Error: SQLite database file not found at {$db_file}.\n" );
}

$pdo = new PDO( 'sqlite:' . $db_file );
$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

$options = getopt( '', [ 'limit::', 'provider::', 'status::', 'stats', 'detail::', 'json', 'help' ] );

if ( isset( $options['help'] ) ) {
    echo "PressHub Token & Activity Log Inspector\n";
    echo "----------------------------------------\n";
    echo "Options:\n";
    echo "  --limit=N        Number of records to show (default: 25)\n";
    echo "  --provider=NAME  Filter by provider (openai, anthropic, gemini, groq, etc.)\n";
    echo "  --status=NAME    Filter by status (success, error, rate_limited)\n";
    echo "  --stats          Display aggregate usage statistics\n";
    echo "  --detail=ID      Display full record details and JSON metadata for record ID\n";
    echo "  --json           Output raw JSON\n";
    exit( 0 );
}

$is_json   = isset( $options['json'] );
$is_stats  = isset( $options['stats'] );
$detail_id = isset( $options['detail'] ) ? (int) $options['detail'] : null;
$limit     = isset( $options['limit'] ) ? max( 1, (int) $options['limit'] ) : 25;
$provider  = isset( $options['provider'] ) ? strtolower( (string) $options['provider'] ) : null;
$status    = isset( $options['status'] ) ? strtolower( (string) $options['status'] ) : null;

// Check if table exists
$table_check = $pdo->query( "SELECT name FROM sqlite_master WHERE type='table' AND name='wp_presshub_ai_token_logs'" );
if ( ! $table_check->fetchColumn() ) {
    exit( "Table 'wp_presshub_ai_token_logs' does not exist yet. Please run WordPress or execute an AI action first.\n" );
}

if ( $detail_id !== null ) {
    $stmt = $pdo->prepare( "SELECT * FROM wp_presshub_ai_token_logs WHERE id = ?" );
    $stmt->execute( [ $detail_id ] );
    $record = $stmt->fetch( PDO::FETCH_ASSOC );

    if ( ! $record ) {
        exit( "Record #{$detail_id} not found.\n" );
    }

    if ( $is_json ) {
        echo json_encode( $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
    } else {
        echo "\n=== PressHub Token Log Record #{$record['id']} ===\n\n";
        foreach ( $record as $k => $v ) {
            if ( $k === 'metadata' && ! empty( $v ) ) {
                echo str_pad( $k . ':', 18 ) . "\n";
                $decoded = json_decode( (string) $v, true );
                echo json_encode( $decoded ?: $v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
            } else {
                echo str_pad( $k . ':', 18 ) . " " . ( $v !== null ? $v : 'NULL' ) . "\n";
            }
        }
    }
    exit( 0 );
}

if ( $is_stats ) {
    $total_reqs = (int) $pdo->query( "SELECT COUNT(*) FROM wp_presshub_ai_token_logs" )->fetchColumn();
    $total_tokens = (int) $pdo->query( "SELECT SUM(total_tokens) FROM wp_presshub_ai_token_logs" )->fetchColumn();
    $total_prompt = (int) $pdo->query( "SELECT SUM(prompt_tokens) FROM wp_presshub_ai_token_logs" )->fetchColumn();
    $total_completion = (int) $pdo->query( "SELECT SUM(completion_tokens) FROM wp_presshub_ai_token_logs" )->fetchColumn();
    $avg_duration = (float) $pdo->query( "SELECT AVG(duration_ms) FROM wp_presshub_ai_token_logs" )->fetchColumn();
    $errors = (int) $pdo->query( "SELECT COUNT(*) FROM wp_presshub_ai_token_logs WHERE status != 'success'" )->fetchColumn();

    $by_provider = $pdo->query( "SELECT provider, COUNT(*) as count, SUM(total_tokens) as tokens, AVG(duration_ms) as avg_latency FROM wp_presshub_ai_token_logs GROUP BY provider ORDER BY tokens DESC" )->fetchAll( PDO::FETCH_ASSOC );

    $stats_data = [
        'Total Requests'     => $total_reqs,
        'Total Tokens'       => $total_tokens,
        'Prompt Tokens'      => $total_prompt,
        'Completion Tokens'  => $total_completion,
        'Avg Latency (ms)'   => round( $avg_duration, 1 ),
        'Failed Requests'    => $errors,
        'Providers'          => $by_provider,
    ];

    if ( $is_json ) {
        echo json_encode( $stats_data, JSON_PRETTY_PRINT ) . "\n";
    } else {
        echo "\n=== PressHub AI Token & Usage Aggregate Statistics ===\n\n";
        echo "Total Requests:      {$total_reqs}\n";
        echo "Total Tokens:        " . number_format( $total_tokens ) . "\n";
        echo "  - Prompt Tokens:   " . number_format( $total_prompt ) . "\n";
        echo "  - Completion:      " . number_format( $total_completion ) . "\n";
        echo "Average Latency:     " . round( $avg_duration, 1 ) . " ms\n";
        echo "Failed Requests:     {$errors}\n\n";
        echo "--- Breakdown by Provider ---\n";
        if ( empty( $by_provider ) ) {
            echo "(No requests recorded)\n";
        } else {
            foreach ( $by_provider as $p ) {
                echo sprintf(
                    "%-15s | Requests: %-4d | Tokens: %-8s | Avg Latency: %-6.1f ms\n",
                    $p['provider'],
                    $p['count'],
                    number_format( (int) $p['tokens'] ),
                    (float) $p['avg_latency']
                );
            }
        }
    }
    exit( 0 );
}

$where = [];
$params = [];

if ( $provider ) {
    $where[]  = 'provider = ?';
    $params[] = $provider;
}

if ( $status ) {
    $where[]  = 'status = ?';
    $params[] = $status;
}

$where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
$sql = "SELECT id, user_id, action, provider, model, prompt_tokens, completion_tokens, total_tokens, duration_ms, status, created_at FROM wp_presshub_ai_token_logs {$where_clause} ORDER BY id DESC LIMIT {$limit}";

$stmt = $pdo->prepare( $sql );
$stmt->execute( $params );
$rows = $stmt->fetchAll( PDO::FETCH_ASSOC );

if ( $is_json ) {
    echo json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
    exit( 0 );
}

echo "\n=== Recent PressHub Token & Activity Logs (Showing " . count( $rows ) . " records) ===\n\n";

if ( empty( $rows ) ) {
    echo "(No token log records found)\n";
    exit( 0 );
}

print_token_table( $rows );

function print_token_table( array $rows ): void {
    $headers = [ 'id', 'action', 'provider', 'model', 'prompt_tokens', 'comp_tokens', 'total_tokens', 'ms', 'status', 'created_at' ];
    $data = [];

    foreach ( $rows as $r ) {
        $data[] = [
            'id'            => (string) $r['id'],
            'action'        => substr( (string) $r['action'], 0, 16 ),
            'provider'      => (string) $r['provider'],
            'model'         => substr( (string) $r['model'], 0, 20 ),
            'prompt_tokens' => (string) $r['prompt_tokens'],
            'comp_tokens'   => (string) $r['completion_tokens'],
            'total_tokens'  => (string) $r['total_tokens'],
            'ms'            => (string) $r['duration_ms'],
            'status'        => (string) $r['status'],
            'created_at'    => substr( (string) $r['created_at'], 0, 19 ),
        ];
    }

    $widths = [];
    foreach ( $headers as $h ) {
        $widths[ $h ] = strlen( $h );
    }

    foreach ( $data as $row ) {
        foreach ( $headers as $h ) {
            $widths[ $h ] = max( $widths[ $h ], strlen( $row[ $h ] ?? '' ) );
        }
    }

    $border = '+';
    foreach ( $headers as $h ) {
        $border .= str_repeat( '-', $widths[ $h ] + 2 ) . '+';
    }

    echo $border . "\n|";
    foreach ( $headers as $h ) {
        echo ' ' . str_pad( $h, $widths[ $h ] ) . ' |';
    }
    echo "\n" . $border . "\n";

    foreach ( $data as $row ) {
        echo '|';
        foreach ( $headers as $h ) {
            echo ' ' . str_pad( $row[ $h ] ?? '', $widths[ $h ] ) . ' |';
        }
        echo "\n";
    }
    echo $border . "\n";
}
