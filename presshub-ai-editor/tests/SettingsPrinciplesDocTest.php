<?php
/**
 * The settings design principles stay binding and in step with the code.
 *
 *  1. docs/settings-design-principles.md exists, says it is binding, and has
 *     its checklist and Exceptions sections.
 *  2. Its section table (§1.2) lists exactly the Settings sections, in order,
 *     with their labels (PressHub_Settings_Sections), so it cannot drift.
 *  3. Every test its Enforcement table names exists, and the CI step it names
 *     is in .github/workflows/ci.yml.
 *  4. agents.md, architecture.md and README link it; agents.md carries the
 *     design gate.
 *  5. The PR template's "Settings design gate" list is the document's
 *     checklist, word for word.
 *  6. Every exception names a tracking issue.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-capabilities.php';
require_once dirname( __DIR__ ) . '/includes/class-settings-sections.php';

$failures = [];
$check    = static function ( bool $ok, string $what ) use ( &$failures ): void {
    if ( ! $ok ) {
        $failures[] = $what;
    }
};

$root = dirname( __DIR__, 2 );
$read = static function ( string $rel ) use ( $root ): string {
    $path = $root . '/' . $rel;
    return is_readable( $path ) ? str_replace( "\r\n", "\n", (string) file_get_contents( $path ) ) : '';
};
/** Text from $start up to (not including) the first $end after it, or ''. */
$slice = static function ( string $text, string $start, string $end ): string {
    $from = strpos( $text, $start );
    if ( false === $from ) {
        return '';
    }
    $to = strpos( $text, $end, $from + strlen( $start ) );
    return false === $to ? substr( $text, $from ) : substr( $text, $from, $to - $from );
};

$doc = $read( 'docs/settings-design-principles.md' );
$check( '' !== $doc, 'docs/settings-design-principles.md exists.' );
$check( false !== strpos( $doc, '**Status: binding.**' ), 'The principles say they are binding.' );
$check( false !== strpos( $doc, "\n## Checklist (copy into the PR)\n" ), 'The principles have a checklist.' );
$check( false !== strpos( $doc, "\n## Exceptions\n" ), 'The principles have an Exceptions section.' );

// 2. The §1.2 section table matches the code.
$table = $slice( $doc, '**1.2 ', "\n**1.3 " );
preg_match_all( '/^\| ([^|]+?) \| `([a-z-]+)` \|/m', $table, $rows, PREG_SET_ORDER );
$doc_sections = [];
foreach ( $rows as $row ) {
    $doc_sections[ $row[2] ] = trim( $row[1] );
}
$check( count( $rows ) === count( $doc_sections ), 'The §1.2 table lists each section once.' );
$code_sections = [];
foreach ( PressHub_Settings_Sections::slugs() as $slug ) {
    $code_sections[ $slug ] = PressHub_Settings_Sections::label( $slug );
}
$check( $doc_sections === $code_sections, 'The §1.2 table lists the Settings sections in order with their labels. Doc: ' . json_encode( $doc_sections ) . ' Code: ' . json_encode( $code_sections ) );

// 3. Every named guard exists.
$enforcement = $slice( $doc, "\n## 8. Enforcement\n", "\n---" );
$check( '' !== $enforcement, 'The principles have an Enforcement section.' );
preg_match_all( '/`([A-Z][A-Za-z0-9]*Test)`/', $enforcement, $tests );
$check( count( array_unique( $tests[1] ) ) >= 10, 'The Enforcement table names its guard tests.' );
foreach ( array_unique( $tests[1] ) as $test ) {
    $check( is_file( __DIR__ . '/' . $test . '.php' ), "Guard test {$test} exists." );
}
$ci = $read( '.github/workflows/ci.yml' );
preg_match_all( '/CI step "([^"]+)"/', $enforcement, $steps );
$check( count( $steps[1] ) >= 1, 'The Enforcement table names its CI step in the form CI step "<name>".' );
foreach ( $steps[1] as $step ) {
    $check( false !== strpos( $ci, '- name: ' . $step . "\n" ), "CI step \"{$step}\" exists in .github/workflows/ci.yml." );
}
if ( false !== strpos( $enforcement, 'run-social-tests.php' ) ) {
    $check( is_file( $root . '/presshub-ai-editor/tests/run-social-tests.php' ), 'tests/run-social-tests.php exists.' );
}

