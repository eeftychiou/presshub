<?php
/**
 * TDD Tests for Issue #45: UTF-8 word counting for multilingual articles.
 *
 * Covers PressHub_AI_Context_Estimator:
 *   - utf8_word_count() handles Greek, Cyrillic, Arabic, Hebrew, CJK scripts
 *   - utf8_word_count() matches str_word_count() on plain ASCII text
 *   - Greek 600-word regression (the literal bug report)
 *   - HTML tag stripping and entity decoding
 *   - Mixed-script tokens (Latin + CJK)
 *   - CJK codepoint counting for Han / Hiragana / Katakana / Hangul
 *   - Empty / whitespace-only input
 *   - estimate_tokens() chars/3 heuristic
 *   - summarize() returns both words and tokens
 *   - clamp_max_context_tokens() boundary handling
 *   - compute_memory_budget_for_context() shrinking under context caps
 *   - estimate_prompt_with_memory() token and char breakdown
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-context-estimator.php';

class ContextEstimatorTest {

	public static function run(): void {
		$failures = [];

		// -------------------------------------------------------------
		// Case 1: Empty / whitespace / null input returns 0
		// -------------------------------------------------------------
		$inputs = [ '', '   ', "\n\t  ", null ];
		foreach ( $inputs as $i => $in ) {
			$count = PressHub_AI_Context_Estimator::utf8_word_count( $in );
			if ( 0 !== $count ) {
				$failures[] = "Case 1[{$i}]: expected 0 words for empty input, got {$count}";
			}
		}

		// -------------------------------------------------------------
		// Case 2: Plain ASCII matches str_word_count exactly
		// -------------------------------------------------------------
		$ascii = 'The quick brown fox jumps over the lazy dog.';
		$expected = str_word_count( $ascii );
		$count = PressHub_AI_Context_Estimator::utf8_word_count( $ascii );
		if ( $count !== $expected ) {
			$failures[] = "Case 2: ASCII count mismatch; expected {$expected}, got {$count}";
		}

		// -------------------------------------------------------------
		// Case 3: Greek 600-word regression (literal issue #45)
		// The previous str_word_count() implementation reported ~32.
		// -------------------------------------------------------------
		$greek_words = [
			'Ελλάδα', 'και', 'Κύπρος', 'έχουν', 'μακρά', 'ιστορία',
			'πολιτικών', 'και', 'πολιτιστικών', 'σχέσεων', 'που', 'ξεκινούν',
			'από', 'την', 'αρχαιότητα', 'και', 'συνεχίζονται', 'μέχρι', 'σήμερα',
			'Οι', 'δύο', 'χώρες', 'μοιράζονται', 'κοινή', 'γλώσσα', 'θρησκεία',
			'και', 'πολλά', 'πολιτιστικά', 'στοιχεία', 'που', 'ενισχύουν', 'τους',
			'δεσμούς', 'μεταξύ', 'των', 'λαών', 'τους', 'Η', 'συνεργασία', 'αυτή',
		];
		$greek_article = trim( str_repeat( implode( ' ', $greek_words ) . ' ', 14 ) );
		$expected_greek = count( $greek_words ) * 14; // 560 words
		$count = PressHub_AI_Context_Estimator::utf8_word_count( $greek_article );
		if ( $count < 500 ) {
			$failures[] = "Case 3 (Greek regression): expected ~560 words, got {$count}";
		}
		if ( abs( $count - $expected_greek ) > 5 ) {
			$failures[] = "Case 3 (Greek regression): off by more than 5; expected ~{$expected_greek}, got {$count}";
		}

		// -------------------------------------------------------------
		// Case 4: Mixed Greek + Latin (the exact bug scenario)
		// -------------------------------------------------------------
		$mixed = 'Το URL https://example.com έχει 32 λέξεις.';
		$count = PressHub_AI_Context_Estimator::utf8_word_count( $mixed );
		// Greek words (4) + URL (1) + Latin numbers (1) = ~6
		if ( $count < 5 || $count > 10 ) {
			$failures[] = "Case 4 (mixed Greek+Latin): expected ~6 words, got {$count}";
		}

		// -------------------------------------------------------------
		// Case 5: Cyrillic (Russian)
		// -------------------------------------------------------------
		$russian = 'Россия и Китай имеют долгую историю дипломатических отношений';
		$count = PressHub_AI_Context_Estimator::utf8_word_count( $russian );
		if ( $count < 8 ) {
			$failures[] = "Case 5 (Cyrillic): expected at least 8 words, got {$count}";
		}

		// -------------------------------------------------------------
		// Case 6: Han / CJK - each codepoint counts as one word
		// -------------------------------------------------------------
		$han = '今天天气很好我们一起去公园散步吧'; // 16 Han codepoints, no spaces
		$count = PressHub_AI_Context_Estimator::utf8_word_count( $han );
		if ( $count !== 16 ) {
			$failures[] = "Case 6 (Han): expected 16 codepoints, got {$count}";
		}

		// -------------------------------------------------------------
		// Case 7: Hiragana / Katakana
		// -------------------------------------------------------------
		$jp = 'こんにちはカタカナテストです';
		$count = PressHub_AI_Context_Estimator::utf8_word_count( $jp );
		if ( $count !== 14 ) {
			$failures[] = "Case 7 (Japanese): expected 14 codepoints, got {$count}";
		}

		// -------------------------------------------------------------
		// Case 8: Hangul
		// -------------------------------------------------------------
		$ko = '안녕하세요한국어테스트';
		$count = PressHub_AI_Context_Estimator::utf8_word_count( $ko );
		if ( $count !== 11 ) {
			$failures[] = "Case 8 (Hangul): expected 11 codepoints, got {$count}";
		}

		// -------------------------------------------------------------
		// Case 9: HTML tags are stripped
		// -------------------------------------------------------------
		$html = '<p>Hello <strong>brave</strong> new <em>world</em>.</p>';
		$count = PressHub_AI_Context_Estimator::utf8_word_count( $html );
		if ( 4 !== $count ) {
			$failures[] = "Case 9 (HTML strip): expected 4 words, got {$count}";
		}

		// -------------------------------------------------------------
		// Case 10: HTML entities are decoded
		// -------------------------------------------------------------
		$entities = 'AT&amp;T &lt;tag&gt; &quot;quote&quot;';
		$count = PressHub_AI_Context_Estimator::utf8_word_count( $entities );
		// AT&T, <tag>, "quote" → 4 whitespace-delimited tokens
		if ( $count < 3 ) {
			$failures[] = "Case 10 (entities): expected >=3 words, got {$count}";
		}

		// -------------------------------------------------------------
		// Case 11: Mixed Latin + CJK within one token
		// -------------------------------------------------------------
		$mixed_token = 'Hello世界World';
		$count = PressHub_AI_Context_Estimator::utf8_word_count( $mixed_token );
		// 'Hello' (1) + 2 Han codepoints + 'World' (1) = 4
		if ( $count !== 4 ) {
			$failures[] = "Case 11 (mixed Latin+CJK): expected 4, got {$count}";
		}

		// -------------------------------------------------------------
		// Case 12: Arabic
		// -------------------------------------------------------------
		$ar = 'السلام عليكم ورحمة الله وبركاته';
		$count = PressHub_AI_Context_Estimator::utf8_word_count( $ar );
		if ( $count < 5 ) {
			$failures[] = "Case 12 (Arabic): expected >=5 words, got {$count}";
		}

		// -------------------------------------------------------------
		// Case 13: estimate_tokens() uses chars/3 heuristic
		// -------------------------------------------------------------
		$text = str_repeat( 'a', 30 ); // 30 chars → 10 tokens
		$tokens = PressHub_AI_Context_Estimator::estimate_tokens( $text );
		if ( 10 !== $tokens ) {
			$failures[] = "Case 13 (estimate_tokens): expected 10, got {$tokens}";
		}

		// -------------------------------------------------------------
		// Case 14: summarize() returns both fields
		// -------------------------------------------------------------
		$summary = PressHub_AI_Context_Estimator::summarize( 'Hello brave new world' );
		if ( ! is_array( $summary ) || ! isset( $summary['words'], $summary['tokens'] ) ) {
			$failures[] = 'Case 14 (summarize): missing words/tokens keys';
		} else {
			if ( 4 !== $summary['words'] ) {
				$failures[] = "Case 14 (summarize): expected words=4, got {$summary['words']}";
			}
			if ( $summary['tokens'] < 4 ) {
				$failures[] = "Case 14 (summarize): expected tokens>=4, got {$summary['tokens']}";
			}
		}

		// -------------------------------------------------------------
		// Case 15: clamp_max_context_tokens() boundary handling
		// -------------------------------------------------------------
		$clamp_cases = [
			'below_min'   => [ 1000,         PressHub_AI_Context_Estimator::MIN_MAX_CONTEXT_TOKENS ],
			'at_min'      => [ PressHub_AI_Context_Estimator::MIN_MAX_CONTEXT_TOKENS, PressHub_AI_Context_Estimator::MIN_MAX_CONTEXT_TOKENS ],
			'in_range'    => [ 50000,        50000 ],
			'at_max'      => [ PressHub_AI_Context_Estimator::MAX_MAX_CONTEXT_TOKENS, PressHub_AI_Context_Estimator::MAX_MAX_CONTEXT_TOKENS ],
			'above_max'   => [ 999999,       PressHub_AI_Context_Estimator::MAX_MAX_CONTEXT_TOKENS ],
			'non_numeric' => [ 'abc',        PressHub_AI_Context_Estimator::DEFAULT_MAX_CONTEXT_TOKENS ],
			'null'        => [ null,         PressHub_AI_Context_Estimator::DEFAULT_MAX_CONTEXT_TOKENS ],
			'string_int'  => [ '75000',      75000 ],
		];
		foreach ( $clamp_cases as $name => $case ) {
			[ $input, $expected ] = $case;
			$actual = PressHub_AI_Context_Estimator::clamp_max_context_tokens( $input );
			if ( $actual !== $expected ) {
				$failures[] = "Case 15 ({$name}): expected {$expected}, got {$actual}";
			}
		}

		// -------------------------------------------------------------
		// Case 16: Constants exposed
		// -------------------------------------------------------------
		if ( 5000 !== PressHub_AI_Context_Estimator::MIN_MAX_CONTEXT_TOKENS ) {
			$failures[] = 'Case 16: MIN_MAX_CONTEXT_TOKENS != 5000';
		}
		if ( 200000 !== PressHub_AI_Context_Estimator::MAX_MAX_CONTEXT_TOKENS ) {
			$failures[] = 'Case 16: MAX_MAX_CONTEXT_TOKENS != 200000';
		}
		if ( 40000 !== PressHub_AI_Context_Estimator::DEFAULT_MAX_CONTEXT_TOKENS ) {
			$failures[] = 'Case 16: DEFAULT_MAX_CONTEXT_TOKENS != 40000';
		}

		// -------------------------------------------------------------
		// Case 17: Whitespace-heavy content handled correctly
		// -------------------------------------------------------------
		$ws = "   \t\n  Hello   \t\n   World   \n  ";
		$count = PressHub_AI_Context_Estimator::utf8_word_count( $ws );
		if ( 2 !== $count ) {
			$failures[] = "Case 17 (whitespace): expected 2 words, got {$count}";
		}

		// -------------------------------------------------------------
		// Case 18: Emojis count as characters in estimate_tokens
		// -------------------------------------------------------------
		$emoji = '🚀🌟'; // 2 codepoints, 8 UTF-16 code units
		$tokens = PressHub_AI_Context_Estimator::estimate_tokens( $emoji );
		// 2 codepoints / 3 = 1
		if ( $tokens < 1 ) {
			$failures[] = "Case 18 (emoji tokens): expected >=1, got {$tokens}";
		}

		// -------------------------------------------------------------
		// Case 19: compute_memory_budget_for_context() shrinking
		// -------------------------------------------------------------
		// Within bounds: 12000 configured + 10000 articles <= 40000 * 3 (120000 chars) -> returns 12000
		$b1 = PressHub_AI_Context_Estimator::compute_memory_budget_for_context( 12000, 10000 );
		if ( 12000 !== $b1 ) {
			$failures[] = "Case 19 (within budget): expected 12000, got {$b1}";
		}

		// Shrunk: max_tokens = 5000 (15000 chars max). articles = 10000. configured memory = 8000.
		// Remaining = 15000 - 10000 = 5000 chars. Shrunk to 5000.
		$b2 = PressHub_AI_Context_Estimator::compute_memory_budget_for_context( 8000, 10000, 5000 );
		if ( 5000 !== $b2 ) {
			$failures[] = "Case 19 (shrunk): expected 5000, got {$b2}";
		}

		// Zero configured budget returns 0
		$b3 = PressHub_AI_Context_Estimator::compute_memory_budget_for_context( 0, 10000 );
		if ( 0 !== $b3 ) {
			$failures[] = "Case 19 (zero budget): expected 0, got {$b3}";
		}

		// Articles completely exceed total capacity -> memory shrinks to 0
		$b4 = PressHub_AI_Context_Estimator::compute_memory_budget_for_context( 8000, 20000, 5000 );
		if ( 0 !== $b4 ) {
			$failures[] = "Case 19 (exhausted capacity): expected 0, got {$b4}";
		}

		// -------------------------------------------------------------
		// Case 20: estimate_prompt_with_memory()
		// -------------------------------------------------------------
		$art = "Ειδήσεις της ημέρας.";
		$mem = "Ιστορικό προηγούμενων ημερών.";
		$est = PressHub_AI_Context_Estimator::estimate_prompt_with_memory( $art, $mem );
		if ( $est['articles_chars'] !== mb_strlen( $art, 'UTF-8' ) || $est['memory_chars'] !== mb_strlen( $mem, 'UTF-8' ) ) {
			$failures[] = "Case 20 (chars mismatch): " . json_encode( $est );
		}
		if ( $est['total_chars'] !== ( $est['articles_chars'] + $est['memory_chars'] ) ) {
			$failures[] = "Case 20 (total chars): " . json_encode( $est );
		}
		if ( $est['total_tokens'] !== ( $est['articles_tokens'] + $est['memory_tokens'] ) ) {
			$failures[] = "Case 20 (total tokens): " . json_encode( $est );
		}

		// Output Results
		if ( $failures ) {
			fwrite( STDERR, "FAIL\n" );
			foreach ( $failures as $f ) {
				fwrite( STDERR, "  - {$f}\n" );
			}
			exit( 1 );
		}
		echo "OK\n";
	}
}

ContextEstimatorTest::run();
