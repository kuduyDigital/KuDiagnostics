<?php
/**
 * Shared WooCommerce rules (RESEARCH.md §10, D10): objects only through WooCommerce's CRUD API, permission checks
 * through WooCommerce's own REST permission functions, one field map for reading, writing and undo.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Woo;

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Field map and helpers for the kuduy-woo-* tools.
 */
final class Woo {

	public const PRICE = '^(\d+(\.\d+)?)?$';

	/**
	 * Editable product fields: name => JSON Schema. Read with `get_{name}( 'edit' )`, written with `set_{name}()`.
	 * Fields only some product types have (external, grouped) are checked against the object's methods.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function product_fields(): array {
		$text  = array( 'type' => 'string' );
		$ids   = array(
			'type'  => 'array',
			'items' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		$price = array(
			'type'    => 'string',
			'pattern' => self::PRICE,
		);
		$date  = array(
			'type'        => array( 'string', 'null' ),
			'description' => 'Date (YYYY-MM-DD or ISO 8601); null removes it.',
		);
		$dim   = array(
			'type'    => 'string',
			'pattern' => self::PRICE,
		);
		return array(
			'name'               => $text + array( 'minLength' => 1 ),
			'slug'               => $text,
			'status'             => array(
				'type' => 'string',
				'enum' => array( 'draft', 'pending', 'private', 'publish' ),
			),
			'description'        => $text,
			'short_description'  => $text,
			'sku'                => $text,
			'regular_price'      => $price,
			'sale_price'         => $price,
			'date_on_sale_from'  => $date,
			'date_on_sale_to'    => $date,
			'tax_status'         => array(
				'type' => 'string',
				'enum' => array( 'taxable', 'shipping', 'none' ),
			),
			'tax_class'          => $text,
			'shipping_class_id'  => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'weight'             => $dim,
			'length'             => $dim,
			'width'              => $dim,
			'height'             => $dim,
			'category_ids'       => $ids,
			'tag_ids'            => $ids,
			'image_id'           => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'gallery_image_ids'  => $ids,
			'catalog_visibility' => array(
				'type' => 'string',
				'enum' => array( 'visible', 'catalog', 'search', 'hidden' ),
			),
			'featured'           => array( 'type' => 'boolean' ),
			'manage_stock'       => array( 'type' => 'boolean' ),
			'stock_status'       => array(
				'type' => 'string',
				'enum' => array( 'instock', 'outofstock', 'onbackorder' ),
			),
			'backorders'         => array(
				'type' => 'string',
				'enum' => array( 'no', 'notify', 'yes' ),
			),
			'low_stock_amount'   => array(
				'type'    => array( 'integer', 'null' ),
				'minimum' => 0,
			),
			'sold_individually'  => array( 'type' => 'boolean' ),
			'virtual'            => array( 'type' => 'boolean' ),
			'reviews_allowed'    => array( 'type' => 'boolean' ),
			'upsell_ids'         => $ids,
			'cross_sell_ids'     => $ids,
			'purchase_note'      => $text,
			'menu_order'         => array( 'type' => 'integer' ),
			'product_url'        => $text + array( 'format' => 'uri' ),
			'button_text'        => $text,
			'children'           => $ids,
		);
	}

	/**
	 * Editable variation fields (a subset of the product fields; `status` publish = enabled, private = disabled).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function variation_fields(): array {
		$fields           = array_intersect_key(
			self::product_fields(),
			array_flip( array( 'description', 'sku', 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to', 'tax_class', 'shipping_class_id', 'weight', 'length', 'width', 'height', 'image_id', 'manage_stock', 'stock_status', 'backorders', 'low_stock_amount', 'virtual', 'menu_order' ) )
		);
		$fields['status'] = array(
			'type'        => 'string',
			'enum'        => array( 'publish', 'private' ),
			'description' => 'publish = enabled, private = disabled.',
		);
		return $fields;
	}

	/**
	 * Attribute list schema for products.
	 *
	 * @return array<string, mixed>
	 */
	public static function attributes_schema(): array {
		return array(
			'type'        => 'array',
			'description' => 'Replaces all attributes. A global attribute is named by its taxonomy (e.g. "pa_color") and its options must be existing terms (name or slug); any other name is a custom attribute with free text options.',
			'items'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'name'      => array(
						'type'      => 'string',
						'minLength' => 1,
					),
					'options'   => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'string' ),
						'minItems' => 1,
					),
					'visible'   => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'variation' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'required'             => array( 'name', 'options' ),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * A product (not a variation), or a ToolError.
	 *
	 * @param int $id Product id.
	 * @throws ToolError When there is no such product.
	 */
	public static function product( int $id ): \WC_Product {
		$product = $id > 0 && 'product' === get_post_type( $id ) ? wc_get_product( $id ) : null;
		if ( ! $product instanceof \WC_Product ) {
			throw new ToolError( 'No WooCommerce product with id ' . (int) $id . '.' );
		}
		return $product;
	}

