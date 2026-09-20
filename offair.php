<?php
/**
 * Plugin Name:       Offair – Branded Error Pages
 * Plugin URI:        https://github.com/nolidev-fr/offair
 * Description:       Branded pages for the screens WordPress shows by itself when it breaks: database connection errors, fatal PHP errors and the update notice. Works even when WordPress cannot load.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Nolidev
 * Author URI:        https://nolidev.fr
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       offair
 * Domain Path:       /languages
 *
 * @package Offair
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'OFFAIR_VERSION' ) ) {
	return;
}

define( 'OFFAIR_VERSION', '1.0.0' );
define( 'OFFAIR_FILE', __FILE__ );
define( 'OFFAIR_DIR', plugin_dir_path( __FILE__ ) );
define( 'OFFAIR_URL', plugin_dir_url( __FILE__ ) );
define( 'OFFAIR_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Loads plugin classes from the includes directory on demand.
 *
 * Offair\Admin_Page maps to includes/class-admin-page.php.
 *
 * @param string $class_name Fully qualified class name.
 */
function offair_autoload( $class_name ) {
	$prefix = 'Offair\\';

	if ( 0 !== strpos( $class_name, $prefix ) ) {
		return;
	}

	$relative = substr( $class_name, strlen( $prefix ) );
	$file     = OFFAIR_DIR . 'includes/class-' . str_replace( '_', '-', strtolower( $relative ) ) . '.php';

	if ( is_readable( $file ) ) {
		require_once $file;
	}
}
spl_autoload_register( 'offair_autoload' );

/**
 * Returns the shared plugin instance.
 *
 * @return \Offair\Plugin
 */
function offair() {
	return \Offair\Plugin::instance();
}

register_activation_hook( __FILE__, array( 'Offair\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Offair\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', 'offair' );
