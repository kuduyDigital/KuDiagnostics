<?php
/**
 * Phase 4: menus and site settings — permissions, allow-list, undo (test matrix C8).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class CoreSiteTest extends TestCase {

	use CallsTools;

	private static int $admin;

	private static int $editor;

	private static int $page;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$editor = self::make_user( 'editor' );
		self::$page   = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Kuduy About',
			)
		);
		register_nav_menus( array( 'kuduy-primary' => 'Kuduy primary' ) );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	// ------------------------------------------------------------------ menus.

	public function test_menus_need_edit_theme_options(): void {
		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-menu-list', array(), 'editors cannot manage menus' );
		$this->fails( 'kuduy-menu-create', array( 'name' => 'Kuduy editor menu' ), 'editors cannot manage menus' );
	}

	public function test_build_a_menu_with_page_custom_link_and_submenu(): void {
		$menu = $this->ok( 'kuduy-menu-create', array( 'name' => 'Kuduy main' ) );
		$page = $this->ok(
			'kuduy-menu-item-add',
			array(
				'menu_id'   => $menu['id'],
				'type'      => 'post_type',
				'object_id' => self::$page,
			)
		);
		$this->assertSame( 'Kuduy About', $page['title'] );
		$this->assertSame( get_permalink( self::$page ), $page['url'] );

		$link = $this->ok(
			'kuduy-menu-item-add',
			array(
				'menu_id'     => $menu['id'],
				'type'        => 'custom',
				'url'         => 'https://example.test/docs',
				'title'       => 'Docs',
				'parent_item' => $page['id'],
				'target'      => '_blank',
			)
		);
		$this->assertSame( $page['id'], $link['parent_item'] );

		$read = $this->ok( 'kuduy-menu-get', array( 'menu_id' => $menu['id'] ) );
		$this->assertSame( array( 'Kuduy About', 'Docs' ), array_column( $read['items'], 'title' ) );

		$this->fails(
			'kuduy-menu-item-add',
			array(
				'menu_id' => $menu['id'],
				'type'    => 'custom',
				'title'   => 'No URL',
			),
			'custom link needs a URL'
		);
		$draft = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => 'Kuduy draft page',
			)
		);
		$this->fails(
			'kuduy-menu-item-add',
			array(
				'menu_id'   => $menu['id'],
				'type'      => 'post_type',
				'object_id' => $draft,
			),
			'only published pages'
		);
	}

	public function test_item_update_keeps_other_fields_and_undo_works(): void {
		$menu = $this->ok( 'kuduy-menu-create', array( 'name' => 'Kuduy footer' ) );
		$item = $this->ok(
			'kuduy-menu-item-add',
			array(
				'menu_id' => $menu['id'],
				'type'    => 'custom',
				'url'     => 'https://example.test/a',
				'title'   => 'Alpha',
				'target'  => '_blank',
			)
		);

		$renamed = $this->ok(
			'kuduy-menu-item-update',
			array(
				'item_id' => $item['id'],
				'title'   => 'Beta',
			)
		);
		$this->assertSame( 'Beta', $renamed['title'] );
		$this->assertSame( 'https://example.test/a', $renamed['url'], 'fields not given are kept' );
		$this->assertSame( '_blank', $renamed['target'] );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $renamed['_snapshot_id'] ) );
		$this->assertSame( 'Alpha', $this->ok( 'kuduy-menu-get', array( 'menu_id' => $menu['id'] ) )['items'][0]['title'] );

		$deleted = $this->ok( 'kuduy-menu-item-delete', array( 'item_id' => $item['id'] ) );
		$this->assertSame( array(), $this->ok( 'kuduy-menu-get', array( 'menu_id' => $menu['id'] ) )['items'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $deleted['_snapshot_id'] ) );
		$this->assertSame( array( 'Alpha' ), array_column( $this->ok( 'kuduy-menu-get', array( 'menu_id' => $menu['id'] ) )['items'], 'title' ) );
	}

	public function test_assign_location_and_undo(): void {
		$menu   = $this->ok( 'kuduy-menu-create', array( 'name' => 'Kuduy located' ) );
		$before = (array) get_theme_mod( 'nav_menu_locations', array() );

		$assigned = $this->ok(
			'kuduy-menu-assign',
			array(
				'location' => 'kuduy-primary',
				'menu_id'  => $menu['id'],
			)
		);
		$list     = $this->ok( 'kuduy-menu-list' );
		$this->assertContains(
			array(
				'location'    => 'kuduy-primary',
				'description' => 'Kuduy primary',
				'menu_id'     => $menu['id'],
			),
			$list['locations']
		);

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $assigned['_snapshot_id'] ) );
		$this->assertSame( (int) ( $before['kuduy-primary'] ?? 0 ), (int) ( get_theme_mod( 'nav_menu_locations', array() )['kuduy-primary'] ?? 0 ) );
		$this->fails(
			'kuduy-menu-assign',
			array(
				'location' => 'nowhere',
				'menu_id'  => $menu['id'],
			),
			'unknown location'
		);
	}

	// --------------------------------------------------------------- settings.

	public function test_settings_need_manage_options(): void {
		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-settings-get', array(), 'editors cannot read settings' );
		$this->fails( 'kuduy-settings-update', array( 'blogname' => 'Hacked' ), 'editors cannot change settings' );
	}

	public function test_settings_update_and_undo(): void {
		$before = get_option( 'blogname' );
		$get    = $this->ok( 'kuduy-settings-get' );
		$this->assertSame( $before, $get['settings']['blogname'] );
		$this->assertContains( 'siteurl', $get['read_only'] );

		$changed = $this->ok(
			'kuduy-settings-update',
			array(
				'blogname'        => 'Kuduy Test Site',
				'posts_per_page'  => 7,
				'timezone_string' => 'Europe/Berlin',
			)
		);
		$this->assertSame( 'Kuduy Test Site', get_option( 'blogname' ) );
		$this->assertSame( 7, (int) get_option( 'posts_per_page' ) );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $changed['_snapshot_id'] ) );
		$this->assertSame( $before, get_option( 'blogname' ) );
	}

	public function test_sensitive_and_invalid_settings_are_refused(): void {
		foreach ( array( 'siteurl', 'home', 'admin_email', 'users_can_register', 'default_role', 'permalink_structure' ) as $name ) {
			$this->fails( 'kuduy-settings-update', array( $name => '1' ), $name . ' is read-only here' );
		}
		$this->assertSame( '0', (string) get_option( 'users_can_register' ) );
		$this->fails( 'kuduy-settings-update', array( 'timezone_string' => 'Mars/Base' ), 'bad timezone' );
		$this->fails(
			'kuduy-settings-update',
			array(
				'show_on_front' => 'page',
				'page_on_front' => 999999,
			),
			'front page must be a published page'
		);
		$this->fails( 'kuduy-settings-update', array(), 'nothing to change' );
	}
}
