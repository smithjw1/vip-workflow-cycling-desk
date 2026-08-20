<?php
/**
 * Turns a raw Wikipedia lookup into a rider bio, or discards it.
 *
 * No WordPress, for the same reason Rider_Extractor has none: this is the class
 * that decides whether a page is actually about a cyclist and, if so, what a
 * reader would want to know about them. Wikipedia_Client's job stops at handing
 * back a title, a description, an extract and a category list — this is where
 * those get read.
 *
 * The discard is the important half. A page that is not about a cyclist at all —
 * a bike model, a team, somebody who shares a rider's name — is dropped entirely
 * rather than returned with empty fields, and a detail this cannot confidently
 * read out of the page (a nationality with no matching category, a current team
 * with no matching sentence) is left blank rather than guessed. The same rule
 * this repo's discovery source runs on: a wrong hint is worse than no hint.
 *
 * @package WorkflowAssistantRiderBios
 */

declare( strict_types=1 );

namespace WorkflowAssistantRiderBios;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps raw Wikipedia data to a bio.
 */
class Bio_Mapper {

	/**
	 * Category fragments that mark a category as a major, career-defining win.
	 *
	 * Matched as a case-insensitive substring of the category name, so
	 * 'Tour de France stage winners' and 'Tour de France general classification
	 * winners' both match on 'tour de france' paired with 'winners'.
	 *
	 * @var array<int, string>
	 */
	private const MAJOR_RACE_FRAGMENTS = array(
		'tour de france',
		"giro d'italia",
		'giro d’italia',
		'vuelta a españa',
		'vuelta a espana',
		'paris–roubaix',
		'paris-roubaix',
		'tour of flanders',
		'ronde van vlaanderen',
		'milan–san remo',
		'milan-san remo',
		'liège–bastogne–liège',
		'liege-bastogne-liege',
		'il lombardia',
		'giro di lombardia',
		'uci road world champion',
		'world road race champion',
		'olympic',
		'national road race champion',
	);

	/**
	 * Words in a category name that mark it as recording a win at all.
	 *
	 * @var array<int, string>
	 */
	private const WIN_WORDS = array( 'winners', 'winner', 'champions', 'champion', 'medalists', 'medallists' );

	/**
	 * Words in a sentence that mark it as describing a win, for the extract scan.
	 *
	 * @var array<int, string>
	 */
	private const WIN_SENTENCE_WORDS = array( 'won', 'winner', 'winning', 'win', 'victory', 'champion', 'title' );

	/**
	 * Map one Wikipedia lookup to a bio.
	 *
	 * @param array $data           Raw data from Wikipedia_Client::lookup() — title, description, extract, categories, url.
	 * @param int   $reference_year Year to measure "recent" against. The caller's current year, passed in rather
	 *                              than read here, so this stays a pure function.
	 * @return array|null Bio, or null if the page is not confidently about a cyclist.
	 */
	public static function map( array $data, int $reference_year ): ?array {
		$categories  = array_map( 'strval', (array) ( $data['categories'] ?? array() ) );
		$description = strtolower( trim( (string) ( $data['description'] ?? '' ) ) );
		$extract     = (string) ( $data['extract'] ?? '' );

		if ( ! self::looks_like_a_cyclist( $description, $categories ) ) {
			return null;
		}

		return array(
			'name'            => (string) ( $data['title'] ?? '' ),
			'nationality'     => self::nationality_from( $categories ),
			'current_team'    => self::current_team_from( $extract ),
			'recent_wins'     => self::recent_wins_from( $extract, $reference_year ),
			'notable_wins'    => self::notable_wins_from( $categories ),
			'source_url'      => (string) ( $data['url'] ?? '' ),
			'wikipedia_title' => (string) ( $data['title'] ?? '' ),
		);
	}

