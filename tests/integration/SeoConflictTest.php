<?php
/**
 * Phase 6 SEO resolver (test matrix S1): with two SEO plugins active, writes are refused and nothing changes;
 * with none, the SEO tools are unavailable but status still answers.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Mcp\ToolRegistry;
use Kuduy\Mcp\Modules\Seo\Seo;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class SeoConflictTest extends TestCase {

	use CallsTools;

	private static int $admin;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin = self::make_user( 'administrator' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	public function test_two_seo_plugins_refuse_writes(): void {
		$found = Seo::detected();
		if ( count( $found ) < 2 ) {
			$this->markTestSkipped( 'Needs two active SEO plugins (KUDUY_PLUGINS).' );
		}
		$status = $this->ok( 'kuduy-seo-status' );
		$this->assertTrue( $status['conflict'] );
		$this->assertNull( $status['active'] );
		$this->assertCount( count( $found ), $status['plugins'] );

		$id      = (int) wp_insert_post(
			array(
				'post_title'  => 'Conflict post',
				'post_status' => 'publish',
			)
		);
		$before  = get_post_meta( $id );
		$message = $this->fails(
			'kuduy-seo-update',
			array(
				'post_id' => $id,
				'title'   => 'Should not be saved',
			),
			'two SEO plugins → refused'
		);
		foreach ( $found as $plugin ) {
			$this->assertStringContainsString( $plugin['name'], $message );
		}
		$this->assertStringContainsString( 'Nothing was changed', $message );
		$this->assertSame( $before, get_post_meta( $id ) );
	}

	public function test_without_seo_plugin_only_status_is_listed(): void {
		if ( Seo::detected() ) {
			$this->markTestSkipped( 'An SEO plugin is active in this run.' );
		}
		$status = $this->ok( 'kuduy-seo-status' );
		$this->assertSame( array(), $status['plugins'] );
		$this->assertNull( $status['active'] );
		$registry = ToolRegistry::create();
		$this->assertTrue( $registry->get( 'kuduy-seo-status' )->is_available() );
		$this->assertIsString( $registry->get( 'kuduy-seo-update' )->is_available() );
	}
}
