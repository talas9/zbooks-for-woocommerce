/**
 * Bulk Sync with Trigger Settings E2E Tests
 * 
 * This test verifies that bulk sync respects trigger settings and syncs orders
 * according to their status, not with a hardcoded draft/submit flag.
 */
import { test, expect } from '@playwright/test';

test.describe('Bulk Sync with Trigger Settings', () => {
    test.beforeAll(async ({ browser }) => {
        // This test requires orders to be created BEFORE the plugin is installed
        // to ensure automatic triggers don't sync them
        const context = await browser.newContext();
        const page = await context.newPage();
        
        // Navigate to WooCommerce orders page
        await page.goto('/wp-admin/edit.php?post_type=shop_order');
        
        // Check if we have pre-existing orders
        const ordersExist = await page.locator('.wp-list-table tbody tr').count() > 0;
        
        if (!ordersExist) {
            console.log('No pre-existing orders found. Creating test orders...');
            // Note: In a real test, you would create orders here using WooCommerce API
            // or a helper script before running the test
        }
        
        await context.close();
    });

    test('bulk sync respects trigger settings for different order statuses', async ({ page }) => {
        // Step 1: Verify trigger settings are configured
        await page.goto('/wp-admin/admin.php?page=zbooks-settings&tab=orders');
        
        // Check that trigger settings exist
        await expect(page.locator('h2:has-text("Status Actions")')).toBeVisible();
        
        // Get current trigger configuration
        const draftTriggerStatus = await page.locator('select[name="zbooks_sync_triggers[sync_draft]"]').inputValue();
        const submitTriggerStatus = await page.locator('select[name="zbooks_sync_triggers[sync_submit]"]').inputValue();
        
        console.log(`Draft trigger: ${draftTriggerStatus}, Submit trigger: ${submitTriggerStatus}`);
        
        // Step 2: Create test orders with different statuses
        // (In a real scenario, these would be created by a setup script)
        
        // Step 3: Navigate to orders page
        await page.goto('/wp-admin/edit.php?post_type=shop_order');
        
        // Wait for orders to load
        await page.waitForSelector('.wp-list-table', { timeout: 10000 });
        
        // Step 4: Select multiple orders with different statuses
        const orderRows = page.locator('.wp-list-table tbody tr');
        const orderCount = await orderRows.count();
        
        if (orderCount === 0) {
            console.log('No orders available for testing. Skipping test.');
            test.skip();
            return;
        }
        
        // Select first few orders (up to 3)
        const ordersToSelect = Math.min(3, orderCount);
        const selectedOrders: Array<{ id: string; status: string }> = [];
        
        for (let i = 0; i < ordersToSelect; i++) {
            const row = orderRows.nth(i);
            const checkbox = row.locator('input[type="checkbox"]');
            const orderId = await checkbox.getAttribute('value');
            const statusBadge = row.locator('.order-status');
            const status = await statusBadge.textContent();
            
            if (orderId) {
                await checkbox.check();
                selectedOrders.push({ 
                    id: orderId, 
                    status: status?.trim().toLowerCase().replace('wc-', '') || 'unknown' 
                });
            }
        }
        
        console.log('Selected orders:', selectedOrders);
        
        // Step 5: Execute bulk sync action
        await page.locator('select#bulk-action-selector-top').selectOption('zbooks_sync');
        await page.locator('#doaction').click();
        
        // Wait for redirect and notice
        await page.waitForURL(/.*edit\.php\?post_type=shop_order.*/, { timeout: 30000 });
        
        // Step 6: Verify success notice appears
        const successNotice = page.locator('.notice-success');
        await expect(successNotice).toBeVisible({ timeout: 10000 });
        
        const noticeText = await successNotice.textContent();
        console.log('Bulk sync notice:', noticeText);
        
        // Verify that some orders were synced
        expect(noticeText).toMatch(/\d+ order[s]? synced to Zoho Books/);
        
        // Step 7: Verify each order was synced according to its status
        for (const order of selectedOrders) {
            // Navigate to order edit page
            await page.goto(`/wp-admin/post.php?post=${order.id}&action=edit`);
            
            // Wait for Zoho Books meta box
            await page.waitForSelector('.zbooks-meta-box', { timeout: 10000 });
            
            // Check sync status
            const syncStatus = page.locator('.zbooks-meta-box .zbooks-status');
            await expect(syncStatus).toBeVisible();
            
            const statusText = await syncStatus.textContent();
            console.log(`Order ${order.id} (${order.status}): ${statusText}`);
            
            // Verify order was synced (either as draft or submitted)
            expect(statusText).toMatch(/Synced|Draft/);
            
            // Verify invoice link exists
            const invoiceLink = page.locator('.zbooks-meta-box a[href*="zoho.com"]');
            await expect(invoiceLink).toBeVisible();
            
            // Check if invoice status matches expected based on order status
            const invoiceStatusElement = page.locator('.zbooks-meta-box p:has-text("Invoice Status:")');
            if (await invoiceStatusElement.isVisible()) {
                const invoiceStatus = await invoiceStatusElement.textContent();
                console.log(`Invoice status: ${invoiceStatus}`);
                
                // If order status matches draft trigger, invoice should be draft
                if (order.status === draftTriggerStatus) {
                    expect(invoiceStatus).toContain('Draft');
                }
                // If order status matches submit trigger, invoice should be sent/viewed
                else if (order.status === submitTriggerStatus) {
                    expect(invoiceStatus).toMatch(/Sent|Viewed|Paid/);
                }
            }
        }
    });

    test('bulk sync handles mixed order statuses correctly', async ({ page }) => {
        // This test verifies that when syncing orders with different statuses,
        // each order is synced according to its own status, not a global setting
        
        await page.goto('/wp-admin/edit.php?post_type=shop_order');
        
        // Get order count
        const orderRows = page.locator('.wp-list-table tbody tr');
        const orderCount = await orderRows.count();
        
        if (orderCount < 2) {
            console.log('Need at least 2 orders for this test. Skipping.');
            test.skip();
            return;
        }
        
        // Find orders with different statuses
        const processingOrders: string[] = [];
        const completedOrders: string[] = [];
        
        for (let i = 0; i < orderCount; i++) {
            const row = orderRows.nth(i);
            const checkbox = row.locator('input[type="checkbox"]');
            const orderId = await checkbox.getAttribute('value');
            const statusBadge = row.locator('.order-status');
            const statusText = await statusBadge.textContent();
            const status = statusText?.trim().toLowerCase().replace('wc-', '') || '';
            
            if (status === 'processing' && processingOrders.length < 1 && orderId) {
                processingOrders.push(orderId);
                await checkbox.check();
            } else if (status === 'completed' && completedOrders.length < 1 && orderId) {
                completedOrders.push(orderId);
                await checkbox.check();
            }
            
            if (processingOrders.length >= 1 && completedOrders.length >= 1) {
                break;
            }
        }
        
        if (processingOrders.length === 0 || completedOrders.length === 0) {
            console.log('Could not find orders with both processing and completed statuses. Skipping.');
            test.skip();
            return;
        }
        
        console.log('Processing orders:', processingOrders);
        console.log('Completed orders:', completedOrders);
        
        // Execute bulk sync
        await page.locator('select#bulk-action-selector-top').selectOption('zbooks_sync');
        await page.locator('#doaction').click();
        
        // Wait for completion
        await page.waitForURL(/.*edit\.php\?post_type=shop_order.*/, { timeout: 30000 });
        
        // Verify processing order was synced as draft
        if (processingOrders[0]) {
            await page.goto(`/wp-admin/post.php?post=${processingOrders[0]}&action=edit`);
            await page.waitForSelector('.zbooks-meta-box', { timeout: 10000 });
            
            const invoiceStatusElement = page.locator('.zbooks-meta-box p:has-text("Invoice Status:")');
            if (await invoiceStatusElement.isVisible()) {
                const invoiceStatus = await invoiceStatusElement.textContent();
                console.log(`Processing order invoice status: ${invoiceStatus}`);
                expect(invoiceStatus).toContain('Draft');
            }
        }
        
        // Verify completed order was synced as submitted
        if (completedOrders[0]) {
            await page.goto(`/wp-admin/post.php?post=${completedOrders[0]}&action=edit`);
            await page.waitForSelector('.zbooks-meta-box', { timeout: 10000 });
            
            const invoiceStatusElement = page.locator('.zbooks-meta-box p:has-text("Invoice Status:")');
            if (await invoiceStatusElement.isVisible()) {
                const invoiceStatus = await invoiceStatusElement.textContent();
                console.log(`Completed order invoice status: ${invoiceStatus}`);
                expect(invoiceStatus).toMatch(/Sent|Viewed|Paid/);
            }
        }
    });

    test('bulk sync action dropdown only shows single sync option', async ({ page }) => {
        // Verify that the bulk actions dropdown no longer has separate draft/submit options
        await page.goto('/wp-admin/edit.php?post_type=shop_order');
        
        // Open bulk actions dropdown
        const bulkActionSelect = page.locator('select#bulk-action-selector-top');
        await expect(bulkActionSelect).toBeVisible();
        
        // Get all options
        const options = await bulkActionSelect.locator('option').allTextContents();
        
        // Should have "Sync to Zoho Books" option
        expect(options.some(opt => opt.includes('Sync to Zoho Books'))).toBeTruthy();
        
        // Should NOT have separate draft option
        expect(options.some(opt => opt.includes('Draft'))).toBeFalsy();
        
        console.log('Bulk action options:', options);
    });
});
