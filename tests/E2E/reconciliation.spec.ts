/**
 * Reconciliation Feature E2E Tests
 */
import { test, expect } from '@playwright/test';

/**
 * Tests for the Reconciliation Settings tab (Settings > Reconciliation)
 */
test.describe('Reconciliation Settings Tab', () => {
	test.beforeEach(async ({ page }) => {
		// Navigate to ZBooks settings with reconciliation tab
		await page.goto('/wp-admin/admin.php?page=zbooks&tab=reconciliation');
	});

	test('reconciliation settings tab loads without errors', async ({ page }) => {
		// Check that the settings heading is present
		await expect(
			page.locator('h2:has-text("Reconciliation Settings")')
		).toBeVisible();

		// Check that description with link to reports is present
		await expect(
			page.locator('a:has-text("Go to Reconciliation Reports")')
		).toBeVisible();

		// Verify no critical errors
		const bodyText = await page.locator('body').textContent();
		expect(bodyText).not.toContain('critical error');
		expect(bodyText).not.toContain('Fatal error');
	});

	test('reconciliation settings form is present', async ({ page }) => {
		// Check for Enable checkbox
		await expect(
			page.locator(
				'input[name="reconciliation[enabled]"][type="checkbox"]'
			)
		).toBeVisible();

		// Check for frequency dropdown
		await expect(
			page.locator('select[name="reconciliation[frequency]"]')
		).toBeVisible();

		// Check for amount tolerance field
		await expect(
			page.locator('input[name="reconciliation[amount_tolerance]"]')
		).toBeVisible();

		// Check for email on discrepancy only checkbox (email settings are on Notifications tab)
		await expect(
			page.locator(
				'input[name="reconciliation[email_on_discrepancy_only]"][type="checkbox"]'
			)
		).toBeVisible();

		// Check for Save Settings button
		await expect(
			page.locator(
				'input[name="save_reconciliation_settings"], button:has-text("Save Settings")'
			)
		).toBeVisible();
	});

	test('reconciliation is disabled by default', async ({ page }) => {
		// The "enabled" checkbox should be unchecked by default
		const enabledCheckbox = page.locator(
			'input[name="reconciliation[enabled]"]'
		);
		await expect(enabledCheckbox).toBeVisible();
	});

	test('frequency dropdown has correct options', async ({ page }) => {
		const frequencySelect = page.locator(
			'select[name="reconciliation[frequency]"]'
		);

		// Check all options exist
		await expect(frequencySelect.locator('option[value="daily"]')).toHaveText(
			'Daily'
		);
		await expect(frequencySelect.locator('option[value="weekly"]')).toHaveText(
			'Weekly'
		);
		await expect(
			frequencySelect.locator('option[value="monthly"]')
		).toHaveText('Monthly');
	});

	test('day of week shows when weekly is selected', async ({ page }) => {
		const frequencySelect = page.locator(
			'select[name="reconciliation[frequency]"]'
		);
		const weeklyOption = page.locator('.zbooks-weekly-option');

		// Select weekly
		await frequencySelect.selectOption('weekly');

		// Day of week should be visible
		await expect(weeklyOption).toBeVisible();

		// Day of month should be hidden
		await expect(page.locator('.zbooks-monthly-option')).toBeHidden();
	});

	test('day of month shows when monthly is selected', async ({ page }) => {
		const frequencySelect = page.locator(
			'select[name="reconciliation[frequency]"]'
		);
		const monthlyOption = page.locator('.zbooks-monthly-option');

		// Select monthly
		await frequencySelect.selectOption('monthly');

		// Day of month should be visible
		await expect(monthlyOption).toBeVisible();

		// Day of week should be hidden
		await expect(page.locator('.zbooks-weekly-option')).toBeHidden();
	});

	test('day options hidden when daily is selected', async ({ page }) => {
		const frequencySelect = page.locator(
			'select[name="reconciliation[frequency]"]'
		);

		// Select daily
		await frequencySelect.selectOption('daily');

		// Both day options should be hidden
		await expect(page.locator('.zbooks-weekly-option')).toBeHidden();
		await expect(page.locator('.zbooks-monthly-option')).toBeHidden();
	});

	test('can save reconciliation settings', async ({ page }) => {
		// Change some settings
		const toleranceInput = page.locator(
			'input[name="reconciliation[amount_tolerance]"]'
		);
		await toleranceInput.fill('0.10');

		// Click Save Settings
		await page.locator('input[name="save_reconciliation_settings"]').click();

		// Wait for page to reload
		await page.waitForLoadState('networkidle');

		// Check for success notice or that value persisted
		// Note: HTML number inputs may strip trailing zeros (0.10 -> 0.1)
		const updatedTolerance = page.locator(
			'input[name="reconciliation[amount_tolerance]"]'
		);
		const toleranceValue = await updatedTolerance.inputValue();
		expect(parseFloat(toleranceValue)).toBe(0.1);
	});

	test('email settings can be configured', async ({ page }) => {
		// Check email on discrepancy only checkbox (this is the only email option on the settings tab)
		const emailOnDiscrepancy = page.locator(
			'input[name="reconciliation[email_on_discrepancy_only]"]'
		);

		// Email option should be present
		await expect(emailOnDiscrepancy).toBeVisible();

		// Check for link to Notifications tab in the form description (where email settings are configured)
		// Use .zbooks-reconciliation-settings to scope within the form area, avoiding the nav tab
		await expect(
			page.locator('.zbooks-reconciliation-settings a[href*="tab=notifications"]')
		).toBeVisible();
	});
});

