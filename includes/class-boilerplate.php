<?php
/**
 * Learns what repeats on every page, so it can be left out.
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Samples a few pages and remembers the blocks they have in common.
 *
 * Markers and selectors between them catch the furniture that announces
 * itself -- a template part, a `<nav>`, an element with role="banner". What
 * they cannot catch is the furniture that looks exactly like content: a cookie
 * notice, a breadcrumb bar, a newsletter box repeated at the foot of every
 * article. No selector list anticipates those, because they are different on
 * every site.
 *
 * Repetition finds them without knowing anything about the theme. A block of
 * text standing on nearly every page of a site is not what any single one of
 * those pages is about.
 */
class Boilerplate {

	/**
	 * Where the learned hashes are kept.
	 */
	const TRANSIENT = 'bluebranch_chatbot_boilerplate';

	/**
	 * How many pages are sampled.
	 *
	 * Enough to tell repetition from coincidence, few enough that the sampling
	 * is not itself a crawl of the site.
	 */
	const SAMPLE_SIZE = 6;

	/**
	 * How long the result is kept.
	 */
	const LIFETIME = DAY_IN_SECONDS;

	/**
	 * Samples the given URLs and stores what they have in common.
	 *
	 * @param string[] $urls Every URL of the run, in order.
	 * @return array<string, true> The hashes that were learned.
	 */
	public function learn( array $urls ) {
		$urls = array_values( array_unique( $urls ) );

		if ( count( $urls ) < 3 ) {
			// Too few pages to tell a repeated block from a coincidence.
			$this->store( array() );

			return array();
		}

		$crawler   = new Crawler();
		$extractor = new Page_Extractor();
		$samples   = array();

		foreach ( $this->spread( $urls ) as $url ) {
			$html = $crawler->fetch( $url );

			if ( is_wp_error( $html ) ) {
				continue;
			}

			$samples[] = $extractor->fingerprints( $html );
		}

		$learned = Page_Extractor::learn_boilerplate( $samples );

		$this->store( $learned );

		Logger::debug(
			sprintf(
				'Boilerplate sampling: %d pages read, %d repeated blocks found.',
				count( $samples ),
				count( $learned )
			)
		);

		return $learned;
	}

	/**
	 * The hashes learned by the last run.
	 *
	 * @return array<string, true>
	 */
	public function stored() {
		if ( ! Options::get( 'strip_boilerplate' ) ) {
			return array();
		}

		$stored = get_transient( self::TRANSIENT );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Forgets what was learned.
	 *
	 * @return void
	 */
	public function forget() {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Keeps the learned hashes.
	 *
	 * @param array $hashes What was learned.
	 * @return void
	 */
	private function store( array $hashes ) {
		set_transient( self::TRANSIENT, $hashes, self::LIFETIME );
	}

	/**
	 * Picks sample URLs from across the list rather than off the front of it.
	 *
	 * The first handful of a sitemap tends to be the newest posts, or all of
	 * one post type. Those have more in common with each other than with the
	 * site, and sampling them would mistake a post template for furniture.
	 *
	 * @param string[] $urls Every URL of the run.
	 * @return string[]
	 */
	private function spread( array $urls ) {
		$total = count( $urls );
		$want  = min( self::SAMPLE_SIZE, $total );

		if ( $want >= $total ) {
			return $urls;
		}

		$step   = (int) floor( $total / $want );
		$picked = array();

		for ( $index = 0; $index < $want; $index++ ) {
			$picked[] = $urls[ $index * $step ];
		}

		return $picked;
	}
}
