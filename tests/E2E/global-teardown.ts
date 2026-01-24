/**
 * Global teardown for Playwright E2E tests.
 *
 * Runs once after all tests complete to clean up the test environment.
 */

import { FullConfig } from '@playwright/test';

async function globalTeardown(config: FullConfig): Promise<void> {
	console.log('Global teardown: Cleaning up test environment...');

	// Add any global cleanup tasks here
	// For example: reset database state, clear uploads, etc.

	console.log('Global teardown complete.');
}

export default globalTeardown;