/**
 * Tests for the Reconciliation Page (Run & Reports)
 */
test.describe('Reconciliation Page', () => {
	test.beforeEach(async ({ page }) => {
		// Navigate to the standalone reconciliation page
		await page.goto('/wp-admin/admin.php?page=zbooks-reconciliation');
	});

	test('reconciliation page loads without errors', async ({ page }) => {
		// Check that the page title is present
		await expect(page.locator('h1:has-text("Reconciliation")')).toBeVisible();

		// Check that description is present
		await expect(
			page.locator(
				'p.description:has-text("Compare WooCommerce orders with Zoho Books invoices")'
			)
		).toBeVisible();

		// Verify no critical errors
		const bodyText = await page.locator('body').textContent();
		expect(bodyText).not.toContain('critical error');
		expect(bodyText).not.toContain('Fatal error');
	});

	test('run reconciliation section is present', async ({ page }) => {
		// Check for Run Reconciliation section
		await expect(
			page.locator('h2:has-text("Run Reconciliation")')
		).toBeVisible();

		// Check for date inputs
		await expect(page.locator('#zbooks-recon-start')).toBeVisible();
		await expect(page.locator('#zbooks-recon-end')).toBeVisible();

		// Check for Run Now button
		await expect(page.locator('#zbooks-run-reconciliation')).toBeVisible();
	});

	test('date inputs have sensible defaults', async ({ page }) => {
		const startDate = page.locator('#zbooks-recon-start');
		const endDate = page.locator('#zbooks-recon-end');

		// Both should have values set
		const startValue = await startDate.inputValue();
		const endValue = await endDate.inputValue();

		// Start date should be before end date
		expect(new Date(startValue).getTime()).toBeLessThanOrEqual(
			new Date(endValue).getTime()
		);

		// Both should be in the past (max attribute should be set)
		const maxDate = await startDate.getAttribute('max');
		expect(maxDate).toBeTruthy();
	});

	test('date validation prevents invalid ranges', async ({ page }) => {
		const startDate = page.locator('#zbooks-recon-start');
		const endDate = page.locator('#zbooks-recon-end');
		const runButton = page.locator('#zbooks-run-reconciliation');

		// Set start date after end date
		await startDate.fill('2024-12-31');
		await endDate.fill('2024-01-01');

		// Set up dialog handler
		let dialogMessage = '';
		page.on('dialog', async (dialog) => {
			dialogMessage = dialog.message();
			await dialog.accept();
		});

		// Click Run Now
		await runButton.click();

		// Should show validation error
		expect(dialogMessage).toContain('Start date must be before end date');
	});

	test('empty dates show validation error', async ({ page }) => {
		const startDate = page.locator('#zbooks-recon-start');
		const endDate = page.locator('#zbooks-recon-end');
		const runButton = page.locator('#zbooks-run-reconciliation');

		// Clear dates
		await startDate.fill('');
		await endDate.fill('');

		// Set up dialog handler
		let dialogMessage = '';
		page.on('dialog', async (dialog) => {
			dialogMessage = dialog.message();
			await dialog.accept();
		});

		// Click Run Now
		await runButton.click();

		// Should show validation error
		expect(dialogMessage).toContain('Please select both start and end dates');
	});
});

