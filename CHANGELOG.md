# Changelog

All notable changes to ZBooks for WooCommerce will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.15] - 2026-01-29

### Fixed
- **Bulk sync date filtering** - Fixed date range query syntax to correctly filter orders within specified date boundaries
- **Bulk sync payment application** - Bulk sync now applies payments for completed orders based on trigger settings (matching order meta box behavior)
- **Trigger settings defaults** - Fixed hardcoded defaults that prevented users from fully disabling auto-sync triggers; setting all triggers to "— None —" now truly disables auto-sync

### Improved
- **E2E test reliability** - Added retry with exponential backoff for Zoho API calls to prevent rate limit failures
- **E2E test execution** - Tests now run serially with delays between operations to avoid overwhelming Zoho API

## [1.0.14] - 2026-01-27

### Added
- **Notifications tab** - Dedicated settings tab for managing all email notifications
- **Professional email templates** - Beautiful HTML email templates for error, warning, and success notifications
- **Email preview** - Preview email templates directly in the admin settings
- **Test email** - Send test emails to verify notification settings work correctly
- **Notification types** - Choose which notifications to receive (sync errors, currency mismatches, warnings, reconciliation alerts, payment confirmations)
- **Invoice verification** - Validates invoice exists in Zoho and matches WooCommerce order before updates
- **Automatic invoice recreation** - Automatically creates new invoice if previous one was deleted in Zoho
- **Invoice-to-order comparison** - Detects discrepancies in total, line item count, and reference number
- **Locked invoice handling** - Prevents modification of paid/void invoices, logs clear error messages
- **Locked invoice behavior setting** - Configure whether to stop sync completely or continue with payment when encountering locked invoices with discrepancies (default: stop sync)

### Changed
- Email notifications now use wp_mail() for seamless integration with SMTP plugins (Brevo, SendGrid, Mailgun, etc.)
- Email notification settings moved from Advanced tab to dedicated Notifications tab
- Log settings in Advanced tab now only contains file retention and rotation settings
- Re-sync now verifies invoice integrity before attempting updates

### Improved
- **Invoice Settings UI** - Renamed "Invoice Numbering" section to "Invoice Settings" for better clarity
- **Invoice Options organization** - Added clear subheadings (Invoice Numbering, Invoice Status, Invoice Delivery) to improve settings readability
- **Currency handling documentation** - Clarified that contact currency cannot be changed once set, with practical multi-currency examples

## [1.0.13] - 2026-01-26

### Fixed
- **Contact email/phone sync** - Customer email and phone are now properly sent to Zoho using contact_persons array (Zoho API requirement)
- **Bank fee currency conversion** - Automatically converts bank fees to order currency when payment gateway processes in a different currency (e.g., Stripe processing in AED for USD orders)

### Developer
- Unit test improvements - Fixed mock configuration for invoice creation tests
- PHPCS configuration - Excluded temp and scripts directories from linting, auto-fixed 30 coding style issues

## [1.0.12] - 2026-01-25

### Added
- **Test runner script** - `run_all_tests.sh` for running all tests locally with progress UI

### Changed
- CI: Branch protection - PR to main now requires all tests to pass and admin approval
- Improved GitHub Actions workflow with unified test status check

## [1.0.11] - 2026-01-24

### Fixed
- **Bitcoin payment reference** - Uses order number for Bitcoin payments (transaction hashes exceed Zoho's 50 char limit). Transaction hash is added to order notes for reference. Other payment methods continue using transaction ID

## [1.0.10] - 2026-01-23

### Fixed
- **Improved upgrade error handling** - Better error messages when autoloader is missing
- Clearer instructions for resolving activation issues during upgrades
- Payment reference truncated to 50 chars (Zoho Books limit)

## [1.0.9] - 2026-01-22

### Changed
- Updated translation template with new strings
- Documentation improvements

## [1.0.8] - 2026-01-21

### Fixed
- **Plugin zip structure** - Zip now has correct folder structure for WordPress upgrades
- Uploading new version properly replaces old version instead of creating duplicates

## [1.0.7] - 2026-01-20

### Fixed
- **Seamless upgrades** - Plugin now properly detects version changes and runs upgrade routines
- No need to deactivate/reactivate after uploading new versions
- All settings and Zoho connection preserved during upgrades

## [1.0.6] - 2026-01-19

### Added
- **Complete translations** for 19 languages:
  - Arabic, Chinese, Danish, Dutch, English UK, French, German, Hindi, Italian, Japanese, Korean, Polish, Portuguese, Russian, Spanish, Swedish, Turkish, Ukrainian, Urdu
- Project documentation (Getting Started, Configuration, Troubleshooting, FAQ)
- GitHub issue templates for bug reports and feature requests

### Changed
- Applied WordPress Coding Standards formatting across all PHP files
- Added .editorconfig for consistent coding style
- Improved CI compatibility

## [1.0.5] - 2026-01-18

### Added
- **Reconciliation Tab** - Compare WooCommerce orders with Zoho Books invoices
- Discrepancy detection for missing, unsynced, or mismatched records
- Amount verification between WooCommerce and Zoho
- One-click sync for unsynced orders from reconciliation view
- E2E tests for discount sync and reconciliation features

### Changed
- Enhanced order notes with detailed sync status information
- Improved order meta box with reconciliation status display

## [1.0.4] - 2026-01-17

### Changed
- Enhanced bulk sync with better progress tracking
- Improved error handling for API requests

### Fixed
- Edge cases in payment reconciliation

## [1.0.3] - 2026-01-16

### Added
- Payment method mapping to Zoho deposit accounts
- Bank fee tracking from payment gateways (Stripe, PayPal, etc.)
- Fee account mapping for expense categorization
- Shipping account configuration

## [1.0.2] - 2026-01-15

### Added
- Payment reconciliation - automatically apply payments to invoices
- Refund sync as credit notes in Zoho Books
- Custom field mapping support
- Invoice numbering options (auto or order number)
- Mark as sent toggle

## [1.0.1] - 2026-01-14

### Added
- Setup wizard for guided configuration
- Log viewer with filtering and search
- Improved sync status display

### Fixed
- Bug fixes and performance improvements

## [1.0.0] - 2026-01-13

### Added
- Initial release
- Automatic order sync on status change
- Manual sync from order page
- Bulk sync by date range
- Customer contact sync
- Product linking to Zoho items
- Configurable retry logic
- Rate limiting support
