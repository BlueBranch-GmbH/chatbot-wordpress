<?php
/**
 * Reduces a crawled page to the part worth training.
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

defined( 'ABSPATH' ) || exit;

/**
 * Takes the HTML of a rendered page and gives back its content.
 *
 * Three passes, each catching what the one before it cannot:
 *
 * 1. The marks Crawl_Marker left during the crawl. Exact, because WordPress
 *    itself said which block was a header. Absent when a proxy dropped the
 *    header or a cache answered first.
 * 2. Structure. A `<main>` narrows the page down in one step; failing that,
 *    the elements that are furniture by role or by tag are removed. Note that
 *    a `<header>` inside an `<article>` is left alone -- that is the entry
 *    header, and it holds the title.
 * 3. Repetition. Anything that appeared on most of the sampled pages is
 *    furniture the first two passes did not recognise: a cookie notice, a
 *    breadcrumb bar, a call to action repeated in every footer.
 *
 * The third pass is the one with teeth, and the one that could do damage, so
 * it gives up if it would take most of the page with it.
 */
class Page_Extractor {

	/**
	 * How much text a block needs before it can count as repeated furniture.
	 */
	const MIN_BLOCK_LENGTH = 40;

	/**
	 * How much text the repetition pass has to leave behind.
	 *
	 * The pass removes blocks one at a time, most-repeated first, and stops
	 * when the next one would take the page below this. A page whose content
	 * genuinely is the thing repeated elsewhere -- a contact page whose address
	 * also stands in the footer -- therefore keeps it, and an empty page never
	 * gets trained as an empty answer.
	 *
	 * Stopping rather than giving up entirely matters: on a small site nearly
	 * everything repeats, and an all-or-nothing rule would remove nothing at
	 * all on exactly the sites with the least content to spare.
	 */
	const MIN_SURVIVING_LENGTH = 200;

	/**
	 * Elements thrown away before anything else is looked at.
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
		'form',
		'input',
		'select',
		'textarea',
		'button',
		'template',
		'audio',
		'video',
		'source',
		'dialog',
		'link',
		'meta',
	);

	/**
	 * Tags that can carry a block of text worth fingerprinting.
	 *
	 * @var string[]
	 */
	private $structural = array(
		'div',
		'section',
		'article',
		'aside',
		'nav',
		'header',
		'footer',
		'ul',
		'ol',
		'dl',
		'table',
		'p',
		'h1',
		'h2',
		'h3',
		'h4',
		'h5',
		'h6',
		'figure',
		'address',
		'blockquote',
	);

	/**
	 * Returns the content of a page as HTML.
	 *
	 * @param string $html         The crawled page.
	 * @param array  $fingerprints Hashes of blocks known to be furniture.
	 * @return string
	 */
	public function extract( $html, array $fingerprints = array() ) {
		$root = $this->clean( $html );

		if ( null === $root ) {
			return '';
		}

		if ( array() !== $fingerprints ) {
			$this->remove_repeated( $root, $fingerprints );
		}

		return $this->inner_html( $root );
	}

	/**
	 * Returns the hashes of every text block on a page.
	 *
	 * Taken after the first two passes, so what is counted is the furniture
	 * those passes did not recognise -- which is the only kind repetition can
	 * still say anything about.
	 *
	 * @param string $html The crawled page.
	 * @return array<string, true>
	 */
	public function fingerprints( $html ) {
		$root = $this->clean( $html );

		if ( null === $root ) {
			return array();
		}

		$hashes = array();

		$this->walk(
			$root,
			function ( $hash ) use ( &$hashes ) {
				$hashes[ $hash ] = true;

				return false;
			}
		);

		return $hashes;
	}

