<?php
/**
 * Keeps MCP responses valid JSON when other code prints during the request (test matrix F2).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Http;

use Kuduy\Mcp\Mcp\RestController;

/**
 * Buffers from plugin load on our route and throws the stray output away before serving.
 */
final class OutputGuard {

	/**
	 * Output-buffer level we opened, or 0.
	 *
	 * @var int
	 */
	private static int $level = 0;

	/**
	 * Start buffering if this HTTP request targets the MCP endpoint. Called from the main plugin file.
	 */
	public static function start(): void {
		if ( self::$level || ! self::is_mcp_request() ) {
			return;
		}
		ob_start();
		self::$level = ob_get_level();
	}

	/**
	 * Hooked to rest_pre_serve_request: drop stray output; send 202 without a body.
	 *
	 * @param bool              $served  Whether the request was already served.
	 * @param \WP_HTTP_Response $result  Result.
	 * @param \WP_REST_Request  $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public static function before_serve( bool $served, \WP_HTTP_Response $result, \WP_REST_Request $request ): bool {
		if ( ! RestController::is_ours( $request ) ) {
			return $served;
		}
		while ( self::$level && ob_get_level() >= self::$level ) {
			ob_end_clean();
		}
		self::$level = 0;
		// Notifications: "202 Accepted with no body" (WordPress would print `null`).
		return 202 === $result->get_status() || 405 === $result->get_status() ? true : $served;
	}

	/**
	 * Request path or ?rest_route= points at our namespace.
	 */
	private static function is_mcp_request(): bool {
		$needle = '/' . RestController::NAMESPACE . '/';
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification -- read-only routing check before WordPress loads REST.
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$route = isset( $_GET['rest_route'] ) ? (string) $_GET['rest_route'] : '';
		// phpcs:enable
		return str_contains( $uri, '/wp-json' . $needle ) || str_starts_with( $route, $needle );
	}
}
