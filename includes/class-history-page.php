<?php
/**
 * Outage history page.
 *
 * @package Offair
 */

namespace Offair;

defined( 'ABSPATH' ) || exit;

/**
 * The history of the pages shown to visitors, on a page of its own under
 * Tools (under the Dashboard of the network admin, which has no Tools), with
 * the settings of the dashboard widget.
 */
class History_Page {

	/**
	 * Page slug.
	 */
	const SLUG = 'offair-history';

	/**
	 * Transient holding the message shown after an action.
	 */
	const NOTICE = 'offair_history_notice_';

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix of the page.
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
		add_action( 'admin_post_offair_clear_history', array( $this, 'handle_clear_history' ) );
		add_action( 'admin_post_offair_save_widget', array( $this, 'handle_save_widget' ) );
	}

	/**
	 * URL of the page, also used outside the admin (in the report).
	 *
	 * @return string
	 */
	public static function url() {
		$base = is_multisite() ? network_admin_url( 'index.php' ) : admin_url( 'tools.php' );

		return add_query_arg( 'page', self::SLUG, $base );
	}

	/**
	 * Adds the page under Tools, or under the Dashboard of the network admin.
	 */
	public function register_menu() {
		$this->hook_suffix = add_submenu_page(
			is_multisite() ? 'index.php' : 'tools.php',
			__( 'Outage history', 'offair' ),
			__( 'Outage history', 'offair' ),
			Settings::capability(),
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Loads the styles and the confirmation of the page.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'offair-admin', OFFAIR_URL . 'assets/admin.css', array(), OFFAIR_VERSION );
		wp_enqueue_script( 'offair-history', OFFAIR_URL . 'assets/history.js', array(), OFFAIR_VERSION, true );
	}

	/**
	 * Deletes the history of the pages shown.
	 */
	public function handle_clear_history() {
		$this->require_capability();
		check_admin_referer( 'offair_clear_history' );

		$this->plugin->journal->clear();

		$this->finish( __( 'History cleared.', 'offair' ) );
	}

	/**
	 * Saves the settings of the dashboard widget. The pages do not depend on
	 * them, so nothing is regenerated.
	 */
	public function handle_save_widget() {
		$this->require_capability();
		check_admin_referer( 'offair_save_widget' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Validated in Settings::sanitize().
		$input = isset( $_POST['offair']['dashboard'] ) && is_array( $_POST['offair']['dashboard'] ) ? wp_unslash( $_POST['offair']['dashboard'] ) : array();

		$this->plugin->settings->update( array( 'dashboard' => $input ) );

		$this->finish( __( 'Settings saved.', 'offair' ) );
	}

	/**
	 * Renders the page.
	 */
	public function render() {
		if ( ! current_user_can( Settings::capability() ) ) {
			return;
		}

		$notice = get_transient( self::NOTICE . get_current_user_id() );

		if ( false !== $notice ) {
			delete_transient( self::NOTICE . get_current_user_id() );
		}
		?>
		<div class="wrap offair-wrap">
			<h1><?php esc_html_e( 'Outage history', 'offair' ); ?></h1>
			<p><a href="<?php echo esc_url( Admin_Page::url() ); ?>"><?php esc_html_e( 'Open the Offair settings', 'offair' ); ?></a></p>

			<?php if ( is_string( $notice ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<?php $this->render_history(); ?>
			<?php $this->render_widget_settings(); ?>
		</div>
		<?php
	}

	/**
	 * History of the pages shown to visitors.
	 */
	private function render_history() {
		$incidents = $this->plugin->journal->incidents( 50 );
		$errors    = $this->plugin->journal->errors();
		$labels    = Settings::screen_labels();
		$settings  = $this->plugin->settings->get();
		$to        = Settings::alert_recipient( $settings['db'] );
		$format    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<p class="offair-intro"><?php esc_html_e( 'Each time a visitor sees one of the pages, the date, the page and the HTTP status are recorded, once a minute at most. Nothing about the visitors is kept, and nothing is recorded while nobody visits the site.', 'offair' ); ?></p>
		<p class="offair-intro"><?php esc_html_e( 'For a PHP error, the error itself is kept too: its message, file and line, which usually name the plugin or theme in cause. Paths start from the WordPress folder.', 'offair' ); ?></p>
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
				<?php $this->render_incident_errors( $this->plugin->journal->incident_errors( $incident, $errors ), $format ); ?>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'The duration runs from the first to the last page shown. The 50 most recent incidents are listed, and the history keeps 180 days.', 'offair' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="offair-confirm" data-confirm="<?php esc_attr_e( 'Delete the whole history?', 'offair' ); ?>">
			<?php wp_nonce_field( 'offair_clear_history' ); ?>
			<input type="hidden" name="action" value="offair_clear_history">
			<p><button type="submit" class="button"><?php esc_html_e( 'Clear the history', 'offair' ); ?></button></p>
		</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Errors behind a PHP error incident, in a row under it, folded.
	 *
	 * @param array[] $errors Distinct errors of the incident.
	 * @param string  $format Date and time format.
	 */
	private function render_incident_errors( array $errors, $format ) {
		if ( ! $errors ) {
			return;
		}
		?>
		<tr class="offair-history-errors">
			<td colspan="4">
				<details>
					<summary>
						<?php
						/* translators: %d: number of distinct errors. */
						echo esc_html( sprintf( _n( '%d error recorded', '%d different errors recorded', count( $errors ), 'offair' ), count( $errors ) ) );
						?>
					</summary>
					<?php foreach ( $errors as $error ) : ?>
					<div class="offair-history-error">
						<pre><?php echo esc_html( $error['message'] ); ?></pre>
						<p>
							<?php
							if ( '' !== $error['file'] ) {
								/* translators: 1: path of the file, from the WordPress folder, 2: line number. */
								echo '<code>' . esc_html( sprintf( __( '%1$s, line %2$d', 'offair' ), $error['file'], $error['line'] ) ) . '</code> ';
							}

							/* translators: 1: number of minutes with this error, 2: date and time it was last recorded. */
							echo esc_html( sprintf( _n( 'Recorded %1$d time, last on %2$s.', 'Recorded %1$d times, last on %2$s.', $error['count'], 'offair' ), $error['count'], wp_date( $format, $error['last'] ) ) );
							?>
						</p>
					</div>
					<?php endforeach; ?>
				</details>
			</td>
		</tr>
		<?php
	}

	/**
	 * Settings of the dashboard widget.
	 */
	private function render_widget_settings() {
		$settings = $this->plugin->settings->get();
		$widget   = $settings['dashboard'];
		?>
		<h2><?php esc_html_e( 'Dashboard widget', 'offair' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'offair_save_widget' ); ?>
			<input type="hidden" name="action" value="offair_save_widget">
			<input type="hidden" name="offair[dashboard][sent]" value="1">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Widget', 'offair' ); ?></th>
					<td>
						<label><input type="checkbox" name="offair[dashboard][widget]" value="1" <?php checked( ! empty( $widget['widget'] ) ); ?>> <?php esc_html_e( 'Show the outages of the last 30 days on the dashboard', 'offair' ); ?></label>
						<p class="description">
							<?php
							if ( is_multisite() ) {
								esc_html_e( 'On a network, the widget sits on the dashboard of the network admin, for the network administrators.', 'offair' );
							} else {
								esc_html_e( 'Off by default. Each user can still hide it from the Screen Options of the dashboard.', 'offair' );
							}
							?>
						</p>
					</td>
				</tr>
				<?php if ( ! is_multisite() ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Shown to', 'offair' ); ?></th>
					<td>
						<fieldset>
							<?php foreach ( wp_roles()->get_names() as $role => $name ) : ?>
							<label><input type="checkbox" name="offair[dashboard][roles][]" value="<?php echo esc_attr( $role ); ?>" <?php checked( in_array( $role, (array) $widget['roles'], true ) ); ?>> <?php echo esc_html( translate_user_role( $name ) ); ?></label><br>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Only these roles see the widget. A user who cannot manage Offair sees the outages without the link to this page.', 'offair' ); ?></p>
					</td>
				</tr>
				<?php endif; ?>
			</table>
			<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'offair' ); ?></button></p>
		</form>
		<?php
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
	 * Stores a message and returns to the page.
	 *
	 * @param string $message Message.
	 */
	private function finish( $message ) {
		set_transient( self::NOTICE . get_current_user_id(), $message, 120 );

		wp_safe_redirect( self::url() );
		exit;
	}
}
