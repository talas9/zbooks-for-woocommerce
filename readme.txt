=== ZBooks for WooCommerce ===
Contributors: talas9
Tags: woocommerce, zoho, zoho-books, invoice, sync
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 1.0.17
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync WooCommerce orders to Zoho Books automatically. Create invoices, apply payments, handle refunds, and reconcile records seamlessly.

== Description ==

ZBooks for WooCommerce seamlessly integrates your WooCommerce store with Zoho Books, automatically creating invoices when orders are placed.

= Features =

**Order & Invoice Sync**
* **Automatic Sync** - Sync orders to Zoho Books when order status changes
* **Manual Sync** - One-click sync button on order admin page
* **Bulk Sync** - Sync existing orders by date range or selection
* **Configurable Triggers** - Choose which order statuses trigger sync
* **Draft/Submit Control** - Create invoices as draft or submit immediately
* **Invoice Numbering** - Use Zoho auto-numbering or WooCommerce order numbers

**Payment Reconciliation**
* **Automatic Payments** - Apply payments to invoices when orders are paid
* **Payment Method Mapping** - Map WooCommerce payment methods to Zoho payment modes
* **Deposit Accounts** - Route payments to specific bank/cash accounts in Zoho
* **Bank Fees Tracking** - Capture gateway fees (Stripe, PayPal, etc.) and record in Zoho
* **Fee Account Mapping** - Assign payment fees to expense accounts

**Refunds & Credit Notes**
* **Refund Sync** - Automatically create credit notes for WooCommerce refunds
* **Partial Refunds** - Support for partial and full refund amounts

**Reconciliation**
* **Order Comparison** - Compare WooCommerce orders with Zoho Books invoices
* **Discrepancy Detection** - Identify missing, unsynced, or mismatched records
* **Amount Verification** - Detect total/subtotal differences between systems
* **Bulk Reconciliation** - Run reconciliation reports for any date range
* **Quick Fixes** - One-click sync for unsynced orders from reconciliation view

**Customer & Product Management**
* **Customer Sync** - Automatically create/match Zoho contacts
* **Product Linking** - Link WooCommerce products to Zoho Books items
* **Inventory Tracking** - Optional inventory sync (requires Zoho Inventory)

**Shipping & Fees**
* **Shipping Charges** - Sync shipping costs with invoices
* **Shipping Account Mapping** - Assign shipping to specific revenue accounts
* **Fee Items** - Include WooCommerce fee line items

**Customization**
* **Custom Field Mapping** - Map WooCommerce order data to Zoho custom fields
* **Mark as Sent** - Control automatic "sent" status on invoices

**Administration**
* **Setup Wizard** - Guided configuration for new installations
* **Log Viewer** - Detailed sync logs with filtering and search
* **Retry Failed Syncs** - Configurable retry logic for failed syncs
* **Rate Limiting** - Respects Zoho's API rate limits

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

= Does the plugin track payment gateway fees? =

Yes! The plugin automatically captures transaction fees from popular gateways like Stripe, PayPal, Square, and WooCommerce Payments. These are recorded as bank charges in Zoho Books when you configure a fee expense account in the payment mapping settings.

= Can I map payments to specific bank accounts? =

Yes, you can configure each WooCommerce payment method to deposit into a specific Zoho Books bank or cash account. Go to WooCommerce > Settings > ZBooks > Payments to set up the mapping.

= Are refunds synced to Zoho? =

Yes, when you process a refund in WooCommerce, the plugin automatically creates a credit note in Zoho Books for the refund amount.

= How do I run a reconciliation report? =

Go to WooCommerce > ZBooks > Reconciliation, select your date range, and click "Run Reconciliation". The report compares all orders and invoices in that period and identifies any discrepancies.

= What Zoho regions are supported? =

The plugin supports all Zoho data centers: US (.com), EU (.eu), IN (.in), AU (.com.au), and JP (.jp). Select your region during the setup wizard.

