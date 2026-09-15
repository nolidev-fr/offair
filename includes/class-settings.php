<?php
/**
 * Settings storage, defaults and validation.
 *
 * @package BeRightBack
 */

namespace BeRightBack;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the single settings option.
 *
 * On multisite the drop-ins are shared by every site, so the settings live
 * in the network options and are managed from the network admin.
 */
class Settings {

	const OPTION         = 'be_right_back_settings';
	const VERSION_OPTION = 'be_right_back_version';
	const LOGO_CACHE     = 'be_right_back_logo_cache';

	/**
	 * Screen keys, in display order.
	 */
	const SCREENS = array( 'db', 'maintenance', 'php' );

	/**
	 * Capability required to manage the plugin.
	 *
	 * @return string
	 */
	public static function capability() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * Reads an option from the right scope.
	 *
	 * @param string $key           Option name.
	 * @param mixed  $default_value Value when the option does not exist.
	 * @return mixed
	 */
	public static function get_option( $key, $default_value = false ) {
		return is_multisite() ? get_site_option( $key, $default_value ) : get_option( $key, $default_value );
	}

	/**
	 * Writes an option to the right scope.
	 *
	 * @param string $key      Option name.
	 * @param mixed  $value    Value.
	 * @param bool   $autoload Whether to autoload (single site only).
	 * @return bool
	 */
	public static function update_option( $key, $value, $autoload = true ) {
		if ( is_multisite() ) {
			return update_site_option( $key, $value );
		}

		return update_option( $key, $value, $autoload );
	}

	/**
	 * Deletes an option from the right scope.
	 *
	 * @param string $key Option name.
	 * @return bool
	 */
	public static function delete_option( $key ) {
		return is_multisite() ? delete_site_option( $key ) : delete_option( $key );
	}

	/**
	 * Human readable label of each screen.
	 *
	 * @return array<string, string>
	 */
	public static function screen_labels() {
		return array(
			'db'          => __( 'Database error', 'be-right-back' ),
			'maintenance' => __( 'Maintenance', 'be-right-back' ),
			'php'         => __( 'PHP error', 'be-right-back' ),
		);
	}

	/**
	 * Default settings. Texts are translated at the time the pages are
	 * generated, so a French site gets French pages once the language pack
	 * is installed.
	 *
	 * @return array
	 */
	public function defaults() {
		$button = __( 'Try again', 'be-right-back' );

		return array(
			'general'     => array(
				'logo_id'          => 0,
				'site_name'        => '',
				'primary_color'    => '#334155',
				'background_color' => '#f5f4f0',
				'heading_font'     => 'serif',
				'ornament'         => 'wave',
				'contact_line'     => '',
			),
			'db'          => array(
				'enabled'       => true,
				'title'         => __( "We'll be right back", 'be-right-back' ),
				'message'       => __( "Our site is taking a short break because of a technical problem. It usually comes back within a few minutes.\n\nThis page refreshes on its own. Thank you for your patience.", 'be-right-back' ),
				'button_label'  => $button,
				'refresh_delay' => 60,
				'retry_after'   => 300,
			),
			'maintenance' => array(
				'enabled'       => true,
				'title'         => __( 'Back in a minute', 'be-right-back' ),
				'message'       => __( "We are installing an update. The site will be back in a moment.\n\nThis page refreshes on its own. Thank you for your patience.", 'be-right-back' ),
				'button_label'  => $button,
				'refresh_delay' => 60,
				'retry_after'   => 300,
			),
			'php'         => array(
				'enabled'       => true,
				'title'         => __( 'Something went wrong', 'be-right-back' ),
				'message'       => __( "A technical error prevents us from displaying this page right now. Please try again in a few minutes.\n\nThank you for your patience.", 'be-right-back' ),
				'button_label'  => $button,
				'refresh_delay' => 60,
				'retry_after'   => 300,
				'status_code'   => 503,
			),
		);
	}

