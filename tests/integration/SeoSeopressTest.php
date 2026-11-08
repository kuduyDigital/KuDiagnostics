<?php
/**
 * Phase 6 SEO with SEOPress (test matrix S1, S4, S10): round trip through SEOPress's abilities and its
 * target-keywords route, rendered `<head>`, clearing, undo, unsupported fields, permissions.
 * Runs only when SEOPress is the single active SEO plugin (KUDUY_PLUGINS=wp-seopress@…).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Modules\Seo\Seo;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';
require_once __DIR__ . '/RendersHead.php';

final class SeoSeopressTest extends TestCase {

	use CallsTools;
	use RendersHead;

	private static int $admin;
	private static int $author;

	public static function setUpBeforeClass(): void {
		if ( array_keys( Seo::detected() ) !== array( 'seopress' ) ) {
			self::markTestSkipped( 'SEOPress is not the only active SEO plugin in this run (KUDUY_PLUGINS).' );
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
				'post_title'   => 'Kuduy SEOPress post',
				'post_content' => 'Hello',
				'post_status'  => 'publish',
				'post_author'  => $author,
			)
		);
	}

	public function test_round_trip_head_clear_and_undo(): void {
		$status = $this->ok( 'kuduy-seo-status' );
		$this->assertSame( 'seopress', $status['active'] );
		$this->assertNotContains( 'cornerstone', $status['fields'] );

		$id      = self::post( self::$admin );
		$updated = $this->ok(
			'kuduy-seo-update',
			array(
				'post_id'             => $id,
				'title'               => 'Kuduy SEOPress title',
				'description'         => 'Kuduy SEOPress description.',
				'focus_keyword'       => 'kuduy',
				'additional_keywords' => array( 'mcp' ),
				'canonical'           => 'https://example.com/seopress/',
				'robots'              => array(
					'noindex'  => true,
					'nofollow' => true,
				),
				'og'                  => array( 'title' => 'Kuduy SEOPress OG' ),
				'twitter'             => array( 'title' => 'Kuduy SEOPress X' ),
			)
		);
		$this->assertSame( 'Kuduy SEOPress title', get_post_meta( $id, '_seopress_titles_title', true ) );
		$this->assertSame( 'yes', get_post_meta( $id, '_seopress_robots_index', true ) );
		$this->assertSame( 'kuduy,mcp', get_post_meta( $id, '_seopress_analysis_target_kw', true ) );
		$stored = $updated['stored'];
		$this->assertTrue( $stored['robots']['noindex'] );
		$this->assertTrue( $stored['robots']['nofollow'] );
		$this->assertSame( 'https://example.com/seopress/', $stored['canonical'] );
		$this->assertSame( 'Kuduy SEOPress X', $stored['twitter']['title'] );
		$this->assertSame( array( 'mcp' ), $stored['additional_keywords'] );

		$head = $this->head( $id );
		$this->assertStringContainsString( '<title>Kuduy SEOPress title</title>', $head );
		$this->assertSame( 'Kuduy SEOPress description.', self::meta_tag( $head, 'description' ) );
		$this->assertStringContainsString( 'noindex', (string) self::meta_tag( $head, 'robots' ) );
		$this->assertSame( 'Kuduy SEOPress OG', self::meta_tag( $head, 'og:title' ) );

		// kuduy-seo-verify reads the same tags from the live page.
		$verified = $this->verify_live(
			$id,
			$head,
			array(
				'title'       => 'Kuduy SEOPress title',
				'description' => 'Kuduy SEOPress description.',
				'noindex'     => true,
				'og'          => array( 'title' => 'Kuduy SEOPress OG' ),
			)
		);
		$this->assertSame( array(), $verified['mismatches'], (string) wp_json_encode( $verified ) );
		$this->assertTrue( $verified['ok'] );

		$this->ok(
			'kuduy-seo-update',
			array(
				'post_id' => $id,
				'title'   => null,
				'robots'  => array( 'noindex' => null ),
			)
		);
		$this->assertFalse( metadata_exists( 'post', $id, '_seopress_titles_title' ) );
		$this->assertFalse( metadata_exists( 'post', $id, '_seopress_robots_index' ) );
		$this->assertSame( 'yes', get_post_meta( $id, '_seopress_robots_follow', true ) );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $updated['_snapshot_id'] ) );
		foreach ( array( '_seopress_titles_title', '_seopress_titles_desc', '_seopress_robots_index', '_seopress_robots_follow', '_seopress_robots_canonical', '_seopress_social_fb_title' ) as $key ) {
			$this->assertSame( '', (string) get_post_meta( $id, $key, true ), $key . ' should be empty after undo' );
		}
		$this->assertSame( '', (string) get_post_meta( $id, '_seopress_analysis_target_kw', true ) );

		$message = $this->fails(
			'kuduy-seo-update',
			array(
				'post_id'     => $id,
				'cornerstone' => true,
			),
			'SEOPress has no cornerstone flag'
		);
		$this->assertStringContainsString( 'cornerstone', $message );
	}

	public function test_permissions(): void {
		$others = self::post( self::$admin );
		$own    = self::post( self::$author );
		wp_set_current_user( self::$author );
		$this->ok(
			'kuduy-seo-update',
			array(
				'post_id' => $own,
				'title'   => 'Author title',
			)
		);
		$this->assertSame( 'Author title', get_post_meta( $own, '_seopress_titles_title', true ) );
		$this->fails(
			'kuduy-seo-update',
			array(
				'post_id' => $others,
				'title'   => 'Not mine',
			),
			'authors cannot edit SEO of others\' posts'
		);
	}
}
