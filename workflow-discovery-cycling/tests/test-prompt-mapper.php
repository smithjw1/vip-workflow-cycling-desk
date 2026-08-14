<?php
/**
 * Tests for the prompt mapper.
 *
 * Plain PHP, no PHPUnit and no WordPress. The mapper is deliberately the one class
 * here with no WordPress in it, because it holds all the guessing — which race a
 * headline names, which kind of story a publisher's category implies — and guessing
 * is the part that needs a test.
 *
 * The fixture is a real capture of all three feeds on 14 August 2026, kept as-is.
 * Heuristics tuned against invented headlines pass on invented headlines: every
 * false positive fixed here came out of that capture, and none of them was a shape
 * anyone would have thought to write by hand.
 *
 * Run: php workflow-discovery-cycling/tests/test-prompt-mapper.php
 *
 * @package WorkflowDiscoveryCycling
 */

declare( strict_types=1 );

namespace WorkflowDiscoveryCycling;

define( 'ABSPATH', __DIR__ );

/**
 * Stands in for the plugin bootstrap, which the mapper only needs for its slug.
 */
class Plugin {
	const PROVIDER_SLUG = 'cycling-desk';
}

require_once __DIR__ . '/../includes/class-prompt-mapper.php';

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

// ── Race read out of a headline ──────────────────────────────────────────────

$race_cases = array(
	// The convention, which is the whole reason this works.
	'Volta a Portugal: Neutralised stage 8 concludes in mourning' => 'Volta a Portugal',
	'Arctic Race of Norway: Erlend Blikra gets a home win'        => 'Arctic Race of Norway',
	'Czech Tour: Netcompany Ineos dominate stage 2'               => 'Czech Tour',

	// A product review reads exactly like the convention and is not a race.
	'Fara Gr4 review: Norwegian sensibilities mate with an all-road bike' => '',
	'Insta360 X6 Review: Why the Best Action Camera for Cycling'  => '',

	// Section labels before the colon.
	'Analysis: why the sprint trains keep getting it wrong'       => '',
	'Video: The Dust, Determination, and Pure Joy of SBT GRVL'     => '',
	'Live: stage 14 as it happened'                                => '',

	// Feature headlines that clear every other test.
	'From Moneyball to Sweat Science: How Allen Lim Is Transforming a Team' => '',
	'How to Watch the 2026 Vuelta a España: every broadcaster'     => '',

	// Reference material, not a race report about the race it names.
	'Tour of Britain Women 2026 start list: Lotte Kopecky leads'   => '',
	'2026 Vuelta a España Cheat Sheet: Route, Contenders, Odds'     => '',

	// A quoted colon is punctuation.
	"'It's a big problem, for sure': how in-race motorbikes affect racing" => '',

	// No colon at all.
	'Visma-Lease a Bike confirm Fabio Jakobsen joins team'         => '',
);

foreach ( $race_cases as $title => $expected ) {
	check( $expected, Prompt_Mapper::race_from_title( (string) $title ), sprintf( 'race_from_title(%s)', $title ) );
}

// ── Race read out of categories ──────────────────────────────────────────────

// Velo tags related races, not the subject, so the name has to be in the headline too.
check(
	'',
	Prompt_Mapper::race_from_categories(
		array( 'categories' => array( 'News', 'Tour de France', 'Transfers' ) ),
		'The Monster Win Bonuses That Add Millions to Pogačar’s Payday'
	),
	'a race tagged but not in the headline is not this story’s race'
);

check(
	'Vuelta a España',
	Prompt_Mapper::race_from_categories(
		array( 'categories' => array( 'Tour de France', 'Vuelta a España' ) ),
		'How to Watch the 2026 Vuelta a España in the USA'
	),
	'the tagged race that is in the headline wins over the one that is not'
);

check(
	'Life Time Grand Prix',
	Prompt_Mapper::race_from_categories(
		array( 'categories' => array( 'life time grand prix' ) ),
		'Life Time Grand Prix Overhauls Qualifying Process for 2027'
	),
	'the headline’s capitalisation is used, not the category’s'
);

check(
	'',
	Prompt_Mapper::race_from_categories(
		array( 'categories' => array( 'Racing', 'Road Racing', 'Pro Cycling' ) ),
		'Racing is back and the peloton is faster than ever'
	),
	'section labels containing "racing" are not race names'
);

