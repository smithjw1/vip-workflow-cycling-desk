<?php
/**
 * Reads candidate rider names out of story prose.
 *
 * No WordPress, deliberately — this is the class that guesses, in the same sense
 * that Prompt_Mapper is in the cycling desk plugin: it is the one place a wrong
 * answer is a wrong *hint*, not a wrong HTTP call. It has to be testable on its own.
 *
 * The heuristic is a shape, not a dictionary: two or three consecutive capitalised
 * words (allowing a lowercase surname particle — 'van', 'de', 'von' — in the middle)
 * are taken as a candidate name, unless the run contains an all-caps token (an
 * acronym, almost always a sponsor or federation — 'SD Worx', 'UAE Team Emirates')
 * or any of its words is on the stopword list of race, team and section vocabulary
 * that reads exactly like a name in cycling headlines — 'Tour de France', 'La
 * Polynormande', 'SD Worx-Protime', 'World Championships'.
 *
 * This deliberately does not chase every shape a name can take. A rider referred to
 * by surname alone — 'Vollering', 'Pogačar' — is not caught, because a single
 * capitalised word is indistinguishable from a hundred other proper nouns in a
 * headline. That is the same trade this plugin's sibling makes with race names:
 * false positives here would put a wrong bio in front of an editor, and a name this
 * misses just gets no bio at all rather than someone else's.
 *
 * The candidates this returns are not final answers. The Wikipedia lookup and
 * Bio_Mapper are the second, stricter filter — they discard anything that does not
 * resolve to an actual cyclist, which is what keeps this class free to be a little
 * generous about what looks name-shaped.
 *
 * @package WorkflowAssistantRiderBios
 */

declare( strict_types=1 );

namespace WorkflowAssistantRiderBios;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extracts candidate rider names from plain text.
 */
class Rider_Extractor {

	/**
	 * Shortest run of tokens taken as a name.
	 */
	private const MIN_RUN = 2;

	/**
	 * Longest run of tokens taken as a name.
	 *
	 * Longer than this and it is headline title-case, not a person — 'Life Time
	 * Grand Prix Overhauls Qualifying Process' is seven capitalised words running
	 * together, and no rider's name is that long. Four allows a double-particle
	 * surname — 'Mathieu van der Poel' — through as well as the three-word kind.
	 */
	private const MAX_RUN = 4;

	/**
	 * Lowercase words that connect two parts of a surname.
	 *
	 * Capitalised at the front of a sentence like anything else, but this class only
	 * ever sees them mid-run, where case is meaningful — 'Wout van Aert', not
	 * 'Wout Van Aert' in running prose.
	 *
	 * @var array<int, string>
	 */
	private const PARTICLES = array( 'van', 'von', 'der', 'den', 'de', 'di', 'du', 'dos', 'das', 'do' );

	/**
	 * Words that make a capitalised run a race, team, section heading or sentence
	 * opener rather than a name, even though they are shaped exactly like one.
	 *
	 * Grown from real cycling headlines, the same way NOT_A_RACE was in the sibling
	 * plugin's Prompt_Mapper: every entry here is something that was capitalised
	 * next to another capitalised word in real coverage and was not a person.
	 *
	 * @var array<int, string>
	 */
	private const STOPWORDS = array(
		// Race and event vocabulary.
		'tour',
		'tours',
		'race',
		'races',
		'racing',
		'championship',
		'championships',
		'classic',
		'classics',
		'grand',
		'prix',
		'cup',
		'trophy',
		'games',
		'olympic',
		'olympics',
		'series',
		'tournament',
		'circuit',
		'festival',
		'vuelta',
		'giro',
		'volta',
		'ronde',
		'omloop',
		'strade',
		'roubaix',
		'flanders',
		'sanremo',
		'lombardia',
		'worlds',
		'world',
		'la',
		'les',
		'il',
		'tirreno',
		'dauphine',
		'dauphiné',
		'criterium',
		'critérium',
		'itzulia',
		'catalunya',
		'romandie',
		'suisse',
		'algarve',
		'polynormande',
		'gravel',
		'femmes',
		// Organisational and section vocabulary.
		'team',
		'teams',
		'squad',
		'squads',
		'development',
		'national',
		'federation',
		'union',
		'committee',
		'board',
		'group',
		'bike',
		'bikes',
		'cycling',
		'riders',
		'rider',
		'women',
		'men',
		'review',
		'reviews',
		'video',
		'live',
		'analysis',
		'news',
		'gallery',
		'podcast',
		'feature',
		'features',
		'interview',
		'interviews',
		'comment',
		'opinion',
		'breaking',
		'exclusive',
		'start',
		'list',
		'startlist',
		// Sponsors and team names that recur across publishers, capitalised in pairs.
		'ineos',
		'netcompany',
		'visma',
		'lease',
		'jumbo',
		'uae',
		'emirates',
		'xrg',
		'worx',
		'protime',
		'decathlon',
		'cma',
		'cgm',
		'picnic',
		'postnl',
		'ef',
		'education',
		'movistar',
		'astana',
		'lidl',
		'trek',
		'alpecin',
		'deceuninck',
		'cofidis',
		'arkea',
		'soudal',
		'quickstep',
		'groupama',
		'fdj',
		'bahrain',
		'israel',
		'premiertech',
		'lotto',
		'dstny',
		'intermarche',
		'tudor',
		'unox',
		'xds',
		'redbull',
		// Demonyms — almost never a name, and pair constantly with team and event nouns.
		'american',
		'belgian',
		'british',
		'welsh',
		'english',
		'scottish',
		'irish',
		'dutch',
		'french',
		'german',
		'italian',
		'spanish',
		'portuguese',
		'norwegian',
		'swedish',
		'danish',
		'swiss',
		'australian',
		'colombian',
		'slovenian',
		'slovakian',
		'czech',
		'polish',
		'canadian',
		'kenyan',
		'eritrean',
		'rwandan',
		'new',
		'south',
		'north',
		// Sentence openers that are capitalised only because they lead the sentence.
		'the',
		'how',
		'why',
		'what',
		'when',
		'where',
		'who',
		'from',
		'watch',
		'read',
		'meet',
		'inside',
		'former',
		// Compound words that read as one capitalised token but are not a name.
		'worldtour',
	);

