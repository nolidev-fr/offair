<?php
/**
 * Compiles the templates into standalone drop-in files.
 *
 * @package BeRightBack
 */

namespace BeRightBack;

defined( 'ABSPATH' ) || exit;

/**
 * Turns settings plus templates into PHP files that need nothing from
 * WordPress to run: no functions, no database, no external asset.
 *
 * A generated file has three parts: the signed header (see Dropins), a short
 * PHP prologue that sends the HTTP headers and computes the few values that
 * depend on the request, and the HTML rendered from the templates with all
 * texts already escaped.
 */
class Generator {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Files on disk.
	 *
	 * @var Dropins
	 */
	private $dropins;

	/**
	 * Logo and colors.
	 *
	 * @var Branding
	 */
	private $branding;

	/**
	 * Compiled bodies for the current request, keyed by screen.
	 *
	 * @var array<string, string>
	 */
	private $bodies = array();

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 * @param Dropins  $dropins  Files on disk.
	 * @param Branding $branding Logo and colors.
	 */
	public function __construct( Settings $settings, Dropins $dropins, Branding $branding ) {
		$this->settings = $settings;
		$this->dropins  = $dropins;
		$this->branding = $branding;
	}

	/**
	 * State of a drop-in compared to the current settings.
	 *
	 * @param string $key Screen key.
	 * @return string One of current, stale, missing, foreign, off.
	 */
	public function state( $key ) {
		$info     = $this->dropins->info( $key );
		$settings = $this->settings->get();
		$enabled  = ! empty( $settings[ $key ]['enabled'] );

		if ( $info['exists'] && ! $info['ours'] ) {
			return 'foreign';
		}
		if ( ! $info['exists'] ) {
			return $enabled ? 'missing' : 'off';
		}
		if ( ! $enabled ) {
			return 'stale';
		}

		return $info['hash'] === $this->hash( $key ) ? 'current' : 'stale';
	}

	/**
	 * States of every drop-in.
	 *
	 * @return array<string, string>
	 */
	public function states() {
		$states = array();

		foreach ( array_keys( Dropins::files() ) as $key ) {
			$states[ $key ] = $this->state( $key );
		}

		return $states;
	}

	/**
	 * Human readable label of a state.
	 *
	 * @param string $state State key.
	 * @return string
	 */
	public static function state_label( $state ) {
		$labels = array(
			'current' => __( 'Up to date', 'be-right-back' ),
			'stale'   => __( 'Needs regeneration', 'be-right-back' ),
			'missing' => __( 'Missing', 'be-right-back' ),
			'foreign' => __( 'Not written by this plugin', 'be-right-back' ),
			'off'     => __( 'Disabled', 'be-right-back' ),
		);

		return isset( $labels[ $state ] ) ? $labels[ $state ] : $state;
	}

	/**
	 * Hash of the body a screen would have if generated now.
	 *
	 * @param string $key Screen key.
	 * @return string
	 */
	public function hash( $key ) {
		return $this->dropins->hash( $this->body( $key ) );
	}

	/**
	 * Complete file content: signed header plus body.
	 *
	 * @param string $key Screen key.
	 * @return string
	 */
	public function source( $key ) {
		$body = $this->body( $key );

		return $this->dropins->header( $this->dropins->file( $key ), $this->dropins->hash( $body ) ) . $body;
	}

