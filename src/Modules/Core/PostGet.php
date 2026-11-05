<?php
/**
 * Tool: kuduy-post-get.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * One post with its raw content, checked with `read_post` for that post.
 */
final class PostGet implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-post-get';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Read a post', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Returns one post, page or custom post by id: title, raw content (block markup or HTML as stored), excerpt, slug, status, dates, author, parent, link, template, featured image id and term ids per taxonomy. Content of a password-protected post is only returned to users who can edit it; the password itself is never returned.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		$schema                = Content::summary_schema();
		$schema['properties'] += array(
			'content'        => array(
				'type'        => array( 'string', 'null' ),
				'description' => 'Raw stored content; null when password-protected and you cannot edit the post.',
			),
			'excerpt'        => array( 'type' => array( 'string', 'null' ) ),
			'menu_order'     => array( 'type' => 'integer' ),
			'comment_status' => array( 'type' => 'string' ),
			'ping_status'    => array( 'type' => 'string' ),
			'sticky'         => array( 'type' => 'boolean' ),
			'template'       => array( 'type' => 'string' ),
			'featured_media' => array( 'type' => 'integer' ),
			'terms'          => array(
				'type'                 => 'object',
				'description'          => 'Term ids by taxonomy.',
				'additionalProperties' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
			),
		);
		$schema['required'][]  = 'content';
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
	public function capability( array $input ): string|array {
		$post = Content::post( (int) ( $input['id'] ?? 0 ) );
		return null === $post ? 'read' : array( 'read_post', $post->ID );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the post does not exist or is not a content post type.
	 */
	public function execute( array $input ): array {
		$post = Content::post( (int) $input['id'] );
		if ( null === $post ) {
			throw new ToolError( 'Post ' . (int) $input['id'] . ' was not found (or is not a content post type; see kuduy-post-types).' );
		}
		AuditLog::target( 'post', (string) $post->ID );

		$hidden = '' !== $post->post_password && ! current_user_can( 'edit_post', $post->ID );
		$terms  = array();
		foreach ( array_intersect( get_object_taxonomies( $post->post_type ), array_keys( Content::taxonomies() ) ) as $taxonomy ) {
			$ids                = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			$terms[ $taxonomy ] = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		}
		return Content::summary( $post ) + array(
			'content'        => $hidden ? null : $post->post_content,
			'excerpt'        => $hidden ? null : $post->post_excerpt,
			'menu_order'     => $post->menu_order,
			'comment_status' => $post->comment_status,
			'ping_status'    => $post->ping_status,
			'sticky'         => 'post' === $post->post_type && is_sticky( $post->ID ),
			'template'       => (string) get_page_template_slug( $post ),
			'featured_media' => (int) get_post_thumbnail_id( $post ),
			'terms'          => $terms ? $terms : new \stdClass(),
		);
	}
}
