<?php
/**
 * Turns a feed item into a discovery prompt.
 *
 * Two jobs. The first is mechanical: the shape the ideation screen renders — id,
 * title, description, url, date, tags.
 *
 * The second is the interesting one. A cycling story arrives already carrying the
 * things the Cycling Desk sequence asks about: the headline names the race, and the
 * publisher's own category says whether this is a race report or a tech piece. So
 * the mapper reads them out and writes them into the seed, where the research and
 * the editor filling in the metadata section both see them.
 *
 * It writes them into the *seed*, not into the fields. Nothing in ideation prefills
 * a metadata field today, and guessing 'Race report' into a required field on the
 * editor's behalf would be worse if wrong than empty is — 'Volta a Portugal:' in a
 * headline is a strong hint about the race and no evidence at all about whether the
 * desk wants a report or an obituary. The hint belongs in front of the person making
 * the call, not in the box instead of their answer.
 *
 * @package WorkflowDiscoveryCycling
 */

declare( strict_types=1 );

namespace WorkflowDiscoveryCycling;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps normalised feed items to discovery prompts.
 */
class Prompt_Mapper {

	/**
	 * Publisher category labels that imply a story type.
	 *
	 * Keyed by lowercase category, valued with an option from the sequence's
	 * story_type field. Only the unambiguous ones are here: Cyclingnews tags almost
	 * everything 'Pro Cycling', which says nothing about the kind of item.
	 *
	 * @var array<string, string>
	 */
	private const CATEGORY_HINTS = array(
		'teams & riders' => 'Rider news',
		'riders'         => 'Rider news',
		'transfers'      => 'Rider news',
		'tech'           => 'Tech',
		'technology'     => 'Tech',
		'gear'           => 'Tech',
		'road gear'      => 'Tech',
		'reviews'        => 'Tech',
		'bike reviews'   => 'Tech',
		'bikes'          => 'Tech',
		'gravel bikes'   => 'Tech',
		'products'       => 'Tech',
		'analysis'       => 'Analysis',
		'opinion'        => 'Analysis',
		'features'       => 'Analysis',
		'interviews'     => 'Interview',
		'live'           => 'Live blog',
	);

	/**
	 * Categories that mean a race report only when the headline names the race.
	 *
	 * Cyclingnews files most of its output under 'Racing', transfer news and quote-led
	 * rider pieces included, so the category alone is not evidence of a race report —
	 * against a day of live feed it was wrong about as often as it was right. Paired
	 * with a race-prefixed headline it is reliable. Unpaired it says nothing, which is
	 * what this then returns.
	 *
	 * @var array<int, string>
	 */
	private const RACE_CATEGORIES = array(
		'racing',
		'road racing',
		'race reports',
		'results',
	);

	/**
	 * Fragments that mark a category as the name of a race.
	 *
	 * Velo tags items with race names — 'Vuelta a España', 'Tour de France' — which
	 * finds races that a headline leading on the rider would hide. Cyclingnews and
	 * Cycling Weekly tag sections, so this finds nothing there and the headline prefix
	 * does the work.
	 *
	 * Deliberately fragments of real race names rather than 'race' or 'racing', which
	 * are section labels on two of the three feeds.
	 *
	 * @var array<int, string>
	 */
	private const RACE_NAME_MARKERS = array(
		'tour de',
		'tour of',
		'tour down under',
		'giro',
		'vuelta',
		'volta',
		'ronde',
		'omloop',
		'strade',
		'roubaix',
		'flanders',
		'sanremo',
		'san remo',
		'lombardia',
		'liège',
		'liege',
		'amstel',
		'flèche',
		'fleche',
		'dauphiné',
		'dauphine',
		'critérium',
		'criterium',
		'itzulia',
		'tirreno',
		'catalunya',
		'romandie',
		'suisse',
		'algarve',
		'race of',
		'grand prix',
		'classic',
		'championships',
		'olympics',
	);

