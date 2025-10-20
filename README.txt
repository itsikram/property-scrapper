=== Property Scrapper for WP Residence ===
Contributors: programmerikram
Tags: real estate, import, scraper, wp residence, properties, listings
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automate importing real‑estate listings into WP Residence. Scrapes sources, assigns location taxonomies, downloads media, and provides widgets/shortcode.

== Description ==

Property Scrapper automates importing real‑estate listings into the WP Residence theme.

Features:
- Scraping mode with configurable selectors (JSON) and rate limiting
- Assigns City/Area taxonomy terms based on address/coordinates (Prague defaults included)
- Downloads gallery images and sets featured image
- Frontend shortcode to list properties with pagination
- Widgets: Map (OpenStreetMap), Area list, Gallery
- Admin: preview scraping, run now, logs, CSV export

This plugin targets the `estate_property` post type from the WP Residence theme. It can be adapted for other locales.

== Installation ==
1. Upload the plugin folder to `/wp-content/plugins/property-scrapper` or install the ZIP
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to Admin → Property Scrapper to configure settings

== Frequently Asked Questions ==

= Does this include any third‑party libraries? =
No third‑party code is bundled. The Map widget uses OpenStreetMap tiles via Leaflet when available; alternatively it can render a simple map without remote libraries.

= Which theme is required? =
It is optimized for WP Residence (`estate_property` post type). Other themes may need adjustments.

= Can I change the scraping source? =
Yes. Update the selectors JSON and start URLs from the admin Scraping tab.

== Screenshots ==
1. Admin settings tabs
2. Properties shortcode grid
3. Area list widget
4. Map widget with markers

== Changelog ==
= 1.1.0 =
* Initial public release

== Upgrade Notice ==
= 1.1.0 =
First release.

== Privacy ==
This plugin stores logs and exported CSVs under the WordPress uploads directory. It does not collect personal data except content you import into your site.


