<?php
/**
 * Settings page, status box, preview and download endpoints.
 *
 * @package Offair
 */

namespace Offair;

defined( 'ABSPATH' ) || exit;

/**
 * Settings, then Offair. On multisite the page lives in the network
 * admin because the drop-ins are shared by every site.
 */
class Admin_Page {

	const SLUG = 'offair';

	/**
	 * Transient prefix for the message shown after a redirect.
	 */
	const NOTICE = 'offair_notice_';

	/**
	 * User meta holding the end of the last outage the user dismissed.
	 */
	const SEEN_META = 'offair_seen_incident';

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix of the settings page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Registers the hooks.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		add_action( 'admin_post_offair_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_offair_generate', array( $this, 'handle_generate' ) );
		add_action( 'admin_post_offair_remove', array( $this, 'handle_remove' ) );
		add_action( 'admin_post_offair_preview', array( $this, 'handle_preview' ) );
		add_action( 'admin_post_offair_download', array( $this, 'handle_download' ) );
		add_action( 'admin_post_offair_test_alert', array( $this, 'handle_test_alert' ) );
		add_action( 'admin_post_offair_clear_history', array( $this, 'handle_clear_history' ) );
		add_action( 'admin_post_offair_dismiss_incident', array( $this, 'handle_dismiss_incident' ) );
		add_action( is_multisite() ? 'network_admin_notices' : 'admin_notices', array( $this, 'render_incident_notice' ) );

		$links_hook = is_multisite() ? 'network_admin_plugin_action_links_' : 'plugin_action_links_';
		add_filter( $links_hook . OFFAIR_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * URL of the settings page.
	 *
	 * @param string $tab Tab to open.
	 * @return string
	 */
	public function page_url( $tab = '' ) {
		return self::url( $tab );
	}

	/**
	 * URL of the settings page, also used outside the admin (in the report).
	 *
	 * @param string $tab Tab to open.
	 * @return string
	 */
	public static function url( $tab = '' ) {
		$base = is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'options-general.php' );
		$url  = add_query_arg( 'page', self::SLUG, $base );

		return '' === $tab ? $url : $url . '#offair-tab-' . $tab;
	}

	/**
	 * URL of admin-post.php on the current site. It also serves the network admin,
	 * which has no admin-post.php of its own.
	 *
	 * @return string
	 */
	private function post_url() {
		return admin_url( 'admin-post.php' );
	}

