=== SeekFix – Broken Link Checker & Fixer ===
Contributors: marcmenez
Tags: broken links, link checker, redirects, 404, seo
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Finds and fixes broken links without leaving WordPress. Every fix can be undone.

== Description ==

SeekFix finds the broken links in your posts and pages and lets you fix them from one screen, without opening each post. Every fix changes only the link, saves a revision first and can be undone.

= How a scan works =

1. SeekFix reads your content and collects every link, button and image.
2. It checks each different URL once, from your server.
3. A link that fails is checked again later before it is marked as broken, so a site that is down for a moment is not reported.

Results are sorted into **Broken**, **Cannot verify** (the other site blocked the check or did not answer) and **Redirects**. The status of each link shows the path it took, such as `301 → 200`.

= Fix links from one screen =

* **Remove link**: the link goes and its text stays in place.
* **Remove button**: removes a button block, and its group if it was the only button.
* **Replace URL**: changes only the address.
* **Update to final URL**: points a redirect straight to where it ends.
* **Group by URL**: fix the same URL in every post at once.
* **Ignore** a link, or **Check again now**.

Filter by status, content type, domain, or internal and external links, search by URL or link text, and export the list as CSV.

= Every fix can be undone =

* Before each fix the post gets a revision.
* The History tab lists every fix: when, by whom, in which post and what changed.
* Undo puts the post back as it was, as long as nobody edited it afterwards. If someone did, Compare revisions opens the WordPress revisions screen so you can restore it by hand without losing their changes.

= Automatic scans and e-mail =

* Scan every day or every week in the background with WP-Cron.
* Get one e-mail per scan when new broken links are found.
* Choose how long the fix history is kept.

= Works with your content =

* Links, buttons and images in the block editor and the classic editor.
* Links inside WPBakery and Divi shortcodes are found and listed. They are fixed in the page builder's editor.
* Domain rules: mark sites that block automated checks as "cannot verify", or never check some domains.

= WP-CLI =

`wp seekfix index`, `wp seekfix check`, `wp seekfix status`, `wp seekfix fix`, `wp seekfix undo` and more. Run `wp help seekfix` for the full list.

== Privacy ==

SeekFix does not collect data and does not use an external service.

To check a link, your server sends a request to the URL of that link, the same as a visitor opening it. The request identifies itself as SeekFix in its User-Agent and does not include information about your site or its users. Addresses on private or local networks are not checked, except your own site.

Scan results and the history of fixes are stored in your database. Deleting the plugin removes them; your posts and their revisions are not changed.

== Installation ==

1. In your admin, go to Plugins > Add New, search for "SeekFix" and click Install Now. Or upload the `seekfix` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to Tools > Broken Links and click Start scan.

== Frequently Asked Questions ==

= Who can use SeekFix? =

Users who can manage options, usually administrators.

= What does "Cannot verify" mean? =

The other site blocked the check or did not answer. Many large sites do this with automated requests. These links may work in a browser: open them to check by hand. You can add those domains to the "Always cannot verify" rule in Settings.

= Does SeekFix slow down my site? =

Checks run in small batches, at most four requests at a time and one per site per second. While the Broken Links screen is open, the scan runs from there; otherwise it continues in the background with WP-Cron.

= My site has WP-Cron turned off. =

Keep the Summary tab open while a scan runs, or set up a server cron job that runs WP-Cron.

= What is scanned? =

The content of the post types you choose in Settings: every public post type except media by default. Drafts are optional. Menus, widgets, comments and custom fields are not scanned.

= Can a fix break my post? =

A fix changes only the bytes of the link. If saving the post would change anything else, for example because a plugin filters post content on save, SeekFix does not save it and asks you to fix the link in the editor.

== Development ==

The source code, including the unminified JavaScript of the admin screen, is at [github.com/MarcoJmz/seekfix](https://github.com/MarcoJmz/seekfix). Run `npm install` and `npm run build` to build `assets/build/` from `assets/src/`.

== Changelog ==

= 0.1.0 =
* First release.
