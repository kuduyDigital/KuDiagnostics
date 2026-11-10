<?php
/**
 * WooCommerce shipping and tax safe writes (test matrix W15–W17, decision D24 A): zones with locations, methods with
 * settings, tax rates, each through WooCommerce's own wc/v3 controllers with undo; a deleted tax rate comes back on
 * undo; shop managers and editors refused by scope or capability as WooCommerce decides.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Mcp\ToolRegistry;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class WooSettingsWriteTest extends TestCase {

	use CallsTools;

	private static int $admin;
	private static int $editor;

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'WC_VERSION' ) ) {
			self::markTestSkipped( 'WooCommerce is not active in this run (KUDUY_PLUGINS).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$editor = self::make_user( 'editor' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	public function test_zone_and_method_with_undo(): void {
		$zone = $this->ok(
			'kuduy-woo-shipping-zone-save',
			array(
				'name'      => 'Kuduy Europe',
				'locations' => array(
					array(
						'code' => 'DE',
						'type' => 'country',
					),
				),
			)
		);
		$this->assertTrue( $zone['created'] );
		$zid = (int) $zone['item']['id'];
		$this->assertSame( array( 'DE' ), array_column( $zone['item']['locations'], 'code' ) );

		$changed = $this->ok(
			'kuduy-woo-shipping-zone-save',
			array(
				'id'        => $zid,
				'name'      => 'Kuduy EU',
				'locations' => array(
					array(
						'code' => 'FR',
						'type' => 'country',
					),
				),
			)
		);
		$this->assertSame( 'Kuduy EU', ( new \WC_Shipping_Zone( $zid ) )->get_zone_name() );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $changed['_snapshot_id'] ) );
		$zone_obj = new \WC_Shipping_Zone( $zid );
		$this->assertSame( 'Kuduy Europe', $zone_obj->get_zone_name() );
		$this->assertSame( array( 'DE' ), array_map( static fn( $l ) => $l->code, $zone_obj->get_zone_locations() ) );

		$method   = $this->ok(
			'kuduy-woo-shipping-method-save',
			array(
				'zone_id'   => $zid,
				'method_id' => 'flat_rate',
				'settings'  => array(
					'title' => 'Kuduy standard',
					'cost'  => '5',
				),
			)
		);
		$instance = (int) $method['item']['instance_id'];
		$this->assertSame( '5', $method['item']['settings']['cost']['value'] );

		$off = $this->ok(
			'kuduy-woo-shipping-method-save',
			array(
				'zone_id'     => $zid,
				'instance_id' => $instance,
				'enabled'     => false,
				'settings'    => array( 'cost' => '9' ),
			)
		);
		$this->assertFalse( $off['item']['enabled'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $off['_snapshot_id'] ) );
		$methods = ( new \WC_Shipping_Zone( $zid ) )->get_shipping_methods();
		$this->assertSame( 'yes', $methods[ $instance ]->enabled );
		$this->assertSame( '5', $methods[ $instance ]->get_option( 'cost' ) );
	}

	public function test_tax_rate_save_delete_and_undo(): void {
		$rate = $this->ok(
			'kuduy-woo-tax-rate-save',
			array(
				'country' => 'DE',
				'rate'    => '19.0000',
				'name'    => 'MwSt',
			)
		);
		$this->assertTrue( $rate['created'] );
		$id = (int) $rate['item']['id'];

		$changed = $this->ok(
			'kuduy-woo-tax-rate-save',
			array(
				'id'   => $id,
				'rate' => '7.0000',
			)
		);
		$this->assertSame( '7.0000', \WC_Tax::_get_tax_rate( $id )['tax_rate'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $changed['_snapshot_id'] ) );
		$this->assertSame( '19.0000', \WC_Tax::_get_tax_rate( $id )['tax_rate'] );

		$deleted = $this->ok( 'kuduy-woo-tax-rate-delete', array( 'id' => $id ) );
		$this->assertTrue( $deleted['deleted'] );
		$this->assertSame( array(), (array) \WC_Tax::_get_tax_rate( $id ) );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $deleted['_snapshot_id'] ) );
		$found = array_filter( \WC_Tax::find_rates( array( 'country' => 'DE' ) ), static fn( $r ) => 'MwSt' === $r['label'] );
		$this->assertCount( 1, $found, 'Undo creates the rate again.' );

		$this->fails( 'kuduy-woo-tax-rate-save', array( 'country' => 'DE' ), 'new rate without rate' );
		$this->assertSame( 'kuduy:admin', ToolRegistry::create()->get( 'kuduy-woo-tax-rate-save' )->scope() );
		wp_set_current_user( self::$editor );
		$this->fails(
			'kuduy-woo-tax-rate-save',
			array(
				'country' => 'FR',
				'rate'    => '20.0000',
			),
			'editor lacks manage_woocommerce'
		);
	}
}
