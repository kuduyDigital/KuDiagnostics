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
	 * The only REST route a bearer token is valid on.
	 */
	private const ROUTE = '/kuduy-mcp/v1/mcp';

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
	 * Whether the request's first REST dispatch (the HTTP request itself) was already checked.
	 *
	 * @var bool
	 */
	private static bool $route_checked = false;

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
	 * OAuth client of this request, or null when not authenticated by token.
	 */
	public static function client_id(): ?string {
		return self::$grant['client_id'] ?? null;
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
		self::$grant         = null;
		self::$error         = null;
		self::$route_checked = false;
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
	 * Hooked to rest_pre_dispatch: a bearer token is valid on the MCP route only, never on core or other routes.
	 * Only the first dispatch is the HTTP request; later ones are internal calls a tool makes (rest_do_request) and stay allowed.
	 *
	 * @param mixed            $result  Response so far (null).
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return mixed
	 */
	public static function guard_route( mixed $result, \WP_REST_Server $server, \WP_REST_Request $request ): mixed {
		if ( null === self::$grant || self::$route_checked ) {
			return $result;
		}
		self::$route_checked = true;
		if ( self::ROUTE === untrailingslashit( $request->get_route() ) ) {
			return $result;
		}
		return new \WP_Error( 'kuduy_mcp_token_route', 'This access token only works on the KUDUY MCP endpoint.', array( 'status' => 401 ) );
	}

	/**
	 * Request targets exactly the MCP endpoint: the REST path itself, or the site root with ?rest_route= (plain permalinks).
	 * A different rest_route in the query or body would make WordPress dispatch elsewhere, so it disqualifies the request.
	 */
	private static function is_mcp_request(): bool {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification -- read-only routing check.
		$path = isset( $_SERVER['REQUEST_URI'] ) ? untrailingslashit( (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) ) : '';
		foreach ( array( $_GET, $_POST ) as $input ) {
			if ( isset( $input['rest_route'] ) && ( ! is_string( $input['rest_route'] ) || self::ROUTE !== untrailingslashit( $input['rest_route'] ) ) ) {
				return false;
			}
		}
		$has_route = isset( $_GET['rest_route'] ) || isset( $_POST['rest_route'] );
		// phpcs:enable
		$home = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		$rest = '/' . rest_get_url_prefix() . self::ROUTE;
		if ( in_array( $path, array( $home . $rest, $home . '/index.php' . $rest ), true ) ) {
			return true;
		}
		return $has_route && in_array( $path, array( $home, $home . '/index.php' ), true );
	}
}
