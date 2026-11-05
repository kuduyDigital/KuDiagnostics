<?php
/**
 * Per user + client call limit (test matrix F11).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Policy;

/**
 * Fixed one-minute window. shortcut: not atomic under heavy concurrency, upgrade to wp_cache_incr when sites run a persistent object cache.
 */
final class RateLimiter {

	/**
	 * Count one call; returns seconds to wait (0 = allowed).
	 *
	 * @param int    $user_id User.
	 * @param string $client  Client label.
	 */
	public static function hit( int $user_id, string $client ): int {
		/**
		 * Filters tool calls allowed per user and client per minute.
		 *
		 * @param int    $limit   Calls per minute.
		 * @param int    $user_id User.
		 * @param string $client  Client label.
		 */
		$limit = (int) apply_filters( 'kuduy_mcp_rate_limit', 120, $user_id, $client );
		$now   = time();
		$key   = 'kuduy_mcp_rl_' . md5( $user_id . '|' . $client . '|' . intdiv( $now, 60 ) );
		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, 2 * MINUTE_IN_SECONDS );
		return $count > $limit ? max( 1, 60 - $now % 60 ) : 0;
	}
}
