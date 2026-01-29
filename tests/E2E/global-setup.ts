/**
 * Global setup for Playwright E2E tests.
 *
 * Runs once before all tests to prepare the test environment.
 */

import { chromium, FullConfig } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { execSync } from 'child_process';

/**
 * Check if wp-env is currently running by checking for active containers.
 */
function isWpEnvRunning(): boolean {
	try {
		const result = execSync('docker ps --format "{{.Names}}" | grep -E "tests-wordpress|tests-mysql"', {
			encoding: 'utf-8',
			stdio: ['pipe', 'pipe', 'pipe'],
		});
		return result.trim().length > 0;
	} catch {
		return false;
	}
}

/**
 * Start wp-env if it's not already running.
 * Returns true if environment was started, false if already running.
 */
function ensureWpEnvRunning(): boolean {
	if (isWpEnvRunning()) {
		console.log('wp-env is already running, using existing environment.');
		return false;
	}

	console.log('wp-env is not running, starting it now...');
	try {
		execSync('npm run env:start', {
			cwd: process.cwd(),
			encoding: 'utf-8',
			stdio: 'inherit',
		});
		console.log('wp-env started successfully.');
		
		// Wait a bit for services to be fully ready
		console.log('Waiting for services to be ready...');
		execSync('sleep 5');
		
		return true;
	} catch (error) {
		console.error('Failed to start wp-env:', error);
		throw new Error('Cannot start wp-env. Make sure Docker is running and ports 8888/8889 are available.');
	}
}

/**
 * Run a WP-CLI command in the wp-env tests container.
 */
function wpCli(command: string): string {
	try {
		const result = execSync(`npx wp-env run tests-cli wp ${command}`, {
			cwd: process.cwd(),
			encoding: 'utf-8',
			stdio: ['pipe', 'pipe', 'pipe'],
		});
		return result;
	} catch (error: unknown) {
		const err = error as { stderr?: string; stdout?: string };
		return err.stderr || err.stdout || '';
	}
}

/**
 * Check if environment is already fully configured.
 * Returns true if all setup has been done.
 */
function isEnvironmentConfigured(): boolean {
	// Single batched check for all required state
	const checkScript = `
		\$plugins = get_option('active_plugins', []);
		\$has_wc = in_array('woocommerce/woocommerce.php', \$plugins);
		\$has_zbooks = in_array('zbooks-for-woocommerce/zbooks-for-woocommerce.php', \$plugins);
		\$onboarding = get_option('woocommerce_onboarding_profile', []);
		\$onboarding_done = isset(\$onboarding['skipped']) && \$onboarding['skipped'];
		\$zoho_org = get_option('zbooks_organization_id', '');
		echo json_encode([
			'plugins_active' => \$has_wc && \$has_zbooks,
			'onboarding_done' => \$onboarding_done,
			'zoho_configured' => !empty(\$zoho_org)
		]);
	`;

	const result = wpCli(`eval '${checkScript.replace(/\n/g, ' ')}'`);
	try {
		const state = JSON.parse(result.trim());
		return state.plugins_active && state.onboarding_done && state.zoho_configured;
	} catch {
		return false;
	}
}

/**
 * Activate required plugins in wp-env.
 */
function activatePlugins(): void {
	console.log('Checking plugins and WooCommerce setup...');

	// Check if plugins are active by checking plugin list
	const pluginList = wpCli('plugin list --status=active --field=name');

	const hasWooCommerce = pluginList.includes('woocommerce');
	const hasZBooks = pluginList.includes('zbooks-for-woocommerce');

	if (!hasWooCommerce || !hasZBooks) {
		// Activate plugins
		console.log('Activating plugins (WC: ' + hasWooCommerce + ', ZBooks: ' + hasZBooks + ')...');
		wpCli('plugin activate woocommerce zbooks-for-woocommerce');
		console.log('Plugins activated.');
	} else {
		console.log('Plugins already active.');
	}

	// Skip WooCommerce onboarding wizard (check first to avoid redundant writes)
	const onboarding = wpCli('option get woocommerce_onboarding_profile --format=json');
	if (!onboarding.includes('"skipped":true')) {
		console.log('Skipping WooCommerce onboarding...');
		wpCli('option update woocommerce_onboarding_profile \'{"skipped":true}\' --format=json');
		wpCli('option update woocommerce_task_list_hidden_lists \'["setup"]\' --format=json');
	} else {
		console.log('WooCommerce onboarding already skipped.');
	}
}

/**
 * Setup Zoho credentials from .env.local file or environment variables.
 */
