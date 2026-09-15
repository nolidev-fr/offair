<?php
/**
 * Plugin Name:       Be Right Back
 * Plugin URI:        https://github.com/nolidev-fr/be-right-back
 * Description:       Friendly branded pages when your site is down: database errors, fatal errors and maintenance mode. Works even when WordPress cannot load.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Nolidev
 * Author URI:        https://nolidev.fr
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       be-right-back
 * Domain Path:       /languages
 *
 * @package BeRightBack
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'BE_RIGHT_BACK_VERSION' ) ) {
	return;
}

define( 'BE_RIGHT_BACK_VERSION', '0.1.0' );
define( 'BE_RIGHT_BACK_FILE', __FILE__ );
define( 'BE_RIGHT_BACK_DIR', plugin_dir_path( __FILE__ ) );
define( 'BE_RIGHT_BACK_URL', plugin_dir_url( __FILE__ ) );
define( 'BE_RIGHT_BACK_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Loads plugin classes from the includes directory on demand.
 *
 * BeRightBack\Admin_Page maps to includes/class-admin-page.php.
 *
 * @param string $class_name Fully qualified class name.
 */
function be_right_back_autoload( $class_name ) {
	$prefix = 'BeRightBack\\';

	if ( 0 !== strpos( $class_name, $prefix ) ) {
		return;
	}

	$relative = substr( $class_name, strlen( $prefix ) );
	$file     = BE_RIGHT_BACK_DIR . 'includes/class-' . str_replace( '_', '-', strtolower( $relative ) ) . '.php';

	if ( is_readable( $file ) ) {
		require_once $file;
	}
}
spl_autoload_register( 'be_right_back_autoload' );

/**
 * Returns the shared plugin instance.
 *
 * @return \BeRightBack\Plugin
 */
function be_right_back() {
	return \BeRightBack\Plugin::instance();
}

register_activation_hook( __FILE__, array( 'BeRightBack\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BeRightBack\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', 'be_right_back' );
