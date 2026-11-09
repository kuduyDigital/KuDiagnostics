<?php
/**
 * Phase 7 WooCommerce reports (test matrix W14): kuduy-woo-report numbers match the fixture orders once WooCommerce's
 * analytics import has run, customer data in reports is masked, and reports need view_woocommerce_reports.
 * Runs only when WooCommerce is active.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class WooReportsTest extends TestCase {

	use CallsTools;

	private const EMAIL = 'report.buyer@example.test';

	private static int $manager;
	private static int $author;

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'WC_VERSION' ) ) {
			self::markTestSkipped( 'WooCommerce is not active in this run (KUDUY_PLUGINS).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$manager = self::make_user( 'shop_manager' );
		self::$author  = self::make_user( 'author' );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Kuduy report item' );
		$product->set_regular_price( '25' );
		$product->set_status( 'publish' );
		$product->save();
		foreach ( array( 1, 3 ) as $qty ) {
			$order = wc_create_order();
			$order->add_product( $product, $qty );
			$order->set_billing_first_name( 'Rita' );
			$order->set_billing_last_name( 'Report' );
			$order->set_billing_email( self::EMAIL );
			$order->set_billing_city( 'Hamburg' );
			$order->set_billing_country( 'DE' );
			$order->calculate_totals();
			$order->set_status( 'completed' );
			$order->save();
			// WooCommerce imports orders into its analytics tables in the background; do it now (test fixture).
			OrdersScheduler::import( $order->get_id() );
		}
	}

	protected function setUp(): void {
		wp_set_current_user( self::$manager );
	}

	/**
	 * Report for today.
	 *
	 * @param string $report Report.
	 * @return array<string, mixed>
	 */
	private function today( string $report ): array {
		$today = current_time( 'Y-m-d' );
		return $this->ok(
			'kuduy-woo-report',
			array(
				'report'   => $report,
				'after'    => $today,
				'before'   => $today,
				'interval' => 'day',
			)
		);
	}

	public function test_revenue_matches_the_orders(): void {
		$out = $this->today( 'revenue' );
		$this->assertSame( 'revenue', $out['report'] );
		$this->assertGreaterThanOrEqual( 2, (int) $out['totals']['orders_count'] );
		$this->assertGreaterThanOrEqual( 100.0, (float) $out['totals']['gross_sales'], '1 × 25 + 3 × 25 at least.' );
		$this->assertNotEmpty( $out['intervals'] );
		$this->assertNotEmpty( $out['note'] );
	}

	public function test_daily_intervals_cover_the_whole_range(): void {
		// Found on staging 2026-11-09: wc-analytics pages intervals (10 by default), so a 30-day report showed 10 days.
		$out = $this->ok(
			'kuduy-woo-report',
			array(
				'report'   => 'revenue',
				'after'    => current_datetime()->modify( '-29 days' )->format( 'Y-m-d' ),
				'before'   => current_time( 'Y-m-d' ),
				'interval' => 'day',
			)
		);
		$this->assertCount( 30, $out['intervals'] );
	}

	public function test_products_report_lists_the_item(): void {
		$out  = $this->today( 'products' );
		$rows = array_values( array_filter( $out['rows'], static fn( $r ) => 'Kuduy report item' === ( $r['extended_info']['name'] ?? '' ) ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 4, (int) $rows[0]['items_sold'] );
	}

	public function test_customer_data_in_reports_is_masked(): void {
		$json = (string) wp_json_encode( array( $this->today( 'customers' ), $this->today( 'orders' ) ) );
		$this->assertStringContainsString( 'r***@example.test', $json );
		$this->assertStringNotContainsString( self::EMAIL, $json );
		$this->assertStringNotContainsString( 'Hamburg', $json );
	}

	public function test_reports_need_report_access(): void {
		wp_set_current_user( self::$author );
		$this->fails( 'kuduy-woo-report', array( 'report' => 'revenue' ), 'author reading reports' );
	}
}