	/**
	 * Phrases that rule a colon prefix out as a race name.
	 *
	 * The 'Race Name: what happened' convention is how the race is read out of a
	 * headline, and publishers use the same colon for section labels and for the
	 * product in a review. Matched anywhere in the prefix, not just as the whole of
	 * it: 'Fara Gr4 review' and 'Insta360 X6 Review' both start with something that
	 * looks like a proper noun and are not races.
	 *
	 * @var array<int, string>
	 */
	private const NOT_A_RACE = array(
		'analysis',
		'opinion',
		'comment',
		'gallery',
		'video',
		'podcast',
		'live',
		'breaking',
		'exclusive',
		'interview',
		'news',
		'tech',
		'review',
		'reviewed',
		'first look',
		'preview',
		'as it happened',
		'blog',
		'rumors',
		'rumours',
		'cheat sheet',
		'start list',
		'startlist',
		'results',
		'route',
		'how to watch',
		'explained',
		'diary',
		'buyer',
		'best',
	);

	/**
	 * Openers that mean the colon is doing something other than naming a race.
	 *
	 * A race name does not begin with a preposition or a question word. Feature
	 * headlines routinely do — 'From Moneyball to Sweat Science: ...' — and they clear
	 * every other test, because those words are capitalised at the start of a title
	 * and the phrase is short.
	 *
	 * @var array<int, string>
	 */
	private const NOT_A_RACE_OPENERS = array(
		'from',
		'how',
		'why',
		'what',
		'when',
		'where',
		'who',
		'the',
		'a',
		'an',
		'in',
		'on',
		'at',
		'with',
		'inside',
		'meet',
		'watch',
		'read',
	);

	/**
	 * Map every item, dropping any that cannot be mapped.
	 *
	 * @param array<int, array> $items        Normalised feed items.
	 * @param int               $blueprint_id Sequence to point the items at, or 0.
	 * @return array<int, array>
	 */
	public static function map_all( array $items, int $blueprint_id = 0 ): array {
		$prompts = array();

		foreach ( $items as $item ) {
			$prompt = self::map( $item, $blueprint_id );

			if ( $prompt ) {
				$prompts[] = $prompt;
			}
		}

		return $prompts;
	}

	/**
	 * Map one item.
	 *
	 * @param array $item         Normalised feed item.
	 * @param int   $blueprint_id Sequence to point the item at, or 0.
	 * @return array|null
	 */
	public static function map( array $item, int $blueprint_id = 0 ): ?array {
		$title = trim( (string) ( $item['title'] ?? '' ) );

		if ( '' === $title ) {
			return null;
		}

		/*
		 * Headline prefix first. Where a publisher uses the convention it is the more
		 * specific answer — 'Volta a Portugal' over a category of 'Racing' — and where
		 * they don't, the categories are the only place a race name appears at all.
		 */
		$race = self::race_from_title( $title );

		if ( '' === $race ) {
			$race = self::race_from_categories( $item, $title );
		}

		$story_type = self::story_type_from( $item, $race );

		$prompt = array(
			'id'          => 'cycling-' . md5( (string) ( $item['url'] ?? $title ) ),
			'provider'    => Plugin::PROVIDER_SLUG,
			'title'       => $title,
			'description' => (string) ( $item['summary'] ?? '' ),
			'url'         => (string) ( $item['url'] ?? '' ),
			'date'        => ( $item['timestamp'] ?? 0 ) > 0 ? gmdate( 'c', (int) $item['timestamp'] ) : null,
			'date_end'    => null,
			'tags'        => self::tags_for( $item, $race ),
			'importance'  => 'normal',
			'source'      => (string) ( $item['feed_name'] ?? '' ),
			'meta'        => array(
				'publisher'  => (string) ( $item['feed_name'] ?? '' ),
				'byline'     => (string) ( $item['author'] ?? '' ),
				'race'       => $race,
				'story_type' => $story_type,
			),
		);

		/*
		 * The item names its own sequence. This is the shorter of the two routes the
		 * core filter documents, and the right one here because the answer is fixed:
		 * everything this source returns is a cycling story for the cycling desk.
		 */
		if ( $blueprint_id > 0 ) {
			$prompt['blueprint_id'] = $blueprint_id;
		}

		return $prompt;
	}

