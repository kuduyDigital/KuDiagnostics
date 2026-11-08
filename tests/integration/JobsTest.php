<?php
/**
 * Phase 6 background jobs (decision D9, test matrix S9): start validates every item, the batch runs each item through
 * the normal executor as the owner (capability per item, snapshots, audit), progress and per-item errors, cancel,
 * owner-only visibility, a revoked connection stops the job. WP-Cron does not run inside the test, so the scheduled
 * hook is called directly, as WP-Cron would.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Jobs\Jobs;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class JobsTest extends TestCase {

	use CallsTools;

	private static int $admin;
	private static int $author;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		require_once ABSPATH . 'wp-admin/includes/image.php';
		self::$admin  = self::make_user( 'administrator' );
		self::$author = self::make_user( 'author' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	/**
	 * An image attachment owned by a user.
	 *
	 * @param int $owner Owner.
	 */
	private function image( int $owner ): int {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD is not available in this PHP build.' );
		}
		$gd = imagecreatetruecolor( 40, 30 );
		ob_start();
		imagepng( $gd );
		$up = wp_upload_bits( 'kuduy-job-' . wp_generate_password( 6, false ) . '.png', null, (string) ob_get_clean() );
		$id = (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Job image',
				'post_status'    => 'inherit',
				'post_author'    => $owner,
			),
			$up['file']
		);
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $up['file'] ) );
		return $id;
	}

	public function test_bulk_alt_text_job_with_progress_errors_and_undo_ids(): void {
		$own    = array( $this->image( self::$author ), $this->image( self::$author ) );
		$admins = $this->image( self::$admin );
		wp_set_current_user( self::$author );

		$this->fails(
			'kuduy-job-start',
			array(
				'tool'  => 'kuduy-media-update',
				'items' => array( array( 'id' => 'not a number' ) ),
			),
			'every item is validated before anything starts'
		);
		$this->fails(
			'kuduy-job-start',
			array(
				'tool'  => 'kuduy-post-trash',
				'items' => array( array( 'id' => 1 ) ),
			),
			'only listed tools run as jobs'
		);

		$items   = array(
			array(
				'id'  => $own[0],
				'alt' => 'First',
			),
			array(
				'id'  => $admins,
				'alt' => 'Not mine',
			),
			array(
				'id'  => $own[1],
				'alt' => 'Second',
			),
		);
		$started = $this->ok(
			'kuduy-job-start',
			array(
				'tool'  => 'kuduy-media-update',
				'items' => $items,
			)
		);
		$this->assertSame( 'queued', $started['status'] );
		$this->assertSame( 3, $started['total'] );
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			// Rank Math, AIOSEO and WooCommerce bundle Action Scheduler: the job goes there.
			$this->assertTrue( as_has_scheduled_action( Jobs::HOOK, array( $started['id'] ), 'kuduy-mcp' ), 'queued on Action Scheduler' );
		} else {
			$this->assertNotFalse( wp_next_scheduled( Jobs::HOOK, array( $started['id'] ) ), 'scheduled on WP-Cron' );
		}

		wp_set_current_user( 0 );
		Jobs::run( $started['id'] ); // The hook WP-Cron fires.
		wp_set_current_user( self::$author );

		$status = $this->ok( 'kuduy-job-status', array( 'id' => $started['id'] ) );
		$this->assertSame( 'done', $status['status'] );
		$this->assertSame( 3, $status['done'] );
		$this->assertSame( 1, $status['failed'] );
		$this->assertTrue( $status['results'][0]['ok'] );
		$this->assertFalse( $status['results'][1]['ok'], 'the author cannot edit the admin\'s image' );
		$this->assertSame( array( 0, 1, 2 ), array_column( $status['results'], 'index' ), 'each result names its item' );
		$this->assertSame( array( $own[0], $admins, $own[1] ), array_column( $status['results'], 'object_id' ) );
		$this->assertSame( 'First', get_post_meta( $own[0], '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'Second', get_post_meta( $own[1], '_wp_attachment_image_alt', true ) );
		$this->assertFalse( metadata_exists( 'post', $admins, '_wp_attachment_image_alt' ) );

		// Each item keeps its own undo.
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $status['results'][0]['snapshot_id'] ) );
		$this->assertFalse( metadata_exists( 'post', $own[0], '_wp_attachment_image_alt' ) );

		// Other users cannot see the job; site managers can.
		wp_set_current_user( self::make_user( 'editor' ) );
		$this->fails( 'kuduy-job-status', array( 'id' => $started['id'] ), 'not the owner' );
		wp_set_current_user( self::$admin );
		$this->assertSame( 'done', $this->ok( 'kuduy-job-status', array( 'id' => $started['id'] ) )['status'] );
	}

	public function test_cancel_and_revoked_connection_stop_a_job(): void {
		$a = $this->image( self::$admin );
		$b = $this->image( self::$admin );

		$job       = $this->ok(
			'kuduy-job-start',
			array(
				'tool'  => 'kuduy-media-regenerate',
				'items' => array( array( 'id' => $a ), array( 'id' => $b ) ),
			)
		);
		$cancelled = $this->ok( 'kuduy-job-cancel', array( 'id' => $job['id'] ) );
		$this->assertSame( 'cancelled', $cancelled['status'] );
		Jobs::run( $job['id'] ); // The hook WP-Cron fires.
		$this->assertSame( 0, $this->ok( 'kuduy-job-status', array( 'id' => $job['id'] ) )['done'] );

		// A job started over an OAuth connection that no longer exists does not run.
		$orphan = Jobs::create( 'kuduy-media-regenerate', array( array( 'id' => $a ) ), self::$admin, 'kuduy-test-client-gone', array( 'kuduy:read', 'kuduy:write' ) );
		Jobs::run( $orphan ); // The hook WP-Cron fires.
		$row = Jobs::get( $orphan );
		$this->assertSame( 'stopped', $row['status'] );
		$this->assertStringContainsString( 'revoked', $row['error'] );
		$this->assertSame( 0, $row['done'] );

		// A read-only connection cannot run a write tool.
		$readonly = Jobs::create( 'kuduy-media-regenerate', array( array( 'id' => $a ) ), self::$admin, null, array( 'kuduy:read' ) );
		Jobs::run( $readonly ); // The hook WP-Cron fires.
		$this->assertSame( 'stopped', Jobs::get( $readonly )['status'] );
	}
}