// ── Story type ───────────────────────────────────────────────────────────────

check(
	'Race report',
	Prompt_Mapper::story_type_from( array( 'categories' => array( 'Racing', 'Pro Cycling' ) ), 'Czech Tour' ),
	'Racing plus a named race is a race report'
);

check(
	'',
	Prompt_Mapper::story_type_from( array( 'categories' => array( 'Racing', 'Pro Cycling' ) ), '' ),
	'Racing on its own says nothing — Cyclingnews files most of its output there'
);

check(
	'Rider news',
	Prompt_Mapper::story_type_from( array( 'categories' => array( 'Racing', 'Teams & Riders' ) ), 'Czech Tour' ),
	'a specific category beats the racing one — this is the transfer-news case'
);

check(
	'Tech',
	Prompt_Mapper::story_type_from( array( 'categories' => array( 'Reviews', 'Bikes' ) ), '' ),
	'reviews are tech'
);

check(
	'',
	Prompt_Mapper::story_type_from( array( 'categories' => array( 'Fitness', 'Pro Cycling' ) ), '' ),
	'a category with no mapping produces no hint rather than a fallback'
);

// ── Mapping the fixture ──────────────────────────────────────────────────────

$items = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/feed-items.json' ), true );

check( true, is_array( $items ) && count( $items ) > 0, 'the fixture loads' );

$prompts = Prompt_Mapper::map_all( (array) $items, 42 );

check( count( (array) $items ), count( $prompts ), 'every fixture item maps to a prompt' );

foreach ( $prompts as $prompt ) {
	check( 42, $prompt['blueprint_id'] ?? 0, 'each prompt names the sequence it is heading for' );
	check( 'cycling-desk', $prompt['provider'], 'each prompt names this provider' );
}

// An item with no sequence gets no blueprint_id at all, rather than a zero.
$without = Prompt_Mapper::map( (array) $items[0], 0 );
check( false, array_key_exists( 'blueprint_id', (array) $without ), 'no sequence means the key is absent' );

/*
 * The detections this fixture is expected to make, checked by exact set rather than
 * by count. A count going green while the contents drifted is the failure a
 * regression test is supposed to catch.
 */
$detected = array();

foreach ( $prompts as $prompt ) {
	$race = (string) $prompt['meta']['race'];

	if ( '' !== $race ) {
		$detected[ $race ] = ( $detected[ $race ] ?? 0 ) + 1;
	}
}

ksort( $detected );

check(
	array(
		'Arctic Race of Norway' => 2,
		'Czech Tour'            => 1,
		'Life Time Grand Prix'  => 1,
		'Tour de France Femmes' => 2,
		'Volta a Portugal'      => 2,
		'Vuelta a España'       => 3,
	),
	$detected,
	'the races found in the 14 August 2026 capture'
);

// ── Seed text ────────────────────────────────────────────────────────────────

$seed = Prompt_Mapper::seed(
	array(
		'title'       => 'Czech Tour: Netcompany Ineos dominate stage 2',
		'description' => 'The British team put three riders in the front group.',
		'url'         => 'https://example.com/czech-tour',
		'meta'        => array(
			'publisher'  => 'Cyclingnews',
			'byline'     => 'Dani Ostanek',
			'race'       => 'Czech Tour',
			'story_type' => 'Race report',
		),
	)
);

check( true, str_contains( $seed, 'Reported by Dani Ostanek in Cyclingnews.' ), 'the seed attributes the source' );
check( true, str_contains( $seed, 'https://example.com/czech-tour' ), 'the seed carries the source url' );
check( true, str_contains( $seed, 'to confirm rather than assume' ), 'the hints are framed as hints, not decisions' );
check( true, str_contains( $seed, 'the race looks to be Czech Tour' ), 'the race hint is in the seed' );

$bare = Prompt_Mapper::seed(
	array(
		'title'       => 'Something happened',
		'description' => '',
		'url'         => '',
		'meta'        => array(
			'publisher'  => '',
			'byline'     => '',
			'race'       => '',
			'story_type' => '',
		),
	)
);

check( 'Something happened', $bare, 'a prompt with nothing derived seeds as just its title' );
check( false, str_contains( $bare, 'Commissioning hints' ), 'no hints means no hints section' );

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
