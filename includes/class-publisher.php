<?php
/**
 * Publishes and removes the pages.
 *
 * @package Offair
 */

namespace Offair;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the drop-ins in wp-content and the page content in uploads in line
 * with the settings, and reports their state.
 */
class Publisher {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Drop-in files.
	 *
	 * @var Dropins
	 */
	private $dropins;

	/**
	 * Page content.
	 *
	 * @var Pages
	 */
	private $pages;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 * @param Dropins  $dropins  Drop-in files.
	 * @param Pages    $pages    Page content.
	 */
	public function __construct( Settings $settings, Dropins $dropins, Pages $pages ) {
		$this->settings = $settings;
		$this->dropins  = $dropins;
		$this->pages    = $pages;
	}

	/**
	 * Saves the page content, then copies or removes each drop-in according
	 * to the settings.
	 *
	 * @param bool $force Replace drop-ins the plugin did not copy.
	 * @return array<string, true|\WP_Error> Result per step: data, then one per screen.
	 */
	public function publish( $force = false ) {
		$this->pages->flush();

		$settings = $this->settings->get();
		$results  = array( 'data' => $this->pages->write() );

		foreach ( array_keys( Dropins::files() ) as $key ) {
			if ( ! empty( $settings[ $key ]['enabled'] ) ) {
				$results[ $key ] = $this->dropins->install( $key, $force );
				continue;
			}

			$info            = $this->dropins->info( $key );
			$results[ $key ] = $info['exists'] && $info['ours'] ? $this->dropins->remove( $key ) : true;
		}

		return $results;
	}

	/**
	 * Removes the drop-ins copied by the plugin. The page content stays in
	 * uploads until the plugin is uninstalled.
	 *
	 * @param bool $force Also remove drop-ins the plugin did not copy.
	 * @return array<string, true|false|\WP_Error> Result per screen.
	 */
	public function unpublish( $force = false ) {
		$results = array();

		foreach ( array_keys( Dropins::files() ) as $key ) {
			$results[ $key ] = $this->dropins->remove( $key, $force );
		}

		return $results;
	}

	/**
	 * State of a page.
	 *
	 * @param string $key Screen key.
	 * @return string One of current, stale, missing, foreign, off.
	 */
	public function state( $key ) {
		$info     = $this->dropins->info( $key );
		$settings = $this->settings->get();
		$enabled  = ! empty( $settings[ $key ]['enabled'] );

		if ( $info['exists'] && ! $info['ours'] ) {
			return 'foreign';
		}
		if ( ! $info['exists'] ) {
			return $enabled ? 'missing' : 'off';
		}
		if ( ! $enabled || ! $info['current'] || ! $this->pages->is_current() ) {
			return 'stale';
		}

		return 'current';
	}

	/**
	 * States of every page.
	 *
	 * @return array<string, string>
	 */
	public function states() {
		$states = array();

		foreach ( array_keys( Dropins::files() ) as $key ) {
			$states[ $key ] = $this->state( $key );
		}

		return $states;
	}

	/**
	 * Human readable label of a state.
	 *
	 * @param string $state State key.
	 * @return string
	 */
	public static function state_label( $state ) {
		$labels = array(
			'current' => __( 'Up to date', 'offair' ),
			'stale'   => __( 'Needs regeneration', 'offair' ),
			'missing' => __( 'Missing', 'offair' ),
			'foreign' => __( 'Not added by this plugin', 'offair' ),
			'off'     => __( 'Disabled', 'offair' ),
		);

		return isset( $labels[ $state ] ) ? $labels[ $state ] : $state;
	}
}
