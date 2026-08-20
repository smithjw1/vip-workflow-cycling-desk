<?php
/**
 * Tests for the rider extractor.
 *
 * Plain PHP, no PHPUnit and no WordPress, the same convention the cycling desk
 * plugin's own test uses — this is the class in this plugin that guesses, so it is
 * the one that needs a test independent of WordPress.
 *
 * The fixture sentences are drawn from the real feed capture already kept in this
 * repo at workflow-discovery-cycling/tests/fixtures/feed-items.json (14 August
 * 2026), rather than invented headlines — every case here is a shape that capture
 * actually produced once the extractor was run against it, the same reasoning the
 * cycling desk plugin's own fixture is built on.
 *
 * Run: php workflow-assistant-rider-bios/tests/test-rider-extractor.php
 *
 * @package WorkflowAssistantRiderBios
 */

declare( strict_types=1 );

namespace WorkflowAssistantRiderBios;

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/../includes/class-rider-extractor.php';

/**
 * Test state.
 *
 * @var array{passed: int, failed: array<int, string>}
 */
$results = array(
	'passed' => 0,
	'failed' => array(),
);

/**
 * Assert two values match.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 * @param string $message  What is being checked.
 */
function check( $expected, $actual, string $message ): void {
	global $results;

	if ( $expected === $actual ) {
		++$results['passed'];
		return;
	}

	$results['failed'][] = sprintf(
		"%s\n    expected: %s\n    actual:   %s",
		$message,
		var_export( $expected, true ),
		var_export( $actual, true )
	);
}

// ── Candidates read out of real story sentences ─────────────────────────────

$cases = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/story-sentences.json' ), true );

check( true, is_array( $cases ) && count( $cases ) > 0, 'the fixture loads' );

foreach ( (array) $cases as $case ) {
	check(
		$case['expected'],
		Rider_Extractor::extract_candidates( (string) $case['text'] ),
		(string) $case['note']
	);
}

// ── Individual shapes, isolated from the fixture sentences ──────────────────

check(
	array(),
	Rider_Extractor::extract_candidates( '' ),
	'empty text finds nothing'
);

check(
	array( 'Mathieu Van der Poel' ),
	Rider_Extractor::extract_candidates( 'Mathieu Van der Poel attacked with two laps to go.' ),
	'a two-particle surname is read as one name'
);

check(
	array(),
	Rider_Extractor::extract_candidates( 'Pogačar attacked with two laps to go.' ),
	'a single capitalised word — a surname alone — is not enough to be a candidate'
);

check(
	array( 'Anna Kiesenhofer', 'Marion Rousse' ),
	Rider_Extractor::extract_candidates( 'Anna Kiesenhofer spoke to race director Marion Rousse after the stage.' ),
	'two names in one sentence are both found, in order'
);

check(
	array( 'Tadej Pogačar' ),
	Rider_Extractor::extract_candidates( 'Tadej Pogačar attacked on the final climb. Tadej Pogačar celebrated at the line.' ),
	'the same name mentioned twice in one story is only returned once'
);

check(
	array(),
	Rider_Extractor::extract_candidates( 'JV Team riders swept the podium.' ),
	'an all-caps acronym rules out the run it is part of'
);

check(
	array(),
	Rider_Extractor::extract_candidates( 'Model X9 Pro is the new flagship groupset.' ),
	'a word carrying a digit is not a name, even where it would otherwise look like one'
);

// ── Report ───────────────────────────────────────────────────────────────────

echo "\n";

if ( $results['failed'] ) {
	printf( "FAILED — %d passed, %d failed\n\n", $results['passed'], count( $results['failed'] ) );

	foreach ( $results['failed'] as $failure ) {
		printf( "  ✗ %s\n\n", $failure );
	}

	exit( 1 );
}

printf( "OK — %d assertions passed\n", $results['passed'] );
exit( 0 );
