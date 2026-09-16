<?php
/**
 * Marks the page furniture while the plugin crawls its own site.
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps headers, footers, menus, sidebars and comments in a marker element.
 *
 * Working out which part of a rendered page is the content and which is the
 * furniture around it is guesswork from the outside, and guesswork that fails
 * differently on every theme. From the inside it is not a guess at all:
 * WordPress knows that a block is a header template part, that this markup
 * came from wp_nav_menu(), that those widgets are a sidebar.
 *
 * So the crawler asks for the page with a signed header, and a request
 * carrying that header gets the furniture wrapped in
 * `<div data-bbchat-chrome="1">`. Page_Extractor then removes exactly those
 * nodes instead of pattern-matching for them.
 *
 * Nothing depends on this working. A proxy that drops the header, a cache that
 * answers before WordPress runs -- the marks are simply absent and the
 * extractor falls back to its selectors. It is an improvement, never a
 * requirement.
 */
class Crawl_Marker {

	/**
	 * Request header carrying the crawl token.
	 */
	const HEADER = 'X-BlueBranch-Chatbot-Crawl';

	/**
	 * Attribute the furniture is marked with.
	 */
	const ATTRIBUTE = 'data-bbchat-chrome';

	/**
	 * How long a crawl token stays valid. Short: it is issued and used within
	 * the same run, seconds apart.
	 */
	const LIFETIME = 300;

	/**
	 * Hooks the marker into WordPress.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'maybe_enable' ), 1 );
	}

	/**
	 * Hands out a token for one crawl run.
	 *
	 * @return string
	 */
	public static function issue_token() {
		$expires = time() + self::LIFETIME;

		return $expires . '.' . self::signature( $expires );
	}

	/**
	 * Whether a token was issued by this site and is still valid.
	 *
	 * @param mixed $token What the request carried.
	 * @return bool
	 */
	public static function verify_token( $token ) {
		if ( ! is_string( $token ) || ! preg_match( '/^(\d{1,12})\.([a-f0-9]{64})$/', $token, $matches ) ) {
			return false;
		}

		if ( (int) $matches[1] < time() ) {
			return false;
		}

		return hash_equals( self::signature( (int) $matches[1] ), $matches[2] );
	}

	/**
	 * Signs an expiry with the site's own secret.
	 *
	 * @param int $expires Unix timestamp the token stops being valid at.
	 * @return string
	 */
	private static function signature( $expires ) {
		return hash_hmac( 'sha256', 'bluebranch_chatbot_crawl|' . $expires, wp_salt( 'nonce' ) );
	}

	/**
	 * Whether this request is a crawl by this plugin.
	 *
	 * @return bool
	 */
	public static function is_crawl_request() {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', self::HEADER ) );

		if ( ! isset( $_SERVER[ $key ] ) ) {
			return false;
		}

		return self::verify_token( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) );
	}

	/**
	 * Registers the marking filters, but only for a crawl request.
	 *
	 * @return void
	 */
	public function maybe_enable() {
		if ( ! self::is_crawl_request() ) {
			return;
		}

		/*
		 * A cached copy would be a page rendered without the marks, and a
		 * cache that stored this one would serve the marks to real visitors.
		 * Both are avoided by the constant every major caching plugin honours.
		 */
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The name belongs to the caching plugins that honour it; a prefixed one would mean nothing to them.
			define( 'DONOTCACHEPAGE', true );
		}

		add_filter( 'show_admin_bar', '__return_false' );

		add_filter( 'render_block', array( $this, 'mark_block' ), 99, 2 );
		add_filter( 'wp_nav_menu', array( $this, 'mark' ), 99 );

		add_action( 'dynamic_sidebar_before', array( $this, 'open_mark' ), 0 );
		add_action( 'dynamic_sidebar_after', array( $this, 'close_mark' ), 99 );
	}

	/**
	 * Marks the blocks that make up the furniture of a block theme.
	 *
	 * Template parts are checked by area rather than by name: a theme is free
	 * to keep a content section in a template part, and removing that would
	 * take real content with it. Only the parts WordPress itself files under
	 * "header" or "footer" are furniture.
	 *
	 * @param string $block_content Rendered block markup.
	 * @param array  $block         The parsed block.
	 * @return string
	 */
	public function mark_block( $block_content, $block ) {
		if ( '' === trim( (string) $block_content ) ) {
			return $block_content;
		}

		$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

		$always = array(
			'core/navigation',
			'core/comments',
			'core/post-comments-form',
			'core/comment-template',
		);

		if ( in_array( $name, $always, true ) ) {
			return $this->mark( $block_content );
		}

		if ( 'core/template-part' !== $name ) {
			return $block_content;
		}

		$area = isset( $block['attrs']['area'] ) ? (string) $block['attrs']['area'] : '';
		$slug = isset( $block['attrs']['slug'] ) ? (string) $block['attrs']['slug'] : '';

		if ( in_array( $area, array( 'header', 'footer' ), true ) ) {
			return $this->mark( $block_content );
		}

		// Older block themes leave the area unset and say it in the slug.
		if ( '' === $area && preg_match( '/(^|-)(header|footer)($|-)/', $slug ) ) {
			return $this->mark( $block_content );
		}

		return $block_content;
	}

	/**
	 * Wraps markup in the marker element.
	 *
	 * @param string $html Markup to mark.
	 * @return string
	 */
	public function mark( $html ) {
		return '<div ' . self::ATTRIBUTE . '="1">' . $html . '</div>';
	}

	/**
	 * Opens a marker around a sidebar.
	 *
	 * @return void
	 */
	public function open_mark() {
		echo '<div ' . esc_attr( self::ATTRIBUTE ) . '="1">';
	}

	/**
	 * Closes the marker around a sidebar.
	 *
	 * @return void
	 */
	public function close_mark() {
		echo '</div>';
	}
}
