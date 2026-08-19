/**
 * The rider card's editor preview.
 *
 * No JSX and no build step, on the same constraint as the rest of this plugin — a
 * plain script, `wp.element.createElement` calls, enqueued with its dependencies
 * declared as WordPress script handles.
 *
 * `ServerSideRender` pointed at this block's own name means the editor preview runs
 * the exact same PHP function (`render_rider_card()`) as the front end. There is no
 * separate client-rendered markup to keep in sync with it, and no controls to edit
 * the facts by hand — the whole point of this block is that its facts came from one
 * source, so a local override field would be a second, competing one.
 */
( function ( blocks, element, serverSideRender ) {
	var el = element.createElement;

	blocks.registerBlockType( 'workflow-discovery-cycling/rider-card', {
		title: 'Rider Card',
		category: 'widgets',
		icon: 'id',
		description:
			"A rider's factual details — team, nationality, date of birth, discipline, notable results — fetched from Wikidata once at commissioning.",
		attributes: {
			riderQid: { type: 'string', default: '' },
			name: { type: 'string', default: '' },
			team: { type: 'string', default: '' },
			nationality: { type: 'string', default: '' },
			dateOfBirth: { type: 'string', default: '' },
			discipline: { type: 'string', default: '' },
			notableResults: { type: 'array', default: [] },
		},
		supports: { html: false },
		edit: function ( props ) {
			return el( serverSideRender, {
				block: 'workflow-discovery-cycling/rider-card',
				attributes: props.attributes,
			} );
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.serverSideRender );
