/* "AI ready FAQs" block: shows the FAQs from Settings → AI ready. No build step. */
( function ( wp ) {
	var el = wp.element.createElement;
	var be = wp.blockEditor;
	var c = wp.components;
	var SSR = wp.serverSideRender;

	wp.blocks.registerBlockType( 'mjs-ai-ready/faq', {
		apiVersion: 2,
		title: 'AI ready FAQs',
		description: 'Questions and answers from Settings → AI ready, with FAQ structured data for search and AI tools.',
		category: 'widgets',
		icon: 'editor-help',
		keywords: [ 'faq', 'questions', 'ai' ],
		attributes: {
			ids: { type: 'string', default: '' },
			title: { type: 'string', default: '' }
		},
		edit: function ( props ) {
			var a = props.attributes;
			return el(
				'div',
				be.useBlockProps(),
				el(
					be.InspectorControls,
					null,
					el(
						c.PanelBody,
						{ title: 'FAQs' },
						el( c.TextControl, {
							label: 'Heading (optional)',
							value: a.title,
							onChange: function ( v ) { props.setAttributes( { title: v } ); }
						} ),
						el( c.TextControl, {
							label: 'Which questions',
							help: 'Leave empty for all, or list their numbers from Settings → AI ready, e.g. 1,3,4',
							value: a.ids,
							onChange: function ( v ) { props.setAttributes( { ids: v.replace( /[^0-9,]/g, '' ) } ); }
						} )
					)
				),
				el( SSR, { block: 'mjs-ai-ready/faq', attributes: a } )
			);
		},
		save: function () { return null; }
	} );
} )( window.wp );
