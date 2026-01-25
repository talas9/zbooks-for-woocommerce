# Configuration Reference

Complete reference for all ZBooks for WooCommerce settings.

## API Settings

Located at **ZBooks > Settings > API**

| Setting | Description |
|---------|-------------|
| Client ID | OAuth client ID from Zoho API Console |
| Client Secret | OAuth client secret from Zoho API Console |
| Grant Token | One-time authorization code |
| Organization ID | Your Zoho Books organization ID |
| Datacenter | Zoho region: US, EU, IN, AU, JP, CN |

### Datacenter URLs

| Region | API URL |
|--------|---------|
| US | `https://www.zohoapis.com` |
| EU | `https://www.zohoapis.eu` |
| IN | `https://www.zohoapis.in` |
| AU | `https://www.zohoapis.com.au` |
| JP | `https://www.zohoapis.jp` |
| CN | `https://www.zohoapis.com.cn` |

## Sync Settings

Located at **ZBooks > Settings > Sync**

### Order Status Triggers

Configure which WooCommerce order statuses trigger sync:

| Status | Recommended Action |
|--------|-------------------|
| Pending | No sync |
| Processing | Create draft invoice |
| On Hold | No sync |
| Completed | Submit invoice + record payment |
| Cancelled | No sync |
| Refunded | Create credit note |
| Failed | No sync |

### Invoice Options

| Setting | Options | Description |
|---------|---------|-------------|
| Default Status | Draft, Submitted | Initial invoice status in Zoho |
| Auto-submit | Yes, No | Automatically submit when order completes |
| Include Notes | Yes, No | Sync order notes to invoice |

## Payment Settings

Located at **ZBooks > Settings > Payments**

### Payment Method Mapping

For each WooCommerce payment gateway:

| Setting | Description |
|---------|-------------|
| Payment Mode | Zoho payment mode (Cash, Bank Transfer, Credit Card, etc.) |
| Deposit Account | Zoho bank/cash account to deposit to |
| Track Fees | Enable gateway fee tracking |
| Fee Account | Expense account for payment fees |

### Common Payment Modes

- Cash
- Bank Transfer
- Credit Card
- PayPal
- Stripe
- Check
- Bank Remittance

## Product Settings

Located at **ZBooks > Settings > Products**

### Product Mapping

| Action | Description |
|--------|-------------|
| Link | Connect WooCommerce product to existing Zoho item |
| Create | Create new Zoho item from WooCommerce product |
| Unlink | Remove connection between product and item |

### Auto-creation Options

| Setting | Description |
|---------|-------------|
| Auto-create Items | Create Zoho items for unmapped products during sync |
| Default Tax | Default tax rate for new items |
| Default Account | Default sales account for new items |

## Customer Settings

Located at **ZBooks > Settings > Customers**

### Contact Matching

| Method | Description |
|--------|-------------|
| Email | Match by customer email (recommended) |
| Name | Match by billing name |
| Create New | Always create new contact |

### Field Mapping

Map WooCommerce customer fields to Zoho contact fields:

| WooCommerce | Zoho |
|-------------|------|
| Billing First Name | Contact First Name |
| Billing Last Name | Contact Last Name |
| Billing Email | Contact Email |
| Billing Phone | Contact Phone |
| Billing Address | Billing Address |
| Shipping Address | Shipping Address |
| Company | Company Name |

## Retry Settings

Located at **ZBooks > Settings > Advanced**

| Setting | Options | Description |
|---------|---------|-------------|
| Max Retries | 1-10, Unlimited | Maximum retry attempts |
| Retry Interval | 15, 30, 60 minutes | Time between retries |
| Backoff | Linear, Exponential | Retry timing strategy |

### Exponential Backoff

Retry delays double each attempt:
- Attempt 1: 15 minutes
- Attempt 2: 30 minutes
- Attempt 3: 60 minutes
- Attempt 4: 120 minutes
- ...

## Logging

Located at **ZBooks > Logs**

### Log Levels

| Level | Description |
|-------|-------------|
| Info | Successful operations |
| Warning | Non-critical issues |
| Error | Failed operations |
| Debug | Detailed diagnostic info (if enabled) |

### Log Retention

| Setting | Description |
|---------|-------------|
| Keep Logs | Number of days to retain logs |
| Auto-cleanup | Automatically delete old logs |

## Reconciliation

Located at **ZBooks > Reconciliation**

### Comparison Options

| Setting | Description |
|---------|-------------|
| Date Range | Orders to compare |
| Include Synced | Show successfully synced orders |
| Include Errors | Show orders with sync errors |

### Discrepancy Types

| Type | Description |
|------|-------------|
| Missing | Order exists in WooCommerce but not Zoho |
| Unsynced | Order not yet synced |
| Amount Mismatch | Totals differ between systems |
| Status Mismatch | Invoice status differs |
