<?php
/**
 * Plugin bootstrap.
 *
 * @package Offair
 */

namespace Offair;

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
	 * History of the pages shown and end of outages.
	 *
	 * @var Journal
	 */
	public $journal;

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
		$this->journal   = new Journal( $this->settings, $this->pages );

		add_action( 'init', array( $this, 'load_textdomain' ) );

		// On any request, not only in the admin: automatic updates run with nobody logged in.
		add_action( 'init', array( $this, 'maybe_upgrade' ), 20 );

		// The pages show the site title, language, timezone and time format, the
		// alert uses the address and the email of the site, the templates come
		// from the active theme: the content is saved again when one changes.
		foreach ( array( 'blogname', 'WPLANG', 'timezone_string', 'gmt_offset', 'time_format', 'site_icon', 'home', 'admin_email', 'stylesheet', 'template' ) as $option ) {
			add_action( 'update_option_' . $option, array( $this, 'schedule_refresh' ) );
		}
		add_action( 'init', array( $this, 'refresh' ), 20 );

		// The logo chosen in the Customizer is a theme modification, stored per theme.
		add_action( 'updated_option', array( $this, 'maybe_refresh_theme_mods' ) );
		add_action( 'added_option', array( $this, 'maybe_refresh_theme_mods' ) );

		// On a network, sites come and go, and a large network is written batch after batch.
		if ( is_multisite() ) {
			add_action( 'wp_initialize_site', array( $this, 'site_added' ), 100 );
			add_action( 'wp_update_site', array( $this, 'site_changed' ) );
			add_action( 'wp_delete_site', array( $this, 'site_deleted' ) );
			add_action( Pages::SITES_CRON, array( $this->pages, 'write_sites' ) );
		}

		// The end of a database outage is noticed by a scheduled check, and at
		// once when someone opens the admin, so the dashboard notice is fresh.
		add_filter( 'cron_schedules', array( Journal::class, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- 15 minutes, see Journal::add_schedule().
		add_action( Journal::CRON, array( $this->journal, 'check' ) );
		add_action( 'admin_init', array( $this->journal, 'check' ) );
		add_action( 'admin_init', array( Journal::class, 'schedule' ) );

		new Site_Health( $this );

		if ( is_admin() ) {
			new Admin_Page( $this );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'offair', new CLI( $this ) );
		}
	}

	/**
	 * Asks for the page content to be saved again on the next request. The
	 * current request may still run with the previous language loaded.
	 * Only the settings of the main site feed the pages.
	 */
	public function schedule_refresh() {
		// Each site of a network has its own page, so each keeps its own flag.
		update_option( Settings::REFRESH_OPTION, 1 );
	}

	/**
	 * Schedules a refresh when the Customizer saves the logo of the site.
	 *
	 * @param string $option Option name.
	 */
	public function maybe_refresh_theme_mods( $option ) {
		if ( 0 === strpos( (string) $option, 'theme_mods_' ) ) {
			$this->schedule_refresh();
		}
	}

	/**
	 * Writes the page of a site added to the network.
	 *
	 * @param \WP_Site $site Site.
	 */
	public function site_added( $site ) {
		if ( file_exists( $this->pages->path() ) ) {
			$this->pages->write_map();
			$this->pages->write_site( $site->id );
		}
	}

	/**
	 * Updates the list of the sites when the address of a site changes.
	 */
	public function site_changed() {
		if ( file_exists( $this->pages->path() ) ) {
			$this->pages->write_map();
		}
	}

	/**
	 * Removes the page of a site deleted from the network.
	 *
	 * @param \WP_Site $site Site.
	 */
	public function site_deleted( $site ) {
		if ( file_exists( $this->pages->path() ) ) {
			$this->pages->delete_site( $site->id );
			$this->pages->write_map();
		}
	}

	/**
	 * Saves the page content again after a site setting it shows has
	 * changed, provided the pages have been published before. On a network,
	 * only the page of the site that changed is written again.
	 */
	public function refresh() {
		if ( ! get_option( Settings::REFRESH_OPTION ) ) {
			return;
		}

		delete_option( Settings::REFRESH_OPTION );

		if ( ! file_exists( $this->pages->path() ) ) {
			return;
		}

		$this->pages->flush();

		if ( is_multisite() && ! is_main_site() ) {
			$this->pages->write_site( get_current_blog_id() );
			return;
		}

		$this->pages->write_main();
	}

	/**
	 * Loads the translations shipped in the languages directory. Language
	 * packs from translate.wordpress.org take precedence when present.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'offair', false, dirname( OFFAIR_BASENAME ) . '/languages' );
	}

	/**
	 * After a plugin update, copies the new drop-in and saves the page
	 * content again so that both match the new version.
	 */
	public function maybe_upgrade() {
		$stored = (string) Settings::get_option( Settings::VERSION_OPTION, '' );

		if ( OFFAIR_VERSION === $stored ) {
			return;
		}

		// Recorded first, so that requests arriving together do not all publish.
		Settings::update_option( Settings::VERSION_OPTION, OFFAIR_VERSION );
		$this->settings->upgrade( $stored );
		$this->publisher->publish();
	}

	/**
	 * Activation: seed the settings from the site branding and publish the pages.
	 */
	public static function activate() {
		$plugin = self::instance();

		$plugin->settings->seed_defaults( $plugin->branding );
		$plugin->publisher->publish();

		Settings::update_option( Settings::VERSION_OPTION, OFFAIR_VERSION );
		Journal::schedule();
	}

	/**
	 * Deactivation: remove the drop-ins. Settings are kept so that
	 * reactivating restores the same pages. Uninstalling removes them.
	 */
	public static function deactivate() {
		$plugin = self::instance();

		$plugin->publisher->unpublish();

		Settings::delete_option( Settings::VERSION_OPTION );
		Journal::unschedule();
		wp_clear_scheduled_hook( Pages::SITES_CRON );
	}
}
