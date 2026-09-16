<?php
/**
 * Turns rendered post markup into Markdown.
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

use DOMDocument;
use DOMNode;
use DOMText;

defined( 'ABSPATH' ) || exit;

/**
 * A small, dependency-free HTML to Markdown converter.
 *
 * The Contao version of this extension uses league/html-to-markdown. Pulling a
 * Composer library into a WordPress plugin means shipping a vendor directory
 * that every installation carries and that can collide with the same library
 * bundled by another plugin. The knowledge base only ever sees prose, so the
 * subset handled here -- headings, lists, links, emphasis, quotes, code and
 * simple tables -- is the whole of what is needed.
 */
class Html_To_Markdown {

	/**
	 * Elements whose content is thrown away entirely.
	 *
	 * @var string[]
	 */
	private $dropped = array(
		'script',
		'style',
		'noscript',
		'iframe',
		'object',
		'embed',
		'svg',
		'canvas',
		'img',
		'picture',
		'video',
		'audio',
		'source',
		'form',
		'input',
		'select',
		'textarea',
		'button',
		'template',
	);

	/**
	 * How deep the current list nesting is.
	 *
	 * @var int
	 */
	private $list_depth = 0;

	/**
	 * Converts a fragment of HTML.
	 *
	 * @param string $html Rendered markup.
	 * @return string Markdown, or plain text when libxml is unavailable.
	 */
	public function convert( $html ) {
		$html = trim( (string) $html );

		if ( '' === $html ) {
			return '';
		}

		if ( ! class_exists( 'DOMDocument' ) ) {
			return $this->collapse_blank_lines( wp_strip_all_tags( $html ) );
		}

		$document = new DOMDocument();

		$previous_state = libxml_use_internal_errors( true );

		/*
		 * The XML declaration pins the encoding: without it libxml assumes
		 * ISO-8859-1 and every umlaut in a German site comes out broken.
		 * LIBXML_NONET keeps the parser from fetching anything over the network.
		 */
		$document->loadHTML(
			'<?xml encoding="utf-8" ?><html><body>' . $html . '</body></html>',
			LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous_state );

		$body = $document->getElementsByTagName( 'body' )->item( 0 );

		if ( ! $body instanceof DOMNode ) {
			return $this->collapse_blank_lines( wp_strip_all_tags( $html ) );
		}

		$this->list_depth = 0;

		return $this->collapse_blank_lines( $this->render_children( $body ) );
	}

	/**
	 * Renders every child of a node.
	 *
	 * @param DOMNode $node Parent node.
	 * @return string
	 */
	private function render_children( DOMNode $node ) {
		$out = '';

		foreach ( $node->childNodes as $child ) {
			$out .= $this->render( $child );
		}

		return $out;
	}

	/**
	 * Renders one node.
	 *
	 * @param DOMNode $node Node to render.
	 * @return string
	 */
	private function render( DOMNode $node ) {
		if ( $node instanceof DOMText ) {
			return preg_replace( '/\s+/u', ' ', $node->textContent );
		}

		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return '';
		}

		$tag = strtolower( $node->nodeName );

		if ( in_array( $tag, $this->dropped, true ) ) {
			return '';
		}

		switch ( $tag ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$level = (int) substr( $tag, 1 );

				return "\n\n" . str_repeat( '#', $level ) . ' ' . trim( $this->render_children( $node ) ) . "\n\n";

			case 'br':
				return "\n";

			case 'hr':
				return "\n\n---\n\n";

			case 'strong':
			case 'b':
				return $this->wrap( $this->render_children( $node ), '**' );

			case 'em':
			case 'i':
				return $this->wrap( $this->render_children( $node ), '*' );

			case 'del':
			case 's':
				return $this->wrap( $this->render_children( $node ), '~~' );

			case 'code':
				return $this->wrap( $this->render_children( $node ), '`' );

			case 'pre':
				return "\n\n```\n" . trim( $node->textContent ) . "\n```\n\n";

			case 'a':
				return $this->render_link( $node );

			case 'ul':
			case 'ol':
				return $this->render_list( $node, $tag );

			case 'li':
				// Reached only for a stray <li> outside a list; treat it as a line.
				return "\n" . trim( $this->render_children( $node ) );

			case 'blockquote':
				return $this->render_blockquote( $node );

			case 'table':
				return $this->render_table( $node );

			case 'p':
			case 'div':
			case 'section':
			case 'article':
			case 'header':
			case 'footer':
			case 'main':
			case 'aside':
			case 'nav':
			case 'figure':
			case 'figcaption':
			case 'dl':
			case 'dt':
			case 'dd':
			case 'address':
				return "\n\n" . trim( $this->render_children( $node ) ) . "\n\n";
		}