	/**
	 * Current settings merged over the defaults.
	 *
	 * @return array
	 */
	public function get() {
		$defaults = $this->defaults();
		$stored   = self::get_option( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$merged   = array();

		foreach ( $defaults as $section => $values ) {
			$section_values     = isset( $stored[ $section ] ) && is_array( $stored[ $section ] ) ? $stored[ $section ] : array();
			$merged[ $section ] = wp_parse_args( $section_values, $values );
		}

		return $merged;
	}

	/**
	 * Validates and saves user input.
	 *
	 * @param array $input Raw input, unslashed.
	 * @return array Clean settings as saved.
	 */
	public function update( array $input ) {
		$clean = $this->sanitize( $input );

		self::update_option( self::OPTION, $clean );

		return $clean;
	}

	/**
	 * Creates the option on first activation, pre-filled with the site logo
	 * and primary color when they can be detected.
	 *
	 * @param Branding $branding Branding detector.
	 */
	public function seed_defaults( Branding $branding ) {
		if ( false !== self::get_option( self::OPTION, false ) ) {
			return;
		}

		$settings = $this->defaults();

		$settings['general']['logo_id'] = $branding->detect_logo_id();

		$color = $branding->detect_primary_color();
		if ( '' !== $color ) {
			$settings['general']['primary_color'] = $color;
		}

		self::update_option( self::OPTION, $settings );
	}

	/**
	 * Sanitizes a (possibly partial) input array. Fields that are absent from
	 * the input keep their current value, so partial updates are safe.
	 *
	 * @param array      $input   Raw input, unslashed.
	 * @param array|null $current Settings to start from, defaults to the saved ones.
	 * @return array
	 */
	public function sanitize( array $input, $current = null ) {
		$defaults = $this->defaults();
		$current  = is_array( $current ) ? $current : $this->get();
		$clean    = $current;

		if ( isset( $input['general'] ) && is_array( $input['general'] ) ) {
			$general = $input['general'];
			$section = &$clean['general'];

			if ( isset( $general['logo_id'] ) ) {
				$section['logo_id'] = $this->sanitize_logo_id( $general['logo_id'] );
			}
			if ( isset( $general['site_name'] ) ) {
				$section['site_name'] = sanitize_text_field( $general['site_name'] );
			}
			foreach ( array( 'primary_color', 'background_color' ) as $color_key ) {
				if ( isset( $general[ $color_key ] ) ) {
					$color                 = sanitize_hex_color( trim( (string) $general[ $color_key ] ) );
					$section[ $color_key ] = $color ? $color : $defaults['general'][ $color_key ];
				}
			}
			if ( isset( $general['heading_font'] ) ) {
				$section['heading_font'] = in_array( $general['heading_font'], array( 'serif', 'sans' ), true ) ? $general['heading_font'] : $defaults['general']['heading_font'];
			}
			if ( isset( $general['ornament'] ) ) {
				$section['ornament'] = in_array( $general['ornament'], array( 'wave', 'line', 'none' ), true ) ? $general['ornament'] : $defaults['general']['ornament'];
			}
			if ( isset( $general['contact_line'] ) ) {
				$section['contact_line'] = sanitize_text_field( $general['contact_line'] );
			}
			unset( $section );
		}

		foreach ( self::SCREENS as $key ) {
			if ( ! isset( $input[ $key ] ) || ! is_array( $input[ $key ] ) ) {
				continue;
			}

			$screen  = $input[ $key ];
			$section = &$clean[ $key ];

			$section['enabled'] = ! empty( $screen['enabled'] );

			if ( isset( $screen['title'] ) ) {
				$section['title'] = sanitize_text_field( $screen['title'] );
			}
			if ( isset( $screen['message'] ) ) {
				$section['message'] = sanitize_textarea_field( $screen['message'] );
			}
			if ( isset( $screen['button_label'] ) ) {
				$section['button_label'] = sanitize_text_field( $screen['button_label'] );
			}
			if ( isset( $screen['refresh_delay'] ) ) {
				$section['refresh_delay'] = min( 3600, absint( $screen['refresh_delay'] ) );
			}
			if ( isset( $screen['retry_after'] ) ) {
				$section['retry_after'] = min( 86400, absint( $screen['retry_after'] ) );
			}
			if ( 'php' === $key && isset( $screen['status_code'] ) ) {
				$status                 = (int) $screen['status_code'];
				$section['status_code'] = in_array( $status, array( 500, 503 ), true ) ? $status : 503;
			}
			unset( $section );
		}

		return $clean;
	}

	/**
	 * Accepts an attachment ID only when it points to an image.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private function sanitize_logo_id( $value ) {
		$id = absint( $value );

		if ( 0 === $id || 'attachment' !== get_post_type( $id ) ) {
			return 0;
		}

		$mime = (string) get_post_mime_type( $id );

		return 0 === strpos( $mime, 'image/' ) ? $id : 0;
	}
}
