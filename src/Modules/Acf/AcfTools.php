<?php
/**
 * Tools: kuduy-acf-status, kuduy-acf-field-groups, kuduy-acf-get, kuduy-acf-update.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Acf;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Audit\MasksAuditArgs;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * ACF through its public API (D14 A): definitions read only, values on posts, users and terms read and written with
 * get_field() / update_field() by field key, validated with acf_validate_value() first. password fields are never
 * returned and are masked in audit rows and snapshot input.
 */
final class AcfTools implements Tool, Snapshottable, MasksAuditArgs {

	/**
	 * Mode.
	 *
	 * @param string $mode status | field-groups | get | update.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-acf-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'status'       => __( 'ACF status', 'kuduy-mcp' ),
			'field-groups' => __( 'ACF field groups', 'kuduy-mcp' ),
			'get'          => __( 'Read ACF fields', 'kuduy-mcp' ),
			default        => __( 'Change ACF fields', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'status'       => 'Advanced Custom Fields version, free or PRO, whether ACF\'s own AI abilities are on, number of field groups (stored and local JSON/PHP) and the field types available.',
			'field-groups' => 'Lists ACF field groups with their location rules and fields (key, name, label, type, required, choices, defaults). Filter by object (post, user or term + id: the groups ACF shows on it), post_type or taxonomy. Read only: field groups are changed in ACF.',
			'get'          => 'Returns every ACF field shown on one post, user or term: key, name, type, raw value and formatted value (related posts, terms and users as id + title). Password fields never show their value (value null, has_value).',
			default        => 'Changes ACF field values on one post, user or term. values maps field keys or names (as in kuduy-acf-get) to new values; only fields ACF shows on that object are accepted. Every value is checked the way ACF\'s edit screen checks it (required, number limits, email, choices) before anything is written. Snapshot first; undo with kuduy-snapshot-restore.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		[ $properties, $required ] = match ( $this->mode ) {
			'status'       => array( array(), array() ),
			'field-groups' => array(
				Acf::object_input() + array(
					'post_type' => array( 'type' => 'string' ),
					'taxonomy'  => array( 'type' => 'string' ),
				),
				array(),
			),
			'get'          => array( Acf::object_input(), array( 'object', 'id' ) ),
			default        => array(
				Acf::object_input() + array(
					'values' => array(
						'type'          => 'object',
						'minProperties' => 1,
						'maxProperties' => 100,
					),
				),
				array( 'object', 'id', 'values' ),
			),
		};//end match
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
		$list   = array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		);
		$fields = array(
			'object' => array( 'type' => 'string' ),
			'id'     => array( 'type' => 'integer' ),
			'fields' => $list,
		);
		return match ( $this->mode ) {
			'status'       => array(
				'type'       => 'object',
				'properties' => array(
					'version'           => array( 'type' => 'string' ),
					'pro'               => array( 'type' => 'boolean' ),
					'abilities_enabled' => array( 'type' => 'boolean' ),
					'field_groups'      => array( 'type' => 'integer' ),
					'local_groups'      => array( 'type' => 'integer' ),
					'field_types'       => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				'required'   => array( 'version', 'pro', 'abilities_enabled' ),
			),
			'field-groups' => array(
				'type'       => 'object',
				'properties' => array( 'groups' => $list ),
				'required'   => array( 'groups' ),
			),
			'get'          => array(
				'type'       => 'object',
				'properties' => $fields,
				'required'   => array( 'object', 'id', 'fields' ),
			),
			default        => array(
				'type'       => 'object',
				'properties' => $fields + array(
					'changed'      => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'_snapshot_id' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'object', 'id', 'fields', 'changed' ),
			),
		};//end match
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		if ( 'update' !== $this->mode ) {
			return Content::read_only();
		}
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
		return 'update' === $this->mode ? 'kuduy:write' : 'kuduy:read';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		$object = (string) ( $input['object'] ?? '' );
		$id     = (int) ( $input['id'] ?? 0 );
		if ( in_array( $this->mode, array( 'get', 'update' ), true ) && in_array( $object, Acf::OBJECTS, true ) && $id > 0 && Acf::exists( $object, $id ) ) {
			return Acf::capability( $object, $id, 'update' === $this->mode );
		}
		return 'update' === $this->mode && 'user' === $object ? 'edit_users' : 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return Acf::available();
	}

	/**
	 * Values of password fields never reach the audit log or the snapshot input.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	public function audit_args( array $args ): array {
		if ( 'update' !== $this->mode || ! is_array( $args['values'] ?? null ) ) {
			return $args;
		}
		$object = (string) ( $args['object'] ?? '' );
		$id     = (int) ( $args['id'] ?? 0 );
		$fields = in_array( $object, Acf::OBJECTS, true ) && $id > 0 && Acf::exists( $object, $id ) ? Acf::fields( $object, $id ) : array();
		$names  = array();
		foreach ( $fields as $key => $field ) {
			if ( 'password' === ( $field['type'] ?? '' ) ) {
				$names[ $key ]                    = true;
				$names[ (string) $field['name'] ] = true;
			}
		}
		foreach ( $args['values'] as $selector => $value ) {
			if ( isset( $names[ (string) $selector ] ) ) {
				$args['values'][ $selector ] = '[redacted]';
			}
		}
		return $args;
	}

	/**
	 * Object from the input, or a ToolError.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array{0: string, 1: int}
	 * @throws ToolError When it does not exist.
	 */
	private static function target( array $input ): array {
		$object = (string) $input['object'];
		$id     = (int) $input['id'];
		if ( ! Acf::exists( $object, $id ) ) {
			throw new ToolError( 'No ' . esc_html( $object ) . ' with id ' . (int) $id . '.' );
		}
		return array( $object, $id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'update' !== $this->mode || ! Acf::exists( (string) ( $input['object'] ?? '' ), (int) ( $input['id'] ?? 0 ) ) ) {
			return null;
		}
		[ $object, $id ] = self::target( $input );
		$acf_id          = Acf::acf_id( $object, $id );
		try {
			$resolved = Acf::resolve( Acf::fields( $object, $id ), (array) $input['values'] );
		} catch ( ToolError $e ) {
			return null;
		}
		$saved = array();
		foreach ( $resolved as $key => $item ) {
			$raw     = acf_get_metadata( $acf_id, (string) $item['field']['name'] );
			$saved[] = array(
				'key'     => $key,
				'existed' => null !== $raw,
				'value'   => null === $raw ? null : get_field( $key, $acf_id, false ),
			);
		}
		return array(
			'object_type' => $object,
			'object_id'   => (string) $id,
			'payload'     => array(
				'object' => $object,
				'id'     => $id,
				'fields' => $saved,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the object is gone.
	 */
	public function restore( array $payload ): void {
		$object = (string) ( $payload['object'] ?? '' );
		$id     = (int) ( $payload['id'] ?? 0 );
		if ( ! in_array( $object, Acf::OBJECTS, true ) || ! Acf::exists( $object, $id ) ) {
			throw new ToolError( 'The object no longer exists.' );
		}
		$acf_id = Acf::acf_id( $object, $id );
		foreach ( (array) ( $payload['fields'] ?? array() ) as $saved ) {
			$key = (string) ( $saved['key'] ?? '' );
			if ( ! empty( $saved['existed'] ) ) {
				update_field( $key, $saved['value'], $acf_id );
			} else {
				delete_field( $key, $acf_id );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the object or a field is missing, or ACF refuses a value.
	 */
	public function execute( array $input ): array {
		return match ( $this->mode ) {
			'status'       => self::status(),
			'field-groups' => self::field_groups( $input ),
			'get'          => self::get( $input ),
			default        => self::update( $input ),
		};
	}

	/**
	 * Status.
	 *
	 * @return array<string, mixed>
	 */
	private static function status(): array {
		$types = array();
		foreach ( (array) acf_get_field_types() as $name => $type ) {
			$types[] = (string) $name;
		}
		return array(
			'version'           => defined( 'ACF_VERSION' ) ? (string) ACF_VERSION : '',
			'pro'               => acf_is_pro(),
			'abilities_enabled' => (bool) acf_get_setting( 'enable_acf_ai' ),
			'field_groups'      => count( (array) acf_get_field_groups() ),
			'local_groups'      => count( (array) acf_get_local_field_groups() ),
			'field_types'       => $types,
		);
	}

	/**
	 * Field groups.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the object does not exist or cannot be read.
	 */
	private static function field_groups( array $input ): array {
		if ( isset( $input['object'], $input['id'] ) ) {
			[ $object, $id ] = self::target( $input );
			if ( ! current_user_can( ...Acf::capability( $object, $id, false ) ) ) {
				throw new ToolError( 'Your account cannot read this object.' );
			}
			$groups = Acf::groups( $object, $id );
		} else {
			$filter = array_filter(
				array(
					'post_type' => (string) ( $input['post_type'] ?? '' ),
					'taxonomy'  => (string) ( $input['taxonomy'] ?? '' ),
				)
			);
			$groups = array_values( (array) acf_get_field_groups( $filter ) );
		}
		$out = array();
		foreach ( $groups as $group ) {
			$out[] = array(
				'key'      => (string) $group['key'],
				'title'    => (string) $group['title'],
				'active'   => ! empty( $group['active'] ),
				'source'   => empty( $group['local'] ) ? 'database' : (string) $group['local'],
				'location' => Acf::plain( (array) ( $group['location'] ?? array() ) ),
				'fields'   => array_map( array( Acf::class, 'definition' ), array_values( (array) acf_get_fields( $group ) ) ),
			);
		}
		return array( 'groups' => $out );
	}

	/**
	 * Values on one object.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private static function get( array $input ): array {
		[ $object, $id ] = self::target( $input );
		AuditLog::target( $object, (string) $id );
		$acf_id = Acf::acf_id( $object, $id );
		$fields = array();
		foreach ( Acf::fields( $object, $id ) as $field ) {
			$fields[] = Acf::describe( $field, $acf_id );
		}
		return array(
			'object' => $object,
			'id'     => $id,
			'fields' => $fields,
		);
	}

	/**
	 * Change values.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When a field is not shown on the object or ACF refuses a value.
	 */
	private static function update( array $input ): array {
		[ $object, $id ] = self::target( $input );
		AuditLog::target( $object, (string) $id );
		$acf_id   = Acf::acf_id( $object, $id );
		$resolved = Acf::resolve( Acf::fields( $object, $id ), (array) $input['values'] );
		Acf::validate( $resolved );
		$changed = array();
		$fields  = array();
		foreach ( $resolved as $key => $item ) {
			update_field( $key, $item['value'], $acf_id );
			$changed[] = (string) $item['field']['name'];
			$fields[]  = Acf::describe( $item['field'], $acf_id );
		}
		return array(
			'object'  => $object,
			'id'      => $id,
			'fields'  => $fields,
			'changed' => $changed,
		);
	}
}
