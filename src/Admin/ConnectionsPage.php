<?php
/**
 * Tools → KUDUY MCP: how to connect, active connections, recent activity.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Admin;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\OAuth\Urls;

/**
 * Every logged-in user can manage their own connections here.
 */
final class ConnectionsPage {

	public const SLUG = 'kuduy-mcp';

	/**
	 * Add the menu entry (admin_menu).
	 */
	public static function menu(): void {
		if ( HiddenMode::is_hidden() ) {
			return;
		}
		add_management_page( 'KUDUY MCP', 'KUDUY MCP', 'read', self::SLUG, array( self::class, 'render' ) );
	}

	/**
	 * Render the page (and process a revoke).
	 */
	public static function render(): void {
		$user    = wp_get_current_user();
		$is_boss = current_user_can( 'manage_options' );
		$notice  = '';
		if ( isset( $_POST['kuduy_revoke'] ) ) {
			// Dies on a bad nonce.
			check_admin_referer( 'kuduy-mcp-revoke' );
			$notice = Connections::revoke( sanitize_key( wp_unslash( $_POST['kuduy_revoke'] ) ), $user )
				? __( 'Connection revoked. The app must sign in again.', 'kuduy-mcp' )
				: __( 'That connection was not found or is not yours.', 'kuduy-mcp' );
		}

		echo '<div class="wrap"><h1>KUDUY MCP</h1>';
		if ( '' !== $notice ) {
			echo '<div class="notice notice-info"><p>' . esc_html( $notice ) . '</p></div>';
		}
		echo '<h2>' . esc_html__( 'Connect Claude', 'kuduy-mcp' ) . '</h2>'
			. '<p>' . esc_html__( 'In Claude, add a custom connector and paste this URL. You will be asked to log in here and approve access.', 'kuduy-mcp' ) . '</p>'
			. '<p><code>' . esc_html( Urls::resource() ) . '</code></p>';

		echo '<h2>' . esc_html__( 'Active connections', 'kuduy-mcp' ) . '</h2>';
		$connections = Connections::for_user( $user );
		if ( ! $connections ) {
			echo '<p>' . esc_html__( 'No active connections.', 'kuduy-mcp' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'App', 'kuduy-mcp' ) . '</th>'
				. ( $is_boss ? '<th>' . esc_html__( 'User', 'kuduy-mcp' ) . '</th>' : '' )
				. '<th>' . esc_html__( 'Access', 'kuduy-mcp' ) . '</th><th>' . esc_html__( 'Signed in until', 'kuduy-mcp' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $connections as $c ) {
				$owner = get_userdata( $c['user_id'] );
				echo '<tr><td>' . esc_html( $c['client_name'] ) . '</td>'
					. ( $is_boss ? '<td>' . esc_html( $owner ? $owner->user_login : '#' . $c['user_id'] ) . '</td>' : '' )
					. '<td>' . esc_html( (string) $c['scope'] ) . '</td>'
					. '<td>' . esc_html( get_date_from_gmt( (string) $c['expires_at'] ) ) . '</td><td><form method="post">';
				wp_nonce_field( 'kuduy-mcp-revoke' );
				echo '<button class="button" name="kuduy_revoke" value="' . esc_attr( (string) $c['family_id'] ) . '">' . esc_html__( 'Revoke', 'kuduy-mcp' ) . '</button></form></td></tr>';
			}
			echo '</tbody></table>';
		}

		HiddenMode::render_section();

		echo '<h2>' . esc_html__( 'Recent tool calls', 'kuduy-mcp' ) . '</h2>';
		$rows = AuditLog::latest( 20, $is_boss ? null : $user->ID );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No tool calls yet.', 'kuduy-mcp' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time', 'kuduy-mcp' ) . '</th>'
			. ( $is_boss ? '<th>' . esc_html__( 'User', 'kuduy-mcp' ) . '</th>' : '' )
			. '<th>' . esc_html__( 'App', 'kuduy-mcp' ) . '</th><th>' . esc_html__( 'Tool', 'kuduy-mcp' ) . '</th><th>' . esc_html__( 'Result', 'kuduy-mcp' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$who = get_userdata( (int) $row['user_id'] );
			echo '<tr><td>' . esc_html( get_date_from_gmt( (string) $row['ts'] ) ) . '</td>'
				. ( $is_boss ? '<td>' . esc_html( $who ? $who->user_login : '—' ) . '</td>' : '' )
				. '<td>' . esc_html( (string) $row['client_id'] ) . '</td>'
				. '<td><code>' . esc_html( (string) $row['tool'] ) . '</code></td>'
				. '<td>' . esc_html( $row['result'] . ( $row['error_code'] ? ' (' . $row['error_code'] . ')' : '' ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
