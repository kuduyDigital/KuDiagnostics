<?php
/**
 * Active Claude connections (one per token family with a live refresh token).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Admin;

use Kuduy\Mcp\OAuth\Tokens;

/**
 * Users see and revoke their own connections; site managers see and revoke all.
 */
final class Connections {

	/**
	 * Connections visible to a user.
	 *
	 * @param \WP_User $user Viewer.
	 * @return list<array<string, mixed>>
	 */
	public static function for_user( \WP_User $user ): array {
		global $wpdb;
		$p   = $wpdb->prefix;
		$now = gmdate( 'Y-m-d H:i:s' );
		// Site managers see everyone's connections (user_id >= 0); others only their own.
		$min = user_can( $user, 'manage_options' ) ? 0 : $user->ID;
		$max = user_can( $user, 'manage_options' ) ? PHP_INT_MAX : $user->ID;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables, fixed names.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.family_id, t.client_id, t.user_id, t.scope, t.expires_at, c.name AS client_name FROM {$p}kuduy_oauth_tokens t LEFT JOIN {$p}kuduy_oauth_clients c ON c.client_id = t.client_id WHERE t.type = 'refresh' AND t.revoked_at IS NULL AND t.expires_at > %s AND t.user_id BETWEEN %d AND %d ORDER BY t.id DESC", $now, $min, $max ), ARRAY_A );
		return array_map(
			static fn( array $row ): array => array(
				'user_id'     => (int) $row['user_id'],
				'client_name' => (string) ( $row['client_name'] ?? wp_parse_url( (string) $row['client_id'], PHP_URL_HOST ) ?? $row['client_id'] ),
			) + $row,
			array_values( (array) $rows )
		);
	}

	/**
	 * Revoke a connection.
	 *
	 * @param string   $family_id Token family.
	 * @param \WP_User $actor     Who asks.
	 * @return bool False when not found or not allowed.
	 */
	public static function revoke( string $family_id, \WP_User $actor ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$owner = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}kuduy_oauth_tokens WHERE family_id = %s LIMIT 1", $family_id ) );
		if ( null === $owner || ( (int) $owner !== $actor->ID && ! user_can( $actor, 'manage_options' ) ) ) {
			return false;
		}
		Tokens::revoke_family( $family_id );
		return true;
	}
}
