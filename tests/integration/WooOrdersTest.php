<?php
/**
 * Phase 7 WooCommerce orders (test matrix W6–W8): order list/get with customer data masked unless allowed (D12),
 * status changes that are confirm-first when WooCommerce would email the customer (D11), notes, undo without
 * emails, and per-object order capabilities. Runs with HPOS on and off (KUDUY_HPOS), only when WooCommerce is active.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class WooOrdersTest extends TestCase {

	use CallsTools;

	private const EMAIL = 'jane.buyer@example.test';

	private static int $manager;
	private static int $editor;
	private static int $author;
	private static int $product;

	/**
	 * Mail WordPress tried to send during the test (recipients and subjects).
	 *
	 * @var list<array{to: string, subject: string}>
	 */
	private static array $mail = array();

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
		$product       = new \WC_Product_Simple();
		$product->set_name( 'Kuduy order item' );
		$product->set_regular_price( '12' );
		$product->set_status( 'publish' );
		self::$product = $product->save();
		add_filter( 'pre_wp_mail', array( self::class, 'capture_mail' ), 10, 2 );
	}

	public static function tearDownAfterClass(): void {
		remove_filter( 'pre_wp_mail', array( self::class, 'capture_mail' ), 10 );
	}

	/**
	 * Record mail instead of sending it.
	 *
	 * @param mixed                $result Short-circuit value.
	 * @param array<string, mixed> $atts   wp_mail() arguments.
	 */
	public static function capture_mail( mixed $result, array $atts ): bool {
		self::$mail[] = array(
			'to'      => implode( ',', (array) $atts['to'] ),
			'subject' => (string) $atts['subject'],
		);
		return true;
	}

	protected function setUp(): void {
		wp_set_current_user( self::$manager );
	}

	/**
	 * An order made through WooCommerce's CRUD API, with customer data.
	 *
	 * @param string $status Status.
	 */
	private static function order( string $status = 'processing' ): \WC_Order {
		$order = wc_create_order();
		$order->add_product( wc_get_product( self::$product ), 2 );
		$order->set_billing_first_name( 'Jane' );
		$order->set_billing_last_name( 'Buyer' );
		$order->set_billing_email( self::EMAIL );
		$order->set_billing_phone( '+49 30 1234567' );
		$order->set_billing_address_1( 'Musterstrasse 1' );
		$order->set_billing_city( 'Berlin' );
		$order->set_billing_country( 'DE' );
		$order->set_customer_ip_address( '203.0.113.9' );
		$order->calculate_totals();
		$order->set_status( $status );
		$order->save();
		self::$mail = array();
		return $order;
	}

	/**
	 * Mail sent to the test customer since the last reset.
	 *
	 * @return list<string> Subjects.
	 */
	private static function customer_mail(): array {
		return array_column( array_filter( self::$mail, static fn( $m ) => str_contains( $m['to'], self::EMAIL ) ), 'subject' );
	}

	public function test_list_masks_customer_data_and_never_shows_order_keys(): void {
		$order = self::order();
		$list  = $this->ok( 'kuduy-woo-order-list', array( 'status' => 'processing' ) );
		$row   = array_values( array_filter( $list['orders'], static fn( $o ) => $o['id'] === $order->get_id() ) )[0] ?? null;
		$this->assertNotNull( $row, 'The order is listed.' );
		$this->assertSame( 'processing', $row['status'] );
		$this->assertSame( 'j***@example.test', $row['email'] );
		$json = (string) wp_json_encode( $list );
		$this->assertStringNotContainsString( self::EMAIL, $json );
		$this->assertStringNotContainsString( 'Musterstrasse', $json );
		$this->assertStringNotContainsString( $order->get_order_key(), $json );
	}

	public function test_get_masks_by_default_and_shows_pii_only_when_asked_and_allowed(): void {
		$order  = self::order();
		$masked = $this->ok( 'kuduy-woo-order-get', array( 'id' => $order->get_id() ) );
		$this->assertCount( 1, $masked['items'] );
		$this->assertSame( 2, $masked['items'][0]['quantity'] );
		$this->assertEquals( 24.0, (float) $masked['total'] );
		$this->assertFalse( $masked['pii'] );
		$json = (string) wp_json_encode( $masked );
		foreach ( array( self::EMAIL, 'Musterstrasse', '1234567', '203.0.113.9', $order->get_order_key() ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $json, $secret );
		}

		$full = $this->ok(
			'kuduy-woo-order-get',
			array(
				'id'          => $order->get_id(),
				'include_pii' => true,
			)
		);
		$this->assertTrue( $full['pii'] );
		$this->assertSame( self::EMAIL, $full['billing']['email'] );
		$this->assertSame( 'Musterstrasse 1', $full['billing']['address_1'] );
		$this->assertSame( '203.0.113.9', $full['customer_ip_address'] );
		$this->assertStringNotContainsString( $order->get_order_key(), (string) wp_json_encode( $full ), 'Order keys never.' );
	}

	public function test_status_change_that_emails_the_customer_is_confirm_first_and_undo_sends_nothing(): void {
		$order = self::order( 'processing' );
		$dry   = $this->ok(
			'kuduy-woo-order-status',
			array(
				'id'     => $order->get_id(),
				'status' => 'completed',
			)
		);
		$this->assertFalse( $dry['done'] );
		$this->assertNotEmpty( $dry['customer_emails'] );
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status(), 'Nothing changes without confirm.' );
		$this->assertSame( array(), self::customer_mail() );

		$done = $this->ok(
			'kuduy-woo-order-status',
			array(
				'id'      => $order->get_id(),
				'status'  => 'completed',
				'confirm' => true,
			)
		);
		$this->assertTrue( $done['done'] );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
		$this->assertCount( 1, self::customer_mail(), 'The completed-order email went to the customer.' );

		self::$mail = array();
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $done['_snapshot_id'] ) );
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( array(), self::customer_mail(), 'Undo sends no customer email.' );
	}

	public function test_status_change_without_customer_email_runs_at_once(): void {
		$order = self::order( 'processing' );
		$out   = $this->ok(
			'kuduy-woo-order-status',
			array(
				'id'     => $order->get_id(),
				'status' => 'on-hold',
			)
		);
		$this->assertTrue( $out['done'] );
		$this->assertSame( array(), $out['customer_emails'] );
		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_refunded_status_is_refused(): void {
		$order = self::order( 'completed' );
		$this->fails(
			'kuduy-woo-order-status',
			array(
				'id'      => $order->get_id(),
				'status'  => 'refunded',
				'confirm' => true,
			),
			'refunds are not offered (D11)'
		);
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( array(), wc_get_order( $order->get_id() )->get_refunds() );
	}

	public function test_private_note_and_confirm_first_customer_note(): void {
		$order = self::order();
		$this->ok(
			'kuduy-woo-order-note',
			array(
				'id'   => $order->get_id(),
				'note' => 'Packed by Kuduy.',
			)
		);
		$this->assertSame( array(), self::customer_mail() );
		$dry = $this->ok(
			'kuduy-woo-order-note',
			array(
				'id'            => $order->get_id(),
				'note'          => 'Your parcel is on its way.',
				'customer_note' => true,
			)
		);
		$this->assertFalse( $dry['done'] );
		$this->assertSame( array(), self::customer_mail() );
		$this->ok(
			'kuduy-woo-order-note',
			array(
				'id'            => $order->get_id(),
				'note'          => 'Your parcel is on its way.',
				'customer_note' => true,
				'confirm'       => true,
			)
		);
		$this->assertCount( 1, self::customer_mail() );
		$notes = array_column( $this->ok( 'kuduy-woo-order-get', array( 'id' => $order->get_id() ) )['notes'], 'customer_note', 'content' );
		$this->assertFalse( $notes['Packed by Kuduy.'] );
		$this->assertTrue( $notes['Your parcel is on its way.'] );
	}

	public function test_users_without_order_capabilities_are_refused(): void {
		$order = self::order();
		foreach ( array( self::$editor, self::$author ) as $user ) {
			wp_set_current_user( $user );
			$this->fails( 'kuduy-woo-order-list', array(), 'list without order caps' );
			$this->fails( 'kuduy-woo-order-get', array( 'id' => $order->get_id() ), 'get without order caps' );
			$this->fails(
				'kuduy-woo-order-status',
				array(
					'id'      => $order->get_id(),
					'status'  => 'on-hold',
					'confirm' => true,
				),
				'status without order caps'
			);
		}
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_pii_needs_edit_rights_on_the_order(): void {
		add_role(
			'kuduy_order_reader',
			'Kuduy order reader',
			array(
				'read'                     => true,
				'read_private_shop_orders' => true,
			)
		);
		$reader = self::make_user( 'kuduy_order_reader' );
		try {
			$order = self::order();
			wp_set_current_user( $reader );
			$this->assertFalse( $this->ok( 'kuduy-woo-order-get', array( 'id' => $order->get_id() ) )['pii'] );
			$message = $this->fails(
				'kuduy-woo-order-get',
				array(
					'id'          => $order->get_id(),
					'include_pii' => true,
				),
				'PII without edit rights'
			);
			$this->assertStringContainsString( 'customer details', $message );
		} finally {
			remove_role( 'kuduy_order_reader' );
		}
	}
}
