<?php
/**
 * Removes everything the plugin created: the drop-ins copied to wp-content,
 * the content folder in uploads and the options stored in the database.
 *
 * Only drop-ins carrying the plugin marker are deleted. A db-error.php,
 * maintenance.php or php-error.php added by someone else is left in place.
 *
 * @package BeRightBack
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'db-error.php', 'maintenance.php', 'php-error.php' ) as $be_right_back_file ) {
	$be_right_back_path = WP_CONTENT_DIR . '/' . $be_right_back_file;

	if ( ! is_file( $be_right_back_path ) ) {
		continue;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the header of a local file.
	$be_right_back_head = file_get_contents( $be_right_back_path, false, null, 0, 2048 );

	if ( false !== $be_right_back_head && false !== strpos( $be_right_back_head, 'Be Right Back drop-in' ) ) {
		wp_delete_file( $be_right_back_path );
	}
}

// Content folder, in the uploads folder of the main site.
if ( is_multisite() && ! is_main_site() ) {
	switch_to_blog( get_main_site_id() );
	$be_right_back_uploads = wp_upload_dir( null, false );
	restore_current_blog();
} else {
	$be_right_back_uploads = wp_upload_dir( null, false );
}

$be_right_back_dir = trailingslashit( $be_right_back_uploads['basedir'] ) . 'be-right-back';

if ( is_dir( $be_right_back_dir ) ) {
	global $wp_filesystem;

	require_once ABSPATH . 'wp-admin/includes/file.php';

	if ( WP_Filesystem() && 'direct' === $wp_filesystem->method ) {
		$wp_filesystem->delete( $be_right_back_dir, true );
	}
}

foreach ( array( 'be_right_back_settings', 'be_right_back_version', 'be_right_back_logo_cache', 'be_right_back_refresh' ) as $be_right_back_option ) {
	delete_option( $be_right_back_option );
	delete_site_option( $be_right_back_option );
}