	/**
	 * Prologue plus HTML, without the header. Deterministic for a given set
	 * of settings, which is what makes the hash comparison meaningful.
	 *
	 * @param string $key Screen key.
	 * @return string
	 */
	public function body( $key ) {
		if ( isset( $this->bodies[ $key ] ) ) {
			return $this->bodies[ $key ];
		}

		// Pages are written in the language of the site, not in the language
		// of the administrator who happens to save the settings.
		$switched = determine_locale() !== get_locale() && switch_to_locale( get_locale() );

		/**
		 * Filters the settings right before a page is compiled. Empty texts
		 * have already been replaced by their translated defaults.
		 *
		 * @param array  $settings Full settings array.
		 * @param string $key      Screen being compiled: db, maintenance or php.
		 */
		$settings = apply_filters( 'be_right_back_settings', $this->settings->resolve(), $key );

		$args = $this->template_vars( $key, $settings );
		$html = $this->render( $key, $args );

		/**
		 * Filters the HTML of a page after the template has been rendered.
		 *
		 * @param string $html HTML document.
		 * @param string $key  Screen: db, maintenance or php.
		 * @param array  $args Template variables.
		 */
		$html = apply_filters( 'be_right_back_dropin_html', $html, $key, $args );

		$this->bodies[ $key ] = $this->prologue( $key, $settings ) . $html;

		if ( $switched ) {
			restore_previous_locale();
		}

		return $this->bodies[ $key ];
	}

	/**
	 * Forgets compiled bodies, to be called after the settings change.
	 */
	public function flush() {
		$this->bodies = array();
	}

	/**
	 * Writes one drop-in, or removes it when its screen is disabled.
	 *
	 * @param string $key   Screen key.
	 * @param bool   $force Replace a file the plugin did not write.
	 * @return true|\WP_Error
	 */
	public function generate( $key, $force = false ) {
		$settings = $this->settings->get();
		$info     = $this->dropins->info( $key );

		if ( empty( $settings[ $key ]['enabled'] ) ) {
			if ( $info['exists'] && $info['ours'] ) {
				$removed = $this->dropins->remove( $key );

				return is_wp_error( $removed ) ? $removed : true;
			}

			return true;
		}

		if ( $info['exists'] && ! $info['ours'] && ! $force ) {
			return new \WP_Error(
				'be_right_back_foreign',
				sprintf(
					/* translators: %s: file name. */
					__( 'wp-content/%s already exists and was not written by Be Right Back, so it was left untouched. Use the Advanced tab to replace it.', 'be-right-back' ),
					$info['file']
				)
			);
		}

		return $this->dropins->write( $key, $this->source( $key ) );
	}

	/**
	 * Writes every drop-in.
	 *
	 * @param bool $force Replace files the plugin did not write.
	 * @return array<string, true|\WP_Error> Result per screen.
	 */
	public function generate_all( $force = false ) {
		$this->flush();

		$results = array();

		foreach ( array_keys( Dropins::files() ) as $key ) {
			$results[ $key ] = $this->generate( $key, $force );
		}

		return $results;
	}

	/**
	 * Removes every drop-in written by the plugin.
	 *
	 * @param bool $force Also remove files the plugin did not write.
	 * @return array<string, true|false|\WP_Error> Result per screen.
	 */
	public function remove_all( $force = false ) {
		$results = array();

		foreach ( array_keys( Dropins::files() ) as $key ) {
			$results[ $key ] = $this->dropins->remove( $key, $force );
		}

		return $results;
	}

