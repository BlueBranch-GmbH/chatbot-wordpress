<?php
/**
 * What happens when the plugin is switched on and off.
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Activation and deactivation.
 *
 * Deactivation deliberately leaves both the settings and the knowledge base
 * alone: switching a plugin off is routinely done to test something, and a
 * deactivation that wipes a customer's trained content would be unrecoverable.
 * Removing data is uninstall.php's job.
 */
class Activation {

	/**
	 * Seeds the defaults and puts the clean-up run on the schedule.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( false === get_option( Options::SETTINGS, false ) ) {
			add_option( Options::SETTINGS, Options::defaults() );
		}

		Cron::schedule_events();
	}

	/**
	 * Takes every scheduled event off the calendar again.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Cron::clear_events();
	}
}
