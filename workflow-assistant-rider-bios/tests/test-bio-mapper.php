<?php
/**
 * Tests for the bio mapper.
 *
 * Plain PHP, no PHPUnit and no WordPress — the same convention as the rider
 * extractor's test and the cycling desk plugin's Prompt_Mapper test, and for the
 * same reason: this is the class that decides whether a Wikipedia page is
 * confidently about a cyclist, and what to trust out of it.
 *
 * This environment has no outbound network access to Wikipedia, so unlike the
 * cycling desk plugin's feed-items.json — a real capture — the fixture here
 * (tests/fixtures/wikipedia-samples.json) is built by hand against Wikipedia's
 * documented REST summary and categories response shapes. See that file's _note.
 * What is under test is the parsing logic against that shape, not any rider's
 * actual record.
 *
 * Run: php workflow-assistant-rider-bios/tests/test-bio-mapper.php
 *
 * @package WorkflowAssistantRiderBios
 */

declare( strict_types=1 );

namespace WorkflowAssistantRiderBios;

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/../includes/class-bio-mapper.php';

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

$samples = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/wikipedia-samples.json' ), true );

check( true, is_array( $samples ), 'the fixture loads' );

// Reference year matches this repo's fictional "today" — see CLAUDE.md's currentDate.
$reference_year = 2026;

// ── A confirmed cyclist gets every detail this class can read ───────────────

$bio = Bio_Mapper::map( (array) $samples['confirmed_cyclist'], $reference_year );

check( true, is_array( $bio ), 'a page described and categorised as a cyclist maps to a bio' );
check( 'Jens Voskuilen', $bio['name'] ?? null, 'the resolved title is the name' );
check( 'Belgian', $bio['nationality'] ?? null, 'nationality read from the "<Nationality> cyclists" category' );
check( 'Dunelight Cycling', $bio['current_team'] ?? null, 'current team read from a "rides for" sentence in the extract' );
check(
	array( 'He won a stage of the 2026 Vuelta a España.' ),
	$bio['recent_wins'] ?? null,
	'a win-shaped sentence naming a year no earlier than last year counts as recent'
);
check(
	array( 'Tour de France stage winners' ),
	$bio['notable_wins'] ?? null,
	'a major-race winner category counts as notable, and a plain "riders" category does not'
);
check( 'https://en.wikipedia.org/wiki/Jens_Voskuilen', $bio['source_url'] ?? null, 'the source url is carried through' );

// ── A cyclist with nothing extra to say still maps, with blanks rather than guesses ──

$bio = Bio_Mapper::map( (array) $samples['cyclist_no_extras'], $reference_year );

check( true, is_array( $bio ), 'a plainly-described cyclist with no further detail still maps' );
check( 'Canadian', $bio['nationality'] ?? null, 'nationality is still read' );
check( '', $bio['current_team'] ?? null, 'no team sentence means an empty field, not a guess' );
check( array(), $bio['recent_wins'] ?? null, 'no win-shaped sentence means no recent wins' );
check( array(), $bio['notable_wins'] ?? null, 'no winner category means no notable wins' );

// ── A cyclist identified only by category, not by description ───────────────

$bio = Bio_Mapper::map( (array) $samples['cyclist_by_category_only'], $reference_year );

check( true, is_array( $bio ), 'a cyclist category is enough on its own, with no "cyclist" in the description' );
check( 'Norwegian', $bio['nationality'] ?? null, 'nationality is read from that same category' );

// ── A page that is not a person is discarded outright ────────────────────────

check(
	null,
	Bio_Mapper::map( (array) $samples['not_a_cyclist'], $reference_year ),
	'a page with no "cyclist" in its description or categories is discarded entirely, not returned half-empty'
);

// ── A win from two years ago is not "recent" ─────────────────────────────────

$stale = array(
	'title'       => 'Test Rider',
	'description' => 'Test cyclist',
	'extract'     => 'Test Rider is a test cyclist. He won a stage of the 2023 Vuelta a España.',
	'categories'  => array( 'Test cyclists' ),
	'url'         => 'https://en.wikipedia.org/wiki/Test_Rider',
);

check(
	array(),
	Bio_Mapper::map( $stale, $reference_year )['recent_wins'] ?? null,
	'a win-shaped sentence naming a year more than a year ago is not counted as recent'
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
