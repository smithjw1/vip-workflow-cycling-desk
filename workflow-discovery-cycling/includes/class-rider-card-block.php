<?php
/**
 * Registers the rider card block and its editor script.
 *
 * @package WorkflowDiscoveryCycling
 */

declare( strict_types=1 );

namespace WorkflowDiscoveryCycling;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../blocks/rider-card/render.php';

/**
 * Wires the rider card block into WordPress.
 */
class Rider_Card_Block {

	/**
	 * Register the block type.
	 */
	public static function register(): void {
		register_block_type(
			Plugin::path() . 'blocks/rider-card',
			array( 'render_callback' => array( self::class, 'render' ) )
		);
	}

	/**
	 * The block's render callback — hands straight off to the pure render function.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render( array $attributes ): string {
		return render_rider_card( $attributes );
	}

	/**
	 * Enqueue the editor-only script.
	 *
	 * A plain script, not a module — this plugin has no build step to produce one.
	 */
	public static function enqueue_editor_assets(): void {
		wp_enqueue_script(
			'workflow-discovery-cycling-rider-card-edit',
			Plugin::url() . 'blocks/rider-card/edit.js',
			array( 'wp-blocks', 'wp-element', 'wp-server-side-render' ),
			'0.1.0',
			true
		);
	}
}
