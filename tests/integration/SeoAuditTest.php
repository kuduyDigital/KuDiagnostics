<?php
/**
 * Phase 6 kuduy-seo-audit (test matrix S11): posts without their own SEO title, description or keyword, read
 * through the active SEO plugin. Runs with exactly one SEO plugin active.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Modules\Seo\Seo;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class SeoAuditTest extends TestCase {

	use CallsTools;

	private static int $admin;

	public static function setUpBeforeClass(): void {
		if ( count( Seo::detected() ) !== 1 ) {
			self::markTestSkipped( 'Needs exactly one active SEO plugin (KUDUY_PLUGINS).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin = self::make_user( 'administrator' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	public function test_lists_posts_missing_seo_data(): void {
		$type = 'page';
		$done = (int) wp_insert_post(
			array(
				'post_type'   => $type,
				'post_title'  => 'Complete',
				'post_status' => 'publish',
			)
		);
		$bare = (int) wp_insert_post(
			array(
				'post_type'   => $type,
				'post_title'  => 'Bare',
				'post_status' => 'publish',
			)
		);
		$this->ok(
			'kuduy-seo-update',
			array(
				'post_id'       => $done,
				'title'         => 'Complete title',
				'description'   => 'Complete description.',
				'focus_keyword' => 'complete',
			)
		);
		$this->ok(
			'kuduy-seo-update',
			array(
				'post_id' => $bare,
				'title'   => 'Only a title',
			)
		);
		$audit = $this->ok( 'kuduy-seo-audit', array( 'post_type' => $type ) );
		$ids   = array_column( $audit['posts'], 'id' );
		$this->assertContains( $bare, $ids );
		$this->assertNotContains( $done, $ids );
		$row = $audit['posts'][ array_search( $bare, $ids, true ) ];
		$this->assertSame( array( 'description', 'focus_keyword' ), $row['missing'] );

		$only = $this->ok(
			'kuduy-seo-audit',
			array(
				'post_type' => $type,
				'missing'   => array( 'title' ),
			)
		);
		$this->assertNotContains( $bare, array_column( $only['posts'], 'id' ) );
		$this->fails( 'kuduy-seo-audit', array( 'post_type' => 'no_such_type' ), 'unknown post type' );
	}
}
