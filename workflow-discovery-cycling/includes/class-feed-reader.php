<?php
/**
 * Reads the cycling feeds and hands back plain arrays.
 *
 * Three publisher RSS feeds, fetched through WordPress's own SimplePie wrapper so
 * caching, timeouts and malformed-XML handling are not reimplemented here.
 *
 * A feed being down is normal, not exceptional. A publisher reorganises their site
 * or rate-limits an IP and one feed of three goes quiet — the desk should still see
 * the other two rather than an empty screen, so a failed fetch is dropped and the
 * rest are returned.
 *
 * @package WorkflowDiscoveryCycling
 */

declare( strict_types=1 );

namespace WorkflowDiscoveryCycling;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches and normalises the configured cycling feeds.
 */
class Feed_Reader {

	/**
	 * How long a fetched set of items stays good for.
	 *
	 * Fifteen minutes. Cycling news breaks in bursts around a stage finish and then
	 * goes quiet for hours, so a short window costs little and a long one means the
	 * desk is looking at a finish that has already been overtaken.
	 */
	public const CACHE_TTL = 900;

	/**
	 * Transient holding the normalised items.
	 */
	public const CACHE_KEY = 'workflow_discovery_cycling_items';

	/**
	 * Most items to keep per feed.
	 *
	 * Cyclingnews returns 50 and Cycling Weekly 50; the desk does not read 150
	 * headlines. Trimming per feed rather than after the merge keeps a fast-moving
	 * feed from crowding out the slower ones.
	 */
	public const PER_FEED_LIMIT = 20;

	/**
	 * The feeds this source reads.
	 *
	 * Each entry names the publisher, because the item's provenance is the first
	 * thing an editor looks at and 'source' on the prompt is where it lands.
	 *
	 * @return array<int, array{slug: string, name: string, url: string}>
	 */
	public static function feeds(): array {
		$feeds = array(
			array(
				'slug' => 'cyclingnews',
				'name' => 'Cyclingnews',
				'url'  => 'https://www.cyclingnews.com/rss/',
			),
			array(
				'slug' => 'velo',
				'name' => 'Velo',
				'url'  => 'https://velo.outsideonline.com/feed/',
			),
			array(
				'slug' => 'cycling-weekly',
				'name' => 'Cycling Weekly',
				'url'  => 'https://www.cyclingweekly.com/rss',
			),
		);

		/**
		 * Filters the feeds the cycling desk reads.
		 *
		 * Swap in a different publisher, or point at a local fixture file so a demo
		 * does not depend on the open internet. Each entry needs a slug, a name and
		 * a url.
		 *
		 * @param array $feeds Feed definitions.
		 */
		$feeds = apply_filters( 'workflow_discovery_cycling_feeds', $feeds );

		return is_array( $feeds ) ? array_values( array_filter( $feeds, array( self::class, 'is_valid_feed' ) ) ) : array();
	}

	/**
	 * Every item across every feed, newest first.
	 *
	 * @param bool $force Skip the cache.
	 * @return array<int, array>
	 */
	public static function items( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$items = array();

		foreach ( self::feeds() as $feed ) {
			foreach ( self::read_feed( $feed ) as $item ) {
				$items[] = $item;
			}
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				return ( $b['timestamp'] ?? 0 ) <=> ( $a['timestamp'] ?? 0 );
			}
		);

		/*
		 * Cache even an empty result. Three feeds all failing usually means the
		 * network is unreachable, and retrying that on every page load of the
		 * ideation screen turns one outage into a slow admin.
		 */
		set_transient( self::CACHE_KEY, $items, self::CACHE_TTL );

		return $items;
	}

	/**
	 * Drop the cache.
	 */
	public static function flush(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Read one feed into normalised items.
	 *
	 * @param array $feed Feed definition.
	 * @return array<int, array>
	 */
	private static function read_feed( array $feed ): array {
		if ( ! function_exists( 'fetch_feed' ) ) {
			require_once ABSPATH . WPINC . '/feed.php';
		}

		$parsed = fetch_feed( $feed['url'] );

		if ( is_wp_error( $parsed ) ) {
			return array();
		}

		$items = array();

		foreach ( $parsed->get_items( 0, self::PER_FEED_LIMIT ) as $entry ) {
			$link = (string) $entry->get_permalink();

			// No link means nothing to research from and nothing to attribute to.
			if ( '' === $link ) {
				continue;
			}

			$date = $entry->get_date( 'U' );

			$items[] = array(
				'feed_slug'   => (string) $feed['slug'],
				'feed_name'   => (string) $feed['name'],
				'title'       => html_entity_decode( wp_strip_all_tags( (string) $entry->get_title() ), ENT_QUOTES, 'UTF-8' ),
				'summary'     => self::summarise( (string) $entry->get_description() ),
				'url'         => $link,
				'timestamp'   => is_numeric( $date ) ? (int) $date : 0,
				'author'      => self::author_of( $entry ),
				'categories'  => self::categories_of( $entry ),
			);
		}

		return $items;
	}

	/**
	 * A one-paragraph summary from feed description HTML.
	 *
	 * @param string $html Raw description.
	 * @return string
	 */
	private static function summarise( string $html ): string {
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );

		return wp_html_excerpt( $text, 400, '…' );
	}

	/**
	 * The byline, or an empty string.
	 *
	 * @param \SimplePie_Item|object $entry Feed item.
	 * @return string
	 */
	private static function author_of( $entry ): string {
		$author = $entry->get_author();

		if ( ! $author ) {
			return '';
		}

		$name = (string) $author->get_name();

		/*
		 * Cyclingnews puts 'laura@cyclingnews.com (Laura Weislo)' in <author> and the
		 * bare name in <dc:creator>, and SimplePie surfaces whichever it found first.
		 * Strip the address so the desk sees a person.
		 */
		if ( '' === $name ) {
			$name = (string) $author->get_email();
		}

		if ( preg_match( '/\(([^)]+)\)/', $name, $matches ) ) {
			$name = $matches[1];
		}

		return trim( wp_strip_all_tags( $name ) );
	}

	/**
	 * Category labels on the item.
	 *
	 * @param \SimplePie_Item|object $entry Feed item.
	 * @return array<int, string>
	 */
	private static function categories_of( $entry ): array {
		$labels = array();

		foreach ( (array) $entry->get_categories() as $category ) {
			$label = html_entity_decode( (string) $category->get_label(), ENT_QUOTES, 'UTF-8' );
			$label = trim( wp_strip_all_tags( $label ) );

			if ( '' !== $label ) {
				$labels[] = $label;
			}
		}

		return array_values( array_unique( $labels ) );
	}

	/**
	 * Whether a filtered feed entry is usable.
	 *
	 * @param mixed $feed Candidate.
	 * @return bool
	 */
	private static function is_valid_feed( $feed ): bool {
		return is_array( $feed )
			&& '' !== trim( (string) ( $feed['slug'] ?? '' ) )
			&& '' !== trim( (string) ( $feed['name'] ?? '' ) )
			&& '' !== trim( (string) ( $feed['url'] ?? '' ) );
	}
}