	/**
	 * Variables handed to the templates.
	 *
	 * @param string $key      Screen key.
	 * @param array  $settings Settings.
	 * @return array
	 */
	public function template_vars( $key, array $settings ) {
		$general = $settings['general'];
		$screen  = $settings[ $key ];
		$status  = $this->status_code( $key, $settings );
		$primary = $general['primary_color'];

		$site_name = trim( (string) $general['site_name'] );
		if ( '' === $site_name ) {
			$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		}

		$status_labels = array(
			'db'          => __( 'Database temporarily unavailable (HTTP 503).', 'be-right-back' ),
			'maintenance' => __( 'Scheduled maintenance in progress (HTTP 503).', 'be-right-back' ),
			/* translators: %d: HTTP status code. */
			'php'         => sprintf( __( 'Technical error (HTTP %d).', 'be-right-back' ), $status ),
		);

		$args = array(
			'screen'        => $key,
			'lang'          => get_bloginfo( 'language' ),
			'site_name'     => $site_name,
			'title'         => (string) $screen['title'],
			'message_html'  => self::paragraphs( $screen['message'] ),
			'button_label'  => empty( $screen['show_button'] ) ? '' : (string) $screen['button_label'],
			'contact_html'  => self::autolink( $general['contact_line'] ),
			'logo'          => $this->branding->logo( $general['logo_id'] ),
			'favicon'       => $this->branding->favicon(),
			'colors'        => array(
				'primary'       => $primary,
				'primary_hover' => self::shade( $primary, -0.15 ),
				'primary_text'  => self::readable_on_white( $primary ),
				'on_primary'    => self::on_color( $primary ),
				'background'    => $general['background_color'],
				'shadow'        => self::rgba( $primary, 0.12 ),
			),
			'heading_font'  => $general['heading_font'],
			'ornament'      => $general['ornament'],
			'refresh_delay' => (int) $screen['refresh_delay'],
			'show_meta'     => ! empty( $screen['show_meta'] ),
			'status_code'   => $status,
			'status_label'  => isset( $status_labels[ $key ] ) ? $status_labels[ $key ] : $status_labels['php'],
			'timezone'      => wp_timezone_string(),
			'runtime'       => $this->runtime( $key ),
			'partials'      => BE_RIGHT_BACK_DIR . 'templates/partials/',
		);

		/**
		 * Filters the variables handed to the templates.
		 *
		 * @param array  $args     Template variables.
		 * @param string $key      Screen: db, maintenance or php.
		 * @param array  $settings Settings.
		 */
		return apply_filters( 'be_right_back_template_vars', $args, $key, $settings );
	}

	/**
	 * HTTP status a screen answers with.
	 *
	 * @param string $key      Screen key.
	 * @param array  $settings Settings.
	 * @return int
	 */
	private function status_code( $key, array $settings ) {
		if ( 'php' === $key && isset( $settings['php']['status_code'] ) ) {
			return (int) $settings['php']['status_code'];
		}

		return 503;
	}

	/**
	 * Renders a template with output buffering.
	 *
	 * @param string $key  Screen key.
	 * @param array  $args Template variables, available as $args in the template.
	 * @return string
	 */
	private function render( $key, array $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $args is read by the included template.
		$template = BE_RIGHT_BACK_DIR . 'templates/' . $this->dropins->file( $key );

		if ( ! is_readable( $template ) ) {
			return '';
		}

		ob_start();
		include $template;

		return (string) ob_get_clean();
	}

	/**
	 * PHP snippets the templates place where a request-time value belongs.
	 * They are written verbatim into the generated file.
	 *
	 * @param string $key Screen key.
	 * @return array<string, string>
	 */
	private function runtime( $key ) {
		$runtime = array(
			'time'   => "<?php echo htmlspecialchars( \$be_right_back_time, ENT_QUOTES, 'UTF-8' ); ?>",
			'href'   => '<?php echo $be_right_back_href; ?>',
			'detail' => '',
			'notice' => '',
		);

		if ( 'php' === $key ) {
			$runtime['notice'] = "<?php if ( '' !== \$be_right_back_notice ) : ?>\n"
				. "\t<p class=\"brb-notice\"><?php echo htmlspecialchars( \$be_right_back_notice, ENT_QUOTES, 'UTF-8' ); ?></p>\n"
				. "\t<?php endif; ?>";
			$runtime['detail'] = "<?php if ( '' !== \$be_right_back_detail ) : ?>\n"
				. "\t<pre class=\"brb-detail\"><?php echo htmlspecialchars( \$be_right_back_detail, ENT_QUOTES, 'UTF-8' ); ?></pre>\n"
				. "\t<?php endif; ?>";
		}

		return $runtime;
	}

