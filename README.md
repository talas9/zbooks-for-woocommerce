# ZBooks for WooCommerce

[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://www.php.net/)
[![WordPress 6.9+](https://img.shields.io/badge/WordPress-6.9%2B-blue.svg)](https://wordpress.org/)
[![WooCommerce 10.4+](https://img.shields.io/badge/WooCommerce-10.4%2B-purple.svg)](https://woocommerce.com/)
[![License: GPL-2.0+](https://img.shields.io/badge/License-GPL--2.0%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![GitHub Actions](https://github.com/talas9/zbooks-for-woocommerce/workflows/CI/badge.svg)](https://github.com/talas9/zbooks-for-woocommerce/actions)

Sync WooCommerce orders to Zoho Books automatically or manually.

## Features

- **Automatic Sync**: Sync orders to Zoho Books when order status changes
- **Manual Sync**: One-click sync button on order admin page
- **Configurable Triggers**: Choose which order statuses trigger sync
- **Draft/Submit Control**: Create invoices as draft or submit immediately
- **Bulk Sync**: Sync existing orders by date range or selection
- **Customer Sync**: Automatically create/match Zoho contacts
- **Retry Failed Syncs**: Configurable retry logic for failed syncs
- **Rate Limiting**: Respects Zoho's 100 requests/minute limit

## Requirements

- WordPress 6.9+
- WooCommerce 10.4+
- PHP 8.2+
- Zoho Books account with API access

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

### Retry Settings

- **Max Retries**: Stop after N failed attempts (default: 5)
- **Indefinite**: Keep retrying with exponential backoff
- **Manual Only**: Only retry via manual sync button

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
