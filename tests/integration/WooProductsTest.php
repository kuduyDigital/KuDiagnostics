<?php
/**
 * Phase 7 WooCommerce products (test matrix W3–W5): kuduy-woo-status, product read and write tools, variations,
 * stock and trash through WooCommerce's CRUD API, undo, and WooCommerce's own permission rules.
 * Runs only when WooCommerce is active (KUDUY_PLUGINS=woocommerce@…, KUDUY_HPOS=on|off).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class WooProductsTest extends TestCase {

	use CallsTools;

	private static int $manager;
	private static int $editor;
	private static int $author;
	private static int $category;

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'WC_VERSION' ) ) {
			self::markTestSkipped( 'WooCommerce is not active in this run (KUDUY_PLUGINS).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$manager  = self::make_user( 'shop_manager' );
		self::$editor   = self::make_user( 'editor' );
		self::$author   = self::make_user( 'author' );
		$term           = wp_insert_term( 'Kuduy shirts', 'product_cat' );
		self::$category = is_array( $term ) ? (int) $term['term_id'] : 0;
	}

	protected function setUp(): void {
		wp_set_current_user( self::$manager );
	}

	/**
	 * Create a product through the tool.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private function create( array $args ): array {
		return $this->ok( 'kuduy-woo-product-create', $args + array( 'name' => 'Kuduy tee' ) );
	}

	/**
	 * A variable product read fresh through WooCommerce.
	 *
	 * @param int $id Product id.
	 */
	private static function variable( int $id ): \WC_Product_Variable {
		$product = wc_get_product( $id );
		self::assertInstanceOf( \WC_Product_Variable::class, $product );
		return $product;
	}

	public function test_status_reports_version_storage_and_currency(): void {
		$status = $this->ok( 'kuduy-woo-status' );
		$this->assertSame( WC_VERSION, $status['version'] );
		$this->assertSame( OrderUtil::custom_orders_table_usage_is_enabled(), $status['hpos']['enabled'] );
		$this->assertSame( get_woocommerce_currency(), $status['currency']['code'] );
		$this->assertFalse( $status['coming_soon'] );
	}

	public function test_create_simple_product_round_trips_through_crud_and_lookup_table(): void {
		global $wpdb;
		$out = $this->create(
			array(
				'sku'           => 'KUDUY-TEE-1',
				'regular_price' => '19.90',
				'category_ids'  => array( self::$category ),
			)
		);
		$this->assertSame( 'draft', $out['status'], 'Created as draft by default.' );
		$product = wc_get_product( $out['id'] );
		$this->assertInstanceOf( \WC_Product_Simple::class, $product );
		$this->assertSame( 'Kuduy tee', $product->get_name() );
		$this->assertSame( 'KUDUY-TEE-1', $product->get_sku() );
		$this->assertSame( '19.90', $product->get_regular_price() );
		$this->assertSame( array( self::$category ), $product->get_category_ids() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test reads WooCommerce's lookup table to prove CRUD ran.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT sku, min_price FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id = %d", $out['id'] ), ARRAY_A );
		$this->assertSame( 'KUDUY-TEE-1', $row['sku'] ?? null );
		$this->assertEquals( 19.9, (float) ( $row['min_price'] ?? 0 ) );
	}

	public function test_duplicate_sku_is_refused_and_nothing_is_created(): void {
		$this->create( array( 'sku' => 'KUDUY-DUP' ) );
		$before  = count(
			wc_get_products(
				array(
					'name'   => 'Kuduy dup',
					'status' => 'any',
					'limit'  => -1,
				)
			)
		);
		$message = $this->fails(
			'kuduy-woo-product-create',
			array(
				'name' => 'Kuduy dup',
				'sku'  => 'KUDUY-DUP',
			),
			'duplicate SKU'
		);
		$this->assertStringContainsString( 'SKU', $message );
		$this->assertSame(
			$before,
			count(
				wc_get_products(
					array(
						'name'   => 'Kuduy dup',
						'status' => 'any',
						'limit'  => -1,
					)
				)
			)
		);
	}

	public function test_update_patches_fields_and_undo_restores_them(): void {
		$id      = $this->create( array( 'regular_price' => '10' ) )['id'];
		$updated = $this->ok(
			'kuduy-woo-product-update',
			array(
				'id'                => $id,
				'name'              => 'Kuduy tee v2',
				'sale_price'        => '8',
				'short_description' => 'Soft cotton',
				'status'            => 'publish',
			)
		);
		$this->assertSame( array( 'name', 'sale_price', 'short_description', 'status' ), $updated['changed'] );
		$product = wc_get_product( $id );
		$this->assertSame( 'Kuduy tee v2', $product->get_name() );
		$this->assertSame( '8', $product->get_sale_price() );
		$this->assertTrue( $product->is_on_sale() );
		$this->assertSame( 'publish', $product->get_status() );
		$this->assertSame( '10', $product->get_regular_price(), 'Fields not sent stay as they were.' );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $updated['_snapshot_id'] ) );
		$product = wc_get_product( $id );
		$this->assertSame( 'Kuduy tee', $product->get_name() );
		$this->assertSame( '', $product->get_sale_price() );
		$this->assertSame( '', $product->get_short_description() );
		$this->assertSame( 'draft', $product->get_status() );
	}

	public function test_read_tools_list_and_get_products(): void {
		$id   = $this->create( array( 'sku' => 'KUDUY-LIST-1' ) )['id'];
		$list = $this->ok(
			'kuduy-woo-product-list',
			array(
				'sku'    => 'KUDUY-LIST-1',
				'status' => 'any',
			)
		);
		$this->assertSame( array( $id ), array_column( $list['products'], 'id' ) );
		$get = $this->ok( 'kuduy-woo-product-get', array( 'id' => $id ) );
		$this->assertSame( 'simple', $get['type'] );
		$this->assertSame( 'KUDUY-LIST-1', $get['sku'] );
		$this->fails( 'kuduy-woo-product-get', array( 'id' => (int) wp_insert_post( array( 'post_title' => 'Plain' ) ) ), 'not a product' );
	}

	public function test_variable_product_variations_and_parent_sync(): void {
		$parent = $this->create(
			array(
				'type'       => 'variable',
				'name'       => 'Kuduy hoodie',
				'attributes' => array(
					array(
						'name'      => 'Size',
						'options'   => array( 'S', 'M' ),
						'variation' => true,
					),
				),
			)
		)['id'];
		$small  = $this->ok(
			'kuduy-woo-variation-create',
			array(
				'product_id'    => $parent,
				'attributes'    => array( 'Size' => 'S' ),
				'regular_price' => '30',
			)
		);
		$this->ok(
			'kuduy-woo-variation-create',
			array(
				'product_id'    => $parent,
				'attributes'    => array( 'Size' => 'M' ),
				'regular_price' => '35',
			)
		);
		$list = $this->ok( 'kuduy-woo-variation-list', array( 'product_id' => $parent ) );
		$this->assertCount( 2, $list['variations'] );
		// The parent's price range comes back with the store's decimals, so compare amounts.
		$this->assertEquals( 30.0, (float) self::variable( $parent )->get_variation_regular_price( 'min' ) );

		$updated = $this->ok(
			'kuduy-woo-variation-update',
			array(
				'id'            => $small['id'],
				'regular_price' => '25',
			)
		);
		$this->assertSame( '25', wc_get_product( $small['id'] )->get_regular_price() );
		$this->assertEquals( 25.0, (float) self::variable( $parent )->get_variation_regular_price( 'min' ), 'Parent prices synced.' );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $updated['_snapshot_id'] ) );
		$this->assertSame( '30', wc_get_product( $small['id'] )->get_regular_price() );

		$this->fails(
			'kuduy-woo-variation-create',
			array(
				'product_id' => $parent,
				'attributes' => array( 'Size' => 'XL' ),
			),
			'unknown attribute value'
		);
	}

	public function test_stock_operations_and_undo(): void {
		$id = $this->create( array() )['id'];
		$this->fails(
			'kuduy-woo-product-stock',
			array(
				'id'        => $id,
				'operation' => 'set',
				'quantity'  => 5,
			),
			'stock not managed'
		);
		$this->ok(
			'kuduy-woo-product-update',
			array(
				'id'           => $id,
				'manage_stock' => true,
			)
		);
		$this->ok(
			'kuduy-woo-product-stock',
			array(
				'id'        => $id,
				'operation' => 'set',
				'quantity'  => 10,
			)
		);
		$out = $this->ok(
			'kuduy-woo-product-stock',
			array(
				'id'        => $id,
				'operation' => 'decrease',
				'quantity'  => 3,
			)
		);
		$this->assertSame( 7, $out['stock_quantity'] );
		$this->assertSame( 7, wc_get_product( $id )->get_stock_quantity() );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $out['_snapshot_id'] ) );
		$this->assertSame( 10, wc_get_product( $id )->get_stock_quantity() );
	}

	public function test_trash_and_undo_keep_status_and_variations(): void {
		$parent    = $this->create(
			array(
				'type'       => 'variable',
				'status'     => 'publish',
				'attributes' => array(
					array(
						'name'      => 'Color',
						'options'   => array( 'Red' ),
						'variation' => true,
					),
				),
			)
		)['id'];
		$variation = $this->ok(
			'kuduy-woo-variation-create',
			array(
				'product_id' => $parent,
				'attributes' => array( 'Color' => 'Red' ),
			)
		)['id'];
		$trashed   = $this->ok( 'kuduy-woo-product-trash', array( 'id' => $parent ) );
		$this->assertSame( 'trash', get_post_status( $parent ) );
		$this->assertSame( 'trash', get_post_status( $variation ) );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $trashed['_snapshot_id'] ) );
		$this->assertSame( 'publish', get_post_status( $parent ) );
		$this->assertNotSame( 'trash', get_post_status( $variation ) );
	}

	public function test_users_without_product_capabilities_are_refused(): void {
		$id = $this->create( array() )['id'];
		foreach ( array( self::$editor, self::$author ) as $user ) {
			wp_set_current_user( $user );
			$this->fails( 'kuduy-woo-product-list', array(), 'list without product caps' );
			$this->fails(
				'kuduy-woo-product-update',
				array(
					'id'   => $id,
					'name' => 'Nope',
				),
				'update without product caps'
			);
			$this->fails( 'kuduy-woo-product-trash', array( 'id' => $id ), 'trash without product caps' );
		}
		$this->assertSame( 'Kuduy tee', wc_get_product( $id )->get_name() );
	}

	public function test_publishing_needs_publish_products(): void {
		add_role(
			'kuduy_product_writer',
			'Kuduy product writer',
			array(
				'read'                  => true,
				'edit_products'         => true,
				'read_private_products' => true,
			)
		);
		$writer = self::make_user( 'kuduy_product_writer' );
		try {
			wp_set_current_user( $writer );
			$this->fails( 'kuduy-woo-product-create', array( 'name' => 'Nope' ), 'create without publish_products' );
			wp_set_current_user( self::$manager );
			$id = $this->create( array() )['id'];
			wp_update_post(
				array(
					'ID'          => $id,
					'post_author' => $writer,
				)
			);
			wp_set_current_user( $writer );
			$this->fails(
				'kuduy-woo-product-update',
				array(
					'id'     => $id,
					'status' => 'publish',
				),
				'publish without publish_products'
			);
			$this->assertSame( 'draft', wc_get_product( $id )->get_status() );
		} finally {
			remove_role( 'kuduy_product_writer' );
		}
	}
}
