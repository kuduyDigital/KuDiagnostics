<?php
/**
 * Lifecycle: activation, upgrade without re-activation, uninstall (test matrix F1).
 *
 * Runs inside Playground's WordPress; tests share one database, so setUpBeforeClass() resets KUDUY first.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Lifecycle\Migrations;
use Kuduy\Mcp\Lifecycle\Uninstaller;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

final class LifecycleTest extends TestCase {

	private const PLUGIN = 'kuduy-mcp/kuduy-mcp.php';

	private const TABLES = array(
		'kuduy_oauth_clients',
		'kuduy_oauth_codes',
		'kuduy_oauth_tokens',
		'kuduy_audit_log',
		'kuduy_snapshots',
	);

	/**
	 * Start from a site where KUDUY was never installed, whatever ran before (files run alphabetically).
	 */
	public static function setUpBeforeClass(): void {
		deactivate_plugins( self::PLUGIN, true );
		Uninstaller::run( true );
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- fixed internal table name.
		$ok = false !== $wpdb->query( "SELECT 1 FROM {$wpdb->prefix}{$table} LIMIT 1" ) && '' === $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );
		return $ok;
	}

	public function test_activation_creates_tables_and_stores_db_version(): void {
		foreach ( self::TABLES as $table ) {
			$this->assertFalse( self::table_exists( $table ), "{$table} must not exist before activation" );
		}

		$result = activate_plugin( self::PLUGIN );

		$this->assertNull( $result, is_wp_error( $result ) ? $result->get_error_message() : 'activation failed' );
		foreach ( self::TABLES as $table ) {
			$this->assertTrue( self::table_exists( $table ), "{$table} missing after activation" );
		}
		$this->assertSame( Migrations::DB_VERSION, (int) get_option( Migrations::OPTION ) );
	}

	public function test_old_db_version_is_upgraded_on_boot_without_reactivation(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE {$wpdb->prefix}kuduy_audit_log" );
		update_option( Migrations::OPTION, 0 );

		$this->assertNotFalse( has_action( 'plugins_loaded', array( Plugin::class, 'boot' ) ), 'boot not hooked to plugins_loaded' );
		Plugin::boot();

		$this->assertTrue( self::table_exists( 'kuduy_audit_log' ) );
		$this->assertSame( Migrations::DB_VERSION, (int) get_option( Migrations::OPTION ) );
	}

	public function test_uninstall_keeps_data_by_default(): void {
		update_option( 'kuduy_mcp_settings', array( 'x' => 1 ) );

		Uninstaller::run( false );

		foreach ( self::TABLES as $table ) {
			$this->assertTrue( self::table_exists( $table ), "{$table} dropped without opt-in" );
		}
		$this->assertNotFalse( get_option( 'kuduy_mcp_settings' ) );
	}

	public function test_uninstall_with_opt_in_drops_tables_and_options(): void {
		update_option( 'kuduy_mcp_settings', array( 'x' => 1 ) );
		update_option( 'kuduy_mcp_ui_hidden', true );
		update_option( 'unrelated_option', 'keep' );

		Uninstaller::run( true );

		foreach ( self::TABLES as $table ) {
			$this->assertFalse( self::table_exists( $table ), "{$table} still exists" );
		}
		foreach ( array( Migrations::OPTION, 'kuduy_mcp_settings', 'kuduy_mcp_ui_hidden' ) as $option ) {
			$this->assertFalse( get_option( $option ), "{$option} still set" );
		}
		$this->assertSame( 'keep', get_option( 'unrelated_option' ) );
	}
}
