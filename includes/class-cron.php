<?php
/**
 * The scheduled clean-up of the AI index.
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Takes posts out of the knowledge base that should no longer be in it.
 *
 * Saving a post and changing its status are both caught as they happen, so
 * most of the work is already done. What is left are the cases nothing fires
 * for: a post whose type was taken out of the settings, a noindex flag set by
 * an SEO plugin without touching the post, an API call that failed while the
 * site was offline. Without a sweep those entries stay in the index for good.
 *
 * The run hooks into WP-Cron, so no server cronjob is needed -- it is enough
 * that the site is visited now and then.
 */
class Cron {

	/**
	 * The recurring clean-up event.
	 */
	const EVENT_PURGE = 'bluebranch_chatbot_purge';

	/**
	 * Schedule WordPress does not ship with.
	 */
	const SCHEDULE_SIX_HOURS = 'bluebranch_chatbot_six_hours';

	/**
	 * How many posts one run looks at.
	 */
	const BATCH_SIZE = 200;

	/**
	 * The most of the index sitemap reconciliation may withdraw in one run.
	 *
	 * Above this the sitemap is assumed to be wrong rather than the index.
	 */
	const MAX_RECONCILE_SHARE = 0.2;

	/**
	 * Hooks the clean-up into WordPress.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'cron_schedules', array( $this, 'add_schedules' ) );
		add_action( self::EVENT_PURGE, array( $this, 'purge' ) );
		add_action( 'update_option_' . Options::SETTINGS, array( $this, 'on_settings_saved' ) );
	}

	/**
	 * Adds the six hour interval WordPress has no name for.
	 *
	 * @param array $schedules Schedules registered so far.
	 * @return array
	 */
	public function add_schedules( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}

