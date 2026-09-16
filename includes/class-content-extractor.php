<?php
/**
 * Turns a post into the payload the knowledge base expects.
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a post the way a visitor would see it and reduces it to prose.
 *
 * Contao hands its extension the finished HTML of a crawled page. WordPress
 * has no crawler, so the markup is produced here by running the content
 * through the_content -- the same filter the theme uses, which is what makes
 * shortcodes, blocks and page builders come out as the reader sees them.
 */
class Content_Extractor {

	/**
	 * Guards against re-entering the render while a render is running.
	 *
	 * A plugin hooked into the_content that triggers a save would otherwise
	 * send this method through itself until the stack runs out.
	 *
	 * @var bool
	 */
	private static $rendering = false;

	/**
	 * Words too common to say anything about what a page is about.
	 *
	 * @var array<string, string[]>
	 */
	private static $stopwords = array(
		'de' => array(
			'aber',
			'alle',
			'allem',
			'allen',
			'aller',
			'alles',
			'als',
			'also',
			'auch',
			'auf',
			'aus',
			'bei',
			'beim',
			'bin',
			'bis',
			'bist',
			'dabei',
			'damit',
			'dann',
			'das',
			'dass',
			'dem',
			'den',
			'denn',
			'der',
			'des',
			'die',
			'dies',
			'diese',
			'diesem',
			'diesen',
			'dieser',
			'dieses',
			'doch',
			'dort',
			'durch',
			'ein',
			'eine',
			'einem',
			'einen',
			'einer',
			'eines',
			'etwa',
			'euch',
			'fuer',
			'für',
			'ganz',
			'gegen',
			'hab',
			'habe',
			'haben',
			'hat',
			'hatte',
			'hier',
			'ihr',
			'ihre',
			'ihrem',
			'ihren',
			'ihrer',
			'immer',
			'ist',
			'jede',
			'jeden',
			'kann',
			'kein',
			'keine',
			'koennen',
			'können',
			'mehr',
			'mein',
			'mit',
			'nach',
			'nicht',
			'noch',
			'nur',
			'oder',
			'ohne',
			'schon',
			'sehr',
			'sein',
			'seine',
			'seinen',
			'seiner',
			'sich',
			'sie',
			'sind',
			'soll',
			'sollen',
			'sondern',
			'ueber',
			'über',
			'und',
			'uns',
			'unser',
			'unsere',
			'unter',
			'vom',
			'von',
			'vor',
			'war',
			'waren',
			'was',
			'wenn',
			'werden',
			'wie',
			'wir',
			'wird',
			'wurde',
			'wurden',
			'zum',
			'zur',
			'zwischen',
		),
		'en' => array(
			'about',
			'after',
			'all',
			'also',
			'and',
			'any',
			'are',
			'because',
			'been',
			'but',
			'can',
			'could',
			'did',
			'does',
			'each',
			'for',
			'from',
			'had',
			'has',
			'have',
			'her',
			'here',
			'him',
			'his',
			'how',
			'into',
			'its',
			'just',
			'like',
			'more',
			'most',
			'not',
			'now',
			'one',
			'only',
			'other',
			'our',
			'out',
			'over',
			'said',
			'she',
			'should',
			'some',
			'such',
			'than',
			'that',
			'the',
			'their',
			'them',
			'then',
			'there',
			'these',
			'they',
			'this',
			'those',
			'through',
			'too',
			'very',
			'was',
			'were',
			'what',
			'when',
			'where',
			'which',
			'while',
			'who',
			'will',
			'with',
			'would',
			'you',
			'your',
		),
	);

	/**
	 * How the last extraction got its HTML: 'crawl' or 'render'.
	 *
	 * @var string
	 */
	private $source = '';

	/**
	 * Which path the last call took, for the admin screens to report.
	 *
	 * @return string
	 */
	public function last_source() {
		return $this->source;
	}

