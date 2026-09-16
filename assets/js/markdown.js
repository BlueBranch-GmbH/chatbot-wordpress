/**
 * A small Markdown renderer for the answers the chatbot sends back.
 *
 * The Contao version of this extension ships marked.js. Here the answer is
 * rendered by hand for one reason: every character is HTML-escaped before a
 * single Markdown rule is applied, so nothing the API returns can become
 * markup. An answer is generated text, and generated text has not earned the
 * right to write into the page.
 */
( function ( window ) {
	'use strict';

	var ESCAPES = {
		'&': '&amp;',
		'<': '&lt;',
		'>': '&gt;',
		'"': '&quot;',
		"'": '&#39;'
	};

	var CODE_MARKER = '@@bbchatcode';

	function escapeHtml( text ) {
		return String( text ).replace( /[&<>"']/g, function ( character ) {
			return ESCAPES[ character ];
		} );
	}

	/**
	 * Only targets a browser can follow without running anything.
	 */
	function safeUrl( url ) {
		var trimmed = String( url ).trim();

		if ( /^(https?:\/\/|mailto:|tel:|\/|#|\.\/|\.\.\/)/i.test( trimmed ) ) {
			return trimmed;
		}

		return '';
	}

	/**
	 * Inline rules, applied to text that is already escaped.
	 *
	 * Code spans are lifted out first and put back at the end, so asterisks
	 * inside a backtick span are not also read as emphasis.
	 */
	function renderInline( text ) {
		var codeSpans = [];

		text = text.replace( /`([^`]+)`/g, function ( match, code ) {
			codeSpans.push( code );

			return CODE_MARKER + ( codeSpans.length - 1 ) + '@@';
		} );

		// An image has nothing to show here; its alt text is the informative part.
		text = text.replace( /!\[([^\]]*)\]\(([^)\s]+)[^)]*\)/g, '$1' );

		text = text.replace( /\[([^\]]+)\]\(([^)\s]+)[^)]*\)/g, function ( match, label, url ) {
			var href = safeUrl( url );

			if ( '' === href ) {
				return label;
			}

			return '<a href="' + escapeHtml( href ) + '" target="_blank" rel="noopener nofollow">' + label + '</a>';
		} );

		text = text.replace( /\*\*([^*]+)\*\*/g, '<strong>$1</strong>' );
		text = text.replace( /__([^_]+)__/g, '<strong>$1</strong>' );
		text = text.replace( /(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>' );
		text = text.replace( /~~([^~]+)~~/g, '<del>$1</del>' );

		return text.replace( new RegExp( CODE_MARKER + '(\\d+)@@', 'g' ), function ( match, index ) {
			return '<code>' + codeSpans[ Number( index ) ] + '</code>';
		} );
	}

	function closeList( state, out ) {
		if ( state.list ) {
			out.push( '</' + state.list + '>' );
			state.list = null;
		}
	}

	function closeParagraph( state, out ) {
		if ( state.paragraph.length ) {
			out.push( '<p>' + renderInline( state.paragraph.join( ' ' ) ) + '</p>' );
			state.paragraph = [];
		}
	}

	function closeQuote( state, out ) {
		if ( state.quote.length ) {
			out.push( '<blockquote>' + renderBlocks( state.quote.join( '\n' ), true ) + '</blockquote>' );
			state.quote = [];
		}
	}

	/**
	 * Renders a block of Markdown into HTML.
	 *
	 * @param {string}  markdown   The text to render.
	 * @param {boolean} preEscaped Whether the text has already been escaped,
	 *                             which it has when it comes from a quote.
	 */
	function renderBlocks( markdown, preEscaped ) {
		if ( ! markdown ) {
			return '';
		}

		var source = preEscaped ? String( markdown ) : escapeHtml( markdown );
		var lines = source.replace( /\r\n/g, '\n' ).split( '\n' );
		var out = [];
		var state = { list: null, paragraph: [], quote: [] };
		var inFence = false;
		var fence = [];
		var index;
		var line;
		var match;

		function flushBlocks() {
			closeParagraph( state, out );
			closeQuote( state, out );
			closeList( state, out );
		}

		for ( index = 0; index < lines.length; index++ ) {
			line = lines[ index ];

			if ( /^\s*```/.test( line ) ) {
				if ( inFence ) {
					out.push( '<pre><code>' + fence.join( '\n' ) + '</code></pre>' );
					fence = [];
					inFence = false;
				} else {
					flushBlocks();
					inFence = true;
				}

				continue;
			}

			if ( inFence ) {
				fence.push( line );
				continue;
			}

			if ( '' === line.trim() ) {
				flushBlocks();
				continue;
			}

			match = line.match( /^(#{1,6})\s+(.*)$/ );

			if ( match ) {
				flushBlocks();
				out.push( '<h' + match[ 1 ].length + '>' + renderInline( match[ 2 ].trim() ) + '</h' + match[ 1 ].length + '>' );
				continue;
			}

			if ( /^\s*(-{3,}|\*{3,}|_{3,})\s*$/.test( line ) ) {
				flushBlocks();
				out.push( '<hr>' );
				continue;
			}

			// The escape pass has already turned "> " into "&gt; ".
			match = line.match( /^\s*&gt;\s?(.*)$/ );

			if ( match ) {
				closeParagraph( state, out );
				closeList( state, out );
				state.quote.push( match[ 1 ] );
				continue;
			}

			closeQuote( state, out );

			match = line.match( /^\s*([-*+])\s+(.*)$/ );

			if ( match ) {
				closeParagraph( state, out );

				if ( 'ul' !== state.list ) {
					closeList( state, out );
					out.push( '<ul>' );
					state.list = 'ul';
				}

				out.push( '<li>' + renderInline( match[ 2 ] ) + '</li>' );
				continue;
			}

			match = line.match( /^\s*\d+[.)]\s+(.*)$/ );

			if ( match ) {
				closeParagraph( state, out );

				if ( 'ol' !== state.list ) {
					closeList( state, out );
					out.push( '<ol>' );
					state.list = 'ol';
				}

				out.push( '<li>' + renderInline( match[ 1 ] ) + '</li>' );
				continue;
			}

			// An indented line after a list item continues that item rather than
			// starting a paragraph that would break out of the list.
			if ( state.list && /^\s{2,}\S/.test( line ) && out.length ) {
				out[ out.length - 1 ] = out[ out.length - 1 ].replace( /<\/li>$/, ' ' + renderInline( line.trim() ) + '</li>' );
				continue;
			}

			closeList( state, out );
			state.paragraph.push( line.trim() );
		}

		if ( inFence && fence.length ) {
			out.push( '<pre><code>' + fence.join( '\n' ) + '</code></pre>' );
		}

		flushBlocks();

		return out.join( '' );
	}

	window.BlueBranchChatbotMarkdown = {
		render: function ( markdown ) {
			return renderBlocks( markdown, false );
		},
		escapeHtml: escapeHtml
	};
}( window ) );
