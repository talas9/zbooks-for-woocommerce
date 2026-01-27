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

### What if I delete or edit an invoice in Zoho?

The plugin handles manual Zoho modifications gracefully:

- **Deleted invoices**: Automatically recreated on re-sync
- **Modified invoices**: Plugin detects discrepancies and warns, then updates to match WooCommerce
- **Locked invoices** (paid/void): Cannot be modified, plugin reports this clearly

### How do I prevent ZBooks from overwriting my Zoho edits?

If you intentionally edit an invoice in Zoho and don't want the plugin to revert your changes:

1. **Mark as Sent**: In Zoho Books, mark the invoice as "Sent" - this locks it from further edits
2. **Record a Payment**: Paid invoices cannot be modified
3. **Void the Invoice**: Voided invoices are locked permanently

Once an invoice is locked, the plugin will detect this and report that it cannot be modified, preserving your manual changes.

### What is the "Locked Invoice Handling" setting?

This setting (found in **ZBooks > Settings > Orders > Sync Behavior**) controls what happens when the plugin encounters a locked invoice (paid/void) that differs from the WooCommerce order:

**When enabled (default - recommended):**
- The entire sync stops when a locked invoice has discrepancies
- No payment is applied to the invoice
- A clear error is logged explaining the issue
- Use this if you want full control and visibility over mismatches

**When disabled:**
- The invoice update is skipped, but sync continues
- Payment is still applied to the existing invoice
- Useful if you intentionally edited the invoice in Zoho but still want payments recorded

### What if I delete a customer in Zoho?

The plugin detects the missing contact and creates a new one on the next order sync. The stale reference is automatically cleared.

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

- [Documentation](https://github.com/talas9/zbooks-for-woocommerce/tree/main/docs)
- [GitHub Issues](https://github.com/talas9/zbooks-for-woocommerce/issues)
- [Discussions](https://github.com/talas9/zbooks-for-woocommerce/discussions)

### How do I report a bug?

Use the [bug report template](https://github.com/talas9/zbooks-for-woocommerce/issues/new?template=bug_report.yml) on GitHub. Include version numbers, steps to reproduce, and any error messages.
