<?php
/**
 * Plugin Name: Workflow Discovery: Cycling Desk
 * Description: A cycling news discovery source for VIP Workflows, paired with a write-edit-publish sequence that asks for its commissioning metadata during ideation.
 * Version: 0.1.0
 * Author: Jacob Smith
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires Plugins: vip-workflow
 * Text Domain: workflow-discovery-cycling
 *
 * @package WorkflowDiscoveryCycling
 */

declare( strict_types=1 );

namespace WorkflowDiscoveryCycling;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-feed-reader.php';
require_once __DIR__ . '/includes/class-prompt-mapper.php';
require_once __DIR__ . '/includes/class-sequence-installer.php';

/**
 * Wires the source, the sequence and the commissioning action together.
 */
class Plugin {

	/**
	 * Provider slug, as core knows it.
	 */
	public const PROVIDER_SLUG = 'cycling-desk';

	/**
	 * Key of the draft action this plugin registers.
	 */
	public const ACTION_KEY = 'cycling-commission';

	/**
	 * Absolute path to this plugin, with a trailing slash.
	 *
	 * @return string
	 */
	public static function path(): string {
		return plugin_dir_path( __FILE__ );
	}

	/**
	 * Hook everything up.
	 */
	public static function boot(): void {
		add_action( 'vip_workflow_register_discovery_providers', array( self::class, 'register_provider' ) );
		add_filter( 'vip_workflow_ideation_draft_actions', array( self::class, 'register_draft_action' ) );

		register_activation_hook( __FILE__, array( self::class, 'on_activation' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once __DIR__ . '/includes/class-cli.php';
			\WP_CLI::add_command( 'workflow-cycling', CLI::class );
		}
	}

	/**
	 * Install the sequence when the plugin is switched on.
	 *
	 * Activation order is not ours to control — somebody can activate this before VIP
	 * Workflow, in which case there is no repository to write to. Failing quietly is
	 * right: the CLI command and the admin notice both exist for that case, and
	 * halting activation over it would be a worse first impression than a source that
	 * needs one more command.
	 */
	public static function on_activation(): void {
		Sequence_Installer::install();
	}

	/**
	 * Register the discovery source.
	 *
	 * @param object $registry Provider registry.
	 */
	public static function register_provider( $registry ): void {
		$registry->register(
			self::PROVIDER_SLUG,
			array(
				'label'                 => __( 'Cycling Desk', 'workflow-discovery-cycling' ),
				'description'           => __( 'Cycling stories from Cyclingnews, Velo and Cycling Weekly.', 'workflow-discovery-cycling' ),
				'icon'                  => 'megaphone',
				'features'              => array( 'recommend', 'search' ),
				'callbacks'             => array(
					'recommend' => array( self::class, 'recommend' ),
					'search'    => array( self::class, 'search' ),
					'filters'   => array( self::class, 'filters' ),
					'seed'      => array( Prompt_Mapper::class, 'seed' ),
				),
				'availability_callback' => '__return_true',
			)
		);
	}

	/**
	 * The stream, filtered by publisher and story type.
	 *
	 * @param array $config Provider config, carrying any set filter values.
	 * @return array
	 */
	public static function recommend( array $config ): array {
		$prompts = Prompt_Mapper::map_all( Feed_Reader::items(), Sequence_Installer::id() );

		return self::apply_filters_to( $prompts, $config );
	}

	/**
	 * Text search across the stream.
	 *
	 * Searches the cached items rather than asking the publishers, because none of
	 * these feeds expose a query endpoint. That bounds a search to roughly the last
	 * day of coverage, which is worth saying out loud in the UI rather than letting
	 * an editor conclude a race was never covered.
	 *
	 * @param array $params Search params.
	 * @return array
	 */
	public static function search( array $params ): array {
		$prompts = Prompt_Mapper::map_all( Feed_Reader::items(), Sequence_Installer::id() );
		$prompts = self::apply_filters_to( $prompts, $params );

		$text = strtolower( trim( (string) ( $params['text'] ?? '' ) ) );

		if ( '' === $text ) {
			return $prompts;
		}

		return array_values(
			array_filter(
				$prompts,
				static function ( array $prompt ) use ( $text ): bool {
					$haystack = strtolower(
						$prompt['title'] . ' ' . $prompt['description'] . ' ' . implode( ' ', (array) $prompt['tags'] )
					);

					return str_contains( $haystack, $text );
				}
			)
		);
	}

	/**
	 * The filter controls shown above the stream.
	 *
	 * @return array
	 */
	public static function filters(): array {
		$publishers = array(
			array(
				'value' => 'all',
				'label' => __( 'All publishers', 'workflow-discovery-cycling' ),
			),
		);

		foreach ( Feed_Reader::feeds() as $feed ) {
			$publishers[] = array(
				'value' => (string) $feed['name'],
				'label' => (string) $feed['name'],
			);
		}

		return array(
			array(
				'key'     => 'publisher',
				'label'   => __( 'Publisher', 'workflow-discovery-cycling' ),
				'type'    => 'select',
				'options' => $publishers,
			),
			array(
				'key'     => 'story_type',
				'label'   => __( 'Looks like', 'workflow-discovery-cycling' ),
				'type'    => 'select',
				'options' => array(
					array(
						'value' => 'all',
						'label' => __( 'Anything', 'workflow-discovery-cycling' ),
					),
					array(
						'value' => 'Race report',
						'label' => __( 'Race report', 'workflow-discovery-cycling' ),
					),
					array(
						'value' => 'Rider news',
						'label' => __( 'Rider news', 'workflow-discovery-cycling' ),
					),
					array(
						'value' => 'Analysis',
						'label' => __( 'Analysis', 'workflow-discovery-cycling' ),
					),
					array(
						'value' => 'Tech',
						'label' => __( 'Tech', 'workflow-discovery-cycling' ),
					),
					array(
						'value' => 'Interview',
						'label' => __( 'Interview', 'workflow-discovery-cycling' ),
					),
					array(
						'value' => 'Live blog',
						'label' => __( 'Live blog', 'workflow-discovery-cycling' ),
					),
				),
			),
		);
	}

	/**
	 * Register the commissioning action.
	 *
	 * Uses the built-in draft-writing flow — this is not a different way of creating a
	 * post, it is the same one with the desk's rules attached. All it adds is the
	 * cross-field check, which is the only place that check can live: the fields are
	 * defined on the sequence, so nothing above the action sees the whole set.
	 *
	 * @param array $actions Registered actions.
	 * @return array
	 */
	public static function register_draft_action( array $actions ): array {
		$actions[ self::ACTION_KEY ] = array(
			'key'            => self::ACTION_KEY,
			'label'          => __( 'Commission Story', 'workflow-discovery-cycling' ),
			'description'    => __( 'Writes a first draft from the research, and refuses a commission whose embargo has no time on it.', 'workflow-discovery-cycling' ),
			'writes_content' => true,
			'callback'       => null,
			'validate'       => array( self::class, 'validate_commission' ),
		);

		return $actions;
	}

	/**
	 * The embargo rule.
	 *
	 * A story marked Embargoed with no time on it is the failure this exists to catch.
	 * It reads as handled — somebody set the field — and then nothing stops it going
	 * out, because there is no time for anything to compare against. A time in the
	 * past is the same problem wearing a value.
	 *
	 * @param array $metadata Submitted field values, keyed by field key.
	 * @param array $context  Project id, blueprint id, title.
	 * @return true|\WP_Error
	 */
	public static function validate_commission( array $metadata, array $context ) {
		$state = trim( (string) ( $metadata['embargo_state'] ?? '' ) );
		$until = trim( (string) ( $metadata['embargo_until'] ?? '' ) );

		if ( 'Embargoed' !== $state ) {
			/*
			 * A time with no embargo state is somebody who filled in half the pair and
			 * moved on. Refusing that would be pedantry — the time is harmless on its
			 * own and the state is the thing anything downstream reads.
			 */
			return true;
		}

		if ( '' === $until ) {
			return new \WP_Error(
				'workflow_discovery_cycling_embargo_no_time',
				__( 'This story is marked embargoed but has no embargo time. Set Embargo Until, or set Embargo to None.', 'workflow-discovery-cycling' ),
				array( 'status' => 400 )
			);
		}

		/*
		 * The control posts a timezone-less datetime-local value — '2026-08-20T14:00' —
		 * and the editor meant that in site time. strtotime() would read it in PHP's
		 * timezone instead, which WordPress sets to UTC, so an embargo typed by a London
		 * desk in summer would come out an hour off and a 30-minute embargo would read
		 * as already passed. Constructing it against wp_timezone() is what makes the
		 * comparison mean what the editor typed.
		 *
		 * This is exactly the class of bug a UTC dev environment hides, because there
		 * the two frames agree.
		 */
		try {
			$moment = new \DateTimeImmutable( $until, wp_timezone() );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'workflow_discovery_cycling_embargo_unreadable',
				__( 'The embargo time could not be read as a date and time.', 'workflow-discovery-cycling' ),
				array( 'status' => 400 )
			);
		}

		if ( $moment->getTimestamp() <= time() ) {
			return new \WP_Error(
				'workflow_discovery_cycling_embargo_past',
				__( 'The embargo time has already passed. Set a time in the future, or set Embargo to None.', 'workflow-discovery-cycling' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Narrow prompts by the publisher and story-type filters.
	 *
	 * @param array $prompts Mapped prompts.
	 * @param array $values  Filter values.
	 * @return array
	 */
	private static function apply_filters_to( array $prompts, array $values ): array {
		$publisher  = (string) ( $values['publisher'] ?? 'all' );
		$story_type = (string) ( $values['story_type'] ?? 'all' );

		return array_values(
			array_filter(
				$prompts,
				static function ( array $prompt ) use ( $publisher, $story_type ): bool {
					$meta = (array) ( $prompt['meta'] ?? array() );

					if ( 'all' !== $publisher && '' !== $publisher && $publisher !== (string) ( $meta['publisher'] ?? '' ) ) {
						return false;
					}

					if ( 'all' !== $story_type && '' !== $story_type && $story_type !== (string) ( $meta['story_type'] ?? '' ) ) {
						return false;
					}

					return true;
				}
			)
		);
	}
}

Plugin::boot();
