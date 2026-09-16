<?php
/**
 * Plugin Name:       BlueBranch Chatbot
 * Plugin URI:        https://github.com/BlueBranch-GmbH/chatbot-wordpress
 * Description:       AI chat and AI search that answer from your own content only. Your pages are handed to the BlueBranch Chatbot API, which builds a vector knowledge base; answers are rendered as a collapsible chat widget, an ask field or a summary above your search results. The API key never leaves the server.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            BlueBranch GmbH
 * Author URI:        https://www.bluebranch.de/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bluebranch-chatbot
 * Domain Path:       /languages
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

defined( 'ABSPATH' ) || exit;

const VERSION = '1.0.0';

define( 'BLUEBRANCH_CHATBOT_FILE', __FILE__ );
define( 'BLUEBRANCH_CHATBOT_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLUEBRANCH_CHATBOT_URL', plugin_dir_url( __FILE__ ) );

require_once BLUEBRANCH_CHATBOT_DIR . 'includes/class-autoloader.php';

Autoloader::register();

register_activation_hook( __FILE__, array( Activation::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Activation::class, 'deactivate' ) );

/**
 * Returns the one plugin instance.
 *
 * @return Plugin
 */
function plugin() {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new Plugin();
	}

	return $instance;
}

plugin()->register();
