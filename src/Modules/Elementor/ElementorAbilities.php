<?php
/**
 * Tools that run Elementor's own MCP abilities for v4 (atomic) design globals (decision D7, hybrid):
 * kuduy-elementor-v4-globals (read classes + variables), kuduy-elementor-v4-classes, kuduy-elementor-v4-variables.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Elementor;

use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Elementor's abilities are called in-process through its ability registry (Elementor's public MCP stays off unless
 * the site owner turns it on). KUDUY adds its own scope check, Elementor's permission check, schema validation,
 * snapshots and the audit log. These are Elementor internals: if they are missing or change shape, the tools report
 * themselves unavailable instead of guessing.
 */
final class ElementorAbilities implements Tool, Snapshottable {

	private const MODES = array(
		'globals'   => array( 'elementor/global-classes-resource', 'elementor/global-variables-resource' ),
		'classes'   => array( 'elementor/manage-classes' ),
		'variables' => array( 'elementor/manage-global-variable' ),
	);

	/**
	 * Mode.
	 *
	 * @param string $mode globals | classes | variables.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-elementor-v4-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'globals' => __( 'Elementor v4 classes and variables', 'kuduy-mcp' ),
			'classes' => __( 'Manage Elementor v4 global classes', 'kuduy-mcp' ),
			default   => __( 'Manage Elementor v4 variables', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		$base = match ( $this->mode ) {
			'globals' => 'Returns the site\'s Elementor v4 global classes and global variables (colors, fonts, sizes) as Elementor reports them. Read this before changing them.',
			'classes' => 'Creates, updates or deletes Elementor v4 global classes through Elementor\'s own "manage classes" ability. A copy of all classes is saved first (see kuduy-snapshot-list), but undo is not automatic for classes.',
			default   => 'Creates, updates or deletes Elementor v4 global variables through Elementor\'s own ability. Snapshot first; undo with kuduy-snapshot-restore.',
		};
		$ability = self::ability( self::MODES[ $this->mode ][0] );
		$llm     = null !== $ability ? trim( (string) $ability->get_description_for_llm() ) : '';
		return '' === $llm ? $base : $base . "\n\nElementor's instructions:\n" . mb_substr( $llm, 0, 4000 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		if ( 'globals' === $this->mode ) {
			return array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			);
		}
		$schema = self::schema( self::MODES[ $this->mode ][0] );
		return null !== $schema && 'object' === ( $schema['type'] ?? null ) ? $schema : array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'result'       => array( 'description' => 'Elementor\'s answer, unchanged.' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'result' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return 'globals' === $this->mode ? Content::read_only() : array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return match ( $this->mode ) {
			'globals'   => 'kuduy:read',
			'classes'   => 'kuduy:write',
			default     => 'kuduy:admin',
		};
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		// Elementor's own permission callback is checked too, inside execute().
		return 'variables' === $this->mode ? 'manage_options' : 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		$base = Elementor::available();
		if ( true !== $base ) {
			return $base;
		}
		foreach ( self::MODES[ $this->mode ] as $id ) {
			$ability = self::ability( $id );
			if ( null === $ability ) {
				return 'Needs Elementor 4.3 or later with v4 (atomic) features; this site\'s Elementor does not provide ' . $id . '.';
			}
			$ready = $ability->is_available();
			if ( is_wp_error( $ready ) ) {
				return 'Elementor says: ' . $ready->get_error_message();
			}
			if ( 'globals' !== $this->mode && null === self::schema( $id ) ) {
				return 'This Elementor version changed how ' . $id . ' describes its input; update KUDUY MCP.';
			}
		}
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'globals' === $this->mode ) {
			return null;
		}
		$kit_id = (int) \Elementor\Plugin::$instance->kits_manager->get_active_id();
		return array(
			'object_type' => 'variables' === $this->mode ? 'elementor_variables' : 'elementor_classes',
			'object_id'   => (string) $kit_id,
			'payload'     => 'variables' === $this->mode
				? array(
					'kit_id'    => $kit_id,
					'variables' => get_post_meta( $kit_id, '_elementor_global_variables', true ),
				)
				: array( 'classes' => self::run( 'elementor/global-classes-resource', array() ) ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError For classes (copy only) or when the Kit is gone.
	 */
	public function restore( array $payload ): void {
		if ( ! array_key_exists( 'variables', $payload ) ) {
			throw new ToolError( 'Global classes are stored by Elementor across several records; this snapshot is a copy for reference (see kuduy-snapshot-list), not an automatic undo. Recreate the classes with kuduy-elementor-v4-classes.' );
		}
		$kit_id = (int) ( $payload['kit_id'] ?? 0 );
		if ( ! get_post( $kit_id ) ) {
			throw new ToolError( 'The Elementor Kit no longer exists.' );
		}
		update_post_meta( $kit_id, '_elementor_global_variables', wp_slash( $payload['variables'] ) );
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When Elementor refuses or reports an error.
	 */
	public function execute( array $input ): array {
		if ( 'globals' === $this->mode ) {
			return array(
				'result' => array(
					'classes'   => self::decode( self::run( 'elementor/global-classes-resource', array() ) ),
					'variables' => self::decode( self::run( 'elementor/global-variables-resource', array() ) ),
				),
			);
		}
		return array( 'result' => self::run( self::MODES[ $this->mode ][0], $input ) );
	}

	/**
	 * Elementor's resources answer with a JSON string; return it as data when it is JSON.
	 *
	 * @param mixed $value Ability result.
	 * @return mixed
	 */
	private static function decode( mixed $value ): mixed {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		$data = json_decode( $value, true );
		return JSON_ERROR_NONE === json_last_error() ? $data : $value;
	}

	/**
	 * Run one Elementor ability after its own permission check.
	 *
	 * @param string               $id    Ability id.
	 * @param array<string, mixed> $input Input.
	 * @return mixed
	 * @throws ToolError When unavailable, not permitted or failed.
	 */
	private static function run( string $id, array $input ): mixed {
		$ability = self::ability( $id );
		if ( null === $ability ) {
			throw new ToolError( 'Elementor does not provide ' . esc_html( $id ) . ' on this site.' );
		}
		if ( ! $ability->check_permission() ) {
			// Elementor grants its v4 capabilities through database updates that run on the next wp-admin page load.
			$hint = current_user_can( 'manage_options' ) ? ' If Elementor was installed or updated recently, open any wp-admin page once so Elementor can finish its database update (it adds these permissions to administrators), then try again.' : '';
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- JSON text; the id is escaped, the hint is fixed text.
			throw new ToolError( 'Elementor does not allow your account to use ' . esc_html( $id ) . '.' . $hint );
		}
		$result = $ability->execute_guarded( $input );
		if ( is_wp_error( $result ) ) {
			throw new ToolError( 'Elementor: ' . esc_html( $result->get_error_message() ) );
		}
		if ( is_array( $result ) && 'error' === ( $result['status'] ?? '' ) ) {
			throw new ToolError( 'Elementor: ' . esc_html( (string) wp_json_encode( $result ) ) );
		}
		return $result;
	}

	/**
	 * Elementor ability object from its in-process registry, or null.
	 *
	 * @param string $id Ability id.
	 */
	private static function ability( string $id ): ?object {
		if ( true !== Elementor::available() || ! isset( \Elementor\Plugin::$instance->modules_manager ) ) {
			return null;
		}
		$module = \Elementor\Plugin::$instance->modules_manager->get_modules( 'mcp' );
		if ( ! is_object( $module ) || ! method_exists( $module, 'registry' ) ) {
			return null;
		}
		$ability = $module->registry()->find_by_id( $id );
		return is_object( $ability ) && method_exists( $ability, 'execute_guarded' ) && method_exists( $ability, 'check_permission' ) ? $ability : null;
	}

	/**
	 * Input JSON Schema Elementor declares for an ability (read from its protected definition), or null.
	 *
	 * @param string $id Ability id.
	 * @return array<string, mixed>|null
	 */
	private static function schema( string $id ): ?array {
		$ability = self::ability( $id );
		if ( null === $ability ) {
			return null;
		}
		try {
			$method = new \ReflectionMethod( $ability, 'definition' );
			$method->setAccessible( true );
			$definition = $method->invoke( $ability );
		} catch ( \ReflectionException $e ) {
			return null;
		}
		$schema = is_object( $definition ) && isset( $definition->input_schema ) ? $definition->input_schema : null;
		return is_array( $schema ) ? $schema : null;
	}
}
