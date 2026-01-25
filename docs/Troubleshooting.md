# Troubleshooting

Common issues and solutions for ZBooks for WooCommerce.

## Connection Issues

### "Invalid Grant Token"

**Cause**: Grant token has expired or already been used.

**Solution**:
1. Go to [Zoho API Console](https://api-console.zoho.com/)
2. Generate a new grant token
3. Use it within 10 minutes
4. Enter in plugin settings and reconnect

### "Invalid Client ID or Secret"

**Cause**: Credentials don't match or were copied incorrectly.

**Solution**:
1. Verify Client ID and Secret in Zoho API Console
2. Check for extra spaces when copying
3. Ensure you're using the correct datacenter

### "Organization Not Found"

**Cause**: Organization ID is incorrect or doesn't match datacenter.

**Solution**:
1. Log in to Zoho Books
2. Go to Settings > Organization Profile
3. Copy the exact Organization ID
4. Verify datacenter matches your Zoho account region

### "Rate Limit Exceeded"

**Cause**: Too many API requests (limit: 100/minute).

**Solution**:
- Wait a few minutes before retrying
- Failed syncs will auto-retry via cron
- Consider spacing out bulk operations

## Sync Issues

### Orders Not Syncing

**Possible causes**:

1. **Wrong trigger status**
   - Check ZBooks > Settings > Sync
   - Verify the order status is configured to trigger sync

2. **API disconnected**
   - Go to ZBooks > Settings
   - Click Test Connection
   - Reconnect if necessary

3. **Product not mapped**
   - Check ZBooks > Settings > Products
   - Map or create missing items

4. **Cron not running**
   - Verify WordPress cron is working
   - Check with WP Crontrol plugin
   - Consider setting up system cron

### "Item Not Found" Error

**Cause**: WooCommerce product not linked to Zoho item.

**Solution**:
1. Go to ZBooks > Settings > Products
2. Find the unmapped product
3. Either Link to existing item or Create new

### "Contact Not Found" Error

**Cause**: Customer matching failed and auto-create is disabled.

**Solution**:
1. Check ZBooks > Settings > Customers
2. Enable auto-create contacts
3. Or manually create contact in Zoho Books

### Duplicate Invoices

**Cause**: Order synced multiple times.

**Solution**:
1. Check order meta for existing Zoho invoice ID
2. Plugin prevents duplicates if meta exists
3. If duplicates occur, check for plugin conflicts

### Amount Mismatch

**Cause**: Rounding differences or tax calculation differences.

**Solution**:
1. Verify tax settings match between systems
2. Check currency and decimal settings
3. Review line item prices and quantities

## Payment Issues

### Payment Not Recorded

**Cause**: Payment method not mapped.

**Solution**:
1. Go to ZBooks > Settings > Payments
2. Map the payment gateway to Zoho payment mode
3. Select deposit account

### "Bank Account Not Found"

**Cause**: Selected bank account doesn't exist in Zoho.

**Solution**:
1. Verify account exists in Zoho Books
2. Re-select account in payment settings
3. Check account is active (not archived)

### Gateway Fees Not Tracking

**Cause**: Fee tracking not enabled or fee account not set.

**Solution**:
1. Go to ZBooks > Settings > Payments
2. Enable "Track Fees" for the gateway
3. Select expense account for fees

## Log Issues

### Logs Not Appearing

**Cause**: Logging disabled or database table missing.

**Solution**:
1. Deactivate and reactivate plugin
2. Check database for `zbooks_logs` table
3. Verify write permissions on database

### Too Many Logs

**Cause**: Debug logging enabled or frequent sync failures.

**Solution**:
1. Go to ZBooks > Logs
2. Click Clear Logs
3. Adjust log retention settings

## Performance Issues

### Slow Admin Pages

**Cause**: Large number of logs or pending syncs.

**Solution**:
1. Clear old logs
2. Process or cancel failed syncs
3. Check for plugin conflicts

### Bulk Sync Timing Out

**Cause**: Too many orders or server timeout limits.

**Solution**:
1. Sync smaller date ranges
2. Increase PHP max_execution_time
3. Use WP-CLI for large syncs

## Debug Mode

To enable detailed logging:

1. Add to `wp-config.php`:
   ```php
   define('ZBOOKS_DEBUG', true);
   ```
2. Check logs for detailed API responses
3. Remove when done troubleshooting

## Getting Help

If issues persist:

1. Check [GitHub Issues](https://github.com/talas9/zbooks-for-woocommerce/issues) for similar problems
2. Gather information:
   - Plugin version
   - WordPress/WooCommerce versions
   - PHP version
   - Error messages from logs
3. Open a new issue with details
