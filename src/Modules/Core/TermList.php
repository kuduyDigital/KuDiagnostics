<?php
/**
 * Tool: kuduy-term-list.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Terms of a taxonomy (categories, tags, custom taxonomies).
 */
final class TermList implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-term-list';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'List terms', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Lists or searches the terms of one taxonomy (default "category"; also "post_tag" or any custom taxonomy with an admin screen), including empty ones, with paging. Non-public taxonomies need permission to manage their terms.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy' => array(
					'type'    => 'string',
					'default' => 'category',
				),
				'search'   => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'parent'   => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => 'Only direct children of this term (0 = top level).',
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
				'terms'    => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'          => array( 'type' => 'integer' ),
							'name'        => array( 'type' => 'string' ),
							'slug'        => array( 'type' => 'string' ),
							'parent'      => array( 'type' => 'integer' ),
							'count'       => array( 'type' => 'integer' ),
							'description' => array( 'type' => 'string' ),
						),
						'required'   => array( 'id', 'name', 'slug', 'parent', 'count' ),
					),
				),
				'total'    => array( 'type' => 'integer' ),
				'page'     => array( 'type' => 'integer' ),
				'per_page' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'terms', 'total', 'page', 'per_page' ),
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
		$taxonomy = Content::taxonomies()[ $input['taxonomy'] ?? 'category' ] ?? null;
		return null === $taxonomy || $taxonomy->public ? 'read' : $taxonomy->cap->manage_terms;
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
	 * @throws ToolError When the taxonomy is unknown.
	 */
	public function execute( array $input ): array {
		$name       = (string) ( $input['taxonomy'] ?? 'category' );
		$taxonomies = Content::taxonomies();
		if ( ! isset( $taxonomies[ $name ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( 'Unknown taxonomy "' . $name . '". Use one of: ' . implode( ', ', array_keys( $taxonomies ) ) . '.' );
		}
		$per_page = (int) ( $input['per_page'] ?? 20 );
		$page     = (int) ( $input['page'] ?? 1 );
		$args     = array(
			'taxonomy'   => $name,
			'hide_empty' => false,
			'number'     => $per_page,
			'offset'     => ( $page - 1 ) * $per_page,
			'orderby'    => 'name',
		);
		if ( isset( $input['search'] ) && '' !== $input['search'] ) {
			$args['search'] = (string) $input['search'];
		}
		if ( isset( $input['parent'] ) ) {
			$args['parent'] = (int) $input['parent'];
		}
		$terms = get_terms( $args );
		$count = wp_count_terms( array_diff_key( $args, array_flip( array( 'number', 'offset' ) ) ) );
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$out[] = array(
				'id'          => $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'parent'      => $term->parent,
				'count'       => $term->count,
				'description' => $term->description,
			);
		}
		return array(
			'terms'    => $out,
			'total'    => is_numeric( $count ) ? (int) $count : count( $out ),
			'page'     => $page,
			'per_page' => $per_page,
		);
	}
}
