<?php
/**
 * OAuth 2.1 authorization server and bearer auth (test matrix OAuth section).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolRegistry;
use Kuduy\Mcp\OAuth\Authorize;
use Kuduy\Mcp\OAuth\Scopes;
use Kuduy\Mcp\OAuth\TokenAuth;
use Kuduy\Mcp\OAuth\Urls;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

final class OAuthTest extends TestCase {

	private const CLAUDE_REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

	private static int $admin;

	private static int $subscriber;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		update_option( 'permalink_structure', '/%postname%/' );
		$GLOBALS['wp_rewrite']->init();
		self::$admin      = self::user( 'administrator' );
		self::$subscriber = self::user( 'subscriber' );
		add_action( 'kuduy_mcp_register_tools', array( self::class, 'register_write_tool' ) );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_GET['rest_route'], $_POST['rest_route'] );
		$_SERVER['REQUEST_URI'] = '/';
		TokenAuth::reset();
		remove_all_filters( 'pre_http_request' );
	}

	private static function user( string $role ): int {
		$id = wp_insert_user(
			array(
				'user_login' => 'oauth_' . $role . '_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => $role,
			)
		);
		return is_wp_error( $id ) ? 0 : $id;
	}

	public static function register_write_tool( ToolRegistry $registry ): void {
		$registry->register(
			new class() implements Tool {
				public function name(): string {
					return 'test-write';
				}
				public function title(): string {
					return 'Write';
				}
				public function description(): string {
					return 'test';
				}
				public function input_schema(): array {
					return array( 'type' => 'object' );
				}
				public function output_schema(): array {
					return array( 'type' => 'object' );
				}
				public function annotations(): array {
					return array();
				}
				public function scope(): string {
					return 'kuduy:write';
				}
				public function capability( array $input ): string {
					return 'read';
				}
				public function is_available(): bool|string {
					return true;
				}
				public function execute( array $input ): array {
					return array( 'written' => true );
				}
			}
		);
		$registry->register(
			new class() implements Tool, Snapshottable {
				public function name(): string {
					return 'test-admin-setting';
				}
				public function title(): string {
					return 'Admin setting';
				}
				public function description(): string {
					return 'test';
				}
				public function input_schema(): array {
					return array(
						'type'       => 'object',
						'properties' => array( 'value' => array( 'type' => 'string' ) ),
					);
				}
				public function output_schema(): array {
					return array( 'type' => 'object' );
				}
				public function annotations(): array {
					return array( 'destructiveHint' => true );
				}
				public function scope(): string {
					return 'kuduy:admin';
				}
				public function capability( array $input ): string {
					return 'manage_options';
				}
				public function is_available(): bool|string {
					return true;
				}
				public function execute( array $input ): array {
					update_option( 'kuduy_test_admin_setting', $input['value'] ?? '' );
					return array( 'ok' => true );
				}
				public function snapshot( array $input ): array {
					return array(
						'object_type' => 'option',
						'object_id'   => 'kuduy_test_admin_setting',
						'payload'     => array( 'value' => get_option( 'kuduy_test_admin_setting' ) ),
					);
				}
				public function restore( array $payload ): void {
					update_option( 'kuduy_test_admin_setting', $payload['value'] );
				}
			}
		);
	}

	// ---------------------------------------------------------------- helpers.

	/**
	 * Dispatch a REST request.
	 *
	 * @param string               $verb   HTTP method.
	 * @param string               $route  Route.
	 * @param array<string, mixed> $form   Form body params.
	 * @param array<mixed>|null    $json   JSON body.
	 */
	private function rest( string $verb, string $route, array $form = array(), ?array $json = null ): \WP_REST_Response {
		TokenAuth::reset(); // A new HTTP request to an OAuth endpoint carries no bearer state.
		$request = new \WP_REST_Request( $verb, $route );
		if ( null !== $json ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $json ) );
		} else {
			$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
			$request->set_body_params( $form );
		}
		$response = rest_ensure_response( rest_get_server()->dispatch( $request ) );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- replays the core hook.
		return apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
	}

	/**
	 * Register a DCR client; returns its client_id.
	 *
	 * @param list<string> $redirects Redirect URIs.
	 */
	private function register_client( array $redirects = array( self::CLAUDE_REDIRECT ) ): string {
		$response = $this->rest(
			'POST',
			'/kuduy-mcp/v1/oauth/register',
			array(),
			array(
				'client_name'   => 'Claude',
				'redirect_uris' => $redirects,
			)
		);
		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		return $response->get_data()['client_id'];
	}

	/**
	 * PKCE pair.
	 *
	 * @return array{0:string, 1:string} Verifier, S256 challenge.
	 */
	private static function pkce(): array {
		$verifier = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return array( $verifier, rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Authorization request params.
	 *
	 * @param string               $client    Client id.
	 * @param string               $challenge PKCE challenge.
	 * @param array<string, mixed> $over      Overrides (null removes).
	 * @return array<string, mixed>
	 */
	private static function query( string $client, string $challenge, array $over = array() ): array {
		$q = array_merge(
			array(
				'response_type'         => 'code',
				'client_id'             => $client,
				'redirect_uri'          => self::CLAUDE_REDIRECT,
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
				'state'                 => 'st-123',
				'scope'                 => 'kuduy:read kuduy:write',
				'resource'              => Urls::resource(),
			),
			$over
		);
		return array_filter( $q, static fn( $v ) => null !== $v );
	}

	/**
	 * Approve the consent screen.
	 *
	 * @param array<string, mixed> $q      Authorization params.
	 * @param list<string>|null    $scopes Ticked scopes (null = the requested ones, ticked as the form is rendered).
	 * @param string               $choice approve|deny.
	 * @return array<string, mixed> Handler result.
	 */
	private static function consent( array $q, ?array $scopes = null, string $choice = 'approve' ): array {
		$requested = Scopes::parse( is_string( $q['scope'] ?? null ) ? $q['scope'] : '' );
		$post      = array_merge(
			$q,
			array(
				'decision'     => $choice,
				'_kuduy_nonce' => wp_create_nonce( 'kuduy-mcp-authorize' ),
				'scopes'       => $scopes ?? ( $requested ? $requested : Scopes::DEFAULT ),
			)
		);
		if ( array() === $post['scopes'] ) {
			unset( $post['scopes'] ); // What a browser sends when every box is unticked.
		}
		return Authorize::handle( 'POST', $q, $post );
	}

	/**
	 * Query parameters of a redirect URL.
	 *
	 * @param string $url URL.
	 * @return array<string, string>
	 */
	private static function params( string $url ): array {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $out );
		return $out;
	}

	/**
	 * Full code flow; returns token response data plus the verifier-free extras.
	 *
	 * @param string            $client   Client id.
	 * @param list<string>|null $scopes   Ticked scopes.
	 * @param array<string, mixed> $over  Query overrides.
	 * @return array<string, mixed>
	 */
	private function tokens( string $client, ?array $scopes = null, array $over = array() ): array {
		[ $verifier, $challenge ] = self::pkce();
		$q                        = self::query( $client, $challenge, $over );
		$result                   = self::consent( $q, $scopes );
		$this->assertSame( 'redirect', $result['type'], (string) wp_json_encode( $result ) );
		$code     = self::params( $result['url'] )['code'];
		$response = $this->rest(
			'POST',
			'/kuduy-mcp/v1/oauth/token',
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => $q['redirect_uri'],
				'client_id'     => $client,
				'code_verifier' => $verifier,
				'resource'      => Urls::resource(),
			)
		);
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		return $response->get_data() + array( '_code' => $code );
	}

	/**
	 * Act as an HTTP request carrying a bearer token.
	 *
	 * @param string|null $token Token (null = none).
	 * @param string      $uri   Request URI.
	 */
	private static function as_bearer( ?string $token, string $uri = '/wp-json/kuduy-mcp/v1/mcp' ): int {
		$_SERVER['REQUEST_URI'] = $uri;
		if ( null === $token ) {
			unset( $_SERVER['HTTP_AUTHORIZATION'] );
		} else {
			$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		}
		TokenAuth::reset();
		wp_set_current_user( 0 );
		$GLOBALS['current_user'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- forces determine_current_user to run again, as on a new request.
		return wp_get_current_user()->ID;
	}

	/**
	 * Modern MCP tools/call or tools/list.
	 *
	 * @param string      $method Method.
	 * @param string|null $tool   Tool name for tools/call.
	 */
	private function mcp( string $method, ?string $tool = null ): \WP_REST_Response {
		$params = array(
			'_meta' => array(
				'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
				'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
			),
		);
		if ( null !== $tool ) {
			$params['name'] = $tool;
		}
		$request = new \WP_REST_Request( 'POST', '/kuduy-mcp/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'MCP-Protocol-Version', '2026-07-28' );
		$request->set_header( 'Mcp-Method', $method );
		if ( null !== $tool ) {
			$request->set_header( 'Mcp-Name', $tool );
		}
		$request->set_body(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => $method,
					'params'  => $params,
				)
			)
		);
		return rest_ensure_response( rest_get_server()->dispatch( $request ) );
	}

	// ---------------------------------------------------------------- discovery.

	public function test_unauthenticated_mcp_call_gets_resource_metadata_challenge(): void {
		self::as_bearer( null );

		$response = $this->mcp( 'tools/list' );

		$this->assertSame( 401, $response->get_status() );
		$challenge = $response->get_headers()['WWW-Authenticate'] ?? '';
		$this->assertStringContainsString( 'Bearer resource_metadata="' . Urls::issuer() . '/protected-resource"', $challenge );
		$this->assertStringContainsString( 'scope="kuduy:read kuduy:write"', $challenge );
	}

	public function test_protected_resource_metadata(): void {
		$data = $this->rest( 'GET', '/kuduy-mcp/v1/oauth/protected-resource' )->get_data();

		$this->assertSame( Urls::resource(), $data['resource'] );
		$this->assertSame( array( Urls::issuer() ), $data['authorization_servers'] );
		$expected = array( 'kuduy:read', 'kuduy:write', 'kuduy:admin' );
		if ( defined( 'KUDUY_MCP_ALLOW_CODE_EXEC' ) && true === constant( 'KUDUY_MCP_ALLOW_CODE_EXEC' ) ) {
			// D4: offered only while the constant is set.
			$expected[] = 'kuduy:code';
		}
		$this->assertSame( $expected, $data['scopes_supported'] );
		$this->assertSame( array( 'header' ), $data['bearer_methods_supported'] );
		$this->assertStringStartsWith( home_url(), Urls::resource() );
		$this->assertStringEndsWith( '/wp-json/kuduy-mcp/v1/mcp', Urls::resource() );
	}

	public function test_authorization_server_metadata_on_both_rest_paths(): void {
		$a = $this->rest( 'GET', '/kuduy-mcp/v1/oauth/.well-known/openid-configuration' );
		$b = $this->rest( 'GET', '/kuduy-mcp/v1/oauth/.well-known/oauth-authorization-server' );

		$this->assertSame( 200, $a->get_status() );
		$this->assertSame( $a->get_data(), $b->get_data() );
		$m = $a->get_data();
		$this->assertSame( Urls::issuer(), $m['issuer'] );
		$this->assertSame( array( 'S256' ), $m['code_challenge_methods_supported'] );
		$this->assertSame( array( 'none' ), $m['token_endpoint_auth_methods_supported'] );
		$this->assertTrue( $m['client_id_metadata_document_supported'] );
		$this->assertTrue( $m['authorization_response_iss_parameter_supported'] );
		$this->assertSame( array( 'code' ), $m['response_types_supported'] );
		$this->assertSame( array( 'authorization_code', 'refresh_token' ), $m['grant_types_supported'] );
		$this->assertContains( 'offline_access', $m['scopes_supported'] );
		$this->assertStringContainsString( 'action=kuduy-mcp-authorize', $m['authorization_endpoint'] );
		foreach ( array( 'token', 'register', 'revoke' ) as $ep ) {
			$this->assertSame( Urls::issuer() . '/' . $ep, $m[ 'token' === $ep ? 'token_endpoint' : ( 'register' === $ep ? 'registration_endpoint' : 'revocation_endpoint' ) ] );
		}
	}

	// ---------------------------------------------------------------- registration.

	public function test_dcr_registers_public_client_and_rejects_bad_redirects(): void {
		$ok = $this->rest(
			'POST',
			'/kuduy-mcp/v1/oauth/register',
			array(),
			array(
				'client_name'                => 'Claude',
				'redirect_uris'              => array( self::CLAUDE_REDIRECT, 'http://localhost/callback' ),
				'token_endpoint_auth_method' => 'client_secret_post',
			)
		);
		$this->assertSame( 201, $ok->get_status() );
		$this->assertSame( 'none', $ok->get_data()['token_endpoint_auth_method'] );
		$this->assertArrayNotHasKey( 'client_secret', $ok->get_data() );
		$this->assertSame( 'no-store', $ok->get_headers()['Cache-Control'] ?? '' );

		foreach ( array(
			array( 'http://evil.example/cb' ),
			array( 'https://claude.ai/cb#frag' ),
			array( 'not a url' ),
			array(),
		) as $bad ) {
			$r = $this->rest( 'POST', '/kuduy-mcp/v1/oauth/register', array(), array( 'redirect_uris' => $bad ) );
			$this->assertSame( 400, $r->get_status(), (string) wp_json_encode( $bad ) );
			$this->assertContains( $r->get_data()['error'], array( 'invalid_redirect_uri', 'invalid_client_metadata' ) );
		}
	}

	public function test_registration_is_capped_by_unused_clients_not_per_ip(): void {
		global $wpdb;
		$p = $wpdb->prefix;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test setup on custom tables.
		$wpdb->query( "DELETE FROM {$p}kuduy_oauth_clients WHERE type = 'dcr' AND client_id NOT IN ( SELECT client_id FROM {$p}kuduy_oauth_tokens ) AND client_id NOT IN ( SELECT client_id FROM {$p}kuduy_oauth_codes )" );
		$limit    = static fn() => 2;
		add_filter( 'kuduy_mcp_registration_limit', $limit );
		$register = fn() => $this->rest(
			'POST',
			'/kuduy-mcp/v1/oauth/register',
			array(),
			array( 'redirect_uris' => array( self::CLAUDE_REDIRECT ) )
		);

		$first = $register()->get_data()['client_id'];
		$this->assertSame( 201, $register()->get_status() );
		$this->assertSame( 429, $register()->get_status(), 'cap reached and nothing is old enough to evict' );

		$wpdb->update( "{$p}kuduy_oauth_clients", array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) ), array( 'client_id' => $first ) );
		$this->assertSame( 201, $register()->get_status(), 'the oldest unused client makes room' );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT client_id FROM {$p}kuduy_oauth_clients WHERE client_id = %s", $first ) ) );
		// phpcs:enable
		remove_filter( 'kuduy_mcp_registration_limit', $limit );
	}

	// ---------------------------------------------------------------- authorize.

	public function test_not_logged_in_is_sent_to_wp_login(): void {
		wp_set_current_user( 0 );
		[ , $challenge ] = self::pkce();

		$result = Authorize::handle( 'GET', self::query( $this->register_client(), $challenge ), array() );

		$this->assertSame( 'login', $result['type'] );
		$this->assertStringContainsString( 'wp-login.php', $result['url'] );
		$this->assertStringContainsString( 'kuduy-mcp-authorize', rawurldecode( $result['url'] ) );
	}

	public function test_logged_out_visitors_cannot_make_the_site_fetch_a_cimd_url(): void {
		wp_set_current_user( 0 );
		$fetched = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$fetched ) {
				++$fetched;
				return new \WP_Error( 'blocked', 'no network in tests' );
			}
		);
		[ , $challenge ] = self::pkce();

		$result = Authorize::handle( 'GET', self::query( 'https://attacker.example/client.json', $challenge ), array() );

		$this->assertSame( 'login', $result['type'] );
		$this->assertSame( 0, $fetched );
	}

	public function test_consent_page_shows_client_and_redirect_host(): void {
		[ , $challenge ] = self::pkce();

		$result = Authorize::handle( 'GET', self::query( $this->register_client(), $challenge ), array() );

		$this->assertSame( 'page', $result['type'] );
		$this->assertSame( 200, $result['status'] );
		$this->assertStringContainsString( 'Claude', $result['html'] );
		$this->assertStringContainsString( 'claude.ai', $result['html'] );
		$this->assertStringContainsString( '_kuduy_nonce', $result['html'] );
		$this->assertStringContainsString( 'value="kuduy:write"', $result['html'] );
	}

	public function test_bad_client_or_redirect_shows_error_page_and_never_redirects(): void {
		[ , $challenge ] = self::pkce();
		$client          = $this->register_client();

		foreach ( array(
			array( 'client_id' => 'unknown-client' ),
			array( 'redirect_uri' => 'https://evil.example/cb' ),
			array( 'redirect_uri' => 'https://claude.ai:8443/api/mcp/auth_callback' ),
			array( 'redirect_uri' => null ),
		) as $over ) {
			$result = Authorize::handle( 'GET', self::query( $client, $challenge, $over ), array() );
			$this->assertSame( 'page', $result['type'], (string) wp_json_encode( $over ) );
			$this->assertSame( 400, $result['status'] );
		}
	}

	public function test_loopback_redirect_is_port_agnostic(): void {
		[ , $challenge ] = self::pkce();
		$client          = $this->register_client( array( 'http://localhost/callback' ) );

		$result = Authorize::handle( 'GET', self::query( $client, $challenge, array( 'redirect_uri' => 'http://localhost:51234/callback' ) ), array() );

		$this->assertSame( 'page', $result['type'] );
		$this->assertSame( 200, $result['status'] );
		$this->assertStringContainsString( 'localhost', $result['html'] );
	}

	public function test_other_errors_redirect_with_state_and_iss(): void {
		[ , $challenge ] = self::pkce();
		$client          = $this->register_client();

		foreach ( array(
			array( array( 'code_challenge' => null ), 'invalid_request' ),
			array( array( 'code_challenge_method' => 'plain' ), 'invalid_request' ),
			array( array( 'response_type' => 'token' ), 'unsupported_response_type' ),
			array( array( 'resource' => 'https://other.example/mcp' ), 'invalid_target' ),
			array( array( 'scope' => 'nonsense' ), 'invalid_scope' ),
		) as [ $over, $error ] ) {
			$result = Authorize::handle( 'GET', self::query( $client, $challenge, $over ), array() );
			$this->assertSame( 'redirect', $result['type'], (string) wp_json_encode( $over ) );
			$p = self::params( $result['url'] );
			$this->assertStringStartsWith( self::CLAUDE_REDIRECT . '?', $result['url'] );
			$this->assertSame( $error, $p['error'], (string) wp_json_encode( $over ) );
			$this->assertSame( 'st-123', $p['state'] );
			$this->assertSame( Urls::issuer(), $p['iss'] );
		}

		$denied = self::consent( self::query( $client, $challenge ), null, 'deny' );
		$this->assertSame( 'access_denied', self::params( $denied['url'] )['error'] );
	}

	public function test_bad_nonce_is_rejected(): void {
		[ , $challenge ] = self::pkce();
		$q               = self::query( $this->register_client(), $challenge );

		$result = Authorize::handle(
			'POST',
			$q,
			$q + array(
				'decision'     => 'approve',
				'_kuduy_nonce' => 'forged',
			)
		);

		$this->assertSame( 'page', $result['type'] );
		$this->assertSame( 403, $result['status'] );
	}

	// ---------------------------------------------------------------- tokens.

	public function test_full_flow_issues_tokens_that_authenticate_mcp(): void {
		$client                   = $this->register_client();
		[ $verifier, $challenge ] = self::pkce();
		$q                        = self::query( $client, $challenge );
		$redir                    = self::consent( $q );
		$p                        = self::params( $redir['url'] );
		$this->assertSame( 'st-123', $p['state'] );
		$this->assertSame( Urls::issuer(), $p['iss'] );

		$response = $this->rest(
			'POST',
			'/kuduy-mcp/v1/oauth/token',
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $p['code'],
				'redirect_uri'  => self::CLAUDE_REDIRECT,
				'client_id'     => $client,
				'code_verifier' => $verifier,
				'resource'      => Urls::resource(),
			)
		);
		$t        = $response->get_data();
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $t ) );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] ?? '' );
		$this->assertSame( 'Bearer', $t['token_type'] );
		$this->assertSame( 3600, $t['expires_in'] );
		$this->assertSame( 'kuduy:read kuduy:write', $t['scope'] );
		$this->assertStringStartsWith( 'kmcp_at_', $t['access_token'] );
		$this->assertStringStartsWith( 'kmcp_rt_', $t['refresh_token'] );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stored = (string) $wpdb->get_var( "SELECT GROUP_CONCAT(token_hash) FROM {$wpdb->prefix}kuduy_oauth_tokens" );
		$this->assertStringNotContainsString( $t['access_token'], $stored, 'raw tokens must never be stored' );
		$this->assertStringContainsString( hash( 'sha256', $t['access_token'] ), $stored );

		$this->assertSame( self::$admin, self::as_bearer( $t['access_token'] ) );
		$call = $this->mcp( 'tools/call', 'kuduy-site-info' );
		$this->assertSame( 200, $call->get_status() );
		$this->assertFalse( $call->get_data()['result']['isError'] );
	}

	public function test_pkce_redirect_client_and_resource_are_enforced(): void {
		$client = $this->register_client();
		foreach ( array(
			array( 'code_verifier' => str_repeat( 'a', 43 ) ),
			array( 'redirect_uri' => 'http://localhost/callback' ),
			array( 'client_id' => $this->register_client() ),
			array( 'resource' => 'https://other.example/mcp' ),
		) as $over ) {
			[ $verifier, $challenge ] = self::pkce();
			$q                        = self::query( $client, $challenge );
			$code                     = self::params( self::consent( $q )['url'] )['code'];
			$r                        = $this->rest(
				'POST',
				'/kuduy-mcp/v1/oauth/token',
				array_merge(
					array(
						'grant_type'    => 'authorization_code',
						'code'          => $code,
						'redirect_uri'  => self::CLAUDE_REDIRECT,
						'client_id'     => $client,
						'code_verifier' => $verifier,
						'resource'      => Urls::resource(),
					),
					$over
				)
			);
			$this->assertSame( 400, $r->get_status(), (string) wp_json_encode( $over ) );
			$this->assertContains( $r->get_data()['error'], array( 'invalid_grant', 'invalid_target' ), (string) wp_json_encode( $over ) );
		}
	}

	public function test_reused_code_fails_and_revokes_its_tokens(): void {
		$client                   = $this->register_client();
		[ $verifier, $challenge ] = self::pkce();
		$code                     = self::params( self::consent( self::query( $client, $challenge ) )['url'] )['code'];
		$form                     = array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'redirect_uri'  => self::CLAUDE_REDIRECT,
			'client_id'     => $client,
			'code_verifier' => $verifier,
			'resource'      => Urls::resource(),
		);
		$first                    = $this->rest( 'POST', '/kuduy-mcp/v1/oauth/token', $form )->get_data();

		$again = $this->rest( 'POST', '/kuduy-mcp/v1/oauth/token', $form );

		$this->assertSame( 400, $again->get_status() );
		$this->assertSame( 'invalid_grant', $again->get_data()['error'] );
		$this->assertSame( 0, self::as_bearer( $first['access_token'] ) );
	}

	public function test_refresh_rotates_and_reuse_revokes_the_family(): void {
		$client = $this->register_client();
		$t1     = $this->tokens( $client );
		$form   = static fn( string $rt ) => array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $rt,
			'client_id'     => $client,
		);

		$r2 = $this->rest( 'POST', '/kuduy-mcp/v1/oauth/token', $form( $t1['refresh_token'] ) );
		$t2 = $r2->get_data();
		$this->assertSame( 200, $r2->get_status(), (string) wp_json_encode( $t2 ) );
		$this->assertNotSame( $t1['refresh_token'], $t2['refresh_token'] );
		$this->assertSame( self::$admin, self::as_bearer( $t2['access_token'] ) );

		$reuse = $this->rest( 'POST', '/kuduy-mcp/v1/oauth/token', $form( $t1['refresh_token'] ) );
		$this->assertSame( 400, $reuse->get_status() );
		$this->assertSame( 'invalid_grant', $reuse->get_data()['error'] );

		$this->assertSame( 'invalid_grant', $this->rest( 'POST', '/kuduy-mcp/v1/oauth/token', $form( $t2['refresh_token'] ) )->get_data()['error'], 'family must be revoked' );
		$this->assertSame( 0, self::as_bearer( $t2['access_token'] ) );
	}

	public function test_bad_expired_or_foreign_tokens_get_invalid_token(): void {
		global $wpdb;
		$t = $this->tokens( $this->register_client() );

		foreach ( array( 'kmcp_at_forged', 'not-ours' ) as $bad ) {
			$this->assertSame( 0, self::as_bearer( $bad ) );
			$response = $this->mcp( 'tools/list' );
			$this->assertSame( 401, $response->get_status() );
			$this->assertStringContainsString( 'error="invalid_token"', $response->get_headers()['WWW-Authenticate'] ?? '' );
		}

		$hash = hash( 'sha256', $t['access_token'] );
		$wpdb->update( $wpdb->prefix . 'kuduy_oauth_tokens', array( 'resource' => 'https://other.example/mcp' ), array( 'token_hash' => $hash ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 0, self::as_bearer( $t['access_token'] ), 'token for another resource' );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'kuduy_oauth_tokens',
			array(
				'resource'   => Urls::resource(),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 10 ),
			),
			array( 'token_hash' => $hash )
		);
		$this->assertSame( 0, self::as_bearer( $t['access_token'] ), 'expired token' );
	}

	public function test_bearer_is_ignored_outside_the_mcp_route(): void {
		$t = $this->tokens( $this->register_client() );

		$this->assertSame( 0, self::as_bearer( $t['access_token'], '/wp-json/wp/v2/users/me' ) );
		$this->assertSame( 0, self::as_bearer( $t['access_token'], '/wp-admin/admin-ajax.php/wp-json/kuduy-mcp/v1/mcp' ), 'PATH_INFO tail is not the MCP path' );
		$_GET['rest_route'] = '/kuduy-mcp/v1/mcp';
		$this->assertSame( 0, self::as_bearer( $t['access_token'], '/wp-admin/admin-ajax.php?rest_route=/kuduy-mcp/v1/mcp' ), 'rest_route only counts on the site root' );
	}

	public function test_mcp_path_with_another_rest_route_does_not_authenticate(): void {
		$t = $this->tokens( $this->register_client(), array( 'kuduy:read' ) );

		foreach ( array( '/wp/v2/users/me', '', '/kuduy-mcp/v1/oauth/token' ) as $route ) {
			$_GET['rest_route'] = $route;
			$this->assertSame( 0, self::as_bearer( $t['access_token'] ), 'rest_route=' . $route );
		}
		unset( $_GET['rest_route'] );
		$_POST['rest_route'] = '/wp/v2/posts';
		$this->assertSame( 0, self::as_bearer( $t['access_token'] ), 'rest_route in the body' );
		unset( $_POST['rest_route'] );

		$_GET['rest_route'] = '/kuduy-mcp/v1/mcp';
		$this->assertSame( self::$admin, self::as_bearer( $t['access_token'], '/?rest_route=/kuduy-mcp/v1/mcp' ), 'plain permalinks still work' );
		unset( $_GET['rest_route'] );
		$this->assertSame( self::$admin, self::as_bearer( $t['access_token'] ) );
	}

	public function test_token_is_refused_on_every_other_rest_route(): void {
		$t = $this->tokens( $this->register_client(), array( 'kuduy:read' ) );
		self::as_bearer( $t['access_token'] );

		$response = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/wp/v2/users/me' ) );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'kuduy_mcp_token_route', $response->get_data()['code'] ?? null );

		self::as_bearer( $t['access_token'] );
		$this->assertSame( 200, $this->mcp( 'tools/call', 'kuduy-site-info' )->get_status() );
		$internal = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/users/me' ) );
		$this->assertSame( 200, $internal->get_status(), 'a tool may dispatch core routes internally during an MCP request' );
	}

	public function test_scopes_follow_user_capabilities_and_are_enforced(): void {
		wp_set_current_user( self::$subscriber );
		$t = $this->tokens( $this->register_client(), null, array( 'scope' => 'kuduy:read kuduy:write kuduy:admin' ) );
		$this->assertSame( 'kuduy:read', $t['scope'], 'subscriber cannot be granted write/admin' );

		self::as_bearer( $t['access_token'] );
		$names = array_column( $this->mcp( 'tools/list' )->get_data()['result']['tools'], 'name' );
		$this->assertContains( 'kuduy-site-info', $names );
		$this->assertNotContains( 'test-write', $names );

		$denied = $this->mcp( 'tools/call', 'test-write' );
		$this->assertSame( 403, $denied->get_status() );
		$header = $denied->get_headers()['WWW-Authenticate'] ?? '';
		$this->assertStringContainsString( 'error="insufficient_scope"', $header );
		$this->assertStringContainsString( 'scope="kuduy:write"', $header );
		$this->assertStringContainsString( 'resource_metadata="', $header );
	}

	public function test_user_can_untick_scopes_and_write_implies_read(): void {
		$t = $this->tokens( $this->register_client(), array( 'kuduy:write' ) );
		$this->assertSame( 'kuduy:write', $t['scope'] );

		self::as_bearer( $t['access_token'] );
		$this->assertSame( 200, $this->mcp( 'tools/call', 'kuduy-site-info' )->get_status(), 'kuduy:write implies kuduy:read' );
		$this->assertSame( 200, $this->mcp( 'tools/call', 'test-write' )->get_status() );
	}

	public function test_admins_may_opt_in_to_admin_scope_the_client_did_not_request(): void {
		[ , $challenge ] = self::pkce();
		$client          = $this->register_client();

		$page = Authorize::handle( 'GET', self::query( $client, $challenge ), array() );
		$this->assertMatchesRegularExpression( '/value="kuduy:write" checked/', $page['html'] );
		$this->assertMatchesRegularExpression( '/value="kuduy:admin">/', $page['html'], 'admin offered, unticked' );

		$t = $this->tokens( $client );
		$this->assertSame( 'kuduy:read kuduy:write', $t['scope'], 'left as rendered: no admin' );

		$t = $this->tokens( $client, array( 'kuduy:read', 'kuduy:write', 'kuduy:admin' ) );
		$this->assertSame( 'kuduy:read kuduy:write kuduy:admin', $t['scope'], 'ticked: admin granted' );
		$this->assertStringNotContainsString( 'kuduy:code', $t['scope'], 'code is never offered unasked' );

		$editor = self::user( 'editor' );
		wp_set_current_user( $editor );
		$page = Authorize::handle( 'GET', self::query( $client, $challenge ), array() );
		$this->assertStringNotContainsString( 'kuduy:admin', $page['html'], 'not offered without manage_options' );
		$t = $this->tokens( $client, array( 'kuduy:read', 'kuduy:write', 'kuduy:admin' ) );
		$this->assertSame( 'kuduy:read kuduy:write', $t['scope'], 'a forged admin box is ignored' );
	}

	public function test_oauth_and_tokens_require_https_outside_local_sites(): void {
		$client = $this->register_client();
		$t      = $this->tokens( $client );
		$force  = static fn() => true;
		add_filter( 'kuduy_mcp_require_https', $force );

		$token = $this->rest(
			'POST',
			'/kuduy-mcp/v1/oauth/token',
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $t['refresh_token'],
				'client_id'     => $client,
			)
		);
		$this->assertSame( 400, $token->get_status() );
		$this->assertSame( 'invalid_request', $token->get_data()['error'] );
		$this->assertSame( 400, $this->rest( 'POST', '/kuduy-mcp/v1/oauth/register', array(), array( 'redirect_uris' => array( self::CLAUDE_REDIRECT ) ) )->get_status() );
		[ , $challenge ] = self::pkce();
		$page            = Authorize::handle( 'GET', self::query( $client, $challenge ), array() );
		$this->assertSame( 'page', $page['type'] );
		$this->assertSame( 400, $page['status'] );

		$this->assertSame( 0, self::as_bearer( $t['access_token'] ), 'bearer over plain HTTP is refused' );
		$this->assertSame( 'invalid_request', TokenAuth::error() );
		$this->assertStringContainsString( 'HTTPS', $this->mcp( 'tools/list' )->get_data()['error']['message'] ?? '' );

		remove_filter( 'kuduy_mcp_require_https', $force );
		$this->assertSame( self::$admin, self::as_bearer( $t['access_token'] ), 'local site: plain HTTP allowed' );
	}

	public function test_token_works_before_wordpress_creates_wp_rewrite(): void {
		$t        = $this->tokens( $this->register_client() );
		$expected = Urls::resource();
		$rewrite  = $GLOBALS['wp_rewrite'];
		unset( $GLOBALS['wp_rewrite'] ); // As during plugins_loaded, when other plugins often check the current user.
		try {
			$early = Urls::resource();
			$user  = self::as_bearer( $t['access_token'] );
		} finally {
			$GLOBALS['wp_rewrite'] = $rewrite; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restores the global the test removed.
		}
		$this->assertSame( $expected, $early );
		$this->assertSame( self::$admin, $user );
	}

	public function test_write_token_cannot_undo_an_admin_tool_change(): void {
		update_option( 'kuduy_test_admin_setting', 'before' );
		$admin = $this->tokens( $this->register_client(), null, array( 'scope' => 'kuduy:admin' ) );
		$write = $this->tokens( $this->register_client(), array( 'kuduy:write' ) );

		self::as_bearer( $admin['access_token'] );
		$this->assertFalse( $this->mcp_call( 'test-admin-setting', array( 'value' => 'after' ) )['isError'] );
		$snapshot_id = (int) AuditLog::latest( 1 )[0]['snapshot_id'];
		$this->assertGreaterThan( 0, $snapshot_id );

		self::as_bearer( $write['access_token'] );
		$denied = $this->mcp_call( 'kuduy-snapshot-restore', array( 'snapshot_id' => $snapshot_id ) );
		$this->assertTrue( $denied['isError'], (string) wp_json_encode( $denied ) );
		$this->assertSame( 'after', get_option( 'kuduy_test_admin_setting' ) );

		self::as_bearer( $admin['access_token'] );
		$this->assertFalse( $this->mcp_call( 'kuduy-snapshot-restore', array( 'snapshot_id' => $snapshot_id ) )['isError'], 'still restorable with the right scope' );
		$this->assertSame( 'before', get_option( 'kuduy_test_admin_setting' ) );
	}

	/**
	 * Modern tools/call with arguments; returns the CallToolResult.
	 *
	 * @param string               $tool Tool.
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private function mcp_call( string $tool, array $args ): array {
		$request = new \WP_REST_Request( 'POST', '/kuduy-mcp/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'MCP-Protocol-Version', '2026-07-28' );
		$request->set_header( 'Mcp-Method', 'tools/call' );
		$request->set_header( 'Mcp-Name', $tool );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/call',
					'params'  => array(
						'name'      => $tool,
						'arguments' => $args,
						'_meta'     => array( 'io.modelcontextprotocol/protocolVersion' => '2026-07-28' ),
					),
				)
			)
		);
		$data = rest_ensure_response( rest_get_server()->dispatch( $request ) )->get_data();
		return $data['result'] ?? array(
			'isError' => true,
			'error'   => $data['error'] ?? null,
		);
	}

	public function test_approving_with_every_box_unticked_grants_nothing(): void {
		[ , $challenge ] = self::pkce();
		$q               = self::query( $this->register_client(), $challenge, array( 'scope' => 'kuduy:read kuduy:write kuduy:admin' ) );

		$result = self::consent( $q, array() );

		$this->assertSame( 'redirect', $result['type'] );
		$p = self::params( $result['url'] );
		$this->assertSame( 'access_denied', $p['error'] ?? null );
		$this->assertArrayNotHasKey( 'code', $p );
	}

	public function test_revoke_always_200_and_kills_the_token(): void {
		$client = $this->register_client();
		$t      = $this->tokens( $client );

		$r = $this->rest(
			'POST',
			'/kuduy-mcp/v1/oauth/revoke',
			array(
				'token'     => $t['access_token'],
				'client_id' => $client,
			)
		);
		$this->assertSame( 200, $r->get_status() );
		$this->assertSame( 0, self::as_bearer( $t['access_token'] ) );

		$unknown = $this->rest(
			'POST',
			'/kuduy-mcp/v1/oauth/revoke',
			array(
				'token'     => 'kmcp_rt_unknown',
				'client_id' => $client,
			)
		);
		$this->assertSame( 200, $unknown->get_status() );

		$this->rest(
			'POST',
			'/kuduy-mcp/v1/oauth/revoke',
			array(
				'token'     => $t['refresh_token'],
				'client_id' => $client,
			)
		);
		$refresh = $this->rest(
			'POST',
			'/kuduy-mcp/v1/oauth/token',
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $t['refresh_token'],
				'client_id'     => $client,
			)
		);
		$this->assertSame( 'invalid_grant', $refresh->get_data()['error'] );
	}

	// ---------------------------------------------------------------- CIMD.

	public function test_cimd_client_is_fetched_safely_and_validated(): void {
		$url  = 'https://client.example/oauth/meta.json';
		$seen = array();
		$doc  = array(
			'client_id'                  => $url,
			'client_name'                => 'Claude Code',
			'redirect_uris'              => array( 'http://localhost/callback', 'http://127.0.0.1/callback' ),
			'token_endpoint_auth_method' => 'none',
		);
		add_filter(
			'pre_http_request',
			static function ( $pre, array $args, string $requested ) use ( &$seen, &$doc ) {
				$seen[] = array( $requested, $args );
				return array(
					'headers'  => array( 'cache-control' => 'max-age=600' ),
					'body'     => (string) wp_json_encode( $doc ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
		[ , $challenge ] = self::pkce();
		$q               = self::query( $url, $challenge, array( 'redirect_uri' => 'http://127.0.0.1:40111/callback' ) );

		$result = Authorize::handle( 'GET', $q, array() );

		$this->assertSame( 200, $result['status'] ?? 0, (string) wp_json_encode( $result ) );
		$this->assertStringContainsString( 'Claude Code', $result['html'] );
		$this->assertStringContainsString( 'client.example', $result['html'] );
		$this->assertSame( $url, $seen[0][0] );
		$this->assertTrue( $seen[0][1]['reject_unsafe_urls'] );
		$this->assertSame( 0, $seen[0][1]['redirection'] );
		$this->assertLessThanOrEqual( 65536, $seen[0][1]['limit_response_size'] );

		$count = count( $seen );
		Authorize::handle( 'GET', $q, array() );
		$this->assertCount( $count, $seen, 'second request served from cache' );

		$doc['client_id'] = 'https://client.example/other.json';
		foreach ( array( 'https://client.example/oauth/mismatch.json', 'https://client.example', 'http://client.example/meta.json' ) as $bad ) {
			$r = Authorize::handle( 'GET', self::query( $bad, $challenge, array( 'redirect_uri' => 'http://127.0.0.1/callback' ) ), array() );
			$this->assertSame( 400, $r['status'] ?? 0, $bad );
		}
	}
}
