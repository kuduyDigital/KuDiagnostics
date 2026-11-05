<?php
/**
 * Tool: kuduy-post-trash.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Move a post to the trash. Permanent deletion is not offered: emptying the trash stays a human action in wp-admin.
 */
final class PostTrash implements Tool, Snapshottable {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-post-trash';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Move a post to the trash', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Moves a post, page or custom post to the trash (WordPress keeps it there, normally for 30 days). Undo with kuduy-snapshot-restore and the returned _snapshot_id, which restores the previous status. Permanent deletion is not available through MCP.';
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
		$schema                               = Content::summary_schema();
		$schema['properties']['_snapshot_id'] = array( 'type' => 'integer' );
		return $schema;
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => true,
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
		$post = Content::post( (int) ( $input['id'] ?? 0 ) );
		return null === $post ? 'edit_posts' : array( 'delete_post', $post->ID );
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
	 */
	public function snapshot( array $input ): ?array {
		$post = Content::post( (int) $input['id'] );
		return null === $post || 'trash' === $post->post_status ? null : array(
			'object_type' => 'post',
			'object_id'   => (string) $post->ID,
			'payload'     => array(
				'id'     => $post->ID,
				'status' => $post->post_status,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the post was deleted permanently or cannot be untrashed.
	 */
	public function restore( array $payload ): void {
		$post = get_post( (int) ( $payload['id'] ?? 0 ) );
		if ( ! $post ) {
			throw new ToolError( 'The post was deleted permanently, so it cannot be restored.' );
		}
		if ( 'trash' === $post->post_status && ! wp_untrash_post( $post->ID ) ) {
			throw new ToolError( 'WordPress could not take the post out of the trash.' );
		}
		// wp_untrash_post() restores to draft by default (WP 5.6+); put the previous status back.
		wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => (string) ( $payload['status'] ?? 'draft' ),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the post is missing, already trashed or WordPress refuses.
	 */
	public function execute( array $input ): array {
		$post = Content::post( (int) $input['id'] );
		if ( null === $post ) {
			throw new ToolError( 'Post ' . (int) $input['id'] . ' was not found (or is not a content post type).' );
		}
		if ( 'trash' === $post->post_status ) {
			throw new ToolError( 'This post is already in the trash.' );
		}
		if ( ! EMPTY_TRASH_DAYS ) {
			throw new ToolError( 'The trash is turned off on this site (EMPTY_TRASH_DAYS is 0), so deleting would be permanent. Not done.' );
		}
		// wp_trash_post() returns the post as it was before trashing, so read it back.
		$trashed = wp_trash_post( $post->ID ) ? get_post( $post->ID ) : null;
		if ( ! $trashed instanceof \WP_Post || 'trash' !== $trashed->post_status ) {
			throw new ToolError( 'WordPress did not move the post to the trash.' );
		}
		return Content::summary( $trashed );
	}
}