async function setupZohoCredentials(): Promise<void> {
	// Check if already configured
	const orgId = wpCli('option get zbooks_organization_id');
	if (orgId && !orgId.includes('Does it exist') && /^\d+/.test(orgId.trim())) {
		console.log('Zoho credentials already configured.');
		return;
	}

	// Try to get credentials from environment variables first (CI)
	let clientId = process.env.ZOHO_CLIENT_ID;
	let clientSecret = process.env.ZOHO_CLIENT_SECRET;
	let refreshToken = process.env.ZOHO_REFRESH_TOKEN;
	let organizationId = process.env.ZOHO_ORGANIZATION_ID;
	let datacenter = process.env.ZOHO_DATACENTER || 'us';

	// If not in env vars, try .env.local file (local dev)
	if (!clientId || !clientSecret || !refreshToken) {
		const envFile = path.join(process.cwd(), '.env.local');

		if (!fs.existsSync(envFile)) {
			console.log('No .env.local file or environment variables found, skipping Zoho setup.');
			return;
		}

		console.log('Setting up Zoho credentials from .env.local...');

		// Read .env.local
		const envContent = fs.readFileSync(envFile, 'utf-8');
		const envVars: Record<string, string> = {};

		envContent.split('\n').forEach((line) => {
			const match = line.match(/^([A-Z_]+)=(.*)$/);
			if (match) {
				envVars[match[1]] = match[2];
			}
		});

		clientId = envVars['ZOHO_CLIENT_ID'];
		clientSecret = envVars['ZOHO_CLIENT_SECRET'];
		refreshToken = envVars['ZOHO_REFRESH_TOKEN'];
		organizationId = envVars['ZOHO_ORGANIZATION_ID'];
		datacenter = envVars['ZOHO_DATACENTER'] || 'us';
	} else {
		console.log('Setting up Zoho credentials from environment variables...');
	}

	if (!clientId || !clientSecret || !refreshToken) {
		console.log('Zoho credentials incomplete, skipping setup.');
		return;
	}

	// Run setup script with improved error handling
	try {
		console.log('Running Zoho credential setup script...');
		
		// Simplified command structure for better environment variable passing
		const setupCommand = `npx wp-env run tests-cli wp eval-file wp-content/plugins/zbooks-for-woocommerce/scripts/setup-zoho-credentials.php`;
		
		const result = execSync(setupCommand, {
			cwd: process.cwd(),
			encoding: 'utf-8',
			stdio: 'pipe',
			env: {
				...process.env,
				ZOHO_CLIENT_ID: clientId,
				ZOHO_CLIENT_SECRET: clientSecret,
				ZOHO_REFRESH_TOKEN: refreshToken,
				ZOHO_ORGANIZATION_ID: organizationId,
				ZOHO_DATACENTER: datacenter
			}
		});
		
		console.log('Setup script output:', result);

		// Wait for credentials to be saved
		await new Promise(resolve => setTimeout(resolve, 2000));

		// Verify setup with retry logic (3 attempts)
		let verifySuccess = false;
		for (let attempt = 1; attempt <= 3; attempt++) {
			console.log(`Verifying credentials (attempt ${attempt}/3)...`);
			
			try {
				const verifyOrgId = wpCli('option get zbooks_organization_id');
				if (
					verifyOrgId &&
					!verifyOrgId.includes('Does it exist') &&
					/^\d+/.test(verifyOrgId.trim())
				) {
					console.log('Zoho credentials configured successfully.');
					verifySuccess = true;
					break;
				}
			} catch (verifyError) {
				console.warn(`Verification attempt ${attempt} failed:`, verifyError);
			}
			
			if (attempt < 3) {
				await new Promise(resolve => setTimeout(resolve, 1000));
			}
		}
		
		if (!verifySuccess) {
			const errorMsg = 'Failed to verify Zoho credentials after setup';
			console.error(errorMsg);
			throw new Error(errorMsg);
		}
	} catch (error: unknown) {
		const err = error as { message?: string; stderr?: string; stdout?: string };
		console.error('Failed to setup Zoho credentials:');
		console.error('Error message:', err.message);
		if (err.stderr) {
			console.error('stderr:', err.stderr);
		}
		if (err.stdout) {
			console.error('stdout:', err.stdout);
		}
		throw error;
	}
}

async function globalSetup(config: FullConfig): Promise<void> {
	const baseURL = config.projects[0].use.baseURL || 'http://localhost:8889';

	console.log('Global setup: Preparing test environment...');
	console.log('Base URL: ' + baseURL);

	// Ensure wp-env is running before attempting any setup
	ensureWpEnvRunning();

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

	// Quick check if everything is already configured
	if (isEnvironmentConfigured()) {
		console.log('Environment already configured, skipping setup.');
		console.log('Global setup complete.');
		return;
	}

	// Activate plugins and setup Zoho
	activatePlugins();
	await setupZohoCredentials();

	console.log('Global setup complete.');
}

export default globalSetup;
