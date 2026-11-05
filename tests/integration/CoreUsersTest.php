<?php
/**
 * Phase 4: users and roles — visibility, no secrets, role changes, lockout guards, undo (test matrix C6, P3, P4).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class CoreUsersTest extends TestCase {

	use CallsTools;

	private static int $admin;

	private static int $editor;

	private static int $subscriber;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin      = self::make_user( 'administrator' );
		self::$editor     = self::make_user( 'editor' );
		self::$subscriber = self::make_user( 'subscriber' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	public function test_subscriber_sees_only_themselves(): void {
		wp_set_current_user( self::$subscriber );

		$me = $this->ok( 'kuduy-user-get', array( 'id' => self::$subscriber ) );
		$this->assertSame( array( 'subscriber' ), $me['roles'] );
		$this->assertContains( 'read', $me['capabilities'] );
		$this->fails( 'kuduy-user-get', array( 'id' => self::$admin ), 'other users need list_users' );
		$this->fails( 'kuduy-user-list', array(), 'listing users needs list_users' );
		$this->fails( 'kuduy-role-list', array(), 'roles need list_users' );
	}

	public function test_list_and_get_never_contain_password_hashes(): void {
		$list = $this->ok( 'kuduy-user-list', array( 'per_page' => 100 ) );
		$this->assertContains( self::$editor, array_column( $list['users'], 'id' ) );

		$get  = $this->ok( 'kuduy-user-get', array( 'id' => self::$editor ) );
		$hash = get_userdata( self::$editor )->user_pass;
		$this->assertNotSame( '', $hash );
		foreach ( array( $list, $get ) as $result ) {
			$json = (string) wp_json_encode( $result );
			$this->assertStringNotContainsString( $hash, $json, 'password hash never returned (P4)' );
			$this->assertStringNotContainsString( 'user_pass', $json );
			$this->assertStringNotContainsString( 'user_activation_key', $json );
		}
	}

	public function test_roles_show_capabilities_and_what_you_may_assign(): void {
		$roles = array_column( $this->ok( 'kuduy-role-list' )['roles'], null, 'name' );

		$this->assertTrue( $roles['administrator']['assignable'] );
		$this->assertContains( 'manage_options', $roles['administrator']['capabilities'] );
		$this->assertNotContains( 'manage_options', $roles['editor']['capabilities'] );
	}

	public function test_create_needs_create_users_and_sets_no_known_password(): void {
		wp_set_current_user( self::$editor );
		$this->fails(
			'kuduy-user-create',
			array(
				'username' => 'kuduy_by_editor',
				'email'    => 'by-editor@example.test',
			),
			'editors cannot create users'
		);

		wp_set_current_user( self::$admin );
		$new = $this->ok(
			'kuduy-user-create',
			array(
				'username'          => 'kuduy_new_writer',
				'email'             => 'new-writer@example.test',
				'display_name'      => 'New Writer',
				'role'              => 'author',
				'send_notification' => false,
			)
		);
		$this->assertSame( array( 'author' ), $new['roles'] );
		$this->assertSame( 'New Writer', $new['display_name'] );
		$this->assertStringNotContainsString( 'pass', strtolower( implode( ',', array_keys( $new ) ) ) );

		$this->fails(
			'kuduy-user-create',
			array(
				'username' => 'kuduy_bad_role',
				'email'    => 'bad-role@example.test',
				'role'     => 'emperor',
			),
			'unknown role'
		);
	}

	public function test_role_change_and_undo(): void {
		$user = self::make_user( 'editor' );

		$changed = $this->ok(
			'kuduy-user-update',
			array(
				'id'         => $user,
				'role'       => 'author',
				'first_name' => 'Changed',
			)
		);
		$this->assertSame( array( 'author' ), $changed['roles'] );
		$this->assertSame( 'Changed', $changed['first_name'] );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $changed['_snapshot_id'] ) );
		$back = get_userdata( $user );
		$this->assertSame( array( 'editor' ), array_values( $back->roles ) );
		$this->assertSame( '', $back->first_name );

		wp_set_current_user( self::$editor );
		$this->fails(
			'kuduy-user-update',
			array(
				'id'   => $user,
				'role' => 'administrator',
			),
			'editors cannot promote anyone'
		);
	}

	public function test_nobody_changes_their_own_role(): void {
		$this->fails(
			'kuduy-user-update',
			array(
				'id'   => self::$admin,
				'role' => 'subscriber',
			),
			'own role'
		);
		$this->assertContains( 'administrator', get_userdata( self::$admin )->roles );
		$this->ok(
			'kuduy-user-update',
			array(
				'id'           => self::$admin,
				'display_name' => 'Admin Self',
			)
		);
	}

	public function test_last_administrator_cannot_be_demoted(): void {
		add_role(
			'kuduy_manager',
			'Kuduy manager',
			array(
				'read'          => true,
				'list_users'    => true,
				'edit_users'    => true,
				'promote_users' => true,
			)
		);
		$manager = self::make_user( 'kuduy_manager' );
		$others  = array_diff(
			get_users(
				array(
					'role'   => 'administrator',
					'fields' => 'ID',
				)
			),
			array( (string) self::$admin )
		);
		foreach ( $others as $id ) {
			get_userdata( (int) $id )->set_role( 'editor' );
		}
		try {
			wp_set_current_user( $manager );
			$message = $this->fails(
				'kuduy-user-update',
				array(
					'id'   => self::$admin,
					'role' => 'editor',
				),
				'last administrator'
			);
			$this->assertStringContainsString( 'last administrator', $message );
			$this->assertContains( 'administrator', get_userdata( self::$admin )->roles );
		} finally {
			foreach ( $others as $id ) {
				get_userdata( (int) $id )->set_role( 'administrator' );
			}
			remove_role( 'kuduy_manager' );
		}
	}
}
