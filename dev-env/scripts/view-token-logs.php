<?php
/**
 * Specialized Activity & Token Log Inspector for PressHub AI.
 *
 * Reads from wp_presshub_ai_token_logs table.
 *
 * Usage:
 *   php dev-env/scripts/view-token-logs.php                       # Show recent 25 logs
 *   php dev-env/scripts/view-token-logs.php --limit=50           # Show 50 logs
 *   php dev-env/scripts/view-token-logs.php --provider=openai     # Filter by provider
 *   php dev-env/scripts/view-token-logs.php --status=error       # Show only failed requests
 *   php dev-env/scripts/view-token-logs.php --action=copilot_chat# Filter by action trigger
 *   php dev-env/scripts/view-token-logs.php --detail=12          # Full details & JSON metadata for ID
 *   php dev-env/scripts/view-token-logs.php --stats              # Aggregate token & performance stats
 *   php dev-env/scripts/view-token-logs.php --json               # JSON output
 */

declare(strict_types=1);

$dev_env_dir = dirname(__DIR__);
$db_file     = $dev_env_dir . '/wordpress/wp-content/database/.ht.sqlite';

if ( ! file_exists( $db_file ) ) {
    exit( "Error: SQLite database file not found at {$db_file}.\n" );
}

$pdo = new PDO( 'sqlite:' . $db_file );
$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

$options = getopt( '', [
    'limit::',
    'provider::',
    'model::',
    'status::',
    'action::',
    'detail::',
    'stats',
    'json',
    'help',
] );

if ( isset( $options['help'] ) ) {
    echo "PressHub AI Token & Activity Log Viewer\n";
    echo "----------------------------------------\n";
    echo "Flags:\n";
    echo "  --limit=N          Number of records to display (default: 25)\n";
    echo "  --provider=NAME    Filter by provider (e.g. openai, anthropic, gemini, groq)\n";
    echo "  --model=NAME       Filter by model name\n";
    echo "  --status=NAME      Filter by status (success, error)\n";
    echo "  --action=NAME      Filter by action trigger (e.g. coauthor_draft, copilot_chat)\n";
    echo "  --detail=ID        Show full expanded record & metadata for a single log ID\n";
    echo "  --stats            Show aggregated summary statistics\n";
    echo "  --json             Output in JSON format\n";
    exit( 0 );
}

$is_json = isset( $options['json'] );

