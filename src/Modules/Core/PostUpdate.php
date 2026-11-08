<?php
/**
 * Tool: kuduy-post-update.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Change fields of an existing post; the previous state is snapshotted first.
 */
final class PostUpdate implements Tool, Snapshottable {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-post-update';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Update a post', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Changes only the given fields of a post, page or custom post: title, content, excerpt, status (publish, schedule, unpublish), slug, date, parent, order, comments, terms, featured image. A snapshot is taken first; the result includes _snapshot_id for kuduy-snapshot-restore. Refuses content changes on Elementor-built pages.';
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
			) + PostWriter::fields(),
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
		return null === $post ? 'edit_posts' : array( 'edit_post', $post->ID );
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
		if ( null !== $post ) {
			Content::assert_writable( $post->post_type );
		}
		return null === $post ? null : array(
			'object_type' => 'post',
			'object_id'   => (string) $post->ID,
			'payload'     => PostWriter::state( $post ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 */
	public function restore( array $payload ): void {
		PostWriter::restore_state( $payload );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the post is missing or the change is not allowed.
	 */
	public function execute( array $input ): array {
		$post = Content::post( (int) $input['id'] );
		if ( null === $post ) {
			throw new ToolError( 'Post ' . (int) $input['id'] . ' was not found (or is not a content post type).' );
		}
		Content::assert_writable( $post->post_type );
		$type = Content::post_type( $post->post_type );
		$need = PostWriter::status_cap( $type, $input['status'] ?? null );
		if ( null !== $need && $input['status'] !== $post->post_status && ! current_user_can( $need ) ) {
			throw new ToolError( 'Your account cannot publish, schedule or make private this post type.' );
		}
		PostWriter::guard_builder( $post, $input );
		$postarr = PostWriter::postarr( $input, $type );
		PostWriter::check_extras( $input, $type );

		if ( $postarr ) {
			$result = wp_update_post( wp_slash( array( 'ID' => $post->ID ) + $postarr ), true );
			if ( is_wp_error( $result ) ) {
				throw new ToolError( 'WordPress refused the change: ' . esc_html( $result->get_error_message() ) );
			}
		}
		PostWriter::apply_extras( $post->ID, $input );
		$fresh = get_post( $post->ID );
		return Content::summary( $fresh instanceof \WP_Post ? $fresh : $post );
	}
}
