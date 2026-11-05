<?php
/**
 * OAuth clients: Client ID Metadata Documents (preferred) and Dynamic Client Registration (deprecated, for Claude.ai).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\OAuth;

/**
 * All clients are public (`token_endpoint_auth_method: none`).
 */
final class Clients {

	private const CIMD_MAX_BYTES = 65536;

	private const LOOPBACK = array( 'localhost', '127.0.0.1', '[::1]' );

	/**
	 * Client by id, or null when unknown or its metadata document is invalid.
	 *
	 * @param string $client_id Client id.
	 * @return array{client_id:string, name:string, redirect_uris:list<string>, type:string}|null
	 */
	public static function find( string $client_id ): ?array {
		return self::is_cimd_id( $client_id ) ? self::cimd( $client_id ) : self::registered( $client_id );
	}

	/**
	 * CIMD client ids are https URLs with a path (MCP client-registration).
	 *
	 * @param string $client_id Client id.
	 */
	public static function is_cimd_id( string $client_id ): bool {
		$p = wp_parse_url( $client_id );
		return is_array( $p ) && 'https' === ( $p['scheme'] ?? '' ) && ! empty( $p['host'] )
			&& isset( $p['path'] ) && '/' !== $p['path'] && '' !== $p['path']
			&& ! isset( $p['fragment'] ) && ! isset( $p['user'] ) && ! isset( $p['pass'] )
			&& strlen( $client_id ) <= 191;
	}

