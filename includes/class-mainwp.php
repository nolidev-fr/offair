<?php
/**
 * MainWP synchronization.
 *
 * @package Offair
 */

namespace Offair;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the recent outages to the data MainWP Child sends back when the
 * MainWP Dashboard synchronizes the site.
 *
 * Nothing is sent unless the dashboard asks for it with the offair_sync key,
 * which the Offair for MainWP extension does. The request is the one MainWP
 * already makes and authenticates: Offair itself contacts no one.
 *
 * Only the version, the address of the settings page and the outages are
 * sent: the page, the times and the HTTP status of each one. The state of
 * the pages already reaches MainWP through the Site Health test.
 */
class MainWP {

	/**
	 * Version of the format of the answer, raised when a key changes meaning.
	 */
	const FORMAT = 1;

	/**
	 * Key of the answer.
	 */
	const KEY = 'offair';

	/**
	 * Outages older than this, in seconds, are not sent (90 days).
	 */
	const PERIOD = 7776000;

	/**
	 * Largest number of outages sent, besides the short maintenance pages.
	 */
	const MAX_INCIDENTS = 50;

	/**
	 * Largest number of maintenance pages shown less than a minute sent, as
	 * during most updates. Counted apart, so that nightly updates do not push
	 * the real outages out of the list.
	 */
	const MAX_SHORT = 50;

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Registers the filter of MainWP Child.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;

		add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync' ), 10, 2 );
	}

	/**
	 * Adds the Offair data to the answer, when the dashboard asks for it.
	 *
	 * @param array $information Data sent back to the dashboard.
	 * @param array $others_data Extra requests of the dashboard extensions.
	 * @return array
	 */
	public function sync( $information, $others_data = array() ) {
		if ( ! is_array( $others_data ) || ! isset( $others_data['offair_sync'] ) || 'yes' !== $others_data['offair_sync'] ) {
			return $information;
		}

		if ( ! is_array( $information ) ) {
			$information = array();
		}

		$information[ self::KEY ] = $this->data();

		return $information;
	}

	/**
	 * Data sent to the dashboard.
	 *
	 * @return array
	 */
	public function data() {
		$complete = true;

		return array(
			'format'    => self::FORMAT,
			'version'   => OFFAIR_VERSION,
			// Relative to wp-admin. On a network, the settings live in the network admin.
			'settings'  => ( is_multisite() ? 'network/settings.php' : 'options-general.php' ) . '?page=' . Admin_Page::SLUG,
			'incidents' => $this->incidents( $complete ),
			'complete'  => $complete,
		);
	}

	/**
	 * Outages of the period, most recent first.
	 *
	 * @param bool $complete Set to false when outages of the period were left out.
	 * @return array[] Each outage has a screen, a start, an end, a count of
	 *                 recorded minutes and the HTTP status.
	 */
	public function incidents( &$complete = true ) {
		$since     = time() - self::PERIOD;
		$incidents = array();
		$outages   = 0;
		$short     = 0;
		$complete  = true;

		// Every incident of the history, so that the short ones do not use up the limit.
		foreach ( $this->plugin->journal->incidents( PHP_INT_MAX ) as $incident ) {
			if ( (int) $incident['end'] < $since ) {
				continue;
			}

			$is_short = 'maintenance' === $incident['screen'] && (int) $incident['end'] - (int) $incident['start'] < MINUTE_IN_SECONDS;

			if ( $is_short ? ++$short > self::MAX_SHORT : ++$outages > self::MAX_INCIDENTS ) {
				$complete = $complete && $is_short;
				continue;
			}

			$incidents[] = array(
				'screen' => (string) $incident['screen'],
				'start'  => (int) $incident['start'],
				'end'    => (int) $incident['end'],
				'count'  => (int) $incident['count'],
				'status' => (int) $incident['status'],
			);
		}

		return $incidents;
	}
}
