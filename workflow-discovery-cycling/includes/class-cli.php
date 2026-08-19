<?php
/**
 * WP-CLI commands for the cycling desk.
 *
 * Two things are worth doing from the command line. Installing the sequence, because
 * plugin activation cannot be relied on to run after VIP Workflow's own — and because
 * a demo that needs re-seeding should not need a trip through the admin.
 *
 * And looking at what the feeds actually returned. A discovery source that shows an
 * empty stream has three possible reasons — the feeds are down, the mapping dropped
 * everything, or the sequence is not installed — and they are indistinguishable from
 * the ideation screen.
 *
 * @package WorkflowDiscoveryCycling
 */

declare( strict_types=1 );

namespace WorkflowDiscoveryCycling;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage the cycling desk source and its sequence.
 */
class CLI {

	/**
	 * Install the bundled Cycling Desk sequence.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Overwrite the config of an existing Cycling Desk sequence. Discards any edits
	 * made to it on this site.
	 *
	 * ## EXAMPLES
	 *
	 *     wp workflow-cycling install-sequence
	 *     wp workflow-cycling install-sequence --force
	 *
	 * @subcommand install-sequence
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function install_sequence( array $args, array $assoc_args ): void {
		$force = ! empty( $assoc_args['force'] );

		$result = Sequence_Installer::install( $force );

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success(
			sprintf(
				'Cycling Desk sequence is installed as id %d. The discovery source will point its items at it.',
				$result
			)
		);
	}

	/**
	 * Show what the feeds returned and how it mapped.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Refetch instead of reading the cache.
	 *
	 * [--limit=<number>]
	 * : How many rows to print. Default 15.
	 *
	 * ## EXAMPLES
	 *
	 *     wp workflow-cycling stream
	 *     wp workflow-cycling stream --force --limit=40
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function stream( array $args, array $assoc_args ): void {
		$force = ! empty( $assoc_args['force'] );
		$limit = max( 1, (int) ( $assoc_args['limit'] ?? 15 ) );

		$blueprint_id = Sequence_Installer::id();

		if ( 0 === $blueprint_id ) {
			\WP_CLI::warning( 'No active Cycling Desk sequence on this site — items will carry no sequence, so ideation will ask for no metadata. Run: wp workflow-cycling install-sequence' );
		}

		$items = Feed_Reader::items( $force );

		if ( ! $items ) {
			\WP_CLI::error( 'No items came back from any feed. Check outbound HTTP from this environment.' );
		}

		$prompts = Prompt_Mapper::map_all( $items, $blueprint_id );

		\WP_CLI::log(
			sprintf(
				'%d items across %d feeds, %d mapped to prompts.',
				count( $items ),
				count( Feed_Reader::feeds() ),
				count( $prompts )
			)
		);

		$rows = array();

		foreach ( array_slice( $prompts, 0, $limit ) as $prompt ) {
			$meta = (array) $prompt['meta'];

			$rows[] = array(
				'publisher'  => (string) $meta['publisher'],
				'race'       => '' !== (string) $meta['race'] ? (string) $meta['race'] : '—',
				'story_type' => '' !== (string) $meta['story_type'] ? (string) $meta['story_type'] : '—',
				'title'      => mb_strimwidth( (string) $prompt['title'], 0, 70, '…' ),
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'publisher', 'race', 'story_type', 'title' ) );

		$with_race = count( array_filter( $prompts, static fn( array $p ): bool => '' !== (string) $p['meta']['race'] ) );
		$with_type = count( array_filter( $prompts, static fn( array $p ): bool => '' !== (string) $p['meta']['story_type'] ) );

		\WP_CLI::log(
			sprintf(
				'Race detected on %d of %d. Story type hinted on %d of %d.',
				$with_race,
				count( $prompts ),
				$with_type,
				count( $prompts )
			)
		);
	}

	/**
	 * Drop the cached feed items.
	 *
	 * ## EXAMPLES
	 *
	 *     wp workflow-cycling flush
	 */
	public function flush(): void {
		Feed_Reader::flush();

		\WP_CLI::success( 'Cached feed items dropped. The next read refetches.' );
	}

