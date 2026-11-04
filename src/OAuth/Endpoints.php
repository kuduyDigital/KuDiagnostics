<?php
/**
 * REST endpoints of the authorization server, plus root /.well-known/ documents.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\OAuth;

/**
 * Metadata (RFC 9728, RFC 8414), token, DCR (RFC 7591), revocation (RFC 7009).
 */
final class Endpoints {

	private const NS = 'kuduy-mcp/v1';

	/**
	 * Register routes (rest_api_init).
	 */
	public static function register(): void {
		$public = array( 'permission_callback' => '__return_true' );
		register_rest_route(
			self::NS,
			'/oauth/protected-resource',
			$public + array(
				'methods'  => 'GET',
				'callback' => static fn() => self::json( self::protected_resource() ),
			)
		);
		foreach ( array( 'openid-configuration', 'oauth-authorization-server' ) as $doc ) {
			register_rest_route(
				self::NS,
				'/oauth/.well-known/' . $doc,
				$public + array(
					'methods'  => 'GET',
					'callback' => static fn() => self::json( self::authorization_server() ),
				)
			);
		}
		register_rest_route(
			self::NS,
			'/oauth/token',
			$public + array(
				'methods'  => 'POST',
				'callback' => array( self::class, 'token' ),
			)
		);
		register_rest_route(
			self::NS,
			'/oauth/register',
			$public + array(
				'methods'  => 'POST',
				'callback' => array( self::class, 'register_client' ),
			)
		);
		register_rest_route(
			self::NS,
			'/oauth/revoke',
			$public + array(
				'methods'  => 'POST',
				'callback' => array( self::class, 'revoke' ),
			)
		);
	}

	/**
	 * RFC 9728 document.
	 *
	 * @return array<string, mixed>
	 */
	public static function protected_resource(): array {
		return array(
			'resource'                 => Urls::resource(),
			'authorization_servers'    => array( Urls::issuer() ),
			// offline_access is an AS concern, not a resource requirement (MCP authorization §Refresh Tokens).
			'scopes_supported'         => Scopes::supported(),
			'bearer_methods_supported' => array( 'header' ),
			'resource_name'            => get_bloginfo( 'name' ) . ' (KUDUY MCP)',
		);
	}

	/**
	 * RFC 8414 document (also served at the OIDC discovery paths).
	 *
	 * @return array<string, mixed>
	 */
	public static function authorization_server(): array {
		$issuer = Urls::issuer();
		return array(
			'issuer'                                     => $issuer,
			'authorization_endpoint'                     => Urls::authorize(),
			'token_endpoint'                             => $issuer . '/token',
			'registration_endpoint'                      => $issuer . '/register',
			'revocation_endpoint'                        => $issuer . '/revoke',
			'scopes_supported'                           => array_merge( Scopes::supported(), array( 'offline_access' ) ),
			'response_types_supported'                   => array( 'code' ),
			'response_modes_supported'                   => array( 'query' ),
			'grant_types_supported'                      => array( 'authorization_code', 'refresh_token' ),
			'token_endpoint_auth_methods_supported'      => array( 'none' ),
			'revocation_endpoint_auth_methods_supported' => array( 'none' ),
			'code_challenge_methods_supported'           => array( 'S256' ),
			'client_id_metadata_document_supported'      => true,
			'authorization_response_iss_parameter_supported' => true,
		);
	}

	/**
	 * Token endpoint.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public static function token( \WP_REST_Request $request ): \WP_REST_Response {
		$form   = self::form( $request );
		$result = match ( $form['grant_type'] ?? null ) {
			'authorization_code' => Tokens::exchange_code( $form ),
			'refresh_token'      => Tokens::refresh( $form ),
			default              => array(
				'error'             => 'unsupported_grant_type',
				'error_description' => 'Use authorization_code or refresh_token.',
			),
		};
		return self::json( $result, isset( $result['error'] ) ? 400 : 200 );
	}

	/**
	 * Dynamic Client Registration, rate-limited per IP.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public static function register_client( \WP_REST_Request $request ): \WP_REST_Response {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' !== $ip ) {
			$key   = 'kuduy_mcp_dcr_' . md5( $ip );
			$count = (int) get_transient( $key );
			/**
			 * Filters how many client registrations one IP may make per hour.
			 *
			 * @param int $limit Registrations per hour.
			 */
			if ( $count >= (int) apply_filters( 'kuduy_mcp_registration_limit', 30 ) ) {
				return self::json(
					array(
						'error'             => 'temporarily_unavailable',
						'error_description' => 'Too many registrations from this address; try again later.',
					),
					429
				);
			}
			set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		}
		$meta   = $request->get_json_params();
		$result = Clients::register( $meta ?? array() );
		if ( is_wp_error( $result ) ) {
			return self::json(
				array(
					'error'             => $result->get_error_code(),
					'error_description' => $result->get_error_message(),
				),
				400
			);
		}
		return self::json( $result, 201 );
	}

	/**
	 * RFC 7009: always 200, whatever the token.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public static function revoke( \WP_REST_Request $request ): \WP_REST_Response {
		$form = self::form( $request );
		if ( is_string( $form['token'] ?? null ) && is_string( $form['client_id'] ?? null ) ) {
			Tokens::revoke( $form['token'], $form['client_id'] );
		}
		return self::json( array() );
	}

	/**
	 * Serve /.well-known/ documents when the host passes them to WordPress (hooked to init).
	 */
	public static function well_known(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared against fixed paths only.
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
		if ( ! str_starts_with( $path, '/.well-known/' ) ) {
			return;
		}
		$issuer_path = (string) wp_parse_url( Urls::issuer(), PHP_URL_PATH );
		$docs        = array(
			'/.well-known/oauth-protected-resource' . wp_parse_url( Urls::resource(), PHP_URL_PATH ) => array( self::class, 'protected_resource' ),
			'/.well-known/oauth-protected-resource' => array( self::class, 'protected_resource' ),
			'/.well-known/oauth-authorization-server' . $issuer_path => array( self::class, 'authorization_server' ),
			'/.well-known/openid-configuration' . $issuer_path => array( self::class, 'authorization_server' ),
		);
		$callback    = $docs[ untrailingslashit( $path ) ] ?? null;
		if ( null === $callback ) {
			return;
		}
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Cache-Control: public, max-age=300' );
		wp_send_json( $callback(), 200 );
	}

	/**
	 * Form body (application/x-www-form-urlencoded), JSON accepted too.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array<string, mixed>
	 */
	private static function form( \WP_REST_Request $request ): array {
		$form = $request->get_body_params();
		if ( ! $form ) {
			// Core returns null for non-JSON bodies, although its docblock says array.
			$form = $request->get_json_params() ?? array();
		}
		return $form;
	}

	/**
	 * JSON response that must not be cached (tokens, registrations).
	 *
	 * @param array<string, mixed> $data   Body.
	 * @param int                  $status Status.
	 */
	private static function json( array $data, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data ? $data : new \stdClass(), $status );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}
}
