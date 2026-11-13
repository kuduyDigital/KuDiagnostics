<?php
/**
 * Wiring only: connects components to WordPress hooks.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp;

use Kuduy\Mcp\Admin\Cli;
use Kuduy\Mcp\Admin\ConnectionsPage;
use Kuduy\Mcp\Admin\HiddenMode;
use Kuduy\Mcp\Http\OutputGuard;
use Kuduy\Mcp\Jobs\Jobs;
use Kuduy\Mcp\Lifecycle\Cleanup;
use Kuduy\Mcp\Lifecycle\Migrations;
use Kuduy\Mcp\Mcp\RestController;
use Kuduy\Mcp\OAuth\Authorize;
use Kuduy\Mcp\OAuth\Endpoints;
use Kuduy\Mcp\Modules\Types\ContentTypes;
use Kuduy\Mcp\OAuth\TokenAuth;
use Kuduy\Mcp\Update\GitHubUpdater;

/**
 * Plugin entry point.
 */
final class Plugin {

	/**
	 * Must match the `Version:` header in kuduy-mcp.php.
	 */
	public const VERSION = '1.2.0';

	/**
	 * Hooks added once per request.
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * Runs on plugins_loaded.
	 */
	public static function boot(): void {
		// Auto-updates replace files without firing the activation hook.
		Migrations::maybe_upgrade();
		Cleanup::schedule();

		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;

		foreach ( array( array( RestController::class, 'register' ), array( Endpoints::class, 'register' ) ) as $routes ) {
			if ( did_action( 'rest_api_init' ) ) {
				$routes();
			} else {
				add_action( 'rest_api_init', $routes );
			}
		}
		add_filter( 'rest_request_after_callbacks', array( RestController::class, 'after_callbacks' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( RestController::class, 'allow_header' ), 20, 3 );
		add_filter( 'rest_pre_serve_request', array( OutputGuard::class, 'before_serve' ), 0, 3 );

		// After core's cookie (10) and Application Password (20) checks: a bad bearer token wins and yields no user.
		add_filter( 'determine_current_user', array( TokenAuth::class, 'determine_current_user' ), 30 );
		add_filter( 'rest_pre_dispatch', array( TokenAuth::class, 'guard_route' ), 0, 3 );
		add_action( 'login_form_kuduy-mcp-authorize', array( Authorize::class, 'route' ) );
		add_action( 'init', array( Endpoints::class, 'well_known' ), 0 );
		add_action( 'admin_notices', array( self::class, 'permalink_notice' ) );
		add_action( Cleanup::HOOK, array( Cleanup::class, 'run' ) );
		add_action( Jobs::HOOK, array( Jobs::class, 'run' ) );
		add_action( 'admin_menu', array( ConnectionsPage::class, 'menu' ) );
		// Hidden mode and its recovery (Phase 8): presentation only, the MCP runtime never reads it.
		HiddenMode::register();
		Cli::register();
		// Post types and taxonomies defined through kuduy-content-type-save (D22).
		ContentTypes::boot();

		add_filter( 'update_plugins_github.com', array( GitHubUpdater::class, 'filter_update' ), 10, 3 );
		add_filter( 'site_transient_update_plugins', array( GitHubUpdater::class, 'inject' ) );
		add_filter( 'plugins_api', array( GitHubUpdater::class, 'plugins_api' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( GitHubUpdater::class, 'pre_download' ), 10, 2 );
	}

	/**
	 * OAuth issuer URLs need pretty permalinks (an issuer with ?rest_route= is not valid).
	 */
	public static function permalink_notice(): void {
		if ( HiddenMode::is_hidden() || '' !== (string) get_option( 'permalink_structure' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'KuDiagnostics needs pretty permalinks so Claude can sign in. Go to Settings → Permalinks and choose any option except "Plain".', 'kuduy-mcp' ) . '</p></div>';
	}
}
