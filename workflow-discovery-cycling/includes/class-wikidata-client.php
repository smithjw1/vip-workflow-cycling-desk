<?php
/**
 * Fetches raw Wikidata JSON, over `wp_remote_get()`, keyless.
 *
 * No fact-shaping here — that is `Rider_Mapper`'s job, deliberately, so the guessing
 * stays in the one class with no WordPress in it and a test against it. This class
 * only fetches and caches.
 *
 * A Wikidata outage behaves like a feed outage: a non-200 or unparsable response
 * comes back as `WP_Error` rather than a thrown exception, and the commissioner
 * skips that one rider rather than failing the whole commission over it.
 *
 * @package WorkflowDiscoveryCycling
 */

declare( strict_types=1 );

namespace WorkflowDiscoveryCycling;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wikidata lookups, cached.
 */
class Wikidata_Client {

	/**
	 * How long a fetched lookup stays good for.
	 *
	 * Seven days. Rider facts move on the scale of transfer windows, not news cycles —
	 * unlike the feed reader's 15-minute cache, which exists because news breaks in
	 * bursts. A week means a transfer that happened yesterday might not show up in a
	 * card commissioned today, but will within the week, and it means three stories
	 * commissioned about the same rider in a week cost one Wikidata round trip, not
	 * three. It has no effect on facts already written into a published card's block
	 * attributes — this only governs the next lookup.
	 */
	public const CACHE_TTL = 604800;

	/**
	 * Prefix for every transient this class sets.
	 */
	private const CACHE_PREFIX = 'wfcy_wd_';

	/**
	 * Wikidata's action API.
	 */
	private const API_URL = 'https://www.wikidata.org/w/api.php';

	/**
	 * Wikidata's SPARQL endpoint.
	 */
	private const SPARQL_URL = 'https://query.wikidata.org/sparql';

	/**
	 * Search for candidates by name.
	 *
	 * @param string $name  What the desk typed.
	 * @param bool   $force Skip the cache.
	 * @return array<int, array{id: string, label: string, description?: string, aliases?: array<int, string>}>|\WP_Error
	 */
	public static function search( string $name, bool $force = false ) {
		$key = self::CACHE_PREFIX . 'search_' . md5( strtolower( trim( $name ) ) );

		return self::cached(
			$key,
			$force,
			function () use ( $name ) {
				$response = self::request(
					array(
						'action'   => 'wbsearchentities',
						'search'   => $name,
						'language' => 'en',
						'type'     => 'item',
						'format'   => 'json',
					),
					self::API_URL
				);

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				return self::normalise_search( (array) ( $response['search'] ?? array() ) );
			}
		);
	}

	/**
	 * The claims for one entity.
	 *
	 * @param string $qid   Wikidata item id.
	 * @param bool   $force Skip the cache.
	 * @return array<string, array<int, array>>|\WP_Error
	 */
	public static function entity( string $qid, bool $force = false ) {
		$key = self::CACHE_PREFIX . 'entity_' . $qid;

		return self::cached(
			$key,
			$force,
			function () use ( $qid ) {
				$response = self::request(
					array(
						'action'    => 'wbgetentities',
						'ids'       => $qid,
						'props'     => 'claims',
						'languages' => 'en',
						'format'    => 'json',
					),
					self::API_URL
				);

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				return (array) ( $response['entities'][ $qid ]['claims'] ?? array() );
			}
		);
	}

	/**
	 * Labels for a set of referenced entities — a team, a country, a discipline.
	 *
	 * @param array<int, string> $qids  Wikidata item ids.
	 * @param bool                $force Skip the cache.
	 * @return array<string, string>|\WP_Error QID => label.
	 */
	public static function labels( array $qids, bool $force = false ) {
		$qids = array_values( array_unique( array_filter( $qids ) ) );

		if ( ! $qids ) {
			return array();
		}

		sort( $qids );
		$key = self::CACHE_PREFIX . 'labels_' . md5( implode( '|', $qids ) );

		return self::cached(
			$key,
			$force,
			function () use ( $qids ) {
				$response = self::request(
					array(
						'action'    => 'wbgetentities',
						'ids'       => implode( '|', $qids ),
						'props'     => 'labels',
						'languages' => 'en',
						'format'    => 'json',
					),
					self::API_URL
				);

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				$flat = array();

				foreach ( (array) ( $response['entities'] ?? array() ) as $qid => $entity ) {
					$flat[ $qid ] = (string) ( $entity['labels']['en']['value'] ?? '' );
				}

				return $flat;
			}
		);
	}

