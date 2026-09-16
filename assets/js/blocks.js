/**
 * The editor side of the three blocks.
 *
 * Written in plain JavaScript rather than JSX so the plugin needs no build
 * step: what is in the repository is what runs, which is also what a reviewer
 * gets to read.
 *
 * Each block renders on the server, so there is nothing to save and nothing to
 * preview here beyond a placeholder saying what will appear.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var createElement = wp.element.createElement;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var Placeholder = wp.components.Placeholder;
	var __ = wp.i18n.__;

	var BLOCKS = [
		{
			name: 'bluebranch-chatbot/widget',
			icon: 'format-chat',
			label: __( 'Chatbot Widget', 'bluebranch-chatbot' ),
			hint: __( 'A collapsible chat button appears here. Its name, colour and greeting come from the plugin settings.', 'bluebranch-chatbot' )
		},
		{
			name: 'bluebranch-chatbot/search',
			icon: 'search',
			label: __( 'Chatbot Search Answer', 'bluebranch-chatbot' ),
			hint: __( 'A question field with its answer below it. On a search results page it answers the term that was searched for instead, without showing a second field.', 'bluebranch-chatbot' )
		}
	];

	BLOCKS.forEach( function ( block ) {
		registerBlockType( block.name, {
			edit: function () {
				return createElement(
					'div',
					useBlockProps(),
					createElement(
						Placeholder,
						{
							icon: block.icon,
							label: block.label,
							instructions: block.hint
						}
					)
				);
			},
			save: function () {
				// Rendered on the server so it always reflects the current settings.
				return null;
			}
		} );
	} );
}( window.wp ) );