	/**
	 * PHP code placed before the HTML: HTTP headers and request-time values.
	 *
	 * Only native PHP is used. Values coming from the settings are embedded
	 * with var_export so they are always valid PHP literals.
	 *
	 * @param string $key      Screen key.
	 * @param array  $settings Settings.
	 * @return string
	 */
	private function prologue( $key, array $settings ) {
		// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- var_export turns settings into valid PHP literals for the generated file.
		$screen      = $settings[ $key ];
		$status      = $this->status_code( $key, $settings );
		$reason      = 503 === $status ? 'Service Unavailable' : 'Internal Server Error';
		$retry_after = (int) $screen['retry_after'];
		$timezone    = wp_timezone_string();
		$time_format = trim( (string) get_option( 'time_format', 'H:i' ) );
		$time_format = '' === $time_format ? 'H:i' : $time_format;

		$code   = array();
		$code[] = '';

		if ( 'php' === $key ) {
			$code[] = '// Discard partial output left by the failing request so the page renders cleanly.';
			$code[] = 'while ( ob_get_level() > 0 ) {';
			$code[] = "\tif ( ! @ob_end_clean() ) {";
			$code[] = "\t\tbreak;";
			$code[] = "\t}";
			$code[] = '}';
			$code[] = '';
		}

		$code[] = 'if ( ! headers_sent() ) {';
		$code[] = "\t\$be_right_back_protocol = 'HTTP/1.0';";
		$code[] = "\tif ( isset( \$_SERVER['SERVER_PROTOCOL'] ) && in_array( \$_SERVER['SERVER_PROTOCOL'], array( 'HTTP/1.1', 'HTTP/2', 'HTTP/2.0', 'HTTP/3' ), true ) ) {";
		$code[] = "\t\t\$be_right_back_protocol = \$_SERVER['SERVER_PROTOCOL'];";
		$code[] = "\t}";
		$code[] = sprintf( "\theader( \$be_right_back_protocol . ' %d %s', true, %d );", $status, $reason, $status );
		if ( 503 === $status && $retry_after > 0 ) {
			$code[] = sprintf( "\theader( 'Retry-After: %d' );", $retry_after );
		}
		$code[] = "\theader( 'Content-Type: text/html; charset=UTF-8' );";
		$code[] = "\theader( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );";
		$code[] = "\theader( 'Pragma: no-cache' );";
		$code[] = "\theader( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT' );";
		$code[] = "\theader( 'X-LiteSpeed-Cache-Control: no-cache' );";
		$code[] = "\theader( 'X-Robots-Tag: noindex, nofollow' );";
		$code[] = '}';
		$code[] = '';
		$code[] = '// Local time of the incident, in the timezone the site used when this file was generated.';
		$code[] = '$be_right_back_time = gmdate( ' . var_export( $time_format, true ) . " ) . ' UTC';";
		$code[] = 'try {';
		$code[] = "\t\$be_right_back_now  = new DateTime( 'now', new DateTimeZone( " . var_export( $timezone, true ) . ' ) );';
		$code[] = "\t\$be_right_back_time = \$be_right_back_now->format( " . var_export( $time_format, true ) . ' );';
		$code[] = '} catch ( Exception $be_right_back_exception ) {';
		$code[] = "\t// The UTC fallback above is kept.";
		$code[] = '}';
		$code[] = '';
		$code[] = '// The retry link points back to the page that was requested.';
		$code[] = "\$be_right_back_href = isset( \$_SERVER['REQUEST_URI'] ) ? (string) \$_SERVER['REQUEST_URI'] : '/';";
		$code[] = "if ( '' === \$be_right_back_href || '/' !== \$be_right_back_href[0] || 0 === strpos( \$be_right_back_href, '//' ) ) {";
		$code[] = "\t\$be_right_back_href = '/';";
		$code[] = '}';
		$code[] = "\$be_right_back_href = htmlspecialchars( \$be_right_back_href, ENT_QUOTES, 'UTF-8' );";

		if ( 'php' === $key ) {
			$notice = __( 'Recovery mode is active: the extension that caused this error has been paused. Check the Plugins and Themes screens for details.', 'be-right-back' );

			$code[] = '';
			$code[] = '// Technical details are shown only when WP_DEBUG_DISPLAY is enabled.';
			$code[] = "\$be_right_back_detail = '';";
			$code[] = "if ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY && isset( \$error ) && is_array( \$error ) ) {";
			$code[] = "\t\$be_right_back_detail = isset( \$error['message'] ) ? trim( (string) \$error['message'] ) : '';";
			$code[] = "\tif ( isset( \$error['file'] ) ) {";
			$code[] = "\t\t\$be_right_back_detail .= \"\\n\" . \$error['file'] . ( isset( \$error['line'] ) ? ':' . (int) \$error['line'] : '' );";
			$code[] = "\t}";
			$code[] = "\t\$be_right_back_detail = trim( \$be_right_back_detail );";
			$code[] = '}';
			$code[] = '';
			$code[] = '// An administrator browsing in recovery mode is told what just happened.';
			$code[] = "\$be_right_back_notice = '';";
			$code[] = "if ( isset( \$handled ) && true === \$handled && function_exists( 'wp_is_recovery_mode' ) && wp_is_recovery_mode() ) {";
			$code[] = "\t\$be_right_back_notice = " . var_export( $notice, true ) . ';';
			$code[] = '}';
		}

		$code[] = '?>';
		// phpcs:enable

		return implode( "\n", $code ) . "\n";
	}

