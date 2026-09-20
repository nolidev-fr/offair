<?php
/**
 * Page content saved for the drop-in.
 *
 * @package Offair
 */

namespace Offair;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the content of the three pages from the settings and saves it as
 * JSON in the uploads folder of the main site, where the drop-in reads it.
 *
 * The drop-in runs while the database is down, so it cannot read the
 * settings from the options table. The file only holds data (texts, colors,
 * the logo as a data URI): the drop-in escapes every value when it prints
 * the page.
 */
class Pages {

	/**
	 * Folder created in uploads, named after the plugin slug.
	 */
	const DIR_NAME = 'offair';

	/**
	 * Data file read by the drop-in.
	 */
	const FILE_NAME = 'pages.json';

	/**
	 * Format of the data file, raised when the structure changes.
	 */
	const FORMAT = 1;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Logo and colors.
	 *
	 * @var Branding
	 */
	private $branding;

	/**
	 * JSON built during the current request.
	 *
	 * @var string|null
	 */
	private $json = null;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 * @param Branding $branding Logo and colors.
	 */
	public function __construct( Settings $settings, Branding $branding ) {
		$this->settings = $settings;
		$this->branding = $branding;
	}

	/**
	 * Folder of the data file, in the uploads folder of the main site.
	 *
	 * @return string
	 */
	public function dir() {
		$switched = false;

		if ( is_multisite() && ! is_main_site() ) {
			switch_to_blog( get_main_site_id() );
			$switched = true;
		}

		$uploads = wp_upload_dir( null, false );

		if ( $switched ) {
			restore_current_blog();
		}

		return trailingslashit( $uploads['basedir'] ) . self::DIR_NAME;
	}

	/**
	 * Absolute path of the data file.
	 *
	 * @return string
	 */
	public function path() {
		return $this->dir() . '/' . self::FILE_NAME;
	}

	/**
	 * Whether the drop-in finds the data file where the plugin saves it.
	 *
	 * The drop-in cannot call wp_upload_dir(): it rebuilds the default path
	 * from WP_CONTENT_DIR and the UPLOADS constant. Only a custom upload path
	 * stored in the database (a legacy setting) makes the two differ.
	 *
	 * @return bool
	 */
	public function is_reachable() {
		$expected = defined( 'UPLOADS' ) ? ABSPATH . UPLOADS : WP_CONTENT_DIR . '/uploads';
		$expected = untrailingslashit( wp_normalize_path( $expected ) ) . '/' . self::DIR_NAME;

		return untrailingslashit( wp_normalize_path( $this->dir() ) ) === $expected;
	}

	/**
	 * Whether the data file can be written.
	 *
	 * @return bool
	 */
	public function is_writable() {
		$filesystem = Filesystem::get();
		$dir        = $this->dir();
		$target     = is_dir( $dir ) ? $dir : dirname( $dir );

		return null !== $filesystem && $filesystem->is_writable( $target );
	}

	/**
	 * Whether the saved file matches the current settings.
	 *
	 * @return bool
	 */
	public function is_current() {
		$path = $this->path();

		if ( ! is_readable( $path ) ) {
			return false;
		}

		return md5_file( $path ) === md5( $this->json() );
	}

	/**
	 * Forgets the JSON built during this request, after the settings change.
	 */
	public function flush() {
		$this->json = null;
	}

	/**
	 * Saves the data file.
	 *
	 * @return true|\WP_Error
	 */
	public function write() {
		$filesystem = Filesystem::get();
		$dir        = $this->dir();
		$error      = new \WP_Error(
			'offair_data_not_written',
			sprintf(
				/* translators: %s: folder path. */
				__( 'The page content could not be saved in %s. Check that the uploads folder is writable.', 'offair' ),
				$dir
			)
		);

		if ( null === $filesystem || ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) ) {
			return $error;
		}

		// Prevents the folder from being listed on servers that allow it.
		if ( ! file_exists( $dir . '/index.html' ) ) {
			$filesystem->put_contents( $dir . '/index.html', '', FS_CHMOD_FILE );
		}

		if ( ! $filesystem->put_contents( $this->path(), $this->json(), FS_CHMOD_FILE ) ) {
			return $error;
		}

