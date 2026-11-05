<?php
/**
 * Tools: kuduy-settings-get, kuduy-settings-update (one class, two modes).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * An allow-list of the Settings screens (General, Writing, Reading, Discussion), needing manage_options.
 * Read-only on purpose: site and home URL, admin email (core confirms changes by email), permalink structure
 * (sign-in depends on it), and membership + default role (together they could open admin sign-ups).
 */
final class Settings implements Tool, Snapshottable {

	/**
	 * Writable settings with their JSON Schema; values are also passed through core's sanitize_option().
	 */
	private const WRITABLE = array(
		'blogname'               => array( 'type' => 'string' ),
		'blogdescription'        => array( 'type' => 'string' ),
		'timezone_string'        => array( 'type' => 'string' ),
		'date_format'            => array( 'type' => 'string' ),
		'time_format'            => array( 'type' => 'string' ),
		'start_of_week'          => array(
			'type'    => 'integer',
			'minimum' => 0,
			'maximum' => 6,
		),
		'posts_per_page'         => array(
			'type'    => 'integer',
			'minimum' => 1,
			'maximum' => 100,
		),
		'default_category'       => array(
			'type'    => 'integer',
			'minimum' => 1,
		),
		'show_on_front'          => array(
			'type' => 'string',
			'enum' => array( 'posts', 'page' ),
		),
		'page_on_front'          => array(
			'type'    => 'integer',
			'minimum' => 0,
		),
		'page_for_posts'         => array(
			'type'    => 'integer',
			'minimum' => 0,
		),
		'blog_public'            => array(
			'type'        => 'integer',
			'enum'        => array( 0, 1 ),
			'description' => '0 asks search engines not to index the site.',
		),
		'default_comment_status' => array(
			'type' => 'string',
			'enum' => array( 'open', 'closed' ),
		),
		'default_ping_status'    => array(
			'type' => 'string',
			'enum' => array( 'open', 'closed' ),
		),
		'comment_moderation'     => array(
			'type' => 'integer',
			'enum' => array( 0, 1 ),
		),
		'comment_registration'   => array(
			'type' => 'integer',
			'enum' => array( 0, 1 ),
		),
		'thread_comments'        => array(
			'type' => 'integer',
			'enum' => array( 0, 1 ),
		),
	);

	private const READ_ONLY = array( 'siteurl', 'home', 'admin_email', 'permalink_structure', 'users_can_register', 'default_role', 'WPLANG' );

	/**
	 * Mode.
	 *
	 * @param string $mode get | update.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-settings-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'get' === $this->mode ? __( 'Read site settings', 'kuduy-mcp' ) : __( 'Update site settings', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'get' === $this->mode
			? 'Returns the main site settings (General, Writing, Reading, Discussion): title, tagline, URLs, timezone, date formats, front page, posts per page, search visibility, comment defaults. Marks which are read-only here.'
			: 'Changes allow-listed site settings: ' . implode( ', ', array_keys( self::WRITABLE ) ) . '. Site/home URL, admin email, permalinks, membership and default role are read-only here. Snapshot first; undo with kuduy-snapshot-restore.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => 'get' === $this->mode ? array() : self::WRITABLE,
			'additionalProperties' => false,
			'minProperties'        => 'get' === $this->mode ? 0 : 1,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'settings'     => array(
					'type'                 => 'object',
					'additionalProperties' => array( 'type' => array( 'string', 'integer', 'boolean' ) ),
				),
				'read_only'    => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'settings', 'read_only' ),
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
		return 'get' === $this->mode ? 'kuduy:read' : 'kuduy:admin';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'manage_options';
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
	public function snapshot( array $input ): array {
		$before = array();
		foreach ( array_intersect_key( $input, self::WRITABLE ) as $name => $value ) {
			$before[ $name ] = get_option( $name );
		}
		return array(
			'object_type' => 'settings',
			'object_id'   => implode( ',', array_keys( $before ) ),
			'payload'     => array( 'options' => $before ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 */
	public function restore( array $payload ): void {
		foreach ( array_intersect_key( (array) ( $payload['options'] ?? array() ), self::WRITABLE ) as $name => $value ) {
			update_option( $name, $value );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When a value is not valid for this site.
	 */
	public function execute( array $input ): array {
		if ( 'update' === $this->mode ) {
			$changes = array_intersect_key( $input, self::WRITABLE );
			self::check( $changes );
			foreach ( $changes as $name => $value ) {
				update_option( $name, $value );
			}
		}
		$settings = array();
		foreach ( array_merge( array_keys( self::WRITABLE ), self::READ_ONLY ) as $name ) {
			$value             = get_option( $name );
			$settings[ $name ] = is_scalar( $value ) ? $value : '';
		}
		return array(
			'settings'  => $settings,
			'read_only' => self::READ_ONLY,
		);
	}

	/**
	 * Values that need the site's own data.
	 *
	 * @param array<string, mixed> $changes Changes.
	 * @throws ToolError When a value does not fit.
	 */
	private static function check( array $changes ): void {
		if ( isset( $changes['timezone_string'] ) && '' !== $changes['timezone_string'] && ! in_array( $changes['timezone_string'], timezone_identifiers_list(), true ) ) {
			throw new ToolError( 'timezone_string must be a timezone name such as "Europe/Berlin".' );
		}
		foreach ( array( 'page_on_front', 'page_for_posts' ) as $name ) {
			$id = (int) ( $changes[ $name ] ?? 0 );
			if ( $id > 0 && ( 'page' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
				throw new ToolError( $name . ' must be a published page (or 0).' );
			}
		}
		if ( isset( $changes['default_category'] ) && ! get_term( (int) $changes['default_category'], 'category' ) instanceof \WP_Term ) {
			throw new ToolError( 'default_category must be an existing category.' );
		}
	}
}