		return $this->render_children( $node );
	}

	/**
	 * Puts Markdown markers around text without swallowing its spacing.
	 *
	 * Leading and trailing spaces have to stay outside the markers, otherwise
	 * "** bold **" fails to render and the marker characters end up visible.
	 *
	 * @param string $text   Inner text.
	 * @param string $marker Markdown marker.
	 * @return string
	 */
	private function wrap( $text, $marker ) {
		$trimmed = trim( $text );

		if ( '' === $trimmed ) {
			return '';
		}

		$lead  = ( ltrim( $text ) !== $text ) ? ' ' : '';
		$trail = ( rtrim( $text ) !== $text ) ? ' ' : '';

		return $lead . $marker . $trimmed . $marker . $trail;
	}

	/**
	 * Renders a link, dropping the target when it adds nothing.
	 *
	 * @param DOMNode $node The anchor.
	 * @return string
	 */
	private function render_link( DOMNode $node ) {
		$text = trim( $this->render_children( $node ) );
		$href = $node instanceof \DOMElement ? trim( $node->getAttribute( 'href' ) ) : '';

		if ( '' === $text ) {
			return '';
		}

		// In-page and script targets carry no information for a knowledge base.
		if ( '' === $href || str_starts_with( $href, '#' ) || str_starts_with( $href, 'javascript:' ) ) {
			return $text;
		}

		return '[' . $text . '](' . $href . ')';
	}

	/**
	 * Renders a list, indenting nested levels.
	 *
	 * @param DOMNode $node List element.
	 * @param string  $tag  Either ul or ol.
	 * @return string
	 */
	private function render_list( DOMNode $node, $tag ) {
		$indent = str_repeat( '  ', $this->list_depth );
		$out    = '';
		$number = 1;

		++$this->list_depth;

		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType || 'li' !== strtolower( $child->nodeName ) ) {
				continue;
			}

			$marker = 'ol' === $tag ? $number . '. ' : '- ';
			$body   = trim( $this->render_children( $child ) );

			if ( '' === $body ) {
				continue;
			}

			// A nested list arrives with its own newlines; the first line carries
			// the marker, the rest are already indented by the deeper level.
			$lines = explode( "\n", $body );
			$first = array_shift( $lines );

			$out .= $indent . $marker . $first . "\n";

			foreach ( $lines as $line ) {
				$out .= '' === trim( $line ) ? '' : $line . "\n";
			}

			++$number;
		}

		--$this->list_depth;

		return "\n\n" . rtrim( $out ) . "\n\n";
	}

	/**
	 * Renders a quote, prefixing every one of its lines.
	 *
	 * @param DOMNode $node The blockquote.
	 * @return string
	 */
	private function render_blockquote( DOMNode $node ) {
		$inner = trim( $this->collapse_blank_lines( $this->render_children( $node ) ) );

		if ( '' === $inner ) {
			return '';
		}

		$lines = array_map(
			static function ( $line ) {
				return '' === trim( $line ) ? '>' : '> ' . trim( $line );
			},
			explode( "\n", $inner )
		);

		return "\n\n" . implode( "\n", $lines ) . "\n\n";
	}

	/**
	 * Renders a table as a Markdown table.
	 *
	 * @param DOMNode $node The table.
	 * @return string
	 */
	private function render_table( DOMNode $node ) {
		if ( ! $node instanceof \DOMElement ) {
			return '';
		}

		$rows = array();

		foreach ( $node->getElementsByTagName( 'tr' ) as $row ) {
			$cells = array();

			foreach ( $row->childNodes as $cell ) {
				if ( XML_ELEMENT_NODE !== $cell->nodeType ) {
					continue;
				}

				if ( ! in_array( strtolower( $cell->nodeName ), array( 'td', 'th' ), true ) ) {
					continue;
				}

				// A pipe inside a cell would split it into two columns.
				$cells[] = str_replace( '|', '\\|', trim( preg_replace( '/\s+/u', ' ', $cell->textContent ) ) );
			}

			if ( array() !== $cells ) {
				$rows[] = $cells;
			}
		}

		if ( array() === $rows ) {
			return '';
		}

		$columns = max( array_map( 'count', $rows ) );
		$out     = '';

		foreach ( $rows as $index => $cells ) {
			$cells = array_pad( $cells, $columns, '' );
			$out  .= '| ' . implode( ' | ', $cells ) . " |\n";

			if ( 0 === $index ) {
				$out .= '| ' . implode( ' | ', array_fill( 0, $columns, '---' ) ) . " |\n";
			}
		}

		return "\n\n" . trim( $out ) . "\n\n";
	}

	/**
	 * Squeezes runs of blank lines and trailing spaces out of the result.
	 *
	 * @param string $text Markdown.
	 * @return string
	 */
	private function collapse_blank_lines( $text ) {
		$text = str_replace( "\r\n", "\n", $text );
		$text = preg_replace( '/[ \t]+\n/', "\n", $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );

		/*
		 * Runs of spaces are squeezed only where they follow visible text.
		 * Matching them at the start of a line as well would flatten the
		 * indentation that tells a nested list item from a top-level one.
		 */
		$text = preg_replace( '/(\S)[ \t]{2,}/', '$1 ', $text );

		return trim( $text );
	}
}