	/**
	 * The seed text handed to the research phase.
	 *
	 * Ends with the commissioning hints, named as hints. The research agent reads
	 * this, and so does the editor when the metadata section asks them for a story
	 * type — being told 'the publisher filed this under Racing' is what makes that
	 * question answerable without opening the source in another tab.
	 *
	 * @param array $prompt The selected prompt.
	 * @return string
	 */
	public static function seed( array $prompt ): string {
		$parts = array();

		$title = trim( (string) ( $prompt['title'] ?? '' ) );

		if ( '' !== $title ) {
			$parts[] = $title;
		}

		$description = trim( (string) ( $prompt['description'] ?? '' ) );

		if ( '' !== $description ) {
			$parts[] = $description;
		}

		$meta      = (array) ( $prompt['meta'] ?? array() );
		$publisher = trim( (string) ( $meta['publisher'] ?? '' ) );
		$byline    = trim( (string) ( $meta['byline'] ?? '' ) );

		if ( '' !== $publisher ) {
			$attribution = '' !== $byline
				? sprintf( 'Reported by %1$s in %2$s.', $byline, $publisher )
				: sprintf( 'Reported by %s.', $publisher );

			$parts[] = $attribution;
		}

		$url = trim( (string) ( $prompt['url'] ?? '' ) );

		if ( '' !== $url ) {
			$parts[] = sprintf( 'Source: %s', $url );
		}

		/*
		 * Said plainly as a suggestion. The desk is about to be asked for a story type
		 * and a race, and a line that reads like a decision already taken is how a
		 * guess ends up in the field unexamined.
		 */
		$hints = array();

		$race = trim( (string) ( $meta['race'] ?? '' ) );

		if ( '' !== $race ) {
			$hints[] = sprintf( 'the race looks to be %s', $race );
		}

		$story_type = trim( (string) ( $meta['story_type'] ?? '' ) );

		if ( '' !== $story_type ) {
			$hints[] = sprintf( 'the publisher filed it under a heading that suggests %s', $story_type );
		}

		if ( $hints ) {
			$parts[] = sprintf(
				'Commissioning hints, to confirm rather than assume: %s.',
				implode( '; ', $hints )
			);
		}

		return implode( ' ', $parts );
	}

	/**
	 * Read a race name out of a headline.
	 *
	 * Cycling publishers prefix race coverage with the race and a colon —
	 * 'Volta a Portugal: Neutralised stage 8 concludes in mourning'. Anything before
	 * a colon that is not a section label and is short enough to be a race name is
	 * taken as one; everything else returns empty rather than a guess.
	 *
	 * @param string $title Headline.
	 * @return string
	 */
	public static function race_from_title( string $title ): string {
		$position = strpos( $title, ':' );

		if ( false === $position || 0 === $position ) {
			return '';
		}

		$candidate = trim( substr( $title, 0, $position ) );

		if ( '' === $candidate ) {
			return '';
		}

		$lower = strtolower( $candidate );

		foreach ( self::NOT_A_RACE as $phrase ) {
			if ( preg_match( '/\b' . preg_quote( $phrase, '/' ) . '\b/', $lower ) ) {
				return '';
			}
		}

		$first = strtok( $lower, ' ' );

		if ( is_string( $first ) && in_array( $first, self::NOT_A_RACE_OPENERS, true ) ) {
			return '';
		}

		/*
		 * Race names run to about six words at the outside — 'Volta a Portugal em
		 * Bicicleta', 'Itzulia Basque Country'. Longer than that and the colon is
		 * doing something else, usually quoting somebody.
		 */
		if ( str_word_count( $candidate ) > 6 ) {
			return '';
		}

		// A quote mark before the colon means the colon is punctuation, not a prefix.
		if ( preg_match( '/["\'“”‘’]/u', $candidate ) ) {
			return '';
		}

		return $candidate;
	}

