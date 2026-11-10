<?php
/**
 * Hidden UI mode (Phase 8, decision D23 A).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Admin;

/**
 * Hidden mode changes presentation only (rule 7 of the brief): it removes KuDiagnostics's Tools page and its admin notices
 * and sends direct visits to the page to the dashboard. Only classes in Admin\ read it; the MCP endpoint, OAuth,
 * tools, jobs, audit log and updater never do. The plugin row stays in Plugins. Recovery needs server or admin
 * access: `wp option update kuduy_mcp_hidden_mode 0`, `wp kuduy ui show`, or
 * `define( 'KUDUY_MCP_SHOW_UI', true );` in wp-config.php (which wins over the option).
 */
final class HiddenMode {

	public const OPTION   = 'kuduy_mcp_hidden_mode';
	public const CONSTANT = 'KUDUY_MCP_SHOW_UI';
	public const ACTION   = 'kuduy_mcp_hide_ui';

	/**
	 * Whether the constant forces the UI on.
	 */
	public static function forced_visible(): bool {
		return defined( self::CONSTANT ) && true === constant( self::CONSTANT );
	}

	/**
	 * Whether KuDiagnostics's admin UI is hidden.
	 */
	public static function is_hidden(): bool {
		return ! self::forced_visible() && '1' === (string) get_option( self::OPTION, '0' );
	}

	/**
	 * Hide the UI.
	 */
	public static function hide(): void {
		update_option( self::OPTION, '1', true );
	}

	/**
	 * Show the UI.
	 */
	public static function show(): void {
		update_option( self::OPTION, '0', true );
	}

	/**
	 * Hook the admin side.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'redirect_hidden_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_hide' ) );
	}

	/**
	 * Where a request for KuDiagnostics's page should go while hidden, or null.
	 *
	 * @param string $page The requested admin page slug.
	 */
	public static function redirect_target( string $page ): ?string {
		return self::is_hidden() && ConnectionsPage::SLUG === $page ? admin_url() : null;
	}

	/**
	 * Send direct visits to the hidden page to the dashboard (admin_init).
	 */
	public static function redirect_hidden_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing on the page slug.
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$target = self::redirect_target( $page );
		if ( null !== $target ) {
			wp_safe_redirect( $target );
			exit;
		}
	}

	/**
	 * The "Hide UI" button's handler (admin-post): site administrators only, nonce checked.
	 */
	public static function handle_hide(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Only site administrators can hide KuDiagnostics.', 'kuduy-mcp' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		self::hide();
		wp_safe_redirect( admin_url() );
		exit;
	}

	/**
	 * The "Hide UI" section of the Tools page (administrators only).
	 */
	public static function render_section(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Hide this screen', 'kuduy-mcp' ) . '</h2>'
			. '<p>' . esc_html__( 'Hides KuDiagnostics\'s page and notices from WP admin for everyone. Claude keeps working exactly as before, and the plugin stays listed under Plugins. To show it again: run "wp kuduy ui show", or "wp option update kuduy_mcp_hidden_mode 0", or add define( \'KUDUY_MCP_SHOW_UI\', true ); to wp-config.php.', 'kuduy-mcp' ) . '</p>';
		if ( self::forced_visible() ) {
			echo '<p><em>' . esc_html__( 'KUDUY_MCP_SHOW_UI is set in wp-config.php, so the page stays visible.', 'kuduy-mcp' ) . '</em></p>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<button class="button">' . esc_html__( 'Hide KuDiagnostics from WP admin', 'kuduy-mcp' ) . '</button></form>';
	}
}