	/**
	 * Resolve a rider name against Wikidata, the way commissioning does.
	 *
	 * The only way to exercise the Wikidata client and the rider mapper outside of
	 * ideation — this repo has no WP test runner, so this is the manual verification
	 * path for the WordPress-layer half of the rider card.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The rider name to resolve, exactly as an author would type it.
	 *
	 * [--force]
	 * : Skip the 7-day lookup cache.
	 *
	 * ## EXAMPLES
	 *
	 *     wp workflow-cycling rider-lookup "Tadej Pogačar"
	 *     wp workflow-cycling rider-lookup "Pogacar"
	 *     wp workflow-cycling rider-lookup "Tadej Pogačar" --force
	 *
	 * @subcommand rider-lookup
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function rider_lookup( array $args, array $assoc_args ): void {
		$name  = (string) ( $args[0] ?? '' );
		$force = ! empty( $assoc_args['force'] );

		if ( '' === trim( $name ) ) {
			\WP_CLI::error( 'Usage: wp workflow-cycling rider-lookup "<name>"' );
		}

		$search = Wikidata_Client::search( $name, $force );

		if ( is_wp_error( $search ) ) {
			\WP_CLI::error( 'Wikidata search failed: ' . $search->get_error_message() );
		}

		$qid = Rider_Mapper::resolve_candidate( $search, $name );

		if ( null === $qid ) {
			\WP_CLI::log( sprintf( '%d candidate(s) found for "%s".', count( $search ), $name ) );
			\WP_CLI::warning( 'No confident match — either no candidate\'s label or alias matched exactly, or more than one did.' );
			return;
		}

		$entity = Wikidata_Client::entity( $qid, $force );

		if ( is_wp_error( $entity ) ) {
			\WP_CLI::error( 'Wikidata entity fetch failed: ' . $entity->get_error_message() );
		}

		if ( ! Rider_Mapper::is_cyclist( $entity ) ) {
			\WP_CLI::warning( sprintf( 'Resolved to %s, but nothing in their claims says they are a cyclist. No confident match.', $qid ) );
			return;
		}

		$referenced = array();

		foreach ( array( 'P54', 'P27', 'P641' ) as $property ) {
			foreach ( (array) ( $entity[ $property ] ?? array() ) as $claim ) {
				$referenced_qid = (string) ( $claim['mainsnak']['datavalue']['value']['id'] ?? '' );

				if ( '' !== $referenced_qid ) {
					$referenced[] = $referenced_qid;
				}
			}
		}

		$labels = $referenced ? Wikidata_Client::labels( $referenced, $force ) : array();

		if ( is_wp_error( $labels ) ) {
			$labels = array();
		}

		$victories = Wikidata_Client::victories( $qid, $force );

		if ( is_wp_error( $victories ) ) {
			$victories = null;
		}

		$card = Rider_Mapper::map( $name, $search, $entity, $labels, $victories );

		if ( null === $card ) {
			\WP_CLI::warning( 'Resolved and confirmed as a cyclist, but the card came back empty. No confident match.' );
			return;
		}

		\WP_CLI::log( sprintf( 'Resolved: %s (%s)', $card['name'], $card['riderQid'] ) );

		\WP_CLI\Utils\format_items(
			'table',
			array(
				array(
					'team'            => '' !== $card['team'] ? $card['team'] : '—',
					'nationality'     => '' !== $card['nationality'] ? $card['nationality'] : '—',
					'date_of_birth'   => '' !== $card['dateOfBirth'] ? $card['dateOfBirth'] : '—',
					'discipline'      => '' !== $card['discipline'] ? $card['discipline'] : '—',
					'notable_results' => $card['notableResults'] ? implode( '; ', $card['notableResults'] ) : '—',
				),
			),
			array( 'team', 'nationality', 'date_of_birth', 'discipline', 'notable_results' )
		);
	}
}
