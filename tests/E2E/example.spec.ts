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

	test('WooCommerce is active', async ({ page }) => {
		// Navigate to plugins page.
		await page.goto('/wp-admin/plugins.php');

		// Look for WooCommerce plugin in the list.
		const wooRow = page.locator('tr[data-slug="woocommerce"]');

		// Verify WooCommerce is active.
		await expect(wooRow.locator('.deactivate')).toBeVisible();
	});

	test('WooCommerce menu is accessible', async ({ page }) => {
		// Navigate to WooCommerce menu.
		await page.goto('/wp-admin/admin.php?page=wc-admin');

		// Verify the page loaded (WooCommerce admin exists).
		await expect(page).toHaveURL(/wc-admin/);
	});
});

test.describe('Frontend', () => {
	test('WordPress site loads correctly', async ({ page }) => {
		// Navigate to homepage.
		await page.goto('/');

		// Verify the site loads (body exists).
		await expect(page.locator('body')).toBeVisible();
	});
});