		$schedules[ self::SCHEDULE_SIX_HOURS ] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 hours', 'bluebranch-chatbot' ),
		);

		return $schedules;
	}

	/**
	 * Puts the clean-up back on the calendar when its setting changed.
	 *
	 * @return void
	 */
	public function on_settings_saved() {
		self::schedule_events();
	}

	/**
	 * Maps a stored interval onto a cron schedule name.
	 *
	 * @param string $interval One of the keys from Options::purge_intervals().
	 * @return string
	 */
	public static function schedule_name( $interval ) {
		switch ( $interval ) {
			case 'hourly':
				return 'hourly';
			case 'six_hours':
				return self::SCHEDULE_SIX_HOURS;
			case 'weekly':
				return 'weekly';
		}

		return 'daily';
	}

	/**
	 * Makes the schedule match the settings, changing nothing if it already does.
	 *
	 * @return void
	 */
	public static function schedule_events() {
		$wanted    = self::schedule_name( Options::get( 'purge_interval' ) );
		$enabled   = (bool) Options::get( 'purge_enabled' );
		$scheduled = wp_get_scheduled_event( self::EVENT_PURGE );

		if ( $enabled && $scheduled && $scheduled->schedule === $wanted ) {
			return;
		}

		wp_clear_scheduled_hook( self::EVENT_PURGE );

		if ( ! $enabled ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, $wanted, self::EVENT_PURGE );
	}

	/**
	 * Takes every scheduled event off the calendar.
	 *
	 * @return void
	 */
	public static function clear_events() {
		wp_clear_scheduled_hook( self::EVENT_PURGE );
		wp_clear_scheduled_hook( Indexer::EVENT_TRAIN );
		wp_clear_scheduled_hook( Indexer::EVENT_DELETE );
	}

	/**
	 * Looks for posts that no longer belong in the index and removes them.
	 *
	 * @return int How many entries were withdrawn.
	 */
	public function purge() {
		if ( ! Options::get( 'purge_enabled' ) || ! Options::has_api_key() ) {
			return 0;
		}

		$types = Options::trainable_post_types();

		if ( array() === $types ) {
			return 0;
		}

		$indexer = new Indexer();
		$removed = 0;

		$suspects = array_merge( $this->suspect_ids( $types ), $this->vanished_from_sitemap( $types ) );

		foreach ( array_unique( $suspects ) as $post_id ) {
			$indexer->delete( $post_id );
			++$removed;
		}

		update_option( Options::PURGE_LAST_RUN, time(), false );

		if ( $removed > 0 ) {
			Logger::debug( sprintf( 'Clean-up finished, %d entries withdrawn from the AI index.', $removed ) );
		}

		return $removed;
	}

	/**
	 * The posts that were trained once and may no longer be.
	 *
	 * Only posts carrying the "trained" marker are considered: withdrawing an
	 * entry that was never sent costs an API call and achieves nothing. That
	 * marker is what keeps a site with thousands of drafts from making
	 * thousands of pointless requests on every run.
	 *
	 * @param string[] $types Post types to look at.
	 * @return int[]
	 */
	private function suspect_ids( array $types ) {
		$query = new WP_Query(
			array(
				'post_type'           => $types,
				// Deliberately not 'any': that keyword leaves out the trash, and a
				// trashed post is exactly one that has to come out of the index.
				'post_status'         => array_keys( get_post_stati() ),
				'posts_per_page'      => self::BATCH_SIZE,
				'fields'              => 'ids',
				'orderby'             => 'modified',
				'order'               => 'DESC',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Runs in cron, never during a page request.
				'meta_key'            => Indexer::META_TRAINED,
				'meta_compare'        => 'EXISTS',
			)
		);

		$eligibility = new Eligibility();
		$suspects    = array();

		foreach ( $query->posts as $post_id ) {
			$post_id = (int) $post_id;

			if ( $eligibility->is_eligible( $post_id ) ) {
				continue;
			}

			$suspects[] = $post_id;
		}

		return $suspects;
	}

	/**
	 * Trained posts the sitemap no longer lists.
	 *
	 * This is the part that keeps the knowledge base honest over time. The
	 * eligibility rules catch what WordPress can tell us about; the sitemap
	 * catches what only the site knows -- a post an SEO plugin dropped, a type
	 * that stopped being public, a page moved behind a rule this plugin has no
	 * idea about.
	 *
	 * It is also the one place here that could do real damage, because a
	 * sitemap that fails to load looks exactly like a sitemap that lists
	 * nothing. So it refuses to act on a small or empty answer, and refuses to
	 * remove a large share of the index in one run. A stale entry surviving
	 * until tomorrow is a small problem; an index emptied by a plugin conflict
	 * is not.
	 *
	 * @param string[] $types Post types to look at.
	 * @return int[]
	 */
	private function vanished_from_sitemap( array $types ) {
		if ( ! Options::get( 'reconcile_sitemap' ) || ! Options::crawls() ) {
			return array();
		}

		$urls = ( new Sitemap() )->urls();

		if ( is_wp_error( $urls ) || count( $urls ) < 5 ) {
			Logger::debug( 'Sitemap reconciliation skipped: the sitemap did not come back with a usable list.' );

			return array();
		}

		$listed = array();

		foreach ( $urls as $url ) {
			$post_id = Indexer::post_id_for_url( $url );

			if ( $post_id > 0 ) {
				$listed[ $post_id ] = true;
			}
		}

		if ( array() === $listed ) {
			return array();
		}

		$trained = $this->trained_ids( $types );
		$missing = array();

		foreach ( $trained as $post_id ) {
			if ( ! isset( $listed[ $post_id ] ) ) {
				$missing[] = $post_id;
			}
		}

		if ( array() === $missing ) {
			return array();
		}

		$share = count( $missing ) / max( 1, count( $trained ) );

		if ( $share > self::MAX_RECONCILE_SHARE ) {
			Logger::error(
				sprintf(
					/* translators: 1: number of entries, 2: percentage. */
					__( 'Sitemap reconciliation stopped: it would have withdrawn %1$d entries (%2$d%% of the index). The sitemap is probably incomplete rather than the index wrong.', 'bluebranch-chatbot' ),
					count( $missing ),
					(int) round( $share * 100 )
				)
			);

			return array();
		}

		return $missing;
	}

	/**
	 * The posts that carry the "trained" marker.
	 *
	 * @param string[] $types Post types to look at.
	 * @return int[]
	 */
	private function trained_ids( array $types ) {
		$query = new WP_Query(
			array(
				'post_type'           => $types,
				'post_status'         => array_keys( get_post_stati() ),
				'posts_per_page'      => self::BATCH_SIZE,
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Runs in cron, never during a page request.
				'meta_key'            => Indexer::META_TRAINED,
				'meta_compare'        => 'EXISTS',
			)
		);

		return array_map( 'intval', $query->posts );
	}
}
