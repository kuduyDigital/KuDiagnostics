<?php
/**
 * Phase 7 WooCommerce customers (test matrix W10): masked list and get, full details only when asked and allowed
 * (D12), address update + undo, no personal data in audit rows, and WooCommerce's rule that a shop manager edits
 * customers only. Runs only when WooCommerce is active.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class WooCustomersTest extends TestCase {

	use CallsTools;

	private static int $manager;
	private static int $author;
	private static int $customer;
	private static string $email;

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'WC_VERSION' ) ) {
			self::markTestSkipped( 'WooCommerce is not active in this run (KUDUY_PLUGINS).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$manager  = self::make_user( 'shop_manager' );
		self::$author   = self::make_user( 'author' );
		self::$customer = self::make_user( 'customer' );
		$customer       = new \WC_Customer( self::$customer );
		$customer->set_first_name( 'Ola' );
		$customer->set_last_name( 'Nordmann' );
		$customer->set_billing_first_name( 'Ola' );
		$customer->set_billing_last_name( 'Nordmann' );
		$customer->set_billing_address_1( 'Karl Johans gate 1' );
		$customer->set_billing_city( 'Oslo' );
		$customer->set_billing_country( 'NO' );
		$customer->set_billing_phone( '+47 22 00 00 00' );
		$customer->save();
		self::$email = $customer->get_email();
	}

	protected function setUp(): void {
		wp_set_current_user( self::$manager );
	}

	public function test_list_and_get_mask_personal_data(): void {
		$list = $this->ok( 'kuduy-woo-customer-list', array( 'per_page' => 100 ) );
		$row  = array_values( array_filter( $list['customers'], static fn( $c ) => self::$customer === $c['id'] ) )[0] ?? null;
		$this->assertNotNull( $row );
		$this->assertSame( 'Ola Nordmann', $row['name'] );
		$this->assertStringContainsString( '***@', $row['email'] );
		$get  = $this->ok( 'kuduy-woo-customer-get', array( 'id' => self::$customer ) );
		$json = (string) wp_json_encode( array( $list, $get ) );
		foreach ( array( self::$email, 'Karl Johans', '22 00 00 00' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $json, $secret );
		}
		$this->assertSame( 'NO', $get['billing']['country'] );

		$full = $this->ok(
			'kuduy-woo-customer-get',
			array(
				'id'          => self::$customer,
				'include_pii' => true,
			)
		);
		$this->assertSame( 'Karl Johans gate 1', $full['billing']['address_1'] );
		$this->assertSame( self::$email, $full['email'] );
	}

	public function test_address_update_with_undo_keeps_personal_data_out_of_the_audit_log(): void {
		$updated = $this->ok(
			'kuduy-woo-customer-update',
			array(
				'id'      => self::$customer,
				'billing' => array(
					'address_1' => 'Storgata 99',
					'city'      => 'Bergen',
				),
			)
		);
		$this->assertSame( array( 'billing.address_1', 'billing.city' ), $updated['changed'] );
		$customer = new \WC_Customer( self::$customer );
		$this->assertSame( 'Storgata 99', $customer->get_billing_address_1() );
		$this->assertSame( 'Bergen', $customer->get_billing_city() );
		$this->assertSame( '+47 22 00 00 00', $customer->get_billing_phone(), 'Fields not sent stay.' );

		$rows = (string) wp_json_encode( AuditLog::latest( 5 ) );
		$this->assertStringContainsString( 'kuduy-woo-customer-update', $rows );
		$this->assertStringNotContainsString( 'Storgata', $rows );
		$this->assertStringNotContainsString( 'Bergen', $rows );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $updated['_snapshot_id'] ) );
		$customer = new \WC_Customer( self::$customer );
		$this->assertSame( 'Karl Johans gate 1', $customer->get_billing_address_1() );
		$this->assertSame( 'Oslo', $customer->get_billing_city() );
	}

	public function test_shop_manager_edits_customers_only_and_authors_see_nothing(): void {
		$editor  = self::make_user( 'editor' );
		$message = $this->fails(
			'kuduy-woo-customer-update',
			array(
				'id'      => $editor,
				'billing' => array( 'city' => 'Nope' ),
			),
			'shop manager editing an editor'
		);
		$this->assertNotSame( '', $message );
		$this->assertSame( '', ( new \WC_Customer( $editor ) )->get_billing_city() );

		wp_set_current_user( self::$author );
		$this->fails( 'kuduy-woo-customer-list', array(), 'author lists customers' );
		$this->fails( 'kuduy-woo-customer-get', array( 'id' => self::$customer ), 'author reads a customer' );
	}

	public function test_invalid_billing_email_is_refused(): void {
		$this->fails(
			'kuduy-woo-customer-update',
			array(
				'id'      => self::$customer,
				'billing' => array( 'email' => 'not-an-email' ),
			),
			'invalid email'
		);
	}
}
