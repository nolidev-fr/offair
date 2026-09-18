<?php
/**
 * Drop-in files in wp-content.
 *
 * @package BeRightBack
 */

namespace BeRightBack;

defined( 'ABSPATH' ) || exit;

/**
 * Copies the static drop-in shipped with the plugin (dropins/drop-in.php)
 * to wp-content under the three names WordPress looks for. The file is
 * copied unchanged: no code is generated. A file the plugin did not copy
 * is never replaced or deleted without an explicit request.
 */
class Dropins {

	/**
	 * Text that identifies the plugin drop-in, found in its header comment.
	 */
	const MARKER = 'Be Right Back drop-in';

	/**
	 * Drop-ins managed by the plugin, keyed by screen.
	 *
	 * @return array<string, string> Screen key to file name.
	 */
	public static function files() {
		return array(
			'db'          => 'db-error.php',
			'maintenance' => 'maintenance.php',
			'php'         => 'php-error.php',
		);
	}

	/**
	 * Static drop-in shipped with the plugin.
	 *
	 * @return string
	 */
	public function source() {
		return BE_RIGHT_BACK_DIR . 'dropins/drop-in.php';
	}

	/**
	 * File name of a screen.
	 *
	 * @param string $key Screen key.
	 * @return string
	 */
	public function file( $key ) {
		$files = self::files();

		return isset( $files[ $key ] ) ? $files[ $key ] : '';
	}

	/**
	 * Absolute path of a drop-in.
	 *
	 * @param string $key Screen key.
	 * @return string
	 */
	public function path( $key ) {
		return trailingslashit( WP_CONTENT_DIR ) . $this->file( $key );
	}

	/**
	 * Whether the drop-ins can be copied without FTP credentials.
	 *
	 * @return bool
	 */
	public function is_writable() {
		$filesystem = Filesystem::get();

		return null !== $filesystem && $filesystem->is_writable( WP_CONTENT_DIR );
	}

	/**
	 * What is on disk for a screen.
	 *
	 * @param string $key Screen key.
	 * @return array {
	 *     @type string $file    File name.
	 *     @type string $path    Absolute path.
	 *     @type bool   $exists  Whether a file is present.
	 *     @type bool   $ours    Whether the file is the plugin drop-in, any version.
	 *     @type bool   $current Whether the file is identical to the drop-in of this version.
	 * }
	 */
	public function info( $key ) {
		$path = $this->path( $key );
		$info = array(
			'file'    => $this->file( $key ),
			'path'    => $path,
			'exists'  => is_file( $path ),
			'ours'    => false,
			'current' => false,
		);

		if ( ! $info['exists'] ) {
			return $info;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the header of a local file.
		$head = file_get_contents( $path, false, null, 0, 2048 );

		$info['ours']    = false !== $head && false !== strpos( $head, self::MARKER );
		$info['current'] = $info['ours'] && md5_file( $path ) === md5_file( $this->source() );

		return $info;
	}

	/**
	 * Copies the drop-in for a screen.
	 *
	 * @param string $key   Screen key.
	 * @param bool   $force Replace a file the plugin did not copy.
	 * @return true|\WP_Error
	 */
	public function install( $key, $force = false ) {
		$info = $this->info( $key );

		if ( $info['exists'] && ! $info['ours'] && ! $force ) {
			return new \WP_Error(
				'be_right_back_foreign',
				sprintf(
					/* translators: %s: file name. */
					__( 'wp-content/%s already exists and was not added by Be Right Back, so it was left untouched. Use the Advanced tab to replace it.', 'be-right-back' ),
					$info['file']
				)
			);
		}

		if ( $info['current'] ) {
			return true;
		}

		$filesystem = Filesystem::get();

		if ( null === $filesystem || ! $filesystem->copy( $this->source(), $info['path'], true, FS_CHMOD_FILE ) ) {
			return new \WP_Error(
				'be_right_back_copy_failed',
				sprintf(
					/* translators: %s: file name. */
					__( 'Could not copy wp-content/%s. Download it from the Advanced tab and upload it yourself.', 'be-right-back' ),
					$info['file']
				)
			);
		}

		$this->invalidate( $info['path'] );

		return true;
	}

	/**
	 * Removes the drop-in of a screen.
	 *
	 * @param string $key   Screen key.
	 * @param bool   $force Also remove a file the plugin did not copy.
	 * @return true|false|\WP_Error True when removed, false when there was nothing to remove.
	 */
	public function remove( $key, $force = false ) {
		$info = $this->info( $key );

		if ( ! $info['exists'] ) {
			return false;
		}

		if ( ! $info['ours'] && ! $force ) {
			return new \WP_Error(
				'be_right_back_foreign',
				/* translators: %s: file name. */
				sprintf( __( 'wp-content/%s was not added by Be Right Back and was left untouched.', 'be-right-back' ), $info['file'] )
			);
		}

		wp_delete_file( $info['path'] );

		if ( file_exists( $info['path'] ) ) {
			return new \WP_Error(
				'be_right_back_delete_failed',
				/* translators: %s: file name. */
				sprintf( __( 'Could not delete wp-content/%s.', 'be-right-back' ), $info['file'] )
			);
		}

		$this->invalidate( $info['path'] );

		return true;
	}

	/**
	 * Makes sure PHP does not keep serving a stale copy from OPcache.
	 *
	 * @param string $path Absolute path.
	 */
	private function invalidate( $path ) {
		// opcache.restrict_api may forbid the call, in which case OPcache revalidates on its own schedule.
		if ( function_exists( 'opcache_invalidate' ) && filter_var( ini_get( 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN ) && '' === (string) ini_get( 'opcache.restrict_api' ) ) {
			opcache_invalidate( $path, true );
		}
	}
}
