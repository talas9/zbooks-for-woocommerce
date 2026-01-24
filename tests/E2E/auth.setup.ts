/**
 * Authentication setup for Playwright E2E tests.
 *
 * This file handles WordPress admin authentication and saves the session
 * for use by other test files.
 */

import { test as setup, expect } from '@playwright/test';
import * as path from 'path';

const authFile = path.join(__dirname, '../../playwright/.auth/admin.json');

setup('authenticate as admin', async ({ page }) => {
	const baseURL = process.env.WP_BASE_URL || 'http://localhost:8889';
	const adminUser = process.env.WP_ADMIN_USER || 'admin';
	const adminPassword = process.env.WP_ADMIN_PASSWORD || 'password';

	console.log(`Authenticating as ${adminUser}...`);

	// Navigate to WordPress login page
	await page.goto(`${baseURL}/wp-login.php`);

	// Fill in login credentials
	await page.locator('#user_login').fill(adminUser);
	await page.locator('#user_pass').fill(adminPassword);

	// Click the login button
	await page.locator('#wp-submit').click();

	// Wait for redirect to dashboard
	await page.waitForURL('**/wp-admin/**');

	// Verify we're logged in by checking for admin menu
	await expect(page.locator('#adminmenu')).toBeVisible();

	console.log('Authentication successful.');

	// Save the authenticated state
	await page.context().storageState({ path: authFile });
});
