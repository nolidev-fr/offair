<?php
/**
 * History of the pages shown to visitors.
 *
 * @package Offair
 */

namespace Offair;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the events the drop-in records, groups them into incidents and
 * notices when a database outage is over.
 *
 * The drop-in appends one line per page and per minute at most, however
 * many visitors come: the time, the page and the HTTP status. Nothing about
 * the visitors is recorded. An incident is a run of lines for the same page
 * with no gap longer than GAP.
 *
 * With each line of a PHP error page, the drop-in also appends the error to
 * ERRORS_FILE: its message, file and line, as WordPress handed them over.
 */
class Journal {

	/**
	 * Event file, in the private folder.
	 */
	const FILE = 'events.log';

	/**
	 * Errors behind the PHP error pages, in the private folder.
	 */
	const ERRORS_FILE = 'errors.log';

	/**
	 * Largest number of distinct errors listed for one incident.
	 */
	const MAX_ERRORS = 10;

	/**
	 * Longest silence, in seconds, between two lines of the same incident.
	 */
	const GAP = 600;

	/**
	 * Time without a database error page, in seconds, after which the outage
	 * is considered over.
	 */
	const QUIET = 300;

	/**
	 * Lines older than this, in seconds, are dropped (180 days).
	 */
	const KEEP = 15552000;

	/**
	 * Largest number of lines kept.
	 */
	const MAX_LINES = 20000;

	/**
	 * Time of the last database error page already reported.
	 */
	const REPORTED_OPTION = 'offair_db_reported';

	/**
	 * Last database outage, kept for the notice on the dashboard.
	 */
	const LAST_OPTION = 'offair_last_incident';

	/**
	 * Scheduled check for the end of an outage.
	 */
	const CRON = 'offair_check';

	/**
	 * Interval of the scheduled check.
	 */
	const SCHEDULE = 'offair_fifteen_minutes';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Page content and private folder.
	 *
	 * @var Pages
	 */
	private $pages;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 * @param Pages    $pages    Page content and private folder.
	 */
	public function __construct( Settings $settings, Pages $pages ) {
		$this->settings = $settings;
		$this->pages    = $pages;
	}

	/**
	 * Absolute path of the event file.
	 *
	 * @return string Empty when the private folder does not exist yet.
	 */
	public function path() {
		$dir = $this->pages->private_dir( false );

		return '' === $dir ? '' : $dir . '/' . self::FILE;
	}

	/**
	 * Recorded events, oldest first.
	 *
	 * @return array[] Each event has a time, a screen and a status.
	 */
	public function events() {
		$path = $this->path();

		if ( '' === $path || ! is_readable( $path ) ) {
			return array();
		}

		$lines  = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$events = array();

		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$parts = explode( ' ', trim( $line ) );

			if ( 3 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! in_array( $parts[1], Settings::SCREENS, true ) ) {
				continue;
			}

			$events[] = array(
				'time'   => (int) $parts[0],
				'screen' => $parts[1],
				'status' => (int) $parts[2],
			);
		}