	/**
	 * Read a race name out of the item's categories.
	 *
	 * Only accepts a category whose name is also in the headline. Velo tags an item
	 * with the races it is *related to*, not the one it is about — a transfer story
	 * gets 'Tour de France' because that is where the rider will ride, and a Vuelta
	 * preview carries three Grand Tours. Taking the first race-shaped category put the
	 * wrong race on four items out of a day's feed.
	 *
	 * The headline is the tiebreak because it is what the piece is actually about, and
	 * requiring both is why this is worth trusting at all.
	 *
	 * @param array  $item  Normalised feed item.
	 * @param string $title Headline.
	 * @return string
	 */
	public static function race_from_categories( array $item, string $title ): string {
		$haystack = strtolower( $title );

		foreach ( (array) ( $item['categories'] ?? array() ) as $category ) {
			$label = trim( (string) $category );

			if ( '' === $label ) {
				continue;
			}

			$lower = strtolower( $label );

			if ( ! str_contains( $haystack, $lower ) ) {
				continue;
			}

			foreach ( self::RACE_NAME_MARKERS as $marker ) {
				if ( str_contains( $lower, $marker ) ) {
					/*
					 * Return the headline's own capitalisation of it. Velo's categories
					 * arrive inconsistently cased — 'life time grand prix' next to
					 * 'Tour de France' — and the race field is read by people.
					 */
					$position = stripos( $title, $label );

					return false !== $position ? substr( $title, $position, strlen( $label ) ) : $label;
				}
			}
		}

		return '';
	}

	/**
	 * Guess a story type from the item's publisher categories.
	 *
	 * Returns empty when nothing maps. An unmapped category is not a reason to fall
	 * back to the most common type — 'Race report' on a tech review is a worse hint
	 * than no hint, and this is going in front of someone about to answer that exact
	 * question.
	 *
	 * The specific categories win over the racing ones. An item tagged both 'Racing'
	 * and 'Teams & Riders' with a race in the headline is the transfer-news case, and
	 * 'Rider news' is the better read of it.
	 *
	 * @param array  $item Normalised feed item.
	 * @param string $race Race detected in the headline, or empty.
	 * @return string
	 */
	public static function story_type_from( array $item, string $race = '' ): string {
		$categories = array();

		foreach ( (array) ( $item['categories'] ?? array() ) as $category ) {
			$categories[] = strtolower( trim( (string) $category ) );
		}

		foreach ( $categories as $key ) {
			if ( isset( self::CATEGORY_HINTS[ $key ] ) ) {
				return self::CATEGORY_HINTS[ $key ];
			}
		}

		if ( '' === $race ) {
			return '';
		}

		foreach ( $categories as $key ) {
			if ( in_array( $key, self::RACE_CATEGORIES, true ) ) {
				return 'Race report';
			}
		}

		return '';
	}

	/**
	 * Tags shown on the prompt card.
	 *
	 * @param array  $item Normalised feed item.
	 * @param string $race Detected race, or empty.
	 * @return array<int, string>
	 */
	private static function tags_for( array $item, string $race ): array {
		$tags = array();

		if ( '' !== $race ) {
			$tags[] = $race;
		}

		foreach ( (array) ( $item['categories'] ?? array() ) as $category ) {
			$label = trim( (string) $category );

			if ( '' !== $label && ! in_array( $label, $tags, true ) ) {
				$tags[] = $label;
			}
		}

		return array_slice( $tags, 0, 4 );
	}
}
