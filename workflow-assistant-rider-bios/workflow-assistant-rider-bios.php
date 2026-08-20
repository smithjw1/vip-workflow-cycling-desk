<?php
/**
 * Plugin Name: Workflow Assistant: Rider Bios (Wikipedia)
 * Description: A VIP Workflows research ability that returns short rider biographies from Wikipedia for every rider named in a story, so a desk does not have to search for each one by hand.
 * Version: 0.1.0
 * Author: Jacob Smith
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires Plugins: vip-workflow
 * Text Domain: workflow-assistant-rider-bios
 *
 * @package WorkflowAssistantRiderBios
 */

declare( strict_types=1 );

namespace WorkflowAssistantRiderBios;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-rider-extractor.php';
require_once __DIR__ . '/includes/class-wikipedia-client.php';
require_once __DIR__ . '/includes/class-bio-mapper.php';

/**
 * Registers the rider-bios research ability.
 *
 * This is an add-on alongside the Cycling Desk discovery source in this repo, not
 * a change to it — a separate plugin, wired into VIP Workflows through its own
 * extension point (a research ability) rather than the discovery one.
 */
class Plugin {

	/**
	 * The ability's id, namespaced to this plugin as the abilities API expects.
	 */
	public const ABILITY_ID = 'workflow-assistant-rider-bios/rider-bios';

	/**
	 * Hook everything up.
	 */
	public static function boot(): void {
		add_action( 'wp_abilities_api_init', array( self::class, 'register_ability' ) );
	}

	/**
	 * Register the ability.
	 *
	 * No settings and no availability check — Wikipedia's API takes no key, so
	 * there is nothing for a desk to configure before this works.
	 */
	public static function register_ability(): void {
		vip_workflow_register_ability(
			self::ABILITY_ID,
			array(
				'label'               => __( 'Rider Bios (Wikipedia)', 'workflow-assistant-rider-bios' ),
				'description'         => __( 'Finds the riders named in a story and returns a short bio for each — nationality, current team, and notable and recent wins — from Wikipedia.', 'workflow-assistant-rider-bios' ),
				'category'            => 'research',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'seed'          => array(
							'type'        => 'string',
							'description' => __( 'The story text to scan for rider names.', 'workflow-assistant-rider-bios' ),
						),
						'seed_analysis' => array(
							'type' => 'object',
						),
						'query'         => array(
							'type'        => 'string',
							'description' => __( 'Look this one rider up directly, instead of scanning the seed for names.', 'workflow-assistant-rider-bios' ),
						),
					),
					'required'   => array( 'seed' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'riders'     => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'name'            => array( 'type' => 'string' ),
									'nationality'     => array( 'type' => 'string' ),
									'current_team'    => array( 'type' => 'string' ),
									'recent_wins'     => array(
										'type'  => 'array',
										'items' => array( 'type' => 'string' ),
									),
									'notable_wins'    => array(
										'type'  => 'array',
										'items' => array( 'type' => 'string' ),
									),
									'source_url'      => array( 'type' => 'string' ),
									'wikipedia_title' => array( 'type' => 'string' ),
								),
							),
						),
						'unresolved' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Names found in the story that could not be confidently matched to a rider on Wikipedia.', 'workflow-assistant-rider-bios' ),
						),
					),
				),
				'execute_callback'    => array( self::class, 'execute' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => array(
					'type'                  => 'research',
					'icon'                  => '🚴',
					'thinking_message'      => __( 'Looking up riders on Wikipedia…', 'workflow-assistant-rider-bios' ),
					'success_message'       => __( 'Rider bios ready.', 'workflow-assistant-rider-bios' ),
					'availability_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Find the riders in the seed text and look each one up.
	 *
	 * A candidate that does not resolve to a confirmed cyclist is dropped from
	 * `riders` and named in `unresolved` instead of coming back with empty fields —
	 * the same rule the cycling desk's own mapper runs on: a wrong hint is worse
	 * than no hint, so nothing here guesses past what Wikipedia actually says.
	 *
	 * @param array $input Ability input — seed, and optionally a direct query.
	 * @return array
	 */
	public static function execute( array $input ): array {
		$seed  = (string) ( $input['seed'] ?? '' );
		$query = trim( (string) ( $input['query'] ?? '' ) );

		$candidates = '' !== $query ? array( $query ) : Rider_Extractor::extract_candidates( $seed );

		$riders          = array();
		$unresolved      = array();
		$resolved_titles = array();
		$reference_year  = (int) gmdate( 'Y' );

		foreach ( $candidates as $candidate ) {
			$raw = Wikipedia_Client::lookup( $candidate );
			$bio = null !== $raw ? Bio_Mapper::map( $raw, $reference_year ) : null;

			if ( null === $bio ) {
				$unresolved[] = $candidate;
				continue;
			}

			/*
			 * Two candidates in the same story can resolve to the same page — 'Wout
			 * van Aert' on first mention and 'Van Aert' on a second, later one. The
			 * Wikipedia title, not the candidate text, is what says they are the
			 * same rider.
			 */
			if ( in_array( $bio['wikipedia_title'], $resolved_titles, true ) ) {
				continue;
			}

			$resolved_titles[] = $bio['wikipedia_title'];
			$riders[]          = $bio;
		}

		return array(
			'riders'     => $riders,
			'unresolved' => $unresolved,
		);
	}
}

Plugin::boot();
