<?php
/**
 * Shared write rules for post tools: fields, status checks, terms, featured image, snapshot and restore.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Avada\Avada;
use Kuduy\Mcp\Modules\Beaver\Beaver;
use Kuduy\Mcp\Modules\WpBakery\WpBakery;

/**
 * Writes go through wp_insert_post() / wp_update_post(), so core's kses (for users without unfiltered_html),
 * revisions and hooks all apply.
 */
final class PostWriter {

	private const STATUSES = array( 'draft', 'pending', 'publish', 'future', 'private' );

	/**
	 * Editable fields (JSON Schema properties).
	 *
	 * @return array<string, mixed>
	 */
	public static function fields(): array {
		return array(
			'title'          => array( 'type' => 'string' ),
			'content'        => array(
				'type'        => 'string',
				'description' => 'Raw content: block markup or HTML. Script and unsafe HTML are removed for users without the unfiltered_html capability.',
			),
			'excerpt'        => array( 'type' => 'string' ),
			'status'         => array(
				'type' => 'string',
				'enum' => self::STATUSES,
			),
			'slug'           => array(
				'type'      => 'string',
				'maxLength' => 200,
			),
			'date_gmt'       => array(
				'type'        => 'string',
				'format'      => 'date-time',
				'description' => 'Publish date in UTC (RFC 3339). Required for status "future", and must then be in the future.',
			),
			'parent'         => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'menu_order'     => array( 'type' => 'integer' ),
			'comment_status' => array(
				'type' => 'string',
				'enum' => array( 'open', 'closed' ),
			),
			'terms'          => array(
				'type'                 => 'object',
				'description'          => 'Term ids per taxonomy, e.g. {"category": [3], "post_tag": [7, 8]}. Replaces the post\'s terms in each listed taxonomy.',
				'additionalProperties' => array(
					'type'  => 'array',
					'items' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			),
			'featured_media' => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => 'Attachment id of an image; 0 removes the featured image.',
			),
		);
	}

	/**
	 * Extra capability a status needs beyond editing (publishing, or making private).
	 *
	 * @param \WP_Post_Type $type   Post type.
	 * @param string|null   $status Requested status.
	 */
	public static function status_cap( \WP_Post_Type $type, ?string $status ): ?string {
		return in_array( $status, array( 'publish', 'future', 'private' ), true ) ? $type->cap->publish_posts : null;
	}

