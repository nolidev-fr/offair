# Offair - Branded Error Pages

Branded pages for the screens WordPress shows when it breaks: database connection errors, fatal PHP errors and the update notice. Works even when WordPress cannot load.

When WordPress breaks, it shows screens of its own that no theme and no plugin can style, because they appear before any of them is loaded. Offair replaces them with a calm page in your colors, with your logo and your words.

| Screen | Shown when | HTTP answer |
| --- | --- | --- |
| Database error | WordPress cannot reach the database | 503 with Retry-After |
| Update notice | WordPress installs updates | 503 with Retry-After |
| PHP error | A fatal PHP error stops a page | 503 (default) or 500 |

This is not a maintenance mode or coming soon plugin. It does not take a site offline. It only covers the moments when WordPress itself cannot serve the site.

## How it works

WordPress looks for three drop-ins in `wp-content`: `db-error.php`, `maintenance.php` and `php-error.php`. It loads them itself, before any plugin, which is the only way to show something when the database is down.

The plugin copies one static file, [`dropins/drop-in.php`](dropins/drop-in.php), under these three names. No code is generated. The drop-in uses plain PHP only, since WordPress functions are not available at that point, and escapes every value it prints.

The content of the pages (texts in the language of the site, colors, logo as a data URI) is saved as JSON in `wp-content/uploads/offair/pages.json`, because the database may be the very thing that is down. When that file is missing, the drop-in shows a neutral English page.

On a network, each site also gets `sites/<id>.json`, built in its own language with its title, logo and timezone, and `sites.json` lists the address of every site. WordPress has not worked out which site is asked for when the database is down, so the drop-in compares the address with that list and picks the page of the site, or the page of the main site for an unknown address.

Anyone can read a file in uploads when they know its address, and `pages.json` has a known address. What visitors must not see (the alert recipient, the folders of the theme, the history) goes in `wp-content/uploads/offair/private-<random>/`, a folder whose name cannot be guessed. The drop-in finds it by its prefix.

## Features

- Four layouts for the three screens (card, minimal, two columns, banner), each with the logo, the site name, the title, the message, a retry button and an incident line with the local time.
- Automatic dark mode following the device of the visitor, or always light, or always dark. Brand colors used for text are lightened in dark mode to stay readable, and an optional logo for dark backgrounds replaces the logo there.
- On a network, a page for each site with its own title, logo, language, timezone and theme templates.
- Logo and colors detected from the theme, the site icon and Elementor global colors, editable at any time. Pale brand colors are darkened for text, and the button picks white or dark text by itself, slightly darkening a mid-tone color when needed, so the page stays readable.
- Texts follow the language of the site until you customize them. French translation included.
- Live preview beside the settings, rendered by the drop-in itself. It follows what you type: trying out colors, layouts or texts writes nothing until you save. A phone width is one click away.
- Status box telling you whether each page is in place and up to date. A file the plugin did not add is never replaced without your say.
- Email alert when the database goes down, off by default. The drop-in sends it with `mail()`, since WordPress cannot run during the outage, at most once an hour. Once the site is back, a scheduled check sends a report through `wp_mail()` with the duration of the outage.
- History of the pages shown to visitors, in its own tab and with `wp offair history`: the time, the page and the HTTP status, once a minute at most per page. Nothing about the visitors is recorded. A dashboard notice reports a database outage an administrator missed.
- Theme templates: a theme can replace any of the three pages with its own design (see below).
- Site Health test, WP-CLI commands, filters and an action for developers.
- Clean removal: drop-ins removed on deactivation, everything removed on uninstall.

## Installation

