<?php
/**
 * Phase 7 custom post types and taxonomies (test matrix T1–T3, decision D22 A): define a post type and a taxonomy,
 * use them through the core post tools, remove a type without losing its content, undo each change, refusals.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Modules\Types\ContentTypes;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class ContentTypesTest extends TestCase {

	use CallsTools;

	private static int $admin;
	private static int $editor;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$editor = self::make_user( 'editor' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	public function test_define_use_remove_and_undo(): void {
		$saved = $this->ok(
			'kuduy-content-type-save',
			array(
				'kind'         => 'post_type',
				'key'          => 'kuduy_book',
				'singular'     => 'Book',
				'plural'       => 'Books',
				'has_archive'  => true,
				'supports'     => array( 'title', 'editor', 'thumbnail' ),
				'rewrite_slug' => 'books',
			)
		);
		$this->assertTrue( post_type_exists( 'kuduy_book' ) );
		$this->assertTrue( get_post_type_object( 'kuduy_book' )->show_in_rest );
		$this->assertSame( 'Book', get_post_type_object( 'kuduy_book' )->labels->singular_name );
		$this->assertContains( 'kuduy_book', array_column( $saved['post_types'], 'key' ) );

		$this->ok(
			'kuduy-content-type-save',
			array(
				'kind'        => 'taxonomy',
				'key'         => 'kuduy_genre',
				'singular'    => 'Genre',
				'plural'      => 'Genres',
				'object_type' => array( 'kuduy_book' ),
			)
		);
		$this->assertTrue( is_object_in_taxonomy( 'kuduy_book', 'kuduy_genre' ) );

		$book = $this->ok(
			'kuduy-post-create',
			array(
				'post_type' => 'kuduy_book',
				'title'     => 'Kuduy handbook',
			)
		);
		$id   = (int) $book['id'];
		$this->assertSame( 'kuduy_book', get_post_type( $id ) );

		$removed = $this->ok(
			'kuduy-content-type-remove',
			array(
				'kind' => 'post_type',
				'key'  => 'kuduy_book',
			)
		);
		$this->assertFalse( post_type_exists( 'kuduy_book' ) );
		$this->assertNotNull( get_post( $id ), 'The content stays.' );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $removed['_snapshot_id'] ) );
		$this->assertTrue( post_type_exists( 'kuduy_book' ), 'Undo defines it again.' );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $saved['_snapshot_id'] ) );
		$this->assertArrayNotHasKey( 'kuduy_book', ContentTypes::stored()['post_types'], 'Undoing the first save removes the definition.' );
	}

	public function test_refusals(): void {
		$base = array(
			'kind'     => 'post_type',
			'singular' => 'X',
			'plural'   => 'Xs',
		);
		$this->fails( 'kuduy-content-type-save', $base + array( 'key' => 'post' ), 'core post type' );
		$this->fails( 'kuduy-content-type-save', $base + array( 'key' => 'author' ), 'reserved term' );
		$this->fails( 'kuduy-content-type-save', $base + array( 'key' => 'a_post_type_key_that_is_too_long' ), 'longer than 20 characters' );
		$this->fails(
			'kuduy-content-type-remove',
			array(
				'kind' => 'post_type',
				'key'  => 'page',
			),
			'not defined through KUDUY'
		);
		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-content-type-save', $base + array( 'key' => 'kuduy_editor_type' ), 'editors cannot define types' );
	}
}
