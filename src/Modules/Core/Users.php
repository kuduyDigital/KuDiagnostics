<?php
/**
 * Tools: kuduy-user-list, kuduy-user-get, kuduy-user-create, kuduy-user-update, kuduy-role-list (one class, five modes).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Same rules as core's REST users controller (list_users, create_users, edit_user, promote_user, editable roles,
 * no own-role downgrade). Passwords are never set or returned: new users get WordPress's own set-password email.
 * Deleting users is not offered.
 */
final class Users implements Tool, Snapshottable {

	private const PROFILE = array( 'email', 'display_name', 'first_name', 'last_name', 'url', 'description' );

	/**
	 * Mode.
	 *
	 * @param string $mode list | get | create | update | roles.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'roles' === $this->mode ? 'kuduy-role-list' : 'kuduy-user-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'list'   => __( 'List users', 'kuduy-mcp' ),
			'get'    => __( 'Read a user', 'kuduy-mcp' ),
			'create' => __( 'Create a user', 'kuduy-mcp' ),
			'update' => __( 'Update a user', 'kuduy-mcp' ),
			default  => __( 'List roles', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'list'   => 'Lists users with their roles, optionally filtered by role or search (login, email, name), with paging. Needs permission to list users.',
			'get'    => 'Returns one user (yourself, or anyone if you can list users): profile, roles and capabilities. Never returns passwords or keys.',
			'create' => 'Creates a user with a role you are allowed to assign. No password is set here: WordPress emails the new user a link to choose one (send_notification, default true).',
			'update' => 'Changes profile fields (email, names, website, bio) or the role of a user. You cannot change your own role, and the last administrator cannot be demoted. Snapshot first; undo with kuduy-snapshot-restore.',
			default  => 'Lists the site\'s roles with their capabilities, and which of them you may assign.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$id      = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$profile = array(
			'email'        => array(
				'type'   => 'string',
				'format' => 'email',
			),
			'display_name' => array(
				'type'      => 'string',
				'maxLength' => 250,
			),
			'first_name'   => array(
				'type'      => 'string',
				'maxLength' => 250,
			),
			'last_name'    => array(
				'type'      => 'string',
				'maxLength' => 250,
			),
			'url'          => array(
				'type'   => 'string',
				'format' => 'uri',
			),
			'description'  => array( 'type' => 'string' ),
			'role'         => array( 'type' => 'string' ),
		);
		$schema  = match ( $this->mode ) {
			'list'   => array(
				'properties' => array(
					'role'   => array( 'type' => 'string' ),
					'search' => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
				) + Content::paging_input(),
			),
			'get'    => array(
				'properties' => array( 'id' => $id ),
				'required'   => array( 'id' ),
			),
			'create' => array(
				'properties' => array(
					'username'          => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 60,
					),
					'send_notification' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				) + $profile,
				'required'   => array( 'username', 'email' ),
			),
			'update' => array(
				'properties' => array( 'id' => $id ) + $profile,
				'required'   => array( 'id' ),
			),
			default  => array( 'properties' => array() ),
		};//end match
		return array( 'type' => 'object' ) + $schema + array( 'additionalProperties' => false );
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		$user = array(
			'type'       => 'object',
			'properties' => array(
				'id'             => array( 'type' => 'integer' ),
				'username'       => array( 'type' => 'string' ),
				'email'          => array( 'type' => 'string' ),
				'display_name'   => array( 'type' => 'string' ),
				'first_name'     => array( 'type' => 'string' ),
				'last_name'      => array( 'type' => 'string' ),
				'url'            => array( 'type' => 'string' ),
				'description'    => array( 'type' => 'string' ),
				'roles'          => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'registered_gmt' => array( 'type' => 'string' ),
				'capabilities'   => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'_snapshot_id'   => array( 'type' => 'integer' ),
			),
			'required'   => array( 'id', 'username', 'display_name', 'roles' ),
		);
		return match ( $this->mode ) {
			'list'  => array(
				'type'       => 'object',
				'properties' => array(
					'users'    => array(
						'type'  => 'array',
						'items' => $user,
					),
					'total'    => array( 'type' => 'integer' ),
					'page'     => array( 'type' => 'integer' ),
					'per_page' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'users', 'total', 'page', 'per_page' ),
			),
			'roles' => array(
				'type'       => 'object',
				'properties' => array(
					'roles' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name'         => array( 'type' => 'string' ),
								'label'        => array( 'type' => 'string' ),
								'users'        => array( 'type' => 'integer' ),
								'assignable'   => array( 'type' => 'boolean' ),
								'capabilities' => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
							),
							'required'   => array( 'name', 'label', 'assignable', 'capabilities' ),
						),
					),
				),
				'required'   => array( 'roles' ),
			),
			default => $user,
		};//end match
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return in_array( $this->mode, array( 'list', 'get', 'roles' ), true ) ? Content::read_only() : array(
			'readOnlyHint'    => false,
			'destructiveHint' => 'update' === $this->mode,
			'idempotentHint'  => 'update' === $this->mode,
			'openWorldHint'   => 'create' === $this->mode,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return in_array( $this->mode, array( 'list', 'get', 'roles' ), true ) ? 'kuduy:read' : 'kuduy:admin';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		$id = (int) ( $input['id'] ?? 0 );
		return match ( $this->mode ) {
			'create' => 'create_users',
			'update' => $id > 0 ? array( 'edit_user', $id ) : 'edit_users',
			// Listing (no id): everyone may read their own account, so the tool is shown; each call checks the id.
			'get'    => 0 === $id || get_current_user_id() === $id ? 'read' : 'list_users',
			default  => 'list_users',
		};
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
		$user = 'update' === $this->mode ? get_userdata( (int) $input['id'] ) : false;
		return $user instanceof \WP_User ? array(
			'object_type' => 'user',
			'object_id'   => (string) $user->ID,
			'payload'     => array(
				'ID'           => $user->ID,
				'user_email'   => $user->user_email,
				'display_name' => $user->display_name,
				'first_name'   => $user->first_name,
				'last_name'    => $user->last_name,
				'user_url'     => $user->user_url,
				'description'  => $user->description,
				'roles'        => array_values( $user->roles ),
			),
		) : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the user is gone or WordPress refuses.
	 */
	public function restore( array $payload ): void {
		$user = get_userdata( (int) ( $payload['ID'] ?? 0 ) );
		if ( ! $user instanceof \WP_User ) {
			throw new ToolError( 'The user no longer exists.' );
		}
		$result = wp_update_user( wp_slash( array_diff_key( $payload, array( 'roles' => true ) ) ) );
		if ( is_wp_error( $result ) ) {
			throw new ToolError( 'Undo failed: ' . esc_html( $result->get_error_message() ) );
		}
		$user->set_role( '' );
		foreach ( (array) ( $payload['roles'] ?? array() ) as $role ) {
			$user->add_role( (string) $role );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the request is not allowed or WordPress refuses.
	 */
	public function execute( array $input ): array {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		return match ( $this->mode ) {
			'list'   => $this->list( $input ),
			'get'    => $this->get( (int) $input['id'] ),
			'create' => $this->create( $input ),
			'update' => $this->update( $input ),
			default  => $this->roles(),
		};
	}

	/**
	 * List users.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private function list( array $input ): array {
		$per_page = (int) ( $input['per_page'] ?? 20 );
		$page     = (int) ( $input['page'] ?? 1 );
		$args     = array(
			'number'      => $per_page,
			'paged'       => $page,
			'orderby'     => 'registered',
			'order'       => 'DESC',
			'count_total' => true,
		);
		if ( isset( $input['role'] ) && '' !== $input['role'] ) {
			$args['role'] = (string) $input['role'];
		}
		if ( isset( $input['search'] ) && '' !== $input['search'] ) {
			$args['search'] = '*' . (string) $input['search'] . '*';
		}
		$query = new \WP_User_Query( $args );
		return array(
			'users'    => array_map( static fn( \WP_User $u ): array => self::shape( $u, false ), (array) $query->get_results() ),
			'total'    => (int) $query->get_total(),
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * One user.
	 *
	 * @param int $id User id.
	 * @return array<string, mixed>
	 * @throws ToolError When the user does not exist.
	 */
	private function get( int $id ): array {
		$user = get_userdata( $id );
		if ( ! $user instanceof \WP_User ) {
			throw new ToolError( 'User not found.' );
		}
		AuditLog::target( 'user', (string) $user->ID );
		return self::shape( $user, true );
	}

	/**
	 * Create a user.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the role is not allowed or WordPress refuses.
	 */
	private function create( array $input ): array {
		$role = (string) ( $input['role'] ?? get_option( 'default_role', 'subscriber' ) );
		self::check_role( 0, $role );
		$text = static fn( string $key ): string => wp_slash( (string) ( $input[ $key ] ?? '' ) );
		$id   = wp_insert_user(
			array(
				'user_login'   => $text( 'username' ),
				// Random and never returned: the user sets their own through WordPress's email link.
				'user_pass'    => wp_generate_password( 32, true, true ),
				'role'         => $role,
				'user_email'   => $text( 'email' ),
				'display_name' => $text( 'display_name' ),
				'first_name'   => $text( 'first_name' ),
				'last_name'    => $text( 'last_name' ),
				'user_url'     => $text( 'url' ),
				'description'  => $text( 'description' ),
			)
		);
		if ( is_wp_error( $id ) ) {
			throw new ToolError( 'WordPress refused the user: ' . esc_html( $id->get_error_message() ) );
		}
		AuditLog::target( 'user', (string) $id );
		if ( $input['send_notification'] ?? true ) {
			wp_new_user_notification( $id, null, 'user' );
		}
		$user = get_userdata( $id );
		if ( ! $user instanceof \WP_User ) {
			throw new ToolError( 'The user was created but could not be read back.' );
		}
		return self::shape( $user, false );
	}

	/**
	 * Update a user.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the change is not allowed or WordPress refuses.
	 */
	private function update( array $input ): array {
		$user = get_userdata( (int) $input['id'] );
		if ( ! $user instanceof \WP_User ) {
			throw new ToolError( 'User not found.' );
		}
		AuditLog::target( 'user', (string) $user->ID );
		$role = isset( $input['role'] ) ? (string) $input['role'] : null;
		if ( null !== $role && array( $role ) !== array_values( $user->roles ) ) {
			if ( ! current_user_can( 'promote_user', $user->ID ) ) {
				throw new ToolError( 'Your account cannot change this user\'s role.' );
			}
			self::check_role( $user->ID, $role );
			if ( in_array( 'administrator', $user->roles, true ) && 'administrator' !== $role && count(
				get_users(
					array(
						'role'   => 'administrator',
						'fields' => 'ID',
					)
				)
			) <= 1 ) {
				throw new ToolError( 'This is the last administrator; give another user the administrator role first.' );
			}
		}
		$data = self::profile( $input );
		if ( $data ) {
			$result = wp_update_user( wp_slash( array( 'ID' => $user->ID ) + $data ) );
			if ( is_wp_error( $result ) ) {
				throw new ToolError( 'WordPress refused the change: ' . esc_html( $result->get_error_message() ) );
			}
		}
		if ( null !== $role ) {
			$user->set_role( $role );
		}
		$fresh = get_userdata( $user->ID );
		return self::shape( $fresh instanceof \WP_User ? $fresh : $user, false );
	}

	/**
	 * Roles and capabilities.
	 *
	 * @return array<string, mixed>
	 */
	private function roles(): array {
		$counts     = count_users();
		$assignable = get_editable_roles();
		$out        = array();
		foreach ( wp_roles()->roles as $name => $role ) {
			$out[] = array(
				'name'         => (string) $name,
				'label'        => translate_user_role( (string) $role['name'] ),
				'users'        => (int) ( $counts['avail_roles'][ $name ] ?? 0 ),
				'assignable'   => isset( $assignable[ $name ] ),
				'capabilities' => array_keys( array_filter( (array) $role['capabilities'] ) ),
			);
		}
		return array( 'roles' => $out );
	}

	/**
	 * Role must exist, be assignable by the current user, and not take edit_users away from yourself.
	 *
	 * @param int    $user_id Target user (0 when creating).
	 * @param string $role    Role.
	 * @throws ToolError When the role is not allowed.
	 */
	private static function check_role( int $user_id, string $role ): void {
		$object = wp_roles()->get_role( $role );
		if ( null === $object ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( 'Role "' . $role . '" does not exist. See kuduy-role-list.' );
		}
		if ( get_current_user_id() === $user_id ) {
			throw new ToolError( 'You cannot change your own role.' );
		}
		if ( ! isset( get_editable_roles()[ $role ] ) ) {
			throw new ToolError( 'Your account is not allowed to give users that role.' );
		}
	}

	/**
	 * Profile fields from input in wp_insert_user() names.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, string>
	 */
	private static function profile( array $input ): array {
		$map = array(
			'email'        => 'user_email',
			'display_name' => 'display_name',
			'first_name'   => 'first_name',
			'last_name'    => 'last_name',
			'url'          => 'user_url',
			'description'  => 'description',
		);
		$out = array();
		foreach ( self::PROFILE as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $map[ $key ] ] = (string) $input[ $key ];
			}
		}
		return $out;
	}

	/**
	 * User for output: never the password hash, activation key or session tokens.
	 *
	 * @param \WP_User $user User.
	 * @param bool     $caps Include effective capabilities.
	 * @return array<string, mixed>
	 */
	private static function shape( \WP_User $user, bool $caps ): array {
		$out = array(
			'id'             => $user->ID,
			'username'       => $user->user_login,
			'email'          => $user->user_email,
			'display_name'   => $user->display_name,
			'first_name'     => (string) $user->first_name,
			'last_name'      => (string) $user->last_name,
			'url'            => $user->user_url,
			'description'    => (string) $user->description,
			'roles'          => array_values( $user->roles ),
			'registered_gmt' => $user->user_registered,
		);
		if ( $caps ) {
			$out['capabilities'] = array_keys( array_filter( $user->allcaps ) );
		}
		return $out;
	}
}
