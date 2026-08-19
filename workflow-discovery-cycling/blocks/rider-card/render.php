<?php
/**
 * The rider card's one render path — shared by the editor preview and the front end,
 * because both call this same function. There is no separate "editor" markup to keep
 * in sync with it.
 *
 * Reads only `$attributes`. No fetch, no transient, no `wp_remote_get()` — the facts
 * were fetched once at commissioning and live in the block's own attributes, which is
 * what makes "no outbound HTTP on page view" true by construction.
 *
 * @package WorkflowDiscoveryCycling
 */

declare( strict_types=1 );

namespace WorkflowDiscoveryCycling;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the rider card, or nothing.
 *
 * A missing name is the one fact resolution guarantees when it produces a card at
 * all, so it is the single check that makes the card degrade to nothing visible —
 * no wrapper element, not an empty one — on both the editor and the front end.
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function render_rider_card( array $attributes ): string {
	$name = trim( (string) ( $attributes['name'] ?? '' ) );

	if ( '' === $name ) {
		return '';
	}

	$rows = array();

	$team = trim( (string) ( $attributes['team'] ?? '' ) );

	if ( '' !== $team ) {
		$rows[] = array( __( 'Team', 'workflow-discovery-cycling' ), $team );
	}

	$nationality = trim( (string) ( $attributes['nationality'] ?? '' ) );

	if ( '' !== $nationality ) {
		$rows[] = array( __( 'Nationality', 'workflow-discovery-cycling' ), $nationality );
	}

	$date_of_birth = trim( (string) ( $attributes['dateOfBirth'] ?? '' ) );

	if ( '' !== $date_of_birth ) {
		$rows[] = array( __( 'Date of birth', 'workflow-discovery-cycling' ), $date_of_birth );
	}

	$discipline = trim( (string) ( $attributes['discipline'] ?? '' ) );

	if ( '' !== $discipline ) {
		$rows[] = array( __( 'Discipline', 'workflow-discovery-cycling' ), $discipline );
	}

	$notable_results = array_values(
		array_filter(
			array_map(
				static function ( $result ): string {
					return trim( (string) $result );
				},
				(array) ( $attributes['notableResults'] ?? array() )
			),
			static function ( string $result ): bool {
				return '' !== $result;
			}
		)
	);

	$wrapper_attributes = get_block_wrapper_attributes(
		array( 'class' => 'workflow-discovery-cycling-rider-card' )
	);

	ob_start();
	?>
	<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() escapes its own output. ?>>
		<p class="workflow-discovery-cycling-rider-card__name"><?php echo esc_html( $name ); ?></p>
		<?php if ( $rows ) : ?>
			<dl class="workflow-discovery-cycling-rider-card__facts">
				<?php foreach ( $rows as $row ) : ?>
					<dt><?php echo esc_html( $row[0] ); ?></dt>
					<dd><?php echo esc_html( $row[1] ); ?></dd>
				<?php endforeach; ?>
			</dl>
		<?php endif; ?>
		<?php if ( $notable_results ) : ?>
			<ul class="workflow-discovery-cycling-rider-card__results">
				<?php foreach ( $notable_results as $result ) : ?>
					<li><?php echo esc_html( $result ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}
