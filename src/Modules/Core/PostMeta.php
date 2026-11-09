<?php
/**
 * Tools: kuduy-post-meta-get, kuduy-post-meta-set (one class, two modes).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Custom fields of a post. Protected keys (`_` prefix or registered as protected) are never read or written here:
 * they belong to WordPress and plugins (Elementor data, SEO fields, Woo prices) and get their own tools.
 */
final class PostMeta implements Tool, Snapshottable {

	/**
	 * Whether the key is the name of an ACF field shown on the post (ACF active only).
	 *
	 * @param int    $post_id Post.
	 * @param string $key     Meta key.
	 */
	private static function acf_field( int $post_id, string $key ): bool {
		if ( '' === $key || ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return false;
		}
		foreach ( (array) acf_get_field_groups( array( 'post_id' => $post_id ) ) as $group ) {
			foreach ( (array) acf_get_fields( $group ) as $field ) {
				if ( ( $field['name'] ?? null ) === $key ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Refuse raw writes to ACF fields: they skip ACF's reference key, formatting and validation (D14).
	 *
	 * @param int    $post_id Post.
	 * @param string $key     Meta key.
	 * @throws ToolError When the key is an ACF field.
	 */
	private static function assert_not_acf( int $post_id, string $key ): void {
		if ( self::acf_field( $post_id, $key ) ) {
			throw new ToolError( 'This is an Advanced Custom Fields field; change it with kuduy-acf-update so ACF stores and checks it properly. Nothing was changed.' );
		}
	}

	/**
	 * Mode.
	 *
	 * @param string $mode get | set.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-post-meta-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'get' === $this->mode ? __( 'Read custom fields', 'kuduy-mcp' ) : __( 'Set a custom field', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'get' === $this->mode
			? 'Returns the public custom fields (post meta) of a post, or one key. Protected keys (starting with "_") are not shown; plugin data such as Elementor, SEO or WooCommerce fields is handled by their own tools.'
			: 'Sets a public custom field (post meta) on a post, replacing all its values; value null deletes the key. Protected keys are refused. Snapshot first; undo with kuduy-snapshot-restore.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$props = array(
			'post_id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'key'     => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 255,
			),
		);
		if ( 'set' === $this->mode ) {
			$props['value'] = array(
				'type'        => array( 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' ),
				'description' => 'New value; null deletes the field. Arrays and objects are stored serialized by WordPress.',
			);
		}
		return array(
			'type'                 => 'object',
			'properties'           => $props,
			'required'             => 'set' === $this->mode ? array( 'post_id', 'key', 'value' ) : array( 'post_id' ),
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
				'post_id'      => array( 'type' => 'integer' ),
				'meta'         => array(
					'type'                 => 'object',
					'description'          => 'Key => list of values (a key can hold several values).',
					'additionalProperties' => array( 'type' => 'array' ),
				),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'post_id', 'meta' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return 'get' === $this->mode ? Content::read_only() : array(
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
		return 'get' === $this->mode ? 'kuduy:read' : 'kuduy:write';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		$post = Content::post( (int) ( $input['post_id'] ?? 0 ) );
		if ( null === $post ) {
			return 'edit_posts';
		}
		if ( 'set' === $this->mode && isset( $input['key'] ) ) {
			if ( self::acf_field( $post->ID, (string) $input['key'] ) ) {
				// ACF denies edit_post_meta for its field keys (acf_map_post_meta_caps); check edit_post here so
				// execute() can say which tool to use instead of a bare "not allowed".
				return array( 'edit_post', $post->ID );
			}
			// Core meta capability: edit_post plus the key's auth callback (protected keys are refused by it).
			return array( 'edit_post_meta', $post->ID, (string) $input['key'] );
		}
		return array( 'edit_post', $post->ID );
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
		$post = Content::post( (int) $input['post_id'] );
		if ( null !== $post && 'set' === $this->mode ) {
			Content::assert_writable( $post->post_type );
			self::assert_not_acf( $post->ID, (string) ( $input['key'] ?? '' ) );
		}
		return null === $post || 'set' !== $this->mode ? null : array(
			'object_type' => 'post',
			'object_id'   => (string) $post->ID,
			'payload'     => array(
				'post_id' => $post->ID,
				'key'     => (string) $input['key'],
				'values'  => get_post_meta( $post->ID, (string) $input['key'], false ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the post is gone.
	 */
	public function restore( array $payload ): void {
		$id = (int) ( $payload['post_id'] ?? 0 );
		if ( ! get_post( $id ) ) {
			throw new ToolError( 'The post no longer exists.' );
		}
		$key = (string) $payload['key'];
		delete_post_meta( $id, $key );
		foreach ( (array) ( $payload['values'] ?? array() ) as $value ) {
			add_post_meta( $id, $key, wp_slash( $value ) );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the post is missing or the key is protected.
	 */
	public function execute( array $input ): array {
		$post = Content::post( (int) $input['post_id'] );
		if ( null === $post ) {
			throw new ToolError( 'Post ' . (int) $input['post_id'] . ' was not found (or is not a content post type).' );
		}
		AuditLog::target( 'post', (string) $post->ID );
		if ( 'set' === $this->mode ) {
			Content::assert_writable( $post->post_type );
		}
		$key = isset( $input['key'] ) ? (string) $input['key'] : null;
		if ( null !== $key && is_protected_meta( $key, 'post' ) ) {
			throw new ToolError( 'This is a protected field used by WordPress or a plugin; it cannot be read or changed with this tool.' );
		}
		if ( 'set' === $this->mode && null !== $key ) {
			self::assert_not_acf( $post->ID, $key );
		}

		if ( 'set' === $this->mode && null !== $key ) {
			// Replace every value of the key (a key can hold several), or delete it for null.
			delete_post_meta( $post->ID, $key );
			if ( null !== $input['value'] && ! add_post_meta( $post->ID, $key, wp_slash( $input['value'] ) ) ) {
				throw new ToolError( 'WordPress did not save the field.' );
			}
		}

		$meta = array();
		foreach ( get_post_meta( $post->ID ) as $name => $values ) {
			if ( is_protected_meta( (string) $name, 'post' ) || ( null !== $key && $name !== $key ) ) {
				continue;
			}
			$meta[ $name ] = array_map( 'maybe_unserialize', (array) $values );
		}
		return array(
			'post_id' => $post->ID,
			'meta'    => $meta ? $meta : new \stdClass(),
		);
	}
}
