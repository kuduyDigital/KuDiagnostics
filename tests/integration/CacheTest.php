<?php
/**
 * Phase 7 cache plugins (test matrix C1–C3, decision D19 A): status, purges through the plugin's own API, permissions
 * (whole site and URL: manage_options; one post: edit_post), URLs outside the site refused, object cache flush.
 * Plugin checks run when LiteSpeed Cache is active (KUDUY_PLUGINS=litespeed-cache@…); the rest runs everywhere.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class CacheTest extends TestCase {

	use CallsTools;

	private static int $admin;
	private static int $author;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$author = self::make_user( 'author' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	public function test_status_and_object_cache_flush(): void {
		$status = $this->ok( 'kuduy-cache-status' );
		$ids    = array_column( $status['plugins'], 'plugin' );
		$this->assertSame( defined( 'LSCWP_V' ), in_array( 'litespeed', $ids, true ) );
		$this->assertIsBool( $status['object_cache'] );

		wp_cache_set( 'kuduy_probe', 'x', 'kuduy' );
		$flushed = $this->ok(
			'kuduy-cache-purge',
			array(
				'target' => 'all',
				'object' => true,
			)
		);
		$this->assertTrue( $flushed['object_flushed'] );
		$this->assertFalse( wp_cache_get( 'kuduy_probe', 'kuduy' ) );
	}

	public function test_permissions_and_url_check(): void {
		$own = (int) wp_insert_post(
			array(
				'post_title'  => 'Kuduy cache',
				'post_status' => 'publish',
				'post_author' => self::$author,
			)
		);
		wp_set_current_user( self::$author );
		$this->fails( 'kuduy-cache-purge', array( 'target' => 'all' ), 'whole-site purge needs manage_options' );
		$this->fails(
			'kuduy-cache-purge',
			array(
				'target'  => 'post',
				'post_id' => $own,
				'object'  => true,
			),
			'object cache flush needs manage_options'
		);
		if ( defined( 'LSCWP_V' ) ) {
			$this->assertSame(
				array( 'litespeed' ),
				$this->ok(
					'kuduy-cache-purge',
					array(
						'target'  => 'post',
						'post_id' => $own,
					)
				)['purged'],
				'An author may purge their own post.'
			);
		}
		wp_set_current_user( self::$admin );
		$this->fails(
			'kuduy-cache-purge',
			array(
				'target' => 'url',
				'url'    => 'https://elsewhere.example/page',
			),
			'URL outside the site'
		);
		if ( ! defined( 'LSCWP_V' ) ) {
			$this->assertStringContainsString( 'nothing to purge', $this->fails( 'kuduy-cache-purge', array( 'target' => 'all' ), 'no cache plugin' ) );
		}
	}

	public function test_litespeed_purges_through_its_api(): void {
		if ( ! defined( 'LSCWP_V' ) ) {
			$this->markTestSkipped( 'LiteSpeed Cache is not active in this run.' );
		}
		$before = did_action( 'litespeed_purged_all' );
		$out    = $this->ok( 'kuduy-cache-purge', array( 'target' => 'all' ) );
		$this->assertSame( array( 'litespeed' ), $out['purged'] );
		$this->assertGreaterThan( $before, did_action( 'litespeed_purged_all' ), 'LiteSpeed ran its own purge.' );
		$this->assertStringContainsString( 'LiteSpeed', $out['note'] );
		$this->assertSame(
			array( 'litespeed' ),
			$this->ok(
				'kuduy-cache-purge',
				array(
					'target' => 'url',
					'url'    => home_url( '/sample-page/' ),
				)
			)['purged']
		);
	}
}
