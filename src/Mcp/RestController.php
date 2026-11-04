<?php
/**
 * Streamable HTTP binding: POST /wp-json/kuduy-mcp/v1/mcp.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Mcp;

/**
 * Maps HTTP to Server and back.
 */
final class RestController {

	public const NAMESPACE = 'kuduy-mcp/v1';

	public const ROUTE = '/mcp';

	/**
	 * Register the route (on rest_api_init).
	 */
	public static function register(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'post' ),
					// Auth is decided per JSON-RPC method inside Server so errors stay JSON-RPC shaped.
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'GET, DELETE',
					'callback'            => array( self::class, 'not_allowed' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Handle a POST.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public static function post( \WP_REST_Request $request ): \WP_REST_Response {
		$origin = $request->get_header( 'origin' );
		if ( null !== $origin && ! in_array( untrailingslashit( $origin ), self::allowed_origins(), true ) ) {
			return self::respond( Server::fail( null, -32600, 'Forbidden origin', 403 ) );
		}
		$message = json_decode( $request->get_body(), true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return self::parse_error();
		}
		$headers = array();
		foreach ( array( 'mcp-protocol-version', 'mcp-method', 'mcp-name' ) as $name ) {
			$headers[ $name ] = $request->get_header( $name );
		}
		return self::respond( ( new Server() )->handle( $message, $headers ) );
	}

	/**
	 * GET/DELETE: no standalone stream and no sessions.
	 */
	public static function not_allowed(): \WP_REST_Response {
		$response = new \WP_REST_Response( null, 405 );
		$response->header( 'Allow', 'POST' );
		return $response;
	}

	/**
	 * WordPress rejects invalid JSON before our callback; turn that into a JSON-RPC parse error.
	 *
	 * @param mixed            $response Response or error.
	 * @param array<mixed>     $handler  Route handler.
	 * @param \WP_REST_Request $request  Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return mixed
	 */
	public static function after_callbacks( mixed $response, array $handler, \WP_REST_Request $request ): mixed {
		if ( is_wp_error( $response ) && 'rest_invalid_json' === $response->get_error_code() && self::is_ours( $request ) ) {
			return self::parse_error();
		}
		return $response;
	}

	/**
	 * Core's rest_send_allow_header lists every registered method (POST, GET, DELETE); only POST is allowed.
	 *
	 * @param \WP_HTTP_Response $response Response.
	 * @param \WP_REST_Server   $server   Server.
	 * @param \WP_REST_Request  $request  Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public static function allow_header( \WP_HTTP_Response $response, \WP_REST_Server $server, \WP_REST_Request $request ): \WP_HTTP_Response {
		if ( self::is_ours( $request ) ) {
			$response->header( 'Allow', 'POST' );
		}
		return $response;
	}

	/**
	 * Whether a REST request targets this endpoint.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public static function is_ours( \WP_REST_Request $request ): bool {
		return str_starts_with( $request->get_route(), '/' . self::NAMESPACE . '/' );
	}

	/**
	 * Origins allowed to call the endpoint from a browser (DNS-rebinding/CSRF guard).
	 *
	 * @return list<string>
	 */
	private static function allowed_origins(): array {
		$origins = array( 'https://claude.ai', 'https://claude.com' );
		foreach ( array( home_url(), site_url() ) as $url ) {
			$parts = wp_parse_url( $url );
			if ( isset( $parts['scheme'], $parts['host'] ) ) {
				$origins[] = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
			}
		}
		/**
		 * Filters origins (scheme://host[:port]) allowed to send requests to the MCP endpoint.
		 *
		 * @param list<string> $origins Allowed origins.
		 */
		return array_values( array_unique( (array) apply_filters( 'kuduy_mcp_allowed_origins', $origins ) ) );
	}

	/**
	 * JSON-RPC parse error response.
	 */
	private static function parse_error(): \WP_REST_Response {
		return self::respond( Server::fail( null, -32700, 'Parse error', 400 ) );
	}

	/**
	 * Server output to REST response.
	 *
	 * @param array{status:int, body:array<string, mixed>|null, headers?:array<string, string>} $out Server output.
	 */
	private static function respond( array $out ): \WP_REST_Response {
		$response = new \WP_REST_Response( $out['body'], $out['status'] );
		foreach ( $out['headers'] ?? array() as $name => $value ) {
			$response->header( $name, $value );
		}
		return $response;
	}
}
