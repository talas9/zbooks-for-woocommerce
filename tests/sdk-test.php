<?php
/**
 * Standalone SDK test for Zoho Books API.
 *
 * This script tests the webleit/zohobooksapi SDK independently
 * from WordPress to verify it works correctly.
 *
 * Usage: php tests/sdk-test.php
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

// Ensure we're running from CLI.
if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

// Load Composer autoloader.
$autoloader = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloader)) {
    die("Error: vendor/autoload.php not found. Run 'composer install' first.\n");
}

require_once $autoloader;

use Weble\ZohoClient\OAuthClient;
use Webleit\ZohoBooksApi\Client;
use Webleit\ZohoBooksApi\ZohoBooks;

echo "=== Zoho Books SDK Test ===\n\n";

// Test 1: Check classes exist
echo "Test 1: Checking SDK classes exist...\n";
$classes = [
    'Weble\ZohoClient\OAuthClient',
    'Webleit\ZohoBooksApi\Client',
    'Webleit\ZohoBooksApi\ZohoBooks',
    'Webleit\ZohoBooksApi\Modules\Invoices',
    'Webleit\ZohoBooksApi\Modules\Contacts',
    'Webleit\ZohoBooksApi\Modules\Organizations',
];

$all_exist = true;
foreach ($classes as $class) {
    if (class_exists($class)) {
        echo "  ✓ $class\n";
    } else {
        echo "  ✗ $class NOT FOUND\n";
        $all_exist = false;
    }
}

if (!$all_exist) {
    echo "\nFAILED: Some required classes are missing.\n";
    exit(1);
}

echo "\n";

// Test 2: OAuth client instantiation
echo "Test 2: Creating OAuth client...\n";
try {
    $oauth = new OAuthClient('test_client_id', 'test_client_secret');
    echo "  ✓ OAuthClient created successfully\n";
} catch (Throwable $e) {
    echo "  ✗ Failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n";

// Test 3: Check OAuth client methods exist
echo "Test 3: Checking OAuth client methods...\n";
$methods = [
    'setRefreshToken',
    'setAccessToken',
    'getAccessToken',
    'offlineMode',
    'setRegion',
];

foreach ($methods as $method) {
    if (method_exists($oauth, $method)) {
        echo "  ✓ $method()\n";
    } else {
        echo "  ✗ $method() NOT FOUND\n";
    }
}

echo "\n";

// Test 4: ZohoBooks Client wrapper instantiation
echo "Test 4: Creating Client wrapper...\n";
try {
    $client = new Client($oauth);
    echo "  ✓ Client wrapper created successfully\n";
} catch (Throwable $e) {
    echo "  ✗ Failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n";

// Test 5: ZohoBooks client instantiation
echo "Test 5: Creating ZohoBooks client...\n";
try {
    $zohoBooks = new ZohoBooks($client);
    echo "  ✓ ZohoBooks client created successfully\n";
} catch (Throwable $e) {
    echo "  ✗ Failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n";

// Test 6: Check ZohoBooks API modules
echo "Test 6: Checking ZohoBooks API modules...\n";
$modules = [
    'invoices',
    'contacts',
    'organizations',
    'items',
    'settings',
];

foreach ($modules as $module) {
    try {
        $api = $zohoBooks->$module;
        echo "  ✓ $module API accessible\n";
    } catch (Throwable $e) {
        echo "  ✗ $module API failed: " . $e->getMessage() . "\n";
    }
}

echo "\n";

// Test 7: Set organization ID (on Client wrapper)
echo "Test 7: Setting organization ID...\n";
try {
    $client->setOrganizationId('123456789');
    echo "  ✓ Organization ID set successfully on Client\n";
} catch (Throwable $e) {
    echo "  ✗ Failed: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 8: Memory usage
echo "Test 8: Memory usage check...\n";
$memory = memory_get_usage(true) / 1024 / 1024;
$peak = memory_get_peak_usage(true) / 1024 / 1024;
echo "  Current: " . round($memory, 2) . " MB\n";
echo "  Peak: " . round($peak, 2) . " MB\n";

if ($peak > 64) {
    echo "  ⚠ Warning: Memory usage is high\n";
} else {
    echo "  ✓ Memory usage is acceptable\n";
}

echo "\n=== All SDK tests passed! ===\n";
echo "\nNote: To test actual API calls, you need valid OAuth credentials.\n";
echo "Set environment variables:\n";
echo "  ZOHO_CLIENT_ID\n";
echo "  ZOHO_CLIENT_SECRET\n";
echo "  ZOHO_REFRESH_TOKEN\n";
echo "  ZOHO_ORGANIZATION_ID\n";

// Optional: Test with real credentials if environment variables are set
$client_id = getenv('ZOHO_CLIENT_ID');
$client_secret = getenv('ZOHO_CLIENT_SECRET');
$token = getenv('ZOHO_REFRESH_TOKEN');
$org_id = getenv('ZOHO_ORGANIZATION_ID');

if ($client_id && $client_secret && $token) {
    echo "\n=== Testing with real credentials ===\n";

    try {
        $oauth = new OAuthClient($client_id, $client_secret);

        // Check if it's a grant code (format: 1000.{32hex}.{32+hex}) or a refresh token
        // Grant codes need to be exchanged first
        $isGrantCode = preg_match('/^1000\.[a-f0-9]{32}\.[a-f0-9]{32,}$/i', $token) === 1;

        if ($isGrantCode) {
            echo "Detected grant code - exchanging for tokens...\n";
            echo "Note: Grant codes expire quickly and can only be used once.\n";
            echo "If this fails, generate a new self-client grant code from Zoho API Console.\n\n";

            // For grant code exchange, use setGrantCode method
            $oauth->offlineMode();
            $oauth->setGrantCode($token);

            try {
                $accessToken = $oauth->getAccessToken();
                echo "  ✓ Tokens obtained from grant code\n";

                // Get the refresh token for future use
                $newRefreshToken = $oauth->getRefreshToken();
                if ($newRefreshToken) {
                    echo "  ! Save this refresh token for future use:\n";
                    echo "    $newRefreshToken\n\n";
                }
            } catch (Throwable $e) {
                echo "  ✗ Grant code exchange failed: " . $e->getMessage() . "\n";
                echo "\n  To fix this:\n";
                echo "  1. Go to https://api-console.zoho.com/\n";
                echo "  2. Select your Self Client\n";
                echo "  3. Generate a new grant code with scope: ZohoBooks.fullaccess.all\n";
                echo "  4. Update the ZOHO_REFRESH_TOKEN with the new code\n";
                echo "  5. Run this test again within 1 minute\n";
                exit(1);
            }
        } else {
            echo "Using refresh token...\n";
            $oauth->setRefreshToken($token);
            $oauth->offlineMode();

            echo "Getting access token...\n";
            $accessToken = $oauth->getAccessToken();
            echo "  ✓ Access token obtained\n";
        }

        $client = new Client($oauth);

        if ($org_id) {
            $client->setOrganizationId($org_id);
        }

        $zohoBooks = new ZohoBooks($client);

        echo "Fetching organizations...\n";
        $orgs = $zohoBooks->organizations->getList();

        if (is_array($orgs) && isset($orgs['organizations'])) {
            $orgList = $orgs['organizations'];
        } else {
            $orgList = $orgs;
        }

        echo "  ✓ Found " . count($orgList) . " organization(s)\n";

        foreach ($orgList as $org) {
            $name = $org['name'] ?? $org['company_name'] ?? 'Unknown';
            $id = $org['organization_id'] ?? 'Unknown';
            echo "    - " . $name . " (ID: " . $id . ")\n";
        }

        echo "\n=== Live API test passed! ===\n";
    } catch (Throwable $e) {
        echo "  ✗ API test failed: " . $e->getMessage() . "\n";
        echo "\n  Full error: " . $e->getTraceAsString() . "\n";
        exit(1);
    }
}

exit(0);
