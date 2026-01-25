# Getting Started

This guide walks you through installing and configuring ZBooks for WooCommerce.

## Installation

### From GitHub Release

1. Download the latest release from [GitHub Releases](https://github.com/talas9/zbooks-for-woocommerce/releases)
2. In WordPress admin, go to **Plugins > Add New > Upload Plugin**
3. Upload the zip file and click **Install Now**
4. Click **Activate Plugin**

### Manual Installation

1. Download and extract the plugin
2. Upload the `zbooks-for-woocommerce` folder to `/wp-content/plugins/`
3. Activate via **Plugins** in WordPress admin

## Zoho API Setup

Before configuring the plugin, you need Zoho API credentials.

### Step 1: Create a Zoho Application

1. Go to [Zoho API Console](https://api-console.zoho.com/)
2. Click **Add Client**
3. Select **Self Client**
4. Give it a name (e.g., "WooCommerce Integration")
5. Click **Create**

### Step 2: Generate Grant Token

1. In your Self Client, go to **Generate Code**
2. Enter scope: `ZohoBooks.fullaccess.all`
3. Set time duration (recommend 10 minutes)
4. Enter a description
5. Click **Create**
6. Copy the generated code (grant token)

### Step 3: Get Organization ID

1. Log in to [Zoho Books](https://books.zoho.com)
2. Go to **Settings > Organization Profile**
3. Copy the **Organization ID**

## Plugin Configuration

### Step 1: Enter API Credentials

1. Go to **ZBooks > Settings**
2. Enter your:
   - Client ID (from Zoho API Console)
   - Client Secret (from Zoho API Console)
   - Grant Token (generated code)
   - Organization ID
3. Select your Zoho datacenter region
4. Click **Connect to Zoho**

### Step 2: Configure Sync Triggers

1. Go to **ZBooks > Settings > Sync**
2. Select which order statuses trigger sync:
   - **Processing** - Creates draft invoice
   - **Completed** - Submits invoice and records payment
3. Choose default invoice status (Draft or Submitted)

### Step 3: Map Payment Methods

1. Go to **ZBooks > Settings > Payments**
2. For each WooCommerce payment gateway:
   - Select Zoho payment mode
   - Select deposit account (bank/cash account)
   - Optionally configure fee tracking

### Step 4: Link Products (Optional)

1. Go to **ZBooks > Settings > Products**
2. Click **Detect Unmapped Products**
3. For each product, either:
   - **Link** to existing Zoho item
   - **Create** new item in Zoho

## Testing Your Setup

### Test Connection

1. Go to **ZBooks > Settings**
2. Click **Test Connection**
3. Verify "Connected successfully" message

### Test Order Sync

1. Create a test order in WooCommerce
2. Change status to one of your configured triggers
3. Check **ZBooks > Logs** for sync results
4. Verify invoice appears in Zoho Books

## Next Steps

- [Configuration](Configuration.md) - Detailed settings reference
- [Troubleshooting](Troubleshooting.md) - If you encounter issues