	/**
	 * Works out which blocks are furniture by seeing them on most pages.
	 *
	 * The share each block reached is kept, not just the fact that it passed.
	 * It is what lets removal start with the blocks it is surest about and
	 * stop before it runs out of page.
	 *
	 * @param array[] $samples   One fingerprint set per sampled page.
	 * @param float   $threshold Share of pages a block must appear on.
	 * @return array<string, float> Hash to the share of pages it appeared on.
	 */
	public static function learn_boilerplate( array $samples, $threshold = 0.6 ) {
		$total = count( $samples );

		/*
		 * Two pages are not evidence of anything: on a site with a handful of
		 * URLs, two of them sharing a paragraph is as likely to be the content
		 * as the furniture.
		 */
		if ( $total < 3 ) {
			return array();
		}

		$counts = array();

		foreach ( $samples as $hashes ) {
			foreach ( array_keys( $hashes ) as $hash ) {
				$counts[ $hash ] = isset( $counts[ $hash ] ) ? $counts[ $hash ] + 1 : 1;
			}
		}

		$needed = max( 2, (int) ceil( $total * $threshold ) );
		$found  = array();

		foreach ( $counts as $hash => $count ) {
			if ( $count >= $needed ) {
				$found[ $hash ] = $count / $total;
			}
		}

		return $found;
	}

	/**
	 * Parses the page and runs the first two passes over it.
	 *
	 * @param string $html The crawled page.
	 * @return DOMNode|null The node holding the content, or null.
	 */
	private function clean( $html ) {
		$html = trim( (string) $html );

		if ( '' === $html || ! class_exists( 'DOMDocument' ) ) {
			return null;
		}

		$document = new DOMDocument();

		$previous_state = libxml_use_internal_errors( true );

		$document->loadHTML(
			'<?xml encoding="utf-8" ?>' . $html,
			LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous_state );

		$xpath = new DOMXPath( $document );

		$this->remove( $xpath, $this->dropped_query() );
		$this->remove( $xpath, $this->chrome_query() );

		$root = $this->main_region( $xpath );

		if ( null === $root ) {
			$body = $document->getElementsByTagName( 'body' )->item( 0 );
			$root = $body instanceof DOMNode ? $body : null;
		}

		return $root;
	}

	/**
	 * XPath for the elements removed before anything is looked at.
	 *
	 * @return string
	 */
	private function dropped_query() {
		return '//' . implode( '|//', $this->dropped );
	}

	/**
	 * XPath for the furniture.
	 *
	 * @return string
	 */
	private function chrome_query() {
		$class = static function ( $name ) {
			return '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $name . ' ")]';
		};

		$queries = array(
			// The marks Crawl_Marker left, when the crawl got through with them.
			'//*[@' . Crawl_Marker::ATTRIBUTE . ']',

			/*
			 * A <header> or <footer> that is not inside an article or a main.
			 * The exclusion is the important half: <header> is also where a
			 * theme puts the entry header, and that is where the title lives.
			 */
			'//header[not(ancestor::article) and not(ancestor::main)]',
			'//footer[not(ancestor::article) and not(ancestor::main)]',

			'//nav',
			'//aside',

			'//*[@role="banner" or @role="contentinfo" or @role="navigation" or @role="complementary" or @role="search" or @role="dialog"]',
			'//*[@id="wpadminbar"]',
			'//*[@id="comments"]',
			'//*[@aria-hidden="true"]',
		);

		foreach ( array(
			'wp-block-template-part',
			'wp-block-post-comments',
			'comments-area',
			'screen-reader-text',
			'skip-link',
			'site-header',
			'site-footer',
			'site-branding',
			'widget-area',
			'chatbot-widget',
			'chatbot-ask-container',
			'chatbot-generate-search-container',
		) as $name ) {
			$queries[] = $class( $name );
		}

		return implode( '|', $queries );
	}

	/**
	 * The element that holds the content, if the page says which one it is.
	 *
	 * @param DOMXPath $xpath Query object for the document.
	 * @return DOMNode|null
	 */
	private function main_region( DOMXPath $xpath ) {
		foreach ( array( '//main', '//*[@role="main"]', '//article' ) as $query ) {
			$found = $xpath->query( $query );

			if ( $found instanceof \DOMNodeList && $found->length > 0 ) {
				return $found->item( 0 );
			}
		}

		return null;
	}

