<?php
/**
 * Plugin Name: Cultivate RSS Builder
 * Plugin URI:  https://cultivatewp.com/our-plugins/cultivate-rss-builder/
 * Description: Allows you to build custom RSS feeds for email marketing
 * Author:      CultivateWP
 * Author URI:  https://cultivatewp.com/
 * Version:     1.2.0
 * Requires PHP: 7.0
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
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
 * @package Cultivate_RSS_Builder
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

define( 'CWP_RSS_BUILDER_VERSION', '1.2.0' );

require_once __DIR__ . '/includes/class-cwp-rss-builder-settings.php';
require_once __DIR__ . '/includes/class-cwp-rss-builder-editor.php';

// Load the class.
require_once __DIR__ . '/includes/class-cwp-rss-builder.php';

/**
 * Register the plugin updater.
 *
 * @return void
 */
function updater() {

	require plugin_dir_path( __FILE__ ) . 'includes/updater/plugin-update-checker.php';
	PucFactory::buildUpdateChecker(
		'https://github.com/CultivateWP/Cultivate-RSS-Builder/',
		__FILE__, // Full path to the main plugin file or functions.php.
		'cultivate-rss-builder'
	);
}
add_action( 'admin_init', __NAMESPACE__ . '\updater' );
