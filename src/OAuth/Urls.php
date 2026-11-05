<?php
/**
 * Canonical URLs of the resource and the authorization server.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\OAuth;

/**
 * The issuer is a REST path so `{issuer}/.well-known/openid-configuration` works on hosts that block `/.well-known/`.
 */
final class Urls {

	/**
	 * MCP endpoint, the RFC 8707 resource every token is bound to.
	 */
	public static function resource(): string {
		return self::rest( 'kuduy-mcp/v1/mcp' );
	}

	/**
	 * Authorization server issuer.
	 */
	public static function issuer(): string {
		return self::rest( 'kuduy-mcp/v1/oauth' );
	}

	/**
	 * Whether OAuth must refuse this request because it is not HTTPS.
	 * Local sites (loopback, *.localhost, *.test, WP_ENVIRONMENT_TYPE=local) may use plain HTTP.
	 * Behind a TLS-terminating proxy, X-Forwarded-Proto: https counts (the site URL must still be https).
	 */
	public static function insecure(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared with a fixed value only.
		$forwarded = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ? strtolower( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] ) : '';
		if ( 'https' === wp_parse_url( self::resource(), PHP_URL_SCHEME ) && ( is_ssl() || 'https' === $forwarded ) ) {
			return false;
		}
		$host  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$local = 'local' === wp_get_environment_type()
			|| in_array( $host, array( 'localhost', '127.0.0.1', '[::1]' ), true )
			|| str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.test' );
		/**
		 * Filters whether OAuth and bearer tokens require HTTPS on this site.
		 *
		 * @param bool $required True unless the site is local.
		 */
		return (bool) apply_filters( 'kuduy_mcp_require_https', ! $local );
	}

	/**
	 * REST URL that also works before WordPress creates $wp_rewrite: rest_url() calls a method on it with pretty
	 * permalinks, so a plugin that checks the current user on plugins_loaded would otherwise cause a fatal error.
	 *
	 * @param string $path REST path.
	 */
	private static function rest( string $path ): string {
		if ( isset( $GLOBALS['wp_rewrite'] ) && $GLOBALS['wp_rewrite'] instanceof \WP_Rewrite ) {
			return untrailingslashit( rest_url( $path ) );
		}
		$path = '/' . ltrim( $path, '/' );
		$url  = get_option( 'permalink_structure' )
			? get_home_url( null, rest_get_url_prefix(), 'rest' ) . $path
			: add_query_arg( 'rest_route', $path, trailingslashit( get_home_url( null, '', 'rest' ) ) . 'index.php' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- same filter rest_url() applies.
		return untrailingslashit( (string) apply_filters( 'rest_url', $url, $path, null, 'rest' ) );
	}

	/**
	 * Protected resource metadata (RFC 9728), as sent in WWW-Authenticate.
	 */
	public static function protected_resource_metadata(): string {
		return self::issuer() . '/protected-resource';
	}

	/**
	 * Browser-facing authorization endpoint (needs the login cookie, which REST ignores without a nonce).
	 */
	public static function authorize(): string {
		return site_url( 'wp-login.php?action=kuduy-mcp-authorize', 'login' );
	}

	/**
	 * Whether a client-supplied resource names our MCP endpoint (scheme/host case-insensitive, trailing slash ignored).
	 *
	 * @param string $uri Resource URI.
	 */
	public static function is_ours( string $uri ): bool {
		return self::normalize( $uri ) === self::normalize( self::resource() );
	}

	/**
	 * Lower-case scheme and host, drop a trailing slash; fragments make it invalid.
	 *
	 * @param string $uri URI.
	 */
	private static function normalize( string $uri ): ?string {
		$p = wp_parse_url( $uri );
		if ( ! is_array( $p ) || empty( $p['scheme'] ) || empty( $p['host'] ) || isset( $p['fragment'] ) ) {
			return null;
		}
		return strtolower( $p['scheme'] ) . '://' . strtolower( $p['host'] ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' )
			. untrailingslashit( $p['path'] ?? '' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
	}
}
