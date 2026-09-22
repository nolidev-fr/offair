<?php
/**
 * Email alerts about database outages.
 *
 * @package Offair
 */

namespace Offair;

defined( 'ABSPATH' ) || exit;

/**
 * Prepares the alert the drop-in sends while the database is down, sends
 * the test from the settings page and the report sent once the site is back.
 *
 * During the outage WordPress cannot run, so the drop-in sends the alert
 * with the mail() function of PHP. Everything it needs is prepared here, in
 * the language of the site, and saved in the private data file. The report
 * is sent later by WordPress itself, with wp_mail(), so it goes through the
 * same route as the other emails of the site.
 */
class Alert {

	/**
	 * Email the drop-in sends when the database cannot be reached. The drop-in
	 * only replaces {time} with the local time of the page it shows.
	 *
	 * @param string $to   Recipient.
	 * @param bool   $test Whether this is the test sent from the settings page.
	 * @return array{to: string, subject: string, body: string, headers: string}
	 */
	public static function outage_email( $to, $test = false ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site title. */
		$subject = sprintf( __( '[%s] The database cannot be reached', 'offair' ), $site );
		$lines   = array(
			/* translators: 1: address of the site, 2: date and time of the page shown to a visitor. */
			sprintf( __( 'The database of %1$s could not be reached at %2$s. Visitors see the database error page instead of the site.', 'offair' ), home_url( '/' ), '{time}' ),
			__( 'While the problem lasts, a new alert is sent at most once an hour. A report follows once the site is back.', 'offair' ),
			__( 'Sent by Offair from the database error page.', 'offair' ),
		);

		if ( $test ) {
			/* translators: %s: subject of the alert. */
			$subject = sprintf( __( 'Test: %s', 'offair' ), $subject );
			array_unshift( $lines, __( 'This is a test sent from the settings of Offair. A real alert looks like this and travels the same way.', 'offair' ) );
		}

		return array(
			'to'      => $to,
			'subject' => self::encode_header( $subject ),
			'body'    => implode( "\n\n", $lines ),
			'headers' => implode(
				"\r\n",
				array(
					'From: ' . self::from(),
					'MIME-Version: 1.0',
					'Content-Type: text/plain; charset=UTF-8',
					'Content-Transfer-Encoding: 8bit',
				)
			),
		);
	}

	/**
	 * Sends the test alert the way the drop-in would, with mail().
	 *
	 * @param string $to Recipient.
	 * @return bool Whether the server accepted the email. Delivery is not
	 *              guaranteed: the recipient still has to check the inbox.
	 */
	public static function send_test( $to ) {
		$email = self::outage_email( $to, true );
		$body  = str_replace( '{time}', self::local_time( time() ), $email['body'] );

		if ( ! function_exists( 'mail' ) ) {
			return false;
		}

		// Same function as the drop-in: wp_mail() cannot run during an outage, so testing it would prove nothing.
		return mail( $email['to'], $email['subject'], $body, $email['headers'] );
	}

	/**
	 * Report sent through WordPress once the database is back.
	 *
	 * @param array  $incident Incident, as built by Journal::incidents().
	 * @param string $to       Recipient.
	 * @return bool
	 */
	public static function send_report( array $incident, $to ) {
		$switched = determine_locale() !== get_locale() && switch_to_locale( get_locale() );
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$format   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		/* translators: %s: site title. */
		$subject = sprintf( __( '[%s] The site is back', 'offair' ), $site );
		$body    = implode(
			"\n\n",
			array(
				sprintf(
					/* translators: 1: address of the site, 2: date and time of the first page shown, 3: time of the last page shown, 4: duration, for example "about 15 minutes". */
					__( 'The database of %1$s could not be reached from %2$s to %3$s (%4$s). Offair showed the database error page to visitors during that time.', 'offair' ),
					home_url( '/' ),
					wp_date( $format, $incident['start'] ),
					wp_date( get_option( 'time_format' ), $incident['end'] ),
					Journal::duration( $incident )
				),
				__( 'These times come from the pages shown to visitors: while nobody visits the site, an outage cannot be seen.', 'offair' ),
				/* translators: %s: address of the history in the settings. */
				sprintf( __( 'History of the outages: %s', 'offair' ), Admin_Page::url( 'history' ) ),
			)
		);

		$sent = wp_mail( $to, $subject, $body );

		if ( $switched ) {
			restore_previous_locale();
		}

		return $sent;
	}

	/**
	 * Local date and time, as written in the alert: numeric, because the
	 * drop-in cannot translate month names.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	public static function local_time( $timestamp ) {
		return wp_date( 'Y-m-d H:i', $timestamp ) . ' (' . wp_timezone_string() . ')';
	}

	/**
	 * Sender of the alert: the one WordPress would use, including the changes
	 * an email plugin makes to it, so that the alert is not treated as spam
	 * more often than the other emails of the site.
	 *
	 * @return string
	 */
	private static function from() {
		$host = wp_parse_url( network_home_url(), PHP_URL_HOST );
		$host = is_string( $host ) ? preg_replace( '/^www\./', '', $host ) : 'localhost';

		/** This filter is documented in wp-includes/pluggable.php */
		$email = apply_filters( 'wp_mail_from', 'wordpress@' . $host ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, applied the way wp_mail() applies it.
		/** This filter is documented in wp-includes/pluggable.php */
		$name = apply_filters( 'wp_mail_from_name', 'WordPress' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, applied the way wp_mail() applies it.

		$email = is_email( $email ) ? $email : 'wordpress@' . $host;
		$name  = trim( str_replace( array( "\r", "\n", '"' ), '', (string) $name ) );

		return '' === $name ? $email : self::encode_header( $name ) . ' <' . $email . '>';
	}

	/**
	 * Header value that survives non-ASCII characters, as RFC 2047 requires.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function encode_header( $text ) {
		$text = str_replace( array( "\r", "\n" ), ' ', (string) $text );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- MIME encoded word, not obfuscation.
		return preg_match( '/[^\x20-\x7e]/', $text ) ? '=?UTF-8?B?' . base64_encode( $text ) . '?=' : $text;
	}
}
