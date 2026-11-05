<?php
/**
 * Authorization codes, access and refresh tokens. Only SHA-256 hashes are stored.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\OAuth;

/**
 * One authorization = one token family (id = first 32 hex chars of the code hash), revoked as a whole on reuse.
 */
final class Tokens {

	public const ACCESS_TTL = HOUR_IN_SECONDS;

	public const REFRESH_TTL = 30 * DAY_IN_SECONDS;

	public const CODE_TTL = 5 * MINUTE_IN_SECONDS;

	private const VERIFIER = '/^[A-Za-z0-9\-._~]{43,128}$/';

	/**
	 * Store a new authorization code.
	 *
	 * @param string       $client_id    Client.
	 * @param int          $user_id      Consenting user.
	 * @param string       $redirect_uri Redirect URI used.
	 * @param list<string> $scopes       Granted scopes.
	 * @param string       $challenge    PKCE S256 challenge.
	 * @return string The raw code.
	 */
	public static function new_code( string $client_id, int $user_id, string $redirect_uri, array $scopes, string $challenge ): string {
		global $wpdb;
		$code = self::random();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		$wpdb->insert(
			$wpdb->prefix . 'kuduy_oauth_codes',
			array(
				'code_hash'    => hash( 'sha256', $code ),
				'client_id'    => $client_id,
				'user_id'      => $user_id,
				'redirect_uri' => $redirect_uri,
				'scope'        => implode( ' ', $scopes ),
				'resource'     => Urls::resource(),
				'challenge'    => $challenge,
				'expires_at'   => self::at( self::CODE_TTL ),
				'used'         => 0,
			)
		);
		return $code;
	}

