<?php
/**
 * Phase 7 WooCommerce coupons (test matrix W9): create with a unique code, update + undo, trash + undo, read tools,
 * and WooCommerce's coupon permissions. Runs only when WooCommerce is active.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class WooCouponsTest extends TestCase {

	use CallsTools;

	private static int $manager;
	private static int $editor;
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
		self::$editor  = self::make_user( 'editor' );
		self::$author  = self::make_user( 'author' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$manager );
	}

	/**
	 * Create a coupon through the tool.
	 *
	 * @param string               $code Code.
	 * @param array<string, mixed> $args More arguments.
	 * @return array<string, mixed>
	 */
	private function create( string $code, array $args = array() ): array {
		return $this->ok(
			'kuduy-woo-coupon-create',
			$args + array(
				'code'          => $code,
				'discount_type' => 'percent',
				'amount'        => '10',
			)
		);
	}

	public function test_create_reads_back_through_wc_coupon_and_codes_are_unique(): void {
		$out    = $this->create( 'kuduy-ten', array( 'free_shipping' => true ) );
		$coupon = new \WC_Coupon( $out['id'] );
		$this->assertSame( 'kuduy-ten', $coupon->get_code() );
		$this->assertSame( 'percent', $coupon->get_discount_type() );
		$this->assertEquals( 10.0, (float) $coupon->get_amount() );
		$this->assertTrue( $coupon->get_free_shipping() );
		$this->assertSame( 'publish', $out['status'], 'Coupons are active when created unless status says otherwise.' );

		$message = $this->fails(
			'kuduy-woo-coupon-create',
			array(
				'code'   => 'KUDUY-TEN',
				'amount' => '5',
			),
			'duplicate code in another case'
		);
		$this->assertStringContainsString( 'already', $message );
	}

	public function test_percent_over_100_is_refused(): void {
		$this->fails(
			'kuduy-woo-coupon-create',
			array(
				'code'          => 'kuduy-too-much',
				'discount_type' => 'percent',
				'amount'        => '150',
			),
			'percent > 100'
		);
		$this->assertSame( 0, wc_get_coupon_id_by_code( 'kuduy-too-much' ) );
	}

	public function test_update_and_undo(): void {
		$id      = $this->create( 'kuduy-update' )['id'];
		$updated = $this->ok(
			'kuduy-woo-coupon-update',
			array(
				'id'             => $id,
				'amount'         => '15',
				'date_expires'   => '2030-12-31',
				'minimum_amount' => '50',
			)
		);
		$this->assertSame( array( 'amount', 'date_expires', 'minimum_amount' ), $updated['changed'] );
		$coupon = new \WC_Coupon( $id );
		$this->assertEquals( 15.0, (float) $coupon->get_amount() );
		$this->assertSame( '2030-12-31', $coupon->get_date_expires()->date( 'Y-m-d' ) );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $updated['_snapshot_id'] ) );
		$coupon = new \WC_Coupon( $id );
		$this->assertEquals( 10.0, (float) $coupon->get_amount() );
		$this->assertNull( $coupon->get_date_expires() );
		$this->assertSame( '', (string) $coupon->get_minimum_amount( 'edit' ), 'Empty again (the default view context shows "0").' );
	}

	public function test_read_tools_and_trash_with_undo(): void {
		$id   = $this->create( 'kuduy-read' )['id'];
		$list = $this->ok( 'kuduy-woo-coupon-list', array( 'search' => 'kuduy-read' ) );
		$this->assertContains( $id, array_column( $list['coupons'], 'id' ) );
		$this->assertSame( $id, $this->ok( 'kuduy-woo-coupon-get', array( 'code' => 'KUDUY-READ' ) )['id'] );

		$trashed = $this->ok( 'kuduy-woo-coupon-trash', array( 'id' => $id ) );
		$this->assertSame( 'trash', get_post_status( $id ) );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $trashed['_snapshot_id'] ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	public function test_users_without_coupon_capabilities_are_refused(): void {
		$id = $this->create( 'kuduy-guarded' )['id'];
		foreach ( array( self::$editor, self::$author ) as $user ) {
			wp_set_current_user( $user );
			$this->fails( 'kuduy-woo-coupon-list', array(), 'list without coupon caps' );
			$this->fails(
				'kuduy-woo-coupon-update',
				array(
					'id'     => $id,
					'amount' => '99',
				),
				'update without coupon caps'
			);
			$this->fails( 'kuduy-woo-coupon-trash', array( 'id' => $id ), 'trash without coupon caps' );
		}
		$this->assertEquals( 10.0, (float) ( new \WC_Coupon( $id ) )->get_amount() );
	}
}
