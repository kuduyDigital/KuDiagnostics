<?php
/**
 * Phase 7 WooCommerce store settings (test matrix W12, W13, W2): shipping zones and tax rates read, payment gateways
 * with every secret masked (by field type and by name), nothing secret in results or audit rows, admin-level access.
 * Runs only when WooCommerce is active.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class WooStoreSettingsTest extends TestCase {

	use CallsTools;

	private const PIN       = '4711-KUDUY-SECRET';
	private const SIGNATURE = 'SIG-KUDUY-9f8e7d6c';

	private static int $admin;
	private static int $manager;
	private static int $editor;

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'WC_VERSION' ) ) {
			self::markTestSkipped( 'WooCommerce is not active in this run (KUDUY_PLUGINS).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		require_once __DIR__ . '/KuduyFakeGateway.php';
		Plugin::boot();
		self::$admin   = self::make_user( 'administrator' );
		self::$manager = self::make_user( 'shop_manager' );
		self::$editor  = self::make_user( 'editor' );

		update_option(
			'woocommerce_kuduy_fake_settings',
			array(
				'enabled'       => 'yes',
				'title'         => 'Kuduy card',
				'testmode'      => 'yes',
				'merchant_pin'  => self::PIN,
				'api_signature' => self::SIGNATURE,
				'sepa_tokens'   => 'yes',
			)
		);
		add_filter( 'woocommerce_payment_gateways', array( self::class, 'add_gateway' ) );
		WC()->payment_gateways()->init();
	}

	public static function tearDownAfterClass(): void {
		remove_filter( 'woocommerce_payment_gateways', array( self::class, 'add_gateway' ) );
		WC()->payment_gateways()->init();
	}

	/**
	 * Register the fake gateway.
	 *
	 * @param array<mixed> $gateways Gateway classes.
	 * @return array<mixed>
	 */
	public static function add_gateway( array $gateways ): array {
		$gateways[] = KuduyFakeGateway::class;
		return $gateways;
	}

	protected function setUp(): void {
		wp_set_current_user( self::$manager );
	}

	public function test_shipping_zones_with_regions_and_methods(): void {
		$zone = new \WC_Shipping_Zone();
		$zone->set_zone_name( 'Kuduy EU' );
		$zone->add_location( 'DE', 'country' );
		$zone->save();
		$instance = $zone->add_shipping_method( 'flat_rate' );
		update_option(
			'woocommerce_flat_rate_' . $instance . '_settings',
			array(
				'title' => 'Kuduy flat',
				'cost'  => '5',
			)
		);

		$out  = $this->ok( 'kuduy-woo-shipping' );
		$mine = array_values( array_filter( $out['zones'], static fn( $z ) => 'Kuduy EU' === $z['name'] ) )[0] ?? null;
		$this->assertNotNull( $mine );
		$this->assertSame( array( 'country:DE' ), $mine['regions'] );
		$this->assertSame( 'flat_rate', $mine['methods'][0]['method_id'] );
		$this->assertSame( 'Kuduy flat', $mine['methods'][0]['title'] );
		$this->assertSame( '5', $mine['methods'][0]['settings']['cost'] );
		$this->assertSame( 0, $out['zones'][ count( $out['zones'] ) - 1 ]['id'], 'The "rest of the world" zone comes last.' );
	}

	public function test_tax_classes_and_rates(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		\WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'DE',
				'tax_rate'          => '19.0000',
				'tax_rate_name'     => 'MwSt',
				'tax_rate_priority' => 1,
				'tax_rate_shipping' => 1,
				'tax_rate_class'    => '',
			)
		);
		$out = $this->ok( 'kuduy-woo-tax' );
		$this->assertTrue( $out['enabled'] );
		$standard = array_values( array_filter( $out['classes'], static fn( $c ) => '' === $c['slug'] ) )[0];
		$rate     = array_values( array_filter( $standard['rates'], static fn( $r ) => 'MwSt' === $r['name'] ) )[0] ?? null;
		$this->assertNotNull( $rate );
		$this->assertSame( 'DE', $rate['country'] );
		$this->assertEquals( 19.0, (float) $rate['rate'] );
		$this->assertTrue( $rate['shipping'] );
	}

	public function test_gateway_secrets_are_masked_by_type_and_by_name_everywhere(): void {
		wp_set_current_user( self::$admin );
		$out  = $this->ok( 'kuduy-woo-payment-gateways' );
		$fake = array_values( array_filter( $out['gateways'], static fn( $g ) => 'kuduy_fake' === $g['id'] ) )[0] ?? null;
		$this->assertNotNull( $fake, 'The extension gateway is listed.' );
		$this->assertTrue( $fake['enabled'] );
		$this->assertTrue( $fake['test_mode'] );
		$settings = array_column( $fake['settings'], null, 'id' );
		$this->assertSame( 'Kuduy card', $settings['title']['value'] );
		foreach ( array( 'merchant_pin', 'api_signature' ) as $secret ) {
			$this->assertNull( $settings[ $secret ]['value'], $secret );
			$this->assertTrue( $settings[ $secret ]['has_value'], $secret );
		}
		// Found on staging 2026-11-09: a checkbox named like a token was hidden; only free-text fields are masked by name.
		$this->assertSame( 'yes', $settings['sepa_tokens']['value'] );
		$json = (string) wp_json_encode( $out ) . (string) wp_json_encode( AuditLog::latest( 5 ) );
		$this->assertStringNotContainsString( self::PIN, $json );
		$this->assertStringNotContainsString( self::SIGNATURE, $json );
	}

	public function test_store_settings_need_manage_woocommerce(): void {
		$this->ok( 'kuduy-woo-payment-gateways' );
		wp_set_current_user( self::$editor );
		foreach ( array( 'kuduy-woo-shipping', 'kuduy-woo-tax', 'kuduy-woo-payment-gateways' ) as $tool ) {
			$this->fails( $tool, array(), $tool . ' as editor' );
		}
	}
}
