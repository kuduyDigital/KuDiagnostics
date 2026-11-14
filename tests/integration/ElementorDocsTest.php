<?php
/**
 * Upgrade plan phase F on real Elementor (test matrix EL12–EL14): duplicate a whole document, document settings
 * read / patch / undo, dynamic tags list / set / clear / undo.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class ElementorDocsTest extends TestCase {

	use CallsTools;

	private static int $admin;

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
		self::$author = self::make_user( 'author' );

		require_once __DIR__ . '/ElementorTestTag.php';
		$manager = \Elementor\Plugin::$instance->dynamic_tags;
		$manager->get_tags();
		$manager->register( new ElementorTestTag() );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	/**
	 * A fresh page: container > heading + image.
	 *
	 * @return array{0:int, 1:string, 2:string} Page id, heading id, image id.
	 */
	private function page(): array {
		$created = $this->ok(
			'kuduy-elementor-create',
			array(
				'title'    => 'Kuduy docs page',
				'template' => 'elementor_canvas',
				'elements' => array(
					array(
						'elType'   => 'container',
						'elements' => array(
							array(
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => array( 'title' => 'Static title' ),
							),
							array(
								'elType'     => 'widget',
								'widgetType' => 'image',
							),
						),
					),
				),
			)
		);
		$tree    = $this->ok( 'kuduy-elementor-get', array( 'id' => $created['id'] ) )['elements'];
		return array( $created['id'], (string) $tree[0]['elements'][0]['id'], (string) $tree[0]['elements'][1]['id'] );
	}

	/**
	 * Stored settings of one element.
	 *
	 * @param int    $page    Page id.
	 * @param string $element Element id.
	 * @return array<string, mixed>
	 */
	private function element( int $page, string $element ): array {
		return (array) $this->ok(
			'kuduy-elementor-get',
			array(
				'id'         => $page,
				'element_id' => $element,
			)
		)['elements'][0]['settings'];
	}

	public function test_el12_duplicate_copies_elements_settings_and_template_into_a_draft(): void {
		[ $page ] = $this->page();
		wp_update_post(
			array(
				'ID'          => $page,
				'post_status' => 'publish',
			)
		);
		$this->ok(
			'kuduy-elementor-document-settings-update',
			array(
				'id'       => $page,
				'settings' => array( 'hide_title' => 'yes' ),
			)
		);

		$copy = $this->ok( 'kuduy-elementor-duplicate', array( 'id' => $page ) );
		$this->assertNotSame( $page, $copy['id'] );
		$this->assertSame( $page, $copy['source_id'] );
		$this->assertSame( 3, $copy['element_count'] );
		$this->assertSame( 'draft', get_post_status( $copy['id'] ), 'A copy is never published.' );
		$this->assertSame( 'Kuduy docs page (copy)', get_the_title( $copy['id'] ) );
		$this->assertSame( self::$admin, (int) get_post_field( 'post_author', $copy['id'] ) );
		$this->assertSame( 'elementor_canvas', get_post_meta( $copy['id'], '_wp_page_template', true ) );
		$this->assertSame( 'builder', get_post_meta( $copy['id'], '_elementor_edit_mode', true ) );

		$original = $this->ok( 'kuduy-elementor-get', array( 'id' => $page ) );
		$copied   = $this->ok( 'kuduy-elementor-get', array( 'id' => $copy['id'] ) );
		$this->assertSame( $original['elements'], $copied['elements'] );
		$this->assertSame( 'yes', ( (array) $copied['page_settings'] )['hide_title'] ?? null );

		$named = $this->ok(
			'kuduy-elementor-duplicate',
			array(
				'id'    => $page,
				'title' => 'Named copy',
			)
		);
		$this->assertSame( 'Named copy', get_the_title( $named['id'] ) );

		$plain = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Not Elementor',
				'post_status' => 'draft',
			)
		);
		$this->fails( 'kuduy-elementor-duplicate', array( 'id' => $plain ), 'not built with Elementor' );

		wp_set_current_user( self::$author );
		$this->fails( 'kuduy-elementor-duplicate', array( 'id' => $page ), 'an author may not edit someone else\'s page' );
	}

	public function test_el13_document_settings_read_patch_and_undo(): void {
		[ $page ] = $this->page();

		$read  = $this->ok( 'kuduy-elementor-document-settings', array( 'id' => $page ) );
		$names = array_column( $read['controls'], 'name' );
		$this->assertContains( 'hide_title', $names );
		$this->assertNotContains( 'post_title', $names, 'Post fields are not page settings.' );
		$this->assertNotContains( 'post_status', $names );
		$this->assertSame( array(), (array) $read['settings'] );

		$first = $this->ok(
			'kuduy-elementor-document-settings-update',
			array(
				'id'       => $page,
				'settings' => array( 'hide_title' => 'yes' ),
			)
		);
		$this->assertSame( array( 'set hide_title' ), $first['changed'] );
		$this->assertSame( 3, $first['element_count'], 'Elements are untouched.' );
		$second = $this->ok(
			'kuduy-elementor-document-settings-update',
			array(
				'id'       => $page,
				'settings' => array(
					'margin' => array(
						'unit'     => 'px',
						'top'      => '10',
						'right'    => '0',
						'bottom'   => '0',
						'left'     => '0',
						'isLinked' => false,
					),
				),
			)
		);
		$stored = (array) $this->ok( 'kuduy-elementor-document-settings', array( 'id' => $page ) )['settings'];
		$this->assertSame( 'yes', $stored['hide_title'], 'A patch keeps the settings it does not name.' );
		$this->assertSame( '10', $stored['margin']['top'] );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $second['_snapshot_id'] ) );
		$stored = (array) $this->ok( 'kuduy-elementor-document-settings', array( 'id' => $page ) )['settings'];
		$this->assertSame( 'yes', $stored['hide_title'] );
		$this->assertArrayNotHasKey( 'margin', $stored );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $first['_snapshot_id'] ) );
		$this->assertSame( array(), (array) $this->ok( 'kuduy-elementor-document-settings', array( 'id' => $page ) )['settings'], 'Undo back to no stored settings.' );

		$this->ok(
			'kuduy-elementor-document-settings-update',
			array(
				'id'       => $page,
				'settings' => array( 'hide_title' => 'yes' ),
			)
		);
		$this->ok(
			'kuduy-elementor-document-settings-update',
			array(
				'id'    => $page,
				'unset' => array( 'hide_title' ),
			)
		);
		$this->assertSame( array(), (array) $this->ok( 'kuduy-elementor-document-settings', array( 'id' => $page ) )['settings'] );

		$title = get_the_title( $page );
		$this->fails(
			'kuduy-elementor-document-settings-update',
			array(
				'id'       => $page,
				'settings' => array( 'post_status' => 'publish' ),
			),
			'status is a post field'
		);
		$this->fails(
			'kuduy-elementor-document-settings-update',
			array(
				'id'       => $page,
				'settings' => array( 'post_title' => 'Changed' ),
			),
			'title is a post field'
		);
		$this->fails(
			'kuduy-elementor-document-settings-update',
			array(
				'id'       => $page,
				'settings' => array( 'no_such_setting' => 1 ),
			),
			'unknown setting'
		);
		$this->fails( 'kuduy-elementor-document-settings-update', array( 'id' => $page ), 'nothing to change' );
		$this->assertSame( 'draft', get_post_status( $page ) );
		$this->assertSame( $title, get_the_title( $page ) );

		$kit = (int) \Elementor\Plugin::$instance->kits_manager->get_active_id();
		$this->fails(
			'kuduy-elementor-document-settings-update',
			array(
				'id'       => $kit,
				'settings' => array( 'hide_title' => 'yes' ),
			),
			'the Kit has its own tool'
		);
	}

	public function test_el14_dynamic_tags_list_set_render_clear_and_undo(): void {
		[ $page, $heading, $image ] = $this->page();

		$tags = array_column( $this->ok( 'kuduy-elementor-dynamic-tags' )['tags'], null, 'name' );
		$this->assertArrayHasKey( 'kuduy-test-tag', $tags );
		$this->assertSame( array( 'text' ), $tags['kuduy-test-tag']['categories'] );
		$this->assertContains( 'suffix', array_column( $tags['kuduy-test-tag']['settings'], 'name' ) );
		$this->assertSame( array( 'kuduy-test-tag' ), array_column( $this->ok( 'kuduy-elementor-dynamic-tags', array( 'search' => 'kuduy test' ) )['tags'], 'name' ) );

		$schema = array_column( $this->ok( 'kuduy-elementor-widget-schema', array( 'type' => 'heading' ) )['controls'], null, 'name' );
		$this->assertTrue( $schema['title']['dynamic'] ?? false, 'The schema marks settings that accept dynamic tags.' );
		$this->assertArrayNotHasKey( 'dynamic', $schema['header_size'] );

		$args = array(
			'id'         => $page,
			'element_id' => $heading,
			'control'    => 'title',
		);
		$set  = $this->ok(
			'kuduy-elementor-dynamic-set',
			$args + array(
				'tag'      => 'kuduy-test-tag',
				'settings' => array( 'suffix' => 'ONE' ),
			)
		);
		$this->assertSame( 3, $set['element_count'] );
		$stored = $this->element( $page, $heading );
		$this->assertSame( 'Static title', $stored['title'], 'The static value stays underneath.' );
		$this->assertStringContainsString( 'name="kuduy-test-tag"', $stored['__dynamic__']['title'] );

		$usage = $this->ok( 'kuduy-elementor-dynamic-tags', array( 'id' => $page ) )['usage'];
		$this->assertCount( 1, $usage );
		$this->assertSame( $heading, $usage[0]['element_id'] );
		$this->assertSame( 'title', $usage[0]['control'] );
		$this->assertSame( 'kuduy-test-tag', $usage[0]['tag'] );
		$this->assertSame( 'ONE', ( (array) $usage[0]['settings'] )['suffix'] );
		$this->assertTrue( $usage[0]['registered'] );

		$shown = $this->ok(
			'kuduy-elementor-verify',
			array(
				'id'           => $page,
				'expect_texts' => array( 'KUDUY-DYNAMIC-ONE', 'Static title' ),
			)
		);
		$this->assertSame( array( 'Static title' ), $shown['missing_texts'], 'Elementor renders the tag instead of the static value.' );

		$this->fails( 'kuduy-elementor-dynamic-set', array( 'control' => 'header_size' ) + $args + array( 'tag' => 'kuduy-test-tag' ), 'header_size takes no dynamic tag' );
		$this->fails( 'kuduy-elementor-dynamic-set', $args + array( 'tag' => 'no-such-tag' ), 'unknown tag' );
		$this->fails(
			'kuduy-elementor-dynamic-set',
			$args + array(
				'tag'      => 'kuduy-test-tag',
				'settings' => array( 'nope' => 1 ),
			),
			'unknown tag setting'
		);
		$this->fails(
			'kuduy-elementor-dynamic-set',
			array(
				'id'         => $page,
				'element_id' => $image,
				'control'    => 'image',
				'tag'        => 'kuduy-test-tag',
			),
			'a text tag does not fit an image setting'
		);
		$this->fails( 'kuduy-elementor-dynamic-set', array( 'element_id' => 'nope123' ) + $args + array( 'tag' => 'kuduy-test-tag' ), 'unknown element' );
		$this->assertCount( 1, $this->ok( 'kuduy-elementor-dynamic-tags', array( 'id' => $page ) )['usage'], 'Refused calls changed nothing.' );

		$clear = $this->ok( 'kuduy-elementor-dynamic-set', $args + array( 'clear' => true ) );
		$this->assertArrayNotHasKey( '__dynamic__', $this->element( $page, $heading ) );
		$shown = $this->ok(
			'kuduy-elementor-verify',
			array(
				'id'           => $page,
				'expect_texts' => array( 'Static title' ),
			)
		);
		$this->assertSame( array(), $shown['missing_texts'], 'The static value shows again.' );
		$this->fails( 'kuduy-elementor-dynamic-set', $args + array( 'clear' => true ), 'nothing to clear' );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $clear['_snapshot_id'] ) );
		$this->assertStringContainsString( 'name="kuduy-test-tag"', $this->element( $page, $heading )['__dynamic__']['title'], 'Undo of clear puts the tag back.' );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $set['_snapshot_id'] ) );
		$this->assertArrayNotHasKey( '__dynamic__', $this->element( $page, $heading ), 'Undo of set removes the tag.' );

		wp_set_current_user( self::$author );
		$this->fails( 'kuduy-elementor-dynamic-set', $args + array( 'tag' => 'kuduy-test-tag' ), 'an author may not edit someone else\'s page' );
		$this->fails( 'kuduy-elementor-document-settings', array( 'id' => $page ), 'an author may not read someone else\'s page settings' );
	}
}
