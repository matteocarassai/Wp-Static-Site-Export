<?php
/**
 * Plugin Name:       WP Static Exporter
 * Plugin URI:        https://github.com/matteocarassai/Wp-Static-Site-Export/tree/master/wp-static-exporter
 * Description:       Exports WordPress pages and posts into a static HTML/CSS/JS website zip archive.
 * Version:           1.8
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Matteo Carassai
 * Author URI:        https://www.matteocarassai.it/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-static-exporter
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'WP_STATIC_EXPORTER_VERSION', '1.8' );
define( 'WP_STATIC_EXPORTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP_STATIC_EXPORTER_URL', plugin_dir_url( __FILE__ ) );

// Include the main class responsible for the export logic
require_once WP_STATIC_EXPORTER_PATH . 'includes/class-static-exporter.php';

// Include the admin page setup
require_once WP_STATIC_EXPORTER_PATH . 'admin/admin-page.php';

// Hook to add the admin menu
add_action( 'admin_menu', 'wp_static_exporter_add_admin_menu' );

// Hook to enqueue admin scripts (This was added during debugging, but needed for baseline AJAX)
add_action( 'admin_enqueue_scripts', 'wp_static_exporter_enqueue_admin_scripts' );

?>