	/**
	 * Builds the payload for one post.
	 *
	 * @param int|WP_Post $post Post or post ID.
	 * @param string      $url  Address to crawl; defaults to the permalink.
	 * @return array|null Payload, or null when the post yields no text.
	 */
	public function extract( $post, $url = '' ) {
		$post = get_post( $post );

		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$html     = $this->page_html( $post, $url );
		$markdown = ( new Html_To_Markdown() )->convert( $html );
		$title    = $this->title( $post );
		$language = $this->language( $post );

		if ( '' === trim( $markdown ) && '' === trim( $title ) ) {
			return null;
		}

		$keywords = $this->keywords( $title . ' ' . wp_strip_all_tags( $html ), $language );

		$payload = array(
			'externalId'       => self::external_id( $post->ID ),
			'title'            => $title,
			'url'              => (string) get_permalink( $post ),
			'content'          => $markdown,
			'keywords'         => implode( ', ', $keywords ),
			'language'         => $language,
			'meta_description' => $this->description( $post ),
			'tstamp'           => time(),
			'type'             => $post->post_type,
		);

		/**
		 * Filters the payload handed to the knowledge base.
		 *
		 * @param array   $payload What will be sent.
		 * @param WP_Post $post    The post it was built from.
		 */
		return (array) apply_filters( 'bluebranch_chatbot_post_payload', $payload, $post );
	}

	/**
	 * The identifier one post is known by in the knowledge base.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function external_id( $post_id ) {
		return 'post_' . (int) $post_id;
	}

	/**
	 * Gets the page's HTML, by crawling it or by rendering it.
	 *
	 * Crawling is tried first because it produces what a reader sees: fields
	 * the template renders, sections a page builder keeps outside
	 * post_content, a block theme's title and meta blocks. None of that is in
	 * post_content, and all of it is on the page.
	 *
	 * A server is not always allowed to call itself, though, so a failed crawl
	 * falls back to rendering rather than leaving the post untrained. Which
	 * path was taken is recorded, so the training screen can say so instead of
	 * quietly producing thinner content than expected.
	 *
	 * @param WP_Post $post Post to read.
	 * @param string  $url  Address to crawl; defaults to the permalink.
	 * @return string
	 */
	private function page_html( WP_Post $post, $url = '' ) {
		if ( ! Options::crawls() ) {
			$this->source = 'render';

			return $this->finish( $this->render( $post ), $post );
		}

		$url  = '' !== $url ? $url : (string) get_permalink( $post );
		$html = ( new Crawler() )->fetch( $url );

		if ( is_wp_error( $html ) ) {
			Logger::debug( 'Crawl failed, falling back to the rendered content: ' . $html->get_error_message() );

			$this->source = 'render';

			return $this->finish( $this->render( $post ), $post );
		}

		$this->source = 'crawl';

		$content = ( new Page_Extractor() )->extract( $html, ( new Boilerplate() )->stored() );

		// An extraction that comes back empty means the selectors found nothing
		// they recognised. The rendered content is thin by comparison, but it
		// is content.
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			Logger::debug( 'Crawled page yielded no content; falling back to the rendered content for post ' . $post->ID . '.' );

			$this->source = 'render';

			return $this->finish( $this->render( $post ), $post );
		}

