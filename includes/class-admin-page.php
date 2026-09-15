<?php
/**
 * Settings page, status box, preview and download endpoints.
 *
 * @package BeRightBack
 */

namespace BeRightBack;

defined( 'ABSPATH' ) || exit;

/**
 * Settings, then Be Right Back. On multisite the page lives in the network
 * admin because the drop-ins are shared by every site.
 */
class Admin_Page {

	const SLUG = 'be-right-back';

	/**
	 * Transient prefix for the message shown after a redirect.
	 */
	const NOTICE = 'be_right_back_notice_';

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

		add_action( 'admin_post_be_right_back_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_be_right_back_generate', array( $this, 'handle_generate' ) );
		add_action( 'admin_post_be_right_back_remove', array( $this, 'handle_remove' ) );
		add_action( 'admin_post_be_right_back_preview', array( $this, 'handle_preview' ) );
		add_action( 'admin_post_be_right_back_download', array( $this, 'handle_download' ) );

		$links_hook = is_multisite() ? 'network_admin_plugin_action_links_' : 'plugin_action_links_';
		add_filter( $links_hook . BE_RIGHT_BACK_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * URL of the settings page.
	 *
	 * @param string $tab Tab to open.
	 * @return string
	 */
	public function page_url( $tab = '' ) {
		$base = is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'options-general.php' );
		$url  = add_query_arg( 'page', self::SLUG, $base );

		return '' === $tab ? $url : $url . '#brb-tab-' . $tab;
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
					'action' => 'be_right_back_preview',
					'screen' => $key,
				),
				self_admin_url( 'admin-post.php' )
			),
			'be_right_back_preview'
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
					'action' => 'be_right_back_download',
					'screen' => $key,
				),
				self_admin_url( 'admin-post.php' )
			),
			'be_right_back_download'
		);
	}

	/**
	 * Adds the page under Settings.
	 */
	public function register_menu() {
		$this->hook_suffix = add_submenu_page(
			is_multisite() ? 'settings.php' : 'options-general.php',
			__( 'Be Right Back', 'be-right-back' ),
			__( 'Be Right Back', 'be-right-back' ),
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
		wp_enqueue_style( 'be-right-back-admin', BE_RIGHT_BACK_URL . 'assets/admin.css', array(), BE_RIGHT_BACK_VERSION );
		wp_enqueue_script( 'be-right-back-admin', BE_RIGHT_BACK_URL . 'assets/admin.js', array( 'jquery', 'wp-color-picker' ), BE_RIGHT_BACK_VERSION, true );
		wp_localize_script(
			'be-right-back-admin',
			'beRightBackAdmin',
			array(
				'chooseLogo' => __( 'Choose a logo', 'be-right-back' ),
				'useLogo'    => __( 'Use this logo', 'be-right-back' ),
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
		array_unshift( $links, '<a href="' . esc_url( $this->page_url() ) . '">' . esc_html__( 'Settings', 'be-right-back' ) . '</a>' );

		return $links;
	}

	/**
	 * Saves the settings and rewrites the pages.
	 */
	public function handle_save() {
		$this->require_capability();
		check_admin_referer( 'be_right_back_save' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Validated field by field in Settings::sanitize().
		$input = isset( $_POST['be_right_back'] ) && is_array( $_POST['be_right_back'] ) ? wp_unslash( $_POST['be_right_back'] ) : array();
		$tab   = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'general';

		$this->plugin->settings->update( $input );
		$results = $this->plugin->generator->generate_all();

		$this->finish( __( 'Settings saved and pages regenerated.', 'be-right-back' ), $results, $tab );
	}

	/**
	 * Rewrites the pages without changing the settings.
	 */
	public function handle_generate() {
		$this->require_capability();
		check_admin_referer( 'be_right_back_generate' );

		$force   = ! empty( $_POST['force'] );
		$results = $this->plugin->generator->generate_all( $force );

		$this->finish( __( 'Pages regenerated.', 'be-right-back' ), $results, 'advanced' );
	}

	/**
	 * Removes the pages written by the plugin.
	 */
	public function handle_remove() {
		$this->require_capability();
		check_admin_referer( 'be_right_back_remove' );

		$results = $this->plugin->generator->remove_all();

		$this->finish( __( 'Pages removed from wp-content. They will be written again the next time you save or regenerate.', 'be-right-back' ), $results, 'advanced' );
	}

	/**
	 * Serves a page exactly as a visitor would see it.
	 *
	 * The file on disk is included when it is current, otherwise the page is
	 * compiled to a temporary file first, so the preview always reflects the
	 * saved settings.
	 */
	public function handle_preview() {
		$this->require_capability();
		check_admin_referer( 'be_right_back_preview' );

		$key       = $this->screen_from_request();
		$generator = $this->plugin->generator;
		$info      = $this->plugin->dropins->info( $key );
		$path      = $info['path'];
		$temporary = '';

		if ( ! ( $info['exists'] && $info['ours'] && 'current' === $generator->state( $key ) ) ) {
			$temporary = $this->temp_file( $generator->source( $key ) );

			if ( '' === $temporary ) {
				wp_die( esc_html__( 'The preview could not be written to a temporary file.', 'be-right-back' ) );
			}

			$path = $temporary;
		}

		// Variables the php-error.php drop-in reads, as WordPress provides them.
		$error   = array(
			'type'    => E_ERROR,
			'message' => __( 'Sample error shown by the preview. Real errors appear here only when WP_DEBUG_DISPLAY is enabled.', 'be-right-back' ),
			'file'    => BE_RIGHT_BACK_FILE,
			'line'    => 1,
		);
		$handled = false;

		include $path;

		if ( '' !== $temporary ) {
			wp_delete_file( $temporary );
		}

		exit;
	}

	/**
	 * Sends a generated page as a file, for manual upload when wp-content is
	 * not writable.
	 */
	public function handle_download() {
		$this->require_capability();
		check_admin_referer( 'be_right_back_download' );

		$key    = $this->screen_from_request();
		$file   = $this->plugin->dropins->file( $key );
		$source = $this->plugin->generator->source( $key );

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );
		header( 'Content-Length: ' . strlen( $source ) );

		echo $source; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PHP source generated by the plugin, served as a download.
		exit;
	}

	/**
	 * Renders the settings page.
	 */
	public function render() {
		if ( ! current_user_can( Settings::capability() ) ) {
			return;
		}

		$settings = $this->plugin->settings->get();
		$states   = $this->plugin->generator->states();
		$labels   = Settings::screen_labels();
		$notice   = get_transient( self::NOTICE . get_current_user_id() );

		if ( false !== $notice ) {
			delete_transient( self::NOTICE . get_current_user_id() );
		}
		?>
		<div class="wrap brb-wrap">
			<h1><?php esc_html_e( 'Be Right Back', 'be-right-back' ); ?></h1>
			<p class="brb-intro"><?php esc_html_e( 'Branded pages shown to visitors when the database is unreachable, when WordPress updates itself and when a fatal PHP error occurs. The pages are written to wp-content so they work even when WordPress cannot load.', 'be-right-back' ); ?></p>

			<?php if ( is_multisite() ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'On a network the three pages are shared by every site. These settings apply to the whole network.', 'be-right-back' ); ?></p></div>
			<?php endif; ?>

			<?php $this->render_notice( $notice ); ?>
			<?php $this->render_status( $states ); ?>

			<h2 class="nav-tab-wrapper brb-tabs">
				<a href="#brb-tab-general" class="nav-tab"><?php esc_html_e( 'General', 'be-right-back' ); ?></a>
				<?php foreach ( Settings::SCREENS as $key ) : ?>
				<a href="#brb-tab-<?php echo esc_attr( $key ); ?>" class="nav-tab"><?php echo esc_html( $labels[ $key ] ); ?></a>
				<?php endforeach; ?>
				<a href="#brb-tab-advanced" class="nav-tab"><?php esc_html_e( 'Advanced', 'be-right-back' ); ?></a>
			</h2>

			<form method="post" action="<?php echo esc_url( self_admin_url( 'admin-post.php' ) ); ?>" id="brb-form">
				<?php wp_nonce_field( 'be_right_back_save' ); ?>
				<input type="hidden" name="action" value="be_right_back_save">
				<input type="hidden" name="tab" value="general" id="brb-current-tab">

				<div id="brb-tab-general" class="brb-panel">
					<?php $this->render_general( $settings['general'] ); ?>
				</div>

				<?php foreach ( Settings::SCREENS as $key ) : ?>
				<div id="brb-tab-<?php echo esc_attr( $key ); ?>" class="brb-panel">
					<?php $this->render_screen( $key, $settings[ $key ] ); ?>
				</div>
				<?php endforeach; ?>

				<p class="submit brb-save">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save and regenerate pages', 'be-right-back' ); ?></button>
				</p>
			</form>

			<div id="brb-tab-advanced" class="brb-panel">
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
		$writable = $dropins->is_content_writable();
		?>
		<div class="brb-status">
			<table class="widefat striped brb-status-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Page', 'be-right-back' ); ?></th>
						<th><?php esc_html_e( 'File', 'be-right-back' ); ?></th>
						<th><?php esc_html_e( 'State', 'be-right-back' ); ?></th>
						<th><?php esc_html_e( 'Written by', 'be-right-back' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $states as $key => $state ) : ?>
					<?php $info = $dropins->info( $key ); ?>
					<tr>
						<td><strong><?php echo esc_html( isset( $labels[ $key ] ) ? $labels[ $key ] : $key ); ?></strong></td>
						<td><code>wp-content/<?php echo esc_html( $info['file'] ); ?></code></td>
						<td>
							<span class="brb-badge brb-badge-<?php echo esc_attr( $state ); ?>"><?php echo esc_html( Generator::state_label( $state ) ); ?></span>
							<?php if ( 'php' === $key && Environment::php_error_page_blocked() ) : ?>
							<span class="brb-badge brb-badge-blocked"><?php esc_html_e( 'Blocked by the PHP configuration', 'be-right-back' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $info['ours'] ) : ?>
								<?php
								printf(
									/* translators: 1: plugin version, 2: generation date. */
									esc_html__( 'Be Right Back %1$s on %2$s', 'be-right-back' ),
									esc_html( $info['version'] ),
									esc_html( $this->format_date( $info['generated'] ) )
								);
								?>
							<?php elseif ( $info['exists'] ) : ?>
								<?php esc_html_e( 'Another plugin or a person. It is never overwritten without your say.', 'be-right-back' ); ?>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
						<td class="brb-status-links">
							<a href="<?php echo esc_url( $this->preview_url( $key ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview', 'be-right-back' ); ?></a>
							<a href="<?php echo esc_url( $this->download_url( $key ) ); ?>"><?php esc_html_e( 'Download', 'be-right-back' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( Environment::php_error_page_blocked() ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'The PHP error page cannot be shown with the current PHP configuration. Open the PHP error tab for the explanation and the fix.', 'be-right-back' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $writable ) : ?>
			<p class="description"><?php esc_html_e( 'wp-content is writable: the pages are rewritten automatically whenever you save.', 'be-right-back' ); ?></p>
			<?php else : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'wp-content is not writable, so the pages cannot be written automatically. Download the three files and upload them to wp-content with FTP or SFTP. Remember to upload them again after changing the settings.', 'be-right-back' ); ?></p></div>
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
		$logo_id  = (int) $general['logo_id'];
		$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Logo', 'be-right-back' ); ?></th>
				<td>
					<div class="brb-logo-field">
						<img id="brb-logo-preview" src="<?php echo esc_url( $logo_url ); ?>" alt="" <?php echo $logo_url ? '' : 'hidden'; ?>>
						<input type="hidden" name="be_right_back[general][logo_id]" id="brb-logo-id" value="<?php echo esc_attr( $logo_id ); ?>">
						<button type="button" class="button brb-logo-choose"><?php esc_html_e( 'Choose from the media library', 'be-right-back' ); ?></button>
						<button type="button" class="button-link brb-logo-remove" <?php echo $logo_url ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove', 'be-right-back' ); ?></button>
					</div>
					<p class="description"><?php esc_html_e( 'Embedded in the pages at 300 pixels wide at most, so it shows even when the media library is unreachable. PNG, JPG, SVG and WebP.', 'be-right-back' ); ?></p>
				</td>
			</tr>
			<?php
			$this->text_row(
				__( 'Displayed name', 'be-right-back' ),
				'general][site_name',
				$general['site_name'],
				array(
					'placeholder' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
					'description' => __( 'Shown above the title. Leave empty to use the site title.', 'be-right-back' ),
				)
			);
			$this->text_row(
				__( 'Primary color', 'be-right-back' ),
				'general][primary_color',
				$general['primary_color'],
				array(
					'class'       => 'brb-color',
					'description' => __( 'Used for the name, the ornament and the button.', 'be-right-back' ),
				)
			);
			$this->text_row(
				__( 'Background color', 'be-right-back' ),
				'general][background_color',
				$general['background_color'],
				array( 'class' => 'brb-color' )
			);
			$this->select_row(
				__( 'Heading font', 'be-right-back' ),
				'general][heading_font',
				$general['heading_font'],
				array(
					'serif' => __( 'Serif (Georgia, Palatino)', 'be-right-back' ),
					'sans'  => __( 'Sans-serif (system font)', 'be-right-back' ),
				),
				__( 'System fonts only: nothing is loaded from the network.', 'be-right-back' )
			);
			$this->select_row(
				__( 'Ornament', 'be-right-back' ),
				'general][ornament',
				$general['ornament'],
				array(
					'wave' => __( 'Wave', 'be-right-back' ),
					'line' => __( 'Line', 'be-right-back' ),
					'none' => __( 'None', 'be-right-back' ),
				)
			);
			$this->text_row(
				__( 'Contact line', 'be-right-back' ),
				'general][contact_line',
				$general['contact_line'],
				array(
					'placeholder' => __( 'Need help? Write to hello@example.com', 'be-right-back' ),
					'description' => __( 'Plain text shown under the button. Email addresses and web addresses become links.', 'be-right-back' ),
				)
			);
			?>
		</table>
		<?php
	}

	/**
	 * Tab of one screen: texts, timings and preview.
	 *
	 * @param string $key    Screen key.
	 * @param array  $screen Screen settings.
	 */
	private function render_screen( $key, array $screen ) {
		$labels     = Settings::screen_labels();
		$file       = $this->plugin->dropins->file( $key );
		$intros     = array(
			'db'          => __( 'Shown when WordPress cannot connect to the database. Answers with HTTP 503 so search engines treat the outage as temporary.', 'be-right-back' ),
			'maintenance' => __( 'Shown while WordPress updates itself, a theme or a plugin. Answers with HTTP 503.', 'be-right-back' ),
			'php'         => __( 'Shown when a fatal PHP error stops the page. Technical details are added only when WP_DEBUG_DISPLAY is enabled.', 'be-right-back' ),
		);
		$preview    = $this->preview_url( $key );
		$name_first = $key . '][';
		?>
		<p class="brb-intro"><?php echo esc_html( $intros[ $key ] ); ?></p>
		<?php if ( 'php' === $key ) : ?>
			<?php $this->render_php_environment_notice(); ?>
		<?php endif; ?>
		<table class="form-table" role="presentation">
			<?php
			$this->checkbox_row(
				__( 'Page', 'be-right-back' ),
				$name_first . 'enabled',
				! empty( $screen['enabled'] ),
				/* translators: %s: file name. */
				sprintf( __( 'Write wp-content/%s', 'be-right-back' ), $file )
			);
			$this->text_row( __( 'Title', 'be-right-back' ), $name_first . 'title', $screen['title'], array( 'class' => 'regular-text' ) );
			$this->textarea_row( __( 'Message', 'be-right-back' ), $name_first . 'message', $screen['message'], __( 'Plain text. Leave a blank line between paragraphs.', 'be-right-back' ) );
			$this->text_row(
				__( 'Button label', 'be-right-back' ),
				$name_first . 'button_label',
				$screen['button_label'],
				array( 'description' => __( 'The button reloads the page. Leave empty to hide it.', 'be-right-back' ) )
			);
			$this->text_row(
				__( 'Automatic refresh', 'be-right-back' ),
				$name_first . 'refresh_delay',
				$screen['refresh_delay'],
				array(
					'type'        => 'number',
					'min'         => 0,
					'max'         => 3600,
					'suffix'      => __( 'seconds', 'be-right-back' ),
					'description' => __( 'The page reloads itself after this delay. 0 disables it.', 'be-right-back' ),
				)
			);
			$this->text_row(
				__( 'Retry-After header', 'be-right-back' ),
				$name_first . 'retry_after',
				$screen['retry_after'],
				array(
					'type'        => 'number',
					'min'         => 0,
					'max'         => 86400,
					'suffix'      => __( 'seconds', 'be-right-back' ),
					'description' => __( 'Tells search engines and monitoring tools when to come back. 0 omits the header.', 'be-right-back' ),
				)
			);
			if ( 'php' === $key ) {
				$this->select_row(
					__( 'HTTP status', 'be-right-back' ),
					$name_first . 'status_code',
					(string) $screen['status_code'],
					array(
						'503' => __( '503 Service Unavailable (recommended)', 'be-right-back' ),
						'500' => __( '500 Internal Server Error (WordPress default)', 'be-right-back' ),
					),
					__( '503 tells search engines the problem is temporary and sends Retry-After. 500 signals a permanent error.', 'be-right-back' )
				);
			}
			?>
		</table>
		<h3><?php esc_html_e( 'Preview', 'be-right-back' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Rendered from the saved settings, exactly as a visitor will see it. Save to refresh it.', 'be-right-back' ); ?></p>
		<iframe class="brb-preview" data-src="<?php echo esc_url( $preview ); ?>" title="<?php echo esc_attr( $labels[ $key ] ); ?>"></iframe>
		<p><a href="<?php echo esc_url( $preview ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the preview in a new tab', 'be-right-back' ); ?></a></p>
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
		<div class="notice notice-warning inline brb-environment">
			<p><strong><?php esc_html_e( 'This page cannot be shown with the current PHP configuration.', 'be-right-back' ); ?></strong></p>
			<?php foreach ( Environment::php_error_page_explanation() as $paragraph ) : ?>
			<p><?php echo esc_html( $paragraph ); ?></p>
			<?php endforeach; ?>
			<p>
				<?php esc_html_e( 'Current values:', 'be-right-back' ); ?>
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
		<h2><?php esc_html_e( 'Regenerate', 'be-right-back' ); ?></h2>
		<p><?php esc_html_e( 'Rewrites the pages from the saved settings. Useful after changing the site title, the timezone or the logo file.', 'be-right-back' ); ?></p>
		<form method="post" action="<?php echo esc_url( self_admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'be_right_back_generate' ); ?>
			<input type="hidden" name="action" value="be_right_back_generate">
			<p><label><input type="checkbox" name="force" value="1"> <?php esc_html_e( 'Also replace files in wp-content that were not written by this plugin', 'be-right-back' ); ?></label></p>
			<p><button type="submit" class="button"><?php esc_html_e( 'Regenerate pages now', 'be-right-back' ); ?></button></p>
		</form>

		<h2><?php esc_html_e( 'Remove', 'be-right-back' ); ?></h2>
		<p><?php esc_html_e( 'Deletes the pages written by this plugin. WordPress shows its default screens again until you save or regenerate.', 'be-right-back' ); ?></p>
		<form method="post" action="<?php echo esc_url( self_admin_url( 'admin-post.php' ) ); ?>" class="brb-confirm" data-confirm="<?php esc_attr_e( 'Remove the pages from wp-content?', 'be-right-back' ); ?>">
			<?php wp_nonce_field( 'be_right_back_remove' ); ?>
			<input type="hidden" name="action" value="be_right_back_remove">
			<p><button type="submit" class="button"><?php esc_html_e( 'Remove pages from wp-content', 'be-right-back' ); ?></button></p>
		</form>

		<h2><?php esc_html_e( 'Manual installation', 'be-right-back' ); ?></h2>
		<p><?php esc_html_e( 'When wp-content is not writable, download the files and upload them to wp-content yourself.', 'be-right-back' ); ?></p>
		<ul class="brb-downloads">
			<?php foreach ( Settings::SCREENS as $key ) : ?>
			<li><a href="<?php echo esc_url( $this->download_url( $key ) ); ?>"><?php echo esc_html( $this->plugin->dropins->file( $key ) ); ?></a> (<?php echo esc_html( $labels[ $key ] ); ?>)</li>
			<?php endforeach; ?>
		</ul>

		<h2><?php esc_html_e( 'WP-CLI', 'be-right-back' ); ?></h2>
		<pre class="brb-cli">wp be-right-back status
wp be-right-back generate [--force]
wp be-right-back remove [--force]
wp be-right-back preview &lt;db|maintenance|php&gt;</pre>

		<h2><?php esc_html_e( 'Deactivation and uninstall', 'be-right-back' ); ?></h2>
		<p><?php esc_html_e( 'Deactivating the plugin removes the three pages from wp-content and keeps your settings. Uninstalling removes the pages and the settings. Files not written by this plugin are never touched.', 'be-right-back' ); ?></p>
		<?php
	}

	/**
	 * Text or number input row.
	 *
	 * @param string $label Label.
	 * @param string $name  Field path inside be_right_back[], for example general][site_name.
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
				<input type="<?php echo esc_attr( $args['type'] ); ?>" id="<?php echo esc_attr( $id ); ?>" name="be_right_back[<?php echo esc_attr( $name ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="<?php echo esc_attr( 'number' === $args['type'] ? 'small-text' : $args['class'] ); ?>"
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
	 */
	private function textarea_row( $label, $name, $value, $description = '' ) {
		$id = $this->field_id( $name );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<textarea id="<?php echo esc_attr( $id ); ?>" name="be_right_back[<?php echo esc_attr( $name ); ?>]" class="large-text" rows="5"><?php echo esc_textarea( $value ); ?></textarea>
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
				<select id="<?php echo esc_attr( $id ); ?>" name="be_right_back[<?php echo esc_attr( $name ); ?>]">
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
					<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="be_right_back[<?php echo esc_attr( $name ); ?>]" value="1" <?php checked( $checked ); ?>>
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
		return 'brb-' . str_replace( array( '][', '_' ), '-', $name );
	}

	/**
	 * Generation date from the file header in the site format.
	 *
	 * @param string $iso ISO 8601 date.
	 * @return string
	 */
	private function format_date( $iso ) {
		$timestamp = strtotime( $iso );

		if ( ! $timestamp ) {
			return $iso;
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Dies unless the current user may manage the plugin.
	 */
	private function require_capability() {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Be Right Back.', 'be-right-back' ), 403 );
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
			wp_die( esc_html__( 'Unknown page.', 'be-right-back' ), 400 );
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

	/**
	 * Writes content to a temporary file.
	 *
	 * @param string $content Content.
	 * @return string Path, or an empty string on failure.
	 */
	private function temp_file( $content ) {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$path = wp_tempnam( 'be-right-back-preview' );

		if ( ! $path ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temporary file for the preview.
		$written = file_put_contents( $path, $content );

		if ( false === $written ) {
			wp_delete_file( $path );

			return '';
		}

		return $path;
	}
}
