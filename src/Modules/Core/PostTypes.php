<?php
/**
 * Tool: kuduy-post-types.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Mcp\Tool;

/**
 * Content post types and what the connected user may do with them.
 */
final class PostTypes implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-post-types';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'List post types', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Lists the content post types (posts, pages and custom post types with an admin screen) with their labels, features, taxonomies, and whether the connected user can create posts or edit other people\'s posts of that type. Use the `name` with kuduy-post-list and kuduy-post-get.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
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
				'post_types' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'name'            => array( 'type' => 'string' ),
							'label'           => array( 'type' => 'string' ),
							'singular_label'  => array( 'type' => 'string' ),
							'public'          => array( 'type' => 'boolean' ),
							'hierarchical'    => array( 'type' => 'boolean' ),
							'supports'        => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'taxonomies'      => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'rest_base'       => array( 'type' => array( 'string', 'null' ) ),
							'can_create'      => array( 'type' => 'boolean' ),
							'can_edit_others' => array( 'type' => 'boolean' ),
						),
						'required'   => array( 'name', 'label', 'public', 'hierarchical', 'can_create' ),
					),
				),
			),
			'required'   => array( 'post_types' ),
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
	public function capability( array $input ): string {
		return 'read';
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
		$out = array();
		foreach ( Content::post_types() as $type ) {
			$out[] = array(
				'name'            => $type->name,
				'label'           => (string) $type->label,
				'singular_label'  => (string) $type->labels->singular_name,
				'public'          => (bool) $type->public,
				'hierarchical'    => (bool) $type->hierarchical,
				'supports'        => array_keys( get_all_post_type_supports( $type->name ) ),
				'taxonomies'      => array_values( array_intersect( get_object_taxonomies( $type->name ), array_keys( Content::taxonomies() ) ) ),
				'rest_base'       => $type->show_in_rest ? ( is_string( $type->rest_base ) && '' !== $type->rest_base ? $type->rest_base : $type->name ) : null,
				'can_create'      => current_user_can( $type->cap->create_posts ),
				'can_edit_others' => current_user_can( $type->cap->edit_others_posts ),
			);
		}
		return array( 'post_types' => $out );
	}
}
