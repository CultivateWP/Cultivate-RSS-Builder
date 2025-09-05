<?php
/**
 * Plugin Name: Cultivate RSS Builder
 * Plugin URI:  https://cultivatewp.com/our-plugins/cultivate-rss-builder/
 * Description: Allows you to build custom RSS feeds for email marketing
 * Author:      CultivateWP
 * Author URI:  https://cultivatewp.com/
 * Version:     1.1.0
 * Text Domain: cultivate-rss-builder
 *
 * Cultivate RSS Builder is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 *
 * Cultivate RSS Builder is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Cultivate RSS Builder. If not, see <http://www.gnu.org/licenses/>.
 */

namespace Cultivate\RSS_Builder;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the class
require_once dirname( __FILE__ ) . '/includes/class-cwp-rss-builder.php';

/**
 * Updater
 */
function updater() {

	require plugin_dir_path( __FILE__ ) . 'includes/updater/plugin-update-checker.php';
	$myUpdateChecker = PucFactory::buildUpdateChecker(
		'https://github.com/CultivateWP/Cultivate-RSS-Builder/',
		__FILE__, //Full path to the main plugin file or functions.php.
		'cultivate-rss-builder'
	);

}
add_action( 'admin_init', __NAMESPACE__ . '\updater' );

