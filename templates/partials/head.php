<?php
/**
 * Opening markup shared by the three pages: document head, styles, card,
 * logo, site name and ornament.
 *
 * Rendered at generation time. Everything printed here ends up as static
 * HTML in the drop-in, except the $args['runtime'] snippets, which are PHP
 * code written by the plugin itself and run when the page is served.
 *
 * @var array $args Template variables, see Generator::template_vars().
 *
 * @package BeRightBack
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $args['lang'] ); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<?php if ( $args['refresh_delay'] > 0 ) : ?>
<meta http-equiv="refresh" content="<?php echo (int) $args['refresh_delay']; ?>">
<?php endif; ?>
<title><?php echo esc_html( $args['title'] . ' · ' . $args['site_name'] ); ?></title>
<?php if ( '' !== $args['favicon'] ) : ?>
<link rel="icon" href="<?php echo esc_attr( $args['favicon'] ); ?>">
<?php endif; ?>
<style>
	:root {
		--brb-primary: <?php echo esc_attr( $args['colors']['primary'] ); ?>;
		--brb-primary-hover: <?php echo esc_attr( $args['colors']['primary_hover'] ); ?>;
		--brb-primary-text: <?php echo esc_attr( $args['colors']['primary_text'] ); ?>;
		--brb-on-primary: <?php echo esc_attr( $args['colors']['on_primary'] ); ?>;
		--brb-background: <?php echo esc_attr( $args['colors']['background'] ); ?>;
		--brb-shadow: <?php echo esc_attr( $args['colors']['shadow'] ); ?>;
	}
	*, *::before, *::after { box-sizing: border-box; }
	html, body { margin: 0; padding: 0; min-height: 100%; }
	body {
		font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
		color: #555;
		background-color: var(--brb-background);
		background-image: linear-gradient(180deg, rgba(255, 255, 255, 0.85) 0%, rgba(255, 255, 255, 0) 100%);
		display: flex;
		align-items: center;
		justify-content: center;
		min-height: 100vh;
		padding: 24px 16px;
		line-height: 1.6;
		-webkit-font-smoothing: antialiased;
	}
	.brb-font-serif .brb-brand,
	.brb-font-serif .brb-title {
		font-family: Georgia, "Iowan Old Style", "Palatino Linotype", Palatino, "Book Antiqua", "Times New Roman", serif;
	}
	.brb-font-sans .brb-brand,
	.brb-font-sans .brb-title {
		font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
	}
	.brb-card {
		background: #fff;
		max-width: 520px;
		width: 100%;
		padding: 48px 32px 36px;
		border-radius: 16px;
		box-shadow: 0 12px 40px var(--brb-shadow);
		text-align: center;
	}
	.brb-logo {
		display: block;
		margin: 0 auto 20px;
		width: auto;
		height: auto;
		max-width: 160px;
		max-height: 120px;
	}
	.brb-brand {
		font-size: 15px;
		letter-spacing: 0.18em;
		text-transform: uppercase;
		color: var(--brb-primary-text);
		margin: 0 0 24px;
	}
	.brb-ornament {
		display: block;
		margin: 0 auto 24px;
		color: var(--brb-primary-text);
	}
	.brb-ornament-wave { width: 72px; height: 14px; }
	.brb-ornament-line { width: 40px; height: 2px; background: var(--brb-primary-text); border-radius: 2px; }
	.brb-title {
		font-weight: 600;
		font-size: 26px;
		line-height: 1.3;
		color: #222;
		margin: 0 0 16px;
	}
	.brb-message p { margin: 0 0 14px; font-size: 16px; }
	.brb-button {
		display: inline-block;
		margin: 18px 0 8px;
		padding: 12px 28px;
		background: var(--brb-primary);
		color: var(--brb-on-primary);
		text-decoration: none;
		border-radius: 999px;
		font-size: 15px;
		letter-spacing: 0.04em;
		transition: background 0.15s ease;
	}
	.brb-button:hover, .brb-button:focus { background: var(--brb-primary-hover); }
	.brb-button:focus-visible { outline: 2px solid var(--brb-primary-text); outline-offset: 3px; }
	.brb-contact { font-size: 14px; color: #777; margin: 12px 0 0; }
	.brb-contact a { color: var(--brb-primary-text); }
	.brb-meta { font-size: 13px; color: #999; margin: 20px 0 0; }
	.brb-notice {
		font-size: 14px;
		color: #7a4f00;
		background: #fff6dd;
		border-radius: 8px;
		padding: 10px 14px;
		margin: 20px 0 0;
		text-align: left;
	}
	.brb-detail {
		font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
		font-size: 12px;
		line-height: 1.5;
		color: #333;
		background: #f4f4f4;
		border-radius: 8px;
		padding: 12px 14px;
		margin: 20px 0 0;
		text-align: left;
		white-space: pre-wrap;
		word-break: break-word;
	}
	@media (max-width: 420px) {
		.brb-card { padding: 36px 20px 28px; }
		.brb-title { font-size: 22px; }
	}
</style>
</head>
<body class="<?php echo esc_attr( 'brb-font-' . $args['heading_font'] . ' brb-screen-' . $args['screen'] ); ?>">
<main class="brb-card">
	<?php if ( ! empty( $args['logo']['src'] ) ) : ?>
	<img class="brb-logo" src="<?php echo esc_attr( $args['logo']['src'] ); ?>" alt="<?php echo esc_attr( $args['site_name'] ); ?>"<?php echo ! empty( $args['logo']['width'] ) ? ' width="' . (int) $args['logo']['width'] . '" height="' . (int) $args['logo']['height'] . '"' : ''; ?>>
	<?php endif; ?>
	<?php if ( ! empty( $args['show_name'] ) && '' !== $args['site_name'] ) : ?>
	<p class="brb-brand"><?php echo esc_html( $args['site_name'] ); ?></p>
	<?php endif; ?>
	<?php if ( 'wave' === $args['ornament'] ) : ?>
	<svg class="brb-ornament brb-ornament-wave" viewBox="0 0 72 14" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M2 8c6-8 12-8 18 0s12 8 18 0 12-8 18 0 12 8 14 0"/></svg>
	<?php elseif ( 'line' === $args['ornament'] ) : ?>
	<span class="brb-ornament brb-ornament-line" aria-hidden="true"></span>
	<?php endif; ?>
