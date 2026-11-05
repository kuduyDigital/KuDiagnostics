<?php
/**
 * Phase 6 SEO with Rank Math (test matrix S1, S2, S4): status, round trip through Rank Math's own API, rendered
 * `<head>`, clearing, undo, Rank Math's role-manager capabilities and post ownership.
 * Runs only when Rank Math is the single active SEO plugin (KUDUY_PLUGINS=seo-by-rank-math@…).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Modules\Seo\Seo;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';
require_once __DIR__ . '/RendersHead.php';

final class SeoRankMathTest extends TestCase {

	use CallsTools;
	use RendersHead;

	private static int $admin;
	private static int $author;

	public static function setUpBeforeClass(): void {
		if ( array_keys( Seo::detected() ) !== array( 'rank-math' ) ) {
			self::markTestSkipped( 'Rank Math is not the only active SEO plugin in this run (KUDUY_PLUGINS).' );
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
				'post_title'   => 'Kuduy SEO post',
				'post_content' => 'Hello',
				'post_status'  => 'publish',
				'post_author'  => $author,
			)
		);
	}

	public function test_status_names_rank_math_and_its_fields(): void {
		$status = $this->ok( 'kuduy-seo-status' );
		$this->assertSame( 'rank-math', $status['active'] );
		$this->assertFalse( $status['conflict'] );
		$this->assertSame( 'Rank Math SEO', $status['plugins'][0]['name'] );
		$this->assertSame( (string) constant( 'RANK_MATH_VERSION' ), $status['plugins'][0]['version'] );
		$this->assertContains( 'robots.noindex', $status['fields'] );
	}

	public function test_round_trip_head_clear_and_undo(): void {
		$id      = self::post( self::$admin );
		$updated = $this->ok(
			'kuduy-seo-update',
			array(
				'post_id'             => $id,
				'title'               => 'Kuduy custom title',
				'description'         => 'Kuduy custom description.',
				'focus_keyword'       => 'kuduy',
				'additional_keywords' => array( 'mcp', 'seo' ),
				'canonical'           => 'https://example.com/canonical/',
				'cornerstone'         => true,
				'robots'              => array(
					'noindex'  => true,
					'nofollow' => true,
				),
				'og'                  => array( 'title' => 'Kuduy OG title' ),
				'twitter'             => array( 'description' => 'Kuduy X description' ),
			)
		);
		$this->assertSame( 'rank-math', $updated['plugin'] );
		$this->assertIsInt( $updated['_snapshot_id'] );

		// Stored as Rank Math's editor stores it.
		$this->assertSame( 'Kuduy custom title', get_post_meta( $id, 'rank_math_title', true ) );
		$this->assertSame( 'kuduy,mcp,seo', get_post_meta( $id, 'rank_math_focus_keyword', true ) );
		$this->assertEqualsCanonicalizing( array( 'noindex', 'nofollow' ), get_post_meta( $id, 'rank_math_robots', true ) );
		$this->assertSame( 'on', get_post_meta( $id, 'rank_math_pillar_content', true ) );
		$this->assertSame( 'off', get_post_meta( $id, 'rank_math_twitter_use_facebook', true ) );

		// Read back through KUDUY, and through Rank Math's own read ability.
		$got = $this->ok( 'kuduy-seo-get', array( 'post_id' => $id ) );
		$this->assertSame( 'Kuduy custom title', $got['stored']['title'] );
		$this->assertSame( array( 'mcp', 'seo' ), $got['stored']['additional_keywords'] );
		$this->assertTrue( $got['stored']['robots']['noindex'] );
		$this->assertFalse( $got['stored']['robots']['noarchive'] );
		$this->assertSame( 'Kuduy custom title', $got['rendered']['title'] );
		$this->assertSame( 'https://example.com/canonical/', $got['rendered']['canonical'] );
		$this->assertContains( 'noindex', $got['rendered']['robots'] );

		// What a visitor gets in <head>.
		$head = $this->head( $id );
		$this->assertStringContainsString( '<title>Kuduy custom title</title>', $head );
		$this->assertSame( 'Kuduy custom description.', self::meta_tag( $head, 'description' ) );
		$this->assertStringContainsString( 'noindex', (string) self::meta_tag( $head, 'robots' ) );
		$this->assertSame( 'Kuduy OG title', self::meta_tag( $head, 'og:title' ) );
		$this->assertSame( 'Kuduy X description', self::meta_tag( $head, 'twitter:description' ) );

		// null clears; untouched fields stay.
		$this->ok(
			'kuduy-seo-update',
			array(
				'post_id' => $id,
				'title'   => null,
				'robots'  => array( 'noindex' => null ),
			)
		);
		$this->assertFalse( metadata_exists( 'post', $id, 'rank_math_title' ) );
		$this->assertSame( array( 'nofollow' ), array_values( get_post_meta( $id, 'rank_math_robots', true ) ) );
		$this->assertSame( 'Kuduy custom description.', get_post_meta( $id, 'rank_math_description', true ) );

		// Undo of the first update brings back the empty post.
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $updated['_snapshot_id'] ) );
		foreach ( array( 'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword', 'rank_math_robots', 'rank_math_pillar_content' ) as $key ) {
			$this->assertFalse( metadata_exists( 'post', $id, $key ), $key . ' should be gone after undo' );
		}
	}

	public function test_rank_math_capabilities_and_ownership(): void {
		$own    = self::post( self::$author );
		$others = self::post( self::$admin );
		wp_set_current_user( self::$author );

		// Authors have rank_math_onpage_general by default, not advanced.
		$this->ok(
			'kuduy-seo-update',
			array(
				'post_id' => $own,
				'title'   => 'Author title',
			)
		);
		$this->assertSame( 'Author title', get_post_meta( $own, 'rank_math_title', true ) );
		$message = $this->fails(
			'kuduy-seo-update',
			array(
				'post_id' => $own,
				'robots'  => array( 'noindex' => true ),
			),
			'robots need rank_math_onpage_advanced'
		);
		$this->assertStringContainsString( 'rank_math_onpage_advanced', $message );
		$this->assertSame( '', get_post_meta( $own, 'rank_math_robots', true ) );

		$this->fails(
			'kuduy-seo-update',
			array(
				'post_id' => $others,
				'title'   => 'Not mine',
			),
			'authors cannot edit SEO of others\' posts'
		);
		$this->fails( 'kuduy-seo-get', array( 'post_id' => $others ), 'authors cannot read SEO of others\' posts' );
	}

	public function test_invalid_input_is_refused(): void {
		$id = self::post( self::$admin );
		$this->fails(
			'kuduy-seo-update',
			array(
				'post_id'   => $id,
				'canonical' => 'not a url',
			),
			'canonical must be a URL'
		);
		$this->fails( 'kuduy-seo-update', array( 'post_id' => $id ), 'nothing to change' );
		$this->fails(
			'kuduy-seo-update',
			array(
				'post_id'             => $id,
				'additional_keywords' => array( 'extra' ),
			),
			'Rank Math needs a focus keyword before additional ones'
		);
	}
}
