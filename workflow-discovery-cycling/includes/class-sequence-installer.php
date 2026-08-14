<?php
/**
 * Installs the Cycling Desk sequence, and finds it again later.
 *
 * The discovery source and the sequence only work as a pair: the source points its
 * items at a sequence, and that sequence is what decides which metadata ideation
 * asks for. Shipping the source without the sequence would give the desk a stream of
 * cycling stories and none of the point.
 *
 * Resolution is by slug, not by id. Ids differ between every site the plugin is
 * installed on, and a hard-coded one would silently point at whatever sequence
 * happens to hold that id — which is worse than pointing at nothing, because the
 * ideation screen would then ask for another desk's fields.
 *
 * @package WorkflowDiscoveryCycling
 */

declare( strict_types=1 );

namespace WorkflowDiscoveryCycling;

use VIPWorkflow\Blueprints\BlueprintRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and locates the bundled sequence.
 */
class Sequence_Installer {

	/**
	 * Slug of the bundled sequence.
	 */
	public const SLUG = 'cycling-desk';

	/**
	 * The sequence's id on this site, or 0 if it is not installed or not active.
	 *
	 * Resolved per request rather than cached in an option, so deactivating or
	 * archiving the sequence takes effect immediately. An archived sequence returning
	 * 0 is the behaviour core's own attach() expects — it degrades to no sequence
	 * rather than failing the seed.
	 *
	 * @return int
	 */
	public static function id(): int {
		if ( ! class_exists( BlueprintRepository::class ) ) {
			return 0;
		}

		$repository = new BlueprintRepository();
		$blueprint  = $repository->find_by_slug( self::SLUG );

		if ( ! $blueprint || ! $blueprint->is_active() ) {
			return 0;
		}

		return (int) $blueprint->id;
	}

	/**
	 * Install the bundled sequence if it is not already there.
	 *
	 * Never overwrites. Once the sequence is on the site it belongs to whoever has
	 * been editing it, and a plugin update that reset somebody's stage labels back to
	 * the shipped defaults would be a data loss nobody asked for. Re-installing after
	 * deliberate changes is a `wp workflow-cycling install-sequence --force` away.
	 *
	 * @param bool $force Replace an existing sequence's config.
	 * @return int|\WP_Error Sequence id, or an error.
	 */
	public static function install( bool $force = false ) {
		if ( ! class_exists( BlueprintRepository::class ) ) {
			return new \WP_Error(
				'workflow_discovery_cycling_no_core',
				__( 'VIP Workflow is not active, so there is nowhere to install the sequence.', 'workflow-discovery-cycling' )
			);
		}

		$definition = self::definition();

		if ( is_wp_error( $definition ) ) {
			return $definition;
		}

		$repository = new BlueprintRepository();
		$existing   = $repository->find_by_slug( self::SLUG );

		if ( $existing ) {
			if ( ! $force ) {
				return (int) $existing->id;
			}

			$updated = $repository->update(
				(int) $existing->id,
				array(
					'name'        => (string) $definition['name'],
					'description' => (string) $definition['description'],
					'config'      => (array) $definition['config'],
				)
			);

			if ( ! $updated ) {
				return new \WP_Error(
					'workflow_discovery_cycling_update_failed',
					__( 'Could not update the existing Cycling Desk sequence.', 'workflow-discovery-cycling' )
				);
			}

			return (int) $existing->id;
		}

		$id = $repository->create(
			(string) $definition['name'],
			self::SLUG,
			(string) $definition['description'],
			(array) $definition['config'],
			get_current_user_id()
		);

		if ( ! $id ) {
			return new \WP_Error(
				'workflow_discovery_cycling_create_failed',
				__( 'Could not create the Cycling Desk sequence.', 'workflow-discovery-cycling' )
			);
		}

		return (int) $id;
	}

	/**
	 * The bundled sequence definition.
	 *
	 * @return array|\WP_Error
	 */
	public static function definition() {
		$path = Plugin::path() . 'sequences/cycling-desk.json';

		if ( ! is_readable( $path ) ) {
			return new \WP_Error(
				'workflow_discovery_cycling_missing_json',
				__( 'The bundled sequence file is missing.', 'workflow-discovery-cycling' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file shipped inside this plugin.
		$raw = file_get_contents( $path );

		$definition = json_decode( (string) $raw, true );

		if ( ! is_array( $definition ) || empty( $definition['config']['statuses'] ) ) {
			return new \WP_Error(
				'workflow_discovery_cycling_bad_json',
				__( 'The bundled sequence file could not be read as a sequence.', 'workflow-discovery-cycling' )
			);
		}

		return $definition;
	}
}
