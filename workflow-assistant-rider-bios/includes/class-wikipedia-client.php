<?php
/**
 * Looks a candidate rider up on Wikipedia.
 *
 * The WordPress-using half of this plugin, in the same split as the cycling desk's
 * Feed_Reader and Prompt_Mapper: this class fetches and hands back plain arrays,
 * and does none of the guessing about whether the result is actually a cyclist —
 * that is Bio_Mapper's job, on data this class has no opinion about.
 *
 * Wikipedia's REST summary endpoint already resolves redirects and reports
 * disambiguation pages by type, which is why a candidate that turns out to be
 * ambiguous — several riders, or several people, sharing a name — is treated the
 * same as one with no page at all: found elsewhere on the internet only counts
 * when it resolves to one specific person.
 *
 * @package WorkflowAssistantRiderBios
 */

declare( strict_types=1 );

namespace WorkflowAssistantRiderBios;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches rider data from the public Wikipedia API. No key, no rate limit
 * encountered — see docs/SOURCES.md in this repo, which already checked.
 */
class Wikipedia_Client {

	/**
	 * Identifies requests to Wikipedia, as their API etiquette asks.
	 */
	private const USER_AGENT = 'workflow-assistant-rider-bios/0.1.0 (VIP Workflows extension; https://github.com/smithjw1/vip-workflow-cycling-desk)';

	/**
	 * Look a name up.
	 *
	 * @param string $name Candidate name, as read out of story text.
	 * @return array|null Title, description, extract, categories and url, or null if
	 *                     nothing resolved to one specific page.
	 */
	public static function lookup( string $name ): ?array {
		$name = trim( $name );

		if ( '' === $name ) {
			return null;
		}

		$summary = self::fetch_summary( $name );

		if ( null === $summary ) {
			return null;
		}

		$title = (string) ( $summary['title'] ?? $name );

		return array(
			'title'       => $title,
			'description' => (string) ( $summary['description'] ?? '' ),
			'extract'     => (string) ( $summary['extract'] ?? '' ),
			'categories'  => self::fetch_categories( $title ),
			'url'         => (string) ( $summary['content_urls']['desktop']['page'] ?? self::page_url( $title ) ),
		);
	}

	/**
	 * The REST summary for a title — the resolved page, its description and its
	 * intro extract, or null if there is no one page to resolve to.
	 *
	 * @param string $name Candidate name.
	 * @return array|null
	 */
	private static function fetch_summary( string $name ): ?array {
		$url = sprintf(
			'https://en.wikipedia.org/api/rest_v1/page/summary/%s',
			rawurlencode( str_replace( ' ', '_', $name ) )
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 8,
				'redirection' => 3,
				'headers'    => array( 'Accept' => 'application/json' ),
				'user-agent' => self::USER_AGENT,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return null;
		}

		// A disambiguation page means several things share this name — not one specific rider.
		if ( 'disambiguation' === ( $body['type'] ?? '' ) ) {
			return null;
		}

		return $body;
	}

	/**
	 * The resolved page's categories, or an empty list.
	 *
	 * Failure here is not fatal to the lookup as a whole — a description alone is
	 * sometimes enough for Bio_Mapper to confirm a cyclist — the same way one feed
	 * being down does not empty the cycling desk's stream.
	 *
	 * @param string $title Resolved page title.
	 * @return array<int, string>
	 */
	private static function fetch_categories( string $title ): array {
		$url = add_query_arg(
			array(
				'action'  => 'query',
				'prop'    => 'categories',
				'format'  => 'json',
				'cllimit' => 500,
				'titles'  => $title,
			),
			'https://en.wikipedia.org/w/api.php'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 8,
				'user-agent' => self::USER_AGENT,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return array();
		}

		$categories = array();

		foreach ( (array) ( $body['query']['pages'] ?? array() ) as $page ) {
			foreach ( (array) ( $page['categories'] ?? array() ) as $category ) {
				$label = (string) ( $category['title'] ?? '' );
				$label = preg_replace( '/^Category:/', '', $label );

				if ( '' !== $label ) {
					$categories[] = $label;
				}
			}
		}

		return $categories;
	}

	/**
	 * Build a page url from a title, for the rare summary response with none.
	 *
	 * @param string $title Page title.
	 * @return string
	 */
	private static function page_url( string $title ): string {
		return 'https://en.wikipedia.org/wiki/' . rawurlencode( str_replace( ' ', '_', $title ) );
	}
}