		return $this->finish( $content, $post );
	}

	/**
	 * Hands the markup to the filter that lets integrations replace it.
	 *
	 * @param string  $html The markup.
	 * @param WP_Post $post The post it belongs to.
	 * @return string
	 */
	private function finish( $html, WP_Post $post ) {
		/**
		 * Filters the markup before it is reduced to Markdown.
		 *
		 * Page builders that store their layout outside post_content can put
		 * their own output here.
		 *
		 * @param string  $html Rendered or crawled markup.
		 * @param WP_Post $post The post it belongs to.
		 */
		return (string) apply_filters( 'bluebranch_chatbot_rendered_html', $html, $post );
	}

	/**
	 * Runs the post content through the_content, as the theme would.
	 *
	 * @param WP_Post $post Post to render.
	 * @return string
	 */
	private function render( WP_Post $post ) {
		if ( self::$rendering ) {
			return $post->post_content;
		}

		$content = Frontend::strip_excluded_regions( $post->post_content );
		$content = Frontend::strip_own_shortcodes( $content );

		self::$rendering = true;

		$previous_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;

		/*
		 * the_content filters routinely reach for the global post -- a gallery
		 * block resolving its attachments, a page builder looking up its layout.
		 * Without this they would render whichever post happens to be current.
		 * The previous value is put back a few lines down.
		 */
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Swapped for the duration of the render and restored below.
		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Applying WordPress's own filter is the point: it is what makes blocks, shortcodes and page builders render as the reader sees them.
		$html = apply_filters( 'the_content', $content );

		wp_reset_postdata();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the value swapped out above.
		$GLOBALS['post'] = $previous_post;

		self::$rendering = false;

		return $html;
	}

	/**
	 * The title, preferring an SEO title when one is set.
	 *
	 * @param WP_Post $post Post to read.
	 * @return string
	 */
	private function title( WP_Post $post ) {
		foreach ( array( '_yoast_wpseo_title', 'rank_math_title', '_seopress_titles_title' ) as $meta_key ) {
			$value = trim( (string) get_post_meta( $post->ID, $meta_key, true ) );

			// SEO titles are templates; one still holding placeholders is of no use.
			if ( '' !== $value && ! str_contains( $value, '%%' ) && ! str_contains( $value, '%title%' ) ) {
				return $value;
			}
		}

		return wp_strip_all_tags( get_the_title( $post ) );
	}

	/**
	 * The meta description, falling back to the excerpt.
	 *
	 * @param WP_Post $post Post to read.
	 * @return string
	 */
	private function description( WP_Post $post ) {
		foreach ( array( '_yoast_wpseo_metadesc', 'rank_math_description', '_seopress_titles_desc' ) as $meta_key ) {
			$value = trim( (string) get_post_meta( $post->ID, $meta_key, true ) );

			if ( '' !== $value && ! str_contains( $value, '%%' ) ) {
				return $value;
			}
		}

		return trim( wp_strip_all_tags( $post->post_excerpt ) );
	}

	/**
	 * The language the post is written in.
	 *
	 * @param WP_Post $post Post to read.
	 * @return string Two-letter code.
	 */
	private function language( WP_Post $post ) {
		$locale   = get_bloginfo( 'language' );
		$language = strtolower( substr( (string) $locale, 0, 2 ) );

		if ( '' === $language ) {
			$language = 'de';
		}

		/**
		 * Filters the language reported for one post.
		 *
		 * Multilingual plugins keep the language per post rather than per site.
		 *
		 * @param string  $language Two-letter code.
		 * @param WP_Post $post     The post in question.
		 */
		return (string) apply_filters( 'bluebranch_chatbot_post_language', $language, $post );
	}

	/**
	 * The words that occur most often, as a rough topic hint.
	 *
	 * @param string $text     Plain text.
	 * @param string $language Two-letter code, picks the stopword list.
	 * @param int    $limit    How many words to keep.
	 * @return string[]
	 */
	public function keywords( $text, $language = 'de', $limit = 15 ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );

		$words = preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $words ) ) {
			return array();
		}

		$stopwords = isset( self::$stopwords[ $language ] ) ? self::$stopwords[ $language ] : self::$stopwords['en'];
		$stopwords = array_merge( $stopwords, self::$stopwords['en'] );
		$stopwords = array_flip( $stopwords );

		$counts = array();

		foreach ( $words as $word ) {
			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $word, 'UTF-8' ) : strlen( $word );

			if ( $length < 3 || isset( $stopwords[ $word ] ) ) {
				continue;
			}

			$counts[ $word ] = isset( $counts[ $word ] ) ? $counts[ $word ] + 1 : 1;
		}

		arsort( $counts );

		return array_slice( array_keys( $counts ), 0, (int) $limit );
	}
}