// 1. Single Record Detail View
if ( isset( $options['detail'] ) ) {
    $id = (int) $options['detail'];
    $stmt = $pdo->prepare( "SELECT * FROM wp_presshub_ai_token_logs WHERE id = ?" );
    $stmt->execute( [ $id ] );
    $row = $stmt->fetch( PDO::FETCH_ASSOC );

    if ( ! $row ) {
        exit( "Error: Log ID {$id} not found.\n" );
    }

    if ( $is_json ) {
        echo json_encode( $row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
    } else {
        echo "\n=================================================================\n";
        echo "Token Log Record #{$row['id']}\n";
        echo "=================================================================\n";
        echo "Timestamp:         {$row['created_at']} UTC\n";
        echo "Action Trigger:    {$row['action_trigger']}\n";
        echo "Provider:          {$row['provider']}\n";
        echo "Model:             {$row['model']}\n";
        echo "Status:            " . ( $row['status'] === 'success' ? "[SUCCESS]" : "[ERROR: {$row['status']}]" ) . "\n";
        echo "Duration:          {$row['duration_ms']} ms\n";
        echo "Prompt Tokens:     {$row['prompt_tokens']}\n";
        echo "Completion Tokens: {$row['completion_tokens']}\n";
        echo "Total Tokens:      {$row['total_tokens']}\n";
        echo "Metric Units:      {$row['metric_units']}\n";
        echo "User ID:           {$row['user_id']}\n";
        if ( ! empty( $row['error_message'] ) ) {
            echo "Error Message:     {$row['error_message']}\n";
        }
        echo "Metadata:\n";
        if ( ! empty( $row['metadata'] ) ) {
            $meta = json_decode( (string) $row['metadata'], true );
            echo json_encode( $meta ?: $row['metadata'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
        } else {
            echo "  (none)\n";
        }
        echo "=================================================================\n\n";
    }
    exit( 0 );
}

// 2. Aggregate Statistics View
if ( isset( $options['stats'] ) ) {
    $stats_stmt = $pdo->query( "
        SELECT
            COUNT(*) as total_requests,
            SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_requests,
            SUM(CASE WHEN status != 'success' THEN 1 ELSE 0 END) as failed_requests,
            SUM(prompt_tokens) as total_prompt_tokens,
            SUM(completion_tokens) as total_completion_tokens,
            SUM(total_tokens) as total_tokens,
            SUM(metric_units) as total_metric_units,
            AVG(duration_ms) as avg_duration_ms
        FROM wp_presshub_ai_token_logs
    " );
    $summary = $stats_stmt->fetch( PDO::FETCH_ASSOC );

    $provider_stmt = $pdo->query( "
        SELECT provider, COUNT(*) as count, SUM(total_tokens) as tokens, AVG(duration_ms) as avg_ms
        FROM wp_presshub_ai_token_logs
        GROUP BY provider
        ORDER BY count DESC
    " );
    $by_provider = $provider_stmt->fetchAll( PDO::FETCH_ASSOC );

    if ( $is_json ) {
        echo json_encode( [
            'summary'     => $summary,
            'by_provider' => $by_provider,
        ], JSON_PRETTY_PRINT ) . "\n";
    } else {
        echo "\n=================================================================\n";
        echo "PressHub AI Token & Usage Statistics\n";
        echo "=================================================================\n";
        echo "Total Requests:       " . number_format( (float) ( $summary['total_requests'] ?? 0 ) ) . "\n";
        echo "Successful:           " . number_format( (float) ( $summary['successful_requests'] ?? 0 ) ) . "\n";
        echo "Failed:               " . number_format( (float) ( $summary['failed_requests'] ?? 0 ) ) . "\n";
        echo "Total Prompt Tokens:  " . number_format( (float) ( $summary['total_prompt_tokens'] ?? 0 ) ) . "\n";
        echo "Total Comp Tokens:    " . number_format( (float) ( $summary['total_completion_tokens'] ?? 0 ) ) . "\n";
        echo "Total Tokens:         " . number_format( (float) ( $summary['total_tokens'] ?? 0 ) ) . "\n";
        echo "Avg Duration:         " . round( (float) ( $summary['avg_duration_ms'] ?? 0 ), 1 ) . " ms\n";
        echo "=================================================================\n";
        echo "Breakdown by Provider:\n";
        if ( empty( $by_provider ) ) {
            echo "  (No requests recorded yet)\n";
        } else {
            foreach ( $by_provider as $p ) {
                printf( "  - %-12s: %5d reqs | %8d tokens | %6.1f ms avg\n",
                    $p['provider'],
                    (int) $p['count'],
                    (int) $p['tokens'],
                    (float) $p['avg_ms']
                );
            }
        }
        echo "=================================================================\n\n";
    }
    exit( 0 );
}

// 3. Tabular Logs View
$where  = [];
$params = [];

if ( isset( $options['provider'] ) ) {
    $where[]  = 'provider = ?';
    $params[] = $options['provider'];
}
if ( isset( $options['model'] ) ) {
    $where[]  = 'model = ?';
    $params[] = $options['model'];
}
if ( isset( $options['status'] ) ) {
    $where[]  = 'status = ?';
    $params[] = $options['status'];
}
if ( isset( $options['action'] ) ) {
    $where[]  = 'action_trigger = ?';
    $params[] = $options['action'];
}

$where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
$limit     = isset( $options['limit'] ) ? (int) $options['limit'] : 25;

$sql = "SELECT id, created_at, action_trigger, provider, model, prompt_tokens, completion_tokens, total_tokens, duration_ms, status
        FROM wp_presshub_ai_token_logs
        {$where_sql}
        ORDER BY id DESC
        LIMIT {$limit}";

$stmt = $pdo->prepare( $sql );
$stmt->execute( $params );
$rows = $stmt->fetchAll( PDO::FETCH_ASSOC );

if ( $is_json ) {
    echo json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
    exit( 0 );
}

echo "\n=== PressHub AI Token Logs (Last " . count( $rows ) . " records) ===\n\n";

if ( empty( $rows ) ) {
    echo "(No token logs recorded yet in database)\n";
    echo "Tip: Run integration tests or trigger an AI action in WP admin to populate logs.\n\n";
    exit( 0 );
}

// Print table
$headers = [ 'ID', 'Time (UTC)', 'Trigger', 'Provider', 'Model', 'Prompt', 'Comp', 'Total', 'Latency', 'Status' ];
$col_keys = [ 'id', 'created_at', 'action_trigger', 'provider', 'model', 'prompt_tokens', 'completion_tokens', 'total_tokens', 'duration_ms', 'status' ];

$widths = [];
foreach ( $headers as $idx => $h ) {
    $widths[ $idx ] = strlen( $h );
}

foreach ( $rows as $r ) {
    foreach ( $col_keys as $idx => $k ) {
        $val = (string) ( $r[ $k ] ?? '' );
        if ( $k === 'duration_ms' ) {
            $val .= 'ms';
        }
        $widths[ $idx ] = max( $widths[ $idx ], strlen( $val ) );
    }
}

$border = '+';
foreach ( $widths as $w ) {
    $border .= str_repeat( '-', $w + 2 ) . '+';
}

echo $border . "\n|";
foreach ( $headers as $idx => $h ) {
    echo ' ' . str_pad( $h, $widths[ $idx ] ) . ' |';
}
echo "\n" . $border . "\n";

foreach ( $rows as $r ) {
    echo '|';
    foreach ( $col_keys as $idx => $k ) {
        $val = (string) ( $r[ $k ] ?? '' );
        if ( $k === 'duration_ms' ) {
            $val .= 'ms';
        }
        echo ' ' . str_pad( $val, $widths[ $idx ] ) . ' |';
    }
    echo "\n";
}
echo $border . "\n";
echo "Run 'php dev-env/scripts/view-token-logs.php --detail=<ID>' for full payload and metadata.\n\n";
