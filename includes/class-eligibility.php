<?php
/**
 * Decides whether a post belongs in the AI index.
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

defined( 'ABSPATH' ) || exit;

/**
 * One place for the question "may this post be used as a source?".
 *
 * The rules are needed when a post is saved, when the clean-up runs and when
 * the admin screens count what can be trained. Kept in three places they would
 * have to be maintained three times, and a forgotten one is invisible: the
 * index would quietly hold posts the editor believes are excluded.
 */
class Eligibility {

	/**
	 * How many children are fetched per query while walking a branch.
	 */
	const PAGE_SIZE = 100;

	/**
	 * Answers already worked out during this request.
	 *
	 * The clean-up run asks about hundreds of posts that share the same
	 * ancestors; without this the ancestor walk would repeat for each of them.
	 *
	 * @var array<int, bool>
	 */
	private $cache = array();

	/**
	 * Whether the post itself or any of its ancestors is excluded from answers.
	 *
	 * The inheritance is the point: excluding a section means the whole branch.
	 * An editor who ticks "Internals" and then has to repeat it on every child
	 * page might as well not have the setting.
	 *
	 * @param int $post_id Post to check.
	 * @return bool
	 */
	public function is_excluded( $post_id ) {
		$post_id = (int) $post_id;

		if ( $post_id < 1 ) {
			return false;
		}

		if ( isset( $this->cache[ $post_id ] ) ) {
			return $this->cache[ $post_id ];
		}

		$ids = array_merge( array( $post_id ), array_map( 'intval', get_post_ancestors( $post_id ) ) );

		foreach ( $ids as $id ) {
			if ( '1' === (string) get_post_meta( $id, Indexer::META_EXCLUDE, true ) ) {
				$this->cache[ $post_id ] = true;

				return true;
			}
		}

		$this->cache[ $post_id ] = false;

		return false;
	}

	/**
	 * Whether the post may go into the index at all.
	 *
	 * @param int|\WP_Post $post Post or post ID.
	 * @return bool
	 */
	public function is_eligible( $post ) {
		$post = get_post( $post );

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		if ( ! in_array( $post->post_type, Options::trainable_post_types(), true ) ) {
			return false;
		}

		if ( 'publish' !== $post->post_status ) {
			return false;
		}

		// A password turns the page into something only some visitors may read.
		// The chatbot cannot ask for the password, so it must not quote the page.
		if ( '' !== $post->post_password ) {
			return false;
		}

		if ( ! is_post_publicly_viewable( $post ) ) {
			return false;
		}

		if ( $this->is_noindex( $post->ID ) ) {
			return false;
		}

		if ( $this->is_excluded( $post->ID ) ) {
			return false;
		}

		/**
		 * Filters whether one post may be used as a source.
		 *
		 * @param bool     $eligible Result of the built-in rules.
		 * @param \WP_Post $post     The post in question.
		 */
		return (bool) apply_filters( 'bluebranch_chatbot_is_eligible', true, $post );
	}

	/**
	 * Whether an SEO plugin has marked the post as noindex.
	 *
	 * A page kept out of search engines is not meant to be found, and quoting
	 * it in an answer would put it back in front of exactly the audience it
	 * was hidden from.
	 *
	 * @param int $post_id Post to check.
	 * @return bool
	 */
	private function is_noindex( $post_id ) {
		if ( '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			return true;
		}

		$rank_math = get_post_meta( $post_id, 'rank_math_robots', true );

		if ( is_array( $rank_math ) && in_array( 'noindex', $rank_math, true ) ) {
			return true;
		}

		if ( 'yes' === (string) get_post_meta( $post_id, '_seopress_robots_index', true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * The post itself and every descendant.
	 *
	 * Needed when the exclusion is set on a parent: its children are still in
	 * the index at that moment and have to come out with it. The clean-up run
	 * would catch them too, but a setting that takes effect tomorrow is not a
	 * setting -- the editor considers the matter closed.
	 *
	 * @param int $post_id Post at the top of the branch.
	 * @return int[]
	 */
	public function branch_ids( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		$ids = array( $post_id );

		if ( ! is_post_type_hierarchical( $post->post_type ) ) {
			return $ids;
		}

		$level = array( $post_id );

		// Iterative rather than recursive: the depth of a page tree is unknown,
		// and a recursion over a broken parent chain would run to the end of
		// the stack.
		while ( array() !== $level ) {
			$next   = array();
			$offset = 0;

			/*
			 * Fetched a page at a time rather than all at once. A fixed limit
			 * would silently stop at the last page it covered, and the children
			 * beyond it would stay in the knowledge base with nothing to say
			 * they had been missed.
			 */
			do {
				$children = get_posts(
					array(
						'post_type'        => $post->post_type,
						// Deliberately not 'any': that keyword leaves out the
						// trash, and a trashed child still has to come out.
						'post_status'      => array_keys( get_post_stati() ),
						'post_parent__in'  => $level,
						'posts_per_page'   => self::PAGE_SIZE,
						'offset'           => $offset,
						'orderby'          => 'ID',
						'order'            => 'ASC',
						'fields'           => 'ids',
						'no_found_rows'    => true,
						'suppress_filters' => false,
					)
				);

				foreach ( $children as $child_id ) {
					$child_id = (int) $child_id;

					// Guards against a cycle in the parent chain; without it the
					// loop would never end.
					if ( in_array( $child_id, $ids, true ) ) {
						continue;
					}

					$ids[]  = $child_id;
					$next[] = $child_id;
				}

				$fetched = count( $children );
				$offset += self::PAGE_SIZE;
			} while ( self::PAGE_SIZE === $fetched );

			$level = $next;
		}

		return $ids;
	}

	/**
	 * Forgets the cached answers.
	 *
	 * Needed when the setting changes within the same request -- the answers
	 * worked out before the save describe the state before it.
	 *
	 * @return void
	 */
	public function reset() {
		$this->cache = array();
	}
}
