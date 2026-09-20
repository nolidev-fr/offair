<?php
/**
 * Site Health test.
 *
 * @package Offair
 */

namespace Offair;

defined( 'ABSPATH' ) || exit;

/**
 * Reports missing, outdated or foreign drop-ins in Tools, Site Health.
 */
class Site_Health {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Registers the test.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;

		add_filter( 'site_status_tests', array( $this, 'register' ) );
	}

	/**
	 * Adds the test to the direct tests.
	 *
	 * @param array $tests Registered tests.
	 * @return array
	 */
	public function register( $tests ) {
		$tests['direct']['offair'] = array(
			'label' => __( 'Offair', 'offair' ),
			'test'  => array( $this, 'run' ),
		);

		return $tests;
	}

	/**
	 * Runs the test.
	 *
	 * @return array Site Health result.
	 */
	public function run() {
		$labels   = Settings::screen_labels();
		$states   = $this->plugin->publisher->states();
		$problems = array();

		foreach ( $states as $key => $state ) {
			$label = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
			$file  = $this->plugin->dropins->file( $key );

			switch ( $state ) {
				case 'missing':
					/* translators: 1: page name, 2: file name. */
					$problems[] = sprintf( __( 'The %1$s page is enabled but wp-content/%2$s is missing.', 'offair' ), $label, $file );
					break;
				case 'stale':
					/* translators: 1: page name, 2: file name. */
					$problems[] = sprintf( __( 'wp-content/%2$s does not match the current settings of the %1$s page.', 'offair' ), $label, $file );
					break;
				case 'foreign':
					/* translators: %s: file name. */
					$problems[] = sprintf( __( 'wp-content/%s was not added by Offair. The plugin leaves it untouched, so this page is not branded.', 'offair' ), $file );
					break;
			}
		}

		if ( Environment::php_error_page_blocked() ) {
			$explanation = Environment::php_error_page_explanation();
			$problems[]  = __( 'The PHP error page cannot be shown with the current PHP configuration.', 'offair' ) . ' ' . $explanation[0] . ' ' . $explanation[2];
		}

		if ( ! $this->plugin->pages->is_reachable() ) {
			$problems[] = __( 'The uploads folder uses a custom path stored in the database, so the pages cannot read their content and show a neutral English page instead.', 'offair' );
		}

		if ( ! $this->plugin->dropins->is_writable() && $problems ) {
			$problems[] = __( 'wp-content is not writable, so the pages cannot be written automatically. Download them from the settings page and upload them yourself.', 'offair' );
		}

		$settings_url = is_multisite()
			? network_admin_url( 'settings.php?page=' . Admin_Page::SLUG )
			: admin_url( 'options-general.php?page=' . Admin_Page::SLUG );

		$result = array(
			'label'       => __( 'Your downtime pages are in place', 'offair' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Offair', 'offair' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Visitors see a branded page when the database is unreachable, during updates and on fatal errors.', 'offair' ) . '</p>',
			'actions'     => '<p><a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Open the Offair settings', 'offair' ) . '</a></p>',
			'test'        => 'offair',
		);

		if ( $problems ) {
			$result['label']       = __( 'Some downtime pages need attention', 'offair' );
			$result['status']      = 'recommended';
			$result['description'] = '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $problems ) ) . '</li></ul>';
		}

		return $result;
	}
}
