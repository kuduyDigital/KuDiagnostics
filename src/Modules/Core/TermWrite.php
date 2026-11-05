<?php
/**
 * Tools: kuduy-term-create, kuduy-term-update, kuduy-term-delete (one class, three modes).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Same rules as core's REST terms controller: create needs the taxonomy's edit_terms; update and delete check
 * edit_term / delete_term for that term. Update and delete are snapshotted.
 */
final class TermWrite implements Tool, Snapshottable {

	/**
	 * Mode.
	 *
	 * @param string $mode create | update | delete.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-term-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'create' => __( 'Create a term', 'kuduy-mcp' ),
			'update' => __( 'Update a term', 'kuduy-mcp' ),
			default  => __( 'Delete a term', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'create' => 'Creates a category, tag or custom taxonomy term (see kuduy-term-list for taxonomies). Returns the term.',
			'update' => 'Changes name, slug, description or parent of a term. Snapshot first; undo with kuduy-snapshot-restore.',
			default  => 'Deletes a term; posts keep existing and lose only this term (WordPress moves posts of a deleted category to the default category). The default category cannot be deleted. Undo recreates the term (new id) and reassigns its posts.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$fields = array(
			'name'        => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 200,
			),
			'slug'        => array(
				'type'      => 'string',
				'maxLength' => 200,
			),
			'description' => array( 'type' => 'string' ),
			'parent'      => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
		);
		$id     = array(
			'id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		return match ( $this->mode ) {
			'create' => array(
				'type'                 => 'object',
				'properties'           => array(
					'taxonomy' => array(
						'type'    => 'string',
						'default' => 'category',
					),
				) + $fields,
				'required'             => array( 'name' ),
				'additionalProperties' => false,
			),
			'update' => array(
				'type'                 => 'object',
				'properties'           => $id + $fields,
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			default  => array(
				'type'                 => 'object',
				'properties'           => $id,
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
		};//end match
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'           => array( 'type' => 'integer' ),
				'taxonomy'     => array( 'type' => 'string' ),
				'name'         => array( 'type' => 'string' ),
				'slug'         => array( 'type' => 'string' ),
				'parent'       => array( 'type' => 'integer' ),
				'description'  => array( 'type' => 'string' ),
				'deleted'      => array( 'type' => 'boolean' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'id', 'taxonomy', 'name', 'slug' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => 'create' !== $this->mode,
			'idempotentHint'  => 'update' === $this->mode,
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
		if ( 'create' === $this->mode ) {
			$tax = Content::taxonomies()[ $input['taxonomy'] ?? 'category' ] ?? null;
			return null === $tax ? 'manage_categories' : $tax->cap->edit_terms;
		}
		$term = self::term( (int) ( $input['id'] ?? 0 ) );
		if ( null === $term ) {
			return 'manage_categories';
		}
		return array( 'update' === $this->mode ? 'edit_term' : 'delete_term', $term->term_id );
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
		$term = self::term( (int) $input['id'] );
		if ( null === $term || 'create' === $this->mode ) {
			return null;
		}
		$objects = get_objects_in_term( $term->term_id, $term->taxonomy );
		return array(
			'object_type' => 'term',
			'object_id'   => (string) $term->term_id,
			'payload'     => array(
				'mode'        => $this->mode,
				'id'          => $term->term_id,
				'taxonomy'    => $term->taxonomy,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'parent'      => $term->parent,
				'objects'     => is_array( $objects ) ? array_map( 'intval', $objects ) : array(),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the term cannot be put back.
	 */
	public function restore( array $payload ): void {
		$taxonomy = (string) $payload['taxonomy'];
		$args     = array(
			'name'        => (string) $payload['name'],
			'slug'        => (string) $payload['slug'],
			'description' => (string) $payload['description'],
			'parent'      => (int) $payload['parent'],
		);
		if ( 'update' === $payload['mode'] ) {
			$result = wp_update_term( (int) $payload['id'], $taxonomy, $args );
		} else {
			$result = wp_insert_term( (string) $payload['name'], $taxonomy, $args );
			if ( is_array( $result ) ) {
				foreach ( (array) $payload['objects'] as $object ) {
					wp_add_object_terms( (int) $object, (int) $result['term_id'], $taxonomy );
				}
			}
		}
		if ( is_wp_error( $result ) ) {
			throw new ToolError( 'Undo failed: ' . esc_html( $result->get_error_message() ) );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the taxonomy or term is invalid or WordPress refuses.
	 */
	public function execute( array $input ): array {
		$args = array_intersect_key( $input, array_flip( array( 'slug', 'description', 'parent' ) ) );
		if ( 'create' === $this->mode ) {
			$taxonomy = (string) ( $input['taxonomy'] ?? 'category' );
			if ( ! isset( Content::taxonomies()[ $taxonomy ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
				throw new ToolError( 'Unknown taxonomy "' . $taxonomy . '".' );
			}
			self::check_parent( $args, $taxonomy );
			$result = wp_insert_term( (string) $input['name'], $taxonomy, $args );
			if ( is_wp_error( $result ) ) {
				throw new ToolError( 'WordPress refused the term: ' . esc_html( $result->get_error_message() ) );
			}
			return $this->shape( (int) $result['term_id'], $taxonomy );
		}

		$term = self::term( (int) $input['id'] );
		if ( null === $term ) {
			throw new ToolError( 'Term ' . (int) $input['id'] . ' was not found (or its taxonomy has no admin screen).' );
		}
		AuditLog::target( 'term', (string) $term->term_id );
		if ( 'update' === $this->mode ) {
			if ( isset( $input['name'] ) ) {
				$args['name'] = $input['name'];
			}
			self::check_parent( $args, $term->taxonomy, $term->term_id );
			$result = wp_update_term( $term->term_id, $term->taxonomy, $args );
			if ( is_wp_error( $result ) ) {
				throw new ToolError( 'WordPress refused the change: ' . esc_html( $result->get_error_message() ) );
			}
			return $this->shape( $term->term_id, $term->taxonomy );
		}

		if ( 'category' === $term->taxonomy && (int) get_option( 'default_category' ) === $term->term_id ) {
			throw new ToolError( 'This is the default category, which WordPress does not allow to delete. Choose another default under Settings > Writing first.' );
		}
		$shape  = $this->shape( $term->term_id, $term->taxonomy );
		$result = wp_delete_term( $term->term_id, $term->taxonomy );
		if ( true !== $result ) {
			throw new ToolError( 'WordPress did not delete the term.' );
		}
		return array( 'deleted' => true ) + $shape;
	}

	/**
	 * Term of a content taxonomy, or null.
	 *
	 * @param int $id Term id.
	 */
	private static function term( int $id ): ?\WP_Term {
		$term = $id > 0 ? get_term( $id ) : null;
		return $term instanceof \WP_Term && isset( Content::taxonomies()[ $term->taxonomy ] ) ? $term : null;
	}

	/**
	 * Parent must be a term of the same hierarchical taxonomy and not the term itself.
	 *
	 * @param array<string, mixed> $args     Term args.
	 * @param string               $taxonomy Taxonomy.
	 * @param int                  $current  Term being edited (0 when creating).
	 * @throws ToolError When the parent is invalid.
	 */
	private static function check_parent( array $args, string $taxonomy, int $current = 0 ): void {
		$parent = (int) ( $args['parent'] ?? 0 );
		if ( 0 === $parent ) {
			return;
		}
		if ( ! is_taxonomy_hierarchical( $taxonomy ) || $parent === $current || ! get_term( $parent, $taxonomy ) instanceof \WP_Term ) {
			throw new ToolError( 'The parent must be another term of the same hierarchical taxonomy.' );
		}
	}

	/**
	 * Result shape.
	 *
	 * @param int    $id       Term id.
	 * @param string $taxonomy Taxonomy.
	 * @return array<string, mixed>
	 */
	private function shape( int $id, string $taxonomy ): array {
		$term = get_term( $id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return array(
				'id'       => $id,
				'taxonomy' => $taxonomy,
				'name'     => '',
				'slug'     => '',
			);
		}
		return array(
			'id'          => $term->term_id,
			'taxonomy'    => $term->taxonomy,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'parent'      => $term->parent,
			'description' => $term->description,
		);
	}
}
