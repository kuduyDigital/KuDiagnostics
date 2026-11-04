<?php
/**
 * Wiring only: connects components to WordPress hooks.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp;

use Kuduy\Mcp\Lifecycle\Migrations;

/**
 * Plugin entry point.
 */
final class Plugin {

	/**
	 * Runs on plugins_loaded.
	 */
	public static function boot(): void {
		// Auto-updates replace files without firing the activation hook.
		Migrations::maybe_upgrade();
	}
}
