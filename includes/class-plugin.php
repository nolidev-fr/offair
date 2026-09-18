<?php
/**
 * Plugin bootstrap.
 *
 * @package BeRightBack
 */

namespace BeRightBack;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin components together and handles the lifecycle hooks.
 */
final class Plugin {

	/**
	 * Shared instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings storage and validation.
	 *
	 * @var Settings
	 */
	public $settings;

	/**
	 * Logo and color detection.
	 *
	 * @var Branding
	 */
	public $branding;

	/**
	 * Drop-in files in wp-content.
	 *
	 * @var Dropins
	 */
	public $dropins;

	/**
	 * Page content saved in the uploads folder.
	 *
	 * @var Pages
	 */
	public $pages;

	/**
	 * Writes and removes everything the visitors see.
	 *
	 * @var Publisher
	 */
	public $publisher;

	/**
	 * Returns the shared instance, creating it on first use.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Builds the components and registers the hooks.
	 */
	private function __construct() {
		$this->settings  = new Settings();
		$this->branding  = new Branding();
		$this->dropins   = new Dropins();
		$this->pages     = new Pages( $this->settings, $this->branding );
		$this->publisher = new Publisher( $this->settings, $this->dropins, $this->pages );

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );

		// The pages show the site title, language, timezone and time format:
		// their content is saved again when one of these settings changes.
		foreach ( array( 'blogname', 'WPLANG', 'timezone_string', 'gmt_offset', 'time_format', 'site_icon' ) as $option ) {
			add_action( 'update_option_' . $option, array( $this, 'schedule_refresh' ) );
		}
		add_action( 'init', array( $this, 'refresh' ), 20 );

		new Site_Health( $this );

		if ( is_admin() ) {
			new Admin_Page( $this );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'be-right-back', new CLI( $this ) );
		}
	}

	/**
	 * Asks for the page content to be saved again on the next request. The
	 * current request may still run with the previous language loaded.
	 * Only the settings of the main site feed the pages.
	 */
	public function schedule_refresh() {
		if ( is_main_site() ) {
			update_option( Settings::REFRESH_OPTION, 1 );
		}
	}

	/**
	 * Saves the page content again after a site setting it shows has
	 * changed, provided the pages have been published before.
	 */
	public function refresh() {
		if ( ! get_option( Settings::REFRESH_OPTION ) ) {
			return;
		}

		delete_option( Settings::REFRESH_OPTION );

		if ( file_exists( $this->pages->path() ) ) {
			$this->pages->flush();
			$this->pages->write();
		}
	}

	/**
	 * Loads the translations shipped in the languages directory. Language
	 * packs from translate.wordpress.org take precedence when present.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'be-right-back', false, dirname( BE_RIGHT_BACK_BASENAME ) . '/languages' );
	}

	/**
	 * After a plugin update, copies the new drop-in and saves the page
	 * content again so that both match the new version.
	 */
	public function maybe_upgrade() {
		$stored = (string) Settings::get_option( Settings::VERSION_OPTION, '' );

		if ( BE_RIGHT_BACK_VERSION === $stored ) {
			return;
		}

		$this->publisher->publish();
		Settings::update_option( Settings::VERSION_OPTION, BE_RIGHT_BACK_VERSION );
	}

	/**
	 * Activation: seed the settings from the site branding and publish the pages.
	 */
	public static function activate() {
		$plugin = self::instance();

		$plugin->settings->seed_defaults( $plugin->branding );
		$plugin->publisher->publish();

		Settings::update_option( Settings::VERSION_OPTION, BE_RIGHT_BACK_VERSION );
	}

	/**
	 * Deactivation: remove the drop-ins. Settings are kept so that
	 * reactivating restores the same pages. Uninstalling removes them.
	 */
	public static function deactivate() {
		$plugin = self::instance();

		$plugin->publisher->unpublish();

		Settings::delete_option( Settings::VERSION_OPTION );
	}
}
