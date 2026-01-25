# Frequently Asked Questions

## General

### What is ZBooks for WooCommerce?

ZBooks for WooCommerce is a plugin that automatically syncs your WooCommerce orders to Zoho Books, creating invoices, recording payments, and keeping your accounting up to date.

### Do I need a Zoho Books subscription?

Yes, you need an active Zoho Books account with API access. The plugin uses Zoho's REST API to create and manage invoices.

### Which Zoho datacenters are supported?

All Zoho datacenters: US, EU, India, Australia, Japan, and China.

### Is it compatible with HPOS?

Yes, the plugin fully supports WooCommerce High-Performance Order Storage (HPOS).

## Sync Behavior

### When do orders sync?

Orders sync when they reach a status you've configured as a trigger. Common setups:
- **Processing** → Create draft invoice
- **Completed** → Submit invoice and record payment

### Can I sync existing orders?

Yes. Go to **ZBooks > Bulk Sync** to sync orders by date range or select specific orders from the WooCommerce orders list.

### What happens if sync fails?

Failed syncs are automatically retried based on your retry settings. You can also manually retry from the order page or logs.

### Does it sync refunds?

Yes. WooCommerce refunds create credit notes in Zoho Books.

## Products & Inventory

### Do I need to map every product?

Products must be linked to Zoho items for sync to work. You can:
- Pre-map products in settings
- Enable auto-create to make new items during sync
- Use a generic "Miscellaneous" item for unmapped products

### Does it sync inventory?

Basic product linking is included. Full inventory sync requires Zoho Inventory integration (coming soon).

### What about variable products?

Each variation should be mapped to a separate Zoho item, or use the parent product mapping for all variations.

## Payments & Accounting

### How are payments recorded?

When an order reaches "Completed" status (or your configured trigger), the plugin records a payment against the invoice in Zoho Books.

### Can I track payment gateway fees?

Yes. Configure fee tracking in payment settings. Fees are recorded as expenses against your selected account.

### What about partial payments?

The plugin records the payment amount from WooCommerce. Partial payments are supported if your WooCommerce setup supports them.

## Customers

### How are customers matched?

By default, customers are matched by email address. You can also configure matching by name or always create new contacts.

### What if a customer has multiple emails?

The billing email from the order is used for matching. Consider standardizing customer emails in WooCommerce.

### Are guest checkouts supported?

Yes. Guest orders create or match contacts based on billing information.

## Troubleshooting

### Why isn't my order syncing?

Common reasons:
1. Order status isn't configured as a trigger
2. API connection expired (test connection in settings)
3. Products not mapped to Zoho items
4. Check logs for specific error messages

### How do I reconnect if the token expires?

Refresh tokens are used automatically. If connection fails:
1. Generate new grant token in Zoho API Console
2. Enter in plugin settings
3. Click Connect

### Where are the logs?

**ZBooks > Logs** shows all sync activity. Filter by date and log level to find specific events.

## Security

### Is my data secure?

- API credentials are stored encrypted in WordPress
- Data transfers use HTTPS
- No data is sent to third parties (only Zoho)

### What permissions does the plugin need?

The plugin requires `ZohoBooks.fullaccess.all` scope to create invoices, contacts, and payments.

### Can I restrict who can sync?

Sync operations require WooCommerce order management capabilities. Only admins can access plugin settings.

## Updates & Support

### How do I update the plugin?

Download the latest release from GitHub and upload via WordPress plugins page, or update via your deployment process.

### Where can I get help?

- [Documentation](https://talas9.github.io/zbooks-for-woocommerce/)
- [GitHub Issues](https://github.com/talas9/zbooks-for-woocommerce/issues)
- [Discussions](https://github.com/talas9/zbooks-for-woocommerce/discussions)

### How do I report a bug?

Use the [bug report template](https://github.com/talas9/zbooks-for-woocommerce/issues/new?template=bug_report.yml) on GitHub. Include version numbers, steps to reproduce, and any error messages.
