=== ZBooks for WooCommerce ===
Contributors: talas9
Tags: woocommerce, zoho, zoho-books, invoice, sync
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync WooCommerce orders to Zoho Books automatically or manually.

== Description ==

ZBooks for WooCommerce seamlessly integrates your WooCommerce store with Zoho Books, automatically creating invoices when orders are placed.

= Features =

* **Automatic Sync** - Sync orders to Zoho Books when order status changes
* **Manual Sync** - One-click sync button on order admin page
* **Configurable Triggers** - Choose which order statuses trigger sync
* **Draft/Submit Control** - Create invoices as draft or submit immediately
* **Bulk Sync** - Sync existing orders by date range or selection
* **Customer Sync** - Automatically create/match Zoho contacts
* **Product Linking** - Link WooCommerce products to Zoho Books items
* **Inventory Tracking** - Optional inventory sync (requires Zoho Inventory)
* **Retry Failed Syncs** - Configurable retry logic for failed syncs
* **Rate Limiting** - Respects Zoho's 100 requests/minute limit

= Requirements =

* WordPress 6.9 or higher
* WooCommerce 10.4 or higher
* PHP 8.2 or higher
* Zoho Books account with API access

== Installation ==

1. Upload the `zbooks-for-woocommerce` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **WooCommerce > Settings > ZBooks** to configure

= Zoho API Setup =

1. Go to [Zoho API Console](https://api-console.zoho.com/)
2. Create a new "Self Client" application
3. Generate a grant token with scope: `ZohoBooks.fullaccess.all`
4. Copy Client ID, Client Secret, and Grant Token to plugin settings

== Frequently Asked Questions ==

= What Zoho Books scopes are required? =

The plugin requires `ZohoBooks.fullaccess.all` scope for full functionality.

= Can I sync existing orders? =

Yes, use the bulk sync feature under WooCommerce > ZBooks > Bulk Sync.

= What happens if a sync fails? =

Failed syncs are automatically retried based on your retry settings. You can also manually retry from the order page.

= Does this work with WooCommerce HPOS? =

Yes, ZBooks for WooCommerce is fully compatible with WooCommerce High-Performance Order Storage (HPOS).

= Can I link existing Zoho items to WooCommerce products? =

Yes! On any product page, you can either create a new Zoho item or search and link an existing one.

= What about inventory tracking? =

Inventory tracking is optional and requires Zoho Inventory integration with your Zoho Books account. When creating a Zoho item, you can choose whether to enable inventory tracking.

== Screenshots ==

1. Plugin settings page
2. Order sync status metabox
3. Bulk sync interface
4. Sync log viewer

== Changelog ==

= 1.0.0 =
* Initial release
* Automatic order sync on status change
* Manual sync from order page
* Bulk sync by date range
* Customer contact sync
* Configurable retry logic
* Rate limiting support

== Upgrade Notice ==

= 1.0.0 =
Initial release of ZBooks for WooCommerce.

== Privacy Policy ==

This plugin sends order and customer data to Zoho Books via their API. This includes:

* Customer names and email addresses
* Billing and shipping addresses
* Order details and line items
* Product information

No data is sent to any third party other than Zoho Books. Please review [Zoho's Privacy Policy](https://www.zoho.com/privacy.html) for information on how they handle your data.
