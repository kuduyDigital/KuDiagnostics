<?php
/**
 * Creates and upgrades the plugin's tables (architecture §5).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Lifecycle;

/**
 * Versioned schema; bump DB_VERSION and edit schema() to migrate.
 */
final class Migrations {

	public const DB_VERSION = 2;

	public const OPTION = 'kuduy_mcp_db_version';

	/**
	 * Table names without the WordPress prefix.
	 *
	 * @var list<string>
	 */
	public const TABLES = array(
		'kuduy_oauth_clients',
		'kuduy_oauth_codes',
		'kuduy_oauth_tokens',
		'kuduy_audit_log',
		'kuduy_snapshots',
		'kuduy_jobs',
	);

	/**
	 * Runs migrations when the stored schema version is older than the code's.
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::OPTION, 0 ) < self::DB_VERSION ) {
			self::run();
		}
	}

	/**
	 * Idempotent: dbDelta creates missing tables and columns, never drops.
	 */
	public static function run(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::schema( $wpdb->prefix, $wpdb->get_charset_collate() ) );
		update_option( self::OPTION, self::DB_VERSION, false );
	}

	/**
	 * CREATE TABLE statements in dbDelta format (two spaces after PRIMARY KEY, one column per line).
	 *
	 * @param string $p       Table prefix.
	 * @param string $collate Charset/collation clause.
	 * @return list<string>
	 */
	public static function schema( string $p, string $collate ): array {
		return array(
			"CREATE TABLE {$p}kuduy_oauth_clients (
client_id varchar(191) NOT NULL,
type varchar(10) NOT NULL,
name varchar(200) NOT NULL DEFAULT '',
redirect_uris longtext NOT NULL,
metadata_url varchar(2048) NULL,
metadata_cache longtext NULL,
created_at datetime NOT NULL,
last_used_at datetime NULL,
PRIMARY KEY  (client_id)
) {$collate};",
			"CREATE TABLE {$p}kuduy_oauth_codes (
code_hash char(64) NOT NULL,
client_id varchar(191) NOT NULL,
user_id bigint(20) unsigned NOT NULL,
redirect_uri varchar(2048) NOT NULL,
scope varchar(255) NOT NULL,
resource varchar(2048) NOT NULL,
challenge varchar(128) NOT NULL,
expires_at datetime NOT NULL,
used tinyint(1) NOT NULL DEFAULT 0,
PRIMARY KEY  (code_hash),
KEY expires_at (expires_at)
) {$collate};",
			"CREATE TABLE {$p}kuduy_oauth_tokens (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
token_hash char(64) NOT NULL,
type varchar(10) NOT NULL,
family_id char(32) NOT NULL,
client_id varchar(191) NOT NULL,
user_id bigint(20) unsigned NOT NULL,
scope varchar(255) NOT NULL,
resource varchar(2048) NOT NULL,
expires_at datetime NOT NULL,
revoked_at datetime NULL,
replaced_by bigint(20) unsigned NULL,
PRIMARY KEY  (id),
UNIQUE KEY token_hash (token_hash),
KEY family_id (family_id),
KEY user_client (user_id,client_id)
) {$collate};",
			"CREATE TABLE {$p}kuduy_audit_log (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
ts datetime NOT NULL,
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
client_id varchar(191) NOT NULL DEFAULT '',
tool varchar(64) NOT NULL,
args_redacted longtext NULL,
result varchar(10) NOT NULL,
error_code varchar(64) NULL,
duration_ms int(10) unsigned NOT NULL DEFAULT 0,
object_type varchar(32) NULL,
object_id varchar(64) NULL,
snapshot_id bigint(20) unsigned NULL,
PRIMARY KEY  (id),
KEY ts (ts),
KEY tool_ts (tool,ts),
KEY user_ts (user_id,ts)
) {$collate};",
			"CREATE TABLE {$p}kuduy_snapshots (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
ts datetime NOT NULL,
user_id bigint(20) unsigned NOT NULL,
tool varchar(64) NOT NULL,
object_type varchar(32) NOT NULL,
object_id varchar(64) NOT NULL,
payload longtext NOT NULL,
restored_at datetime NULL,
PRIMARY KEY  (id),
KEY object (object_type,object_id)
) {$collate};",
			"CREATE TABLE {$p}kuduy_jobs (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
user_id bigint(20) unsigned NOT NULL,
client_id varchar(191) NOT NULL DEFAULT '',
scopes varchar(255) NOT NULL DEFAULT '',
tool varchar(64) NOT NULL,
status varchar(16) NOT NULL,
total int(10) unsigned NOT NULL DEFAULT 0,
done int(10) unsigned NOT NULL DEFAULT 0,
failed int(10) unsigned NOT NULL DEFAULT 0,
error varchar(255) NOT NULL DEFAULT '',
items longtext NOT NULL,
results longtext NOT NULL,
PRIMARY KEY  (id),
KEY user_status (user_id,status),
KEY updated_at (updated_at)
) {$collate};",
		);
	}
}
