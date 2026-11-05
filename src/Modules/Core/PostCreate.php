<?php
/**
 * Tool: kuduy-post-create.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Create a post, page or custom post (draft by default).
 */
final class PostCreate implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-post-create';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Create a post', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Creates a post, page or custom post (see kuduy-post-types) as a draft unless another status is given. Publishing, scheduling (status "future" + date_gmt) and private need publish rights for that type. Can set terms and a featured image. Returns the new post summary.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_type' => array(
					'type'    => 'string',
					'default' => 'post',
				),
			) + PostWriter::fields(),
			'required'             => array( 'title' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return Content::summary_schema();
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => false,
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
		$type = Content::post_types()[ $input['post_type'] ?? 'post' ] ?? null;
		if ( null === $type ) {
			// Unknown type: execute() explains it.
			return 'edit_posts';
		}
		return PostWriter::status_cap( $type, $input['status'] ?? null ) ?? $type->cap->create_posts;
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
	 * @throws ToolError When the input is not allowed or WordPress refuses the post.
	 */
	public function execute( array $input ): array {
		$type = Content::post_type( (string) ( $input['post_type'] ?? 'post' ) );
		if ( ! current_user_can( $type->cap->create_posts ) ) {
			throw new ToolError( 'Your account cannot create this post type.' );
		}
		$postarr = PostWriter::postarr( $input, $type ) + array(
			'post_type'   => $type->name,
			'post_status' => 'draft',
			'post_author' => get_current_user_id(),
		);
		PostWriter::check_extras( $input, $type );

		$id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $id ) ) {
			throw new ToolError( 'WordPress refused the post: ' . esc_html( $id->get_error_message() ) );
		}
		AuditLog::target( 'post', (string) $id );
		PostWriter::apply_extras( $id, $input );
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			throw new ToolError( 'The post was created but could not be read back.' );
		}
		return Content::summary( $post );
	}
}
