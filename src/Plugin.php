<?php
/**
 * Wiring only: connects components to WordPress hooks.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp;

use Kuduy\Mcp\Http\OutputGuard;
use Kuduy\Mcp\Lifecycle\Migrations;
use Kuduy\Mcp\Mcp\RestController;

/**
 * Plugin entry point.
 */
final class Plugin {

	/**
	 * Must match the `Version:` header in kuduy-mcp.php.
	 */
	public const VERSION = '0.1.0';

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

		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;

		if ( did_action( 'rest_api_init' ) ) {
			RestController::register();
		} else {
			add_action( 'rest_api_init', array( RestController::class, 'register' ) );
		}
		add_filter( 'rest_request_after_callbacks', array( RestController::class, 'after_callbacks' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( RestController::class, 'allow_header' ), 20, 3 );
		add_filter( 'rest_pre_serve_request', array( OutputGuard::class, 'before_serve' ), 0, 3 );
	}
}
