<?php
/**
 * Tests for the rider mapper.
 *
 * Plain PHP, no PHPUnit and no WordPress, on the same precedent as
 * test-prompt-mapper.php. The fixtures are real captures — a `wbsearchentities` and
 * `wbgetentities` response for a current pro rider, a retired one, a rider with no
 * discipline claim, a same-name non-cyclist, and a rider with a real same-labelled
 * namesake — taken from Wikidata on 19 August 2026. None of these shapes was invented
 * to make a test pass; every edge case below is something Wikidata's real data does.
 *
 * Run: php workflow-discovery-cycling/tests/test-rider-mapper.php
 *
 * @package WorkflowDiscoveryCycling
 */

declare( strict_types=1 );

namespace WorkflowDiscoveryCycling;

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/../includes/class-rider-mapper.php';

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

/**
 * Load a fixture file as a decoded array.
 *
 * @param string $name Filename, without directory.
 * @return array
 */
function fixture( string $name ): array {
	$path = __DIR__ . '/fixtures/' . $name;
	$raw  = file_get_contents( $path );

	return json_decode( (string) $raw, true );
}

// Fixtures: a current pro rider (Remco Evenepoel), a retired one (Alberto Contador),
// a rider with no discipline claim on Wikidata (Asensio Navarro), a same-name
// non-cyclist (Mo Farah, a track and field athlete), and a rider (Tadej Pogačar) who
// shares his exact name with an unrelated Slovenian painter.
$evenepoel_search    = fixture( 'wikidata-search-evenepoel.json' );
$evenepoel_entity    = fixture( 'wikidata-entity-evenepoel.json' );
$evenepoel_labels    = fixture( 'wikidata-labels-evenepoel.json' );
$evenepoel_victories = fixture( 'wikidata-victories-evenepoel.json' );

$contador_search    = fixture( 'wikidata-search-contador.json' );
$contador_entity    = fixture( 'wikidata-entity-contador.json' );
$contador_labels    = fixture( 'wikidata-labels-contador.json' );
$contador_victories = fixture( 'wikidata-victories-contador.json' );

$navarro_search    = fixture( 'wikidata-search-navarro.json' );
$navarro_entity    = fixture( 'wikidata-entity-navarro.json' );
$navarro_labels    = fixture( 'wikidata-labels-navarro.json' );
$navarro_victories = fixture( 'wikidata-victories-navarro.json' );

$farah_search = fixture( 'wikidata-search-farah.json' );
$farah_entity = fixture( 'wikidata-entity-farah.json' );

$pogacar_search = fixture( 'wikidata-search-pogacar.json' );

// ── Stage A: resolve_candidate() ────────────────────────────────────────────────

check( 'Q50821956', Rider_Mapper::resolve_candidate( $evenepoel_search, 'Remco Evenepoel' ), 'a single exact-label candidate resolves' );
check( 'Q132738', Rider_Mapper::resolve_candidate( $contador_search, 'Alberto Contador' ), 'a single exact-label candidate resolves (retired rider)' );
check( 'Q5708079', Rider_Mapper::resolve_candidate( $navarro_search, 'Asensio Navarro' ), 'a single exact-label candidate resolves (no-discipline rider)' );
check( 'Q1671', Rider_Mapper::resolve_candidate( $farah_search, 'Mo Farah' ), 'a single exact-label candidate resolves even when it is not a cyclist — that check is stage B' );

check( null, Rider_Mapper::resolve_candidate( $pogacar_search, 'Tadej Pogačar' ), 'two exact-label candidates (the rider and a same-named painter) refuses to pick one' );
check( null, Rider_Mapper::resolve_candidate( $pogacar_search, 'Tadej Pogacar' ), 'a dropped diacritic matches no candidate label exactly, and is refused rather than fuzzy-matched' );
check( null, Rider_Mapper::resolve_candidate( array(), 'Anyone' ), 'no candidates at all resolves to nothing' );

check(
	null,
	Rider_Mapper::resolve_candidate( $evenepoel_search, 'R.EV Cycling Academy Alias Only' ),
	'a typed name matching nothing exactly resolves to nothing'
);

// A candidate whose *alias*, not label, matches exactly.
check(
	'Q120175844',
	Rider_Mapper::resolve_candidate( $evenepoel_search, 'Remco Evenepoel Cycling Academy' ),
	'an exact alias match resolves just as a label match does'
);

// ── label_of() ───────────────────────────────────────────────────────────────────

check( 'Remco Evenepoel', Rider_Mapper::label_of( $evenepoel_search, 'Q50821956' ), 'the resolved candidate\'s own label is used for display' );
check( '', Rider_Mapper::label_of( $evenepoel_search, 'Q999999' ), 'an unknown QID has no label to report' );

// ── Stage B: is_cyclist() ────────────────────────────────────────────────────────

check( true, Rider_Mapper::is_cyclist( $evenepoel_entity ), 'an occupation of sport cyclist counts as a cyclist' );
check( true, Rider_Mapper::is_cyclist( $contador_entity ), 'a retired rider still carries the cyclist occupation claim' );
check( true, Rider_Mapper::is_cyclist( $navarro_entity ), 'a cyclist occupation claim is enough even with no discipline (sport) claim at all' );
check( false, Rider_Mapper::is_cyclist( $farah_entity ), 'a same-name athletics competitor carries no cycling occupation or sport claim' );
check( false, Rider_Mapper::is_cyclist( array() ), 'no claims at all is not a cyclist' );

