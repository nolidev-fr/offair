<?php
/**
 * WP-CLI commands.
 *
 * @package BeRightBack
 */

namespace BeRightBack;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the branded downtime pages written to wp-content.
 */
class CLI {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Shows the state of each page.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp be-right-back status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function status( $args, $assoc_args ) {
		$rows = array();

		foreach ( $this->plugin->generator->states() as $key => $state ) {
			$info   = $this->plugin->dropins->info( $key );
			$rows[] = array(
				'page'      => $key,
				'file'      => 'wp-content/' . $info['file'],
				'state'     => $state,
				'version'   => $info['ours'] ? $info['version'] : '',
				'generated' => $info['ours'] ? $info['generated'] : '',
			);
		}

		\WP_CLI\Utils\format_items(
			isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table',
			$rows,
			array( 'page', 'file', 'state', 'version', 'generated' )
		);

		if ( ! $this->plugin->dropins->is_content_writable() ) {
			\WP_CLI::warning( 'wp-content is not writable.' );
		}
	}

	/**
	 * Writes the pages from the current settings.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Replace files in wp-content that were not written by this plugin.
	 *
	 * ## EXAMPLES
	 *
	 *     wp be-right-back generate
	 *     wp be-right-back generate --force
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function generate( $args, $assoc_args ) {
		$force   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		$results = $this->plugin->generator->generate_all( $force );

		$this->report( $results, 'written' );
	}

	/**
	 * Removes the pages written by this plugin.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Also remove files that were not written by this plugin.
	 *
	 * ## EXAMPLES
	 *
	 *     wp be-right-back remove
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function remove( $args, $assoc_args ) {
		$force   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		$results = $this->plugin->generator->remove_all( $force );

		$this->report( $results, 'removed' );
	}

	/**
	 * Prints the generated source of one page.
	 *
	 * ## OPTIONS
	 *
	 * <page>
	 * : Page to print.
	 * ---
	 * options:
	 *   - db
	 *   - maintenance
	 *   - php
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp be-right-back preview db > db-error.php
	 *
	 * @param array $args Positional arguments.
	 */
	public function preview( $args ) {
		$key   = isset( $args[0] ) ? $args[0] : '';
		$files = Dropins::files();

		if ( ! isset( $files[ $key ] ) ) {
			\WP_CLI::error( 'Unknown page. Use db, maintenance or php.' );
		}

		\WP_CLI::line( $this->plugin->generator->source( $key ) );
	}

	/**
	 * Prints one line per page and exits with an error when something failed.
	 *
	 * @param array  $results Result per page.
	 * @param string $verb    Past participle describing a success.
	 */
	private function report( array $results, $verb ) {
		$failed = 0;

		foreach ( $results as $key => $result ) {
			$file = 'wp-content/' . $this->plugin->dropins->file( $key );

			if ( is_wp_error( $result ) ) {
				++$failed;
				\WP_CLI::warning( $result->get_error_message() );
			} elseif ( false === $result ) {
				\WP_CLI::log( $file . ': nothing to do.' );
			} else {
				\WP_CLI::log( $file . ': ' . $verb . '.' );
			}
		}

		if ( $failed > 0 ) {
			\WP_CLI::error( $failed . ' page(s) could not be processed.' );
		}

		\WP_CLI::success( 'Done.' );
	}
}
