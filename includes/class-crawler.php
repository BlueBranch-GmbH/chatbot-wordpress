<?php
/**
 * Fetches the site's own pages the way a visitor would.
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Requests a URL over HTTP and hands back the rendered page.
 *
 * Rendering post_content through the_content gets what the editor typed.
 * Crawling gets what the reader sees -- which on a real site is not the same
 * thing. Fields rendered by the template, sections a page builder keeps
 * outside post_content, a block theme's post-title and post-meta blocks: none
 * of that is in post_content, and all of it is on the page.
 *
 * The catch is that a server is not always allowed to call itself. Loopback
 * requests are blocked often enough that WordPress ships a Site Health check
 * for it, so nothing here is fatal: a failed crawl is reported and the caller
 * falls back to rendering.
 */
class Crawler {

	/**
	 * How long to wait for one page.
	 */
	const TIMEOUT = 25;

	/**
	 * How this crawler identifies itself in the access log.
	 *
	 * @return string
	 */
	public static function user_agent() {
		return 'BlueBranch-Chatbot-Crawler/' . VERSION . '; ' . home_url( '/' );
	}

	/**
	 * Fetches one page.
	 *
	 * @param string $url Absolute URL on this site.
	 * @return string|WP_Error The page's HTML.
	 */
	public function fetch( $url ) {
		$url = esc_url_raw( (string) $url );

		if ( '' === $url || ! $this->is_same_site( $url ) ) {
			return new WP_Error(
				'bluebranch_chatbot_foreign_url',
				__( 'Only addresses on this site can be crawled.', 'bluebranch-chatbot' )
			);
		}

		/**
		 * Filters the address the crawler actually requests.
		 *
		 * Behind a reverse proxy, or on a host whose firewall refuses a
		 * loopback call to its own public name, the page may only be reachable
		 * under a different address than the one it is published at.
		 *
		 * @param string $request_url What will be requested.
		 * @param string $url         The public address of the page.
		 */
		$request_url = (string) apply_filters( 'bluebranch_chatbot_crawl_url', $url, $url );

		/*
		 * reject_unsafe_urls is the important one here. The address handed in
		 * was checked against this site's host, but a redirect is not: three
		 * hops later a request can be somewhere else entirely, and on a cloud
		 * host "somewhere else" includes the metadata service. It hands every
		 * hop to wp_http_validate_url(), which refuses private and loopback
		 * addresses -- while still allowing this site's own host, so a server
		 * that reaches itself over a private address keeps working.
		 */
		$response = wp_remote_get(
			$request_url,
			array(
				'timeout'            => self::TIMEOUT,
				'redirection'        => 3,
				'reject_unsafe_urls' => true,
				'user-agent'         => self::user_agent(),
				'headers'            => array(
					'Accept'             => 'text/html',
					// Tells this site to mark its own furniture; see Crawl_Marker.
					Crawl_Marker::HEADER => Crawl_Marker::issue_token(),
					'Cache-Control'      => 'no-cache',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'bluebranch_chatbot_crawl_failed',
				sprintf(
					/* translators: 1: URL, 2: error message. */
					__( '%1$s could not be fetched: %2$s', 'bluebranch-chatbot' ),
					$url,
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status ) {
			return new WP_Error(
				'bluebranch_chatbot_crawl_status',
				sprintf(
					/* translators: 1: URL, 2: HTTP status code. */
					__( '%1$s answered with HTTP %2$d.', 'bluebranch-chatbot' ),
					$url,
					$status
				)
			);
		}

		$type = (string) wp_remote_retrieve_header( $response, 'content-type' );

		if ( '' !== $type && false === strpos( strtolower( $type ), 'text/html' ) ) {
			return new WP_Error(
				'bluebranch_chatbot_crawl_type',
				sprintf(
					/* translators: 1: URL, 2: content type. */
					__( '%1$s is not an HTML page (%2$s).', 'bluebranch-chatbot' ),
					$url,
					$type
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === trim( $body ) ) {
			return new WP_Error(
				'bluebranch_chatbot_crawl_empty',
				sprintf(
					/* translators: %s: URL. */
					__( '%s came back empty.', 'bluebranch-chatbot' ),
					$url
				)
			);
		}

		return $body;
	}

	/**
	 * Whether the loopback request works at all.
	 *
	 * Asked once before a run rather than discovered one failure at a time, so
	 * a blocked loopback reads as one clear message instead of as every page
	 * failing for its own reason.
	 *
	 * @return true|WP_Error
	 */
	public function check_loopback() {
		$result = $this->fetch( home_url( '/' ) );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Whether a URL belongs to this site.
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
