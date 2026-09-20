<?php
/**
 * Access to the WordPress filesystem API.
 *
 * @package Offair
 */

namespace Offair;

defined( 'ABSPATH' ) || exit;

/**
 * Returns the WordPress filesystem object when files can be written
 * directly, without asking the administrator for FTP credentials.
 */
class Filesystem {

	/**
	 * Filesystem object, or null when direct access is not available.
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	public static function get() {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base && ! WP_Filesystem() ) {
			return null;
		}

		// Paths handled by the plugin are local paths, which only the direct method understands.
		return 'direct' === $wp_filesystem->method ? $wp_filesystem : null;
	}
}