1. Install and activate the plugin from the [WordPress.org plugin directory](https://wordpress.org/plugins/offair/), or search for Offair in Plugins, Add New.
2. Open Settings, Offair. The logo and primary color of the site are pre-filled when they can be detected.
3. Adjust the texts and colors, save. The preview shows the result.

If `wp-content` is not writable, download the drop-ins from the settings page and upload them yourself, once. Later changes are saved in the uploads folder.

Requires WordPress 6.0 and PHP 7.4 or later.

## WP-CLI

```
wp offair status
wp offair generate [--force]
wp offair remove [--force]
wp offair preview <db|maintenance|php>
wp offair history [--format=<table|json|csv>]
```

## Theme templates

A theme or child theme can design the pages itself. The drop-in looks for a file named after it in an `offair` folder of the active theme, then of its parent theme:

```
wp-content/themes/your-theme/offair/db-error.php
wp-content/themes/your-theme/offair/maintenance.php
wp-content/themes/your-theme/offair/php-error.php
```

WordPress is not loaded when these pages are shown, so a template can only use plain PHP. The drop-in has already sent the HTTP status and the no-cache headers. The template prints the whole HTML document and receives one variable, `$offair`:

| Key | Content |
| --- | --- |
| `screen` | `db`, `maintenance` or `php` |
| `status` | HTTP status sent, 503 or 500 |
| `lang` | Language of the site, for the `lang` attribute |
| `site_name`, `show_name` | Displayed name and whether to show it |
| `title`, `paragraphs`, `button` | Title, message split into paragraphs, label of the retry button (empty when hidden) |
| `contact` | Contact line, as parts with a `text` and, for links, an `href` |
| `meta` | Incident line, with the local time filled in (empty when hidden) |
| `notice`, `detail` | Recovery mode notice and technical details of a PHP error, when WordPress would show them |
| `refresh` | Automatic refresh delay in seconds, 0 when off |
| `logo`, `logo_dark`, `favicon` | Logo and logo for dark backgrounds (`src` data URI, `width`, `height`) or null, favicon data URI or empty |
| `colors` | `primary`, `primary_hover`, `primary_text` (readable on white), `primary_dark` (readable on the dark surface), `on_primary`, `background` |
| `heading_font`, `ornament` | `serif` or `sans`, and `wave`, `line` or `none` |
| `layout`, `color_scheme` | `card`, `minimal`, `split` or `banner`, and `auto`, `light` or `dark` |
| `head` | Built-in `<head>` content: meta tags, refresh, title, favicon and styles |
| `main` | Built-in `<main class="offair-page">` markup, arranged by the styles for the chosen layout |
| `body_class` | Classes the built-in page puts on `<body>`, which the styles rely on |
| `css` | Built-in stylesheet |
| `escape` | Function that escapes a value for HTML |

Every text value is raw: print it through `$offair['escape']`. `head` and `main` are already escaped. A minimal template that keeps the built-in page and adds a line above it:

```php
<?php $e = $offair['escape']; ?>
<!DOCTYPE html>
<html lang="<?php echo $e( $offair['lang'] ); ?>">
<head>
<?php echo $offair['head']; ?>
</head>
<body class="<?php echo $e( $offair['body_class'] ); ?>">
<p class="banner"><?php echo $e( $offair['site_name'] ); ?></p>
<?php echo $offair['main']; ?>
</body>
</html>
```

If the template throws an error, has a syntax error or prints nothing, the built-in page is shown instead. The path is checked against `wp-content/themes`, so a theme stored elsewhere cannot provide templates. On a network, the templates come from the theme of the site asked for.

## Hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `offair_settings` | Filter | Settings right before the page content is built |
| `offair_data` | Filter | Page content before it is saved for the drop-in |
| `offair_incident_resolved` | Action | Fires once a database outage is over, with an array holding `start` and `end` (Unix times of the first and last pages shown), `count` (minutes with a page shown) and `status` |

## Limits worth knowing

- Pages served from a full page cache (LiteSpeed Cache, WP Rocket, Cloudflare) keep being served normally during an outage. Only requests that reach PHP see the outage page, which is what you want.
- A web server or PHP outage is not covered. Only the host can show a page in that case.
- The history and the alert only see outages while someone visits the site: they rely on the pages actually shown.
- During an outage the alert is sent with the `mail()` function of PHP, which some hosts block or send to spam. The test button in the Database error tab uses the same path. The report sent once the site is back uses `wp_mail()` and any email plugin.
- WordPress can only show an error page for a fatal error that happens before the page started being sent. When PHP prints errors on screen and does not buffer its output, no error page can be shown at all. The settings page and Site Health detect that combination and explain the fix.

## Development

```
composer install
composer lint
```

The `readme.txt` file is the WordPress.org readme. Coding standards are checked with PHP_CodeSniffer and the WordPress ruleset, and a GitHub Actions workflow runs a syntax check on PHP 7.4 to 8.4.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
