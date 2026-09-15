<?php
/**
 * Source template of the db-error.php drop-in, shown on a database connection error.
 *
 * Compiled by the plugin into wp-content/db-error.php. This source file is
 * never loaded by WordPress itself.
 *
 * @var array $args Template variables, see Generator::template_vars().
 *
 * @package BeRightBack
 */

defined( 'ABSPATH' ) || exit;

require $args['partials'] . 'head.php';
require $args['partials'] . 'content.php';
require $args['partials'] . 'foot.php';
