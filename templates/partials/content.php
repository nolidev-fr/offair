<?php
/**
 * Body of the card shared by the three pages: title, message, button,
 * contact line and the incident line.
 *
 * The $args['runtime'] entries are PHP snippets written by the plugin and
 * executed when the page is served, which is why they are printed as is.
 *
 * @var array $args Template variables, see Generator::template_vars().
 *
 * @package BeRightBack
 */

defined( 'ABSPATH' ) || exit;
?>
	<h1 class="brb-title"><?php echo esc_html( $args['title'] ); ?></h1>
	<?php if ( '' !== $args['message_html'] ) : ?>
	<div class="brb-message">
		<?php
		echo wp_kses(
			$args['message_html'],
			array(
				'p'  => array(),
				'br' => array(),
			)
		);
		?>
	</div>
	<?php endif; ?>
	<?php if ( '' !== $args['button_label'] ) : ?>
	<a class="brb-button" href="<?php echo $args['runtime']['href']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PHP snippet written by the plugin, escaped at request time. ?>"><?php echo esc_html( $args['button_label'] ); ?></a>
	<?php endif; ?>
	<?php if ( '' !== $args['contact_html'] ) : ?>
	<p class="brb-contact"><?php echo wp_kses( $args['contact_html'], array( 'a' => array( 'href' => array() ) ) ); ?></p>
	<?php endif; ?>
	<?php echo $args['runtime']['notice']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PHP snippet written by the plugin, escaped at request time. ?>
	<?php echo $args['runtime']['detail']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PHP snippet written by the plugin, escaped at request time. ?>
	<?php if ( ! empty( $args['show_meta'] ) ) : ?>
	<p class="brb-meta">
		<?php
		printf(
			/* translators: 1: local time of the incident (computed when the page is served), 2: timezone name, 3: sentence describing the incident. */
			esc_html__( 'Incident recorded at %1$s (%2$s). %3$s', 'be-right-back' ),
			$args['runtime']['time'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PHP snippet written by the plugin, escaped at request time.
			esc_html( $args['timezone'] ),
			esc_html( $args['status_label'] )
		);
		?>
	</p>
	<?php endif; ?>