		return true;
	}

	/**
	 * Deletes the folder and the data file.
	 */
	public function delete() {
		$filesystem = Filesystem::get();
		$dir        = $this->dir();

		if ( null !== $filesystem && is_dir( $dir ) ) {
			$filesystem->delete( $dir, true );
		}
	}

	/**
	 * Content of the data file.
	 *
	 * @return string
	 */
	public function json() {
		if ( null === $this->json ) {
			$this->json = wp_json_encode( $this->data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		}

		return $this->json;
	}

	/**
	 * Content of the three pages, in the language of the site.
	 *
	 * @return array
	 */
	public function data() {
		// The pages are written in the language of the site, not in the
		// language of the administrator who happens to save the settings.
		$switched = determine_locale() !== get_locale() && switch_to_locale( get_locale() );

		/**
		 * Filters the settings right before the page content is built. Empty
		 * texts have already been replaced by their translated defaults.
		 *
		 * @param array $settings Full settings array.
		 */
		$settings = apply_filters( 'offair_settings', $this->settings->resolve() );
		$general  = $settings['general'];
		$primary  = self::hex( $general['primary_color'], '#334155' );
		$timezone = wp_timezone_string();

		$site_name = trim( (string) $general['site_name'] );
		if ( '' === $site_name ) {
			$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		}

		$time_format = trim( (string) get_option( 'time_format', 'H:i' ) );
		$logo        = $this->branding->logo( $general['logo_id'] );

		$data = array(
			'format'       => self::FORMAT,
			'lang'         => get_bloginfo( 'language' ),
			'site_name'    => $site_name,
			'show_name'    => ! empty( $general['show_name'] ),
			'logo'         => $logo ? array(
				'src'    => $logo['src'],
				'width'  => (int) $logo['width'],
				'height' => (int) $logo['height'],
			) : null,
			'favicon'      => $this->branding->favicon(),
			'colors'       => array(
				'primary'       => $primary,
				'primary_hover' => self::shade( $primary, -0.15 ),
				'primary_text'  => self::readable_on_white( $primary ),
				'on_primary'    => self::on_color( $primary ),
				'background'    => self::hex( $general['background_color'], '#f5f4f0' ),
			),
			'heading_font' => $general['heading_font'],
			'ornament'     => $general['ornament'],
			'contact'      => self::contact_parts( $general['contact_line'] ),
			'timezone'     => $timezone,
			'time_format'  => '' === $time_format ? 'H:i' : $time_format,
			'screens'      => array(),
		);

		foreach ( Settings::SCREENS as $key ) {
			$screen = $settings[ $key ];
			$status = 'php' === $key && 500 === (int) $screen['status_code'] ? 500 : 503;

			$labels = array(
				'db'          => __( 'Database temporarily unavailable (HTTP 503).', 'offair' ),
				'maintenance' => __( 'Scheduled maintenance in progress (HTTP 503).', 'offair' ),
				/* translators: %d: HTTP status code. */
				'php'         => sprintf( __( 'Technical error (HTTP %d).', 'offair' ), $status ),
			);

			$meta = '';
			if ( ! empty( $screen['show_meta'] ) ) {
				$meta = sprintf(
					/* translators: 1: local time of the incident, filled in when the page is shown, 2: timezone name, 3: sentence describing the incident. */
					__( 'Incident recorded at %1$s (%2$s). %3$s', 'offair' ),
					'{time}',
					$timezone,
					$labels[ $key ]
				);
			}

			$data['screens'][ $key ] = array(
				'title'       => (string) $screen['title'],
				'paragraphs'  => self::paragraphs( $screen['message'] ),
				'button'      => empty( $screen['show_button'] ) ? '' : (string) $screen['button_label'],
				'refresh'     => (int) $screen['refresh_delay'],
				'retry_after' => (int) $screen['retry_after'],
				'status'      => $status,
				'meta'        => $meta,
				'notice'      => 'php' === $key ? __( 'Recovery mode is active: the extension that caused this error has been paused. Check the Plugins and Themes screens for details.', 'offair' ) : '',
			);
		}

		/**
		 * Filters the page content before it is saved for the drop-in.
		 *
		 * @param array $data     Page content.
		 * @param array $settings Settings it was built from.
		 */
		$data = apply_filters( 'offair_data', $data, $settings );

		if ( $switched ) {
			restore_previous_locale();
		}

		return $data;
	}

	/**
	 * Plain text split into paragraphs on blank lines.
	 *
	 * @param string $text Plain text.
	 * @return string[]
	 */
	public static function paragraphs( $text ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", trim( (string) $text ) );

		if ( '' === $text ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', preg_split( '/\n{2,}/', $text ) ), 'strlen' ) );
	}

	/**
	 * Plain text split into parts, email and web addresses carrying a link.
	 *
	 * @param string $text Plain text.
	 * @return array[] Each part has a text and, for links, an href.
	 */
	public static function contact_parts( $text ) {
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return array();
		}

		$pattern = '~(https?://[^\s<>"\']+|www\.[^\s<>"\']+|[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,})~i';
		$pieces  = preg_split( $pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		$parts   = array();

		foreach ( $pieces as $index => $piece ) {
			if ( 0 === $index % 2 ) {
				if ( '' !== $piece ) {
					$parts[] = array( 'text' => $piece );
				}
				continue;
			}

			$link   = rtrim( $piece, '.,;:!?)' );
			$suffix = substr( $piece, strlen( $link ) );

			if ( false !== strpos( $link, '@' ) && false === strpos( $link, '://' ) ) {
				$href = 'mailto:' . sanitize_email( $link );
			} elseif ( 0 === stripos( $link, 'www.' ) ) {
				$href = esc_url_raw( 'https://' . $link );
			} else {
				$href = esc_url_raw( $link );
			}

			$parts[] = '' === $href || 'mailto:' === $href ? array( 'text' => $link ) : array(
				'text' => $link,
				'href' => $href,
			);

			if ( '' !== $suffix ) {
				$parts[] = array( 'text' => $suffix );
			}
		}

		return $parts;
	}

	/**
	 * Six-digit lowercase hex color.
	 *
	 * @param string $color    Color from the settings.
	 * @param string $fallback Color used when the value is not valid.
	 * @return string
	 */
	public static function hex( $color, $fallback ) {
		$color = sanitize_hex_color( (string) $color );

		if ( ! $color ) {
			return $fallback;
		}

		if ( 4 === strlen( $color ) ) {
			$color = '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
		}

		return strtolower( $color );
	}

	/**
	 * Hex color to RGB channels.
	 *
	 * @param string $hex Six-digit hex color.
	 * @return int[] Red, green, blue.
	 */
	public static function hex_to_rgb( $hex ) {
		$hex = ltrim( self::hex( $hex, '#334155' ), '#' );

		return array(
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * Relative luminance as defined by WCAG 2.
	 *
	 * @param string $hex Hex color.
	 * @return float Between 0 and 1.
	 */
	public static function luminance( $hex ) {
		$channels = array();

		foreach ( self::hex_to_rgb( $hex ) as $value ) {
			$value      = $value / 255;
			$channels[] = $value <= 0.03928 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
		}

		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/**
	 * Text color that contrasts best with a background color.
	 *
	 * @param string $hex Background color.
	 * @return string White or near black.
	 */
	public static function on_color( $hex ) {
		return self::luminance( $hex ) > 0.215 ? '#1f2328' : '#ffffff';
	}

	/**
	 * Darkens a color until text in that color reads on white with a
	 * contrast ratio of at least 4.5, as WCAG AA asks. A pale brand color
	 * still drives the button, but the name, ornament and links use this one.
	 *
	 * @param string $hex Hex color.
	 * @return string Hex color.
	 */
	public static function readable_on_white( $hex ) {
		$color = self::hex( $hex, '#334155' );

		for ( $step = 0; $step < 12; $step++ ) {
			if ( 1.05 / ( self::luminance( $color ) + 0.05 ) >= 4.5 ) {
				break;
			}

			$color = self::shade( $color, -0.12 );
		}

		return $color;
	}

	/**
	 * Darkens (negative amount) or lightens (positive amount) a color.
	 *
	 * @param string $hex    Hex color.
	 * @param float  $amount Between -1 and 1.
	 * @return string Hex color.
	 */
	public static function shade( $hex, $amount ) {
		$rgb    = self::hex_to_rgb( $hex );
		$target = $amount < 0 ? 0 : 255;
		$amount = min( 1, abs( (float) $amount ) );

		foreach ( $rgb as $index => $value ) {
			$rgb[ $index ] = (int) round( $value + ( $target - $value ) * $amount );
		}

		return sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
	}
}
