# Be Right Back

Friendly branded pages when your WordPress site is down: database errors, fatal errors and maintenance mode. Works even when WordPress cannot load.

When a WordPress site breaks, visitors see a blank page or a bare English sentence. Be Right Back replaces the three screens WordPress shows during an outage with a calm page in your colors, with your logo and your words.

| Screen | Shown when | HTTP answer |
| --- | --- | --- |
| Database error | WordPress cannot reach the database | 503 with Retry-After |
| Maintenance | WordPress updates itself, a theme or a plugin | 503 with Retry-After |
| PHP error | A fatal PHP error stops a page | 503 (default) or 500 |

## How it works

Regular plugins cannot do this: when the database is down, no plugin is loaded. Be Right Back is a generator. It writes three small standalone files, the WordPress drop-ins `db-error.php`, `maintenance.php` and `php-error.php`, into `wp-content`. WordPress loads these files itself, before any plugin, so the page shows even when nothing else can run.

The generated files need nothing from WordPress: no function, no database query, no external asset. The logo is embedded in the file, the fonts are system fonts, and the headers tell caches and search engines exactly what is going on (`503`, `Retry-After`, `Cache-Control: no-store`, `X-Robots-Tag: noindex`).

## Features

- One design for the three screens: centered card, logo, site name, title, message, retry button and an incident line with the local time.
- Logo and colors detected from the theme, the site icon and Elementor global colors, editable at any time. Pale brand colors are darkened for text so the page stays readable.
- Texts follow the language of the site until you customize them.
- Live preview of each page, rendered exactly as visitors will see it.
- Status box telling you whether each file is present, up to date and written by the plugin. A file the plugin did not write is never overwritten without your say.
- Site Health test, WP-CLI commands and filters for developers.
- Clean removal: the pages are removed on deactivation, everything is removed on uninstall.

## Installation

1. Install and activate the plugin.
2. Open Settings, Be Right Back. The logo and primary color of the site are pre-filled when they can be detected.
3. Adjust the texts and colors, save. The three files are written to `wp-content` and the preview shows the result.

If `wp-content` is not writable, download the three files from the settings page and upload them yourself.

Requires WordPress 6.0 and PHP 7.4 or later.

## WP-CLI

```
wp be-right-back status
wp be-right-back generate [--force]
wp be-right-back remove [--force]
wp be-right-back preview <db|maintenance|php>
```

## Hooks

| Filter | Purpose |
| --- | --- |
| `be_right_back_settings` | Settings right before a page is compiled |
| `be_right_back_template_vars` | Variables handed to the templates |
| `be_right_back_dropin_html` | Final HTML of each page |
| `be_right_back_dropins` | List of managed files |

## Limits worth knowing

- Pages served from a full page cache (LiteSpeed Cache, WP Rocket, Cloudflare) keep being served normally during an outage. Only requests that reach PHP see the error page, which is what you want.
- A web server or PHP outage is not covered. Only the host can show a page in that case.
- WordPress can only show an error page for a fatal error that happens before the page started being sent. When PHP prints errors on screen (`WP_DEBUG_DISPLAY`) and does not buffer its output, no error page can be shown at all. The settings page and Site Health detect that combination and explain the fix.

## Development

```
composer install
composer lint
```

The `readme.txt` file is the WordPress.org readme. Coding standards are checked with PHP_CodeSniffer and the WordPress ruleset, and a GitHub Actions workflow runs a syntax check on PHP 7.4 to 8.4.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
