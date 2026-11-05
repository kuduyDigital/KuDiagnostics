<?php
/**
 * Phase 5 write pass on real Elementor (test matrix EL3, EL4, EL5, EL6, EL7): create, patch edits, responsive
 * preservation, validation, unregistered-widget guard, undo, Kit update, verification.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class ElementorWriteTest extends TestCase {

	use CallsTools;

	private static int $admin;

	private static int $editor;

	private static int $author;

	public static function setUpBeforeClass(): void {
		if ( ! did_action( 'elementor/loaded' ) ) {
			self::markTestSkipped( 'Elementor is not active in this run (KUDUY_ELEMENTOR=none).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$editor = self::make_user( 'editor' );
		self::$author = self::make_user( 'author' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	/**
	 * A fresh page: container > heading (with desktop and mobile alignment) + text.
	 *
	 * @return array{0:int, 1:string, 2:string} Page id, container id, heading id.
	 */
	private function page(): array {
		$created = $this->ok(
			'kuduy-elementor-create',
			array(
				'title'    => 'Kuduy built page',
				'elements' => array(
					array(
						'elType'   => 'container',
						'elements' => array(
							array(
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => array(
									'title'        => 'Original title',
									'align'        => 'center',
									'align_mobile' => 'start',
								),
							),
							array(
								'elType'     => 'widget',
								'widgetType' => 'text-editor',
								'settings'   => array( 'editor' => '<p>Body text</p>' ),
							),
						),
					),
				),
			)
		);
		$tree    = $this->ok( 'kuduy-elementor-get', array( 'id' => $created['id'] ) )['elements'];
		return array( $created['id'], (string) $tree[0]['id'], (string) $tree[0]['elements'][0]['id'] );
	}

	/**
	 * Heading settings as stored.
	 *
	 * @param int    $page    Page id.
	 * @param string $heading Heading id.
	 * @return array<string, mixed>
	 */
	private function heading( int $page, string $heading ): array {
		return $this->ok(
			'kuduy-elementor-get',
			array(
				'id'         => $page,
				'element_id' => $heading,
			)
		)['elements'][0]['settings'];
	}

	public function test_create_builds_an_elementor_page(): void {
		[ $page ] = $this->page();

		$this->assertSame( 'builder', get_post_meta( $page, '_elementor_edit_mode', true ) );
		$this->assertSame( 'draft', get_post_status( $page ) );
		$this->assertSame( 3, $this->ok( 'kuduy-elementor-get', array( 'id' => $page ) )['element_count'] );
	}

	public function test_el4_update_changes_only_the_named_keys(): void {
		[ $page, , $heading ] = $this->page();

		$this->ok(
			'kuduy-elementor-edit',
			array(
				'id'         => $page,
				'operations' => array(
					array(
						'action'     => 'update',
						'element_id' => $heading,
						'settings'   => array(
							'title'        => 'New title',
							'align_tablet' => 'end',
						),
					),
				),
			)
		);
		$settings = $this->heading( $page, $heading );
		$this->assertSame( 'New title', $settings['title'] );
		$this->assertSame( 'center', $settings['align'], 'desktop value untouched' );
		$this->assertSame( 'start', $settings['align_mobile'], 'mobile value untouched' );
		$this->assertSame( 'end', $settings['align_tablet'] );

		$this->ok(
			'kuduy-elementor-edit',
			array(
				'id'         => $page,
				'operations' => array(
					array(
						'action'     => 'update',
						'element_id' => $heading,
						'unset'      => array( 'align_mobile' ),
					),
				),
			)
		);
		$settings = $this->heading( $page, $heading );
		$this->assertArrayNotHasKey( 'align_mobile', $settings );
		$this->assertSame( 'center', $settings['align'] );
	}

	public function test_one_invalid_operation_saves_nothing(): void {
		[ $page, , $heading ] = $this->page();

		$message = $this->fails(
			'kuduy-elementor-edit',
			array(
				'id'         => $page,
				'operations' => array(
					array(
						'action'     => 'update',
						'element_id' => $heading,
						'settings'   => array( 'title' => 'Should not stay' ),
					),
					array(
						'action'     => 'update',
						'element_id' => $heading,
						'settings'   => array( 'no_such_setting' => 1 ),
					),
				),
			),
			'unknown setting'
		);
		$this->assertStringContainsString( 'Operation 2', $message );
		$this->assertSame( 'Original title', $this->heading( $page, $heading )['title'] );
	}

	public function test_add_duplicate_move_remove_and_nesting_rules(): void {
		[ $page, $container, $heading ] = $this->page();

		$result = $this->ok(
			'kuduy-elementor-edit',
			array(
				'id'         => $page,
				'operations' => array(
					array(
						'action'    => 'add',
						'parent_id' => $container,
						'index'     => 0,
						'element'   => array(
							'elType'     => 'widget',
							'widgetType' => 'button',
							'settings'   => array( 'text' => 'Buy now' ),
						),
					),
					array(
						'action'     => 'duplicate',
						'element_id' => $heading,
					),
				),
			)
		);
		$this->assertSame( 5, $result['element_count'] );
		$tree    = $this->ok( 'kuduy-elementor-get', array( 'id' => $page ) )['elements'][0]['elements'];
		$ids     = array_column( $tree, 'id' );
		$button  = $ids[0];
		$copy_id = $ids[2];
		$this->assertSame( 'button', $tree[0]['widgetType'] );
		$this->assertNotSame( $heading, $copy_id, 'duplicate gets a fresh id' );
		$this->assertCount( 4, array_unique( $ids ) );

		$this->ok(
			'kuduy-elementor-edit',
			array(
				'id'         => $page,
				'operations' => array(
					array(
						'action'     => 'move',
						'element_id' => $button,
						'index'      => 3,
					),
					array(
						'action'     => 'remove',
						'element_id' => $copy_id,
					),
				),
			)
		);
		$tree = $this->ok( 'kuduy-elementor-get', array( 'id' => $page ) )['elements'][0]['elements'];
		$this->assertSame( array( $heading, (string) $tree[1]['id'], $button ), array_column( $tree, 'id' ) );

		foreach ( array(
			array(
				'widget at the top level',
				array(
					'elType'     => 'widget',
					'widgetType' => 'heading',
				),
				'document',
			),
			array( 'column in a container', array( 'elType' => 'column' ), $container ),
			array(
				'unknown widget',
				array(
					'elType'     => 'widget',
					'widgetType' => 'kuduy-nope',
				),
				$container,
			),
		) as [ $label, $element, $parent ] ) {
			$this->fails(
				'kuduy-elementor-edit',
				array(
					'id'         => $page,
					'operations' => array(
						array(
							'action'    => 'add',
							'parent_id' => $parent,
							'element'   => $element,
						),
					),
				),
				$label
			);
		}
	}

	public function test_undo_restores_the_previous_page(): void {
		[ $page, , $heading ] = $this->page();

		$edit = $this->ok(
			'kuduy-elementor-edit',
			array(
				'id'         => $page,
				'operations' => array(
					array(
						'action'     => 'remove',
						'element_id' => $heading,
					),
				),
			)
		);
		$this->assertSame( 2, $edit['element_count'] );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $edit['_snapshot_id'] ) );
		$this->assertSame( 'Original title', $this->heading( $page, $heading )['title'] );
	}

	public function test_pages_with_inactive_addon_widgets_are_not_saved(): void {
		$page = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => 'Kuduy addon page',
			)
		);
		update_post_meta( $page, '_elementor_edit_mode', 'builder' );
		$data = (string) wp_json_encode(
			array(
				array(
					'id'         => 'add0001',
					'elType'     => 'widget',
					'widgetType' => 'kuduy-missing-addon-widget',
					'settings'   => array( 'x' => 1 ),
					'elements'   => array(),
				),
			)
		);
		update_post_meta( $page, '_elementor_data', wp_slash( $data ) );

		$message = $this->fails(
			'kuduy-elementor-edit',
			array(
				'id'         => $page,
				'operations' => array(
					array(
						'action'     => 'remove',
						'element_id' => 'add0001',
					),
				),
			),
			'unregistered widget on the page'
		);
		$this->assertStringContainsString( 'kuduy-missing-addon-widget', $message );
		$this->assertSame( $data, get_post_meta( $page, '_elementor_data', true ), 'stored data untouched' );
	}

	public function test_permissions_and_kses(): void {
		[ $page, , $heading ] = $this->page();

		wp_set_current_user( self::$author );
		$this->fails(
			'kuduy-elementor-edit',
			array(
				'id'         => $page,
				'operations' => array(
					array(
						'action'     => 'remove',
						'element_id' => $heading,
					),
				),
			),
			'authors cannot edit other people\'s pages'
		);

		$own = $this->ok(
			'kuduy-elementor-create',
			array(
				'title'     => 'Author post',
				'post_type' => 'post',
				'elements'  => array(
					array(
						'elType'   => 'container',
						'elements' => array(
							array(
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => array( 'title' => 'Safe<script>alert(1)</script>' ),
							),
						),
					),
				),
			)
		);
		$this->assertStringNotContainsString( '<script>', (string) get_post_meta( $own['id'], '_elementor_data', true ), 'Elementor applies kses without unfiltered_html (EL5)' );
	}

	public function test_kit_update_adds_and_changes_colors_with_undo(): void {
		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-elementor-kit-update', array( 'colors' => array( array( 'color' => '#123456' ) ) ), 'editors cannot change site-wide styles' );

		wp_set_current_user( self::$admin );
		$added = $this->ok(
			'kuduy-elementor-kit-update',
			array(
				'colors' => array(
					array(
						'title' => 'Kuduy brand',
						'color' => '#ff6600',
					),
				),
			)
		);
		$kit   = $this->ok( 'kuduy-elementor-kit' );
		$brand = array_values( array_filter( $kit['colors'], static fn( $c ) => 'Kuduy brand' === $c['title'] ) );
		$this->assertSame( '#FF6600', $brand[0]['color'] ?? null );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $added['_snapshot_id'] ) );
		$after = array_column( $this->ok( 'kuduy-elementor-kit' )['colors'], 'title' );
		$this->assertNotContains( 'Kuduy brand', $after, 'undo removes the added color' );

		$this->fails(
			'kuduy-elementor-kit-update',
			array(
				'colors' => array(
					array(
						'id'    => 'nope123',
						'color' => '#000000',
					),
				),
			),
			'unknown color id'
		);
	}

	public function test_verify_reports_rendered_ids_and_texts(): void {
		[ $page, , $heading ] = $this->page();

		$ok = $this->ok(
			'kuduy-elementor-verify',
			array(
				'id'           => $page,
				'expect_ids'   => array( $heading ),
				'expect_texts' => array( 'Original title', 'Body text' ),
			)
		);
		$this->assertTrue( $ok['rendered'] );
		$this->assertTrue( $ok['ok'], (string) wp_json_encode( $ok ) );
		$this->assertFalse( $ok['live_checked'], 'drafts are checked through rendering only' );

		$missing = $this->ok(
			'kuduy-elementor-verify',
			array(
				'id'           => $page,
				'expect_texts' => array( 'Text that is not there' ),
			)
		);
		$this->assertFalse( $missing['ok'] );
		$this->assertSame( array( 'Text that is not there' ), $missing['missing_texts'] );
	}
}
