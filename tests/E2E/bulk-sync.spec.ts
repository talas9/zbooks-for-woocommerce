/**
 * Bulk Sync Page E2E Tests
 */
import { test, expect } from '@playwright/test';

test.describe('Bulk Sync Page', () => {
    test.beforeEach(async ({ page }) => {
        // Go to bulk sync page
        await page.goto('/wp-admin/admin.php?page=zbooks-bulk-sync');
    });

    test('bulk sync page loads without errors', async ({ page }) => {
        // Check that the page title is present
        await expect(page.locator('h1')).toContainText('Bulk Sync Orders to Zoho Books');

        // Check that statistics boxes are present
        await expect(page.locator('.zbooks-stat-box').first()).toBeVisible();

        // Check that the date range form is present
        await expect(page.locator('#zbooks-bulk-sync-form')).toBeVisible();
        await expect(page.locator('#zbooks_date_from')).toBeVisible();
        await expect(page.locator('#zbooks_date_to')).toBeVisible();

        // Check that the Start Bulk Sync button is present
        await expect(page.locator('#zbooks-start-bulk-sync')).toBeVisible();

        // Verify no critical errors
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toContain('critical error');
        expect(bodyText).not.toContain('Fatal error');
    });

    test('pending orders table displays correctly', async ({ page }) => {
        // The table might show "No pending orders" or a list of orders
        const tableOrMessage = page.locator('#zbooks-orders-form, p:has-text("No pending orders")');
        await expect(tableOrMessage).toBeVisible();

        // If there's a table, check its structure
        const table = page.locator('#zbooks-orders-form table');
        if (await table.isVisible()) {
            // Check table headers (use exact text to avoid matching "Order Status" when checking "Order")
            await expect(page.locator('th', { hasText: /^Order$/ })).toBeVisible();
            await expect(page.locator('th:has-text("Customer")')).toBeVisible();
            await expect(page.locator('th:has-text("Total")')).toBeVisible();
            await expect(page.locator('th:has-text("Sync Status")')).toBeVisible();

            // Check select all checkbox
            await expect(page.locator('.zbooks-select-all')).toBeVisible();
        }
    });

    test('select all checkbox works', async ({ page }) => {
        // Check if there are any checkboxes
        const checkboxes = page.locator('.zbooks-item-checkbox');
        const count = await checkboxes.count();

        if (count > 0) {
            // Click select all
            await page.locator('.zbooks-select-all').click();

            // All checkboxes should be checked
            for (let i = 0; i < count; i++) {
                await expect(checkboxes.nth(i)).toBeChecked();
            }

            // Selected count should update
            await expect(page.locator('.zbooks-selected-count')).toContainText(`${count} item(s) selected`);

            // Sync Selected button should be enabled
            await expect(page.locator('#zbooks-sync-selected')).toBeEnabled();
        }
    });
});
