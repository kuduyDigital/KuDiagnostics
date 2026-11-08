<?php
/**
 * Daily retention cleanup.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Lifecycle;

/**
 * Revoked tokens stay until they expire, so refresh-token reuse is still detected.
 */
final class Cleanup {

	public const HOOK = 'kuduy_mcp_cleanup';

	/**
	 * Make sure the daily event exists.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Delete rows past retention.
	 */
	public static function run(): void {
		global $wpdb;
		$p = $wpdb->prefix;
		/**
		 * Filters how many days tool-call audit rows are kept.
		 *
		 * @param int $days Days.
		 */
		$audit_days = max( 1, (int) apply_filters( 'kuduy_mcp_audit_retention_days', 90 ) );
		/**
		 * Filters how many days snapshots are kept.
		 *
		 * @param int $days Days.
		 */
		$snapshot_days = max( 1, (int) apply_filters( 'kuduy_mcp_snapshot_retention_days', 30 ) );
		$ago           = static fn( int $seconds ): string => gmdate( 'Y-m-d H:i:s', time() - $seconds );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables, fixed names.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}kuduy_audit_log WHERE ts < %s", $ago( $audit_days * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}kuduy_snapshots WHERE ts < %s", $ago( $snapshot_days * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}kuduy_jobs WHERE updated_at < %s AND status NOT IN ('queued', 'running')", $ago( $snapshot_days * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}kuduy_oauth_codes WHERE expires_at < %s", $ago( DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}kuduy_oauth_tokens WHERE expires_at < %s", $ago( DAY_IN_SECONDS ) ) );
		// Clients nobody holds tokens or codes for, after 30 days (CIMD ones are simply fetched again).
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$p}kuduy_oauth_clients WHERE created_at < %s
				AND client_id NOT IN ( SELECT client_id FROM {$p}kuduy_oauth_tokens )
				AND client_id NOT IN ( SELECT client_id FROM {$p}kuduy_oauth_codes )",
				$ago( 30 * DAY_IN_SECONDS )
			)
		);
		// phpcs:enable
	}
}
