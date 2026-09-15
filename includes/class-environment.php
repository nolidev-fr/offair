<?php
/**
 * Checks on the PHP configuration that affect the pages.
 *
 * @package BeRightBack
 */

namespace BeRightBack;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress can only show an error page on a fatal error when nothing has
 * been sent to the browser yet. When PHP prints errors on screen and does
 * not buffer its output, the raw error goes out first, headers included,
 * and WordPress never loads wp-content/php-error.php. This class detects
 * that situation so the settings page and Site Health can explain it.
 */
class Environment {

	/**
	 * Whether PHP prints errors in the page output.
	 *
	 * WordPress sets display_errors from WP_DEBUG and WP_DEBUG_DISPLAY early
	 * in the request, so the runtime value is the one that matters.
	 *
	 * @return bool
	 */
	public static function displays_errors() {
		$value = strtolower( trim( (string) ini_get( 'display_errors' ) ) );

		return in_array( $value, array( '1', 'on', 'true', 'yes', 'stdout' ), true );
	}

	/**
	 * Whether PHP buffers its output (output_buffering directive).
	 *
	 * @return bool
	 */
	public static function buffers_output() {
		$value = strtolower( trim( (string) ini_get( 'output_buffering' ) ) );

		return ! in_array( $value, array( '', '0', 'off', 'false', 'no' ), true );
	}

	/**
	 * Whether WP_DEBUG_DISPLAY is the reason errors are printed.
	 *
	 * @return bool
	 */
	public static function debug_display_enabled() {
		return defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY;
	}

	/**
	 * Whether the PHP error page can never be shown with the current setup.
	 *
	 * @return bool
	 */
	public static function php_error_page_blocked() {
		return self::displays_errors() && ! self::buffers_output();
	}

	/**
	 * Plain text explanation of the problem and of the two ways to fix it.
	 *
	 * @return string[] Paragraphs.
	 */
	public static function php_error_page_explanation() {
		if ( self::debug_display_enabled() ) {
			$cause = __( 'PHP prints errors on screen because WP_DEBUG_DISPLAY is enabled in wp-config.php, and it does not buffer its output (the PHP directive output_buffering is off).', 'be-right-back' );
		} else {
			$cause = __( 'PHP prints errors on screen (the PHP directive display_errors is on) and does not buffer its output (the PHP directive output_buffering is off).', 'be-right-back' );
		}

		return array(
			$cause,
			__( 'On a fatal error, PHP therefore sends the raw error message and the HTTP headers before WordPress runs its error handler, so WordPress never loads wp-content/php-error.php. Visitors see the raw PHP error with an HTTP 200 status instead of this page. The database and maintenance pages are not affected.', 'be-right-back' ),
			__( 'To fix it, either disable WP_DEBUG_DISPLAY (recommended on a live site) or set output_buffering to 4096 in the PHP configuration (php.ini, .user.ini or the hosting panel).', 'be-right-back' ),
		);
	}

	/**
	 * Current values, for display.
	 *
	 * @return array<string, string>
	 */
	public static function php_error_page_values() {
		return array(
			'WP_DEBUG_DISPLAY' => self::debug_display_enabled() ? 'true' : 'false',
			'display_errors'   => (string) ini_get( 'display_errors' ),
			'output_buffering' => (string) ini_get( 'output_buffering' ),
		);
	}
}
