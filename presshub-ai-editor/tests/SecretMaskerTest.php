<?php
/**
 * Test suite for PressHub_AI_Secret_Masker.
 *
 * Covers every known key prefix, Bearer tokens, nested arrays, key-name masking,
 * free-text masking, idempotency on already-masked values, and non-secret text.
 *
 * @package PressHub_AI_Editor
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-secret-masker.php';

$failures = [];
$checks   = 0;

function sm_check( bool $cond, string $msg ): void {
    global $failures, $checks;
    $checks++;
    if ( ! $cond ) {
        $failures[] = $msg;
    }
}

$body = 'AbCdEf0123456789xyz';

// 1. Every known prefix: mask_if_secret, mask_text, mask_array.
foreach ( PressHub_AI_Secret_Masker::KNOWN_PREFIXES as $pfx ) {
    $key = $pfx . $body;

    $masked = PressHub_AI_Secret_Masker::mask_if_secret( $key );
    sm_check( $masked !== $key && false === strpos( $masked, $body ), "mask_if_secret masks prefix {$pfx}" );
    sm_check( false !== strpos( $masked, '••••' ), "masked form of {$pfx} contains mask dots" );

    $text = PressHub_AI_Secret_Masker::mask_text( "request failed using {$key} (HTTP 401)" );
    sm_check( false === strpos( $text, $body ), "mask_text masks prefix {$pfx} in free text" );
    sm_check( 0 === strpos( $text, 'request failed using ' ) && ' (HTTP 401)' === substr( $text, -11 ), "mask_text keeps surrounding text for {$pfx}" );

    $arr = PressHub_AI_Secret_Masker::mask_array( [ 'note' => $key ] );
    sm_check( false === strpos( $arr['note'], $body ), "mask_array masks prefix {$pfx} under a neutral key name" );
}

// 2. Bearer tokens.
$bearer = 'Bearer abcdef123456ghijkl789';
$m      = PressHub_AI_Secret_Masker::mask_if_secret( $bearer );
sm_check( 0 === strpos( $m, 'Bearer ' ) && false === strpos( $m, 'abcdef123456ghijkl789' ), 'mask_if_secret masks Bearer token' );
$t = PressHub_AI_Secret_Masker::mask_text( 'Header Authorization: Bearer abcdef123456ghijkl789 sent' );
sm_check( false === strpos( $t, 'abcdef123456ghijkl789' ) && false !== strpos( $t, 'Bearer ' ), 'mask_text masks Bearer token in text' );
sm_check( false !== strpos( $t, ' sent' ), 'mask_text keeps text after Bearer token' );

// 3. Nested arrays and key-name masking.
$nested = PressHub_AI_Secret_Masker::mask_array( [
    'provider' => 'openai',
    'api_key'  => 'plainvalue1234567890',
    'headers'  => [
        'Authorization' => 'Bearer zzzzzz111111yyyyyy',
        'deep'          => [ 'password' => 'hunter2hunter2', 'model' => 'gpt-4o' ],
    ],
    'count'    => 5,
] );
sm_check( 'openai' === $nested['provider'], 'non-secret field unchanged' );
sm_check( false === strpos( $nested['api_key'], 'plainvalue1234567890' ), 'key-name api_key masked' );
sm_check( false === strpos( $nested['headers']['Authorization'], 'zzzzzz111111yyyyyy' ), 'nested Authorization masked' );
sm_check( false === strpos( $nested['headers']['deep']['password'], 'hunter2hunter2' ), 'deeply nested password masked' );
sm_check( 'gpt-4o' === $nested['headers']['deep']['model'], 'deeply nested non-secret unchanged' );
sm_check( 5 === $nested['count'], 'non-string values untouched' );

// 3b. Key names are matched by whole segment, not substring.
$by_name = PressHub_AI_Secret_Masker::mask_array( [
    'author'         => 'Μαρία Παπαδοπούλου',
    'bypass'         => 'yes-please-keep',
    'keyword'        => 'Λευκωσία',
    'max_tokens'     => '16384',
    'cache_key'      => 'abc123def456ghi789',
    'story_key'      => 'cyprus-bases-talks',
    'X-Goog-Api-Key' => 'plainvalue1234567890',
    'apiKey'         => 'plainvalue1234567891',
    'client_secret'  => 'plainvalue1234567892',
    'worker-secret'  => 'plainvalue1234567893',
] );
sm_check( 'Μαρία Παπαδοπούλου' === $by_name['author'], "'author' is not treated as 'auth'" );
sm_check( 'yes-please-keep' === $by_name['bypass'], "'bypass' is not treated as 'pass'" );
sm_check( 'Λευκωσία' === $by_name['keyword'], "'keyword' is not treated as 'key'" );
sm_check( '16384' === $by_name['max_tokens'], "'max_tokens' is not treated as 'token'" );
sm_check( 'abc123def456ghi789' === $by_name['cache_key'], 'cache_key is allow-listed' );
sm_check( 'cyprus-bases-talks' === $by_name['story_key'], 'story_key is allow-listed' );
sm_check( false === strpos( $by_name['X-Goog-Api-Key'], 'plainvalue1234567890' ), 'header X-Goog-Api-Key masked' );
sm_check( false === strpos( $by_name['apiKey'], 'plainvalue1234567891' ), 'camelCase apiKey masked' );
sm_check( false === strpos( $by_name['client_secret'], 'plainvalue1234567892' ), 'client_secret masked' );
sm_check( false === strpos( $by_name['worker-secret'], 'plainvalue1234567893' ), 'kebab worker-secret masked' );

// 4. Already-masked values stay unchanged (idempotent).
$once  = PressHub_AI_Secret_Masker::mask_value( 'sk-proj-' . $body );
sm_check( $once === PressHub_AI_Secret_Masker::mask_value( $once ), 'mask_value is idempotent' );
sm_check( $once === PressHub_AI_Secret_Masker::mask_if_secret( $once ), 'mask_if_secret leaves masked value unchanged' );
sm_check( $once === PressHub_AI_Secret_Masker::mask_text( $once ), 'mask_text leaves masked value unchanged' );
$bm = PressHub_AI_Secret_Masker::mask_text( 'Authorization: Bearer abcdef123456ghijkl789' );
sm_check( $bm === PressHub_AI_Secret_Masker::mask_text( $bm ), 'mask_text is idempotent on Bearer output' );

// 5. Non-secret text unchanged.
$plain_cases = [
    'Provider openai returned HTTP 500 after 1200ms',
    'The items-list and risks-overview are fine',
    'sk-1',
    'ghp_ab',
    'Bearer',
    '',
];
foreach ( $plain_cases as $pc ) {
    sm_check( $pc === PressHub_AI_Secret_Masker::mask_text( $pc ), 'non-secret text unchanged: ' . $pc );
}
sm_check( 'hello world' === PressHub_AI_Secret_Masker::mask_if_secret( 'hello world' ), 'mask_if_secret leaves plain string' );
sm_check( '' === PressHub_AI_Secret_Masker::mask_value( '   ' ), 'mask_value of blank is empty' );

// 6. Multiple keys in one text.
$multi = PressHub_AI_Secret_Masker::mask_text( 'a sk-ant-api03-AAAAAAAAAAAA b ghp_BBBBBBBBBBBBBB c' );
sm_check( false === strpos( $multi, 'AAAAAAAAAAAA' ) && false === strpos( $multi, 'BBBBBBBBBBBBBB' ), 'mask_text masks every key in text' );

if ( empty( $failures ) ) {
    echo "SecretMaskerTest: OK ({$checks} checks passed)\n";
    exit( 0 );
}
echo 'SecretMaskerTest: FAILED with ' . count( $failures ) . " failure(s):\n";
foreach ( $failures as $f ) {
    echo "  - {$f}\n";
}
exit( 1 );
