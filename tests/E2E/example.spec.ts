/**
 * Example E2E test for ZBooks for WooCommerce plugin.
 *
 * Demonstrates how to write Playwright tests for WordPress.
 *
 * @see https://playwright.dev/docs/writing-tests
 */

import { test, expect } from '@playwright/test';

test.describe('ZBooks for WooCommerce Plugin', () => {
	test('plugin is activated in admin', async ({ page }) => {
		// Navigate to plugins page.
		await page.goto('/wp-admin/plugins.php');

		// Look for ZBooks for WooCommerce plugin in the list.
		const pluginRow = page.locator('tr[data-slug="zbooks-for-woocommerce"]');

		// Verify plugin is active (has deactivate link instead of activate).
		await expect(pluginRow.locator('.deactivate')).toBeVisible();
	});

	test('plugin settings page is accessible', async ({ page }) => {
		// Navigate to ZBooks for WooCommerce settings.
		// Adjust this URL based on your actual plugin menu structure.
		await page.goto('/wp-admin/admin.php?page=zbooks');

		// Verify the page loaded without errors.
		await expect(page.locator('.wrap')).toBeVisible();

		// Check for plugin title.
		await expect(page.locator('h1')).toContainText('ZBooks');
	});

	test('WooCommerce integration is configured', async ({ page }) => {
		// Navigate to WooCommerce settings.
		await page.goto('/wp-admin/admin.php?page=wc-settings');

		// Verify WooCommerce settings page is accessible.
		await expect(page.locator('.woocommerce')).toBeVisible();
	});
});

test.describe('Frontend', () => {
	test('shop page loads correctly', async ({ page }) => {
		// Navigate to shop page.
		await page.goto('/shop/');

		// Verify shop page elements.
		await expect(page.locator('.woocommerce')).toBeVisible();
	});
});
