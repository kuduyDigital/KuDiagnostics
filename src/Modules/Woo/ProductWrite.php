<?php
/**
 * Tools: kuduy-woo-product-create, kuduy-woo-product-update, kuduy-woo-product-stock, kuduy-woo-product-trash.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Woo;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Product writes through WooCommerce's CRUD API only (D10): setters + save(), wc_update_product_stock(),
 * WC_Data::delete( false ) for trash. Never a permanent delete (D11). Update, stock and trash keep the previous
 * values for kuduy-snapshot-restore; creates are drafts by default and are undone with kuduy-woo-product-trash.
 */
final class ProductWrite implements Tool, Snapshottable {

	private const TYPES = array(
		'simple'   => \WC_Product_Simple::class,
		'variable' => \WC_Product_Variable::class,
		'grouped'  => \WC_Product_Grouped::class,
		'external' => \WC_Product_External::class,
	);

	/**
	 * Mode.
	 *
	 * @param string $mode create | update | stock | trash.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-woo-product-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'create' => __( 'Create a product', 'kuduy-mcp' ),
			'update' => __( 'Change a product', 'kuduy-mcp' ),
			'stock'  => __( 'Change product stock', 'kuduy-mcp' ),
			default  => __( 'Move a product to the trash', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'create' => 'Creates a WooCommerce product (simple, variable, grouped or external) through WooCommerce\'s own product API. Draft unless status says otherwise (publishing needs the publish_products capability). Prices are strings ("19.90"). For a variable product send attributes with variation: true, then add variations with kuduy-woo-variation-create. Undo: kuduy-woo-product-trash.',
			'update' => 'Changes only the fields you send on a WooCommerce product (same names as kuduy-woo-product-get): name, status, descriptions, SKU, prices and sale dates, tax, shipping class, dimensions, categories, tags, images, attributes (replaces all), visibility, featured, stock settings, upsells and cross-sells. "" clears a price or text. Use kuduy-woo-product-stock for stock quantities and kuduy-woo-variation-update for variations. Snapshot first; undo with kuduy-snapshot-restore.',
			'stock'  => 'Sets, increases or decreases the stock quantity of a product or variation through WooCommerce (wc_update_product_stock), which also updates the stock status. Stock management must be on for it (kuduy-woo-product-update manage_stock: true). Snapshot first; undo with kuduy-snapshot-restore.',
			default  => 'Moves a WooCommerce product (and its variations) to the trash. Never deletes permanently. Undo with kuduy-snapshot-restore, which also restores its previous status.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$id = array(
			'id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		if ( 'stock' === $this->mode ) {
			$properties = array(
				'id'        => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Product or variation id.',
				),
				'operation' => array(
					'type' => 'string',
					'enum' => array( 'set', 'increase', 'decrease' ),
				),
				'quantity'  => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			);
			$required   = array( 'id', 'operation', 'quantity' );
		} elseif ( 'trash' === $this->mode ) {
			$properties = $id;
			$required   = array( 'id' );
		} else {
			$properties               = Woo::product_fields();
			$properties['attributes'] = Woo::attributes_schema();
			if ( 'create' === $this->mode ) {
				$properties = array(
					'type' => array(
						'type'    => 'string',
						'enum'    => array_keys( self::TYPES ),
						'default' => 'simple',
					),
				) + $properties;
				$required   = array( 'name' );
			} else {
				$properties = $id + $properties;
				$required   = array( 'id' );
			}
		}//end if
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		$schema = Woo::summary_schema();
		if ( 'update' === $this->mode ) {
			$schema['properties']['changed'] = array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			);
		}
		$schema['properties']['_snapshot_id'] = array( 'type' => 'integer' );
		return $schema;
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => 'create' !== $this->mode,
			'idempotentHint'  => 'update' === $this->mode || 'trash' === $this->mode,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:write';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		if ( 'create' === $this->mode ) {
			// WooCommerce's create check (wc_rest_check_post_permissions 'create').
			return 'publish_products';
		}
		$id = isset( $input['id'] ) ? Woo::owner_id( (int) $input['id'] ) : 0;
		if ( $id <= 0 || ! in_array( get_post_type( $id ), array( 'product' ), true ) ) {
			return 'edit_products';
		}
		return array( 'trash' === $this->mode ? 'delete_post' : 'edit_post', $id );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return Woo::available();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		$id = (int) ( $input['id'] ?? 0 );
		if ( 'create' === $this->mode || $id <= 0 ) {
			return null;
		}
		if ( 'stock' === $this->mode ) {
			$product = wc_get_product( $id );
			if ( ! $product instanceof \WC_Product || ! $product->managing_stock() ) {
				return null;
			}
			$managed = wc_get_product( $product->get_stock_managed_by_id() );
			if ( ! $managed instanceof \WC_Product ) {
				return null;
			}
			return array(
				'object_type' => 'product',
				'object_id'   => (string) $managed->get_id(),
				'payload'     => array(
					'stock_id' => $managed->get_id(),
					'quantity' => (int) $managed->get_stock_quantity(),
				),
			);
		}
		$product = 'product' === get_post_type( $id ) ? wc_get_product( $id ) : null;
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}
		if ( 'trash' === $this->mode ) {
			return 'trash' === $product->get_status() ? null : array(
				'object_type' => 'product',
				'object_id'   => (string) $id,
				'payload'     => array(
					'trash_id' => $id,
					'status'   => $product->get_status(),
				),
			);
		}
		$keys = array_keys( array_intersect_key( $input, Woo::product_fields() + array( 'attributes' => true ) ) );
		return array(
			'object_type' => 'product',
			'object_id'   => (string) $id,
			'payload'     => array(
				'id'     => $id,
				'fields' => Woo::read( $product, $keys ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the product is gone.
	 */
	public function restore( array $payload ): void {
		if ( isset( $payload['stock_id'] ) ) {
			$product = wc_get_product( (int) $payload['stock_id'] );
			if ( ! $product instanceof \WC_Product ) {
				throw new ToolError( 'The product no longer exists.' );
			}
			wc_update_product_stock( $product, (int) $payload['quantity'], 'set' );
			return;
		}
		if ( isset( $payload['trash_id'] ) ) {
			$id = (int) $payload['trash_id'];
			if ( ! get_post( $id ) ) {
				throw new ToolError( 'The product was deleted permanently, so it cannot be restored.' );
			}
			// WooCommerce's untrashed_post handler brings the variations back too.
			if ( 'trash' === get_post_status( $id ) && ! wp_untrash_post( $id ) ) {
				throw new ToolError( 'WordPress could not take the product out of the trash.' );
			}
			$product = Woo::product( $id );
			$product->set_status( (string) ( $payload['status'] ?? 'draft' ) );
			$product->save();
			return;
		}
		$product = Woo::product( (int) ( $payload['id'] ?? 0 ) );
		Woo::apply( $product, (array) ( $payload['fields'] ?? array() ) );
		$product->save();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the product is missing, the change is not allowed or WooCommerce refuses it.
	 */
	public function execute( array $input ): array {
		return match ( $this->mode ) {
			'create' => $this->create( $input ),
			'update' => $this->update( $input ),
			'stock'  => $this->stock( $input ),
			default  => $this->trash( $input ),
		};
	}

	/**
	 * Fields of the input that the product field map knows.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private static function fields( array $input ): array {
		return array_intersect_key( $input, Woo::product_fields() + array( 'attributes' => true ) );
	}

	/**
	 * Publishing, scheduling or making private needs publish_products (WooCommerce's product update ability rule).
	 *
	 * @param array<string, mixed> $fields Fields.
	 * @throws ToolError When the user may not.
	 */
	private static function assert_status_allowed( array $fields ): void {
		if ( isset( $fields['status'] ) && in_array( $fields['status'], array( 'publish', 'private' ), true ) && ! current_user_can( 'publish_products' ) ) {
			throw new ToolError( 'Your account cannot publish products (publish_products). Nothing was changed.' );
		}
	}

	/**
	 * Create.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When WooCommerce refuses.
	 */
	private function create( array $input ): array {
		Woo::assert_product_permission( 'create' );
		$fields = self::fields( $input ) + array( 'status' => 'draft' );
		self::assert_status_allowed( $fields );
		$class   = self::TYPES[ (string) ( $input['type'] ?? 'simple' ) ];
		$product = new $class();
		Woo::apply( $product, $fields );
		$id = $product->save();
		if ( $id <= 0 ) {
			throw new ToolError( 'WooCommerce did not save the product.' );
		}
		AuditLog::target( 'product', (string) $id );
		return Woo::summary( Woo::product( $id ) );
	}

	/**
	 * Update.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When nothing is sent or WooCommerce refuses.
	 */
	private function update( array $input ): array {
		$product = Woo::product( (int) $input['id'] );
		Woo::assert_product_permission( 'edit', $product->get_id() );
		AuditLog::target( 'product', (string) $product->get_id() );
		$fields = self::fields( $input );
		if ( ! $fields ) {
			throw new ToolError( 'Send at least one field to change.' );
		}
		self::assert_status_allowed( $fields );
		Woo::apply( $product, $fields );
		$product->save();
		return Woo::summary( Woo::product( $product->get_id() ) ) + array( 'changed' => array_keys( $fields ) );
	}

	/**
	 * Stock.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When stock is not managed or the object is not a product.
	 */
	private function stock( array $input ): array {
		$id      = (int) $input['id'];
		$product = in_array( get_post_type( $id ), array( 'product', 'product_variation' ), true ) ? wc_get_product( $id ) : null;
		if ( ! $product instanceof \WC_Product ) {
			throw new ToolError( 'No WooCommerce product or variation with id ' . (int) $id . '.' );
		}
		Woo::assert_product_permission( 'edit', Woo::owner_id( $id ) );
		if ( ! $product->managing_stock() ) {
			throw new ToolError( 'Stock is not managed for this product. Turn it on first with kuduy-woo-product-update (manage_stock: true). Nothing was changed.' );
		}
		AuditLog::target( 'product', (string) $product->get_stock_managed_by_id() );
		$result = wc_update_product_stock( $product, (int) $input['quantity'], (string) $input['operation'] );
		if ( false === $result ) {
			throw new ToolError( 'WooCommerce did not change the stock.' );
		}
		$fresh = wc_get_product( $product->get_stock_managed_by_id() );
		return Woo::summary( $fresh instanceof \WC_Product ? $fresh : $product );
	}

	/**
	 * Trash.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the trash is off or the product is already trashed.
	 */
	private function trash( array $input ): array {
		$product = Woo::product( (int) $input['id'] );
		Woo::assert_product_permission( 'delete', $product->get_id() );
		AuditLog::target( 'product', (string) $product->get_id() );
		if ( 'trash' === $product->get_status() ) {
			throw new ToolError( 'This product is already in the trash.' );
		}
		if ( ! EMPTY_TRASH_DAYS ) {
			throw new ToolError( 'The trash is turned off on this site (EMPTY_TRASH_DAYS is 0), so deleting would be permanent. Not done.' );
		}
		// WC_Data::delete() resets the object's id to 0, so keep it.
		$id = $product->get_id();
		if ( ! $product->delete( false ) ) {
			throw new ToolError( 'WooCommerce did not move the product to the trash.' );
		}
		return Woo::summary( Woo::product( $id ) );
	}
}
