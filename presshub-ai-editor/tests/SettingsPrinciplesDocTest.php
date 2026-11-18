<?php
/**
 * The settings design principles stay binding and in step with the code.
 *
 *  1. docs/settings-design-principles.md exists, says it is binding, and has
 *     its checklist and Exceptions sections.
 *  2. Its section table lists exactly the Settings sections, in order, with
 *     their labels (PressHub_Settings_Sections), so the document cannot drift.
 *  3. Every test it names as a guard exists.
 *  4. agents.md, architecture.md and README link it; agents.md carries the
 *     design gate.
 *  5. The PR template carries the same checklist, word for word.
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

$doc = $read( 'docs/settings-design-principles.md' );
$check( '' !== $doc, 'docs/settings-design-principles.md exists.' );
$check( false !== strpos( $doc, '**Status: binding.**' ), 'The principles say they are binding.' );
$check( false !== strpos( $doc, "\n## Checklist (copy into the PR)\n" ), 'The principles have a checklist.' );
$check( false !== strpos( $doc, "\n## Exceptions\n" ), 'The principles have an Exceptions section.' );

// 2. The section table matches the code.
preg_match_all( '/^\| ([^|]+?) \| `([a-z-]+)` \|/m', $doc, $rows, PREG_SET_ORDER );
$doc_sections = [];
foreach ( $rows as $row ) {
    $doc_sections[ $row[2] ] = trim( $row[1] );
}
$code_sections = [];
foreach ( PressHub_Settings_Sections::slugs() as $slug ) {
    $code_sections[ $slug ] = PressHub_Settings_Sections::label( $slug );
}
$check( $doc_sections === $code_sections, 'The section table lists the Settings sections in order with their labels. Doc: ' . json_encode( $doc_sections ) . ' Code: ' . json_encode( $code_sections ) );

// 3. Every named guard test exists.
$enforcement = (string) strstr( $doc, "\n## 6. Enforcement\n" );
preg_match_all( '/`([A-Z][A-Za-z]+Test)`/', $enforcement, $tests );
$check( count( $tests[1] ) >= 10, 'The enforcement table names its guard tests.' );
foreach ( array_unique( $tests[1] ) as $test ) {
    $check( is_file( __DIR__ . '/' . $test . '.php' ), "Guard test {$test} exists." );
}

// 4. Linked from the other documents.
$agents = $read( 'agents.md' );
$check( false !== strpos( $agents, 'Design gate for settings (binding)' ), 'agents.md carries the settings design gate.' );
foreach ( [ 'agents.md' => $agents, 'architecture.md' => $read( 'architecture.md' ), 'README.md' => $read( 'README.md' ) ] as $name => $text ) {
    $check( false !== strpos( $text, 'docs/settings-design-principles.md' ), "{$name} links docs/settings-design-principles.md." );
}

// 5. The PR template carries the same checklist.
$template = $read( '.github/pull_request_template.md' );
preg_match( '/## Checklist \(copy into the PR\)\n\n(.*?)\n\n## /s', $doc, $m );
$checklist = $m[1] ?? '';
$check( '' !== $checklist, 'The checklist can be read from the principles.' );
$check( '' !== $checklist && false !== strpos( $template, $checklist ), 'The PR template carries the principles checklist word for word.' );

if ( $failures ) {
    echo "FAIL\n";
    foreach ( $failures as $f ) {
        echo '  - ' . $f . "\n";
    }
    exit( 1 );
}
echo "SettingsPrinciplesDocTest: OK\n";