	/**
	 * Core post fields from tool input, validated.
	 *
	 * @param array<string, mixed> $input Input.
	 * @param \WP_Post_Type        $type  Post type.
	 * @return array<string, mixed>
	 * @throws ToolError On invalid combinations.
	 */
	public static function postarr( array $input, \WP_Post_Type $type ): array {
		$map = array(
			'title'          => 'post_title',
			'content'        => 'post_content',
			'excerpt'        => 'post_excerpt',
			'status'         => 'post_status',
			'slug'           => 'post_name',
			'parent'         => 'post_parent',
			'menu_order'     => 'menu_order',
			'comment_status' => 'comment_status',
		);
		$out = array();
		foreach ( $map as $key => $field ) {
			if ( array_key_exists( $key, $input ) ) {
				$out[ $field ] = $input[ $key ];
			}
		}
		if ( isset( $input['parent'] ) && (int) $input['parent'] > 0 ) {
			if ( ! $type->hierarchical ) {
				throw new ToolError( 'This post type has no parent pages.' );
			}
			$parent = get_post( (int) $input['parent'] );
			if ( ! $parent || $parent->post_type !== $type->name ) {
				throw new ToolError( 'The parent must be an existing post of the same type.' );
			}
		}
		if ( isset( $input['date_gmt'] ) ) {
			$time = strtotime( (string) $input['date_gmt'] );
			if ( false === $time ) {
				throw new ToolError( 'date_gmt is not a valid date.' );
			}
			$out['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $time );
			$out['post_date']     = get_date_from_gmt( $out['post_date_gmt'] );
			$out['edit_date']     = true;
		}
		if ( 'future' === ( $input['status'] ?? null ) && ( ! isset( $out['post_date_gmt'] ) || strtotime( $out['post_date_gmt'] . ' UTC' ) <= time() ) ) {
			throw new ToolError( 'Scheduling (status "future") needs a date_gmt in the future.' );
		}
		return $out;
	}

	/**
	 * Check terms and featured image before writing anything.
	 *
	 * @param array<string, mixed> $input Input.
	 * @param \WP_Post_Type        $type  Post type.
	 * @throws ToolError When a taxonomy, term or image is not allowed.
	 */
	public static function check_extras( array $input, \WP_Post_Type $type ): void {
		foreach ( (array) ( $input['terms'] ?? array() ) as $taxonomy => $ids ) {
			$tax = Content::taxonomies()[ (string) $taxonomy ] ?? null;
			if ( null === $tax || ! is_object_in_taxonomy( $type->name, (string) $taxonomy ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
				throw new ToolError( 'Taxonomy "' . $taxonomy . '" does not belong to post type "' . $type->name . '".' );
			}
			if ( ! current_user_can( $tax->cap->assign_terms ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
				throw new ToolError( 'Your account cannot assign "' . $taxonomy . '" terms.' );
			}
			foreach ( (array) $ids as $id ) {
				$term = get_term( (int) $id, (string) $taxonomy );
				if ( ! $term instanceof \WP_Term ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
					throw new ToolError( 'Term ' . (int) $id . ' does not exist in "' . $taxonomy . '".' );
				}
			}
		}
		$media = (int) ( $input['featured_media'] ?? 0 );
		if ( $media > 0 ) {
			if ( ! post_type_supports( $type->name, 'thumbnail' ) ) {
				throw new ToolError( 'This post type does not support featured images.' );
			}
			if ( ! wp_attachment_is_image( $media ) ) {
				throw new ToolError( 'featured_media must be the id of an image in the media library.' );
			}
		}
	}

	/**
	 * Apply terms and featured image after the post exists.
	 *
	 * @param int                  $post_id Post id.
	 * @param array<string, mixed> $input   Input (already checked).
	 */
	public static function apply_extras( int $post_id, array $input ): void {
		foreach ( (array) ( $input['terms'] ?? array() ) as $taxonomy => $ids ) {
			wp_set_object_terms( $post_id, array_map( 'intval', (array) $ids ), (string) $taxonomy );
		}
		if ( array_key_exists( 'featured_media', $input ) ) {
			$media = (int) $input['featured_media'];
			if ( $media > 0 ) {
				set_post_thumbnail( $post_id, $media );
			} else {
				delete_post_thumbnail( $post_id );
			}
		}
	}

	/**
	 * Refuse content writes to Elementor-built posts: their layout lives in Elementor data, not post_content.
	 *
	 * @param \WP_Post             $post  Post.
	 * @param array<string, mixed> $input Input.
	 * @throws ToolError When content would be overwritten on an Elementor page.
	 */
	public static function guard_builder( \WP_Post $post, array $input ): void {
		if ( array_key_exists( 'content', $input ) && 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
			throw new ToolError( 'This post is built with Elementor; its layout is not stored in the content field. Change other fields here; Elementor editing comes with the Elementor tools.' );
		}
		if ( array_key_exists( 'content', $input ) && true === Avada::available() && 'avada' === Avada::builder( $post ) ) {
			throw new ToolError( 'This post is built with Avada Builder; its content is a layout of containers, columns and elements. Change other fields here, and the layout with the kuduy-avada-* tools (kuduy-avada-replace swaps the whole layout and checks it first).' );
		}
		if ( array_key_exists( 'content', $input ) && true === Beaver::available() && get_post_meta( $post->ID, '_fl_builder_enabled', true ) ) {
			throw new ToolError( 'This post is built with Beaver Builder; its layout is not stored in the content field. Change other fields here, and the layout with kuduy-beaver-edit.' );
		}
		if ( array_key_exists( 'content', $input ) && true === WpBakery::available() && 'wpbakery' === WpBakery::builder( $post ) ) {
			throw new ToolError( 'This post is built with WPBakery Page Builder; its content is a layout of shortcodes. Change other fields here, and the layout with the kuduy-wpbakery-* tools (kuduy-wpbakery-replace swaps the whole layout and checks it first).' );
		}
	}

	/**
	 * Everything an update can change, for undo.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	public static function state( \WP_Post $post ): array {
		$terms = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$ids                = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			$terms[ $taxonomy ] = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		}
		return array(
			'id'             => $post->ID,
			'post_title'     => $post->post_title,
			'post_content'   => $post->post_content,
			'post_excerpt'   => $post->post_excerpt,
			'post_status'    => $post->post_status,
			'post_name'      => $post->post_name,
			'post_date'      => $post->post_date,
			'post_date_gmt'  => $post->post_date_gmt,
			'post_parent'    => $post->post_parent,
			'menu_order'     => $post->menu_order,
			'comment_status' => $post->comment_status,
			'terms'          => $terms,
			'featured_media' => (int) get_post_thumbnail_id( $post ),
		);
	}

	/**
	 * Put a saved state back.
	 *
	 * @param array<string, mixed> $state From state().
	 * @throws ToolError When the post is gone or the update fails.
	 */
	public static function restore_state( array $state ): void {
		$id = (int) ( $state['id'] ?? 0 );
		if ( ! get_post( $id ) ) {
			throw new ToolError( 'The post no longer exists, so this change cannot be undone.' );
		}
		$fields = array_intersect_key( $state, array_flip( array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_date', 'post_date_gmt', 'post_parent', 'menu_order', 'comment_status' ) ) );
		$result = wp_update_post( array( 'ID' => $id ) + $fields + array( 'edit_date' => true ), true );
		if ( is_wp_error( $result ) ) {
			throw new ToolError( 'Undo failed: ' . esc_html( $result->get_error_message() ) );
		}
		foreach ( (array) ( $state['terms'] ?? array() ) as $taxonomy => $ids ) {
			wp_set_object_terms( $id, array_map( 'intval', (array) $ids ), (string) $taxonomy );
		}
		$media = (int) ( $state['featured_media'] ?? 0 );
		if ( $media > 0 ) {
			set_post_thumbnail( $id, $media );
		} else {
			delete_post_thumbnail( $id );
		}
	}
}
