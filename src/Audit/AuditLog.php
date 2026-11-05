<?php
/**
 * One row per tool call (test matrix F9).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Audit;

use Kuduy\Mcp\OAuth\TokenAuth;

/**
 * Arguments are always redacted before they are stored.
 */
final class AuditLog {

	private const MAX_ARGS_BYTES = 65536;

	/**
	 * Object the running call works on, recorded with the next audit row.
	 *
	 * @var array{0:string, 1:string}|null
	 */
	private static ?array $target = null;

	/**
	 * Name the object the current tool call works on (e.g. 'post', '42'). The next record() stores and clears it.
	 *
	 * @param string $type Object type.
	 * @param string $id   Object id.
	 */
	public static function target( string $type, string $id ): void {
		self::$target = array( mb_substr( $type, 0, 32 ), mb_substr( $id, 0, 64 ) );
	}

	/**
	 * Record a call.
	 *
	 * @param string   $tool        Tool name.
	 * @param mixed    $args        Raw arguments.
	 * @param string   $result      ok | error | denied.
	 * @param ?string  $error_code  Machine-readable reason.
	 * @param int      $duration_ms Duration.
	 * @param int|null $snapshot_id Snapshot taken before the call.
	 */
	public static function record( string $tool, mixed $args, string $result, ?string $error_code, int $duration_ms, ?int $snapshot_id = null ): void {
		global $wpdb;
		$target       = self::$target;
		self::$target = null;
		$json         = (string) wp_json_encode( Redactor::redact( $args ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( strlen( $json ) > self::MAX_ARGS_BYTES ) {
			$json = (string) wp_json_encode( array( '_truncated' => strlen( $json ) . ' bytes' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		$wpdb->insert(
			$wpdb->prefix . 'kuduy_audit_log',
			array(
				'ts'            => gmdate( 'Y-m-d H:i:s' ),
				'user_id'       => get_current_user_id(),
				'client_id'     => mb_substr( self::client_label(), 0, 191 ),
				'tool'          => mb_substr( $tool, 0, 64 ),
				'args_redacted' => $json,
				'result'        => $result,
				'error_code'    => $error_code,
				'duration_ms'   => max( 0, $duration_ms ),
				'snapshot_id'   => $snapshot_id,
				'object_type'   => $target[0] ?? null,
				'object_id'     => $target[1] ?? null,
			)
		);
	}

	/**
	 * Who is calling: the OAuth client id, or how the user signed in.
	 */
	public static function client_label(): string {
		return TokenAuth::client_id() ?? ( did_action( 'application_password_did_authenticate' ) ? 'application-password' : 'wordpress-login' );
	}

	/**
	 * Most recent rows, newest first.
	 *
	 * @param int      $limit   Rows.
	 * @param int|null $user_id Only this user's calls (null = everyone).
	 * @return list<array<string, mixed>>
	 */
	public static function latest( int $limit = 20, ?int $user_id = null ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'kuduy_audit_log';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$rows = null === $user_id
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT %d", $user_id, $limit ), ARRAY_A );
		// phpcs:enable
		return array_values( (array) $rows );
	}
}