= Can I export reconciliation reports? =

Yes, each reconciliation report can be exported to CSV format for further analysis or record-keeping.

== Screenshots ==

1. Plugin settings page with connection status
2. Order sync status metabox on order edit page
3. Bulk sync interface with date range selection
4. Sync log viewer with filtering options
5. Reconciliation report with discrepancy detection

== Changelog ==

= 1.0.16 =
* **NEW: Payment Status Detection** - Reconciliation reports now show draft payment status from Zoho Books
* **NEW: Payment Status Column** - Added "Payment Status" column showing paid, unpaid, draft, credit note applied, or credit note pending status
* **NEW: Batch Payment Fetching** - Reconciliation efficiently fetches all payments in 2 API calls instead of N+1 per invoice
* **Improvement: Reconciliation Performance** - Optimized payment fetching with intelligent payment lookup and pagination limits
* **Improvement: Error Handling** - Payment fetch failures no longer break reconciliation; continues gracefully with detailed logging
* **Improvement: Test Reliability** - Fixed E2E test credential setup and enhanced error reporting with detailed diagnostics
* **Fix: ReconciliationService** - Changed error catching from Exception to Throwable to properly catch PHP type errors
* **Fix: CSS Tests** - Updated E2E tests to check for properly enqueued CSS files (WordPress.org compliance)
* **Dev: Test Infrastructure Overhaul** - Smart testing strategy with test pyramid (PHP Unit > API Tests > Browser E2E)
* **Dev: Zoho API Mocking** - Added mock system for fast, reliable tests without credentials
* **Dev: Enhanced Test Reporter** - Custom reporter with category breakdown, performance insights, and failure summaries
* **Dev: Parallel Execution** - Increased test workers (8 for API, 4 for browser) for 2-3x faster test runs
* **Dev: Test Strategy Guide** - Comprehensive documentation for unit/API/browser testing best practices
* **Dev: WordPress.org Compliance** - Updated .distignore to exclude 100+ forbidden files

= 1.0.15 =
* **Fix: Bulk sync date filtering** - Corrected date range query to properly filter orders within specified date boundaries
* **Fix: Bulk sync payment application** - Bulk sync now applies payments for completed orders based on trigger settings, matching order meta box behavior
* **Fix: Trigger settings defaults** - Fixed hardcoded defaults that prevented full disabling of auto-sync; setting triggers to "— None —" now truly disables auto-sync
* **Improvement: E2E test reliability** - Added retry with exponential backoff for Zoho API calls to prevent rate limiting failures

= 1.0.14 =
* **NEW: Notifications tab** - Dedicated settings tab for managing email notifications with granular control over notification types
* **NEW: Professional email templates** - Beautiful HTML email templates for sync errors, warnings, and success notifications
* **NEW: Email preview** - Preview email templates directly in the admin before sending
* **NEW: Test email** - Send test emails to verify your notification settings
* **NEW: Locked invoice behavior setting** - Configure whether to stop sync or continue with payment when encountering locked invoices with discrepancies
* **Improvement: Invoice Settings UI** - Reorganized with clear subheadings (Invoice Numbering, Invoice Status, Invoice Delivery) for better clarity
* **Improvement: Currency handling docs** - Clarified that contact currency cannot be changed once set, with multi-currency examples
* **Improvement** - Email notifications now use wp_mail() which integrates with SMTP plugins (Brevo, SendGrid, etc.)
* **Moved** - Email notification settings relocated from Advanced tab to new Notifications tab

= 1.0.13 =
* **Fix: Contact email/phone sync** - Customer email and phone are now properly sent to Zoho using contact_persons array (Zoho API requirement)
* **Fix: Bank fee currency conversion** - Automatically converts bank fees to order currency when payment gateway processes in a different currency (e.g., Stripe processing in AED for USD orders)
* **Dev: Unit test improvements** - Fixed mock configuration for invoice creation tests
* **Dev: PHPCS configuration** - Excluded temp and scripts directories from linting, auto-fixed 30 coding style issues

