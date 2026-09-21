<?php
/**
 * Detects the site logo, icon and colors, and encodes the logo for embedding.
 *
 * @package Offair
 */

namespace Offair;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the generated pages borrow from the site identity.
 */
class Branding {

	/**
	 * Widest logo embedded in the pages, in pixels.
	 */
	const MAX_LOGO_WIDTH = 300;

	/**
	 * Largest logo embedded in the pages, in bytes (150 KB).
	 */
	const MAX_LOGO_BYTES = 153600;

	/**
	 * Largest SVG searched for a wrapped image, in bytes (5 MB).
	 */
	const MAX_WRAPPER_BYTES = 5242880;

	/**
	 * Raster formats the pages embed as they are. They match what the drop-in
	 * accepts, and every browser still in use reads them.
	 */
	const EMBEDDED_TYPES = array( 'image/png', 'image/jpeg', 'image/gif', 'image/webp' );

	/**
	 * Largest favicon embedded in the pages, in bytes (12 KB).
	 */
	const MAX_FAVICON_BYTES = 12288;

	/**
	 * Attachment ID of the theme logo, or of the site icon as a fallback.
	 *
	 * @return int 0 when nothing usable is found.
	 */
	public function detect_logo_id() {
		$candidates = array(
			(int) get_theme_mod( 'custom_logo' ),
			(int) get_option( 'site_icon' ),
		);

		foreach ( $candidates as $id ) {
			if ( $id > 0 && $this->is_image_attachment( $id ) ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * Primary color from Elementor global colors or from theme.json.
	 *
	 * @return string Hex color, or an empty string when nothing is detected.
	 */
	public function detect_primary_color() {
		$color = $this->elementor_primary_color();

		if ( '' === $color ) {
			$color = $this->theme_json_primary_color();
		}

		return $color;
	}

	/**
	 * Logo as an inline data URI, resized to fit the size budget.
	 *
	 * The result is cached because image resizing is expensive and the value
	 * is needed every time the pages are compiled or compared.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|null Keys: src, width, height, bytes, mime. Null when unusable.
	 */
	public function logo( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( $attachment_id <= 0 || ! $this->is_image_attachment( $attachment_id ) ) {
			return null;
		}

		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! file_exists( $file ) ) {
			return null;
		}

		// The version is part of the key: an update may encode the same file differently.
		$cache_key = md5( $attachment_id . '|' . filemtime( $file ) . '|' . self::MAX_LOGO_WIDTH . '|' . self::MAX_LOGO_BYTES . '|' . OFFAIR_VERSION );
		$cached    = Settings::get_option( Settings::LOGO_CACHE, array() );

		if ( is_array( $cached ) && isset( $cached['key'], $cached['logo'] ) && $cache_key === $cached['key'] ) {
			return $cached['logo'];
		}

		$logo = $this->encode_logo( $file, (string) get_post_mime_type( $attachment_id ) );

		Settings::update_option(
			Settings::LOGO_CACHE,
			array(
				'key'  => $cache_key,
				'logo' => $logo,
			),
			false
		);

		return $logo;
	}

	/**
	 * Site icon (32 px) as a data URI for the favicon, or an empty string.
	 *
	 * @return string
	 */
	public function favicon() {
		$icon_id = (int) get_option( 'site_icon' );

		if ( $icon_id <= 0 ) {
			return '';
		}

		$size = image_get_intermediate_size( $icon_id, 'site_icon-32' );

		if ( empty( $size['path'] ) ) {
			return '';
		}

		$uploads = wp_get_upload_dir();
		$path    = trailingslashit( $uploads['basedir'] ) . $size['path'];

		if ( ! file_exists( $path ) || filesize( $path ) > self::MAX_FAVICON_BYTES ) {
			return '';
		}

		$mime = ! empty( $size['mime-type'] ) ? $size['mime-type'] : (string) get_post_mime_type( $icon_id );

		return $this->data_uri( $path, $mime );
	}

	/**
	 * Whether the attachment is an image the pages can embed.
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	private function is_image_attachment( $id ) {
		if ( 'attachment' !== get_post_type( $id ) ) {
			return false;
		}

		return 0 === strpos( (string) get_post_mime_type( $id ), 'image/' );
	}

	/**
	 * Builds the logo array from a file, resizing raster images as needed.
	 *
	 * @param string $file Absolute path.
	 * @param string $mime Mime type.
	 * @return array|null
	 */
	private function encode_logo( $file, $mime ) {
		if ( 'image/svg+xml' === $mime ) {
			if ( filesize( $file ) > self::MAX_LOGO_BYTES ) {
				return $this->encode_wrapped_image( $file );
			}

			$src = $this->data_uri( $file, $mime );

			return '' === $src ? null : array(
				'src'    => $src,
				'width'  => 0,
				'height' => 0,
				'bytes'  => filesize( $file ),
				'mime'   => $mime,
			);
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$original = wp_getimagesize( $file );
		$widths   = array( self::MAX_LOGO_WIDTH, 220, 160 );

		// AVIF, HEIC, BMP or TIFF logos are embedded as PNG, which keeps transparency.
		$output = in_array( $mime, self::EMBEDDED_TYPES, true ) ? $mime : 'image/png';

		foreach ( $widths as $width ) {
			$editor = wp_get_image_editor( $file );

			if ( is_wp_error( $editor ) ) {
				return null;
			}

			$needs_resize = $original && $original[0] > $width;

			if ( $needs_resize ) {
				$resized = $editor->resize( $width, $width, false );

				if ( is_wp_error( $resized ) ) {
					continue;
				}
			}

			$editor->set_quality( 82 );

			$temp  = wp_tempnam( 'offair-logo' );
			$saved = $editor->save( $temp, $output );

			if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
				wp_delete_file( $temp );
				continue;
			}

			// A site may convert what it saves to another format: the result is checked, not the request.
			$bytes = filesize( $saved['path'] );
			$fits  = $bytes <= self::MAX_LOGO_BYTES && in_array( $saved['mime-type'], self::EMBEDDED_TYPES, true );
			$src   = $fits ? $this->data_uri( $saved['path'], $saved['mime-type'] ) : '';

			wp_delete_file( $saved['path'] );
			if ( $saved['path'] !== $temp ) {
				wp_delete_file( $temp );
			}

			if ( '' !== $src ) {
				return array(
					'src'    => $src,
					'width'  => (int) $saved['width'],
					'height' => (int) $saved['height'],
					'bytes'  => $bytes,
					'mime'   => $saved['mime-type'],
				);
			}

			// Still too heavy: a smaller width is tried next, unless the image
			// was already smaller than the target, in which case nothing will help.
			if ( ! $needs_resize ) {
				break;
			}
		}

		return null;
	}

	/**
	 * Builds the logo array from an SVG too heavy to embed, when all it does
	 * is wrap one raster image, as design tools export them. The image is
	 * taken out and resized like any other.
	 *
	 * @param string $file Absolute path.
	 * @return array|null Null when the file draws anything besides one image.
	 */
	private function encode_wrapped_image( $file ) {
		if ( filesize( $file ) > self::MAX_WRAPPER_BYTES ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file, read only.
		$svg = (string) file_get_contents( $file );

		// Shapes and texts would be lost, and several images cannot be merged.
		if ( 1 !== preg_match_all( '#<image\b#i', $svg ) || preg_match( '#<(?:path|circle|ellipse|line|polyline|polygon|text|use|foreignObject)\b#i', $svg ) ) {
			return null;
		}

		// Only the start of the data URI is matched: the payload is too long for a pattern.
		if ( ! preg_match( '#<image\b[^>]*?\bhref\s*=\s*(["\'])data:image/[a-z0-9.+-]+;base64,#i', $svg, $match, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$start = $match[0][1] + strlen( $match[0][0] );
		$end   = strpos( $svg, $match[1][0], $start );

		if ( false === $end ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Data URI decoding, not obfuscation.
		$image = base64_decode( substr( $svg, $start, $end - $start ) );
		unset( $svg );

		$filesystem = Filesystem::get();

		if ( ! $image || ! $filesystem ) {
			return null;
		}

		$temp = wp_tempnam( 'offair-logo' );

		if ( ! $filesystem->put_contents( $temp, $image ) ) {
			wp_delete_file( $temp );
			return null;
		}

		// The type is read from the content, not from what the SVG declares.
		// Image libraries pick their decoder from the extension, which a temporary file lacks.
		$mime      = (string) wp_get_image_mime( $temp );
		$extension = strtok( (string) array_search( $mime, wp_get_mime_types(), true ), '|' );
		$named     = $temp . '.' . $extension;
		$logo      = null;

		if ( $extension && $filesystem->move( $temp, $named, true ) ) {
			$logo = $this->encode_logo( $named, $mime );
			wp_delete_file( $named );
		}

		wp_delete_file( $temp );

		return $logo;
	}

	/**
	 * Reads a file and returns it as a base64 data URI.
	 *
	 * @param string $path Absolute path.
	 * @param string $mime Mime type.
	 * @return string Empty string on failure.
	 */
	private function data_uri( $path, $mime ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file, read only.
		$contents = file_get_contents( $path );

		if ( false === $contents || '' === $contents ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Data URI encoding, not obfuscation.
		return 'data:' . $mime . ';base64,' . base64_encode( $contents );
	}

	/**
	 * Primary color of the active Elementor kit, if Elementor is in use.
	 *
	 * @return string
	 */
	private function elementor_primary_color() {
		$kit_id = (int) get_option( 'elementor_active_kit' );

		if ( $kit_id <= 0 ) {
			return '';
		}

		$kit = get_post_meta( $kit_id, '_elementor_page_settings', true );

		if ( ! is_array( $kit ) || empty( $kit['system_colors'] ) || ! is_array( $kit['system_colors'] ) ) {
			return '';
		}

		foreach ( $kit['system_colors'] as $entry ) {
			if ( isset( $entry['_id'], $entry['color'] ) && 'primary' === $entry['_id'] ) {
				$color = sanitize_hex_color( $entry['color'] );

				return $color ? $color : '';
			}
		}

		return '';
	}

	/**
	 * Color whose slug mentions primary, brand or accent in the theme palette.
	 *
	 * @return string
	 */
	private function theme_json_primary_color() {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return '';
		}

		$palette = wp_get_global_settings( array( 'color', 'palette' ) );
		$colors  = isset( $palette['theme'] ) && is_array( $palette['theme'] ) ? $palette['theme'] : array();

		foreach ( array( 'primary', 'brand', 'accent' ) as $needle ) {
			foreach ( $colors as $entry ) {
				if ( empty( $entry['slug'] ) || empty( $entry['color'] ) ) {
					continue;
				}

				if ( false !== strpos( (string) $entry['slug'], $needle ) ) {
					$color = sanitize_hex_color( $entry['color'] );

					if ( $color ) {
						return $color;
					}
				}
			}
		}

		return '';
	}
}
