=== Be Right Back ===
Contributors: nolidev
Tags: maintenance mode, database error, fatal error, error page, 503
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Friendly branded pages when your site is down: database errors, fatal errors and maintenance mode. Works even when WordPress cannot load.

== Description ==

When a WordPress site breaks, visitors see a blank page or a bare English sentence. Be Right Back replaces the three screens WordPress shows during an outage with a calm page in your colors, with your logo and your words:

* **Database error**: shown when WordPress cannot reach the database.
* **Maintenance**: shown while WordPress updates itself, a theme or a plugin.
* **PHP error**: shown when a fatal PHP error stops a page.

Regular plugins cannot do this: when the database is down, no plugin is loaded. Be Right Back works differently. It writes three small standalone files (the WordPress *drop-ins* `db-error.php`, `maintenance.php` and `php-error.php`) into `wp-content`. WordPress loads these files itself, before any plugin, so your page shows even when nothing else can run.

= What you get =

* One design for the three screens: centered card, logo, site name, title, message, retry button and an incident line with the local time.
* Your logo and colors, detected from the theme, the site icon and Elementor global colors when available, editable at any time.
* Correct HTTP answers: 503 with a Retry-After header for the database and maintenance pages, 503 or 500 for PHP errors (your choice), plus no-cache headers so no cache ever keeps the error page.
* No external dependency: system fonts, logo embedded in the file, nothing loaded from the network.
* A live preview of each page, rendered exactly as visitors will see it.
* A status box telling you whether each file is present, up to date and written by the plugin.
* A Site Health test, WP-CLI commands and hooks for developers.
* Clean removal: the pages are removed when the plugin is deactivated and everything is removed when it is uninstalled.

Be Right Back never overwrites a `db-error.php`, `maintenance.php` or `php-error.php` it did not write itself. If one already exists, the plugin tells you and waits for your decision.

= Developers =

* `be_right_back_settings` filters the settings before a page is compiled.
* `be_right_back_template_vars` filters the variables handed to the templates.
* `be_right_back_dropin_html` filters the final HTML of each page.
* `be_right_back_dropins` filters the list of managed files.
* WP-CLI: `wp be-right-back status`, `generate [--force]`, `remove [--force]`, `preview <db|maintenance|php>`.

== Installation ==

1. Install and activate the plugin from Plugins, Add New.
2. Open Settings, Be Right Back. The logo and primary color of your site are pre-filled when they can be detected.
3. Adjust the texts and colors, save. The three files are written to `wp-content` and the preview shows the result.

On activation the plugin writes `db-error.php`, `maintenance.php` and `php-error.php` to `wp-content`. If the directory is not writable, download the three files from the settings page and upload them yourself.

== Frequently Asked Questions ==

= Why does the plugin write files to wp-content? =

Because it is the only way to show something when WordPress cannot start. WordPress looks for these three files itself, before loading any plugin. The files contain only what is needed to display the page: no WordPress function, no database query, no external request.

= Does it work with page caching (LiteSpeed Cache, WP Rocket, Cloudflare)? =

Yes. Pages already in the cache keep being served normally during an outage, which is what you want. Only requests that reach PHP see the error page, and that page is sent with headers that prevent it from being cached.

= Does it cover a web server or PHP outage? =

No. If the web server or PHP itself is down, nothing on the site can run. Only your host can show a page in that case.

= The site is fine but the status box says a file was not written by the plugin. =

Another plugin or a person placed a file with the same name in `wp-content`. Be Right Back leaves it untouched. Open the Advanced tab to replace it if you want to.

= I enabled WP_DEBUG_DISPLAY and the PHP error page does not show. =

When WP_DEBUG_DISPLAY is enabled and PHP runs without output buffering, PHP prints the raw error and sends the headers before WordPress can act, so WordPress never loads any error page, branded or not. This is how WordPress works. With output buffering enabled in PHP (the case on most hosts) the branded page shows, with the technical details added at the bottom.

= Does the plugin send emails or contact any service? =

No. Nothing leaves your server. There is no tracking, no update check and no external asset.

= Does it work on multisite? =

Yes. The three files are shared by every site of the network, so the settings live in the network admin under Settings, Be Right Back.

== Screenshots ==

1. The database error page as visitors see it.
2. The settings page with the status box and the live preview.

== Changelog ==

= 0.1.0 =
* Initial development version.

== Upgrade Notice ==

= 0.1.0 =
Initial development version.