= 1.0.12 =
* **NEW: Test runner script** - `run_all_tests.sh` for running all tests locally
* **CI: Branch protection** - PR to main now requires all tests to pass and admin approval
* Improved GitHub Actions workflow with unified test status check

= 1.0.11 =
* **Fix: Bitcoin payment reference** - Uses order number for Bitcoin payments (transaction hashes exceed Zoho's 50 char limit). Transaction hash is added to order notes for reference. Other payment methods continue using transaction ID

= 1.0.10 =
* **Fix: Improved upgrade error handling** - Better error messages when autoloader is missing
* Clearer instructions for resolving activation issues during upgrades
* Fix: Payment reference truncated to 50 chars (Zoho Books limit)

= 1.0.9 =
* Updated translation template with new strings
* Documentation improvements

= 1.0.8 =
* **Fix: Plugin zip structure** - Zip now has correct folder structure for WordPress upgrades
* Uploading new version properly replaces old version instead of creating duplicates

= 1.0.7 =
* **Fix: Seamless upgrades** - Plugin now properly detects version changes and runs upgrade routines
* No need to deactivate/reactivate after uploading new versions
* All settings and Zoho connection preserved during upgrades

= 1.0.6 =
* **NEW: Complete translations** for 19 languages (Arabic, Chinese, Danish, Dutch, English UK, French, German, Hindi, Italian, Japanese, Korean, Polish, Portuguese, Russian, Spanish, Swedish, Turkish, Ukrainian, Urdu)
* Added project documentation (Getting Started, Configuration, Troubleshooting, FAQ)
* Added GitHub issue templates for bug reports and feature requests
* Applied WordPress Coding Standards formatting across all PHP files
* Added .editorconfig for consistent coding style
* Improved CI compatibility

= 1.0.5 =
* **NEW: Reconciliation Tab** - Compare WooCommerce orders with Zoho Books invoices
* Discrepancy detection for missing, unsynced, or mismatched records
* Amount verification between WooCommerce and Zoho
* One-click sync for unsynced orders from reconciliation view
* Enhanced order notes with detailed sync status information
* Improved order meta box with reconciliation status display
* E2E tests for discount sync and reconciliation features

= 1.0.4 =
* Enhanced bulk sync with better progress tracking
* Improved error handling for API requests
* Fixed edge cases in payment reconciliation

= 1.0.3 =
* Added payment method mapping to Zoho deposit accounts
* Bank fee tracking from payment gateways (Stripe, PayPal, etc.)
* Fee account mapping for expense categorization
* Shipping account configuration

= 1.0.2 =
* Payment reconciliation - automatically apply payments to invoices
* Refund sync as credit notes in Zoho Books
* Custom field mapping support
* Invoice numbering options (auto or order number)
* Mark as sent toggle

= 1.0.1 =
* Setup wizard for guided configuration
* Log viewer with filtering and search
* Improved sync status display
* Bug fixes and performance improvements

= 1.0.0 =
* Initial release
* Automatic order sync on status change
* Manual sync from order page
* Bulk sync by date range
* Customer contact sync
* Product linking to Zoho items
* Configurable retry logic
* Rate limiting support

== Upgrade Notice ==

= 1.0.6 =
Added complete translations for 19 languages and project documentation.

= 1.0.5 =
New reconciliation feature to compare and verify sync between WooCommerce and Zoho Books.

= 1.0.4 =
Enhanced bulk sync and improved error handling.

= 1.0.0 =
Initial release of ZBooks for WooCommerce.

== Privacy Policy ==

This plugin sends order and customer data to Zoho Books via their API. This includes:

* Customer names and email addresses
* Billing and shipping addresses
* Order details and line items
* Product information

No data is sent to any third party other than Zoho Books. Please review [Zoho's Privacy Policy](https://www.zoho.com/privacy.html) for information on how they handle your data.
