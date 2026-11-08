<?php
/**
 * Tools: kuduy-woo-product-list, kuduy-woo-product-get, kuduy-woo-variation-list.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Woo;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Products read through wc_get_products() / wc_get_product() with WooCommerce's own read permission
 * (`wc_rest_check_post_permissions( 'product', 'read' )` = read_private_products).
 */
final class ProductRead implements Tool {

	/**
	 * Mode.
	 *
	 * @param string $mode list | get | variations.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'variations' === $this->mode ? 'kuduy-woo-variation-list' : 'kuduy-woo-product-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'list'  => __( 'List products', 'kuduy-mcp' ),
			'get'   => __( 'Read a product', 'kuduy-mcp' ),
			default => __( 'List a product\'s variations', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'list'  => 'Lists WooCommerce products with price, sale, stock and status. Filters: status (default any), type, category or tag (slug), sku (partial match), stock_status, on_sale, featured. Paged.',
			'get'   => 'Returns one WooCommerce product with every field kuduy-woo-product-update can change (same names), its attributes, variation ids (variable products), categories, images, ratings and downloads (file names; file URLs only for users who can edit the product).',
			default => 'Lists the variations of a variable product: id, attribute values, price, sale, stock and enabled state.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$properties = match ( $this->mode ) {
			'list'  => array(
				'status'       => array(
					'type'    => 'string',
					'enum'    => array( 'any', 'draft', 'pending', 'private', 'publish', 'trash' ),
					'default' => 'any',
				),
				'type'         => array(
					'type' => 'string',
					'enum' => array( 'simple', 'variable', 'grouped', 'external' ),
				),
				'category'     => array( 'type' => 'string' ),
				'tag'          => array( 'type' => 'string' ),
				'sku'          => array( 'type' => 'string' ),
				'stock_status' => array(
					'type' => 'string',
					'enum' => array( 'instock', 'outofstock', 'onbackorder' ),
				),
				'on_sale'      => array( 'type' => 'boolean' ),
				'featured'     => array( 'type' => 'boolean' ),
			) + Content::paging_input(),
			'get'   => array(
				'id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			default => array(
				'product_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
		};//end match
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => match ( $this->mode ) {
				'list'  => array(),
				'get'   => array( 'id' ),
				default => array( 'product_id' ),
			},
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		$summary = Woo::summary_schema();
		if ( 'list' === $this->mode ) {
			return array(
				'type'       => 'object',
				'properties' => array(
					'products'    => array(
						'type'  => 'array',
						'items' => $summary,
					),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
					'page'        => array( 'type' => 'integer' ),
					'per_page'    => array( 'type' => 'integer' ),
				),
				'required'   => array( 'products', 'total', 'total_pages', 'page', 'per_page' ),
			);
		}
		if ( 'variations' === $this->mode ) {
			$item                             = $summary;
			$item['properties']['attributes'] = array(
				'type'                 => 'object',
				'additionalProperties' => array( 'type' => 'string' ),
			);
			$item['properties']['enabled']    = array( 'type' => 'boolean' );
			return array(
				'type'       => 'object',
				'properties' => array(
					'product_id' => array( 'type' => 'integer' ),
					'variations' => array(
						'type'  => 'array',
						'items' => $item,
					),
				),
				'required'   => array( 'product_id', 'variations' ),
			);
		}
		$schema                = $summary;
		$schema['properties'] += Woo::product_fields();
		$schema['properties'] += array(
			'attributes'     => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'variation_ids'  => array(
				'type'  => 'array',
				'items' => array( 'type' => 'integer' ),
			),
			'categories'     => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'average_rating' => array( 'type' => 'string' ),
			'review_count'   => array( 'type' => 'integer' ),
			'total_sales'    => array( 'type' => 'integer' ),
			'downloads'      => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'date_created'   => array( 'type' => array( 'string', 'null' ) ),
			'date_modified'  => array( 'type' => array( 'string', 'null' ) ),
		);
		// Values read back are not limited like inputs (e.g. an empty name on an old product).
		foreach ( $schema['properties'] as $key => $property ) {
			unset( $schema['properties'][ $key ]['minLength'], $schema['properties'][ $key ]['pattern'], $schema['properties'][ $key ]['format'], $schema['properties'][ $key ]['enum'] );
		}
		return $schema;
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return Content::read_only();
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:read';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'read_private_products';
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
	public function execute( array $input ): array {
		if ( 'list' === $this->mode ) {
			Woo::assert_product_permission( 'read' );
			return $this->items( $input );
		}
		$product = Woo::product( (int) ( $input['id'] ?? $input['product_id'] ) );
		Woo::assert_product_permission( 'read', $product->get_id() );
		AuditLog::target( 'post', (string) $product->get_id() );
		if ( 'variations' === $this->mode ) {
			$out = array();
			foreach ( $product->get_children() as $child ) {
				$variation = wc_get_product( $child );
				if ( $variation instanceof \WC_Product_Variation ) {
					$out[] = Woo::summary( $variation ) + array(
						'attributes' => Woo::variation_attributes( $variation ),
						'enabled'    => 'publish' === $variation->get_status(),
					);
				}
			}
			return array(
				'product_id' => $product->get_id(),
				'variations' => $out,
			);
		}
		return $this->detail( $product );
	}

	/**
	 * A page of products.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private function items( array $input ): array {
		$per_page = (int) ( $input['per_page'] ?? 20 );
		$page     = (int) ( $input['page'] ?? 1 );
		$status   = (string) ( $input['status'] ?? 'any' );
		$args     = array(
			'status'   => 'any' === $status ? array( 'draft', 'pending', 'private', 'publish' ) : $status,
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
			'orderby'  => 'date',
			'order'    => 'DESC',
		);
		foreach ( array( 'type', 'sku', 'stock_status' ) as $key ) {
			if ( isset( $input[ $key ] ) && '' !== $input[ $key ] ) {
				$args[ $key ] = $input[ $key ];
			}
		}
		if ( ! empty( $input['category'] ) ) {
			$args['category'] = array( (string) $input['category'] );
		}
		if ( ! empty( $input['tag'] ) ) {
			$args['tag'] = array( (string) $input['tag'] );
		}
		if ( isset( $input['featured'] ) ) {
			$args['featured'] = (bool) $input['featured'];
		}
		if ( isset( $input['on_sale'] ) ) {
			$sale = array_merge( array( 0 ), wc_get_product_ids_on_sale() );
			$args[ $input['on_sale'] ? 'include' : 'exclude' ] = $sale;
		}
		$result = wc_get_products( $args );
		return array(
			'products'    => array_values( array_map( array( Woo::class, 'summary' ), (array) $result->products ) ),
			'total'       => (int) $result->total,
			'total_pages' => (int) $result->max_num_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Every editable field plus read-only facts.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<string, mixed>
	 */
	private function detail( \WC_Product $product ): array {
		$can_edit  = current_user_can( 'edit_post', $product->get_id() );
		$downloads = array();
		foreach ( $product->get_downloads( 'edit' ) as $id => $download ) {
			$downloads[] = array(
				'id'   => (string) $id,
				'name' => $download->get_name(),
				'file' => $can_edit ? $download->get_file() : null,
			);
		}
		$categories = array();
		foreach ( $product->get_category_ids( 'edit' ) as $term_id ) {
			$term = get_term( (int) $term_id, 'product_cat' );
			if ( $term instanceof \WP_Term ) {
				$categories[] = array(
					'id'   => $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				);
			}
		}
		$created  = $product->get_date_created( 'edit' );
		$modified = $product->get_date_modified( 'edit' );
		return Woo::summary( $product )
			+ Woo::read( $product, array_keys( Woo::product_fields() ) )
			+ array(
				'attributes'     => Woo::attributes( $product ),
				'variation_ids'  => $product instanceof \WC_Product_Variable ? array_map( 'intval', $product->get_children() ) : array(),
				'categories'     => $categories,
				'average_rating' => (string) $product->get_average_rating( 'edit' ),
				'review_count'   => (int) $product->get_review_count( 'edit' ),
				'total_sales'    => (int) $product->get_total_sales( 'edit' ),
				'downloads'      => $downloads,
				'date_created'   => $created ? $created->date( 'c' ) : null,
				'date_modified'  => $modified ? $modified->date( 'c' ) : null,
			);
	}
}
