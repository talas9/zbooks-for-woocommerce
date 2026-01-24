/**
 * Global setup for Playwright E2E tests.
 *
 * Runs once before all tests to prepare the test environment.
 */

import { chromium, FullConfig } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

async function globalSetup(config: FullConfig): Promise<void> {
	const baseURL = config.projects[0].use.baseURL || 'http://localhost:8889';

	console.log('Global setup: Preparing test environment...');
	console.log('Base URL: ' + baseURL);

	// Ensure auth directory exists
	const authDir = path.join(process.cwd(), 'playwright', '.auth');
	if (!fs.existsSync(authDir)) {
		fs.mkdirSync(authDir, { recursive: true });
	}

	// Verify WordPress is accessible
	const browser = await chromium.launch();
	const context = await browser.newContext();
	const page = await context.newPage();

	try {
		console.log('Verifying WordPress is accessible...');
		const response = await page.goto(baseURL, { waitUntil: 'networkidle' });

		if (!response || !response.ok()) {
			const status = response ? response.status() : 'unknown';
			throw new Error(
				'WordPress is not accessible at ' + baseURL + '. Status: ' + status
			);
		}

		console.log('WordPress is accessible.');
	} catch (error) {
		console.error('Failed to connect to WordPress:', error);
		throw error;
	} finally {
		await context.close();
		await browser.close();
	}

	console.log('Global setup complete.');
}

export default globalSetup;
