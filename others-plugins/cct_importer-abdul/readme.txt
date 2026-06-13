=== CSV to JetEngine CCT Importer ===
Contributors: abdulmuqsit
Donate link: https://www.paypal.com/donate/?hosted_button_id=STNMGQUAPV4B2
Tags: jetengine, csv, import, custom content types, wp-import, csv-to-db
Requires at least: 5.5
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A simple CSV importer for JetEngine Custom Content Types (CCT). Easily import structured data into CCT tables from the admin or via shortcode.

== Description ==

This plugin allows you to import CSV files into JetEngine Custom Content Type (CCT) database tables. Built for ease of use, it provides both backend and frontend import functionality.

**Key Features:**

- Upload CSV files to import data into JetEngine CCT tables
- Admin interface via WP Dashboard
- Frontend interface via shortcode `[import_cct_csv]`
- Visual mapping of CSV columns to CCT fields
- Option to skip first row if it contains headers
- Clean, safe integration using WordPress native APIs

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory or install via Plugins > Add New
2. Activate the plugin
3. Use the **CCT Importer** menu in the admin to begin importing
4. Optionally, use `[import_cct_csv]` shortcode on any page to allow frontend users to import CSVs

== Screenshots ==

1. Admin dashboard upload and table selection
2. Column mapping screen
3. Frontend interface via shortcode
4. Import success message
5. PayPal donation option

== Frequently Asked Questions ==

= Does this work with CPT or ACF? =  
No. This plugin is specifically for JetEngine's Custom Content Types (CCT), which are stored in separate DB tables.

= Can I use this on frontend pages? =  
Yes! Use `[import_cct_csv]` shortcode on any page/post.

= Is there undo functionality? =  
No rollback for now. Please back up your site/database before importing.

== Changelog ==

= 1.1 =
* Added shortcode usage instructions in the admin
* Added PayPal donate button
* Replaced move_uploaded_file() with wp_handle_upload()

= 1.0 =
* Initial release

== Upgrade Notice ==

= 1.1 =
Improved security and WP compatibility for plugin directory submission

