=== Sortable Tag Count ===
Contributors: thomaskujawa,threadi
Tags: tags, sortable column, backend
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
License: GPL-3.0
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Stable tag: @@VersionNumber@@

This plugin adds a sortable column is added to the admin overview of posts and pages. This contains the number of tags assigned in each case.

== Installation ==

1. Upload "sortable-tag-count" to the "/wp-content/plugins/" directory.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Go to Posts to show the list of posts where the tag counter is visible.

== Frequently Asked Questions ==

= Why is the tag count not shown for all entries directly after activation? =

To prevent timeouts on large sites, the tag counts are calculated in the background via WP-Cron after activation. A notice in the backend shows the progress. Until the calculation is finished, sorting by tag count may be incomplete.

= Is there a WP-CLI command? =

Yes. Run `wp sortable-tag-count recalculate` to calculate all tag counts directly. Use `--network` for all sites of a multisite network and `--batch-size=<number>` to change the number of entries per batch. `wp sortable-tag-count status` shows the state of the background calculation.

= Can I change the batch size of the background calculation? =

Yes, with the filter `fks_stc_batch_size` (default: 100). The filter `fks_stc_cron_time_limit` sets the maximum duration of one WP-Cron run in seconds (default: 20).

== Changelog ==

= 1.2.0 =
* Fixed tag count not being updated when saving in the block editor (REST API)
* Fixed sorting to work across all pages of the list
* Fixed PHP deprecation notice in sort callback
* Added support for custom post types registered on init
* Added multisite support for activation and uninstallation
* Optimized uninstallation and update on tag deletion
* Load styles only on list views
* Compatibility with WordPress 7.1
* Calculate tag counts in the background after activation to prevent timeouts on large sites
* Added admin notice with progress of the background calculation
* Added WP-CLI commands "wp sortable-tag-count recalculate" and "wp sortable-tag-count status"

= 1.1.1 =
* Optimized database statements
* Optimized column width
* Fixed tested up to value

= 1.1.0 =
* Optimized handling of saved counting value
* Added uninstaller to clean-up db on uninstallation
* Fixed sort of tag counter

= 1.0.1 =
* License Update

= 1.0.0 =
* Initial release
