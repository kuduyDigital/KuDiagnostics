<?php
/**
 * Bearer-token authentication, only for the MCP endpoint.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\OAuth;

/**
 * Runs on determine_current_user after core's cookie and Application Password checks.
 */
final class TokenAuth {

	/**
	 * Validated grant for this request, if a valid bearer token was sent.
	 *
	 * @var array{user_id:int, client_id:string, scopes:list<string>}|null
	 */
	private static ?array $grant = null;

	/**
	 * OAuth error for this request (`invalid_token`), if a bad bearer token was sent.
	 *
	 * @var string|null
	 */
	private static ?string $error = null;

	/**
	 * Filter callback.
	 *
	 * @param int|false $user_id User found so far.
	 * @return int|false
	 */
	public static function determine_current_user( int|false $user_id ): int|false {
		$token = self::is_mcp_request() ? self::bearer() : null;
		if ( null === $token ) {
			return $user_id;
		}
		self::$grant = Tokens::authenticate( $token );
		if ( null === self::$grant ) {
			// A bad bearer token never falls back to another identity.
			self::$error = 'invalid_token';
			return 0;
		}
		return self::$grant['user_id'];
	}

	/**
	 * Granted scopes, or null when the user is authenticated another way (cookie/Application Password: their WordPress capabilities are the only limit).
	 *
	 * @return list<string>|null
	 */
	public static function scopes(): ?array {
		return self::$grant['scopes'] ?? null;
	}

	/**
	 * OAuth error code for this request, if any.
	 */
	public static function error(): ?string {
		return self::$error;
	}

	/**
	 * Forget per-request state (tests, long-running processes).
	 */
	public static function reset(): void {
		self::$grant = null;
		self::$error = null;
	}

	/**
	 * WWW-Authenticate value (RFC 6750 §3, RFC 9728 §5.1).
	 *
	 * @param string|null $error Error code (invalid_token, insufficient_scope).
	 * @param string|null $scope Scope hint; defaults to the default scopes.
	 */
	public static function challenge( ?string $error = null, ?string $scope = null ): string {
		$parts = array( 'resource_metadata="' . Urls::protected_resource_metadata() . '"' );
		if ( null !== $error ) {
			$parts[] = 'error="' . $error . '"';
		}
		$parts[] = 'scope="' . ( $scope ?? implode( ' ', Scopes::DEFAULT ) ) . '"';
		return 'Bearer ' . implode( ', ', $parts );
	}

	/**
	 * Bearer token from the Authorization header (never from the query string).
	 */
	private static function bearer(): ?string {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- compared against stored hashes only.
		$header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
		// phpcs:enable
		return is_string( $header ) && preg_match( '/^Bearer\s+(\S+)$/i', trim( $header ), $m ) ? $m[1] : null;
	}

	/**
	 * Request targets the MCP endpoint (path or ?rest_route=).
	 */
	private static function is_mcp_request(): bool {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification -- read-only routing check.
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
		$route = isset( $_GET['rest_route'] ) ? (string) $_GET['rest_route'] : '';
		// phpcs:enable
		return str_ends_with( untrailingslashit( $uri ), '/' . rest_get_url_prefix() . '/kuduy-mcp/v1/mcp' ) || '/kuduy-mcp/v1/mcp' === untrailingslashit( $route );
	}
}