// ── facts_from_entity() ───────────────────────────────────────────────────────────

$evenepoel_facts = Rider_Mapper::facts_from_entity( $evenepoel_entity, $evenepoel_labels );

check( 'Red Bull-Bora-Hansgrohe', $evenepoel_facts['team'], 'the P54 claim with a start date and no end date is read as the current team' );
check( 'Belgium', $evenepoel_facts['nationality'], 'nationality reads off the P27 claim' );
check( '2000-01-25', $evenepoel_facts['date_of_birth'], 'date of birth reads off the P569 claim' );
check( 'road bicycle racing', $evenepoel_facts['discipline'], 'discipline picks the P641 value that is a recognised cycling sport, not the football one also on this entity' );

$contador_facts = Rider_Mapper::facts_from_entity( $contador_entity, $contador_labels );

check( '', $contador_facts['team'], 'a retired rider whose every P54 claim has an end date has no current team, not their last one' );
check( 'Spain', $contador_facts['nationality'], 'nationality reads off the P27 claim' );
check( '1982-12-06', $contador_facts['date_of_birth'], 'date of birth reads off the P569 claim' );
check( 'road bicycle racing', $contador_facts['discipline'], 'discipline reads off the P641 claim' );

$navarro_facts = Rider_Mapper::facts_from_entity( $navarro_entity, $navarro_labels );

check( '', $navarro_facts['team'], 'no current team' );
check( 'Spain', $navarro_facts['nationality'], 'nationality reads off the P27 claim' );
check( '1970-09-27', $navarro_facts['date_of_birth'], 'date of birth reads off the P569 claim' );
check( '', $navarro_facts['discipline'], 'no P641 claim at all leaves discipline blank rather than guessed' );

// ── notable_results() ─────────────────────────────────────────────────────────────

check(
	array( 'Winner, 2026 Clásica de San Sebastián', 'Winner, 2026 Tour de France, Stage 16' ),
	Rider_Mapper::notable_results( $evenepoel_victories ),
	'the two most recent reverse-P1346 bindings, formatted, most recent first'
);

check(
	array( 'Winner, 2017 Vuelta a España, stage 20', 'Winner, 2017 Tour de France, stage 17' ),
	Rider_Mapper::notable_results( $contador_victories ),
	'a retired rider\'s most recent results are still their most recent, not their career-best'
);

check( array(), Rider_Mapper::notable_results( $navarro_victories ), 'no reverse-P1346 bindings means no notable results, not an empty guess' );
check( array(), Rider_Mapper::notable_results( array() ), 'an empty bindings list is handled the same as none' );

// ── map() end to end ──────────────────────────────────────────────────────────────

$evenepoel_card = Rider_Mapper::map( 'Remco Evenepoel', $evenepoel_search, $evenepoel_entity, $evenepoel_labels, $evenepoel_victories );

check(
	array(
		'riderQid'       => 'Q50821956',
		'name'           => 'Remco Evenepoel',
		'team'           => 'Red Bull-Bora-Hansgrohe',
		'nationality'    => 'Belgium',
		'dateOfBirth'    => '2000-01-25',
		'discipline'     => 'road bicycle racing',
		'notableResults' => array( 'Winner, 2026 Clásica de San Sebastián', 'Winner, 2026 Tour de France, Stage 16' ),
	),
	$evenepoel_card,
	'a fully resolvable current rider maps to a complete set of card fields'
);

$contador_card = Rider_Mapper::map( 'Alberto Contador', $contador_search, $contador_entity, $contador_labels, $contador_victories );

check(
	array(
		'riderQid'       => 'Q132738',
		'name'           => 'Alberto Contador',
		'team'           => '',
		'nationality'    => 'Spain',
		'dateOfBirth'    => '1982-12-06',
		'discipline'     => 'road bicycle racing',
		'notableResults' => array( 'Winner, 2017 Vuelta a España, stage 20', 'Winner, 2017 Tour de France, stage 17' ),
	),
	$contador_card,
	'a retired rider maps with an empty team rather than their last one'
);

$navarro_card = Rider_Mapper::map( 'Asensio Navarro', $navarro_search, $navarro_entity, $navarro_labels, $navarro_victories );

check(
	array(
		'riderQid'       => 'Q5708079',
		'name'           => 'Asensio Navarro',
		'team'           => '',
		'nationality'    => 'Spain',
		'dateOfBirth'    => '1970-09-27',
		'discipline'     => '',
		'notableResults' => array(),
	),
	$navarro_card,
	'a resolvable rider with sparse Wikidata data maps with each missing fact blank, not fabricated'
);

check(
	null,
	Rider_Mapper::map( 'Mo Farah', $farah_search, $farah_entity, array(), null ),
	'a resolvable same-name non-cyclist produces no card at all'
);

check(
	null,
	Rider_Mapper::map( 'Tadej Pogačar', $pogacar_search, null, array(), null ),
	'an ambiguous typed name (two exact candidates) produces no card, regardless of what entity data is available'
);

check(
	null,
	Rider_Mapper::map( 'Tadej Pogacar', $pogacar_search, null, array(), null ),
	'a misspelled (diacritic-dropped) typed name produces no card'
);

check(
	null,
	Rider_Mapper::map( 'Remco Evenepoel', $evenepoel_search, null, array(), null ),
	'a resolved candidate whose entity fetch failed produces no card rather than a name-only one'
);

// ── Report ───────────────────────────────────────────────────────────────────────

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
