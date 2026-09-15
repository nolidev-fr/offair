<?php
/**
 * Source template of the maintenance.php drop-in, shown on a maintenance mode.
 *
 * Compiled by the plugin into wp-content/maintenance.php. This source file is
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
