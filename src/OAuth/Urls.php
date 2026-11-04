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
		return untrailingslashit( rest_url( 'kuduy-mcp/v1/mcp' ) );
	}

	/**
	 * Authorization server issuer.
	 */
	public static function issuer(): string {
		return untrailingslashit( rest_url( 'kuduy-mcp/v1/oauth' ) );
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