	/**
	 * Punctuation that ends a clause.
	 *
	 * Given no space of its own — 'Portugal: Neutralised', 'Courtney? Examining' — a
	 * word on either side of one of these would otherwise merge into a single run,
	 * pairing a proper noun before it with an unrelated capitalised word that only
	 * happens to open the next clause. Splitting on whitespace alone cannot see
	 * that break, so it is inserted before the split.
	 *
	 * @var string
	 */
	private const CLAUSE_BOUNDARY_PATTERN = '/([.!?:;|–—])/u';

	/**
	 * Read every candidate rider name out of a piece of text.
	 *
	 * Returns each candidate once, in the order it first appears.
	 *
	 * @param string $text Story prose — a headline, standfirst, or draft body.
	 * @return array<int, string>
	 */
	public static function extract_candidates( string $text ): array {
		$text  = (string) preg_replace( self::CLAUSE_BOUNDARY_PATTERN, ' $1 ', $text );
		$words = preg_split( '/\s+/u', trim( $text ) );

		if ( ! is_array( $words ) ) {
			return array();
		}

		$candidates = array();
		$run        = array();

		foreach ( $words as $word ) {
			$token = self::classify( $word );

			if ( null === $token ) {
				self::flush_run( $run, $candidates );
				$run = array();
				continue;
			}

			$run[] = $token;
		}

		self::flush_run( $run, $candidates );

		return array_values( $candidates );
	}

	/**
	 * Classify one raw word as part of a name run, or rule it out.
	 *
	 * @param string $word Raw whitespace-delimited word, with surrounding punctuation.
	 * @return array{word: string, type: string}|null
	 */
	private static function classify( string $word ): ?array {
		// A word carrying a digit is a model code or a date, not a name —
		// "Gr4" and "2026" both otherwise clean down to something name-shaped.
		if ( preg_match( '/\d/', $word ) ) {
			return null;
		}

		$clean = self::clean( $word );

		if ( '' === $clean ) {
			return null;
		}

		if ( preg_match( '/^[A-Z]{2,}$/', $clean ) ) {
			return array(
				'word' => $clean,
				'type' => 'acronym',
			);
		}

		if ( preg_match( '/^[\p{Lu}][\p{L}\'\x{2019}\-]*$/u', $clean ) ) {
			return array(
				'word' => $clean,
				'type' => 'cap',
			);
		}

		if ( in_array( strtolower( $clean ), self::PARTICLES, true ) ) {
			return array(
				'word' => $clean,
				'type' => 'particle',
			);
		}

		return null;
	}

	/**
	 * Strip punctuation a word picks up from headline prose without touching what a
	 * name is actually made of — internal apostrophes and hyphens.
	 *
	 * @param string $word Raw word.
	 * @return string
	 */
	private static function clean( string $word ): string {
		// A trailing possessive is not part of the name — "Vollering's" is "Vollering".
		$word = (string) preg_replace( '/[\'\x{2019}]s?$/u', '', $word );

		// Quotes, colons, commas and the like at either edge; hyphens and apostrophes
		// inside a word are what makes "Court-Pienaar" and "O'Brien" one token.
		return (string) preg_replace( '/^[^\p{L}]+|[^\p{L}]+$/u', '', $word );
	}

	/**
	 * Decide whether a completed run of tokens is a name, and if so record it.
	 *
	 * @param array<int, array{word: string, type: string}> $run        Tokens seen since the last break.
	 * @param array<int, string>                             $candidates Accepted candidates, by reference.
	 */
	private static function flush_run( array $run, array &$candidates ): void {
		$count = count( $run );

		if ( $count < self::MIN_RUN || $count > self::MAX_RUN ) {
			return;
		}

		if ( 'cap' !== $run[0]['type'] || 'cap' !== $run[ $count - 1 ]['type'] ) {
			return;
		}

		foreach ( $run as $token ) {
			if ( 'acronym' === $token['type'] ) {
				return;
			}

			// Checked whole and by hyphen part — "Paris-Roubaix" is a stopword by its
			// second half, the same as "Roubaix" on its own would be.
			foreach ( explode( '-', strtolower( $token['word'] ) ) as $part ) {
				if ( in_array( $part, self::STOPWORDS, true ) ) {
					return;
				}
			}
		}

		$name = implode(
			' ',
			array_map(
				static function ( array $token ): string {
					return $token['word'];
				},
				$run
			)
		);

		if ( ! in_array( $name, $candidates, true ) ) {
			$candidates[] = $name;
		}
	}
}
