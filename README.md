# ZBooks for WooCommerce

[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://www.php.net/)
[![WordPress 6.9+](https://img.shields.io/badge/WordPress-6.9%2B-blue.svg)](https://wordpress.org/)
[![WooCommerce 10.4+](https://img.shields.io/badge/WooCommerce-10.4%2B-purple.svg)](https://woocommerce.com/)
[![License: GPL-2.0+](https://img.shields.io/badge/License-GPL--2.0%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![GitHub Actions](https://github.com/talas9/zbooks-for-woocommerce/workflows/CI/badge.svg)](https://github.com/talas9/zbooks-for-woocommerce/actions)

Sync WooCommerce orders to Zoho Books automatically or manually.

## Features

### Order Sync
- **Automatic Sync**: Sync orders to Zoho Books when order status changes
- **Manual Sync**: One-click sync button on order admin page
- **Bulk Sync**: Sync existing orders by date range or selection
- **Bulk Actions**: Sync multiple orders from WooCommerce orders list
- **Configurable Triggers**: Choose which order statuses trigger sync
- **Draft/Submit Control**: Create invoices as draft or submit immediately
- **HPOS Compatible**: Full support for WooCommerce High-Performance Order Storage

### Customer & Contact Management
- **Customer Sync**: Automatically create/match Zoho contacts
- **Custom Field Mapping**: Map WooCommerce customer fields to Zoho contact fields
- **Conflict Detection**: Detect and resolve duplicate contacts

### Product & Item Management
- **Product Sync**: Sync WooCommerce products to Zoho Books items
- **Product Mapping**: Link existing WooCommerce products to Zoho items
- **Product Creation**: Create new Zoho items directly from WooCommerce
- **Unmapped Product Detection**: Identify products not linked to Zoho items

### Invoice & Payment
- **Invoice Field Mapping**: Map WooCommerce order fields to Zoho invoice fields
- **Payment Mapping**: Map WooCommerce payment methods to Zoho payment modes and bank accounts
- **Payment Application**: Apply payments to invoices when orders are completed
- **Refund Sync**: Sync WooCommerce refunds to Zoho Books credit notes

### Reliability & Monitoring
- **Retry Failed Syncs**: Configurable retry logic with exponential backoff
- **Rate Limiting**: Respects Zoho's 100 requests/minute limit
- **Sync Logging**: Detailed logs with filtering by date and level
- **Sync Statistics**: Track success/failure rates and sync history
- **Background Processing**: Automatic retry via cron jobs (every 15 minutes)

### Administration
- **Setup Wizard**: Guided multi-step configuration (can be re-run from Settings page)
- **Connection Testing**: Test Zoho API connection from settings
- **Order Meta Box**: View sync status and Zoho invoice details on order page
- **Product Meta Box**: Manage Zoho item linking from product page
- **Log Viewer**: Browse and filter sync logs from admin panel

### Multi-Region & Localization
- **Zoho Datacenters**: Support for US, EU, IN, AU, JP, CN regions
- **RTL Support**: Right-to-left language interface support
- **Translations**: Available in 20 languages (see below)

## Requirements

- WordPress 6.9+
- WooCommerce 10.4+
- PHP 8.2+
- Zoho Books account with API access

## Supported Languages

| Language | Locale |
|----------|--------|
| English (US) | Default |
| Arabic | ar |
| Chinese (Simplified) | zh_CN |
| Danish | da_DK |
| Dutch | nl_NL |
| English (UK) | en_GB |
| French | fr_FR |
| German | de_DE |
| Hindi | hi_IN |
| Italian | it_IT |
| Japanese | ja |
| Korean | ko_KR |
| Polish | pl_PL |
| Portuguese (Brazil) | pt_BR |
| Russian | ru_RU |
| Spanish | es_ES |
| Swedish | sv_SE |
| Turkish | tr_TR |
| Ukrainian | uk |
| Urdu | ur |

## Installation

1. Download the latest release
2. Upload to `/wp-content/plugins/zbooks-for-woocommerce/`
3. Activate the plugin
4. Go to **WooCommerce > Settings > ZBooks** to configure

## Configuration

### Zoho API Setup

1. Go to [Zoho API Console](https://api-console.zoho.com/)
2. Create a new "Self Client" application
3. Generate a grant token with scope: `ZohoBooks.fullaccess.all`
4. Copy Client ID, Client Secret, and Grant Token to plugin settings

### Sync Triggers

Configure which order statuses trigger automatic sync:

| Status | Action |
|--------|--------|
| Processing | Create Draft Invoice |
| Completed | Submit Invoice |
| On-Hold | No Action |

### Field Mapping

Map WooCommerce data to Zoho Books fields:

- **Customer Fields**: Map billing/shipping fields to Zoho contact fields
- **Invoice Fields**: Map order data (notes, custom fields) to invoice fields

Access via **WooCommerce > ZBooks > Field Mapping**

### Product Mapping

Link WooCommerce products to Zoho Books items:

- **Auto-create**: Create new Zoho items from WooCommerce products
- **Manual Link**: Search and link existing Zoho items
- **Bulk Detection**: Find unmapped products that need linking

Access via **WooCommerce > ZBooks > Product Mapping**

### Payment Mapping

Map WooCommerce payment methods to Zoho payment modes:

- **Payment Mode**: Select Zoho payment mode per WooCommerce gateway
- **Bank Account**: Assign bank account for each payment method

Access via **WooCommerce > ZBooks > Payment Mapping**

### Retry Settings

- **Max Retries**: Stop after N failed attempts (default: 5)
- **Indefinite**: Keep retrying with exponential backoff
- **Manual Only**: Only retry via manual sync button

### Log Viewer

Monitor sync activity and troubleshoot issues:

- **Date Filtering**: Browse logs by date
- **Level Filtering**: Filter by log level (info, warning, error)
- **Statistics**: View success/failure counts
- **Clear Logs**: Remove old log entries

Access via **WooCommerce > ZBooks > Logs**

## Development

### Local Setup

```bash
# Install dependencies
composer install
npm install

# Start local WordPress environment
npm run env:start

# Access at http://localhost:8888
# Admin: admin / password
```

### Testing

```bash
# PHP coding standards
composer phpcs

# Unit tests
composer test

# E2E tests
npm run test:e2e
```

### Directory Structure

```
zbooks-for-woocommerce/
├── src/
│   ├── Plugin.php           # Main plugin class
│   ├── Admin/               # Admin pages and settings
│   ├── Api/                 # Zoho API client
│   ├── Service/             # Business logic
│   ├── Hooks/               # WooCommerce integration
│   ├── Model/               # Data models
│   ├── Repository/          # Data persistence
│   └── Cron/                # Scheduled tasks
├── assets/                  # CSS/JS
├── tests/                   # PHPUnit and E2E tests
└── languages/               # Translations
```

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Run tests: `composer test && npm run test:e2e`
5. Submit a pull request

## License

GPL-2.0+ - see [LICENSE](LICENSE)

## Author

Created by [talas9](https://github.com/talas9)

## Support

- [GitHub Issues](https://github.com/talas9/zbooks-for-woocommerce/issues)
- [Documentation](https://github.com/talas9/zbooks-for-woocommerce/wiki)
