<?php
/**
 * Tool: kuduy-post-list.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Mcp\Tool;

/**
 * List and search posts, pages and custom post types, limited to what the user may read.
 */
final class PostList implements Tool {

	private const STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	private const ORDERBY = array(
		'date'       => 'date',
		'modified'   => 'modified',
		'title'      => 'title',
		'menu_order' => 'menu_order',
		'id'         => 'ID',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-post-list';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'List posts', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Lists or searches posts of one post type (default "post"; see kuduy-post-types), newest first, with paging. Unpublished statuses need permission to edit that post type; users who cannot edit other people\'s posts only see their own unpublished posts. Returns summaries; use kuduy-post-get for content.';
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
				'status'    => array(
					'type'    => 'string',
					'enum'    => array_merge( self::STATUSES, array( 'any' ) ),
					'default' => 'publish',
				),
				'search'    => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'author'    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'parent'    => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => 'Only children of this post (0 = top level). Hierarchical types such as pages.',
				),
				'orderby'   => array(
					'type'    => 'string',
					'enum'    => array_keys( self::ORDERBY ),
					'default' => 'date',
				),
				'order'     => array(
					'type'    => 'string',
					'enum'    => array( 'asc', 'desc' ),
					'default' => 'desc',
				),
			) + Content::paging_input(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'posts'       => array(
					'type'  => 'array',
					'items' => Content::summary_schema(),
				),
				'total'       => array(
					'type'        => 'integer',
					'description' => 'Matching posts before the per-post read check.',
				),
				'total_pages' => array( 'type' => 'integer' ),
				'page'        => array( 'type' => 'integer' ),
				'per_page'    => array( 'type' => 'integer' ),
			),
			'required'   => array( 'posts', 'total', 'total_pages', 'page', 'per_page' ),
		);
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
		$type = Content::post_types()[ $input['post_type'] ?? 'post' ] ?? null;
		if ( null === $type ) {
			return 'read';
			// execute() explains the unknown type.
		}
		$status = $input['status'] ?? 'publish';
		if ( 'publish' === $status && $type->public ) {
			return 'read';
		}
		return 'private' === $status ? $type->cap->read_private_posts : $type->cap->edit_posts;
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
	public function execute( array $input ): array {
		$type     = Content::post_type( (string) ( $input['post_type'] ?? 'post' ) );
		$status   = (string) ( $input['status'] ?? 'publish' );
		$per_page = (int) ( $input['per_page'] ?? 20 );
		$page     = (int) ( $input['page'] ?? 1 );
		$args     = array(
			'post_type'           => $type->name,
			'post_status'         => 'any' === $status ? self::STATUSES : array( $status ),
			'posts_per_page'      => $per_page,
			'paged'               => $page,
			'orderby'             => self::ORDERBY[ $input['orderby'] ?? 'date' ] ?? 'date',
			'order'               => 'asc' === ( $input['order'] ?? 'desc' ) ? 'ASC' : 'DESC',
			'ignore_sticky_posts' => true,
			'perm'                => 'readable',
		);
		if ( isset( $input['search'] ) && '' !== $input['search'] ) {
			$args['s'] = (string) $input['search'];
		}
		if ( isset( $input['author'] ) ) {
			$args['author'] = (int) $input['author'];
		}
		if ( isset( $input['parent'] ) ) {
			$args['post_parent'] = (int) $input['parent'];
		}
		// Like "Mine" in wp-admin: without edit_others_posts, unpublished posts are limited to your own.
		if ( 'publish' !== $status && ! current_user_can( $type->cap->edit_others_posts ) ) {
			$args['author'] = get_current_user_id();
		}

		$query = new \WP_Query( $args );
		$posts = array();
		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post && current_user_can( 'read_post', $post->ID ) ) {
				$posts[] = Content::summary( $post );
			}
		}
		return array(
			'posts'       => $posts,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}
}
