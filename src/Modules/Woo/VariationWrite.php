<?php
/**
 * Tools: kuduy-woo-variation-create, kuduy-woo-variation-update.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Woo;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Variations of variable products through WC_Product_Variation + save(); the parent's prices and stock status are
 * synced with WC_Product_Variable::sync() afterwards. Access follows the parent product (edit_post on it).
 */
final class VariationWrite implements Tool, Snapshottable {

	/**
	 * Mode.
	 *
	 * @param string $mode create | update.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-woo-variation-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'create' === $this->mode ? __( 'Add a product variation', 'kuduy-mcp' ) : __( 'Change a product variation', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'create' === $this->mode
			? 'Adds a variation to a variable WooCommerce product. attributes maps each variation attribute name (as in kuduy-woo-product-get) to one of its options; "" means any value. Refused when a variation with the same attribute values exists. Enabled (status publish) unless status: private. Prices are strings. The parent\'s price range is updated.'
			: 'Changes only the fields you send on a product variation: attribute values, price, sale price and dates, SKU, stock settings, dimensions, image, description, enabled (status publish) or disabled (private). Use kuduy-woo-product-stock for stock quantities. Snapshot first; undo with kuduy-snapshot-restore.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$attributes = array(
			'type'                 => 'object',
			'additionalProperties' => array( 'type' => 'string' ),
		);
		$properties = Woo::variation_fields() + array( 'attributes' => $attributes );
		if ( 'create' === $this->mode ) {
			$properties = array(
				'product_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			) + $properties;
			$required   = array( 'product_id', 'attributes' );
		} else {
			$properties = array(
				'id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			) + $properties;
			$required   = array( 'id' );
		}
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
		$schema                             = Woo::summary_schema();
		$schema['properties']['attributes'] = array(
			'type'                 => 'object',
			'additionalProperties' => array( 'type' => 'string' ),
		);
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
			'destructiveHint' => 'update' === $this->mode,
			'idempotentHint'  => 'update' === $this->mode,
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
		$parent = 'create' === $this->mode ? (int) ( $input['product_id'] ?? 0 ) : Woo::owner_id( (int) ( $input['id'] ?? 0 ) );
		return $parent > 0 && 'product' === get_post_type( $parent ) ? array( 'edit_post', $parent ) : 'edit_products';
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
		if ( 'update' !== $this->mode || 'product_variation' !== get_post_type( $id ) ) {
			return null;
		}
		$variation = Woo::variation( $id );
		$keys      = array_keys( array_intersect_key( $input, Woo::variation_fields() + array( 'attributes' => true ) ) );
		return array(
			'object_type' => 'product',
			'object_id'   => (string) $id,
			'payload'     => array(
				'id'     => $id,
				'fields' => Woo::read( $variation, $keys ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 */
	public function restore( array $payload ): void {
		$variation = Woo::variation( (int) ( $payload['id'] ?? 0 ) );
		Woo::apply( $variation, (array) ( $payload['fields'] ?? array() ) );
		$variation->save();
		\WC_Product_Variable::sync( $variation->get_parent_id() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the product is not variable, the attributes do not match or WooCommerce refuses.
	 */
	public function execute( array $input ): array {
		$fields = array_intersect_key( $input, Woo::variation_fields() + array( 'attributes' => true ) );
		if ( 'create' === $this->mode ) {
			$parent = Woo::product( (int) $input['product_id'] );
			Woo::assert_product_permission( 'edit', $parent->get_id() );
			if ( ! $parent instanceof \WC_Product_Variable ) {
				throw new ToolError( 'Variations belong to variable products; this product is ' . esc_html( $parent->get_type() ) . '.' );
			}
			$wanted = Woo::variation_attribute_values( $parent->get_id(), (array) $input['attributes'] );
			foreach ( $parent->get_children() as $child ) {
				$existing = wc_get_product( $child );
				if ( $existing instanceof \WC_Product_Variation && $existing->get_attributes( 'edit' ) == $wanted ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- same keys and values in any order.
					throw new ToolError( 'Variation ' . (int) $existing->get_id() . ' already has these attribute values. Change it with kuduy-woo-variation-update.' );
				}
			}
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$fields += array( 'status' => 'publish' );
		} else {
			$variation = Woo::variation( (int) $input['id'] );
			Woo::assert_product_permission( 'edit', $variation->get_parent_id() );
			if ( ! $fields ) {
				throw new ToolError( 'Send at least one field to change.' );
			}
		}//end if
		Woo::apply( $variation, $fields );
		$id = $variation->save();
		if ( $id <= 0 ) {
			throw new ToolError( 'WooCommerce did not save the variation.' );
		}
		\WC_Product_Variable::sync( $variation->get_parent_id() );
		AuditLog::target( 'product', (string) $id );
		$fresh = Woo::variation( $id );
		$out   = Woo::summary( $fresh ) + array( 'attributes' => Woo::variation_attributes( $fresh ) );
		if ( 'update' === $this->mode ) {
			$out['changed'] = array_keys( $fields );
		}
		return $out;
	}
}
