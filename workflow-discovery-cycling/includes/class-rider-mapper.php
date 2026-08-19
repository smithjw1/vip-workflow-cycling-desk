<?php
/**
 * Turns raw Wikidata JSON into either "no card" or a fully-formed set of card facts.
 *
 * This is the class with the guessing in it — which of several same-named search
 * candidates is the rider that was typed, and whether that candidate is even a
 * cyclist — so it is the class with the test, for the same reason `Prompt_Mapper` is:
 * it holds the heuristics, and heuristics need a fixture to be checked against.
 *
 * Two stages, and both fail closed. Stage one (`resolve_candidate`) keeps only
 * candidates whose label or an alias is an exact, case-insensitive match for what was
 * typed; anything other than exactly one survivor is refused rather than guessed at.
 * Stage two (`is_cyclist`) rejects the single survivor anyway if nothing in their
 * claims says they play this sport — a common name can resolve to exactly one
 * Wikidata entry and still be the wrong person entirely. Getting the wrong rider's
 * palmarès into print is the failure this guards against, so a fuzzy match is treated
 * as no match at all.
 *
 * No WordPress in this file, on purpose — see CLAUDE.md.
 *
 * @package WorkflowDiscoveryCycling
 */

declare( strict_types=1 );

namespace WorkflowDiscoveryCycling;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps Wikidata search/entity/SPARQL responses to rider card facts.
 */
class Rider_Mapper {

	/**
	 * Occupation (P106) values that mean "this person is a competitive cyclist".
	 *
	 * @var array<int, string>
	 */
	private const CYCLIST_OCCUPATIONS = array(
		'Q2309784',  // sport cyclist.
		'Q15117395', // track cyclist.
		'Q15117415', // cyclo-cross cyclist.
	);

	/**
	 * Sport (P641) values that count as a cycling discipline, mapped to the label
	 * Wikidata gives each one.
	 *
	 * Also what `is_cyclist()` checks P641 against — a rider who played another sport
	 * first (several do) carries that sport's item here too, and it must not count.
	 *
	 * @var array<string, string>
	 */
	private const CYCLING_SPORTS = array(
		'Q2215841' => 'cycle sport',
		'Q3609'    => 'road bicycle racing',
		'Q221635'  => 'track cycling',
		'Q335638'  => 'cyclo-cross',
		'Q520611'  => 'mountain biking',
	);

	/**
	 * Most notable results kept per rider.
	 */
	private const MAX_NOTABLE_RESULTS = 2;

	/**
	 * Stage A: find the one candidate whose label or an alias exactly matches what
	 * was typed.
	 *
	 * Wikidata's own search is fuzzy and diacritic-insensitive — searching "Tadej
	 * Pogacar" surfaces the rider even though his name is "Tadej Pogačar" — which is
	 * exactly the behaviour this refuses to trust. Matching is done here, in code,
	 * against the exact string the desk typed.
	 *
	 * @param array<int, array{id: string, label: string, aliases?: array<int, string>}> $search_response Candidates, as `Wikidata_Client::search()` returns them.
	 * @param string                                                                     $typed_name      What the desk typed.
	 * @return string|null The one candidate's QID, or null if zero or more than one matched.
	 */
	public static function resolve_candidate( array $search_response, string $typed_name ): ?string {
		$typed   = self::normalise( $typed_name );
		$matched = array();

		foreach ( $search_response as $candidate ) {
			$labels = array( (string) ( $candidate['label'] ?? '' ) );

			foreach ( (array) ( $candidate['aliases'] ?? array() ) as $alias ) {
				$labels[] = (string) $alias;
			}

			foreach ( $labels as $label ) {
				if ( '' !== $label && self::normalise( $label ) === $typed ) {
					$matched[] = (string) ( $candidate['id'] ?? '' );
					break;
				}
			}
		}

		$matched = array_values( array_unique( array_filter( $matched ) ) );

		return 1 === count( $matched ) ? $matched[0] : null;
	}

	/**
	 * The label a resolved candidate had in the search response, for display.
	 *
	 * Wikidata's own casing and diacritics, not whatever case the desk happened to
	 * type — "tadej pogačar" typed in lower case still shows the rider's name properly
	 * capitalised on the card.
	 *
	 * @param array<int, array{id: string, label: string}> $search_response Candidates.
	 * @param string                                        $qid             The resolved candidate's QID.
	 * @return string
	 */
	public static function label_of( array $search_response, string $qid ): string {
		foreach ( $search_response as $candidate ) {
			if ( ( $candidate['id'] ?? '' ) === $qid ) {
				return (string) ( $candidate['label'] ?? '' );
			}
		}

		return '';
	}

