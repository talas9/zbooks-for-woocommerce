<?php
/**
 * Example unit test.
 *
 * @package ZBooks_For_WooCommerce
 */

namespace Zbooks\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Example test case.
 */
class ExampleTest extends TestCase {

	/**
	 * Test that true is true.
	 *
	 * @return void
	 */
	public function test_true_is_true(): void {
		$this->assertTrue( true );
	}

	/**
	 * Test plugin constants are defined.
	 *
	 * @return void
	 */
	public function test_plugin_version_format(): void {
		// Version should be in semver format.
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', '1.0.0' );
	}
}