	/**
	 * Removes everything an XPath query matches.
	 *
	 * @param DOMXPath $xpath Query object for the document.
	 * @param string   $query The query.
	 * @return void
	 */
	private function remove( DOMXPath $xpath, $query ) {
		$found = $xpath->query( $query );

		if ( ! $found instanceof \DOMNodeList ) {
			return;
		}

		// Collected first: removing while iterating a live list skips entries.
		$nodes = array();

		foreach ( $found as $node ) {
			$nodes[] = $node;
		}

		foreach ( $nodes as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}
	}

	/**
	 * Drops the blocks that were seen on most of the sampled pages.
	 *
	 * @param DOMNode $root         Node holding the content.
	 * @param array   $fingerprints Hash to the share of pages it appeared on.
	 * @return void
	 */
	private function remove_repeated( DOMNode $root, array $fingerprints ) {
		$remaining = $this->text_length( $root );

		if ( 0 === $remaining ) {
			return;
		}

		$candidates = array();

		$this->walk(
			$root,
			function ( $hash, $element ) use ( $fingerprints, &$candidates ) {
				if ( ! isset( $fingerprints[ $hash ] ) ) {
					return false;
				}

				$candidates[] = array(
					'element' => $element,
					'share'   => (float) $fingerprints[ $hash ],
					'length'  => $this->text_length( $element ),
				);

				// The children of a doomed block go with it; there is nothing
				// to learn by walking into them.
				return true;
			}
		);

		if ( array() === $candidates ) {
			return;
		}

		/*
		 * Surest first. A block standing on every single page is furniture
		 * beyond doubt; one that made the threshold by a hair is a guess, and
		 * a guess should be the first thing dropped when the budget runs out.
		 */
		usort(
			$candidates,
			static function ( $first, $second ) {
				if ( $first['share'] === $second['share'] ) {
					return $second['length'] - $first['length'];
				}

				return $first['share'] < $second['share'] ? 1 : -1;
			}
		);

		$removed = 0;

		foreach ( $candidates as $candidate ) {
			if ( ( $remaining - $candidate['length'] ) < self::MIN_SURVIVING_LENGTH ) {
				continue;
			}

			if ( ! $candidate['element']->parentNode ) {
				continue;
			}

			$candidate['element']->parentNode->removeChild( $candidate['element'] );

			$remaining -= $candidate['length'];
			++$removed;
		}

		Logger::debug(
			sprintf(
				'Repeated-block removal: %d of %d candidates dropped, %d characters left.',
				$removed,
				count( $candidates ),
				$remaining
			)
		);
	}

	/**
	 * Visits every structural element that carries enough text.
	 *
	 * @param DOMNode  $node    Where to start.
	 * @param callable $visitor Receives the hash and the element; returning
	 *                          true stops the walk descending into it.
	 * @return void
	 */
	private function walk( DOMNode $node, callable $visitor ) {
		if ( ! $node->hasChildNodes() ) {
			return;
		}

		// A snapshot, because the visitor may remove what it is handed.
		$children = array();

		foreach ( $node->childNodes as $child ) {
			$children[] = $child;
		}

		foreach ( $children as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			if ( ! in_array( strtolower( $child->nodeName ), $this->structural, true ) ) {
				$this->walk( $child, $visitor );

				continue;
			}

			$text = $this->normalise( $child->textContent );

			if ( strlen( $text ) < self::MIN_BLOCK_LENGTH ) {
				$this->walk( $child, $visitor );

				continue;
			}

			if ( true === $visitor( md5( $text ), $child ) ) {
				continue;
			}

			$this->walk( $child, $visitor );
		}
	}

	/**
	 * Reduces text to something two pages can be compared on.
	 *
	 * @param string $text Raw text content.
	 * @return string
	 */
	private function normalise( $text ) {
		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		$text = trim( (string) $text );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * How much text a node holds.
	 *
	 * @param DOMNode $node The node.
	 * @return int
	 */
	private function text_length( DOMNode $node ) {
		return strlen( $this->normalise( $node->textContent ) );
	}

	/**
	 * Serialises the children of a node back to HTML.
	 *
	 * @param DOMNode $node The node.
	 * @return string
	 */
	private function inner_html( DOMNode $node ) {
		$html = '';

		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}

		return trim( $html );
	}
}
