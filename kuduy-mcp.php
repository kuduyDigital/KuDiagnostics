<?php
/**
 * Plugin Name:       KUDUY MCP
 * Description:       MCP server for WordPress: connect Claude and manage this site under your own WordPress permissions.
 * Version:           0.1.2
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            KUDUY Digital
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kuduy-mcp
 * Update URI:        https://github.com/kuduyDigital/KuDiagnostics
 *
 * @package Kuduy\Mcp
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'KUDUY MCP is missing its vendor/ folder. Install the release ZIP, or run composer install.', 'kuduy-mcp' ) . '</p></div>';
		}
	);
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

Kuduy\Mcp\Http\OutputGuard::start();

register_activation_hook( __FILE__, array( Kuduy\Mcp\Lifecycle\Migrations::class, 'run' ) );
register_deactivation_hook( __FILE__, array( Kuduy\Mcp\Lifecycle\Deactivator::class, 'run' ) );
add_action( 'plugins_loaded', array( Kuduy\Mcp\Plugin::class, 'boot' ) );