		return $events;
	}

	/**
	 * Errors recorded with the PHP error pages, oldest first.
	 *
	 * @return array[] Each error has a time, a type, a message, a file and a line.
	 */
	public function errors() {
		$dir  = $this->pages->private_dir( false );
		$path = '' === $dir ? '' : $dir . '/' . self::ERRORS_FILE;

		if ( '' === $path || ! is_readable( $path ) ) {
			return array();
		}

		$lines  = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$errors = array();

		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$parts = explode( "\t", $line, 2 );
			$data  = 2 === count( $parts ) && ctype_digit( $parts[0] ) ? json_decode( $parts[1], true ) : null;

			if ( ! is_array( $data ) || ! isset( $data['message'] ) || ! is_string( $data['message'] ) ) {
				continue;
			}

			$errors[] = array(
				'time'    => (int) $parts[0],
				'type'    => isset( $data['type'] ) ? (int) $data['type'] : 0,
				'message' => $data['message'],
				'file'    => isset( $data['file'] ) && is_string( $data['file'] ) ? $data['file'] : '',
				'line'    => isset( $data['line'] ) ? (int) $data['line'] : 0,
			);
		}

		return $errors;
	}

	/**
	 * Distinct errors recorded during an incident, the most frequent first.
	 *
	 * @param array        $incident Incident.
	 * @param array[]|null $errors   Errors from errors(), to read the file once for several incidents.
	 * @return array[] Each error has a type, a message, a file, a line, a count and the time it was last recorded.
	 */
	public function incident_errors( array $incident, $errors = null ) {
		if ( 'php' !== $incident['screen'] ) {
			return array();
		}

		$found = array();

		foreach ( null === $errors ? $this->errors() : $errors as $error ) {
			if ( $error['time'] < (int) $incident['start'] || $error['time'] > (int) $incident['end'] ) {
				continue;
			}

			$key = md5( $error['file'] . '|' . $error['line'] . '|' . $error['message'] );

			if ( isset( $found[ $key ] ) ) {
				++$found[ $key ]['count'];
				$found[ $key ]['last'] = max( $found[ $key ]['last'], $error['time'] );
				continue;
			}

			$found[ $key ] = array(
				'type'    => $error['type'],
				'message' => $error['message'],
				'file'    => $error['file'],
				'line'    => $error['line'],
				'count'   => 1,
				'last'    => $error['time'],
			);
		}

		$found = array_values( $found );

		usort(
			$found,
			static function ( $a, $b ) {
				return $b['count'] - $a['count'] ? $b['count'] - $a['count'] : $b['last'] - $a['last'];
			}
		);

		return array_slice( $found, 0, self::MAX_ERRORS );
	}

	/**
	 * Incidents, most recent first.
	 *
	 * @param int    $limit  Largest number of incidents returned.
	 * @param string $screen Only this screen, or every screen when empty.
	 * @return array[] Each incident has a screen, a start, an end, a count of
	 *                 recorded minutes and the HTTP status.
	 */
	public function incidents( $limit = 50, $screen = '' ) {
		$open      = array();
		$incidents = array();

		foreach ( $this->events() as $event ) {
			$key = $event['screen'];

			if ( '' !== $screen && $screen !== $key ) {
				continue;
			}

			if ( isset( $open[ $key ] ) && $event['time'] - $open[ $key ]['end'] <= self::GAP ) {
				$open[ $key ]['end'] = max( $open[ $key ]['end'], $event['time'] );
				++$open[ $key ]['count'];
				continue;
			}

			if ( isset( $open[ $key ] ) ) {
				$incidents[] = $open[ $key ];
			}

			$open[ $key ] = array(
				'screen' => $key,
				'start'  => $event['time'],
				'end'    => $event['time'],
				'count'  => 1,
				'status' => $event['status'],
			);
		}

		$incidents = array_merge( $incidents, array_values( $open ) );

		usort(
			$incidents,
			static function ( $a, $b ) {
				return $b['start'] - $a['start'];
			}
		);

		return array_slice( $incidents, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Drops old lines and keeps the file small. Called by WordPress, never by
	 * the drop-in, which only appends.
	 */
	public function prune() {
		$this->prune_errors();

		$path   = $this->path();
		$events = $this->events();

		if ( '' === $path || ! $events ) {
			return;
		}

		$limit = time() - self::KEEP;
		$kept  = array_values(
			array_filter(
				$events,
				static function ( $event ) use ( $limit ) {
					return $event['time'] >= $limit;
				}
			)
		);
		$kept  = array_slice( $kept, -self::MAX_LINES );

		if ( count( $kept ) === count( $events ) ) {
			return;
		}

		$lines = '';
		foreach ( $kept as $event ) {
			$lines .= $event['time'] . ' ' . $event['screen'] . ' ' . $event['status'] . "\n";
		}

		$filesystem = Filesystem::get();

		if ( null !== $filesystem ) {
			$filesystem->put_contents( $path, $lines, FS_CHMOD_FILE );
		}
	}

	/**
	 * Drops the old errors, like the old lines of the history.
	 */
	private function prune_errors() {
		$dir    = $this->pages->private_dir( false );
		$errors = $this->errors();

		if ( '' === $dir || ! $errors ) {
			return;
		}

		$limit = time() - self::KEEP;
		$kept  = array_values(
			array_filter(
				$errors,
				static function ( $error ) use ( $limit ) {
					return $error['time'] >= $limit;
				}
			)
		);
		$kept  = array_slice( $kept, -self::MAX_LINES );

		if ( count( $kept ) === count( $errors ) ) {
			return;
		}

		$lines = '';
		foreach ( $kept as $error ) {
			$time = $error['time'];
			unset( $error['time'] );
			$lines .= $time . "\t" . wp_json_encode( $error, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		}

		$filesystem = Filesystem::get();

		if ( null !== $filesystem ) {
			$filesystem->put_contents( $dir . '/' . self::ERRORS_FILE, $lines, FS_CHMOD_FILE );
		}
	}

	/**
	 * Deletes the history.
	 */
	public function clear() {
		$dir = $this->pages->private_dir( false );

		if ( '' !== $dir ) {
			wp_delete_file( $dir . '/' . self::FILE );
			wp_delete_file( $dir . '/' . self::ERRORS_FILE );
		}

		Settings::delete_option( self::LAST_OPTION );
	}

	/**
	 * Notices the end of a database outage: fires offair_incident_resolved
	 * and sends the report when the alert is on.
	 *
	 * The drop-in touches seen-db each time it records a database error page.
	 * Once that file is older than QUIET and newer than the last report, the
	 * outage it belongs to is over and has not been reported yet.
	 */
	public function check() {
		$dir = $this->pages->private_dir( false );

		if ( '' === $dir || ! is_file( $dir . '/seen-db' ) ) {
			return;
		}

		clearstatcache( true, $dir . '/seen-db' );

		$last     = (int) filemtime( $dir . '/seen-db' );
		$reported = (int) Settings::get_option( self::REPORTED_OPTION, 0 );

		if ( $last <= $reported || $last > time() - self::QUIET ) {
			return;
		}

		// Recorded first, so that a check running at the same time does not report twice.
		Settings::update_option( self::REPORTED_OPTION, $last, false );

		$incidents = $this->incidents( 1, 'db' );

		if ( ! $incidents ) {
			return;
		}

		$incident = $incidents[0];

		Settings::update_option( self::LAST_OPTION, $incident, false );

		/**
		 * Fires once a database outage is over, when WordPress runs again.
		 *
		 * The times come from the database error pages shown to visitors: an
		 * outage while nobody visits the site cannot be seen.
		 *
		 * @param array $incident {
		 *     @type string $screen Always "db".
		 *     @type int    $start  Unix time of the first page shown.
		 *     @type int    $end    Unix time of the last page shown.
		 *     @type int    $count  Number of minutes with at least one page shown.
		 *     @type int    $status HTTP status sent to visitors.
		 * }
		 */
		do_action( 'offair_incident_resolved', $incident );

		$settings = $this->settings->get();
		$to       = Settings::alert_recipient( $settings['db'] );

		if ( ! empty( $settings['db']['alert'] ) && '' !== $to ) {
			Alert::send_report( $incident, $to );
		}

		$this->prune();
	}

	/**
	 * Last database outage, for the notice on the dashboard.
	 *
	 * @return array|null
	 */
	public static function last_incident() {
		$incident = Settings::get_option( self::LAST_OPTION, null );

		return is_array( $incident ) && isset( $incident['start'], $incident['end'] ) ? $incident : null;
	}

	/**
	 * Duration of an incident, in words.
	 *
	 * @param array $incident Incident.
	 * @return string
	 */
	public static function duration( array $incident ) {
		$seconds = (int) $incident['end'] - (int) $incident['start'];

		if ( $seconds < MINUTE_IN_SECONDS ) {
			return __( 'less than a minute', 'offair' );
		}

		/* translators: %s: duration, for example "15 mins". */
		return sprintf( __( 'about %s', 'offair' ), human_time_diff( (int) $incident['start'], (int) $incident['end'] ) );
	}

	/**
	 * Adds the interval of the scheduled check.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function add_schedule( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes (Offair)', 'offair' ),
		);

		return $schedules;
	}

	/**
	 * Schedules the check, on the main site only: the drop-ins and the
	 * private folder are shared by the whole network.
	 */
	public static function schedule() {
		if ( is_main_site() && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::CRON );
		}
	}

	/**
	 * Removes the scheduled check.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON );
	}
}