	/**
	 * A variation, or a ToolError.
	 *
	 * @param int $id Variation id.
	 * @throws ToolError When there is no such variation.
	 */
	public static function variation( int $id ): \WC_Product_Variation {
		$variation = $id > 0 && 'product_variation' === get_post_type( $id ) ? wc_get_product( $id ) : null;
		if ( ! $variation instanceof \WC_Product_Variation ) {
			throw new ToolError( 'No product variation with id ' . (int) $id . '.' );
		}
		return $variation;
	}

	/**
	 * Post id whose `edit_post` / `delete_post` decides access: the parent product for a variation.
	 *
	 * @param int $id Product or variation id.
	 */
	public static function owner_id( int $id ): int {
		return 'product_variation' === get_post_type( $id ) ? (int) wp_get_post_parent_id( $id ) : $id;
	}

	/**
	 * WooCommerce's own REST permission check for products (keeps the `woocommerce_rest_check_permissions` filter).
	 *
	 * @param string $context read | create | edit | delete.
	 * @param int    $id      Product id (0 for collection checks).
	 * @throws ToolError When WooCommerce refuses.
	 */
	public static function assert_product_permission( string $context, int $id = 0 ): void {
		if ( ! wc_rest_check_post_permissions( 'product', $context, $id ) ) {
			throw new ToolError( 'WooCommerce does not allow your account to ' . esc_html( $context ) . ' this product.' );
		}
	}

	/**
	 * An order (not a refund), from either storage (HPOS or posts), or a ToolError.
	 *
	 * @param int $id Order id.
	 * @throws ToolError When there is no such order.
	 */
	public static function order( int $id ): \WC_Order {
		$order = $id > 0 ? wc_get_order( $id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			throw new ToolError( 'No WooCommerce order with id ' . (int) $id . '.' );
		}
		return $order;
	}

	/**
	 * WooCommerce's own REST permission check for orders (keeps the `woocommerce_rest_check_permissions` filter).
	 * Under HPOS without sync WooCommerce maps edit_post / read_post for order ids itself (HposOrderCapabilityHelper).
	 *
	 * @param string $context read | edit.
	 * @param int    $id      Order id (0 for collection checks).
	 * @throws ToolError When WooCommerce refuses.
	 */
	public static function assert_order_permission( string $context, int $id = 0 ): void {
		if ( ! wc_rest_check_post_permissions( 'shop_order', $context, $id ) ) {
			throw new ToolError( 'WooCommerce does not allow your account to ' . esc_html( $context ) . ' this order.' );
		}
	}

