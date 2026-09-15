<?php
/**
 * Removes everything the plugin created: the drop-ins written to wp-content
 * and the options stored in the database.
 *
 * Only files carrying the plugin signature are deleted. A db-error.php,
 * maintenance.php or php-error.php written by someone else is left in place.
 *
 * @package BeRightBack
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$be_right_back_files = array( 'db-error.php', 'maintenance.php', 'php-error.php' );

foreach ( $be_right_back_files as $be_right_back_file ) {
	$be_right_back_path = WP_CONTENT_DIR . '/' . $be_right_back_file;

	if ( ! is_file( $be_right_back_path ) ) {
		continue;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local file header, no WP_Filesystem needed.
	$be_right_back_head = file_get_contents( $be_right_back_path, false, null, 0, 2048 );

	if ( false === $be_right_back_head || false === strpos( $be_right_back_head, 'Be Right Back drop-in' ) ) {
		continue;
	}

	wp_delete_file( $be_right_back_path );

	if ( function_exists( 'opcache_invalidate' ) ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- opcache.restrict_api may forbid the call.
		@opcache_invalidate( $be_right_back_path, true );
	}
}

$be_right_back_options = array(
	'be_right_back_settings',
	'be_right_back_version',
	'be_right_back_logo_cache',
);

foreach ( $be_right_back_options as $be_right_back_option ) {
	delete_option( $be_right_back_option );
	delete_site_option( $be_right_back_option );
}
