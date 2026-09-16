<?php
/**
 * Keeps the knowledge base in step with the posts.
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Trains a post when it is published and removes it when it stops being public.
 *
 * The work is handed to WP-Cron rather than done during the save. Training
 * means a round trip to the API, and an editor who presses "Update" should not
 * be made to wait for it -- nor should the save fail because the API is slow.
 */
class Indexer {

	/**
	 * Post meta holding the exclusion from AI answers.
	 */
	const META_EXCLUDE = '_bluebranch_chatbot_exclude';

	/**
	 * Post meta holding when the post was last handed to the API.
	 */
	const META_TRAINED = '_bluebranch_chatbot_trained';

	/**
	 * Post meta holding a hash of what was last handed over.
	 *
	 * This is what keeps the knowledge base current without resending it. A
	 * run that re-trains everything costs one API call per post whether or not
	 * anything changed; with the hash it costs one call per post that did.
	 */
	const META_HASH = '_bluebranch_chatbot_hash';

	/**
	 * Single event that trains one post.
	 */
	const EVENT_TRAIN = 'bluebranch_chatbot_train_post';

	/**
	 * Single event that removes one post.
	 */
	const EVENT_DELETE = 'bluebranch_chatbot_delete_post';

	/**
	 * Hooks the indexer into WordPress.
	 *
	 * @return void
	 */
	public function register() {
		// After the post, its meta and its terms are all written -- an earlier
		// hook would render the post without the data saved alongside it.
		add_action( 'wp_after_insert_post', array( $this, 'on_post_saved' ), 20, 2 );
		add_action( 'transition_post_status', array( $this, 'on_status_changed' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_post_deleted' ) );

		add_action( self::EVENT_TRAIN, array( $this, 'train' ) );
		add_action( self::EVENT_DELETE, array( $this, 'delete' ) );
	}

	/**
	 * Decides what a save means for the index.
	 *
	 * @param int          $post_id Post that was saved.
	 * @param WP_Post|null $post    The post object.
	 * @return void
	 */
	public function on_post_saved( $post_id, $post = null ) {
		$post = $post instanceof WP_Post ? $post : get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		if ( ! in_array( $post->post_type, Options::trainable_post_types(), true ) ) {
			return;
		}

		$eligibility = new Eligibility();

		/*
		 * Excluding a parent takes its children with it. They are still in the
		 * index at this moment and inherit the exclusion only through this
		 * check. The clean-up run would catch them too, but an exclusion that
		 * takes effect tomorrow is not an exclusion -- the editor has moved on.
		 */
		if ( $eligibility->is_excluded( $post->ID ) ) {
			foreach ( $eligibility->branch_ids( $post->ID ) as $branch_id ) {
				$this->queue_delete( $branch_id );
			}

			return;
		}

		if ( ! $eligibility->is_eligible( $post ) ) {
			$this->queue_delete( $post->ID );

			return;
		}

		if ( ! Options::get( 'auto_train' ) ) {
			return;
		}

		$this->queue_train( $post->ID );
	}

	/**
	 * Removes a post that has stopped being published.
	 *
	 * Catches what a save does not: trashing from the list table, a scheduled
	 * post reverting, a status set by another plugin.
	 *
	 * @param string  $new_status Status after the change.
	 * @param string  $old_status Status before the change.
	 * @param WP_Post $post       The post.
	 * @return void
	 */
	public function on_status_changed( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post || $new_status === $old_status ) {
			return;
		}

		if ( 'publish' !== $old_status || 'publish' === $new_status ) {
			return;
		}

		if ( ! in_array( $post->post_type, Options::trainable_post_types(), true ) ) {
			return;
		}

		$eligibility = new Eligibility();

		foreach ( $eligibility->branch_ids( $post->ID ) as $branch_id ) {
			$this->queue_delete( $branch_id );
		}
	}

	/**
	 * Removes a post that is being deleted for good.
	 *
	 * @param int $post_id Post about to disappear.
	 * @return void
	 */
	public function on_post_deleted( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, Options::trainable_post_types(), true ) ) {
			return;
		}