	/**
	 * Plain text with blank lines into escaped paragraphs.
	 *
	 * @param string $text Plain text.
	 * @return string HTML made of p and br elements only.
	 */
	public static function paragraphs( $text ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", trim( (string) $text ) );

		if ( '' === $text ) {
			return '';
		}

		$html = '';

		foreach ( preg_split( '/\n{2,}/', $text ) as $paragraph ) {
			$paragraph = trim( $paragraph );

			if ( '' !== $paragraph ) {
				$html .= '<p>' . nl2br( esc_html( $paragraph ) ) . '</p>' . "\n";
			}
		}

		return rtrim( $html );
	}

	/**
	 * Plain text with email addresses and web addresses turned into links.
	 *
	 * @param string $text Plain text.
	 * @return string HTML made of text and a elements only.
	 */
	public static function autolink( $text ) {
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return '';
		}

		$pattern = '~(https?://[^\s<>"\']+|www\.[^\s<>"\']+|[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,})~i';
		$parts   = preg_split( $pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		$html    = '';

		foreach ( $parts as $index => $part ) {
			if ( 0 === $index % 2 ) {
				$html .= esc_html( $part );
				continue;
			}

			$link   = rtrim( $part, '.,;:!?)' );
			$suffix = substr( $part, strlen( $link ) );

			if ( false !== strpos( $link, '@' ) && false === strpos( $link, '://' ) ) {
				$href = 'mailto:' . $link;
			} elseif ( 0 === stripos( $link, 'www.' ) ) {
				$href = 'https://' . $link;
			} else {
				$href = $link;
			}

			$html .= '<a href="' . esc_url( $href ) . '">' . esc_html( $link ) . '</a>' . esc_html( $suffix );
		}

		return $html;
	}

	/**
	 * Hex color to RGB channels.
	 *
	 * @param string $hex Hex color with or without #.
	 * @return int[] Red, green, blue.
	 */
	public static function hex_to_rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return array( 51, 65, 85 );
		}

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
	 * Darkens a color step by step until text in that color reads on white
	 * with a contrast ratio of at least 4.5, as WCAG AA asks. A pale brand
	 * color still drives the button, but the site name, the ornament and
	 * the links use this variant.
	 *
	 * @param string $hex Hex color.
	 * @return string Hex color.
	 */
	public static function readable_on_white( $hex ) {
		$color = $hex;

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

	/**
	 * CSS rgba() notation for a hex color.
	 *
	 * @param string $hex   Hex color.
	 * @param float  $alpha Opacity between 0 and 1.
	 * @return string
	 */
	public static function rgba( $hex, $alpha ) {
		list( $red, $green, $blue ) = self::hex_to_rgb( $hex );

		return sprintf( 'rgba(%d, %d, %d, %s)', $red, $green, $blue, number_format( (float) $alpha, 2, '.', '' ) );
	}
}