	/**
	 * Whether the page is confidently about a cyclist.
	 *
	 * Requires the word to appear in the short description Wikipedia itself
	 * maintains, or in one of the page's own categories — not just anywhere in the
	 * extract, where a sentence like 'he raced against several cyclists' would
	 * wrongly clear a page about somebody else's teammate.
	 *
	 * @param string              $description Lowercased short description.
	 * @param array<int, string>  $categories  Category names.
	 * @return bool
	 */
	private static function looks_like_a_cyclist( string $description, array $categories ): bool {
		if ( str_contains( $description, 'cyclist' ) ) {
			return true;
		}

		foreach ( $categories as $category ) {
			if ( preg_match( '/\bcyclists?\b/i', $category ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Read a nationality out of the standard '<Nationality> cyclists' category.
	 *
	 * Only that exact shape is trusted. A category like 'Cyclists from Ghent' names
	 * a city, not the nationality this field means, and guessing one from the other
	 * is exactly the kind of guess this class exists to refuse.
	 *
	 * @param array<int, string> $categories Category names.
	 * @return string
	 */
	private static function nationality_from( array $categories ): string {
		foreach ( $categories as $category ) {
			if ( preg_match( '/^([\p{L}]+) cyclists?$/u', trim( $category ), $matches ) ) {
				return $matches[1];
			}
		}

		return '';
	}

	/**
	 * Read a current team out of a sentence that says so plainly.
	 *
	 * Wikipedia's infobox carries a current team, but Wikipedia_Client only hands
	 * back the intro extract, which usually states it in prose — 'He currently
	 * rides for the UCI WorldTeam Visma | Lease a Bike.' Nothing found means nothing
	 * returned; the field is worth having only when the page says it outright.
	 *
	 * @param string $extract Intro extract.
	 * @return string
	 */
	private static function current_team_from( string $extract ): string {
		$patterns = array(
			'/(?:currently rides?|rides?) for (?:the )?(?:UCI (?:WorldTeam|ProTeam|Continental Team) )?([\p{Lu}][\p{L}0-9.&\'\x{2019}\-| ]*?)(?=[.,;]| since| in the| as of|$)/u',
			'/is a member of (?:the )?(?:UCI (?:WorldTeam|ProTeam|Continental Team) )?([\p{Lu}][\p{L}0-9.&\'\x{2019}\-| ]*?)(?=[.,;]| since| in the| as of|$)/u',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $extract, $matches ) ) {
				$team = trim( $matches[1] );

				if ( '' !== $team && strlen( $team ) <= 60 ) {
					return $team;
				}
			}
		}

		return '';
	}

	/**
	 * Notable wins read out of the page's categories.
	 *
	 * Categories are Wikipedia's own durable record of what a rider is known for —
	 * Grand Tour stage wins, Monuments, World and Olympic titles — which is why
	 * these are the "notable" wins rather than the "recent" ones: nothing about a
	 * category says when it happened.
	 *
	 * @param array<int, string> $categories Category names.
	 * @return array<int, string>
	 */
	private static function notable_wins_from( array $categories ): array {
		$wins = array();

		foreach ( $categories as $category ) {
			$lower = strtolower( $category );

			if ( ! self::contains_any( $lower, self::WIN_WORDS ) ) {
				continue;
			}

			if ( self::contains_any( $lower, self::MAJOR_RACE_FRAGMENTS ) ) {
				$wins[] = $category;
			}
		}

		return array_values( array_unique( $wins ) );
	}

	/**
	 * Recent wins read out of the intro extract.
	 *
	 * A sentence counts as recent when it names a win and a year no earlier than
	 * last year. There is no structured field for this in the intro extract — the
	 * infobox's results table is not part of it — so this is deliberately looking
	 * for a sentence a Wikipedia editor chose to write into the prose because it was
	 * still the newest thing worth saying, which lags actual results by however
	 * long it took someone to edit the page.
	 *
	 * @param string $extract        Intro extract.
	 * @param int    $reference_year Year to measure "recent" against.
	 * @return array<int, string>
	 */
	private static function recent_wins_from( string $extract, int $reference_year ): array {
		$sentences = preg_split( '/(?<=[.!?])\s+/u', trim( $extract ) );

		if ( ! is_array( $sentences ) ) {
			return array();
		}

		$wins = array();

		foreach ( $sentences as $sentence ) {
			if ( ! preg_match( '/\b(19|20)\d{2}\b/', $sentence, $year_match ) ) {
				continue;
			}

			if ( (int) $year_match[0] < $reference_year - 1 ) {
				continue;
			}

			if ( ! self::contains_any( strtolower( $sentence ), self::WIN_SENTENCE_WORDS ) ) {
				continue;
			}

			$wins[] = mb_strimwidth( trim( $sentence ), 0, 160, '…' );
		}

		return array_values( array_unique( $wins ) );
	}

	/**
	 * Whether a haystack contains any of a list of needles.
	 *
	 * @param string             $haystack Lowercased text to search.
	 * @param array<int, string> $needles  Lowercased fragments to look for.
	 * @return bool
	 */
	private static function contains_any( string $haystack, array $needles ): bool {
		foreach ( $needles as $needle ) {
			if ( str_contains( $haystack, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