	/**
	 * Authorization-code grant.
	 *
	 * @param array<string, mixed> $form Token request.
	 * @return array<string, mixed> Token response, or ['error' => …].
	 */
	public static function exchange_code( array $form ): array {
		global $wpdb;
		$code = self::str( $form, 'code' );
		if ( '' === $code ) {
			return self::error( 'invalid_request', 'code is required.' );
		}
		$hash  = hash( 'sha256', $code );
		$codes = $wpdb->prefix . 'kuduy_oauth_codes';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$codes} WHERE code_hash = %s", $hash ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return self::error( 'invalid_grant', 'Unknown authorization code.' );
		}
		$family = substr( $hash, 0, 32 );
		// Atomic single use: only one request can flip used 0 -> 1.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		if ( 1 !== (int) $wpdb->query( $wpdb->prepare( "UPDATE {$codes} SET used = 1 WHERE code_hash = %s AND used = 0", $hash ) ) ) {
			self::revoke_family( $family );
			return self::error( 'invalid_grant', 'Authorization code already used; tokens issued from it were revoked.' );
		}
		if ( self::expired( (string) $row['expires_at'] ) ) {
			return self::error( 'invalid_grant', 'Authorization code expired.' );
		}
		if ( self::str( $form, 'client_id' ) !== $row['client_id'] || self::str( $form, 'redirect_uri' ) !== $row['redirect_uri'] ) {
			return self::error( 'invalid_grant', 'client_id or redirect_uri does not match the authorization request.' );
		}
		$resource = self::str( $form, 'resource' );
		if ( '' !== $resource && ! Urls::is_ours( $resource ) ) {
			return self::error( 'invalid_target', 'resource must be ' . Urls::resource() );
		}
		$verifier = self::str( $form, 'code_verifier' );
		if ( ! preg_match( self::VERIFIER, $verifier ) || ! hash_equals( (string) $row['challenge'], self::b64url( hash( 'sha256', $verifier, true ) ) ) ) {
			return self::error( 'invalid_grant', 'PKCE verification failed.' );
		}
		$user = get_userdata( (int) $row['user_id'] );
		if ( ! $user ) {
			return self::error( 'invalid_grant', 'User no longer exists.' );
		}
		return self::issue( (string) $row['client_id'], $user, Scopes::parse( (string) $row['scope'] ), $family );
	}

	/**
	 * Refresh-token grant with rotation; reuse of a rotated or revoked token revokes the family.
	 *
	 * @param array<string, mixed> $form Token request.
	 * @return array<string, mixed> Token response, or ['error' => …].
	 */
	public static function refresh( array $form ): array {
		global $wpdb;
		$raw = self::str( $form, 'refresh_token' );
		if ( '' === $raw ) {
			return self::error( 'invalid_request', 'refresh_token is required.' );
		}
		$tokens = $wpdb->prefix . 'kuduy_oauth_tokens';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tokens} WHERE token_hash = %s AND type = 'refresh'", hash( 'sha256', $raw ) ), ARRAY_A );
		if ( ! is_array( $row ) || self::str( $form, 'client_id' ) !== $row['client_id'] ) {
			return self::error( 'invalid_grant', 'Unknown refresh token.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		if ( 1 !== (int) $wpdb->query( $wpdb->prepare( "UPDATE {$tokens} SET revoked_at = %s WHERE id = %d AND revoked_at IS NULL", self::at( 0 ), $row['id'] ) ) ) {
			self::revoke_family( (string) $row['family_id'] );
			return self::error( 'invalid_grant', 'Refresh token was already used or revoked; this connection was signed out.' );
		}
		if ( self::expired( (string) $row['expires_at'] ) ) {
			return self::error( 'invalid_grant', 'Refresh token expired.' );
		}
		$resource = self::str( $form, 'resource' );
		if ( '' !== $resource && ! Urls::is_ours( $resource ) ) {
			return self::error( 'invalid_target', 'resource must be ' . Urls::resource() );
		}
		$granted = Scopes::parse( (string) $row['scope'] );
		$asked   = self::str( $form, 'scope' );
		if ( '' !== $asked ) {
			$narrow = Scopes::parse( $asked );
			if ( ! $narrow || array_diff( $narrow, $granted ) ) {
				return self::error( 'invalid_scope', 'Refresh may only narrow the granted scopes.' );
			}
			$granted = $narrow;
		}
		$user = get_userdata( (int) $row['user_id'] );
		if ( ! $user ) {
			return self::error( 'invalid_grant', 'User no longer exists.' );
		}
		$response = self::issue( (string) $row['client_id'], $user, $granted, (string) $row['family_id'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$wpdb->update( $tokens, array( 'replaced_by' => $response['_refresh_id'] ), array( 'id' => $row['id'] ) );
		unset( $response['_refresh_id'] );
		return $response;
	}

	/**
	 * RFC 7009 revocation. Revoking a refresh token ends the whole connection.
	 *
	 * @param string $raw       Token.
	 * @param string $client_id Client presenting it.
	 */
	public static function revoke( string $raw, string $client_id ): void {
		global $wpdb;
		$tokens = $wpdb->prefix . 'kuduy_oauth_tokens';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, type, family_id, client_id FROM {$tokens} WHERE token_hash = %s", hash( 'sha256', $raw ) ), ARRAY_A );
		if ( ! is_array( $row ) || $row['client_id'] !== $client_id ) {
			return;
		}
		if ( 'refresh' === $row['type'] ) {
			self::revoke_family( (string) $row['family_id'] );
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$wpdb->query( $wpdb->prepare( "UPDATE {$tokens} SET revoked_at = %s WHERE id = %d AND revoked_at IS NULL", self::at( 0 ), $row['id'] ) );
	}

	/**
	 * Validate an access token for this resource.
	 *
	 * @param string $raw Bearer token.
	 * @return array{user_id:int, client_id:string, scopes:list<string>}|null
	 */
	public static function authenticate( string $raw ): ?array {
		if ( ! str_starts_with( $raw, 'kmcp_at_' ) ) {
			return null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kuduy_oauth_tokens WHERE token_hash = %s AND type = 'access'", hash( 'sha256', $raw ) ), ARRAY_A );
		if ( ! is_array( $row ) || null !== $row['revoked_at'] || self::expired( (string) $row['expires_at'] ) || ! Urls::is_ours( (string) $row['resource'] ) || ! get_userdata( (int) $row['user_id'] ) ) {
			return null;
		}
		return array(
			'user_id'   => (int) $row['user_id'],
			'client_id' => (string) $row['client_id'],
			'scopes'    => Scopes::parse( (string) $row['scope'] ),
		);
	}

	/**
	 * Issue an access + refresh pair.
	 *
	 * @param string       $client_id Client.
	 * @param \WP_User     $user      User.
	 * @param list<string> $scopes    Requested scopes (re-checked against current capabilities).
	 * @param string       $family    Family id.
	 * @return array<string, mixed>
	 */
	private static function issue( string $client_id, \WP_User $user, array $scopes, string $family ): array {
		global $wpdb;
		$scopes  = Scopes::for_user( $scopes, $user );
		$access  = 'kmcp_at_' . self::random();
		$refresh = 'kmcp_rt_' . self::random();
		$common  = array(
			'family_id' => $family,
			'client_id' => $client_id,
			'user_id'   => $user->ID,
			'scope'     => implode( ' ', $scopes ),
			'resource'  => Urls::resource(),
		);
		$table   = $wpdb->prefix . 'kuduy_oauth_tokens';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		$wpdb->insert(
			$table,
			$common + array(
				'token_hash' => hash( 'sha256', $access ),
				'type'       => 'access',
				'expires_at' => self::at( self::ACCESS_TTL ),
			)
		);
		$wpdb->insert(
			$table,
			$common + array(
				'token_hash' => hash( 'sha256', $refresh ),
				'type'       => 'refresh',
				'expires_at' => self::at( self::REFRESH_TTL ),
			)
		);
		// phpcs:enable
		return array(
			'access_token'  => $access,
			'token_type'    => 'Bearer',
			'expires_in'    => self::ACCESS_TTL,
			'refresh_token' => $refresh,
			'scope'         => implode( ' ', $scopes ),
			'_refresh_id'   => (int) $wpdb->insert_id,
		);
	}

	/**
	 * Revoke every token of a family.
	 *
	 * @param string $family Family id.
	 */
	public static function revoke_family( string $family ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}kuduy_oauth_tokens SET revoked_at = %s WHERE family_id = %s AND revoked_at IS NULL", self::at( 0 ), $family ) );
	}

	/**
	 * OAuth error array.
	 *
	 * @param string $code        Error code.
	 * @param string $description Description.
	 * @return array{error:string, error_description:string}
	 */
	private static function error( string $code, string $description ): array {
		return array(
			'error'             => $code,
			'error_description' => $description,
		);
	}

	/**
	 * String field or ''.
	 *
	 * @param array<string, mixed> $form Form.
	 * @param string               $key  Key.
	 */
	private static function str( array $form, string $key ): string {
		return is_string( $form[ $key ] ?? null ) ? $form[ $key ] : '';
	}

	/**
	 * 256 random bits, base64url.
	 */
	private static function random(): string {
		return self::b64url( random_bytes( 32 ) );
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $bytes Bytes.
	 */
	public static function b64url( string $bytes ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- token encoding.
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * UTC datetime $seconds from now.
	 *
	 * @param int $seconds Offset.
	 */
	private static function at( int $seconds ): string {
		return gmdate( 'Y-m-d H:i:s', time() + $seconds );
	}

	/**
	 * Whether a UTC datetime is in the past.
	 *
	 * @param string $datetime Datetime.
	 */
	private static function expired( string $datetime ): bool {
		return strtotime( $datetime . ' UTC' ) <= time();
	}
}
