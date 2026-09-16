<?php
/**
 * Finds out which URLs the site says it has.
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

use DOMDocument;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the site's XML sitemaps and returns the URLs in them.
 *
 * The sitemap is the site's own answer to "what is worth finding here", kept
 * by whichever plugin the operator already trusts with it. Walking the post
 * tables instead would mean re-deciding that question, and deciding it
 * differently from the sitemap the search engines are given.
 *
 * It is also a boundary rather than a starting point: what is listed gets
 * crawled, and nothing else. No links are followed out of the pages, so the
 * crawl cannot wander into a filter combination or a calendar that generates
 * URLs for ever.
 */
class Sitemap {

	/**
	 * How many sitemap files one run will read.
	 */
	const MAX_SITEMAPS = 50;

	/**
	 * How many URLs one run will collect.
	 */
	const MAX_URLS = 10000;

	/**
	 * Returns every URL the sitemaps list.
	 *
	 * @return string[]|\WP_Error
	 */
	public function urls() {
		$entry_points = $this->entry_points();

		if ( array() === $entry_points ) {
			return new \WP_Error(
				'bluebranch_chatbot_no_sitemap',
				__( 'No XML sitemap was found. Enter its address in the settings, or switch training to rendered content.', 'bluebranch-chatbot' )
			);
		}

		$urls      = array();
		$seen      = array();
		$queue     = $entry_points;
		$processed = 0;

		while ( array() !== $queue && $processed < self::MAX_SITEMAPS ) {
			$sitemap_url = array_shift( $queue );

			if ( isset( $seen[ $sitemap_url ] ) ) {
				continue;
			}

			$seen[ $sitemap_url ] = true;
			++$processed;

			$document = $this->load( $sitemap_url );

			if ( null === $document ) {
				continue;
			}

			// A sitemap index points at more sitemaps; anything else lists URLs.
			if ( $document->getElementsByTagNameNS( '*', 'sitemapindex' )->length > 0 ) {
				foreach ( $this->locations( $document ) as $nested ) {
					$queue[] = $nested;
				}

				continue;
			}

			foreach ( $this->locations( $document ) as $url ) {
				if ( count( $urls ) >= self::MAX_URLS ) {
					break 2;
				}

				$urls[ $url ] = true;
			}
		}

		$urls = array_keys( $urls );

		/**
		 * Filters the URLs discovered from the sitemaps.
		 *
		 * @param string[] $urls Absolute URLs.
		 */
		return (array) apply_filters( 'bluebranch_chatbot_sitemap_urls', $urls );
	}

	/**
	 * Where to start looking.
	 *
	 * @return string[]
	 */
	public function entry_points() {
		$configured = trim( (string) Options::get( 'sitemap_url' ) );

		if ( '' !== $configured ) {
			return array( $configured );
		}

		/*
		 * robots.txt first, because every SEO plugin announces its sitemap
		 * there under whatever name it chose. Guessing file names finds the
		 * two or three arrangements somebody thought of; robots.txt finds the
		 * one this site actually uses.
		 */
		$from_robots = $this->sitemaps_from_robots();

		if ( array() !== $from_robots ) {
			return $from_robots;
		}

		$candidates = array(
			home_url( '/wp-sitemap.xml' ),
			home_url( '/sitemap_index.xml' ),
			home_url( '/sitemap.xml' ),
		);

		foreach ( $candidates as $candidate ) {
			if ( null !== $this->load( $candidate ) ) {
				return array( $candidate );
			}
		}

		return array();
	}

	/**
	 * Reads the Sitemap lines out of robots.txt.
	 *
	 * @return string[]
	 */
	private function sitemaps_from_robots() {
		$response = wp_remote_get(
			home_url( '/robots.txt' ),
			array(
				'timeout'            => 15,
				'user-agent'         => Crawler::user_agent(),
				'reject_unsafe_urls' => true,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$found = array();

		if ( preg_match_all( '/^\s*sitemap\s*:\s*(\S+)/im', wp_remote_retrieve_body( $response ), $matches ) ) {
			foreach ( $matches[1] as $url ) {
				$url = esc_url_raw( trim( $url ) );

				if ( '' !== $url && $this->is_same_site( $url ) ) {
					$found[] = $url;
				}
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Fetches and parses one sitemap file.
	 *
	 * @param string $url Sitemap address.
	 * @return DOMDocument|null
	 */
	private function load( $url ) {
		if ( ! $this->is_same_site( $url ) ) {
			return null;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'            => 20,
				'user-agent'         => Crawler::user_agent(),
				'headers'            => array( 'Accept' => 'application/xml, text/xml' ),
				// A sitemap is a file like any other and may redirect; see Crawler.
				'reject_unsafe_urls' => true,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );

		// Some sitemaps are served as a .gz file rather than a compressed
		// response, so the HTTP layer hands them over still packed.
		if ( 0 === strpos( $body, "\x1f\x8b" ) && function_exists( 'gzdecode' ) ) {
			$unpacked = gzdecode( $body );
			$body     = false === $unpacked ? $body : $unpacked;
		}

		if ( '' === trim( $body ) || false === strpos( $body, '<' ) ) {
			return null;
		}

		$document = new DOMDocument();

		$previous_state = libxml_use_internal_errors( true );

		/*
		 * LIBXML_NONET stops the parser fetching anything named in the
		 * document, and entities are left unexpanded because LIBXML_NOENT is
		 * not passed -- between them that is the external entity attack.
		 */
		$loaded = $document->loadXML( $body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous_state );

		return $loaded ? $document : null;
	}

	/**
	 * Every <loc> in a sitemap, whatever namespace it wears.
	 *
	 * @param DOMDocument $document Parsed sitemap.
	 * @return string[]
	 */
	private function locations( DOMDocument $document ) {
		$found = array();

		foreach ( $document->getElementsByTagNameNS( '*', 'loc' ) as $node ) {
			$url = esc_url_raw( trim( $node->textContent ) );

			if ( '' !== $url && $this->is_same_site( $url ) ) {
				$found[] = $url;
			}
		}

		return $found;
	}

	/**
	 * Whether a URL belongs to this site.
	 *
	 * A sitemap is a file like any other and can name any address it likes.
	 * Following one off-site would turn this plugin into something that
	 * fetches arbitrary URLs on request, which is a different and much less
	 * welcome thing to have on a server.
	 *
	 * @param string $url The URL.
	 * @return bool
	 */
	private function is_same_site( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$home = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		return is_string( $host ) && is_string( $home ) && strtolower( $host ) === strtolower( $home );
	}
}
