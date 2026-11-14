<?php
/**
 * Beaver Builder tools on the real plugin (Beaver Builder Lite, test matrix BB1–BB6): status, modules and schema,
 * create, read, edit operations, all-or-nothing, undo, refusals.
 * Runs when Beaver Builder is installed (KUDUY_PLUGINS=beaver-builder-lite-version@…); skips otherwise.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class BeaverTest extends TestCase {

	use CallsTools;

	private static int $admin;

	private static int $author;

	private static int $subscriber;

	public static function setUpBeforeClass(): void {
		if ( ! class_exists( 'FLBuilderModel' ) ) {
			self::markTestSkipped( 'Beaver Builder is not active in this run (KUDUY_PLUGINS).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin      = self::make_user( 'administrator' );
		self::$author     = self::make_user( 'author' );
		self::$subscriber = self::make_user( 'subscriber' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	/**
	 * A fresh page: one two-column row, heading left, rich text right.
	 *
	 * @return array{0:int, 1:string, 2:string, 3:string, 4:string, 5:string} Page, row, left column, right column, heading, text.
	 */
	private function page(): array {
		$created = $this->ok(
			'kuduy-beaver-create',
			array(
				'title' => 'Kuduy beaver page',
				'rows'  => array(
					array(
						'cols'    => '2-cols',
						'columns' => array(
							array(
								array(
									'module'   => 'heading',
									'settings' => array( 'heading' => 'Beaver heading one' ),
								),
							),
							array(
								array(
									'module'   => 'rich-text',
									'settings' => array( 'text' => '<p>Beaver body text</p>' ),
								),
							),
						),
					),
				),
			)
		);
		$row     = $this->ok( 'kuduy-beaver-get', array( 'id' => $created['id'] ) )['rows'][0];
		$cols    = $row['children'][0]['children'];
		return array( $created['id'], $row['id'], $cols[0]['id'], $cols[1]['id'], $cols[0]['children'][0]['id'], $cols[1]['children'][0]['id'] );
	}

	/**
	 * Module nodes of a page by id, from the tree.
	 *
	 * @param int $page Page id.
	 * @return array<string, array<string, mixed>>
	 */
	private function modules( int $page ): array {
		$out  = array();
		$walk = static function ( array $nodes ) use ( &$walk, &$out ): void {
			foreach ( $nodes as $node ) {
				if ( 'module' === $node['type'] ) {
					$out[ $node['id'] ] = $node;
				}
				$walk( $node['children'] ?? array() );
			}
		};
		$walk( $this->ok( 'kuduy-beaver-get', array( 'id' => $page ) )['rows'] );
		return $out;
	}

	public function test_bb1_status_modules_and_schema(): void {
		$status = $this->ok( 'kuduy-beaver-status' );
		$this->assertSame( FL_BUILDER_VERSION, $status['version'] );
		$this->assertSame( 'lite', $status['edition'] );
		$this->assertContains( 'page', $status['post_types'] );
		$this->assertTrue( $status['can_build'] );

		$modules = array_column( $this->ok( 'kuduy-beaver-modules' )['modules'], null, 'slug' );
		$this->assertArrayHasKey( 'heading', $modules );
		$this->assertArrayHasKey( 'rich-text', $modules );
		$this->assertTrue( $modules['heading']['enabled'] );

		$schema = $this->ok( 'kuduy-beaver-module-schema', array( 'type' => 'heading' ) );
		$fields = array_column( $schema['settings'], null, 'name' );
		$this->assertSame( 'text', $fields['heading']['type'] );
		$this->assertContains( 'h3', $fields['tag']['options'] );
		$this->assertContains( 'heading', $schema['accepted'] );
		$this->assertNotEmpty( $this->ok( 'kuduy-beaver-module-schema', array( 'type' => 'row' ) )['accepted'] );
		$this->fails( 'kuduy-beaver-module-schema', array( 'type' => 'no-such-module' ), 'unknown module' );

		$integrations = array_column( $this->ok( 'kuduy-integrations', array( 'all' => true ) )['integrations'], null, 'id' );
		$this->assertTrue( $integrations['beaver-builder']['active'] );
		$this->assertTrue( $integrations['beaver-builder']['tools']['available'] );
	}

	public function test_bb2_create_builds_a_layout_and_post_content(): void {
		[ $page, , , , $heading, $text ] = $this->page();

		$this->assertSame( 'draft', get_post_status( $page ) );
		$this->assertNotEmpty( get_post_meta( $page, '_fl_builder_enabled', true ) );
		$tree = $this->ok( 'kuduy-beaver-get', array( 'id' => $page ) );
		$this->assertSame( 6, $tree['node_count'], 'row + column group + 2 columns + 2 modules' );
		$this->assertSame( array(), $tree['unregistered'] );
		$modules = $this->modules( $page );
		$this->assertSame( 'heading', $modules[ $heading ]['module'] );
		$this->assertSame( 'Beaver heading one', $modules[ $heading ]['settings']['heading'] );
		$this->assertSame( '<p>Beaver body text</p>', $modules[ $text ]['settings']['text'] );
		$this->assertArrayNotHasKey( 'tag', $modules[ $heading ]['settings'], 'Only settings that differ from the defaults are returned.' );

		$content = (string) get_post_field( 'post_content', $page );
		$this->assertStringContainsString( 'Beaver heading one', $content, 'Beaver Builder wrote the text into post_content.' );
		$this->assertStringContainsString( 'Beaver body text', $content );

		$this->assertContains( $page, array_column( $this->ok( 'kuduy-beaver-layouts', array( 'search' => 'Kuduy beaver page' ) )['layouts'], 'id' ) );
		$outline = $this->ok(
			'kuduy-beaver-get',
			array(
				'id'               => $page,
				'include_settings' => false,
			)
		);
		$this->assertArrayNotHasKey( 'settings', $outline['rows'][0]['children'][0]['children'][0]['children'][0] );

		$this->fails(
			'kuduy-beaver-create',
			array(
				'title' => 'Bad module',
				'rows'  => array(
					array(
						'columns' => array( array( array( 'module' => 'no-such-module' ) ) ),
					),
				),
			),
			'unknown module'
		);
		$this->assertNull( get_page_by_path( 'bad-module' ), 'Nothing is created when a module is invalid.' );
	}

	public function test_bb3_edit_operations_and_undo(): void {
		[ $page, $row, $left, $right, $heading, $text ] = $this->page();

		$edit = $this->ok(
			'kuduy-beaver-edit',
			array(
				'id'         => $page,
				'operations' => array(
					array(
						'action'   => 'update',
						'node_id'  => $heading,
						'settings' => array(
							'heading' => 'Beaver heading two',
							'tag'     => 'h3',
						),
					),
					array(
						'action'    => 'add_module',
						'parent_id' => $left,
						'module'    => 'html',
						'settings'  => array( 'html' => '<em>Added block</em>' ),
						'position'  => 0,
					),
					array(
						'action'  => 'duplicate',
						'node_id' => $text,
					),
					array(
						'action'  => 'add_row',
						'cols'    => '1-col',
						'columns' => array(
							array(
								array(
									'module'   => 'heading',
									'settings' => array( 'heading' => 'Second row' ),
								),
							),
						),
					),
				),
			)
		);
		$this->assertCount( 4, $edit['changed'] );
		$this->assertSame( 'draft', get_post_status( $page ), 'The post status does not change.' );
		$tree = $this->ok( 'kuduy-beaver-get', array( 'id' => $page ) );
		$this->assertSame( 12, $tree['node_count'] );
		$this->assertCount( 2, $tree['rows'] );
		$modules = $this->modules( $page );
		$this->assertSame( 'Beaver heading two', $modules[ $heading ]['settings']['heading'] );
		$this->assertSame( 'h3', $modules[ $heading ]['settings']['tag'] );
		$left_children = $tree['rows'][0]['children'][0]['children'][0]['children'];
		$this->assertSame( array( 'html', 'heading' ), array_column( $left_children, 'module' ), 'The new module went to position 0.' );
		$this->assertCount( 2, $tree['rows'][0]['children'][0]['children'][1]['children'], 'The text module was duplicated in its column.' );
		$content = (string) get_post_field( 'post_content', $page );
		$this->assertStringContainsString( 'Beaver heading two', $content );
		$this->assertStringContainsString( 'Second row', $content );

		$html = (string) array_search( 'html', array_map( static fn( array $m ): string => $m['module'], $modules ), true );
		$more = $this->ok(
			'kuduy-beaver-edit',
			array(
				'id'         => $page,
				'operations' => array(
					array(
						'action'    => 'move',
						'node_id'   => $html,
						'parent_id' => $right,
						'position'  => 0,
					),
					array(
						'action'   => 'move',
						'node_id'  => $tree['rows'][1]['id'],
						'position' => 0,
					),
					array(
						'action'  => 'remove',
						'node_id' => $text,
					),
				),
			)
		);
		$tree = $this->ok( 'kuduy-beaver-get', array( 'id' => $page ) );
		$this->assertSame( 11, $tree['node_count'] );
		$this->assertSame( $row, $tree['rows'][1]['id'], 'The second row moved to the top.' );
		$right_children = $tree['rows'][1]['children'][0]['children'][1]['children'];
		$this->assertSame( $html, $right_children[0]['id'] );
		$this->assertNotContains( $text, array_column( $right_children, 'id' ) );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $more['_snapshot_id'] ) );
		$tree = $this->ok( 'kuduy-beaver-get', array( 'id' => $page ) );
		$this->assertSame( 12, $tree['node_count'] );
		$this->assertSame( $row, $tree['rows'][0]['id'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $edit['_snapshot_id'] ) );
		$tree = $this->ok( 'kuduy-beaver-get', array( 'id' => $page ) );
		$this->assertSame( 6, $tree['node_count'], 'Undo restores the first layout.' );
		$this->assertSame( 'Beaver heading one', $this->modules( $page )[ $heading ]['settings']['heading'] );
		$this->assertStringContainsString( 'Beaver heading one', (string) get_post_field( 'post_content', $page ) );
	}

	public function test_bb4_one_failing_operation_saves_nothing(): void {
		[ $page, , $left, , $heading ] = $this->page();
		$before                        = get_post_meta( $page, '_fl_builder_data', true );

		$message = $this->fails(
			'kuduy-beaver-edit',
			array(
				'id'         => $page,
				'operations' => array(
					array(
						'action'   => 'update',
						'node_id'  => $heading,
						'settings' => array( 'heading' => 'Should not stay' ),
					),
					array(
						'action'    => 'add_module',
						'parent_id' => $left,
						'module'    => 'heading',
						'settings'  => array( 'no_such_setting' => 1 ),
					),
				),
			),
			'unknown setting in the second operation'
		);
		$this->assertStringContainsString( 'Operation 2', $message );
		$this->assertEquals( $before, get_post_meta( $page, '_fl_builder_data', true ), 'The published layout is untouched.' );
		$this->assertSame( 'Beaver heading one', $this->modules( $page )[ $heading ]['settings']['heading'] );

		$again = $this->ok(
			'kuduy-beaver-edit',
			array(
				'id'         => $page,
				'operations' => array(
					array(
						'action'   => 'update',
						'node_id'  => $heading,
						'settings' => array( 'heading' => 'After a failed call' ),
					),
				),
			)
		);
		$this->assertSame( array( 'updated ' . $heading ), $again['changed'], 'A failed call leaves no stale draft behind.' );
	}

	public function test_bb5_refusals(): void {
		[ $page, $row, $left, , $heading ] = $this->page();
		$op                                = static fn( array $o ): array => array(
			'id'         => $page,
			'operations' => array( $o ),
		);

		$this->fails(
			'kuduy-beaver-edit',
			$op(
				array(
					'action'  => 'remove',
					'node_id' => 'nope123',
				)
			),
			'unknown node'
		);
		$this->fails(
			'kuduy-beaver-edit',
			$op(
				array(
					'action'    => 'add_module',
					'parent_id' => $row,
					'module'    => 'heading',
				)
			),
			'a row is not a column'
		);
		$this->fails(
			'kuduy-beaver-edit',
			$op(
				array(
					'action'   => 'update',
					'node_id'  => $left,
					'settings' => array( 'size' => 10 ),
				)
			),
			'column widths are not changed'
		);
		$this->fails(
			'kuduy-beaver-edit',
			$op(
				array(
					'action'   => 'update',
					'node_id'  => $heading,
					'settings' => array( 'type' => 'html' ),
				)
			),
			'the module type cannot be changed'
		);

		$plain = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Not Beaver',
				'post_status' => 'draft',
			)
		);
		$this->fails( 'kuduy-beaver-get', array( 'id' => $plain ), 'not built with Beaver Builder' );

		// Unpublished changes made in the builder are never overwritten.
		$draft = \FLBuilderModel::get_layout_data( 'published', $page );
		foreach ( $draft as $node ) {
			if ( 'module' === $node->type ) {
				// get_layout_data() clones the nodes but not their settings objects.
				$node->settings          = clone $node->settings;
				$node->settings->heading = 'Unpublished builder change';
			}
		}
		\FLBuilderModel::update_layout_data( $draft, 'draft', $page );
		$this->fails(
			'kuduy-beaver-edit',
			$op(
				array(
					'action'  => 'duplicate',
					'node_id' => $heading,
				)
			),
			'the builder has unpublished changes'
		);
		$this->assertSame( 'Unpublished builder change', \FLBuilderModel::get_layout_data( 'draft', $page )[ $heading ]->settings->heading );

		wp_set_current_user( self::$author );
		$this->fails( 'kuduy-beaver-get', array( 'id' => $page ), 'an author may not read someone else\'s page' );
		$this->fails(
			'kuduy-beaver-create',
			array( 'title' => 'Author page' ),
			'an author may not create pages'
		);
		wp_set_current_user( self::$subscriber );
		$this->fails( 'kuduy-beaver-layouts', array(), 'a subscriber may not list layouts' );
	}

	public function test_bb6_markup_is_filtered_for_users_without_unfiltered_html(): void {
		[ $page, , , , , $text ] = $this->page();
		$editor                  = self::make_user( 'editor' );
		// Beaver Builder gives editors builder access by default; multisite-style restriction of unfiltered_html.
		$deny = static fn( array $caps, string $cap ): array => 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
		add_filter( 'map_meta_cap', $deny, 10, 2 );
		wp_set_current_user( $editor );
		try {
			$this->fails(
				'kuduy-beaver-edit',
				array(
					'id'         => $page,
					'operations' => array(
						array(
							'action'   => 'update',
							'node_id'  => $text,
							'settings' => array( 'text' => '<p>Hi</p><script>alert(1)</script>' ),
						),
					),
				),
				'script markup needs unfiltered_html'
			);
			$ok = $this->ok(
				'kuduy-beaver-edit',
				array(
					'id'         => $page,
					'operations' => array(
						array(
							'action'   => 'update',
							'node_id'  => $text,
							'settings' => array( 'text' => '<p>Plain <strong>markup</strong> is fine</p>' ),
						),
					),
				)
			);
			$this->assertSame( array( 'updated ' . $text ), $ok['changed'] );
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
		}
		$this->assertStringNotContainsString( '<script', (string) get_post_field( 'post_content', $page ) );
	}
}
