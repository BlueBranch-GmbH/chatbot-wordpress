<?php
/**
 * What is removed when the plugin is deleted.
 *
 * Deleting a plugin is a deliberate act, so everything this plugin created in
 * the database goes with it. The knowledge base held by the API is left alone:
 * it belongs to the account, not to this installation, and a reinstall or a
 * second site using the same key would otherwise find it empty.
 *
 * @package BlueBranch\Chatbot
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes every trace of the plugin from one site.
 *
 * @return void
 */
function bluebranch_chatbot_uninstall_site() {
	global $wpdb;

	delete_option( 'bluebranch_chatbot_settings' );
	delete_option( 'bluebranch_chatbot_api_key' );
	delete_option( 'bluebranch_chatbot_purge_last_run' );
	delete_option( 'bluebranch_chatbot_last_error' );
	delete_option( 'bluebranch_chatbot_db_version' );

	// The plugin's own tables: saved questions and the additional content.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bluebranch_chatbot_log" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bluebranch_chatbot_content" );

	// Answers waiting for feedback while saving is off.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_bbchat\\_ans\\_%' OR option_name LIKE '\\_transient\\_timeout\\_bbchat\\_ans\\_%'" );

	delete_transient( 'bluebranch_chatbot_train_queue' );
	delete_transient( 'bluebranch_chatbot_boilerplate' );

	wp_clear_scheduled_hook( 'bluebranch_chatbot_purge' );
	wp_clear_scheduled_hook( 'bluebranch_chatbot_maintenance' );
	wp_clear_scheduled_hook( 'bluebranch_chatbot_train_post' );
	wp_clear_scheduled_hook( 'bluebranch_chatbot_delete_post' );

	// Two meta keys across every post: no API exists for that, and doing it
	// post by post would be one query per post on a site of any size.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_bluebranch_chatbot_exclude' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_bluebranch_chatbot_trained' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_bluebranch_chatbot_hash' ) );
}

if ( is_multisite() ) {
	$bluebranch_chatbot_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $bluebranch_chatbot_sites as $bluebranch_chatbot_site_id ) {
		switch_to_blog( (int) $bluebranch_chatbot_site_id );
		bluebranch_chatbot_uninstall_site();
		restore_current_blog();
	}
} else {
	bluebranch_chatbot_uninstall_site();
}
