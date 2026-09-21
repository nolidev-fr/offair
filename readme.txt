=== Offair - Branded Error Pages ===
Contributors: nolidev
Tags: error page, database, fatal error, downtime, 503
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Branded pages for the screens WordPress shows when it breaks: database connection errors, fatal PHP errors and the update notice.

== Description ==

When WordPress breaks, it shows screens of its own that no theme and no plugin can style, because they appear before any of them is loaded:

* **Error establishing a database connection**, when the database is unreachable.
* **Briefly unavailable for scheduled maintenance**, while WordPress installs updates.
* **There has been a critical error on this website**, after a fatal PHP error.

Offair replaces these three screens with a calm page in your colors, with your logo and your words, and makes them answer with the right HTTP status so that search engines and caches treat the outage as temporary.

This is not a maintenance mode or coming soon plugin. It does not take your site offline and it does not add a page you switch on. It only covers the moments when WordPress itself cannot serve your site.

= How it works =

WordPress looks for three special files in `wp-content`, called drop-ins: `db-error.php`, `maintenance.php` and `php-error.php`. It loads them itself, before any plugin, which is the only way to show something when the database is down.

The plugin copies one static file shipped in its own folder (`dropins/drop-in.php`) under these three names. No code is generated. The content of the pages (texts, colors, logo) is saved as a JSON file in `wp-content/uploads/offair/`, and the drop-in reads it when a page has to be shown.

= What you get =

* One design for the three screens: centered card, logo, site name, title, message, retry button and an incident line with the local time.
* Your logo and colors, detected from the theme, the site icon and Elementor global colors when available, editable at any time.
* Texts in the language of the site until you customize them. French translation included.
* Correct HTTP answers: 503 with a Retry-After header for the database and update pages, 503 or 500 for PHP errors (your choice), plus no-cache headers so no cache ever keeps an error page.
* No external dependency: system fonts, logo embedded in the content file, nothing loaded from the network.
* A live preview of each page, rendered by the drop-in itself, exactly as visitors will see it.
* A status box, a Site Health test and WP-CLI commands.
* Clean removal: the drop-ins are removed when the plugin is deactivated, and everything is removed when it is uninstalled.

The plugin never replaces a `db-error.php`, `maintenance.php` or `php-error.php` it did not add itself. If one already exists, it tells you and waits for your decision.

= Developers =

* `offair_settings` filters the settings before the page content is built.
* `offair_data` filters the page content before it is saved for the drop-in.
* WP-CLI: `wp offair status`, `generate [--force]`, `remove [--force]`, `preview <db|maintenance|php>`.

== Installation ==

1. Install and activate the plugin from Plugins, Add New.
2. Open Settings, Offair. The logo and primary color of your site are pre-filled when they can be detected.
3. Adjust the texts and colors, save. The preview shows the result.

On activation the plugin copies its drop-in to `wp-content` under the three names WordPress expects, and saves the page content in `wp-content/uploads/offair/`. If `wp-content` is not writable, download the drop-ins from the settings page and upload them yourself, once.

== Frequently Asked Questions ==

= Is this a maintenance mode plugin? =

No. It does not let you put your site offline. It styles the screens WordPress shows on its own when the database is down, while updates are installed and after a fatal error. It works alongside any maintenance mode or coming soon plugin.

= Why does the plugin add files to wp-content? =

Because WordPress only looks for these drop-ins there, and loads them before any plugin. That is the only way to show a page when the database is down. The three files are identical copies of `dropins/drop-in.php` from the plugin folder. They are removed when the plugin is deactivated or uninstalled.

= Why is the content saved as a file and not in the database? =

The database is precisely what may be unreachable when the page is shown. The content is saved in the uploads folder, in `offair/pages.json`, and contains only what the visitors see on the page.

= Does it work with page caching (LiteSpeed Cache, WP Rocket, Cloudflare)? =

Yes. Pages already in the cache keep being served normally during an outage, which is what you want. Only requests that reach PHP see the outage page, and that page is sent with headers that prevent it from being cached.

= Does it cover a web server or PHP outage? =

No. If the web server or PHP itself is down, nothing on the site can run. Only your host can show a page in that case.

= The status box says a file was not added by the plugin. =

Another plugin or a person placed a file with the same name in `wp-content`. The plugin leaves it untouched. Open the Advanced tab to replace it if you want to.

= Does the plugin need output buffering in PHP? =

No. The database and update pages never depend on it, and the PHP error page works without it as long as PHP does not print errors on screen, which is the normal configuration of a live site.

There is one combination WordPress cannot handle: errors printed on screen (usually because WP_DEBUG and WP_DEBUG_DISPLAY are enabled) together with output buffering off. PHP then sends the raw error and the headers before WordPress runs its handler, so no error page, branded or not, can be shown. The settings page and Site Health detect this combination and explain the two fixes: disable WP_DEBUG_DISPLAY on a live site, or set output_buffering to 4096 in the PHP configuration.

= Does the plugin send emails or contact any service? =

No. Nothing leaves your server. There is no tracking, no update check and no external asset.

= Does it work on multisite? =

Yes. The drop-ins are shared by every site of the network, so the settings live in the network admin under Settings, Offair.

== Screenshots ==

1. The database error page as visitors see it: logo, message, retry button and incident line.
2. The settings page: state of the three pages, logo, colors and fonts.
3. One tab per page, with the live preview rendered exactly as visitors will see it.

== Changelog ==

= 1.0.3 =
* Fix: a logo in a format the pages cannot show, such as AVIF, was left out without any warning. It is now embedded as PNG.

= 1.0.2 =
* After an update, the pages are brought up to date on the first request, without waiting for someone to open the admin.

= 1.0.1 =
* Fix: an SVG logo over 150 KB that only wraps one image, as design tools export them, was left out of the pages. The image is now taken out and resized like any other logo.
* New: optional button text color. Left empty, the choice stays automatic.
* The automatic button text is now white on mid-tone colors, with the button slightly darkened when needed, instead of dark text that was hard to read. The label always reaches a 4.5:1 contrast.
* The settings page warns when the chosen logo cannot be embedded.
* The plugin name uses a plain hyphen.

= 1.0.0 =
* Initial release: branded pages for database connection errors, the update notice and fatal PHP errors.
* Settings page with status box, live preview, manual download and per-page options.
* Logo and color detection, texts in the language of the site, French translation.
* Site Health test and WP-CLI commands.

== Upgrade Notice ==

= 1.0.3 =
Fixes AVIF logos missing from the pages.

= 1.0.2 =
The pages are brought up to date right after an update, even when nobody opens the admin.

= 1.0.1 =
Fixes heavy SVG logos missing from the pages, makes the button easier to read on mid-tone colors and adds a button text color setting.

= 1.0.0 =
Initial release.
