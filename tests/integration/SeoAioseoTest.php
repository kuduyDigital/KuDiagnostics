<?php
/**
 * Phase 6 SEO with All in One SEO (test matrix S1, S4, S10): round trip through AIOSEO's own abilities (its own
 * table), rendered `<head>`, clearing, undo, permissions.
 * Runs only when AIOSEO is the single active SEO plugin (KUDUY_PLUGINS=all-in-one-seo-pack@…).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Modules\Seo\Seo;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';
require_once __DIR__ . '/RendersHead.php';

final class SeoAioseoTest extends TestCase {

	use CallsTools;
	use RendersHead;

	private static int $admin;
	private static int $author;

	public static function setUpBeforeClass(): void {
		if ( array_keys( Seo::detected() ) !== array( 'aioseo' ) ) {
			self::markTestSkipped( 'AIOSEO is not the only active SEO plugin in this run (KUDUY_PLUGINS).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$author = self::make_user( 'author' );
		self::remember_hooks();
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	/**
	 * A published post.
	 *
	 * @param int $author Author.
	 */
	private static function post( int $author ): int {
		return (int) wp_insert_post(
			array(
				'post_title'   => 'Kuduy AIOSEO post',
				'post_content' => 'Hello',
				'post_status'  => 'publish',
				'post_author'  => $author,
			)
		);
	}

	public function test_round_trip_head_clear_and_undo(): void {
		$status = $this->ok( 'kuduy-seo-status' );
		$this->assertSame( 'aioseo', $status['active'] );

		$id      = self::post( self::$admin );
		$updated = $this->ok(
			'kuduy-seo-update',
			array(
				'post_id'             => $id,
				'title'               => 'Kuduy AIOSEO title',
				'description'         => 'Kuduy AIOSEO description.',
				'focus_keyword'       => 'kuduy',
				'additional_keywords' => array( 'mcp' ),
				'canonical'           => 'https://example.com/aioseo/',
				'cornerstone'         => true,
				'robots'              => array(
					'noindex'  => true,
					'nofollow' => true,
				),
				'og'                  => array( 'title' => 'Kuduy AIOSEO OG' ),
			)
		);
		$this->assertSame( 'aioseo', $updated['plugin'] );
		$stored = $updated['stored'];
		$this->assertSame( 'Kuduy AIOSEO title', $stored['title'] );
		$this->assertSame( 'kuduy', $stored['focus_keyword'] );
		$this->assertSame( array( 'mcp' ), $stored['additional_keywords'] );
		$this->assertSame( 'https://example.com/aioseo/', $stored['canonical'] );
		$this->assertTrue( $stored['cornerstone'] );
		$this->assertTrue( $stored['robots']['noindex'] );
		$this->assertTrue( $stored['robots']['nofollow'] );
		$this->assertFalse( $stored['robots']['noarchive'] );
		$this->assertSame( 'Kuduy AIOSEO OG', $stored['og']['title'] );
		$this->assertSame( 'Kuduy AIOSEO title', $updated['rendered']['title'] );

		// Saved in AIOSEO's own table, not only in post meta.
		$row = \AIOSEO\Plugin\Common\Models\Post::getPost( $id );
		$this->assertSame( 'Kuduy AIOSEO title', $row->title );

		$head = $this->head( $id );
		$this->assertStringContainsString( '<title>Kuduy AIOSEO title</title>', $head );
		$this->assertSame( 'Kuduy AIOSEO description.', self::meta_tag( $head, 'description' ) );
		$this->assertStringContainsString( 'noindex', (string) self::meta_tag( $head, 'robots' ) );
		$this->assertSame( 'Kuduy AIOSEO OG', self::meta_tag( $head, 'og:title' ) );

		// kuduy-seo-verify reads the same tags from the live page.
		$verified = $this->verify_live(
			$id,
			$head,
			array(
				'title'       => 'Kuduy AIOSEO title',
				'description' => 'Kuduy AIOSEO description.',
				'noindex'     => true,
				'og'          => array( 'title' => 'Kuduy AIOSEO OG' ),
			)
		);
		$this->assertSame( array(), $verified['mismatches'], (string) wp_json_encode( $verified ) );
		$this->assertTrue( $verified['ok'] );

		// noindex false = index (explicit), null = default; null clears a text.
		$this->ok(
			'kuduy-seo-update',
			array(
				'post_id' => $id,
				'title'   => null,
				'robots'  => array(
					'noindex'  => false,
					'nofollow' => false,
				),
			)
		);
		$got = $this->ok( 'kuduy-seo-get', array( 'post_id' => $id ) )['stored'];
		$this->assertNull( $got['title'] );
		$this->assertFalse( $got['robots']['noindex'] );
		$this->assertSame( 'Kuduy AIOSEO description.', $got['description'] );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $updated['_snapshot_id'] ) );
		$back = $this->ok( 'kuduy-seo-get', array( 'post_id' => $id ) )['stored'];
		$this->assertNull( $back['title'] );
		$this->assertNull( $back['description'] );
		$this->assertNull( $back['focus_keyword'] );
		$this->assertNull( $back['robots']['noindex'], 'post type default again' );
		$this->assertFalse( $back['cornerstone'] );
	}

	public function test_permissions(): void {
		$others = self::post( self::$admin );
		wp_set_current_user( self::$author );
		$this->fails(
			'kuduy-seo-update',
			array(
				'post_id' => $others,
				'title'   => 'Not mine',
			),
			'authors cannot edit SEO of others\' posts'
		);
		$own    = self::post( self::$author );
		$result = $this->call(
			'kuduy-seo-update',
			array(
				'post_id' => $own,
				'title'   => 'Author title',
			)
		);
		if ( aioseo()->access->hasAccess( 'aioseo_page_general_settings' ) ) {
			$this->assertFalse( $result['isError'], (string) wp_json_encode( $result ) );
		} else {
			$this->assertTrue( $result['isError'] );
			$this->assertStringContainsString( 'aioseo_page_general_settings', (string) $result['content'][0]['text'] );
		}
	}
}