	/**
	 * Current values of the given fields, in the same shape the tools accept.
	 *
	 * @param \WC_Product   $product Product or variation.
	 * @param array<string> $keys    Field names (unknown names are skipped).
	 * @return array<string, mixed>
	 */
	public static function read( \WC_Product $product, array $keys ): array {
		$out = array();
		foreach ( $keys as $key ) {
			if ( 'attributes' === $key ) {
				$out['attributes'] = $product instanceof \WC_Product_Variation ? self::variation_attributes( $product ) : self::attributes( $product );
				continue;
			}
			$getter = 'get_' . $key;
			if ( ! method_exists( $product, $getter ) ) {
				continue;
			}
			$value = $product->$getter( 'edit' );
			if ( $value instanceof \WC_DateTime ) {
				$value = $value->date( 'c' );
			} elseif ( in_array( $key, array( 'image_id', 'shipping_class_id', 'menu_order' ), true ) ) {
				$value = (int) $value;
			} elseif ( 'low_stock_amount' === $key ) {
				$value = '' === $value || null === $value ? null : (int) $value;
			} elseif ( is_array( $value ) ) {
				$value = array_values( array_map( 'intval', $value ) );
			}
			$out[ $key ] = $value;
		}//end foreach
		return $out;
	}

	/**
	 * Apply fields to a product or variation (not saved). Every value is set before anything is saved, so a refusal
	 * leaves the stored object unchanged.
	 *
	 * @param \WC_Product          $product Product or variation.
	 * @param array<string, mixed> $fields  Fields.
	 * @throws ToolError When a field does not apply to this product type or WooCommerce rejects a value.
	 */
	public static function apply( \WC_Product $product, array $fields ): void {
		try {
			foreach ( $fields as $key => $value ) {
				if ( 'attributes' === $key ) {
					if ( $product instanceof \WC_Product_Variation ) {
						$product->set_attributes( self::variation_attribute_values( $product->get_parent_id(), (array) $value ) );
					} else {
						$product->set_attributes( self::build_attributes( (array) $value ) );
					}
					continue;
				}
				$setter = 'set_' . $key;
				if ( ! method_exists( $product, $setter ) || ( in_array( $key, array( 'product_url', 'button_text', 'children' ), true ) && ! method_exists( $product, 'get_' . $key ) ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
					throw new ToolError( '"' . $key . '" does not apply to ' . $product->get_type() . ' products. Nothing was changed.' );
				}
				if ( in_array( $key, array( 'regular_price', 'sale_price', 'weight', 'length', 'width', 'height' ), true ) && '' !== $value ) {
					$value = wc_format_decimal( (string) $value );
				}
				if ( 'low_stock_amount' === $key && null === $value ) {
					$value = '';
				}
				if ( 'category_ids' === $key || 'tag_ids' === $key ) {
					self::assert_terms( (array) $value, 'category_ids' === $key ? 'product_cat' : 'product_tag' );
				}
				$product->$setter( $value );
			}//end foreach
		} catch ( \WC_Data_Exception $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( 'WooCommerce refused the value: ' . wp_strip_all_tags( $e->getMessage() ) . ' Nothing was changed.' );
		}//end try
	}

	/**
	 * Every term id must exist in the taxonomy.
	 *
	 * @param array<mixed> $ids      Term ids.
	 * @param string       $taxonomy Taxonomy.
	 * @throws ToolError When one does not.
	 */
	private static function assert_terms( array $ids, string $taxonomy ): void {
		foreach ( $ids as $id ) {
			if ( ! term_exists( (int) $id, $taxonomy ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
				throw new ToolError( 'Term ' . (int) $id . ' does not exist in ' . $taxonomy . '. Nothing was changed.' );
			}
		}
	}

	/**
	 * Attributes of a product in the tool shape.
	 *
	 * @param \WC_Product $product Product.
	 * @return list<array<string, mixed>>
	 */
	public static function attributes( \WC_Product $product ): array {
		$out = array();
		foreach ( $product->get_attributes( 'edit' ) as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}
			$options = $attribute->get_options();
			if ( $attribute->is_taxonomy() ) {
				$options = array_values(
					array_filter(
						array_map(
							static function ( $term_id ) use ( $attribute ) {
								$term = get_term( (int) $term_id, $attribute->get_name() );
								return $term instanceof \WP_Term ? $term->name : null;
							},
							$options
						)
					)
				);
			}
			$out[] = array(
				'name'      => $attribute->get_name(),
				'options'   => array_values( array_map( 'strval', $options ) ),
				'visible'   => $attribute->get_visible(),
				'variation' => $attribute->get_variation(),
			);
		}//end foreach
		return $out;
	}

	/**
	 * WC_Product_Attribute objects from the tool shape.
	 *
	 * @param array<mixed> $items Attributes.
	 * @return list<\WC_Product_Attribute>
	 * @throws ToolError When a global attribute term does not exist.
	 */
	private static function build_attributes( array $items ): array {
		$out = array();
		foreach ( array_values( $items ) as $position => $item ) {
			$item      = (array) $item;
			$name      = (string) $item['name'];
			$options   = array_map( 'strval', (array) $item['options'] );
			$attribute = new \WC_Product_Attribute();
			if ( str_starts_with( $name, 'pa_' ) && taxonomy_exists( $name ) ) {
				$attribute->set_id( wc_attribute_taxonomy_id_by_name( $name ) );
				$terms = array();
				foreach ( $options as $option ) {
					$term = get_term_by( 'name', $option, $name );
					$term = $term instanceof \WP_Term ? $term : get_term_by( 'slug', $option, $name );
					if ( ! $term instanceof \WP_Term ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
						throw new ToolError( '"' . $option . '" is not a term of ' . $name . '. Nothing was changed.' );
					}
					$terms[] = $term->term_id;
				}
				$options = $terms;
			} else {
				$attribute->set_id( 0 );
			}
			$attribute->set_name( $name );
			$attribute->set_options( $options );
			$attribute->set_position( $position );
			$attribute->set_visible( (bool) ( $item['visible'] ?? true ) );
			$attribute->set_variation( (bool) ( $item['variation'] ?? false ) );
			$out[] = $attribute;
		}//end foreach
		return $out;
	}

	/**
	 * A variation's attribute values keyed by the parent's attribute names ("" = any value).
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 * @return array<string, string>
	 */
	public static function variation_attributes( \WC_Product_Variation $variation ): array {
		$parent = wc_get_product( $variation->get_parent_id() );
		$names  = array();
		if ( $parent instanceof \WC_Product ) {
			foreach ( $parent->get_attributes( 'edit' ) as $key => $attribute ) {
				if ( $attribute instanceof \WC_Product_Attribute ) {
					$names[ (string) $key ] = $attribute;
				}
			}
		}
		$out = array();
		foreach ( $variation->get_attributes( 'edit' ) as $key => $value ) {
			$attribute = $names[ (string) $key ] ?? null;
			$label     = $attribute ? $attribute->get_name() : (string) $key;
			if ( $attribute && $attribute->is_taxonomy() && '' !== $value ) {
				$term  = get_term_by( 'slug', (string) $value, $attribute->get_name() );
				$value = $term instanceof \WP_Term ? $term->name : $value;
			}
			$out[ $label ] = (string) $value;
		}
		return $out;
	}

	/**
	 * Variation attribute values (name => option) checked against the parent's variation attributes.
	 *
	 * @param int          $parent_id Parent product.
	 * @param array<mixed> $values    Name => option ("" = any).
	 * @return array<string, string> Attribute key => stored value (term slug for global attributes).
	 * @throws ToolError When a name or option is not one of the parent's.
	 */
	public static function variation_attribute_values( int $parent_id, array $values ): array {
		$parent = wc_get_product( $parent_id );
		if ( ! $parent instanceof \WC_Product_Variable ) {
			throw new ToolError( 'Variations belong to variable products; product ' . (int) $parent_id . ' is not one.' );
		}
		$allowed = array();
		foreach ( $parent->get_attributes( 'edit' ) as $key => $attribute ) {
			if ( $attribute instanceof \WC_Product_Attribute && $attribute->get_variation() ) {
				$allowed[ $attribute->get_name() ] = array( (string) $key, $attribute );
			}
		}
		$out = array();
		foreach ( $values as $name => $option ) {
			$name   = (string) $name;
			$option = (string) $option;
			if ( ! isset( $allowed[ $name ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
				throw new ToolError( '"' . $name . '" is not a variation attribute of the product. Use one of: ' . implode( ', ', array_keys( $allowed ) ) . '. Nothing was changed.' );
			}
			[ $key, $attribute ] = $allowed[ $name ];
			if ( '' === $option ) {
				$out[ $key ] = '';
				continue;
			}
			$options = self::attributes_option_names( $attribute );
			$match   = array_search( strtolower( $option ), array_map( 'strtolower', array_keys( $options ) ), true );
			if ( false === $match ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
				throw new ToolError( '"' . $option . '" is not an option of ' . $name . '. Use one of: ' . implode( ', ', array_keys( $options ) ) . '. Nothing was changed.' );
			}
			$out[ $key ] = array_values( $options )[ $match ];
		}
		return $out;
	}

	/**
	 * Option display name => stored value for one attribute.
	 *
	 * @param \WC_Product_Attribute $attribute Attribute.
	 * @return array<string, string>
	 */
	private static function attributes_option_names( \WC_Product_Attribute $attribute ): array {
		$out = array();
		foreach ( $attribute->get_options() as $option ) {
			if ( $attribute->is_taxonomy() ) {
				$term = get_term( (int) $option, $attribute->get_name() );
				if ( $term instanceof \WP_Term ) {
					$out[ $term->name ] = $term->slug;
				}
			} else {
				$out[ (string) $option ] = (string) $option;
			}
		}
		return $out;
	}

	/**
	 * Short form of a product for lists and write results.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return array<string, mixed>
	 */
	public static function summary( \WC_Product $product ): array {
		return array(
			'id'             => $product->get_id(),
			'type'           => $product->get_type(),
			'name'           => $product->get_name(),
			'status'         => $product->get_status(),
			'sku'            => $product->get_sku(),
			'price'          => (string) $product->get_price(),
			'regular_price'  => (string) $product->get_regular_price(),
			'sale_price'     => (string) $product->get_sale_price(),
			'on_sale'        => $product->is_on_sale( 'edit' ),
			'stock_status'   => $product->get_stock_status(),
			'manage_stock'   => (bool) $product->get_manage_stock(),
			'stock_quantity' => null === $product->get_stock_quantity() ? null : (int) $product->get_stock_quantity(),
			'parent_id'      => $product->get_parent_id(),
			'permalink'      => (string) get_permalink( $product->get_id() ),
		);
	}

	/**
	 * Schema of summary().
	 *
	 * @return array<string, mixed>
	 */
	public static function summary_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'             => array( 'type' => 'integer' ),
				'type'           => array( 'type' => 'string' ),
				'name'           => array( 'type' => 'string' ),
				'status'         => array( 'type' => 'string' ),
				'sku'            => array( 'type' => 'string' ),
				'price'          => array( 'type' => 'string' ),
				'regular_price'  => array( 'type' => 'string' ),
				'sale_price'     => array( 'type' => 'string' ),
				'on_sale'        => array( 'type' => 'boolean' ),
				'stock_status'   => array( 'type' => 'string' ),
				'manage_stock'   => array( 'type' => 'boolean' ),
				'stock_quantity' => array( 'type' => array( 'integer', 'null' ) ),
				'parent_id'      => array( 'type' => 'integer' ),
				'permalink'      => array( 'type' => 'string' ),
			),
			'required'   => array( 'id', 'type', 'name', 'status' ),
		);
	}

	/**
	 * Tool availability.
	 */
	public static function available(): bool|string {
		return defined( 'WC_VERSION' ) && function_exists( 'wc_get_product' ) ? true : 'WooCommerce is not active';
	}
}