	/**
	 * Stage B: is this candidate actually a cyclist.
	 *
	 * A recognised cycling occupation or a recognised cycling sport claim, either is
	 * enough — a track specialist may carry only the sport claim, a rider whose entry
	 * predates good categorisation may carry only the occupation.
	 *
	 * @param array<string, array<int, array>> $entity_claims The resolved candidate's claims.
	 * @return bool
	 */
	public static function is_cyclist( array $entity_claims ): bool {
		foreach ( (array) ( $entity_claims['P106'] ?? array() ) as $claim ) {
			if ( in_array( self::qid_of( $claim ), self::CYCLIST_OCCUPATIONS, true ) ) {
				return true;
			}
		}

		foreach ( (array) ( $entity_claims['P641'] ?? array() ) as $claim ) {
			if ( isset( self::CYCLING_SPORTS[ self::qid_of( $claim ) ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The five facts, read straight off whatever claims exist.
	 *
	 * Each fact is independently optional — a retired rider has no current team, an
	 * early entry may have no discipline claim — and each is left blank rather than
	 * reporting the nearest available answer to a different question. A blank field is
	 * "Wikidata didn't say"; it is never "here is a guess instead".
	 *
	 * @param array<string, array<int, array>> $entity_claims The resolved candidate's claims.
	 * @param array<string, string>            $labels        QID => label, for whichever team/country/sport QIDs are referenced above.
	 * @return array{team: string, nationality: string, date_of_birth: string, discipline: string}
	 */
	public static function facts_from_entity( array $entity_claims, array $labels ): array {
		return array(
			'team'          => self::current_team( $entity_claims, $labels ),
			'nationality'   => self::nationality( $entity_claims, $labels ),
			'date_of_birth' => self::date_of_birth( $entity_claims ),
			'discipline'    => self::discipline( $entity_claims, $labels ),
		);
	}

	/**
	 * Up to two most recent results, formatted for the card.
	 *
	 * Wikidata's race items already carry their year in the label ("2024 Tour de
	 * France", "2017 Vuelta a España, stage 20"), so nothing here re-derives one —
	 * doing that from `pointInTime` too would double it up.
	 *
	 * @param array<int, array{eventLabel?: array{value?: string}, pointInTime?: array{value?: string}}> $victory_bindings SPARQL bindings for reverse P1346 claims.
	 * @return array<int, string>
	 */
	public static function notable_results( array $victory_bindings ): array {
		$rows = array();

		foreach ( $victory_bindings as $binding ) {
			$label = trim( (string) ( $binding['eventLabel']['value'] ?? '' ) );

			if ( '' === $label ) {
				continue;
			}

			$rows[] = array(
				'label' => $label,
				'when'  => (string) ( $binding['pointInTime']['value'] ?? '' ),
			);
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return strcmp( $b['when'], $a['when'] );
			}
		);

		$formatted = array();

		foreach ( array_slice( $rows, 0, self::MAX_NOTABLE_RESULTS ) as $row ) {
			$formatted[] = sprintf( 'Winner, %s', $row['label'] );
		}

		return $formatted;
	}

	/**
	 * The one entry point the commissioner calls.
	 *
	 * Wires both resolution stages and the fact extraction together, and returns null
	 * at any fail-closed point — an unresolved candidate, a resolved non-cyclist, or a
	 * resolved cyclist whose display name turned out empty.
	 *
	 * @param string                                          $typed_name      What the desk typed.
	 * @param array<int, array{id: string, label: string}>    $search_response `Wikidata_Client::search()` result for $typed_name.
	 * @param array<string, array<int, array>>|null            $entity          `Wikidata_Client::entity()` result for the resolved QID, or null if that fetch failed.
	 * @param array<string, string>                            $labels          QID => label for the referenced team/country/sport, or empty if not fetched.
	 * @param array<int, array>|null                           $victories       `Wikidata_Client::victories()` result, or null if that fetch failed.
	 * @return array{riderQid: string, name: string, team: string, nationality: string, dateOfBirth: string, discipline: string, notableResults: array<int, string>}|null
	 */
	public static function map( string $typed_name, array $search_response, ?array $entity, array $labels, ?array $victories ): ?array {
		$qid = self::resolve_candidate( $search_response, $typed_name );

		if ( null === $qid || null === $entity ) {
			return null;
		}

		if ( ! self::is_cyclist( $entity ) ) {
			return null;
		}

		$name = self::label_of( $search_response, $qid );

		if ( '' === $name ) {
			return null;
		}

		$facts = self::facts_from_entity( $entity, $labels );

		return array(
			'riderQid'       => $qid,
			'name'           => $name,
			'team'           => $facts['team'],
			'nationality'    => $facts['nationality'],
			'dateOfBirth'    => $facts['date_of_birth'],
			'discipline'     => $facts['discipline'],
			'notableResults' => null === $victories ? array() : self::notable_results( $victories ),
		);
	}

	/**
	 * A P54 (team member of) claim with a start date and no end date — the one
	 * Wikidata convention for "still there".
	 *
	 * @param array<string, array<int, array>> $entity_claims Claims.
	 * @param array<string, string>            $labels        QID => label.
	 * @return string
	 */
	private static function current_team( array $entity_claims, array $labels ): string {
		foreach ( (array) ( $entity_claims['P54'] ?? array() ) as $claim ) {
			$qualifiers = (array) ( $claim['qualifiers'] ?? array() );

			if ( isset( $qualifiers['P580'] ) && ! isset( $qualifiers['P582'] ) ) {
				return $labels[ self::qid_of( $claim ) ] ?? '';
			}
		}

		return '';
	}

	/**
	 * P27 (country of citizenship), first value.
	 *
	 * @param array<string, array<int, array>> $entity_claims Claims.
	 * @param array<string, string>            $labels        QID => label.
	 * @return string
	 */
	private static function nationality( array $entity_claims, array $labels ): string {
		foreach ( (array) ( $entity_claims['P27'] ?? array() ) as $claim ) {
			$qid = self::qid_of( $claim );

			if ( '' !== $qid ) {
				return $labels[ $qid ] ?? '';
			}
		}

		return '';
	}

	/**
	 * P569 (date of birth), as Y-m-d.
	 *
	 * @param array<string, array<int, array>> $entity_claims Claims.
	 * @return string
	 */
	private static function date_of_birth( array $entity_claims ): string {
		foreach ( (array) ( $entity_claims['P569'] ?? array() ) as $claim ) {
			$time = (string) ( $claim['mainsnak']['datavalue']['value']['time'] ?? '' );

			if ( '' === $time ) {
				continue;
			}

			// Wikidata's own format: a leading sign, then the date, then a time nothing here needs.
			$date = substr( ltrim( $time, '+' ), 0, 10 );

			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				return $date;
			}
		}

		return '';
	}

	/**
	 * The first P641 (sport) value that is a recognised cycling discipline.
	 *
	 * Riders who competed in another sport first carry that sport's item too — this
	 * only ever returns a cycling one.
	 *
	 * @param array<string, array<int, array>> $entity_claims Claims.
	 * @param array<string, string>            $labels        QID => label.
	 * @return string
	 */
	private static function discipline( array $entity_claims, array $labels ): string {
		foreach ( (array) ( $entity_claims['P641'] ?? array() ) as $claim ) {
			$qid = self::qid_of( $claim );

			if ( isset( self::CYCLING_SPORTS[ $qid ] ) ) {
				return $labels[ $qid ] ?? self::CYCLING_SPORTS[ $qid ];
			}
		}

		return '';
	}

	/**
	 * The QID a claim's value points at, or empty if it is not an item value.
	 *
	 * @param array $claim One statement.
	 * @return string
	 */
	private static function qid_of( array $claim ): string {
		return (string) ( $claim['mainsnak']['datavalue']['value']['id'] ?? '' );
	}

	/**
	 * Case- and whitespace-insensitive comparison key.
	 *
	 * Deliberately not diacritic-insensitive — dropping that is Wikidata's search
	 * doing its job, and this class's job is to be stricter than that.
	 *
	 * @param string $value Text.
	 * @return string
	 */
	private static function normalise( string $value ): string {
		return strtolower( trim( $value ) );
	}
}
