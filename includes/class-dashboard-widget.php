<?php
/**
 * Dashboard widget.
 *
 * @package Offair
 */

namespace Offair;

defined( 'ABSPATH' ) || exit;

/**
 * Outages of the last 30 days on the dashboard, for the roles chosen on the
 * history page. Off by default: a site owner may not want the editors, or
 * the clients they give an account to, to see them.
 *
 * On a network the history is shared by every site, so the widget sits on
 * the dashboard of the network admin only.
 */
class Dashboard_Widget {

	/**
	 * Widget ID.
	 */
	const ID = 'offair_outages';

	/**
	 * Period covered, in seconds (30 days).
	 */
	const PERIOD = 2592000;

	/**
	 * Largest number of outages listed.
	 */
	const MAX_ROWS = 5;

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Registers the hooks.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( is_multisite() ? 'wp_network_dashboard_setup' : 'wp_dashboard_setup', array( $this, 'register' ) );
	}

	/**
	 * Whether the current user sees the widget.
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	public static function visible( array $settings ) {
		if ( empty( $settings['dashboard']['widget'] ) ) {
			return false;
		}

		if ( is_multisite() ) {
			return current_user_can( Settings::capability() );
		}

		$user = wp_get_current_user();

		return (bool) array_intersect( (array) $user->roles, (array) $settings['dashboard']['roles'] );
	}

	/**
	 * Adds the widget for the users who may see it.
	 */
	public function register() {
		if ( ! self::visible( $this->plugin->settings->get() ) ) {
			return;
		}

		wp_add_dashboard_widget( self::ID, __( 'Outages seen by visitors', 'offair' ), array( $this, 'render' ) );
	}

	/**
	 * Renders the widget.
	 */
	public function render() {
		$since     = time() - self::PERIOD;
		$labels    = Settings::screen_labels();
		$format    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$incidents = array();
		$short     = 0;

		foreach ( $this->plugin->journal->incidents( 200 ) as $incident ) {
			if ( (int) $incident['end'] < $since ) {
				continue;
			}

			// A maintenance page shown less than a minute is a routine update.
			if ( 'maintenance' === $incident['screen'] && (int) $incident['end'] - (int) $incident['start'] < MINUTE_IN_SECONDS ) {
				++$short;
				continue;
			}

			$incidents[] = $incident;
		}

		if ( ! $incidents ) {
			echo '<p>' . esc_html__( 'No outage in the last 30 days.', 'offair' ) . '</p>';
		} else {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %d: number of outages. */
					_n( '%d outage in the last 30 days.', '%d outages in the last 30 days.', count( $incidents ), 'offair' ),
					count( $incidents )
				)
			) . '</p>';
			?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Page', 'offair' ); ?></th>
						<th><?php esc_html_e( 'Started', 'offair' ); ?></th>
						<th><?php esc_html_e( 'Observed duration', 'offair' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( array_slice( $incidents, 0, self::MAX_ROWS ) as $incident ) : ?>
					<tr>
						<td><?php echo esc_html( isset( $labels[ $incident['screen'] ] ) ? $labels[ $incident['screen'] ] : $incident['screen'] ); ?></td>
						<td><?php echo esc_html( wp_date( $format, $incident['start'] ) ); ?></td>
						<td><?php echo esc_html( Journal::duration( $incident ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}

		if ( $short > 0 ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %d: number of maintenance pages. */
					_n( 'Plus %d maintenance page shown less than a minute, during an update.', 'Plus %d maintenance pages shown less than a minute, during updates.', $short, 'offair' ),
					$short
				)
			) . '</p>';
		}

		if ( current_user_can( Settings::capability() ) ) {
			echo '<p><a href="' . esc_url( History_Page::url() ) . '">' . esc_html__( 'See the history', 'offair' ) . '</a></p>';
		}
	}
}
