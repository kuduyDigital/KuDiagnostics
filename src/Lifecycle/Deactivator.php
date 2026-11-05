<?php
/**
 * Deactivation: stop scheduled work, keep all data.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Lifecycle;

/**
 * Registered with register_deactivation_hook().
 */
final class Deactivator {

	/**
	 * Clear cron events.
	 */
	public static function run(): void {
		wp_clear_scheduled_hook( Cleanup::HOOK );
	}
}
