<?php
/**
 * Phase 6 SEO with Yoast SEO (test matrix S1, S3, S4): round trip through WPSEO_Meta and the Surfaces API, rendered
 * `<head>`, clearing, undo, Yoast's advanced-metadata capability and post ownership.
 * Runs only when Yoast is the single active SEO plugin (KUDUY_PLUGINS=wordpress-seo@…).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Modules\Seo\Seo;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';
require_once __DIR__ . '/RendersHead.php';

final class SeoYoastTest extends TestCase {

	use CallsTools;
	use RendersHead;

	private static int $admin;
	private static int $author;

	public static function setUpBeforeClass(): void {
		if ( array_keys( Seo::detected() ) !== array( 'yoast' ) ) {
			self::markTestSkipped( 'Yoast SEO is not the only active SEO plugin in this run (KUDUY_PLUGINS).' );
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
				'post_title'   => 'Kuduy Yoast post',
				'post_content' => 'Hello',
				'post_status'  => 'publish',
				'post_author'  => $author,
			)
		);
	}

	public function test_status_names_yoast_and_its_fields(): void {
		$status = $this->ok( 'kuduy-seo-status' );
		$this->assertSame( 'yoast', $status['active'] );
		$this->assertSame( 'Yoast SEO', $status['plugins'][0]['name'] );
		$this->assertContains( 'og.title', $status['fields'], 'Open Graph is on by default' );
		$this->assertNotContains( 'additional_keywords', $status['fields'], 'related keyphrases are Yoast Premium' );
	}

	public function test_round_trip_head_clear_and_undo(): void {
		$id      = self::post( self::$admin );
		$updated = $this->ok(
			'kuduy-seo-update',
			array(
				'post_id'       => $id,
				'title'         => 'Kuduy Yoast title',
				'description'   => 'Kuduy Yoast description.',
				'focus_keyword' => 'kuduy',
				'canonical'     => 'https://example.com/yoast-canonical/',
				'cornerstone'   => true,
				'robots'        => array(
					'noindex'   => true,
					'noarchive' => true,
				),
				'og'            => array( 'title' => 'Kuduy Yoast OG' ),
				'twitter'       => array( 'description' => 'Kuduy Yoast X' ),
			)
		);
		$this->assertSame( 'yoast', $updated['plugin'] );

		// Stored as Yoast's metabox stores it.
		$this->assertSame( 'Kuduy Yoast title', get_post_meta( $id, '_yoast_wpseo_title', true ) );
		$this->assertSame( 'kuduy', get_post_meta( $id, '_yoast_wpseo_focuskw', true ) );
		$this->assertSame( '1', get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true ) );
		$this->assertSame( 'noarchive', get_post_meta( $id, '_yoast_wpseo_meta-robots-adv', true ) );
		$this->assertSame( '1', get_post_meta( $id, '_yoast_wpseo_is_cornerstone', true ) );

		// Read back, stored and as Yoast renders it (indexable rebuilt right away).
		$this->assertSame( 'Kuduy Yoast title', $updated['stored']['title'] );
		$this->assertTrue( $updated['stored']['robots']['noindex'] );
		$this->assertTrue( $updated['stored']['robots']['noarchive'] );
		$this->assertFalse( $updated['stored']['robots']['nofollow'] );
		$this->assertSame( 'Kuduy Yoast title', $updated['rendered']['title'] );
		$this->assertSame( 'Kuduy Yoast description.', $updated['rendered']['description'] );
		$this->assertContains( 'noindex', $updated['rendered']['robots'] );
		$this->assertSame( 'Kuduy Yoast OG', $updated['rendered']['og']['title'] );

		// What a visitor gets in <head>.
		$head = $this->head( $id );
		$this->assertStringContainsString( '<title>Kuduy Yoast title</title>', $head );
		$this->assertSame( 'Kuduy Yoast description.', self::meta_tag( $head, 'description' ) );
		$this->assertStringContainsString( 'noindex', (string) self::meta_tag( $head, 'robots' ) );
		$this->assertSame( 'Kuduy Yoast OG', self::meta_tag( $head, 'og:title' ) );
		$this->assertSame( 'Kuduy Yoast X', self::meta_tag( $head, 'twitter:description' ) );

		// kuduy-seo-verify reads the same tags from the live page.
		$verified = $this->verify_live(
			$id,
			$head,
			array(
				'title'       => 'Kuduy Yoast title',
				'description' => 'Kuduy Yoast description.',
				'noindex'     => true,
				'og'          => array( 'title' => 'Kuduy Yoast OG' ),
			)
		);
		$this->assertSame( array(), $verified['mismatches'], (string) wp_json_encode( $verified ) );
		$this->assertTrue( $verified['ok'] );

		// null clears; noindex false forces index; untouched fields stay.
		$this->ok(
			'kuduy-seo-update',
			array(
				'post_id' => $id,
				'title'   => null,
				'robots'  => array(
					'noindex'   => false,
					'noarchive' => false,
				),
			)
		);
		$this->assertFalse( metadata_exists( 'post', $id, '_yoast_wpseo_title' ) );
		$this->assertSame( '2', get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true ) );
		$this->assertFalse( metadata_exists( 'post', $id, '_yoast_wpseo_meta-robots-adv' ) );
		$this->assertSame( 'Kuduy Yoast description.', get_post_meta( $id, '_yoast_wpseo_metadesc', true ) );

		// Undo of the first update brings back the empty post.
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $updated['_snapshot_id'] ) );
		foreach ( array( 'title', 'metadesc', 'focuskw', 'canonical', 'meta-robots-noindex', 'is_cornerstone', 'opengraph-title' ) as $key ) {
			$this->assertFalse( metadata_exists( 'post', $id, '_yoast_wpseo_' . $key ), $key . ' should be gone after undo' );
		}
	}

	public function test_yoast_capabilities_and_ownership(): void {
		$own    = self::post( self::$author );
		$others = self::post( self::$admin );
		wp_set_current_user( self::$author );

		$this->ok(
			'kuduy-seo-update',
			array(
				'post_id'       => $own,
				'title'         => 'Author title',
				'focus_keyword' => 'author',
			)
		);
		$this->assertSame( 'Author title', get_post_meta( $own, '_yoast_wpseo_title', true ) );
		$message = $this->fails(
			'kuduy-seo-update',
			array(
				'post_id'   => $own,
				'canonical' => 'https://example.com/x/',
			),
			'canonical is advanced metadata'
		);
		$this->assertStringContainsString( 'wpseo_edit_advanced_metadata', $message );
		$this->assertFalse( metadata_exists( 'post', $own, '_yoast_wpseo_canonical' ) );

		$this->fails(
			'kuduy-seo-update',
			array(
				'post_id' => $others,
				'title'   => 'Not mine',
			),
			'authors cannot edit SEO of others\' posts'
		);
		$this->fails(
			'kuduy-seo-update',
			array(
				'post_id'             => $own,
				'additional_keywords' => array( 'extra' ),
			),
			'Yoast free cannot store related keyphrases'
		);
	}
}