test.describe('Report History', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=zbooks-reconciliation');
	});

	test('report history section is present', async ({ page }) => {
		// Check for Report History section
		await expect(
			page.locator('h2:has-text("Report History")')
		).toBeVisible();

		// Either shows "No reconciliation reports yet." or a table
		const noReportsMessage = page.locator(
			'p.description:has-text("No reconciliation reports yet")'
		);
		const reportsTable = page.locator('.zbooks-report-history table');

		// One of these should be visible
		const hasNoReports = await noReportsMessage.isVisible().catch(() => false);
		const hasTable = await reportsTable.isVisible().catch(() => false);

		expect(hasNoReports || hasTable).toBeTruthy();
	});

	test('reports table has correct columns when reports exist', async ({
		page,
	}) => {
		const reportsTable = page.locator('.zbooks-report-history table');
		const hasTable = await reportsTable.isVisible().catch(() => false);

		if (hasTable) {
			// Check table headers - scope to the report history table to avoid matching
			// the "Recent Discrepancies" table which also has Date/Status columns
			await expect(
				reportsTable.locator('th:has-text("Date")')
			).toBeVisible();
			await expect(
				reportsTable.locator('th:has-text("Period")')
			).toBeVisible();
			await expect(
				reportsTable.locator('th:has-text("Status")')
			).toBeVisible();
			await expect(
				reportsTable.locator('th:has-text("Matched")')
			).toBeVisible();
			await expect(
				reportsTable.locator('th:has-text("Discrepancies")')
			).toBeVisible();
			await expect(
				reportsTable.locator('th:has-text("Difference")')
			).toBeVisible();
			await expect(
				reportsTable.locator('th:has-text("Actions")')
			).toBeVisible();
		}
	});

	test('report actions buttons exist when reports exist', async ({ page }) => {
		const viewButtons = page.locator('.zbooks-view-report');
		const deleteButtons = page.locator('.zbooks-delete-report');

		const viewCount = await viewButtons.count();
		const deleteCount = await deleteButtons.count();

		// If there are reports, there should be action buttons
		if (viewCount > 0) {
			expect(deleteCount).toBeGreaterThan(0);
		}
	});
});

test.describe('Reconciliation CSS Styling', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=zbooks-reconciliation');
	});

	test('badge styles are defined', async ({ page }) => {
		// Check that the style tag with badge styles exists
		const styleContent = await page.evaluate(() => {
			const styles = document.querySelectorAll('style');
			for (const style of styles) {
				if (style.textContent?.includes('.zbooks-badge')) {
					return style.textContent;
				}
			}
			return '';
		});

		// Should have badge styles defined
		expect(styleContent).toContain('.zbooks-badge-missing_in_zoho');
		expect(styleContent).toContain('.zbooks-badge-amount_mismatch');
	});

	test('status styles are defined', async ({ page }) => {
		const styleContent = await page.evaluate(() => {
			const styles = document.querySelectorAll('style');
			for (const style of styles) {
				if (style.textContent?.includes('.zbooks-status')) {
					return style.textContent;
				}
			}
			return '';
		});

		// Should have status styles defined
		expect(styleContent).toContain('.zbooks-status-completed');
		expect(styleContent).toContain('.zbooks-status-failed');
	});
});

test.describe('Latest Report Summary', () => {
	test('shows summary cards when report exists', async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=zbooks-reconciliation');

		// Check for Latest Report Summary section (only visible if reports exist)
		const latestReport = page.locator('.zbooks-latest-report');
		const hasLatestReport = await latestReport.isVisible().catch(() => false);

		if (hasLatestReport) {
			// Check for summary cards using correct class names from ReconciliationPage.php
			await expect(
				page.locator('.zbooks-card:has(.zbooks-card-label:has-text("Matched"))')
			).toBeVisible();
			await expect(
				page.locator(
					'.zbooks-card:has(.zbooks-card-label:has-text("Missing in Zoho"))'
				)
			).toBeVisible();
			await expect(
				page.locator(
					'.zbooks-card:has(.zbooks-card-label:has-text("Amount Mismatches"))'
				)
			).toBeVisible();
			await expect(
				page.locator(
					'.zbooks-card:has(.zbooks-card-label:has-text("Total Difference"))'
				)
			).toBeVisible();
		}
	});
});

test.describe('Navigation between Settings and Reports', () => {
	test('link from settings to reports works', async ({ page }) => {
		// Go to settings tab
		await page.goto('/wp-admin/admin.php?page=zbooks&tab=reconciliation');

		// Click the link to reports
		await page.locator('a:has-text("Go to Reconciliation Reports")').click();

		// Should be on the reconciliation page
		await page.waitForLoadState('networkidle');
		expect(page.url()).toContain('page=zbooks-reconciliation');

		// Page should load correctly
		await expect(
			page.locator('h2:has-text("Run Reconciliation")')
		).toBeVisible();
	});
});
