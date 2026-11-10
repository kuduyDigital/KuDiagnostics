<?php
/**
 * Tools: kuduy-content-types, kuduy-content-type-save, kuduy-content-type-remove.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Types;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Custom post types and taxonomies defined through KuDiagnostics (D22 A). Site administrators with the admin scope; every
 * change has an undo; removing a definition keeps the content. Rewrite rules are flushed after a change so new
 * addresses work at once.
 */
final class TypeTools implements Tool, Snapshottable {

	/**
	 * Mode.
	 *
	 * @param string $mode list | save | remove.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'list' === $this->mode ? 'kuduy-content-types' : 'kuduy-content-type-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'list'  => __( 'Custom content types', 'kuduy-mcp' ),
			'save'  => __( 'Define a content type', 'kuduy-mcp' ),
			default => __( 'Remove a content type', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'list'  => 'Lists the custom post types and taxonomies defined through KuDiagnostics (with their settings and number of items). Types from the theme or other plugins are listed by kuduy-post-types.',
			'save'  => 'Defines or changes a custom post type (kind "post_type": key up to 20 characters, singular, plural, public, hierarchical, has_archive, supports, menu_icon, rewrite_slug, taxonomies) or taxonomy (kind "taxonomy": key up to 32 characters, singular, plural, object_type = post types it applies to, public, hierarchical, rewrite_slug). Shown in the editor and REST API; access works like posts. KuDiagnostics must stay active for the type to exist. Snapshot first; undo with kuduy-snapshot-restore.',
			default => 'Removes a content type defined through KuDiagnostics. Its posts or terms are not deleted: they stay in the database and return if the type is defined again. Snapshot first; undo with kuduy-snapshot-restore.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$text       = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 100,
		);
		$list       = array(
			'type'     => 'array',
			'items'    => array(
				'type'    => 'string',
				'pattern' => '^[a-z0-9_-]{1,32}$',
			),
			'maxItems' => 20,
		);
		$properties = array();
		$required   = array();
		if ( 'save' === $this->mode || 'remove' === $this->mode ) {
			$properties = array(
				'kind' => array(
					'type' => 'string',
					'enum' => array( 'post_type', 'taxonomy' ),
				),
				'key'  => array(
					'type'    => 'string',
					'pattern' => '^[a-z0-9_-]{1,32}$',
				),
			);
			$required   = array( 'kind', 'key' );
		}
		if ( 'save' === $this->mode ) {
			$properties += array(
				'singular'     => $text,
				'plural'       => $text,
				'public'       => array( 'type' => 'boolean' ),
				'hierarchical' => array( 'type' => 'boolean' ),
				'has_archive'  => array( 'type' => 'boolean' ),
				'supports'     => array(
					'type'  => 'array',
					'items' => array(
						'type' => 'string',
						'enum' => ContentTypes::SUPPORTS,
					),
				),
				'menu_icon'    => array(
					'type'    => 'string',
					'pattern' => '^dashicons-[a-z0-9-]+$',
				),
				'rewrite_slug' => array(
					'type'    => 'string',
					'pattern' => '^[a-z0-9-]{1,60}$',
				),
				'taxonomies'   => $list,
				'object_type'  => $list,
			);
			$required[]  = 'singular';
			$required[]  = 'plural';
		}//end if
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
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
				'post_types'   => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'taxonomies'   => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'post_types', 'taxonomies' ),
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
		return 'list' === $this->mode ? 'kuduy:read' : 'kuduy:admin';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'list' === $this->mode ? 'edit_posts' : 'manage_options';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return true;
	}

	/**
	 * Store key of a kind.
	 *
	 * @param string $kind post_type | taxonomy.
	 */
	private static function store( string $kind ): string {
		return 'post_type' === $kind ? 'post_types' : 'taxonomies';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the key cannot be used.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'list' === $this->mode ) {
			return null;
		}
		$store = self::store( (string) $input['kind'] );
		if ( 'save' === $this->mode ) {
			ContentTypes::assert_slug( (string) $input['key'], 'post_types' === $store ? 20 : 32, $store );
		}
		return array(
			'object_type' => 'content_type',
			'object_id'   => (string) $input['key'],
			'payload'     => array( 'defs' => ContentTypes::stored() ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 */
	public function restore( array $payload ): void {
		$before = ContentTypes::stored();
		$defs   = (array) ( $payload['defs'] ?? array() );
		ContentTypes::save(
			array(
				'post_types' => (array) ( $defs['post_types'] ?? array() ),
				'taxonomies' => (array) ( $defs['taxonomies'] ?? array() ),
			)
		);
		self::apply( $before );
	}

	/**
	 * Re-register KuDiagnostics's types after a change: every key defined before or after is unregistered, then what is
	 * stored now is registered, and rewrite rules are flushed.
	 *
	 * @param array{post_types: array<string, mixed>, taxonomies: array<string, mixed>} $before Definitions before.
	 */
	private static function apply( array $before ): void {
		$after = ContentTypes::stored();
		foreach ( array_keys( $before['post_types'] + $after['post_types'] ) as $slug ) {
			if ( post_type_exists( (string) $slug ) ) {
				unregister_post_type( (string) $slug );
			}
		}
		foreach ( array_keys( $before['taxonomies'] + $after['taxonomies'] ) as $slug ) {
			if ( taxonomy_exists( (string) $slug ) ) {
				unregister_taxonomy( (string) $slug );
			}
		}
		ContentTypes::register();
		flush_rewrite_rules( false );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the key cannot be used or is not KuDiagnostics's.
	 */
	public function execute( array $input ): array {
		if ( 'list' !== $this->mode ) {
			$kind  = (string) $input['kind'];
			$store = self::store( $kind );
			$key   = (string) $input['key'];
			$defs  = ContentTypes::stored();
			AuditLog::target( 'content_type', $key );
			if ( 'remove' === $this->mode ) {
				if ( ! isset( $defs[ $store ][ $key ] ) ) {
					throw new ToolError( '"' . esc_html( $key ) . '" was not defined through KuDiagnostics, so it cannot be removed here.' );
				}
				unset( $defs[ $store ][ $key ] );
			} else {
				ContentTypes::assert_slug( $key, 'post_types' === $store ? 20 : 32, $store );
				$fields                 = array( 'singular', 'plural', 'public', 'hierarchical', 'rewrite_slug' );
				$fields                 = 'post_types' === $store ? array_merge( $fields, array( 'has_archive', 'supports', 'menu_icon', 'taxonomies' ) ) : array_merge( $fields, array( 'object_type' ) );
				$defs[ $store ][ $key ] = array_intersect_key( $input, array_flip( $fields ) );
			}
			$before = ContentTypes::stored();
			ContentTypes::save( $defs );
			self::apply( $before );
		}//end if
		return self::listing();
	}

	/**
	 * Stored definitions with counts.
	 *
	 * @return array<string, mixed>
	 */
	private static function listing(): array {
		$defs  = ContentTypes::stored();
		$types = array();
		foreach ( $defs['post_types'] as $slug => $def ) {
			$counts  = wp_count_posts( (string) $slug );
			$types[] = array( 'key' => (string) $slug ) + (array) $def + array(
				'registered' => post_type_exists( (string) $slug ),
				'items'      => (int) ( $counts->publish ?? 0 ) + (int) ( $counts->draft ?? 0 ) + (int) ( $counts->private ?? 0 ),
			);
		}
		$taxes = array();
		foreach ( $defs['taxonomies'] as $slug => $def ) {
			$count   = taxonomy_exists( (string) $slug ) ? wp_count_terms( array( 'taxonomy' => (string) $slug ) ) : 0;
			$taxes[] = array( 'key' => (string) $slug ) + (array) $def + array(
				'registered' => taxonomy_exists( (string) $slug ),
				'items'      => is_numeric( $count ) ? (int) $count : 0,
			);
		}
		return array(
			'post_types' => $types,
			'taxonomies' => $taxes,
		);
	}
}
