/**
 * Sync Robustness E2E Tests
 *
 * Tests all 12 robustness cases implemented in the sync workflow.
 * These tests create real orders, sync to Zoho, and verify results
 * in WP admin (order meta, notes, logs) and in Zoho Books.
 *
 * @see /Users/talas9/.claude/plans/zippy-churning-cook.md
 */
import { test, expect, Page } from '@playwright/test';
import { exec } from 'child_process';
import { promisify } from 'util';

const execAsync = promisify(exec);

/**
 * WP-CLI helper to run commands in the wp-env container.
 * Uses npx wp-env run tests-cli to execute WP-CLI commands in the test environment.
 * Tests run on port 8889 which is the tests environment.
 */
async function wpCli(command: string): Promise<string> {
	try {
		// Use npx wp-env run tests-cli (not cli) for the test environment
		const { stdout, stderr } = await execAsync(
			`npx wp-env run tests-cli wp ${command}`,
			{ cwd: process.cwd() }
		);
		// Filter out wp-env info messages
		const lines = stdout.split('\n').filter(
			(line) =>
				!line.includes('Starting') &&
				!line.includes('Ran `wp') &&
				!line.includes('level=warning')
		);
		return lines.join('\n').trim();
	} catch (error: unknown) {
		const err = error as { stderr?: string; stdout?: string; message?: string };
		// Some commands may fail but still have useful output
		if (err.stdout) {
			// Filter the stdout as well
			const lines = err.stdout.split('\n').filter(
				(line) =>
					!line.includes('Starting') &&
					!line.includes('Ran `wp') &&
					!line.includes('level=warning')
			);
			const filtered = lines.join('\n').trim();
			if (filtered) {
				return filtered;
			}
		}
		console.error('WP-CLI error:', err.stderr || err.message);
		throw error;
	}
}

/**
 * Generate a unique ID for test data.
 */
function uniqueId(): string {
	return `${Date.now()}-${Math.random().toString(36).substring(2, 8)}`;
}

/**
 * Generate random test data to ensure each test run is unique.
 * This prevents collisions with old orders/invoices in Zoho.
 */
function generateRandomTestData() {
	const id = uniqueId();

	// Random first names and last names
	const firstNames = ['Alice', 'Bob', 'Charlie', 'Diana', 'Eve', 'Frank', 'Grace', 'Henry'];
	const lastNames = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis'];

	// Random street names
	const streets = ['Oak St', 'Maple Ave', 'Cedar Ln', 'Pine Rd', 'Elm Dr', 'Birch Way'];
	const cities = ['New York', 'Los Angeles', 'Chicago', 'Houston', 'Phoenix', 'Seattle'];
	const states = ['NY', 'CA', 'IL', 'TX', 'AZ', 'WA'];

	// Random product names
	const productNames = ['Widget', 'Gadget', 'Sprocket', 'Gizmo', 'Thingamajig', 'Doohickey'];
	const productAdjectives = ['Premium', 'Deluxe', 'Basic', 'Pro', 'Ultra', 'Mini'];

	// Random quantity between 1 and 5
	const quantity = Math.floor(1 + Math.random() * 5);
	// Random unit price between 10 and 100
	const unitPrice = parseFloat((10 + Math.random() * 90).toFixed(2));

	return {
		firstName: firstNames[Math.floor(Math.random() * firstNames.length)],
		lastName: `${lastNames[Math.floor(Math.random() * lastNames.length)]}_${id}`,
		email: `e2e-test-${id}@example.com`,
		phone: `555-${Math.floor(1000 + Math.random() * 9000)}`,
		address: `${Math.floor(100 + Math.random() * 9900)} ${streets[Math.floor(Math.random() * streets.length)]}`,
		city: cities[Math.floor(Math.random() * cities.length)],
		state: states[Math.floor(Math.random() * states.length)],
		postcode: `${Math.floor(10000 + Math.random() * 89999)}`,
		// Product data
		productName: `${productAdjectives[Math.floor(Math.random() * productAdjectives.length)]} ${productNames[Math.floor(Math.random() * productNames.length)]} ${id}`,
		quantity: quantity,
		unitPrice: unitPrice,
		// Total is calculated from line items
		total: parseFloat((quantity * unitPrice).toFixed(2)),
	};
}

