<?php
/**
 * WP-CLI command: wp kuduy ui show|hide|status (Phase 8, D23 A).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Admin;

/**
 * Recovery for hidden mode from the server: whoever can run WP-CLI on the site can show the admin UI again.
 */
final class Cli {

	/**
	 * Register the command when WP-CLI runs.
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::add_command( 'kuduy ui', self::class );
		}
	}

	/**
	 * Show KUDUY MCP's admin page and notices again.
	 *
	 * ## EXAMPLES
	 *
	 *     wp kuduy ui show
	 */
	public function show(): void {
		HiddenMode::show();
		self::say( 'KUDUY MCP admin UI is visible (Tools → KUDUY MCP).' );
	}

	/**
	 * Hide KUDUY MCP's admin page and notices (Claude keeps working).
	 *
	 * ## EXAMPLES
	 *
	 *     wp kuduy ui hide
	 */
	public function hide(): void {
		HiddenMode::hide();
		self::say( HiddenMode::forced_visible() ? 'Hidden mode is set, but KUDUY_MCP_SHOW_UI in wp-config.php keeps the UI visible.' : 'KUDUY MCP admin UI is hidden. Show it again with: wp kuduy ui show' );
	}

	/**
	 * Say whether the admin UI is hidden.
	 *
	 * ## EXAMPLES
	 *
	 *     wp kuduy ui status
	 */
	public function status(): void {
		self::say( HiddenMode::is_hidden() ? 'hidden' : 'visible' . ( HiddenMode::forced_visible() ? ' (KUDUY_MCP_SHOW_UI)' : '' ) );
	}

	/**
	 * Print a success line through WP-CLI.
	 *
	 * @param string $message Message.
	 */
	private static function say( string $message ): void {
		if ( class_exists( 'WP_CLI' ) ) {
			\WP_CLI::success( $message );
		}
	}
}
