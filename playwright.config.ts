/**
 * Playwright configuration for ZBooks for WooCommerce E2E tests.
 *
 * Uses @wordpress/e2e-test-utils-playwright for WordPress-specific testing utilities.
 *
 * @see https://playwright.dev/docs/test-configuration
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-e2e-test-utils-playwright/
 */

import { defineConfig, devices } from '@playwright/test';

/**
 * Read environment variables for test configuration.
 */
const WP_BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8889';
const WP_ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const WP_ADMIN_PASSWORD = process.env.WP_ADMIN_PASSWORD || 'password';

export default defineConfig({
	/**
	 * Directory containing test files.
	 */
	testDir: './tests/E2E',

	/**
	 * Test file matching pattern.
	 */
	testMatch: '**/*.spec.ts',

	/**
	 * Run tests in files in parallel.
	 */
	fullyParallel: true,

	/**
	 * Fail the build on CI if you accidentally left test.only in the source code.
	 */
	forbidOnly: !!process.env.CI,

	/**
	 * Retry on CI only.
	 */
	retries: process.env.CI ? 2 : 0,

	/**
	 * Opt out of parallel tests on CI.
	 */
	workers: process.env.CI ? 1 : undefined,

	/**
	 * Reporter to use.
	 */
	reporter: [
		['html', { outputFolder: 'playwright-report' }],
		['list'],
		...(process.env.CI ? [['github' as const]] : []),
	],

	/**
	 * Shared settings for all the projects below.
	 */
	use: {
		/**
		 * Base URL to use in actions like `await page.goto('/')`.
		 */
		baseURL: WP_BASE_URL,

		/**
		 * Collect trace when retrying the failed test.
		 */
		trace: 'on-first-retry',

		/**
		 * Take screenshot on failure.
		 */
		screenshot: 'only-on-failure',

		/**
		 * Record video on failure.
		 */
		video: 'on-first-retry',

		/**
		 * Viewport size.
		 */
		viewport: { width: 1280, height: 720 },

		/**
		 * Maximum time each action can take.
		 */
		actionTimeout: 10000,

		/**
		 * Maximum time to wait for navigation.
		 */
		navigationTimeout: 30000,
	},

	/**
	 * Global timeout for each test.
	 */
	timeout: 60000,

	/**
	 * Expect timeout.
	 */
	expect: {
		timeout: 10000,
	},

	/**
	 * Configure projects for major browsers.
	 */
	projects: [
		/**
		 * Setup project for authentication.
		 * Runs before all other projects to establish authenticated state.
		 */
		{
			name: 'setup',
			testMatch: /.*\.setup\.ts/,
			use: {
				...devices['Desktop Chrome'],
			},
		},

		/**
		 * Desktop Chrome - primary test browser.
		 */
		{
			name: 'chromium',
			use: {
				...devices['Desktop Chrome'],
				// Use authenticated state from setup.
				storageState: 'playwright/.auth/admin.json',
			},
			dependencies: ['setup'],
		},

		/**
		 * Desktop Firefox.
		 */
		{
			name: 'firefox',
			use: {
				...devices['Desktop Firefox'],
				storageState: 'playwright/.auth/admin.json',
			},
			dependencies: ['setup'],
		},

		/**
		 * Desktop Safari (WebKit).
		 */
		{
			name: 'webkit',
			use: {
				...devices['Desktop Safari'],
				storageState: 'playwright/.auth/admin.json',
			},
			dependencies: ['setup'],
		},

		/**
		 * Mobile Chrome.
		 */
		{
			name: 'mobile-chrome',
			use: {
				...devices['Pixel 5'],
				storageState: 'playwright/.auth/admin.json',
			},
			dependencies: ['setup'],
		},

		/**
		 * Mobile Safari.
		 */
		{
			name: 'mobile-safari',
			use: {
				...devices['iPhone 12'],
				storageState: 'playwright/.auth/admin.json',
			},
			dependencies: ['setup'],
		},
	],

	/**
	 * Output folder for test artifacts.
	 */
	outputDir: 'test-results',

	/**
	 * Run local dev server before starting the tests.
	 * Uncomment and configure if you want Playwright to start your dev server.
	 */
	// webServer: {
	// 	command: 'npm run wp-env start',
	// 	url: WP_BASE_URL,
	// 	reuseExistingServer: !process.env.CI,
	// 	timeout: 120000,
	// },

	/**
	 * Global setup file - runs once before all tests.
	 */
	globalSetup: './tests/E2E/global-setup.ts',

	/**
	 * Global teardown file - runs once after all tests.
	 */
	globalTeardown: './tests/E2E/global-teardown.ts',
});

/**
 * Export WordPress credentials for use in tests.
 */
export const wpCredentials = {
	adminUser: WP_ADMIN_USER,
	adminPassword: WP_ADMIN_PASSWORD,
};
