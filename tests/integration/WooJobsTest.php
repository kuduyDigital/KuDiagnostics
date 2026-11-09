<?php
/**
 * Phase 7 WooCommerce bulk work (plan step 6, decision D9): kuduy-job-start runs the WooCommerce product, variation,
 * stock and coupon update tools over many items, each item checked and snapshotted like a single call; tools that
 * reach customers (order status, notes) are not allowed as jobs. WP-Cron does not run inside the test, so the
 * scheduled hook is called directly. Runs only when WooCommerce is active.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Jobs\Jobs;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class WooJobsTest extends TestCase {

	use CallsTools;

	private static int $manager;

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'WC_VERSION' ) ) {
			self::markTestSkipped( 'WooCommerce is not active in this run (KUDUY_PLUGINS).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$manager = self::make_user( 'shop_manager' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$manager );
	}

	/**
	 * A simple product.
	 *
	 * @param bool $stock Manage stock (10 in stock).
	 */
	private static function product( bool $stock = false ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Kuduy bulk item' );
		$product->set_regular_price( '20' );
		$product->set_status( 'publish' );
		if ( $stock ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( 10 );
		}
		return $product->save();
	}

	/**
	 * Run a job as WP-Cron would and return its status.
	 *
	 * @param string            $tool  Tool.
	 * @param list<array<mixed>> $items Items.
	 * @return array<string, mixed>
	 */
	private function run_job( string $tool, array $items ): array {
		$started = $this->ok(
			'kuduy-job-start',
			array(
				'tool'  => $tool,
				'items' => $items,
			)
		);
		wp_set_current_user( 0 );
		Jobs::run( $started['id'] ); // The hook WP-Cron fires.
		wp_set_current_user( self::$manager );
		return $this->ok( 'kuduy-job-status', array( 'id' => $started['id'] ) );
	}

	public function test_bulk_sale_prices_with_per_item_errors_and_undo(): void {
		$a      = self::product();
		$b      = self::product();
		$status = $this->run_job(
			'kuduy-woo-product-update',
			array(
				array(
					'id'         => $a,
					'sale_price' => '15',
				),
				array(
					'id'         => 999999,
					'sale_price' => '15',
				),
				array(
					'id'         => $b,
					'sale_price' => '16',
				),
			)
		);
		$this->assertSame( 'done', $status['status'] );
		$this->assertSame( 3, $status['done'] );
		$this->assertSame( 1, $status['failed'] );
		$this->assertFalse( $status['results'][1]['ok'], 'missing product' );
		$this->assertSame( '15', wc_get_product( $a )->get_sale_price() );
		$this->assertSame( '16', wc_get_product( $b )->get_sale_price() );
		$this->assertSame( array( $a, 999999, $b ), array_column( $status['results'], 'object_id' ) );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $status['results'][0]['snapshot_id'] ) );
		$this->assertSame( '', wc_get_product( $a )->get_sale_price() );
		$this->assertSame( '16', wc_get_product( $b )->get_sale_price(), 'Undo is per item.' );
	}

	public function test_bulk_stock_changes(): void {
		$a      = self::product( true );
		$b      = self::product( true );
		$status = $this->run_job(
			'kuduy-woo-product-stock',
			array(
				array(
					'id'        => $a,
					'operation' => 'decrease',
					'quantity'  => 4,
				),
				array(
					'id'        => $b,
					'operation' => 'set',
					'quantity'  => 2,
				),
			)
		);
		$this->assertSame( 0, $status['failed'] );
		$this->assertSame( 6, wc_get_product( $a )->get_stock_quantity() );
		$this->assertSame( 2, wc_get_product( $b )->get_stock_quantity() );
	}

	public function test_tools_that_reach_customers_cannot_run_as_jobs(): void {
		foreach ( array( 'kuduy-woo-order-status', 'kuduy-woo-order-note', 'kuduy-woo-product-trash' ) as $tool ) {
			$this->fails(
				'kuduy-job-start',
				array(
					'tool'  => $tool,
					'items' => array( array( 'id' => 1 ) ),
				),
				$tool . ' as a job'
			);
		}
	}
}
