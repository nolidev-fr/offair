<?php
/**
 * WP-CLI commands.
 *
 * @package BeRightBack
 */

namespace BeRightBack;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the branded pages shown when WordPress breaks.
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

		foreach ( $this->plugin->publisher->states() as $key => $state ) {
			$info   = $this->plugin->dropins->info( $key );
			$rows[] = array(
				'page'  => $key,
				'file'  => 'wp-content/' . $info['file'],
				'state' => $state,
			);
		}

		\WP_CLI\Utils\format_items(
			isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table',
			$rows,
			array( 'page', 'file', 'state' )
		);

		\WP_CLI::log( 'Content: ' . wp_normalize_path( $this->plugin->pages->path() ) );

		if ( ! $this->plugin->dropins->is_writable() ) {
			\WP_CLI::warning( 'wp-content is not writable.' );
		}
		if ( ! $this->plugin->pages->is_reachable() ) {
			\WP_CLI::warning( 'The uploads folder uses a custom path stored in the database: the pages cannot read their content.' );
		}
	}

	/**
	 * Saves the content of the pages and copies the drop-ins.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Replace files in wp-content that were not added by this plugin.
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
		$results = $this->plugin->publisher->publish( $force );

		$this->report( $results, 'written' );
	}

	/**
	 * Removes the drop-ins added by this plugin.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Also remove files that were not added by this plugin.
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
		$results = $this->plugin->publisher->unpublish( $force );

		$this->report( $results, 'removed' );
	}

	/**
	 * Prints the HTML of one page, as visitors would see it.
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
	 *     wp be-right-back preview db > db-error.html
	 *
	 * @param array $args Positional arguments.
	 */
	public function preview( $args ) {
		$key   = isset( $args[0] ) ? $args[0] : '';
		$files = Dropins::files();

		if ( ! isset( $files[ $key ] ) ) {
			\WP_CLI::error( 'Unknown page. Use db, maintenance or php.' );
		}

		define( 'BE_RIGHT_BACK_PREVIEW', $key );

		include $this->plugin->dropins->source();
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
			$file = 'data' === $key ? wp_normalize_path( $this->plugin->pages->path() ) : 'wp-content/' . $this->plugin->dropins->file( $key );

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
