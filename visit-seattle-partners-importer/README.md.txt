=== Visit Seattle Partners Importer ===
Contributors: jaybillings
Tags: partners, api, admin, importer
Requires at least: 4.7.0
Tested up to: 4.9.4
Stable tag: 1.1.0

Fetches partner data sourced from SimpleView.

== Description ==

Internal plug-in for Visit Seattle which fetches partner data, sourced from SimpleView, and creates the appropriate
posts and taxonomy.

== Installation ==

Move /visit-seattle-partners-importer into the /wp-content/plugins folder.

== Changelog ==

= 1.1.0 =
Adds `update_images` functionality.

= 1.0.8 =
Before requesting single listing data, importer tests whether listing has images by checking for PHOTOFILE presence.

= 1.0.7 =
Fixes issue where images weren't uploaded when running `import_all`.

= 1.0.6 =
Fixes bug where listings were not correctly associated with pre-existing subcategories.

= 1.0.5=
Adds code to determine whether a given image has changed before replacing it.

= 1.0.0 =

Establishes basic plugin functionality and user interface.