	/**
	 * Races this rider has won, most recent first — the reverse of each race's own
	 * P1346 (winner) claim.
	 *
	 * @param string $qid   Wikidata item id.
	 * @param bool   $force Skip the cache.
	 * @return array<int, array{eventLabel: array{value: string}, pointInTime?: array{value: string}}>|\WP_Error
	 */
	public static function victories( string $qid, bool $force = false ) {
		$key = self::CACHE_PREFIX . 'victories_' . $qid;

		return self::cached(
			$key,
			$force,
			function () use ( $qid ) {
				$query = sprintf(
					'SELECT ?event ?eventLabel ?pointInTime WHERE { ?event wdt:P1346 wd:%s . OPTIONAL { ?event wdt:P585 ?pointInTime . } SERVICE wikibase:label { bd:serviceParam wikibase:language "en". } } ORDER BY DESC(?pointInTime) LIMIT 10',
					$qid
				);

				$response = self::request( array( 'query' => $query, 'format' => 'json' ), self::SPARQL_URL );

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				return (array) ( $response['results']['bindings'] ?? array() );
			}
		);
	}

	/**
	 * Read (and populate) the cache around a fetch.
	 *
	 * @param string   $key      Transient key.
	 * @param bool     $force    Skip the cache.
	 * @param callable $fetch    Returns the value to cache, or a WP_Error.
	 * @return mixed|\WP_Error
	 */
	private static function cached( string $key, bool $force, callable $fetch ) {
		if ( ! $force ) {
			$cached = get_transient( $key );

			if ( false !== $cached ) {
				return $cached;
			}
		}

		$value = $fetch();

		if ( is_wp_error( $value ) ) {
			return $value;
		}

		set_transient( $key, $value, self::CACHE_TTL );

		return $value;
	}

	/**
	 * Keep only the fields the mapper reads off a search candidate.
	 *
	 * @param array $raw Wikidata's own `search` array.
	 * @return array<int, array{id: string, label: string, description?: string, aliases?: array<int, string>}>
	 */
	private static function normalise_search( array $raw ): array {
		$candidates = array();

		foreach ( $raw as $item ) {
			$candidate = array(
				'id'    => (string) ( $item['id'] ?? '' ),
				'label' => (string) ( $item['label'] ?? '' ),
			);

			if ( isset( $item['description'] ) ) {
				$candidate['description'] = (string) $item['description'];
			}

			if ( ! empty( $item['aliases'] ) ) {
				$candidate['aliases'] = array_map( 'strval', (array) $item['aliases'] );
			}

			$candidates[] = $candidate;
		}

		return $candidates;
	}

	/**
	 * A GET request against one of Wikidata's own endpoints.
	 *
	 * @param array  $args Query args.
	 * @param string $url  Base URL.
	 * @return array|\WP_Error Decoded JSON body, or an error.
	 */
	private static function request( array $args, string $url ) {
		$response = wp_remote_get(
			add_query_arg( $args, $url ),
			array(
				'timeout'    => 10,
				'user-agent' => 'workflow-discovery-cycling/0.1 (https://github.com/smithjw1/vip-workflow-cycling-desk)',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== (int) $code ) {
			return new \WP_Error(
				'workflow_discovery_cycling_wikidata_http',
				sprintf( 'Wikidata returned HTTP %d.', (int) $code )
			);
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) ) {
			return new \WP_Error(
				'workflow_discovery_cycling_wikidata_unparsable',
				'Wikidata returned a response that could not be read as JSON.'
			);
		}

		return $decoded;
	}
}