	/**
	 * Redirect URI allowed for this client: exact match, or loopback with any port (RFC 8252 §7.3).
	 *
	 * @param array{redirect_uris:list<string>} $client Client.
	 * @param string                            $uri    Requested redirect URI.
	 */
	public static function redirect_allowed( array $client, string $uri ): bool {
		if ( in_array( $uri, $client['redirect_uris'], true ) ) {
			return true;
		}
		$asked = wp_parse_url( $uri );
		if ( ! is_array( $asked ) || 'http' !== ( $asked['scheme'] ?? '' ) || ! in_array( $asked['host'] ?? '', self::LOOPBACK, true ) ) {
			return false;
		}
		foreach ( $client['redirect_uris'] as $registered ) {
			$r = wp_parse_url( $registered );
			if ( is_array( $r ) && 'http' === ( $r['scheme'] ?? '' ) && ( $r['host'] ?? '' ) === $asked['host']
				&& ( $r['path'] ?? '' ) === ( $asked['path'] ?? '' ) && ( $r['query'] ?? '' ) === ( $asked['query'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Redirect URIs must be https or http loopback, absolute, without fragment.
	 *
	 * @param mixed $uri Candidate.
	 */
	public static function valid_redirect( mixed $uri ): bool {
		if ( ! is_string( $uri ) || strlen( $uri ) > 2048 ) {
			return false;
		}
		$p = wp_parse_url( $uri );
		if ( ! is_array( $p ) || empty( $p['host'] ) || isset( $p['fragment'] ) ) {
			return false;
		}
		$scheme = $p['scheme'] ?? '';
		return 'https' === $scheme || ( 'http' === $scheme && in_array( $p['host'], self::LOOPBACK, true ) );
	}

	/**
	 * Dynamic Client Registration (RFC 7591).
	 *
	 * @param array<string, mixed> $meta Client metadata.
	 * @return array<string, mixed>|\WP_Error Registration response or error (data: status).
	 */
	public static function register( array $meta ): array|\WP_Error {
		$uris = $meta['redirect_uris'] ?? null;
		if ( ! is_array( $uris ) || ! $uris || ! array_is_list( $uris ) ) {
			return new \WP_Error( 'invalid_client_metadata', 'redirect_uris must be a non-empty array.', array( 'status' => 400 ) );
		}
		foreach ( $uris as $uri ) {
			if ( ! self::valid_redirect( $uri ) ) {
				return new \WP_Error( 'invalid_redirect_uri', 'Redirect URIs must be https, or http on localhost/127.0.0.1, without a fragment.', array( 'status' => 400 ) );
			}
		}
		$name = is_string( $meta['client_name'] ?? null ) ? sanitize_text_field( $meta['client_name'] ) : '';
		$name = '' === $name ? 'MCP client' : mb_substr( $name, 0, 200 );

		global $wpdb;
		$client_id = 'kmcp_c_' . bin2hex( random_bytes( 16 ) );
		$now       = time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		$wpdb->insert(
			$wpdb->prefix . 'kuduy_oauth_clients',
			array(
				'client_id'     => $client_id,
				'type'          => 'dcr',
				'name'          => $name,
				'redirect_uris' => (string) wp_json_encode( $uris ),
				'created_at'    => gmdate( 'Y-m-d H:i:s', $now ),
			)
		);
		return array(
			'client_id'                  => $client_id,
			'client_id_issued_at'        => $now,
			'client_name'                => $name,
			'redirect_uris'              => $uris,
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			// Public client regardless of what was asked: no secrets are issued.
			'token_endpoint_auth_method' => 'none',
		);
	}

	/**
	 * Keep unused DCR clients (never got a code or token) under a cap before registering another.
	 * At the cap, the oldest unused ones older than an hour are deleted; false only while a flood is under way.
	 */
	public static function make_room(): bool {
		global $wpdb;
		/**
		 * Filters how many registered-but-unused DCR clients the site keeps.
		 *
		 * @param int $limit Unused clients.
		 */
		$limit  = max( 1, (int) apply_filters( 'kuduy_mcp_registration_limit', 1000 ) );
		$p      = $wpdb->prefix;
		$unused = "FROM {$p}kuduy_oauth_clients WHERE type = 'dcr' AND client_id NOT IN ( SELECT client_id FROM {$p}kuduy_oauth_tokens ) AND client_id NOT IN ( SELECT client_id FROM {$p}kuduy_oauth_codes )";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- custom tables, fixed SQL.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) {$unused}" );
		if ( $count < $limit ) {
			return true;
		}
		$stale = $wpdb->get_col( $wpdb->prepare( "SELECT client_id {$unused} AND created_at < %s ORDER BY created_at LIMIT %d", gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ), $count - $limit + 1 ) );
		if ( ! $stale ) {
			return false;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}kuduy_oauth_clients WHERE client_id IN (" . implode( ',', array_fill( 0, count( $stale ), '%s' ) ) . ')', $stale ) );
		// phpcs:enable
		return $count - count( $stale ) < $limit;
	}

	/**
	 * DCR client from the table.
	 *
	 * @param string $client_id Client id.
	 * @return array{client_id:string, name:string, redirect_uris:list<string>, type:string}|null
	 */
	private static function registered( string $client_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table, primary-key lookup.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kuduy_oauth_clients WHERE client_id = %s AND type = 'dcr'", $client_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$uris = json_decode( (string) $row['redirect_uris'], true );
		return array(
			'client_id'     => $client_id,
			'name'          => (string) $row['name'],
			'redirect_uris' => is_array( $uris ) ? array_values( array_filter( $uris, 'is_string' ) ) : array(),
			'type'          => 'dcr',
		);
	}

	/**
	 * CIMD client: cached document, or a fresh SSRF-safe fetch.
	 *
	 * @param string $url Client id URL.
	 * @return array{client_id:string, name:string, redirect_uris:list<string>, type:string}|null
	 */
	private static function cimd( string $url ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'kuduy_oauth_clients';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$cached = $wpdb->get_var( $wpdb->prepare( "SELECT metadata_cache FROM {$table} WHERE client_id = %s AND type = 'cimd'", $url ) );
		$cached = is_string( $cached ) ? json_decode( $cached, true ) : null;
		if ( is_array( $cached ) && ( $cached['expires'] ?? 0 ) > time() ) {
			return self::client( $url, (string) $cached['name'], (array) $cached['redirect_uris'] );
		}

		// wp_safe_remote_get() refuses private, loopback and reserved IPs and non-standard ports.
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => self::CIMD_MAX_BYTES,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$doc = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $doc ) || ( $doc['client_id'] ?? null ) !== $url ) {
			return null;
		}
		$uris = $doc['redirect_uris'] ?? null;
		if ( ! is_array( $uris ) || ! $uris || count( array_filter( $uris, array( self::class, 'valid_redirect' ) ) ) !== count( $uris ) ) {
			return null;
		}
		if ( 'none' !== ( $doc['token_endpoint_auth_method'] ?? 'none' ) ) {
			return null;
			// Only public clients; private_key_jwt is not supported.
		}
		$name = is_string( $doc['client_name'] ?? null ) && '' !== $doc['client_name'] ? mb_substr( sanitize_text_field( $doc['client_name'] ), 0, 200 ) : (string) wp_parse_url( $url, PHP_URL_HOST );

		$cache = array(
			'name'          => $name,
			'redirect_uris' => array_values( $uris ),
			'expires'       => time() + self::ttl( (string) wp_remote_retrieve_header( $response, 'cache-control' ) ),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$wpdb->replace(
			$table,
			array(
				'client_id'      => $url,
				'type'           => 'cimd',
				'name'           => $name,
				'redirect_uris'  => (string) wp_json_encode( $cache['redirect_uris'] ),
				'metadata_url'   => $url,
				'metadata_cache' => (string) wp_json_encode( $cache ),
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		return self::client( $url, $name, $cache['redirect_uris'] );
	}

	/**
	 * Cache lifetime from Cache-Control, clamped to 5 min – 24 h (default 1 h).
	 *
	 * @param string $header Cache-Control header.
	 */
	private static function ttl( string $header ): int {
		if ( str_contains( $header, 'no-store' ) || str_contains( $header, 'no-cache' ) ) {
			return 300;
		}
		return preg_match( '/max-age=(\d+)/', $header, $m ) ? max( 300, min( DAY_IN_SECONDS, (int) $m[1] ) ) : HOUR_IN_SECONDS;
	}

	/**
	 * Client shape.
	 *
	 * @param string       $id   Client id.
	 * @param string       $name Name.
	 * @param array<mixed> $uris Redirect URIs.
	 * @return array{client_id:string, name:string, redirect_uris:list<string>, type:string}
	 */
	private static function client( string $id, string $name, array $uris ): array {
		return array(
			'client_id'     => $id,
			'name'          => $name,
			'redirect_uris' => array_values( array_filter( $uris, 'is_string' ) ),
			'type'          => 'cimd',
		);
	}
}
