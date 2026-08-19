<?php
/**
 * The ideation-side stage: turns a typed rider name into a rider card, once.
 *
 * The `riders` field on the Cycling Desk sequence is asked at commission time, before
 * the post exists. Once the draft is created, this hooks `save_post` and — the first
 * time only, guarded by its own postmeta — resolves each named rider and appends a
 * rider card block for every one that resolves. A name that does not resolve is
 * skipped silently, on the same convention `Prompt_Mapper` uses: no card is a better
 * outcome than a guessed one, and this is not the place to relitigate that.
 *
 * Why `save_post` and not the draft action's own `callback`: nothing in this repo
 * exercises what arguments a non-null `callback` on `vip_workflow_ideation_draft_actions`
 * receives, and the core source that would answer that is not readable from here (see
 * CLAUDE.md). Hooking the save that the built-in draft-writing flow already performs
 * avoids depending on an unread, unstable signature.
 *
 * @package WorkflowDiscoveryCycling
 */

declare( strict_types=1 );

namespace WorkflowDiscoveryCycling;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the `riders` field on a fresh Cycling Desk post into rider card blocks.
 */
class Rider_Card_Commissioner {

	/**
	 * Postmeta key guarding against re-processing.
	 */
	private const PROCESSED_META_KEY = '_workflow_cycling_riders_processed';

	/**
	 * Metadata field carrying the typed rider names.
	 */
	private const FIELD_KEY = 'riders';

	/**
	 * Most rider names resolved per commission.
	 *
	 * Bounds the worst-case number of Wikidata round trips a single save can trigger.
	 */
	private const MAX_RIDERS = 5;

	/**
	 * Hooked to `save_post`.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post object.
	 */
	public static function on_save( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( '' !== get_post_meta( $post_id, self::PROCESSED_META_KEY, true ) ) {
			return;
		}

		if ( ! self::is_cycling_desk_post( $post_id ) ) {
			return;
		}

		// Set before doing any network work — wp_update_post() below re-fires this
		// hook, and this is what stops that re-entry from fetching a second time.
		update_post_meta( $post_id, self::PROCESSED_META_KEY, '1' );

		$names = self::rider_names( $post_id );

		if ( ! $names ) {
			return;
		}

		$blocks = array();

		foreach ( $names as $name ) {
			$card = self::resolve( $name );

			if ( null !== $card ) {
				$blocks[] = $card;
			}
		}

		if ( ! $blocks ) {
			return;
		}

		$markup = '';

		foreach ( $blocks as $attributes ) {
			$markup .= serialize_block(
				array(
					'blockName'    => 'workflow-discovery-cycling/rider-card',
					'attrs'        => $attributes,
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				)
			);
		}

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $post->post_content . $markup,
			)
		);
	}

	/**
	 * Whether this post belongs to the Cycling Desk sequence.
	 *
	 * There is no documented, stable postmeta key for "which sequence is this post
	 * on" reachable from this repo (see CLAUDE.md on the unreadable core branch), so
	 * this checks for the sequence's own metadata already being set rather than
	 * guessing at one. If that signal turns out to be wrong, the failure mode is this
	 * returning false and no card being attached — a smaller failure than attaching
	 * the wrong desk's card.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	private static function is_cycling_desk_post( int $post_id ): bool {
		if ( 0 === Sequence_Installer::id() ) {
			return false;
		}

		$story_type = get_post_meta( $post_id, 'story_type', true );

		return '' !== $story_type;
	}

	/**
	 * The typed rider names, trimmed, deduped, capped.
	 *
	 * @param int $post_id Post id.
	 * @return array<int, string>
	 */
	private static function rider_names( int $post_id ): array {
		$raw = (string) get_post_meta( $post_id, self::FIELD_KEY, true );

		if ( '' === trim( $raw ) ) {
			return array();
		}

		$names = array_map( 'trim', explode( ',', $raw ) );
		$names = array_filter(
			$names,
			static function ( string $name ): bool {
				return '' !== $name;
			}
		);
		$names = array_values( array_unique( $names ) );

		return array_slice( $names, 0, self::MAX_RIDERS );
	}

	/**
	 * Resolve one typed name into rider card block attributes, or null.
	 *
	 * Each of search/entity/labels/victories independently degrades to "skip this
	 * rider" on a Wikidata error — a Wikidata outage skips the rider it happened on,
	 * not the whole commission.
	 *
	 * @param string $name Typed rider name.
	 * @return array|null
	 */
	private static function resolve( string $name ): ?array {
		$search = Wikidata_Client::search( $name );

		if ( is_wp_error( $search ) ) {
			return null;
		}

		$qid = Rider_Mapper::resolve_candidate( $search, $name );

		if ( null === $qid ) {
			return null;
		}

		$entity = Wikidata_Client::entity( $qid );

		if ( is_wp_error( $entity ) ) {
			return null;
		}

		if ( ! Rider_Mapper::is_cyclist( $entity ) ) {
			return null;
		}

		$referenced = self::referenced_qids( $entity );
		$labels     = $referenced ? Wikidata_Client::labels( $referenced ) : array();

		if ( is_wp_error( $labels ) ) {
			$labels = array();
		}

		$victories = Wikidata_Client::victories( $qid );

		if ( is_wp_error( $victories ) ) {
			$victories = null;
		}

		return Rider_Mapper::map( $name, $search, $entity, $labels, $victories );
	}

	/**
	 * Every team/country/sport QID this entity's claims point at, so their labels can
	 * be fetched in one request.
	 *
	 * @param array<string, array<int, array>> $entity_claims Claims.
	 * @return array<int, string>
	 */
	private static function referenced_qids( array $entity_claims ): array {
		$qids = array();

		foreach ( array( 'P54', 'P27', 'P641' ) as $property ) {
			foreach ( (array) ( $entity_claims[ $property ] ?? array() ) as $claim ) {
				$qid = (string) ( $claim['mainsnak']['datavalue']['value']['id'] ?? '' );

				if ( '' !== $qid ) {
					$qids[] = $qid;
				}
			}
		}

		return array_values( array_unique( $qids ) );
	}
}
