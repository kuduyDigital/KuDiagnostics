<?php
/**
 * Tools: kuduy-revision-list, kuduy-revision-restore (one class, two modes).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * WordPress revisions of a post. Restoring uses wp_restore_post_revision(), so plugins that keep data in
 * revisions (Elementor) restore theirs too; the current state is snapshotted first.
 */
final class Revisions implements Tool, Snapshottable {

	/**
	 * Mode.
	 *
	 * @param string $mode list | restore.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-revision-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'list' === $this->mode ? __( 'List revisions', 'kuduy-mcp' ) : __( 'Restore a revision', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'list' === $this->mode
			? 'Lists the saved revisions of a post or page, newest first: id, author, date and title (content is not included; read it with include_content).'
			: 'Restores a post or page to one of its revisions (see kuduy-revision-list). The current state is snapshotted first; undo with kuduy-snapshot-restore.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$id = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		if ( 'list' === $this->mode ) {
			return array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id'         => $id,
					'include_content' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'limit'           => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 50,
						'default' => 10,
					),
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			);
		}
		return array(
			'type'                 => 'object',
			'properties'           => array( 'revision_id' => $id ),
			'required'             => array( 'revision_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		if ( 'restore' === $this->mode ) {
			$schema                               = Content::summary_schema();
			$schema['properties']['_snapshot_id'] = array( 'type' => 'integer' );
			return $schema;
		}
		return array(
			'type'       => 'object',
			'properties' => array(
				'revisions' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'       => array( 'type' => 'integer' ),
							'author'   => array( 'type' => 'integer' ),
							'date_gmt' => array( 'type' => 'string' ),
							'title'    => array( 'type' => 'string' ),
							'autosave' => array( 'type' => 'boolean' ),
							'content'  => array( 'type' => 'string' ),
						),
						'required'   => array( 'id', 'author', 'date_gmt', 'title', 'autosave' ),
					),
				),
			),
			'required'   => array( 'revisions' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return 'list' === $this->mode ? Content::read_only() : array(
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
		return 'list' === $this->mode ? 'kuduy:read' : 'kuduy:write';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		$post = $this->parent( $input );
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
		$post = $this->parent( $input );
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
	 * @throws ToolError When the post or revision is invalid.
	 */
	public function execute( array $input ): array {
		$post = $this->parent( $input );
		if ( null === $post ) {
			throw new ToolError( 'list' === $this->mode ? 'Post not found (or not a content post type).' : 'Revision not found, or it does not belong to a content post.' );
		}
		AuditLog::target( 'post', (string) $post->ID );
		if ( 'list' === $this->mode ) {
			$out = array();
			foreach ( wp_get_post_revisions( $post->ID, array( 'posts_per_page' => (int) ( $input['limit'] ?? 10 ) ) ) as $revision ) {
				$row = array(
					'id'       => $revision->ID,
					'author'   => (int) $revision->post_author,
					'date_gmt' => $revision->post_date_gmt,
					'title'    => $revision->post_title,
					'autosave' => (bool) wp_is_post_autosave( $revision ),
				);
				if ( ! empty( $input['include_content'] ) ) {
					$row['content'] = $revision->post_content;
				}
				$out[] = $row;
			}
			return array( 'revisions' => $out );
		}
		if ( ! wp_restore_post_revision( (int) $input['revision_id'] ) ) {
			throw new ToolError( 'WordPress could not restore this revision.' );
		}
		$fresh = get_post( $post->ID );
		return Content::summary( $fresh instanceof \WP_Post ? $fresh : $post );
	}

	/**
	 * The content post the request is about (for restore: the revision's parent).
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	private function parent( array $input ): ?\WP_Post {
		if ( 'list' === $this->mode ) {
			return Content::post( (int) ( $input['post_id'] ?? 0 ) );
		}
		$id       = (int) ( $input['revision_id'] ?? 0 );
		$revision = wp_get_post_revision( $id );
		return $revision instanceof \WP_Post ? Content::post( $revision->post_parent ) : null;
	}
}
