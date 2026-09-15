<?php
/**
 * Drop-in files on disk: signature, detection and atomic writes.
 *
 * @package BeRightBack
 */

namespace BeRightBack;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the files in wp-content without ever touching a file the
 * plugin did not write itself.
 */
class Dropins {

	/**
	 * Text that identifies a file written by this plugin.
	 */
	const MARKER = 'Be Right Back drop-in';

	/**
	 * Managed drop-ins, keyed by screen.
	 *
	 * @return array<string, string> Screen key to file name.
	 */
	public static function files() {
		$files = array(
			'db'          => 'db-error.php',
			'maintenance' => 'maintenance.php',
			'php'         => 'php-error.php',
		);

		/**
		 * Filters the drop-ins managed by the plugin.
		 *
		 * @param array<string, string> $files Screen key to file name.
		 */
		return apply_filters( 'be_right_back_dropins', $files );
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
	 * Whether wp-content accepts new files.
	 *
	 * @return bool
	 */
	public function is_content_writable() {
		return wp_is_writable( WP_CONTENT_DIR );
	}

	/**
	 * What is on disk for a screen.
	 *
	 * @param string $key Screen key.
	 * @return array {
	 *     @type string      $file      File name.
	 *     @type string      $path      Absolute path.
	 *     @type bool        $exists    Whether a file is present.
	 *     @type bool        $ours      Whether the file carries the plugin signature.
	 *     @type string      $hash      Settings hash found in the file header.
	 *     @type string      $version   Plugin version found in the file header.
	 *     @type string      $generated Generation date found in the file header.
	 * }
	 */
	public function info( $key ) {
		$path = $this->path( $key );
		$info = array(
			'file'      => $this->file( $key ),
			'path'      => $path,
			'exists'    => is_file( $path ),
			'ours'      => false,
			'hash'      => '',
			'version'   => '',
			'generated' => '',
		);

		if ( ! $info['exists'] ) {
			return $info;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file header, read only.
		$head = file_get_contents( $path, false, null, 0, 2048 );

		if ( false === $head || false === strpos( $head, self::MARKER ) ) {
			return $info;
		}

		$info['ours'] = true;

		if ( preg_match( '/Settings hash: ([a-f0-9]{32})/', $head, $match ) ) {
			$info['hash'] = $match[1];
		}
		if ( preg_match( '/Plugin version: (\S+)/', $head, $match ) ) {
			$info['version'] = $match[1];
		}
		if ( preg_match( '/Generated on: (\S+)/', $head, $match ) ) {
			$info['generated'] = $match[1];
		}

		return $info;
	}

	/**
	 * Fingerprint of a compiled body, stored in the header so that changes to
	 * the settings, the logo or the templates can be detected.
	 *
	 * @param string $body Compiled body.
	 * @return string
	 */
	public function hash( $body ) {
		return md5( $body );
	}

	/**
	 * Signed header placed at the top of every generated file.
	 *
	 * @param string $file File name.
	 * @param string $hash Body hash.
	 * @return string
	 */
	public function header( $file, $hash ) {
		$lines = array(
			'<?php',
			'/**',
			' * ' . self::MARKER . ': ' . $file,
			' *',
			' * Written by the Be Right Back plugin from its settings. Do not edit',
			' * this file: it is rewritten whenever the settings change, and removed',
			' * when the plugin is deactivated or uninstalled.',
			' *',
			' * Plugin version: ' . BE_RIGHT_BACK_VERSION,
			' * Generated on: ' . gmdate( 'c' ),
			' * Settings hash: ' . $hash,
			' */',
		);

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Writes a drop-in atomically: temporary file first, then rename.
	 *
	 * @param string $key    Screen key.
	 * @param string $source Full file content.
	 * @return true|\WP_Error
	 */
	public function write( $key, $source ) {
		$path = $this->path( $key );
		$dir  = dirname( $path );

		if ( ! wp_is_writable( $dir ) ) {
			return new \WP_Error(
				'be_right_back_not_writable',
				/* translators: %s: directory path. */
				sprintf( __( 'The directory %s is not writable.', 'be-right-back' ), $dir )
			);
		}

		$temp = $path . '.' . wp_generate_password( 8, false, false ) . '.tmp';

		// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- Atomic write of a drop-in, WP_Filesystem cannot rename in place.
		$written = file_put_contents( $temp, $source, LOCK_EX );

		if ( false === $written || strlen( $source ) !== $written ) {
			@unlink( $temp );

			return new \WP_Error(
				'be_right_back_write_failed',
				/* translators: %s: file name. */
				sprintf( __( 'Could not write %s.', 'be-right-back' ), $this->file( $key ) )
			);
		}

		@chmod( $temp, $this->file_mode() );

		if ( ! @rename( $temp, $path ) ) {
			// Windows cannot rename over an existing file.
			@unlink( $path );

			if ( ! @rename( $temp, $path ) ) {
				@unlink( $temp );

				return new \WP_Error(
					'be_right_back_rename_failed',
					/* translators: %s: file name. */
					sprintf( __( 'Could not replace %s.', 'be-right-back' ), $this->file( $key ) )
				);
			}
		}
		// phpcs:enable

		$this->invalidate( $path );

		return true;
	}

	/**
	 * Removes a drop-in written by the plugin.
	 *
	 * @param string $key   Screen key.
	 * @param bool   $force Also remove a file the plugin did not write.
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
				sprintf( __( '%s was not written by Be Right Back and was left untouched.', 'be-right-back' ), $info['file'] )
			);
		}

		wp_delete_file( $info['path'] );

		if ( file_exists( $info['path'] ) ) {
			return new \WP_Error(
				'be_right_back_delete_failed',
				/* translators: %s: file name. */
				sprintf( __( 'Could not delete %s.', 'be-right-back' ), $info['file'] )
			);
		}

		$this->invalidate( $info['path'] );

		return true;
	}

	/**
	 * Permissions for new files, aligned with WordPress conventions.
	 *
	 * @return int
	 */
	private function file_mode() {
		if ( defined( 'FS_CHMOD_FILE' ) ) {
			return FS_CHMOD_FILE;
		}

		return ( fileperms( ABSPATH . 'index.php' ) & 0777 ) | 0644;
	}

	/**
	 * Makes sure PHP does not keep serving a stale copy from OPcache.
	 *
	 * @param string $path Absolute path.
	 */
	private function invalidate( $path ) {
		if ( function_exists( 'opcache_invalidate' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- opcache.restrict_api may forbid the call.
			@opcache_invalidate( $path, true );
		}
	}
}
