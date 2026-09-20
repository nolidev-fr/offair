<?php
/**
 * Removes everything the plugin created: the drop-ins copied to wp-content,
 * the content folder in uploads and the options stored in the database.
 *
 * Only drop-ins carrying the plugin marker are deleted. A db-error.php,
 * maintenance.php or php-error.php added by someone else is left in place.
 *
 * @package Offair
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'db-error.php', 'maintenance.php', 'php-error.php' ) as $offair_file ) {
	$offair_path = WP_CONTENT_DIR . '/' . $offair_file;

	if ( ! is_file( $offair_path ) ) {
		continue;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the header of a local file.
	$offair_head = file_get_contents( $offair_path, false, null, 0, 2048 );

	if ( false !== $offair_head && false !== strpos( $offair_head, 'Offair drop-in' ) ) {
		wp_delete_file( $offair_path );
	}
}

// Content folder, in the uploads folder of the main site.
if ( is_multisite() && ! is_main_site() ) {
	switch_to_blog( get_main_site_id() );
	$offair_uploads = wp_upload_dir( null, false );
	restore_current_blog();
} else {
	$offair_uploads = wp_upload_dir( null, false );
}

$offair_dir = trailingslashit( $offair_uploads['basedir'] ) . 'offair';

if ( is_dir( $offair_dir ) ) {
	global $wp_filesystem;

	require_once ABSPATH . 'wp-admin/includes/file.php';

	if ( WP_Filesystem() && 'direct' === $wp_filesystem->method ) {
		$wp_filesystem->delete( $offair_dir, true );
	}
}

foreach ( array( 'offair_settings', 'offair_version', 'offair_logo_cache', 'offair_refresh' ) as $offair_option ) {
	delete_option( $offair_option );
	delete_site_option( $offair_option );
}
