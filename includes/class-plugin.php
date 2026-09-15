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
	 * Drop-in files on disk.
	 *
	 * @var Dropins
	 */
	public $dropins;

	/**
	 * Template compiler.
	 *
	 * @var Generator
	 */
	public $generator;

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
		$this->generator = new Generator( $this->settings, $this->dropins, $this->branding );

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );

		new Site_Health( $this );

		if ( is_admin() ) {
			new Admin_Page( $this );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'be-right-back', new CLI( $this ) );
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
	 * Rewrites the drop-ins after a plugin update so they always match the
	 * current templates.
	 */
	public function maybe_upgrade() {
		$stored = (string) Settings::get_option( Settings::VERSION_OPTION, '' );

		if ( BE_RIGHT_BACK_VERSION === $stored ) {
			return;
		}

		$this->generator->generate_all();
		Settings::update_option( Settings::VERSION_OPTION, BE_RIGHT_BACK_VERSION );
	}

	/**
	 * Activation: seed the settings from the site branding and write the pages.
	 */
	public static function activate() {
		$plugin = self::instance();

		$plugin->settings->seed_defaults( $plugin->branding );
		$plugin->generator->generate_all();

		Settings::update_option( Settings::VERSION_OPTION, BE_RIGHT_BACK_VERSION );
	}

	/**
	 * Deactivation: remove the pages written by the plugin. Settings are kept
	 * so that reactivating restores the same pages. Uninstalling removes them.
	 */
	public static function deactivate() {
		$plugin = self::instance();

		$plugin->generator->remove_all();

		Settings::delete_option( Settings::VERSION_OPTION );
	}
}
