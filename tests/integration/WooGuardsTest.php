<?php
/**
 * Phase 7 WooCommerce (test matrix W1): the run's HPOS mode is the one asked for, and core content tools refuse to
 * write WooCommerce objects (wp_update_post and raw meta writes would skip WooCommerce's lookup tables and hooks).
 * Runs only when WooCommerce is active (KUDUY_PLUGINS=woocommerce@…, KUDUY_HPOS=on|off).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class WooGuardsTest extends TestCase {

	use CallsTools;

	private static int $admin;

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'WC_VERSION' ) ) {
			self::markTestSkipped( 'WooCommerce is not active in this run (KUDUY_PLUGINS).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin = self::make_user( 'administrator' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	/**
	 * A simple product made through WooCommerce's CRUD.
	 */
	private static function product(): int {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Kuduy guard product' );
		$product->set_regular_price( '10' );
		$product->set_status( 'publish' );
		return $product->save();
	}

	public function test_hpos_mode_is_the_one_the_run_asked_for(): void {
		$expected = get_option( 'kuduy_test_hpos' );
		$this->assertContains( $expected, array( 'on', 'off' ), 'The runner sets kuduy_test_hpos from KUDUY_HPOS.' );
		$this->assertSame( 'on' === $expected, OrderUtil::custom_orders_table_usage_is_enabled() );
	}

	public function test_post_update_refuses_products(): void {
		$id      = self::product();
		$message = $this->fails(
			'kuduy-post-update',
			array(
				'id'    => $id,
				'title' => 'Changed',
			),
			'product through post-update'
		);
		$this->assertStringContainsString( 'kuduy-woo-', $message );
		$this->assertSame( 'Kuduy guard product', wc_get_product( $id )->get_name() );
	}

	public function test_post_create_refuses_woocommerce_types(): void {
		foreach ( array( 'product', 'shop_coupon' ) as $type ) {
			$message = $this->fails(
				'kuduy-post-create',
				array(
					'post_type' => $type,
					'title'     => 'Nope',
				),
				$type . ' through post-create'
			);
			$this->assertStringContainsString( 'kuduy-woo-', $message, $type );
		}
	}

	public function test_post_trash_refuses_products(): void {
		$id = self::product();
		$this->fails( 'kuduy-post-trash', array( 'id' => $id ), 'product through post-trash' );
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	public function test_post_meta_set_refuses_products(): void {
		$id = self::product();
		$this->fails(
			'kuduy-post-meta-set',
			array(
				'post_id' => $id,
				'key'     => 'kuduy_note',
				'value'   => 'x',
			),
			'product meta'
		);
		$this->assertSame( '', get_post_meta( $id, 'kuduy_note', true ) );
	}

	public function test_reads_and_normal_posts_still_work(): void {
		$id = self::product();
		$this->assertSame( $id, $this->ok( 'kuduy-post-get', array( 'id' => $id ) )['id'] );
		$post = (int) wp_insert_post(
			array(
				'post_title'  => 'Kuduy plain post',
				'post_status' => 'draft',
			)
		);
		$this->assertSame(
			'Renamed',
			$this->ok(
				'kuduy-post-update',
				array(
					'id'    => $post,
					'title' => 'Renamed',
				)
			)['title']
		);
	}
}
