<?php
/**
 * Shared rules for content tools: which post types and taxonomies they may touch, and the post shape they return.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Only types and taxonomies with an admin UI (`show_ui`): internal ones (revisions, changesets, privacy requests,
 * Woo orders) have their own rules and get their own tools.
 */
final class Content {

	/**
	 * Post types the content tools work with.
	 *
	 * @return array<string, \WP_Post_Type>
	 */
	public static function post_types(): array {
		$types = get_post_types( array( 'show_ui' => true ), 'objects' );
		// Attachments get media tools with image SEO (Phase 6).
		unset( $types['attachment'] );
		return $types;
	}

	/**
	 * A post type the tools may use, or a ToolError naming the valid ones.
	 *
	 * @param string $name Post type.
	 * @throws ToolError When it is not a content post type.
	 */
	public static function post_type( string $name ): \WP_Post_Type {
		$types = self::post_types();
		if ( ! isset( $types[ $name ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( 'Unknown post type "' . $name . '". Use one of: ' . implode( ', ', array_keys( $types ) ) . '.' );
		}
		return $types[ $name ];
	}

	/**
	 * WooCommerce post types and the KuDiagnostics tools that write them through WooCommerce's CRUD API.
	 */
	private const WOO_TYPES = array(
		'product'              => 'kuduy-woo-product-*',
		'product_variation'    => 'kuduy-woo-variation-*',
		'shop_order'           => 'kuduy-woo-order-*',
		'shop_order_refund'    => 'kuduy-woo-order-*',
		'shop_order_placehold' => 'kuduy-woo-order-*',
		'shop_coupon'          => 'kuduy-woo-coupon-*',
	);

	/**
	 * Refuse writes to WooCommerce objects while WooCommerce is active: wp_update_post() and raw meta writes skip
	 * its lookup tables, caches and hooks (RESEARCH.md §10, D10). Reads stay allowed.
	 *
	 * @param string $type Post type.
	 * @throws ToolError When the type belongs to WooCommerce.
	 */
	public static function assert_writable( string $type ): void {
		if ( defined( 'WC_VERSION' ) && isset( self::WOO_TYPES[ $type ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( 'WooCommerce ' . $type . ' objects are changed through WooCommerce, not as plain posts. Use the ' . self::WOO_TYPES[ $type ] . ' tools.' );
		}
	}

	/**
	 * Taxonomies the content tools work with.
	 *
	 * @return array<string, \WP_Taxonomy>
	 */
	public static function taxonomies(): array {
		return get_taxonomies( array( 'show_ui' => true ), 'objects' );
	}

	/**
	 * A post of a content post type, or null.
	 *
	 * @param int $id Post id.
	 */
	public static function post( int $id ): ?\WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;
		return $post instanceof \WP_Post && isset( self::post_types()[ $post->post_type ] ) ? $post : null;
	}

	/**
	 * Short form of a post for lists. Never includes the post password.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	public static function summary( \WP_Post $post ): array {
		return array(
			'id'                 => $post->ID,
			'type'               => $post->post_type,
			'status'             => $post->post_status,
			'title'              => $post->post_title,
			'slug'               => $post->post_name,
			'author'             => (int) $post->post_author,
			'parent'             => $post->post_parent,
			'date_gmt'           => $post->post_date_gmt,
			'modified_gmt'       => $post->post_modified_gmt,
			'link'               => (string) get_permalink( $post ),
			'password_protected' => '' !== $post->post_password,
		);
	}

	/**
	 * JSON Schema of summary().
	 *
	 * @return array<string, mixed>
	 */
	public static function summary_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'                 => array( 'type' => 'integer' ),
				'type'               => array( 'type' => 'string' ),
				'status'             => array( 'type' => 'string' ),
				'title'              => array( 'type' => 'string' ),
				'slug'               => array( 'type' => 'string' ),
				'author'             => array( 'type' => 'integer' ),
				'parent'             => array( 'type' => 'integer' ),
				'date_gmt'           => array( 'type' => 'string' ),
				'modified_gmt'       => array( 'type' => 'string' ),
				'link'               => array( 'type' => 'string' ),
				'password_protected' => array( 'type' => 'boolean' ),
			),
			'required'   => array( 'id', 'type', 'status', 'title', 'slug', 'author', 'link' ),
		);
	}

	/**
	 * Read-only annotations shared by the read tools.
	 *
	 * @return array<string, bool>
	 */
	public static function read_only(): array {
		return array(
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		);
	}

	/**
	 * Paging fields shared by list tools.
	 *
	 * @return array<string, mixed>
	 */
	public static function paging_input(): array {
		return array(
			'per_page' => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
				'default' => 20,
			),
			'page'     => array(
				'type'    => 'integer',
				'minimum' => 1,
				'default' => 1,
			),
		);
	}
}
