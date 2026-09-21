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

## Features

- One design for the three screens: centered card, logo, site name, title, message, retry button and an incident line with the local time.
- Logo and colors detected from the theme, the site icon and Elementor global colors, editable at any time. Pale brand colors are darkened for text, and the button picks white or dark text by itself, slightly darkening a mid-tone color when needed, so the page stays readable.
- Texts follow the language of the site until you customize them. French translation included.
- Live preview of each page, rendered by the drop-in itself.
- Status box telling you whether each page is in place and up to date. A file the plugin did not add is never replaced without your say.
- Site Health test, WP-CLI commands and filters for developers.
- Clean removal: drop-ins removed on deactivation, everything removed on uninstall.

## Installation

1. Install and activate the plugin.
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
```

## Hooks

| Filter | Purpose |
| --- | --- |
| `offair_settings` | Settings right before the page content is built |
| `offair_data` | Page content before it is saved for the drop-in |

## Limits worth knowing

- Pages served from a full page cache (LiteSpeed Cache, WP Rocket, Cloudflare) keep being served normally during an outage. Only requests that reach PHP see the outage page, which is what you want.
- A web server or PHP outage is not covered. Only the host can show a page in that case.
- WordPress can only show an error page for a fatal error that happens before the page started being sent. When PHP prints errors on screen and does not buffer its output, no error page can be shown at all. The settings page and Site Health detect that combination and explain the fix.

## Development

```
composer install
composer lint
```

The `readme.txt` file is the WordPress.org readme. Coding standards are checked with PHP_CodeSniffer and the WordPress ruleset, and a GitHub Actions workflow runs a syntax check on PHP 7.4 to 8.4.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