// 4. Linked from the other documents.
$agents = $read( 'agents.md' );
$check( false !== strpos( $agents, 'Design gate for settings (binding)' ), 'agents.md carries the settings design gate.' );
foreach ( [ 'agents.md' => $agents, 'architecture.md' => $read( 'architecture.md' ), 'README.md' => $read( 'README.md' ) ] as $name => $text ) {
    $check( false !== strpos( $text, 'docs/settings-design-principles.md' ), "{$name} links docs/settings-design-principles.md." );
}

// 5. The PR template's list is the checklist, exactly.
$extract_list = static function ( string $block ): string {
    $lines = [];
    foreach ( explode( "\n", $block ) as $line ) {
        if ( preg_match( '/^\s*- \[[ xX]\] /', $line ) ) {
            $lines[] = rtrim( preg_replace( '/^(\s*- \[)[xX](\] )/', '$1 $2', $line ) );
        }
    }
    return implode( "\n", $lines );
};
$doc_list      = $extract_list( $slice( $doc, "\n## Checklist (copy into the PR)\n", "\n## Exceptions" ) );
$template      = $read( '.github/pull_request_template.md' );
$template_list = $extract_list( $slice( $template, "\n## Settings design gate\n", "\n## Tests" ) );
$check( '' !== $doc_list, 'The checklist can be read from the principles.' );
$check( '' !== $template_list && $template_list === $doc_list, 'The PR template carries the principles checklist word for word.' );

// 6. Every exception row names a tracking issue.
$exceptions = (string) strstr( $doc, "\n## Exceptions\n" );
preg_match_all( '/^\|(?!\s*#\s*\|)(?!\s*-)(.*)\|\s*$/m', $exceptions, $ex_rows, PREG_SET_ORDER );
$check( count( $ex_rows ) >= 1 || false !== strpos( $exceptions, 'None.' ), 'The Exceptions section has rows, or says "None.".' );
$seen_ids = [];
foreach ( $ex_rows as $ex ) {
    $cells = array_map( 'trim', preg_split( '/(?<!\\\\)\|/', $ex[1] ) );
    $id    = $cells[0] ?? '';
    $check( 6 === count( $cells ), "Exception {$id} has the six columns (#, rule, what deviates, why, tracking, ends)." );
    $check( (bool) preg_match( '/^E\d+$/', $id ), "Exception id {$id} has the form E<number>." );
    $check( ! isset( $seen_ids[ $id ] ), "Exception id {$id} is used once." );
    $seen_ids[ $id ] = true;
    $check( 6 === count( $cells ) && ! in_array( '', $cells, true ), "Exception {$id} fills every column." );
    $check( (bool) preg_match( '/^#\d+$/', $cells[4] ?? '' ), "Exception {$id} names one tracking issue (#<number>)." );
}
// Every exception the rules and tables mention still has its row.
$body = substr( $doc, 0, (int) strpos( $doc, "\n## Exceptions\n" ) );
preg_match_all( '/\bE(\d+)\b/', $body, $refs );
foreach ( array_unique( $refs[1] ) as $n ) {
    $check( isset( $seen_ids[ 'E' . $n ] ), "Exception E{$n} is mentioned in the rules and has a row under Exceptions." );
}

if ( $failures ) {
    echo "FAIL\n";
    foreach ( $failures as $f ) {
        echo '  - ' . $f . "\n";
    }
    exit( 1 );
}
echo "SettingsPrinciplesDocTest: OK\n";