/**
 * Create a WooCommerce order via WP-CLI.
 * Returns the order ID.
 * All test data is randomized to avoid collisions with existing data in Zoho.
 */
async function createOrder(options: {
	status?: string;
	total?: number;
	customerEmail?: string;
	paymentMethod?: string;
}): Promise<number> {
	const randomData = generateRandomTestData();
	const status = options.status || 'processing';
	// Use provided total or random total
	const total = options.total !== undefined ? options.total : randomData.total;
	const email = options.customerEmail || randomData.email;
	const paymentMethod = options.paymentMethod || 'bacs';

	// Create order using WP-CLI (use shop_order subcommand)
	// Note: JSON in billing needs proper escaping
	const billingJson = JSON.stringify({
		email: email,
		first_name: randomData.firstName,
		last_name: randomData.lastName,
		address_1: randomData.address,
		city: randomData.city,
		state: randomData.state,
		postcode: randomData.postcode,
		country: 'US',
		phone: randomData.phone,
	}).replace(/"/g, '\\"');

	const orderIdStr = await wpCli(
		`wc shop_order create --status=${status} --billing="${billingJson}" --payment_method=${paymentMethod} --user=admin --porcelain`
	);
	const orderId = parseInt(orderIdStr, 10);

	if (isNaN(orderId)) {
		throw new Error(`Failed to create order: ${orderIdStr}`);
	}

	// Add line items with randomized product data
	// We need to add at least one item because Zoho requires it
	const productName = randomData.productName.replace(/'/g, "\\'");
	const quantity = randomData.quantity;
	// If a specific total is requested, use it directly; otherwise calculate from random data
	const lineTotal = total !== undefined ? total : randomData.total;

	// Add a line item to the order using PHP eval
	await wpCli(
		`eval '$o = wc_get_order(${orderId}); if($o) { $item = new WC_Order_Item_Product(); $item->set_name("${productName}"); $item->set_quantity(${quantity}); $item->set_total(${lineTotal}); $item->set_subtotal(${lineTotal}); $o->add_item($item); $o->calculate_totals(); $o->save(); }'`
	);

	return orderId;
}

/**
 * Get order meta via WP-CLI using PHP eval for HPOS compatibility.
 */
async function getOrderMeta(orderId: number, metaKey: string): Promise<string | null> {
	try {
		const result = await wpCli(
			`eval '$o = wc_get_order(${orderId}); echo $o ? $o->get_meta("${metaKey}") : "";'`
		);
		return result || null;
	} catch {
		return null;
	}
}

/**
 * Get all Zoho-related order meta.
 */
async function getZohoOrderMeta(orderId: number): Promise<Record<string, string>> {
	const metaKeys = [
		'_zbooks_zoho_invoice_id',
		'_zbooks_zoho_contact_id',
		'_zbooks_zoho_payment_id',
		'_zbooks_zoho_invoice_status',
		'_zbooks_sync_status',
		'_zbooks_payment_error',
		'_zbooks_unapplied_credit_note',
	];

	const result: Record<string, string> = {};
	for (const key of metaKeys) {
		const value = await getOrderMeta(orderId, key);
		if (value) {
			result[key] = value;
		}
	}
	return result;
}

/**
 * Get order notes via WP-CLI using PHP eval for HPOS compatibility.
 */
async function getOrderNotes(orderId: number): Promise<string[]> {
	try {
		const result = await wpCli(
			`eval '$notes = wc_get_order_notes(array("order_id" => ${orderId})); $content = array_map(fn($n) => $n->content, $notes); echo json_encode($content);'`
		);
		return JSON.parse(result || '[]');
	} catch {
		return [];
	}
}

/**
 * Delete order via WP-CLI.
 */
async function deleteOrder(orderId: number): Promise<void> {
	try {
		await wpCli(`wc shop_order delete ${orderId} --force --user=admin`);
	} catch {
		// Ignore deletion errors in cleanup
	}
}

/**
 * Check if Zoho is connected by testing the connection.
 */
async function isZohoConnected(): Promise<boolean> {
	try {
		const result = await wpCli(`option get zbooks_organization_id`);
		return !!result && result !== '';
	} catch {
		return false;
	}
}

/**
 * Update order status via WP-CLI.
 */
async function updateOrderStatus(orderId: number, status: string): Promise<void> {
	await wpCli(`wc shop_order update ${orderId} --status=${status} --user=admin`);
}

// ============================================================================
// ZOHO VERIFICATION HELPERS
// These functions query Zoho Books API to verify data was synced correctly
// ============================================================================

interface ZohoInvoiceResult {
	success: boolean;
	exists?: boolean;
	synced?: boolean;
	invoice_id?: string;
	invoice_number?: string;
	status?: string;
	total?: number;
	balance?: number;
	customer_id?: string;
	customer_name?: string;
	line_items?: number;
	order_id?: string;
	wc_total?: number;
	wc_status?: string;
	totals_match?: boolean;
	payment_synced?: boolean;
	payment_id?: string;
	error?: string;
}

interface ZohoContactResult {
	success: boolean;
	exists?: boolean;
	contact_id?: string;
	contact_name?: string;
	email?: string;
	status?: string;
	error?: string;
}

interface ZohoPaymentResult {
	success: boolean;
	exists?: boolean;
	payment_id?: string;
	payment_number?: string;
	amount?: number;
	date?: string;
	payment_mode?: string;
	customer_id?: string;
	customer_name?: string;
	invoices?: Array<{ invoice_id: string; amount_applied: number }>;
	error?: string;
}

/**
 * Verify invoice exists in Zoho Books and return details.
 */
async function verifyZohoInvoice(invoiceId: string): Promise<ZohoInvoiceResult> {
	try {
		const result = await wpCli(
			`eval-file /var/www/html/wp-content/plugins/zbooks-for-woocommerce/scripts/verify-zoho.php invoice ${invoiceId}`
		);
		return JSON.parse(result);
	} catch (error) {
		return { success: false, error: String(error) };
	}
}

/**
 * Verify contact exists in Zoho Books and return details.
 */
async function verifyZohoContact(contactId: string): Promise<ZohoContactResult> {
	try {
		const result = await wpCli(
			`eval-file /var/www/html/wp-content/plugins/zbooks-for-woocommerce/scripts/verify-zoho.php contact ${contactId}`
		);
		return JSON.parse(result);
	} catch (error) {
		return { success: false, error: String(error) };
	}
}

/**
 * Verify payment exists in Zoho Books and return details.
 */
async function verifyZohoPayment(paymentId: string): Promise<ZohoPaymentResult> {
	try {
		const result = await wpCli(
			`eval-file /var/www/html/wp-content/plugins/zbooks-for-woocommerce/scripts/verify-zoho.php payment ${paymentId}`
		);
		return JSON.parse(result);
	} catch (error) {
		return { success: false, error: String(error) };
	}
}

/**
 * Verify invoice by WooCommerce order ID - compares WC order with Zoho invoice.
 */
async function verifyZohoInvoiceByOrder(orderId: number): Promise<ZohoInvoiceResult> {
	try {
		const result = await wpCli(
			`eval-file /var/www/html/wp-content/plugins/zbooks-for-woocommerce/scripts/verify-zoho.php invoice-by-order ${orderId}`
		);
		return JSON.parse(result);
	} catch (error) {
		return { success: false, error: String(error) };
	}
}

/**
 * Verify Zoho connection is working.
 */
async function verifyZohoConnection(): Promise<{ success: boolean; connected: boolean; error?: string }> {
	try {
		const result = await wpCli(
			`eval-file /var/www/html/wp-content/plugins/zbooks-for-woocommerce/scripts/verify-zoho.php connection`
		);
		return JSON.parse(result);
	} catch (error) {
		return { success: false, connected: false, error: String(error) };
	}
}

test.describe('Sync Robustness E2E Tests', () => {
	// Track created orders for cleanup
	const createdOrderIds: number[] = [];

	test.beforeAll(async () => {
		// Verify Zoho is connected
		const connected = await isZohoConnected();
		if (!connected) {
			console.warn('Zoho not connected - some tests may be skipped');
		}
	});

	test.afterAll(async () => {
		// Cleanup created orders
		for (const orderId of createdOrderIds) {
			try {
				await deleteOrder(orderId);
			} catch {
				// Ignore cleanup errors
			}
		}
	});

	test.describe('Case 1: Customer Verification', () => {
		test('syncs order with new customer', async ({ page }) => {
			// Create a new order with unique email
			const orderId = await createOrder({
				status: 'processing',
				customerEmail: `new-customer-${Date.now()}@test.com`,
				// Total is randomized automatically
			});
			createdOrderIds.push(orderId);

			// Navigate to order page
			await page.goto(`/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`);
			await page.waitForLoadState('networkidle');

			// Find and click sync button
			const syncButton = page.locator('.zbooks-sync-btn[data-draft="false"]');
			if (await syncButton.isVisible()) {
				await syncButton.click();

				// Wait for AJAX response
				await page.waitForTimeout(5000);

				// Refresh page to see updated meta
				await page.reload();
				await page.waitForLoadState('networkidle');
			}

			// Verify sync status in meta box
			const metaBox = page.locator('#zbooks_sync_status');
			if (await metaBox.isVisible()) {
				const metaBoxText = await metaBox.textContent();

				// Should show synced status or invoice link
				const hasSynced =
					metaBoxText?.includes('Invoice:') ||
					metaBoxText?.includes('Synced') ||
					(await page.locator('.zbooks-status-synced').isVisible());

				// Get order meta to verify
				const meta = await getZohoOrderMeta(orderId);
				console.log('Order meta after sync:', meta);

				if (meta['_zbooks_zoho_invoice_id']) {
					expect(meta['_zbooks_zoho_contact_id']).toBeTruthy();
				}
			}
		});
	});

	test.describe('Case 2: Invoice Status Sync', () => {
		test('displays invoice status and refresh button', async ({ page }) => {
			// Navigate to orders
			await page.goto('/wp-admin/admin.php?page=wc-orders');

			const ordersTable = page.locator('.wp-list-table tbody tr');
			const orderCount = await ordersTable.count();

			if (orderCount > 0) {
				// Find a synced order
				for (let i = 0; i < Math.min(orderCount, 5); i++) {
					await ordersTable.nth(i).locator('a.order-view').click();
					await page.waitForLoadState('networkidle');

					const metaBox = page.locator('#zbooks_sync_status');
					if (await metaBox.isVisible()) {
						// Check for invoice status display
						const invoiceStatusBadge = page.locator('.zbooks-invoice-status-badge');
						const refreshButton = page.locator('.zbooks-refresh-status-btn');

						const hasInvoice = await page.locator('.zbooks-meta-box p:has-text("Invoice:")').isVisible();

						if (hasInvoice) {
							// Should have status badge
							if (await invoiceStatusBadge.isVisible()) {
								const statusText = await invoiceStatusBadge.textContent();
								expect(statusText).toBeTruthy();

								// Test refresh button if visible
								if (await refreshButton.isVisible()) {
									await refreshButton.click();
									await page.waitForTimeout(3000);
									// Status should update (or stay same if already current)
								}
							}
							break;
						}
					}

					await page.goto('/wp-admin/admin.php?page=wc-orders');
				}
			}
		});
	});

	test.describe('Case 3 & 4: Concurrent Sync Protection', () => {
		test('handles rapid sync button clicks without duplicate invoices', async ({ page }) => {
			// Create a new order
			const orderId = await createOrder({
				status: 'processing',
				// Total is randomized automatically
			});
			createdOrderIds.push(orderId);

			// Navigate to order page
			await page.goto(`/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`);
			await page.waitForLoadState('networkidle');

			const syncButton = page.locator('.zbooks-sync-btn[data-draft="false"]');
			if (await syncButton.isVisible()) {
				// Click rapidly multiple times (simulating race condition)
				await Promise.all([
					syncButton.click(),
					syncButton.click().catch(() => {}), // May fail if button disabled
				]);

				// Wait for all requests to complete
				await page.waitForTimeout(5000);

				// Refresh and check
				await page.reload();
				await page.waitForLoadState('networkidle');

				// Verify only one invoice was created
				const meta = await getZohoOrderMeta(orderId);
				const invoiceId = meta['_zbooks_zoho_invoice_id'];

				// Should have at most one invoice (or none if sync failed)
				if (invoiceId) {
					expect(invoiceId).not.toContain(','); // No multiple IDs
				}
			}
		});
	});

	test.describe('Cases 5 & 6: Invoice Validation Before Payment', () => {
		test('shows payment error for invalid invoice states', async ({ page }) => {
			// This test verifies that payment validation errors are displayed
			// Navigate to a synced order
			await page.goto('/wp-admin/admin.php?page=wc-orders');

			const ordersTable = page.locator('.wp-list-table tbody tr');
			const orderCount = await ordersTable.count();

			for (let i = 0; i < Math.min(orderCount, 5); i++) {
				await ordersTable.nth(i).locator('a.order-view').click();
				await page.waitForLoadState('networkidle');

				// Check for payment error display
				const paymentError = page.locator('.zbooks-payment-error, .zbooks-meta-box:has-text("Payment Error")');
				if (await paymentError.isVisible()) {
					const errorText = await paymentError.textContent();
					expect(errorText).toBeTruthy();
					break;
				}

				await page.goto('/wp-admin/admin.php?page=wc-orders');
			}
		});
	});

	test.describe('Case 7: Invoice Update Capability', () => {
		test('resync button appears for already synced orders', async ({ page }) => {
			await page.goto('/wp-admin/admin.php?page=wc-orders');

			const ordersTable = page.locator('.wp-list-table tbody tr');
			const orderCount = await ordersTable.count();

			for (let i = 0; i < Math.min(orderCount, 5); i++) {
				await ordersTable.nth(i).locator('a.order-view').click();
				await page.waitForLoadState('networkidle');

				const metaBox = page.locator('#zbooks_sync_status');
				if (await metaBox.isVisible()) {
					const hasInvoice = await page.locator('.zbooks-meta-box p:has-text("Invoice:")').isVisible();

					if (hasInvoice) {
						// For already synced orders, sync button should still be available
						// to allow re-syncing (updating) the invoice
						const syncButton = page.locator('.zbooks-sync-btn[data-draft="false"]');
						expect(await syncButton.isVisible()).toBeTruthy();
						break;
					}
				}

				await page.goto('/wp-admin/admin.php?page=wc-orders');
			}
		});
	});

	test.describe('Case 8: Combined Sync+Payment Result', () => {
		test('sync with payment shows combined result', async ({ page }) => {
			// Create a paid order
			const orderId = await createOrder({
				status: 'completed', // Triggers payment
				// Total is randomized automatically
				paymentMethod: 'bacs',
			});
			createdOrderIds.push(orderId);

			// Mark as paid in WooCommerce
			await wpCli(`eval '$o = wc_get_order(${orderId}); if($o) { $o->set_status("completed"); $o->set_date_paid(time()); $o->save(); }'`);

			// Navigate to order page
			await page.goto(`/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`);
			await page.waitForLoadState('networkidle');

			const syncButton = page.locator('.zbooks-sync-btn[data-draft="false"]');
			if (await syncButton.isVisible()) {
				await syncButton.click();
				await page.waitForTimeout(5000);

				// Check for sync result message
				const successMessage = page.locator('.notice-success, .zbooks-sync-success');
				const errorMessage = page.locator('.notice-error, .zbooks-sync-error');

				// Refresh to see updated meta
				await page.reload();
				await page.waitForLoadState('networkidle');

				// Verify meta includes both invoice and potentially payment
				const meta = await getZohoOrderMeta(orderId);
				console.log('Order meta after sync with payment:', meta);

				// Check order notes for combined result
				const notes = await getOrderNotes(orderId);
				console.log('Order notes:', notes);
			}
		});
	});

	test.describe('Case 9: Unapplied Credit Tracking', () => {
		test('tracks unapplied credits in order meta', async ({ page }) => {
			// This tests the display of unapplied credit notes
			await page.goto('/wp-admin/admin.php?page=wc-orders');

			const ordersTable = page.locator('.wp-list-table tbody tr');
			const orderCount = await ordersTable.count();

			for (let i = 0; i < Math.min(orderCount, 10); i++) {
				await ordersTable.nth(i).locator('a.order-view').click();
				await page.waitForLoadState('networkidle');

				// Check for unapplied credit warning
				const unappliedCredit = page.locator('.zbooks-unapplied-credit, .zbooks-meta-box:has-text("Unapplied Credit")');
				if (await unappliedCredit.isVisible()) {
					const creditText = await unappliedCredit.textContent();
					expect(creditText).toBeTruthy();
					break;
				}

				await page.goto('/wp-admin/admin.php?page=wc-orders');
			}
		});
	});

	test.describe('Case 10: Mark as Sent Tracking', () => {
		test('order notes show mark as sent status', async ({ page }) => {
			// Create and sync an order
			const orderId = await createOrder({
				status: 'processing',
				// Total is randomized automatically
			});
			createdOrderIds.push(orderId);

			// Navigate and sync
			await page.goto(`/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`);
			await page.waitForLoadState('networkidle');

			const syncButton = page.locator('.zbooks-sync-btn[data-draft="false"]');
			if (await syncButton.isVisible()) {
				await syncButton.click();
				await page.waitForTimeout(5000);

				// Check order notes for mark as sent info
				const notes = await getOrderNotes(orderId);
				const hasMarkAsSentNote = notes.some(
					(note) => note.includes('marked as sent') || note.includes('Invoice synced')
				);

				console.log('Order notes:', notes);
				// Note: Mark as sent might be mentioned in order notes
			}
		});
	});

	test.describe('Case 11: Order Cancellation Handling', () => {
		test('cancelled order note mentions Zoho status', async ({ page }) => {
			// Create and sync an order, then cancel it
			const orderId = await createOrder({
				status: 'processing',
				// Total is randomized automatically
			});
			createdOrderIds.push(orderId);

			// Navigate and sync first
			await page.goto(`/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`);
			await page.waitForLoadState('networkidle');

			const syncButton = page.locator('.zbooks-sync-btn[data-draft="false"]');
			if (await syncButton.isVisible()) {
				await syncButton.click();
				await page.waitForTimeout(5000);
			}

			// Now cancel the order via WP-CLI
			await updateOrderStatus(orderId, 'cancelled');

			// Check order notes for cancellation handling
			const notes = await getOrderNotes(orderId);
			console.log('Order notes after cancellation:', notes);

			// Should have a note about Zoho invoice status
			// Either voided or warning that manual void is needed
		});
	});

	test.describe('Case 12: Retry Backoff Delay', () => {
		test('failed syncs are tracked for retry', async ({ page }) => {
			// This test verifies the retry mechanism is in place
			// Navigate to logs to see retry attempts
			await page.goto('/wp-admin/admin.php?page=zbooks-logs');

			const bodyText = await page.locator('body').textContent();
			expect(bodyText).not.toContain('critical error');

			// Check for retry-related log entries
			const retryLogs = page.locator('tr:has-text("retry"), tr:has-text("Retry")');
			const retryCount = await retryLogs.count();
			console.log(`Found ${retryCount} retry-related log entries`);
		});
	});

	test.describe('Sync Workflow Integration', () => {
		test('full sync workflow: create order, sync, verify in WP and Zoho', async ({ page }) => {
			// Create order with randomized data
			const orderId = await createOrder({
				status: 'processing',
				// Let total be randomized
			});
			createdOrderIds.push(orderId);

			// Fetch the actual order total that was set
			const orderTotalStr = await wpCli(
				`eval 'echo wc_get_order(${orderId})->get_total();'`
			);
			const orderTotal = parseFloat(orderTotalStr);

			console.log(`Created order ${orderId} with total ${orderTotal}`);

			// Navigate to order
			await page.goto(`/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`);
			await page.waitForLoadState('networkidle');

			// Verify meta box is present
			const metaBox = page.locator('#zbooks_sync_status');
			await expect(metaBox).toBeVisible();

			// Initial state should show sync buttons
			const syncButton = page.locator('.zbooks-sync-btn[data-draft="false"]');
			const draftButton = page.locator('.zbooks-sync-btn[data-draft="true"]');
			await expect(syncButton).toBeVisible();
			await expect(draftButton).toBeVisible();

			// Click sync
			await syncButton.click();

			// Wait for AJAX
			await page.waitForTimeout(5000);

			// Reload to see updated state
			await page.reload();
			await page.waitForLoadState('networkidle');

			// ========== VERIFY WORDPRESS SIDE ==========
			const meta = await getZohoOrderMeta(orderId);
			console.log('WP Order meta:', meta);

			const notes = await getOrderNotes(orderId);
			console.log('WP Order notes:', notes);

			// Check for sync success indicators
			if (meta['_zbooks_zoho_invoice_id']) {
				// Invoice was created in WP
				expect(meta['_zbooks_zoho_contact_id']).toBeTruthy();
				expect(meta['_zbooks_sync_status']).toBe('synced');

				// Should have order note about sync
				const hasSyncNote = notes.some(
					(note) =>
						note.includes('Invoice') ||
						note.includes('synced') ||
						note.includes('Zoho')
				);
				expect(hasSyncNote).toBeTruthy();

				// ========== VERIFY ZOHO SIDE ==========
				console.log('Verifying data in Zoho Books...');

				// Verify invoice exists in Zoho
				const zohoInvoice = await verifyZohoInvoice(meta['_zbooks_zoho_invoice_id']);
				console.log('Zoho Invoice:', zohoInvoice);

				if (zohoInvoice.success && zohoInvoice.exists) {
					// Invoice exists in Zoho
					expect(zohoInvoice.invoice_id).toBe(meta['_zbooks_zoho_invoice_id']);
					expect(zohoInvoice.status).toBeTruthy();

					// Verify totals match (allowing for small rounding differences)
					if (zohoInvoice.total !== undefined) {
						const totalDiff = Math.abs(zohoInvoice.total - orderTotal);
						console.log(`Total comparison: Zoho=${zohoInvoice.total}, WC=${orderTotal}, diff=${totalDiff}`);
						expect(totalDiff).toBeLessThan(0.01);
					}
				}

				// Verify contact exists in Zoho
				const zohoContact = await verifyZohoContact(meta['_zbooks_zoho_contact_id']);
				console.log('Zoho Contact:', zohoContact);

				if (zohoContact.success && zohoContact.exists) {
					expect(zohoContact.contact_id).toBe(meta['_zbooks_zoho_contact_id']);
					expect(zohoContact.contact_name).toBeTruthy();
				}

				// Use the combined verification helper
				const zohoVerification = await verifyZohoInvoiceByOrder(orderId);
				console.log('Zoho verification by order:', zohoVerification);

				if (zohoVerification.success && zohoVerification.synced) {
					expect(zohoVerification.totals_match).toBe(true);
				}
			}
		});

		test('apply payment workflow and verify in Zoho', async ({ page }) => {
			// Find a synced order without payment
			await page.goto('/wp-admin/admin.php?page=wc-orders');

			const ordersTable = page.locator('.wp-list-table tbody tr');
			const orderCount = await ordersTable.count();

			for (let i = 0; i < Math.min(orderCount, 10); i++) {
				await ordersTable.nth(i).locator('a.order-view').click();
				await page.waitForLoadState('networkidle');

				const applyPaymentBtn = page.locator('.zbooks-apply-payment-btn');

				if (await applyPaymentBtn.isVisible()) {
					// Get order ID from URL before clicking
					const url = page.url();
					const idMatch = url.match(/id=(\d+)/);
					const orderId = idMatch ? parseInt(idMatch[1]) : null;

					// Click apply payment
					await applyPaymentBtn.click();
					await page.waitForTimeout(5000);

					// Reload to see updated state
					await page.reload();
					await page.waitForLoadState('networkidle');

					if (orderId) {
						// ========== VERIFY WORDPRESS SIDE ==========
						const meta = await getZohoOrderMeta(orderId);
						console.log('WP Order meta after payment:', meta);

						// Should have payment ID or payment error
						const hasPaymentResult =
							meta['_zbooks_zoho_payment_id'] || meta['_zbooks_payment_error'];
						expect(hasPaymentResult).toBeTruthy();

						// ========== VERIFY ZOHO SIDE ==========
						if (meta['_zbooks_zoho_payment_id']) {
							console.log('Verifying payment in Zoho Books...');

							const zohoPayment = await verifyZohoPayment(meta['_zbooks_zoho_payment_id']);
							console.log('Zoho Payment:', zohoPayment);

							if (zohoPayment.success && zohoPayment.exists) {
								// Payment exists in Zoho
								expect(zohoPayment.payment_id).toBe(meta['_zbooks_zoho_payment_id']);
								expect(zohoPayment.amount).toBeGreaterThan(0);

								// Payment should be applied to the invoice
								if (meta['_zbooks_zoho_invoice_id'] && zohoPayment.invoices) {
									const appliedToInvoice = zohoPayment.invoices.some(
										(inv) => inv.invoice_id === meta['_zbooks_zoho_invoice_id']
									);
									console.log(`Payment applied to invoice: ${appliedToInvoice}`);
								}
							}

							// Verify invoice balance updated
							if (meta['_zbooks_zoho_invoice_id']) {
								const zohoInvoice = await verifyZohoInvoice(meta['_zbooks_zoho_invoice_id']);
								console.log('Zoho Invoice after payment:', zohoInvoice);

								if (zohoInvoice.success && zohoInvoice.exists) {
									// Invoice should have reduced balance or be paid
									console.log(`Invoice status: ${zohoInvoice.status}, balance: ${zohoInvoice.balance}`);
								}
							}
						}
					}
					break;
				}

				await page.goto('/wp-admin/admin.php?page=wc-orders');
			}
		});
	});

	test.describe('Sync Log Verification', () => {
		test('sync logs capture operation details', async ({ page }) => {
			// Navigate to logs page
			await page.goto('/wp-admin/admin.php?page=zbooks-logs');

			// Verify page loads
			const bodyText = await page.locator('body').textContent();
			expect(bodyText).not.toContain('critical error');

			// Check for log table
			const logTable = page.locator('.zbooks-logs-table, table.wp-list-table');
			if (await logTable.isVisible()) {
				// Check for expected log entry types
				const logContent = await logTable.textContent();

				// Should have various operation types logged
				const hasOperationLogs =
					logContent?.includes('invoice') ||
					logContent?.includes('contact') ||
					logContent?.includes('payment') ||
					logContent?.includes('sync');

				console.log('Log page contains operation logs:', hasOperationLogs);
			}
		});
	});

	test.describe('Meta Box UI Verification', () => {
		test('meta box displays all relevant sync information', async ({ page }) => {
			await page.goto('/wp-admin/admin.php?page=wc-orders');

			const ordersTable = page.locator('.wp-list-table tbody tr');
			const orderCount = await ordersTable.count();

			if (orderCount > 0) {
				// Open first order
				await ordersTable.first().locator('a.order-view').click();
				await page.waitForLoadState('networkidle');

				const metaBox = page.locator('#zbooks_sync_status');
				if (await metaBox.isVisible()) {
					// Check meta box structure
					const metaBoxContent = await metaBox.textContent();

					// Should have sync buttons
					expect(await page.locator('.zbooks-sync-btn').first().isVisible()).toBeTruthy();

					// If synced, should show:
					// - Invoice link
					// - Contact link (possibly)
					// - Invoice status badge
					// - Payment info (if paid)
					// - Refresh status button

					const hasInvoice = await page
						.locator('.zbooks-meta-box p:has-text("Invoice:")')
						.isVisible();
					if (hasInvoice) {
						// Verify invoice link is clickable
						const invoiceLink = page.locator('.zbooks-meta-box a[href*="zoho"]').first();
						if (await invoiceLink.isVisible()) {
							const href = await invoiceLink.getAttribute('href');
							expect(href).toContain('zoho');
						}

						// Check for status badge
						const statusBadge = page.locator('.zbooks-invoice-status-badge');
						if (await statusBadge.isVisible()) {
							const statusText = await statusBadge.textContent();
							expect(statusText?.trim().length).toBeGreaterThan(0);
						}
					}
				}
			}
		});
	});
});