		/*
		 * Done straight away rather than queued: by the time a scheduled event
		 * ran, the post would be gone and nothing would be left to work out
		 * which entry belonged to it.
		 */
		$this->delete( $post_id );
	}

	/**
	 * Puts one post in line to be trained.
	 *
	 * @param int $post_id Post to train.
	 * @return void
	 */
	public function queue_train( $post_id ) {
		$post_id = (int) $post_id;
		$args    = array( $post_id );

		if ( wp_next_scheduled( self::EVENT_TRAIN, $args ) ) {
			return;
		}

		wp_schedule_single_event( time() + 10, self::EVENT_TRAIN, $args );
	}

	/**
	 * Puts one post in line to be removed.
	 *
	 * @param int $post_id Post to remove.
	 * @return void
	 */
	public function queue_delete( $post_id ) {
		$post_id = (int) $post_id;

		// Nothing was ever sent for this post, so there is nothing to withdraw.
		if ( ! get_post_meta( $post_id, self::META_TRAINED, true ) ) {
			return;
		}

		$args = array( $post_id );

		if ( wp_next_scheduled( self::EVENT_DELETE, $args ) ) {
			return;
		}

		wp_schedule_single_event( time() + 10, self::EVENT_DELETE, $args );
	}

	/**
	 * Hands one post to the knowledge base, if it has anything new to say.
	 *
	 * @param int    $post_id Post to train.
	 * @param string $url     Address to crawl; defaults to the permalink.
	 * @param bool   $force   Send even when nothing has changed.
	 * @return string One of: trained, unchanged, removed, skipped, failed.
	 */
	public function train( $post_id, $url = '', $force = false ) {
		$post_id = (int) $post_id;

		if ( ! Options::has_api_key() ) {
			return 'skipped';
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			$this->delete( $post_id );

			return 'removed';
		}

		$eligibility = new Eligibility();

		// A post that has stopped qualifying is withdrawn rather than trained.
		// Training and removing are the same job seen from two sides, and
		// splitting them would let a post that was excluded between the queue
		// and the run stay in the index.
		if ( ! $eligibility->is_eligible( $post ) ) {
			$this->delete( $post_id );

			return 'removed';
		}

		$extractor = new Content_Extractor();
		$payload   = $extractor->extract( $post, $url );

		if ( null === $payload ) {
			Logger::debug( 'Nothing to train for post ' . $post_id . ': the post yields no text.' );

			return 'skipped';
		}

		$hash = $this->payload_hash( $payload );

		if ( ! $force && (string) get_post_meta( $post_id, self::META_HASH, true ) === $hash ) {
			// Touched anyway, so the clean-up run can tell an entry that was
			// checked from one nobody has looked at since the last purge.
			update_post_meta( $post_id, self::META_TRAINED, time() );

			return 'unchanged';
		}

		$result = ( new Api_Client() )->train_content( $payload );

		if ( is_wp_error( $result ) ) {
			return 'failed';
		}

		update_post_meta( $post_id, self::META_TRAINED, time() );
		update_post_meta( $post_id, self::META_HASH, $hash );

		Logger::debug( sprintf( 'Post %d trained from the %s.', $post_id, 'crawl' === $extractor->last_source() ? 'crawled page' : 'rendered content' ) );

		return 'trained';
	}

	/**
	 * A fingerprint of what would be sent.
	 *
	 * The timestamp is left out on purpose: it changes on every run, and a
	 * hash that always differs would defeat the whole point of having one.
	 *
	 * @param array $payload What would be sent.
	 * @return string
	 */
	private function payload_hash( array $payload ) {
		unset( $payload['tstamp'] );

		return md5( (string) wp_json_encode( $payload ) );
	}

	/**
	 * Removes one post from the knowledge base.
	 *
	 * @param int $post_id Post to remove.
	 * @return bool Whether the API accepted it.
	 */
	public function delete( $post_id ) {
		$post_id = (int) $post_id;

		if ( ! Options::has_api_key() ) {
			return false;
		}

		$result = ( new Api_Client() )->delete_content( Content_Extractor::external_id( $post_id ) );

		delete_post_meta( $post_id, self::META_TRAINED );
		delete_post_meta( $post_id, self::META_HASH );

		return ! is_wp_error( $result );
	}

	/**
	 * The IDs of every post that may be trained.
	 *
	 * @param int $limit  How many to return, -1 for all.
	 * @param int $offset Where to start.
	 * @return int[]
	 */
	public function trainable_ids( $limit = -1, $offset = 0 ) {
		$types = Options::trainable_post_types();

		if ( array() === $types ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'           => $types,
				'post_status'         => 'publish',
				'has_password'        => false,
				'posts_per_page'      => (int) $limit,
				'offset'              => (int) $offset,
				'fields'              => 'ids',
				'orderby'             => 'ID',
				'order'               => 'ASC',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			)
		);

		$eligibility = new Eligibility();

		return array_values(
			array_filter(
				array_map( 'intval', $query->posts ),
				static function ( $post_id ) use ( $eligibility ) {
					return $eligibility->is_eligible( $post_id );
				}
			)
		);
	}

	/**
	 * What a crawl run should visit, taken from the sitemaps.
	 *
	 * Every URL is resolved back to the post it belongs to. That resolution is
	 * not bureaucracy: the post ID is what the entry is filed under, what the
	 * exclusion checkbox hangs off, and what lets the entry be withdrawn again
	 * when the post is unpublished. A URL trained without one could never be
	 * found again once its address changed.
	 *
	 * A URL that resolves to nothing -- a category archive, an author page,
	 * the blog home -- is reported rather than trained, so the count on the
	 * screen adds up and nobody is left wondering where the rest went.
	 *
	 * @return array{targets: array<int, string>, unresolved: string[], total: int}|\WP_Error
	 */
	public function crawl_targets() {
		$urls = ( new Sitemap() )->urls();

		if ( is_wp_error( $urls ) ) {
			return $urls;
		}

		$eligibility = new Eligibility();
		$targets     = array();
		$unresolved  = array();

		foreach ( $urls as $url ) {
			$post_id = self::post_id_for_url( $url );

			if ( $post_id < 1 ) {
				$unresolved[] = $url;

				continue;
			}

			if ( ! $eligibility->is_eligible( $post_id ) ) {
				continue;
			}

			// Keyed by post, so a sitemap listing the same post twice -- under
			// a paginated address, say -- still trains it once.
			$targets[ $post_id ] = $url;
		}

		return array(
			'targets'    => $targets,
			'unresolved' => $unresolved,
			'total'      => count( $urls ),
		);
	}

	/**
	 * The post a public URL belongs to, or 0.
	 *
	 * @param string $url Absolute URL on this site.
	 * @return int
	 */
	public static function post_id_for_url( $url ) {
		$post_id = (int) url_to_postid( $url );

		if ( $post_id > 0 ) {
			return $post_id;
		}

		/*
		 * url_to_postid() does not answer for the front page, which is the one
		 * URL every sitemap contains. When a page is set as the front page it
		 * is an ordinary post with content worth training.
		 */
		if ( untrailingslashit( $url ) === untrailingslashit( home_url( '/' ) ) ) {
			return (int) get_option( 'page_on_front' );
		}

		return 0;
	}

	/**
	 * How many posts could be trained right now.
	 *
	 * @return int
	 */
	public function trainable_count() {
		return count( $this->trainable_ids() );
	}

	/**
	 * How many posts have been handed to the API at least once.
	 *
	 * @return int
	 */
	public function trained_count() {
		$types = Options::trainable_post_types();

		if ( array() === $types ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'           => $types,
				'post_status'         => 'any',
				'posts_per_page'      => 1,
				'fields'              => 'ids',
				'ignore_sticky_posts' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Counting what was trained needs the meta; this runs on an admin screen, not on a page request.
				'meta_key'            => self::META_TRAINED,
				'meta_compare'        => 'EXISTS',
			)
		);

		return (int) $query->found_posts;
	}
}