	/**
	 * Preview URL of a screen, protected by capability and nonce.
	 *
	 * @param string $key Screen key.
	 * @return string
	 */
	public function preview_url( $key ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'offair_preview',
					'screen' => $key,
				),
				$this->post_url()
			),
			'offair_preview'
		);
	}

	/**
	 * Download URL of a screen, protected by capability and nonce.
	 *
	 * @param string $key Screen key.
	 * @return string
	 */
	public function download_url( $key ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'offair_download',
					'screen' => $key,
				),
				$this->post_url()
			),
			'offair_download'
		);
	}

	/**
	 * Adds the page under Settings.
	 */
	public function register_menu() {
		$this->hook_suffix = add_submenu_page(
			is_multisite() ? 'settings.php' : 'options-general.php',
			__( 'Offair', 'offair' ),
			__( 'Offair', 'offair' ),
			Settings::capability(),
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Loads the admin assets on the settings page only.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'offair-admin', OFFAIR_URL . 'assets/admin.css', array(), OFFAIR_VERSION );
		wp_enqueue_script( 'offair-admin', OFFAIR_URL . 'assets/admin.js', array( 'jquery', 'wp-color-picker' ), OFFAIR_VERSION, true );
		wp_localize_script(
			'offair-admin',
			'offairAdmin',
			array(
				'chooseLogo' => __( 'Choose a logo', 'offair' ),
				'useLogo'    => __( 'Use this logo', 'offair' ),
			)
		);
	}

	/**
	 * Settings link on the plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( $this->page_url() ) . '">' . esc_html__( 'Settings', 'offair' ) . '</a>' );

		return $links;
	}

	/**
	 * Saves the settings and rewrites the pages.
	 */
	public function handle_save() {
		$this->require_capability();
		check_admin_referer( 'offair_save' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Validated field by field in Settings::sanitize().
		$input = isset( $_POST['offair'] ) && is_array( $_POST['offair'] ) ? wp_unslash( $_POST['offair'] ) : array();
		$tab   = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'general';

		$this->plugin->settings->update( $input );
		$this->plugin->pages->flush();
		$results = $this->plugin->publisher->publish();

		$this->finish( __( 'Settings saved and pages regenerated.', 'offair' ), $results, $tab );
	}

	/**
	 * Rewrites the pages without changing the settings.
	 */
	public function handle_generate() {
		$this->require_capability();
		check_admin_referer( 'offair_generate' );

		$force   = ! empty( $_POST['force'] );
		$results = $this->plugin->publisher->publish( $force );

		$this->finish( __( 'Pages regenerated.', 'offair' ), $results, 'advanced' );
	}

	/**
	 * Removes the pages written by the plugin.
	 */
	public function handle_remove() {
		$this->require_capability();
		check_admin_referer( 'offair_remove' );

		$results = $this->plugin->publisher->unpublish();

		$this->finish( __( 'Pages removed from wp-content. They will be written again the next time you save or regenerate.', 'offair' ), $results, 'advanced' );
	}

	/**
	 * Serves a page exactly as a visitor would see it, by running the
	 * drop-in shipped with the plugin on the saved page content.
	 */
	public function handle_preview() {
		$this->require_capability();
		check_admin_referer( 'offair_preview' );

		$key = $this->screen_from_request();

		// Variables WordPress hands to php-error.php, filled with a sample error.
		$error   = array(
			'type'    => E_ERROR,
			'message' => __( 'Sample error shown by the preview. Real errors appear here only when WP_DEBUG and WP_DEBUG_DISPLAY are enabled.', 'offair' ),
			'file'    => OFFAIR_FILE,
			'line'    => 1,
		);
		$handled = false;

		define( 'OFFAIR_PREVIEW', $key );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked above, the site only changes what the preview shows.
		$site = isset( $_GET['site'] ) ? absint( $_GET['site'] ) : 0;

		if ( is_multisite() && $site > 0 && get_site( $site ) ) {
			define( 'OFFAIR_PREVIEW_SITE', $site );
		}

		// The settings page sends the fields being edited, so the preview
		// follows them without saving anything and without writing the pages.
		if ( isset( $_POST['offair'] ) && is_array( $_POST['offair'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Validated field by field in Settings::sanitize().
			$input = wp_unslash( $_POST['offair'] );

			define( 'OFFAIR_PREVIEW_DATA', $this->plugin->pages->preview_json( $this->plugin->settings->sanitize( $input ), $site ) );
		}

		include $this->plugin->dropins->source();

		exit;
	}

	/**
	 * Sends the drop-in under the name of a page, for manual upload when
	 * wp-content is not writable.
	 */
	public function handle_download() {
		$this->require_capability();
		check_admin_referer( 'offair_download' );

		$key    = $this->screen_from_request();
		$file   = $this->plugin->dropins->file( $key );
		$source = $this->plugin->dropins->source();

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );
		header( 'Content-Length: ' . filesize( $source ) );

		readfile( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Sends a file shipped with the plugin.
		exit;
	}

	/**
	 * Saves the settings, then sends a test alert the way the drop-in would.
	 */
	public function handle_test_alert() {
		$this->require_capability();
		check_admin_referer( 'offair_save' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Validated field by field in Settings::sanitize().
		$input = isset( $_POST['offair'] ) && is_array( $_POST['offair'] ) ? wp_unslash( $_POST['offair'] ) : array();

		$settings = $this->plugin->settings->update( $input );
		$this->plugin->pages->flush();
		$results = $this->plugin->publisher->publish();
		$to      = Settings::alert_recipient( $settings['db'] );

		if ( '' === $to ) {
			$message = __( 'Settings saved. No test was sent: enter a valid email address first.', 'offair' );
		} elseif ( Alert::send_test( $to ) ) {
			/* translators: %s: email address. */
			$message = sprintf( __( 'Settings saved. A test alert was handed to the server for %s. Check the inbox and the spam folder: if it never arrives, this host does not deliver the emails PHP sends by itself.', 'offair' ), $to );
		} else {
			$message   = __( 'Settings saved.', 'offair' );
			$results[] = new \WP_Error( 'offair_mail_failed', __( 'The server refused to send the test: the mail function of PHP does not work on this host, so no alert can be sent during an outage. The report sent once the site is back goes through WordPress and is not affected.', 'offair' ) );
		}

		// The test works with the alert off: say so, or a successful test looks like a working alert.
		if ( '' !== $to && empty( $settings['db']['alert'] ) ) {
			$results[] = new \WP_Error( 'offair_alert_off', __( 'The email alert itself is off, so nothing will be sent during an outage. Tick Email alert and save to turn it on.', 'offair' ) );
		}

		$this->finish( $message, $results, 'db' );
	}

	/**
	 * Deletes the history of the pages shown.
	 */
	public function handle_clear_history() {
		$this->require_capability();
		check_admin_referer( 'offair_clear_history' );

		$this->plugin->journal->clear();

		$this->finish( __( 'History cleared.', 'offair' ), array(), 'history' );
	}

	/**
	 * Hides the outage notice for the current user, until the next outage.
	 */
	public function handle_dismiss_incident() {
		$this->require_capability();
		check_admin_referer( 'offair_dismiss_incident' );

		$incident = Journal::last_incident();

		if ( null !== $incident ) {
			update_user_meta( get_current_user_id(), self::SEEN_META, (int) $incident['end'] );
		}

		$referer = wp_get_referer();

		wp_safe_redirect( $referer ? $referer : self::url( 'history' ) );
		exit;
	}

	/**
	 * Tells administrators, on the dashboard, that the database could not be
	 * reached while they were away.
	 */
	public function render_incident_notice() {
		$screen = get_current_screen();

		if ( null === $screen || ! in_array( $screen->id, array( 'dashboard', 'dashboard-network' ), true ) || ! current_user_can( Settings::capability() ) ) {
			return;
		}

		$incident = Journal::last_incident();

		if ( null === $incident || (int) get_user_meta( get_current_user_id(), self::SEEN_META, true ) >= (int) $incident['end'] ) {
			return;
		}

		$dismiss = wp_nonce_url( add_query_arg( 'action', 'offair_dismiss_incident', $this->post_url() ), 'offair_dismiss_incident' );
		?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: 1: date and time the outage started, 2: duration, for example "about 15 mins". */
					esc_html__( 'Offair: the database of this site could not be reached on %1$s (%2$s). Visitors saw the database error page.', 'offair' ),
					esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $incident['start'] ) ),
					esc_html( Journal::duration( $incident ) )
				);
				?>
			</p>
			<p>
				<a href="<?php echo esc_url( self::url( 'history' ) ); ?>"><?php esc_html_e( 'See the history', 'offair' ); ?></a>
				&middot;
				<a href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'Dismiss', 'offair' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Renders the settings page.
	 */
	public function render() {
		if ( ! current_user_can( Settings::capability() ) ) {
			return;
		}

		$settings = $this->plugin->settings->get();
		$states   = $this->plugin->publisher->states();
		$labels   = Settings::screen_labels();
		$notice   = get_transient( self::NOTICE . get_current_user_id() );

		if ( false !== $notice ) {
			delete_transient( self::NOTICE . get_current_user_id() );
		}
		?>
		<div class="wrap offair-wrap">
			<h1><?php esc_html_e( 'Offair', 'offair' ); ?></h1>
			<p class="offair-intro"><?php esc_html_e( 'Branded pages shown to visitors when the database is unreachable, when WordPress updates itself and when a fatal PHP error occurs. WordPress loads them from wp-content before any plugin, so they work even when WordPress cannot load.', 'offair' ); ?></p>

			<?php if ( is_multisite() ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'On a network the three pages are shared by every site. These settings apply to the whole network.', 'offair' ); ?></p></div>
			<?php endif; ?>

			<?php $this->render_notice( $notice ); ?>
			<?php $this->render_status( $states ); ?>

			<h2 class="nav-tab-wrapper offair-tabs">
				<a href="#offair-tab-general" class="nav-tab"><?php esc_html_e( 'General', 'offair' ); ?></a>
				<?php foreach ( Settings::SCREENS as $key ) : ?>
				<a href="#offair-tab-<?php echo esc_attr( $key ); ?>" class="nav-tab"><?php echo esc_html( $labels[ $key ] ); ?></a>
				<?php endforeach; ?>
				<a href="#offair-tab-history" class="nav-tab"><?php esc_html_e( 'History', 'offair' ); ?></a>
				<a href="#offair-tab-advanced" class="nav-tab"><?php esc_html_e( 'Advanced', 'offair' ); ?></a>
			</h2>

			<form method="post" action="<?php echo esc_url( $this->post_url() ); ?>" id="offair-form">
				<?php wp_nonce_field( 'offair_save' ); ?>
				<input type="hidden" name="action" value="offair_save">
				<input type="hidden" name="tab" value="general" id="offair-current-tab">

				<div id="offair-tab-general" class="offair-panel offair-panel-wide">
					<div class="offair-editor">
						<div class="offair-editor-settings"><?php $this->render_general( $settings['general'] ); ?></div>
						<div class="offair-editor-preview"><?php $this->render_preview( 'db', 'general' ); ?></div>
					</div>
				</div>

				<?php foreach ( Settings::SCREENS as $key ) : ?>
				<div id="offair-tab-<?php echo esc_attr( $key ); ?>" class="offair-panel offair-panel-wide">
					<div class="offair-editor">
						<div class="offair-editor-settings"><?php $this->render_screen( $key, $settings[ $key ] ); ?></div>
						<div class="offair-editor-preview"><?php $this->render_preview( $key ); ?></div>
					</div>
				</div>
				<?php endforeach; ?>

				<p class="submit offair-save">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save and regenerate pages', 'offair' ); ?></button>
				</p>
			</form>

			<div id="offair-tab-history" class="offair-panel">
				<?php $this->render_history(); ?>
			</div>

			<div id="offair-tab-advanced" class="offair-panel">
				<?php $this->render_advanced(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Message stored before the last redirect.
	 *
	 * @param array|false $notice Notice data.
	 */
	private function render_notice( $notice ) {
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		$errors = ! empty( $notice['errors'] ) && is_array( $notice['errors'] ) ? $notice['errors'] : array();
		?>
		<div class="notice <?php echo $errors ? 'notice-warning' : 'notice-success'; ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
			<?php foreach ( $errors as $error ) : ?>
			<p><?php echo esc_html( $error ); ?></p>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * State of each file on disk.
	 *
	 * @param array<string, string> $states States by screen.
	 */
	private function render_status( array $states ) {
		$dropins  = $this->plugin->dropins;
		$labels   = Settings::screen_labels();
		$pages    = $this->plugin->pages;
		$writable = $dropins->is_writable();
		?>
		<div class="offair-status">
			<table class="widefat striped offair-status-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Page', 'offair' ); ?></th>
						<th><?php esc_html_e( 'File', 'offair' ); ?></th>
						<th><?php esc_html_e( 'State', 'offair' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $states as $key => $state ) : ?>
					<?php $info = $dropins->info( $key ); ?>
					<tr>
						<td><strong><?php echo esc_html( isset( $labels[ $key ] ) ? $labels[ $key ] : $key ); ?></strong></td>
						<td><code>wp-content/<?php echo esc_html( $info['file'] ); ?></code></td>
						<td class="offair-status-state">
							<?php
							$tooltip = '';
							if ( $info['exists'] && ! $info['ours'] ) {
								$tooltip = __( 'Added by another plugin or a person. It is never replaced without your say.', 'offair' );
							}
							?>
							<span class="offair-badge offair-badge-<?php echo esc_attr( $state ); ?>" title="<?php echo esc_attr( $tooltip ); ?>"><?php echo esc_html( Publisher::state_label( $state ) ); ?></span>
							<?php if ( 'php' === $key && Environment::php_error_page_blocked() ) : ?>
							<span class="offair-badge offair-badge-blocked"><?php esc_html_e( 'Blocked by the PHP configuration', 'offair' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="offair-status-links">
							<a href="<?php echo esc_url( $this->preview_url( $key ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview', 'offair' ); ?></a>
							<a href="<?php echo esc_url( $this->download_url( $key ) ); ?>"><?php esc_html_e( 'Download', 'offair' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( Environment::php_error_page_blocked() ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'The PHP error page cannot be shown with the current PHP configuration. Open the PHP error tab for the explanation and the fix.', 'offair' ); ?></p></div>
			<?php endif; ?>
			<?php if ( is_multisite() ) : ?>
				<?php $sites = $pages->sites_status(); ?>
			<p class="description">
				<?php
				printf(
					/* translators: 1: number of sites with their page written, 2: number of sites of the network besides the main site. */
					esc_html( _n( 'Each site of the network has its own page: %1$d of %2$d site written.', 'Each site of the network has its own page: %1$d of %2$d sites written.', $sites['sites'], 'offair' ) ),
					(int) $sites['written'],
					(int) $sites['sites']
				);
				?>
			</p>
				<?php if ( $sites['written'] < $sites['sites'] ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Some sites of the network do not have their page yet. A large network is written in the background, batch after batch. Regenerate from the Advanced tab to write them now.', 'offair' ); ?></p></div>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( ! $pages->is_reachable() ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'The uploads folder of this site uses a custom path stored in the database. The pages cannot read their content from there and show a neutral English page instead.', 'offair' ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! $pages->is_writable() ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'The uploads folder is not writable, so the content of the pages cannot be saved.', 'offair' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $writable ) : ?>
			<p class="description"><?php esc_html_e( 'The drop-ins are in wp-content and the content of the pages is updated whenever you save.', 'offair' ); ?></p>
			<?php else : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'wp-content is not writable, so the drop-ins cannot be added automatically. Download them from the table above and upload them to wp-content with FTP or SFTP. This is needed only once: your later changes are saved in the uploads folder.', 'offair' ); ?></p></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * General tab: identity and look.
	 *
	 * @param array $general General settings.
	 */
	private function render_general( array $general ) {
		$network = is_multisite();
		?>
		<?php if ( $network ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'Each site of the network shows its own page: its title, its logo or site icon, its language and its timezone. The design, the colors and the texts set here are shared by every site.', 'offair' ); ?></p></div>
		<?php endif; ?>
		<table class="form-table" role="presentation">
			<?php
			$this->logo_row(
				__( 'Logo', 'offair' ),
				'logo_id',
				(int) $general['logo_id'],
				$network
					? __( 'Embedded in the pages at 300 pixels wide at most, so it shows even when the media library is unreachable. PNG, JPG, SVG and WebP. A site of the network with a logo or site icon of its own shows it instead.', 'offair' )
					: __( 'Embedded in the pages at 300 pixels wide at most, so it shows even when the media library is unreachable. PNG, JPG, SVG and WebP.', 'offair' )
			);
			$this->logo_row(
				__( 'Logo on dark backgrounds', 'offair' ),
				'logo_dark_id',
				(int) $general['logo_dark_id'],
				__( 'Optional, usually a white version of the logo. It replaces the logo in dark mode and on a dark colored panel. Leave empty to keep the logo as it is everywhere.', 'offair' )
			);
			$this->text_row(
				__( 'Displayed name', 'offair' ),
				'general][site_name',
				$general['site_name'],
				array(
					'placeholder' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
					'description' => $network
						? __( 'Shown above the title on the main site. Leave empty to use its title. The other sites of the network show their own title.', 'offair' )
						: __( 'Shown above the title. Leave empty to use the site title.', 'offair' ),
				)
			);
			$this->checkbox_row(
				__( 'Name display', 'offair' ),
				'general][show_name',
				! empty( $general['show_name'] ),
				__( 'Show the name above the title. Uncheck it when the logo already contains the name.', 'offair' )
			);
			$this->select_row(
				__( 'Layout', 'offair' ),
				'general][layout',
				$general['layout'],
				array(
					'card'    => __( 'Card: the message in a card centered on the background', 'offair' ),
					'minimal' => __( 'Minimal: the message right on the background, without a card', 'offair' ),
					'split'   => __( 'Two columns: a panel in the primary color with the logo, the message beside it', 'offair' ),
					'banner'  => __( 'Banner: a band in the primary color with the logo, the message below', 'offair' ),
				)
			);
			$this->select_row(
				__( 'Appearance', 'offair' ),
				'general][color_scheme',
				$general['color_scheme'],
				array(
					'auto'  => __( 'Automatic: light or dark, as set on the device of the visitor', 'offair' ),
					'light' => __( 'Always light', 'offair' ),
					'dark'  => __( 'Always dark', 'offair' ),
				),
				__( 'In dark mode the background and the card turn dark, and the primary color is lightened where it colors text, so that it stays readable.', 'offair' )
			);
			$this->text_row(
				__( 'Primary color', 'offair' ),
				'general][primary_color',
				$general['primary_color'],
				array(
					'class'       => 'offair-color',
					'description' => __( 'Used for the name, the ornament and the button.', 'offair' ),
				)
			);
			$this->text_row(
				__( 'Button text color', 'offair' ),
				'general][button_text_color',
				$general['button_text_color'],
				array(
					'class'       => 'offair-color',
					'description' => __( 'Leave empty for an automatic choice: white text, on a slightly darkened button when a mid-tone color needs it, or dark text on a light color.', 'offair' ),
				)
			);
			$this->text_row(
				__( 'Background color', 'offair' ),
				'general][background_color',
				$general['background_color'],
				array( 'class' => 'offair-color' )
			);
			$this->select_row(
				__( 'Heading font', 'offair' ),
				'general][heading_font',
				$general['heading_font'],
				array(
					'serif' => __( 'Serif (Georgia, Palatino)', 'offair' ),
					'sans'  => __( 'Sans-serif (system font)', 'offair' ),
				),
				__( 'System fonts only: nothing is loaded from the network.', 'offair' )
			);
			$this->select_row(
				__( 'Ornament', 'offair' ),
				'general][ornament',
				$general['ornament'],
				array(
					'wave' => __( 'Wave', 'offair' ),
					'line' => __( 'Line', 'offair' ),
					'none' => __( 'None', 'offair' ),
				)
			);
			$this->text_row(
				__( 'Contact line', 'offair' ),
				'general][contact_line',
				$general['contact_line'],
				array(
					'placeholder' => __( 'Need help? Write to hello@example.com', 'offair' ),
					'description' => __( 'Plain text shown under the button. Email addresses and web addresses become links.', 'offair' ),
				)
			);
			?>
		</table>
		<?php
	}

	/**
	 * Logo row: media library picker, preview and a warning when the image
	 * cannot be embedded.
	 *
	 * @param string $label       Label.
	 * @param string $key         Setting key in the general section.
	 * @param int    $logo_id     Attachment ID.
	 * @param string $description Description.
	 */
	private function logo_row( $label, $key, $logo_id, $description ) {
		$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		$embedded = ! $logo_id || null !== $this->plugin->branding->logo( $logo_id );
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<div class="offair-logo-field">
					<img class="offair-logo-preview" src="<?php echo esc_url( $logo_url ); ?>" alt="" <?php echo $logo_url ? '' : 'hidden'; ?>>
					<input type="hidden" class="offair-logo-id" name="offair[general][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $logo_id ); ?>">
					<button type="button" class="button offair-logo-choose"><?php esc_html_e( 'Choose from the media library', 'offair' ); ?></button>
					<button type="button" class="button-link offair-logo-remove" <?php echo $logo_url ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove', 'offair' ); ?></button>
				</div>
				<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php if ( ! $embedded ) : ?>
				<div class="notice notice-warning inline offair-logo-warning"><p>
					<?php
					printf(
						/* translators: %s: file size, for example 150 KB. */
						esc_html__( 'This logo cannot be embedded, so the pages are shown without it. An SVG has to weigh less than %s, unless all it contains is one image. Try a PNG or JPG version of the logo.', 'offair' ),
						esc_html( size_format( Branding::MAX_LOGO_BYTES ) )
					);
					?>
				</p></div>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Tab of one screen: texts and timings. The preview sits beside it.
	 *
	 * @param string $key    Screen key.
	 * @param array  $screen Screen settings.
	 */
	private function render_screen( $key, array $screen ) {
		$file       = $this->plugin->dropins->file( $key );
		$intros     = array(
			'db'          => __( 'Shown when WordPress cannot connect to the database. Answers with HTTP 503 so search engines treat the outage as temporary.', 'offair' ),
			'maintenance' => __( 'Shown while WordPress updates itself, a theme or a plugin. Answers with HTTP 503.', 'offair' ),
			'php'         => __( 'Shown when a fatal PHP error stops the page. Technical details are added only when WP_DEBUG and WP_DEBUG_DISPLAY are enabled.', 'offair' ),
		);
		$name_first = $key . '][';
		$defaults   = $this->plugin->settings->defaults();
		$default    = $defaults[ $key ];
		$empty_hint = __( 'Leave empty to use the default text in the language of the site.', 'offair' );
		?>
		<p class="offair-intro"><?php echo esc_html( $intros[ $key ] ); ?></p>
		<?php if ( 'php' === $key ) : ?>
			<?php $this->render_php_environment_notice(); ?>
		<?php endif; ?>
		<?php $template = Pages::theme_template( $key ); ?>
		<?php if ( '' !== $template ) : ?>
		<div class="notice notice-info inline">
			<p>
				<?php
				printf(
					/* translators: %s: path of the template file. */
					esc_html__( 'The active theme replaces this page with its template %s. The settings below are passed to it, and the template decides what to show.', 'offair' ),
					'<code>' . esc_html( str_replace( wp_normalize_path( WP_CONTENT_DIR ), 'wp-content', wp_normalize_path( $template ) ) ) . '</code>'
				);
				?>
			</p>
		</div>
		<?php endif; ?>
		<table class="form-table" role="presentation">
			<?php
			$this->checkbox_row(
				__( 'Page', 'offair' ),
				$name_first . 'enabled',
				! empty( $screen['enabled'] ),
				/* translators: %s: file name. */
				sprintf( __( 'Write wp-content/%s', 'offair' ), $file )
			);
			$this->text_row(
				__( 'Title', 'offair' ),
				$name_first . 'title',
				$screen['title'],
				array(
					'placeholder' => $default['title'],
					'description' => $empty_hint,
				)
			);
			$this->textarea_row(
				__( 'Message', 'offair' ),
				$name_first . 'message',
				$screen['message'],
				__( 'Plain text. Leave a blank line between paragraphs.', 'offair' ) . ' ' . $empty_hint,
				$default['message']
			);
			$this->checkbox_row(
				__( 'Retry button', 'offair' ),
				$name_first . 'show_button',
				! empty( $screen['show_button'] ),
				__( 'Show a button that reloads the page', 'offair' )
			);
			$this->text_row(
				__( 'Button label', 'offair' ),
				$name_first . 'button_label',
				$screen['button_label'],
				array(
					'placeholder' => $default['button_label'],
					'description' => $empty_hint,
				)
			);
			$this->text_row(
				__( 'Automatic refresh', 'offair' ),
				$name_first . 'refresh_delay',
				$screen['refresh_delay'],
				array(
					'type'        => 'number',
					'min'         => 0,
					'max'         => 3600,
					'suffix'      => __( 'seconds', 'offair' ),
					'description' => __( 'The page reloads itself after this delay. 0 disables it.', 'offair' ),
				)
			);
			$this->text_row(
				__( 'Retry-After header', 'offair' ),
				$name_first . 'retry_after',
				$screen['retry_after'],
				array(
					'type'        => 'number',
					'min'         => 0,
					'max'         => 86400,
					'suffix'      => __( 'seconds', 'offair' ),
					'description' => __( 'Tells search engines and monitoring tools when to come back. 0 omits the header.', 'offair' ),
				)
			);
			$this->checkbox_row(
				__( 'Incident line', 'offair' ),
				$name_first . 'show_meta',
				! empty( $screen['show_meta'] ),
				__( 'Show the local time of the incident and the HTTP status under the message', 'offair' )
			);
			if ( 'db' === $key ) {
				$this->checkbox_row(
					__( 'Email alert', 'offair' ),
					$name_first . 'alert',
					! empty( $screen['alert'] ),
					__( 'Send an email when visitors see this page, and a report once the site is back', 'offair' )
				);
				$this->text_row(
					__( 'Alert recipient', 'offair' ),
					$name_first . 'alert_email',
					$screen['alert_email'],
					array(
						'type'        => 'email',
						'placeholder' => (string) ( is_multisite() ? get_site_option( 'admin_email' ) : get_option( 'admin_email' ) ),
						'description' => __( 'Leave empty to use the administration email address of the site.', 'offair' ),
					)
				);
				$this->render_alert_test_row();
			}
			if ( 'php' === $key ) {
				$this->select_row(
					__( 'HTTP status', 'offair' ),
					$name_first . 'status_code',
					(string) $screen['status_code'],
					array(
						'503' => __( '503 Service Unavailable (recommended)', 'offair' ),
						'500' => __( '500 Internal Server Error (WordPress default)', 'offair' ),
					),
					__( '503 tells search engines the problem is temporary and sends Retry-After. 500 signals a permanent error.', 'offair' )
				);
			}
			?>
		</table>
		<?php
	}

	/**
	 * Live preview of a page, beside the settings. It follows what is typed:
	 * the fields are sent to the preview endpoint, which renders them without
	 * saving anything. On a network, the page of any site can be shown.
	 *
	 * @param string $key     Screen key.
	 * @param string $context Tab the preview sits in, when it is not the tab of the screen.
	 */
	private function render_preview( $key, $context = '' ) {
		$labels  = Settings::screen_labels();
		$preview = $this->preview_url( $key );
		$frame   = 'offair-preview-' . ( '' !== $context ? $context : $key );
		?>
		<div class="offair-preview-pane">
			<div class="offair-preview-bar">
				<strong><?php esc_html_e( 'Live preview', 'offair' ); ?></strong>
				<span class="offair-preview-devices">
					<button type="button" class="button button-small offair-device is-active" data-device="desktop"><?php esc_html_e( 'Desktop', 'offair' ); ?></button>
					<button type="button" class="button button-small offair-device" data-device="mobile"><?php esc_html_e( 'Mobile', 'offair' ); ?></button>
				</span>
			</div>
			<?php if ( is_multisite() ) : ?>
			<p class="offair-preview-site-row">
				<label>
					<?php esc_html_e( 'Site shown:', 'offair' ); ?>
					<select class="offair-preview-site">
						<?php foreach ( get_sites( array( 'number' => 100 ) ) as $site ) : ?>
						<option value="<?php echo esc_attr( is_main_site( $site->blog_id ) ? 0 : $site->blog_id ); ?>"><?php echo esc_html( untrailingslashit( $site->domain . $site->path ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</p>
			<?php endif; ?>
			<div class="offair-preview-frame">
				<iframe class="offair-preview" name="<?php echo esc_attr( $frame ); ?>" data-base="<?php echo esc_url( $preview ); ?>" title="<?php echo esc_attr( $labels[ $key ] ); ?>"></iframe>
			</div>
			<p class="description">
				<?php esc_html_e( 'The preview follows what you type. Nothing is saved, and the pages of the site are left untouched, until you save.', 'offair' ); ?>
				<a href="<?php echo esc_url( $preview ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the saved page in a new tab', 'offair' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Test button of the alert. It submits the settings form, so the test
	 * uses the address typed in, saved first.
	 */
	private function render_alert_test_row() {
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Test', 'offair' ); ?></th>
			<td>
				<button type="submit" class="button" name="action" value="offair_test_alert"><?php esc_html_e( 'Save and send a test alert', 'offair' ); ?></button>
				<p class="description"><?php esc_html_e( 'While the database is down WordPress cannot run, so the alert is sent with the mail function of PHP. Some hosts block it, and it may land in spam: the test travels exactly the same way. The report goes through WordPress, like its other emails, once the error page has not been shown for five minutes.', 'offair' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * History tab: the pages shown to visitors, grouped into incidents.
	 */
	private function render_history() {
		$incidents = $this->plugin->journal->incidents( 50 );
		$labels    = Settings::screen_labels();
		$settings  = $this->plugin->settings->get();
		$to        = Settings::alert_recipient( $settings['db'] );
		$format    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<p class="offair-intro"><?php esc_html_e( 'Each time a visitor sees one of the pages, the date, the page and the HTTP status are recorded, once a minute at most. Nothing about the visitors is kept, and nothing is recorded while nobody visits the site.', 'offair' ); ?></p>
		<?php if ( empty( $settings['db']['alert'] ) ) : ?>
		<p><?php esc_html_e( 'Email alerts are off. Turn them on in the Database error tab to hear about an outage while it happens.', 'offair' ); ?></p>
		<?php elseif ( '' === $to ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'Email alerts are on, but no valid address is set to receive them.', 'offair' ); ?></p></div>
		<?php else : ?>
		<p>
			<?php
			printf(
				/* translators: %s: email address. */
				esc_html__( 'Email alerts about database outages go to %s.', 'offair' ),
				'<strong>' . esc_html( $to ) . '</strong>'
			);
			?>
		</p>
		<?php endif; ?>

		<?php if ( ! $incidents ) : ?>
		<p><em><?php esc_html_e( 'No page has been shown to visitors so far.', 'offair' ); ?></em></p>
		<?php else : ?>
		<table class="widefat striped offair-history">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Page', 'offair' ); ?></th>
					<th><?php esc_html_e( 'Started', 'offair' ); ?></th>
					<th><?php esc_html_e( 'Observed duration', 'offair' ); ?></th>
					<th><?php esc_html_e( 'HTTP status', 'offair' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $incidents as $incident ) : ?>
				<tr>
					<td><?php echo esc_html( isset( $labels[ $incident['screen'] ] ) ? $labels[ $incident['screen'] ] : $incident['screen'] ); ?></td>
					<td><?php echo esc_html( wp_date( $format, $incident['start'] ) ); ?></td>
					<td><?php echo esc_html( Journal::duration( $incident ) ); ?></td>
					<td><?php echo esc_html( (string) $incident['status'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'The duration runs from the first to the last page shown. The 50 most recent incidents are listed, and the history keeps 180 days.', 'offair' ); ?></p>
		<form method="post" action="<?php echo esc_url( $this->post_url() ); ?>" class="offair-confirm" data-confirm="<?php esc_attr_e( 'Delete the whole history?', 'offair' ); ?>">
			<?php wp_nonce_field( 'offair_clear_history' ); ?>
			<input type="hidden" name="action" value="offair_clear_history">
			<p><button type="submit" class="button"><?php esc_html_e( 'Clear the history', 'offair' ); ?></button></p>
		</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Warning shown in the PHP error tab when the PHP configuration prevents
	 * WordPress from ever loading php-error.php, with the values in cause.
	 */
	private function render_php_environment_notice() {
		if ( ! Environment::php_error_page_blocked() ) {
			return;
		}
		?>
		<div class="notice notice-warning inline offair-environment">
			<p><strong><?php esc_html_e( 'This page cannot be shown with the current PHP configuration.', 'offair' ); ?></strong></p>
			<?php foreach ( Environment::php_error_page_explanation() as $paragraph ) : ?>
			<p><?php echo esc_html( $paragraph ); ?></p>
			<?php endforeach; ?>
			<p>
				<?php esc_html_e( 'Current values:', 'offair' ); ?>
				<?php foreach ( Environment::php_error_page_values() as $name => $value ) : ?>
				<code><?php echo esc_html( $name . ' = ' . ( '' === $value ? '(empty)' : $value ) ); ?></code>
				<?php endforeach; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Advanced tab: maintenance actions.
	 */
	private function render_advanced() {
		$labels = Settings::screen_labels();
		?>
		<h2><?php esc_html_e( 'Regenerate', 'offair' ); ?></h2>
		<p><?php esc_html_e( 'Saves the content of the pages again and restores missing drop-ins. Useful after changing the site title, the timezone or the logo file.', 'offair' ); ?></p>
		<form method="post" action="<?php echo esc_url( $this->post_url() ); ?>">
			<?php wp_nonce_field( 'offair_generate' ); ?>
			<input type="hidden" name="action" value="offair_generate">
			<p><label><input type="checkbox" name="force" value="1"> <?php esc_html_e( 'Also replace files in wp-content that were not added by this plugin', 'offair' ); ?></label></p>
			<p><button type="submit" class="button"><?php esc_html_e( 'Regenerate pages now', 'offair' ); ?></button></p>
		</form>

		<h2><?php esc_html_e( 'Remove', 'offair' ); ?></h2>
		<p><?php esc_html_e( 'Deletes the drop-ins added by this plugin. WordPress shows its default screens again until you save or regenerate.', 'offair' ); ?></p>
		<form method="post" action="<?php echo esc_url( $this->post_url() ); ?>" class="offair-confirm" data-confirm="<?php esc_attr_e( 'Remove the drop-ins from wp-content?', 'offair' ); ?>">
			<?php wp_nonce_field( 'offair_remove' ); ?>
			<input type="hidden" name="action" value="offair_remove">
			<p><button type="submit" class="button"><?php esc_html_e( 'Remove drop-ins from wp-content', 'offair' ); ?></button></p>
		</form>

		<h2><?php esc_html_e( 'Manual installation', 'offair' ); ?></h2>
		<p><?php esc_html_e( 'When wp-content is not writable, download the drop-ins and upload them to wp-content yourself. They are the same file under three names. The content of the pages is saved in the uploads folder, so later changes need no new upload.', 'offair' ); ?></p>
		<ul class="offair-downloads">
			<?php foreach ( Settings::SCREENS as $key ) : ?>
			<li><a href="<?php echo esc_url( $this->download_url( $key ) ); ?>"><?php echo esc_html( $this->plugin->dropins->file( $key ) ); ?></a> (<?php echo esc_html( $labels[ $key ] ); ?>)</li>
			<?php endforeach; ?>
		</ul>

		<h2><?php esc_html_e( 'Files', 'offair' ); ?></h2>
		<p><?php esc_html_e( 'The drop-ins are copies of dropins/drop-in.php from the plugin folder. The content of the pages is saved here:', 'offair' ); ?></p>
		<p><code><?php echo esc_html( wp_normalize_path( $this->plugin->pages->path() ) ); ?></code></p>
		<p><?php esc_html_e( 'The alert settings, the theme folders and the history are kept next to it, in a private folder whose name cannot be guessed, because anyone can read a file in uploads when they know its address.', 'offair' ); ?></p>

		<h2><?php esc_html_e( 'WP-CLI', 'offair' ); ?></h2>
		<pre class="offair-cli">wp offair status
wp offair generate [--force]
wp offair remove [--force]
wp offair preview &lt;db|maintenance|php&gt;
wp offair history [--format=&lt;table|json|csv&gt;]</pre>

		<h2><?php esc_html_e( 'Deactivation and uninstall', 'offair' ); ?></h2>
		<p><?php esc_html_e( 'Deactivating the plugin removes the three drop-ins from wp-content and keeps your settings. Uninstalling also removes the settings and the content folder in uploads. Files not added by this plugin are never touched.', 'offair' ); ?></p>
		<?php
	}

	/**
	 * Text or number input row.
	 *
	 * @param string $label Label.
	 * @param string $name  Field path inside offair[], for example general][site_name.
	 * @param mixed  $value Value.
	 * @param array  $args  type, class, placeholder, description, min, max, suffix.
	 */
	private function text_row( $label, $name, $value, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'type'        => 'text',
				'class'       => 'regular-text',
				'placeholder' => '',
				'description' => '',
				'min'         => null,
				'max'         => null,
				'suffix'      => '',
			)
		);
		$id   = $this->field_id( $name );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="<?php echo esc_attr( $args['type'] ); ?>" id="<?php echo esc_attr( $id ); ?>" name="offair[<?php echo esc_attr( $name ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="<?php echo esc_attr( 'number' === $args['type'] ? 'small-text' : $args['class'] ); ?>"
					<?php echo '' !== $args['placeholder'] ? ' placeholder="' . esc_attr( $args['placeholder'] ) . '"' : ''; ?>
					<?php echo null !== $args['min'] ? ' min="' . (int) $args['min'] . '"' : ''; ?>
					<?php echo null !== $args['max'] ? ' max="' . (int) $args['max'] . '"' : ''; ?>>
				<?php if ( '' !== $args['suffix'] ) : ?>
				<span><?php echo esc_html( $args['suffix'] ); ?></span>
				<?php endif; ?>
				<?php if ( '' !== $args['description'] ) : ?>
				<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Textarea row.
	 *
	 * @param string $label       Label.
	 * @param string $name        Field path.
	 * @param string $value       Value.
	 * @param string $description Help text.
	 * @param string $placeholder Placeholder.
	 */
	private function textarea_row( $label, $name, $value, $description = '', $placeholder = '' ) {
		$id = $this->field_id( $name );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<textarea id="<?php echo esc_attr( $id ); ?>" name="offair[<?php echo esc_attr( $name ); ?>]" class="large-text" rows="5" placeholder="<?php echo esc_attr( $placeholder ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
				<?php if ( '' !== $description ) : ?>
				<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Select row.
	 *
	 * @param string                $label       Label.
	 * @param string                $name        Field path.
	 * @param string                $value       Selected value.
	 * @param array<string, string> $options     Value to label.
	 * @param string                $description Help text.
	 */
	private function select_row( $label, $name, $value, array $options, $description = '' ) {
		$id = $this->field_id( $name );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select id="<?php echo esc_attr( $id ); ?>" name="offair[<?php echo esc_attr( $name ); ?>]">
					<?php foreach ( $options as $option_value => $option_label ) : ?>
					<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( (string) $value, (string) $option_value ); ?>><?php echo esc_html( $option_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( '' !== $description ) : ?>
				<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Checkbox row.
	 *
	 * @param string $label   Label.
	 * @param string $name    Field path.
	 * @param bool   $checked Whether checked.
	 * @param string $text    Text next to the box.
	 */
	private function checkbox_row( $label, $name, $checked, $text ) {
		$id = $this->field_id( $name );
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<label for="<?php echo esc_attr( $id ); ?>">
					<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="offair[<?php echo esc_attr( $name ); ?>]" value="1" <?php checked( $checked ); ?>>
					<?php echo esc_html( $text ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	/**
	 * HTML id derived from a field path.
	 *
	 * @param string $name Field path.
	 * @return string
	 */
	private function field_id( $name ) {
		return 'offair-' . str_replace( array( '][', '_' ), '-', $name );
	}

	/**
	 * Dies unless the current user may manage the plugin.
	 */
	private function require_capability() {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Offair.', 'offair' ), 403 );
		}
	}

	/**
	 * Screen key from the request, after the nonce has been checked.
	 *
	 * @return string
	 */
	private function screen_from_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Every caller runs check_admin_referer() first.
		$key   = isset( $_GET['screen'] ) ? sanitize_key( wp_unslash( $_GET['screen'] ) ) : 'db';
		$files = Dropins::files();

		if ( ! isset( $files[ $key ] ) ) {
			wp_die( esc_html__( 'Unknown page.', 'offair' ), 400 );
		}

		return $key;
	}

	/**
	 * Stores the outcome of an action and returns to the settings page.
	 *
	 * @param string $message Message.
	 * @param array  $results Result per screen, WP_Error for failures.
	 * @param string $tab     Tab to open.
	 */
	private function finish( $message, array $results, $tab ) {
		$errors = array();

		foreach ( $results as $result ) {
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			}
		}

		set_transient(
			self::NOTICE . get_current_user_id(),
			array(
				'message' => $message,
				'errors'  => $errors,
			),
			120
		);

		wp_safe_redirect( $this->page_url( $tab ) );
		exit;
	}
}
