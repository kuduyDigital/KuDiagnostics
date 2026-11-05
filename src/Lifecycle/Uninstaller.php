<?php
/**
 * Removes plugin data on uninstall, only when the site owner opted in.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Lifecycle;

/**
 * Drops plugin tables and options.
 */
final class Uninstaller {

	public const OPTIONS = array( Migrations::OPTION, 'kuduy_mcp_settings', 'kuduy_mcp_ui_hidden' );

	/**
	 * Run the uninstall.
	 *
	 * @param bool $delete_data True only when KUDUY_MCP_DELETE_DATA_ON_UNINSTALL is set.
	 */
	public static function run( bool $delete_data ): void {
		wp_clear_scheduled_hook( Cleanup::HOOK );
		if ( ! $delete_data ) {
			return;
		}
		global $wpdb;
		foreach ( Migrations::TABLES as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- fixed internal table names.
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
		}
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
	}
}
