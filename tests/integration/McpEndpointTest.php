<?php
/**
 * MCP endpoint: dual-era wire rules, tools/list, tools/call (test matrix F3–F8).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Mcp\ToolRegistry;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

final class McpEndpointTest extends TestCase {

	private const MODERN = '2026-07-28';

	private static int $admin;

	private static int $subscriber;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin      = self::user( 'administrator' );
		self::$subscriber = self::user( 'subscriber' );
		add_action( 'kuduy_mcp_register_tools', array( self::class, 'register_test_tools' ) );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	private static function user( string $role ): int {
		$id = wp_insert_user(
			array(
				'user_login' => 'kuduy_' . $role . '_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => $role,
			)
		);
		return is_wp_error( $id ) ? 0 : $id;
	}

	public static function register_test_tools( ToolRegistry $registry ): void {
		$registry->register( self::tool( 'test-admin-only', 'manage_options', static fn() => array( 'ok' => true ) ) );
		$registry->register(
			self::tool(
				'test-echo',
				'read',
				static fn( array $in ) => array( 'echo' => $in['text'] ),
				array(
					'type'                 => 'object',
					'properties'           => array( 'text' => array( 'type' => 'string' ) ),
					'required'             => array( 'text' ),
					'additionalProperties' => false,
				)
			)
		);
		$registry->register(
			self::tool(
				'test-throws',
				'read',
				static function (): array {
					throw new \RuntimeException( 'secret internal detail' );
				}
			)
		);
		$registry->register(
			self::tool(
				'test-tool-error',
				'read',
				static function (): array {
					throw new ToolError( 'Post 42 not found.' );
				}
			)
		);
		$registry->register( self::tool( 'test-unavailable', 'read', static fn() => array(), null, 'Elementor not active' ) );
	}

	/**
	 * Builds a test tool.
	 *
	 * @param string                    $name      Tool name.
	 * @param string                    $cap       Capability.
	 * @param callable                  $run       Body.
	 * @param array<string, mixed>|null $schema    Input schema.
	 * @param string|null               $unusable  Unavailability reason.
	 */
	private static function tool( string $name, string $cap, callable $run, ?array $schema = null, ?string $unusable = null ): Tool {
		return new class( $name, $cap, $run, $schema, $unusable ) implements Tool {
			/**
			 * Constructor.
			 *
			 * @param string                    $n Name.
			 * @param string                    $c Capability.
			 * @param callable                  $r Body.
			 * @param array<string, mixed>|null $s Schema.
			 * @param string|null               $u Unavailable reason.
			 */
			public function __construct( private string $n, private string $c, private $r, private ?array $s, private ?string $u ) {}
			public function name(): string {
				return $this->n;
			}
			public function title(): string {
				return $this->n;
			}
			public function description(): string {
				return 'test';
			}
			public function input_schema(): array {
				return $this->s ?? array( 'type' => 'object' );
			}
			public function annotations(): array {
				return array( 'readOnlyHint' => true );
			}
			public function scope(): string {
				return 'kuduy:read';
			}
			public function capability( array $input ): string {
				return $this->c;
			}
			public function is_available(): bool|string {
				return $this->u ?? true;
			}
			public function execute( array $input ): array {
				return ( $this->r )( $input );
			}
		};
	}

	/**
	 * Modern request with correct headers unless overridden.
	 *
	 * @param string               $method  JSON-RPC method.
	 * @param array<string, mixed> $params  Params without _meta.
	 * @param array<string, mixed> $headers Header overrides (null removes).
	 * @param string               $version Protocol version in _meta.
	 * @return array{0:int, 1:array<string, mixed>|null, 2:string}
	 */
	private function modern( string $method, array $params = array(), array $headers = array(), string $version = self::MODERN ): array {
		$params['_meta'] = array(
			'io.modelcontextprotocol/protocolVersion'    => $version,
			'io.modelcontextprotocol/clientInfo'         => array(
				'name'    => 'phpunit',
				'version' => '1',
			),
			'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
		);
		$defaults        = array(
			'MCP-Protocol-Version' => $version,
			'Mcp-Method'           => $method,
			'Mcp-Name'             => $params['name'] ?? null,
		);
		return $this->post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 7,
				'method'  => $method,
				'params'  => $params,
			),
			array_merge( $defaults, $headers )
		);
	}

	/**
	 * POST to the endpoint through the REST server.
	 *
	 * @param array<mixed>|string       $body    Body.
	 * @param array<string, mixed>      $headers Headers (null values skipped).
	 * @param string                    $verb    HTTP method.
	 * @return array{0:int, 1:array<string, mixed>|null, 2:string} Status, decoded body, raw JSON.
	 */
	private function post( $body, array $headers = array(), string $verb = 'POST' ): array {
		$request = new \WP_REST_Request( $verb, '/kuduy-mcp/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		foreach ( $headers as $name => $value ) {
			if ( null !== $value ) {
				$request->set_header( $name, (string) $value );
			}
		}
		$request->set_body( is_string( $body ) ? $body : (string) wp_json_encode( $body ) );
		$response = rest_get_server()->dispatch( $request );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- replays the core hook WP_REST_Server::serve_request() fires.
		$response = apply_filters( 'rest_post_dispatch', rest_ensure_response( $response ), rest_get_server(), $request );
		$raw      = (string) wp_json_encode( $response->get_data() );
		return array( $response->get_status(), json_decode( $raw, true ), $raw );
	}

	public function test_discover_lists_both_eras_and_modern_result_fields(): void {
		[ $status, $body, $raw ] = $this->modern( 'server/discover' );

		$this->assertSame( 200, $status, $raw );
		$this->assertSame( 7, $body['id'] );
		$this->assertSame( array( '2026-07-28', '2025-11-25' ), $body['result']['supportedVersions'] );
		$this->assertSame( 'complete', $body['result']['resultType'] );
		$this->assertSame( 0, $body['result']['ttlMs'] );
		$this->assertSame( 'private', $body['result']['cacheScope'] );
		$this->assertSame( 'kuduy-mcp', $body['result']['_meta']['io.modelcontextprotocol/serverInfo']['name'] );
		$this->assertStringContainsString( '"tools":{"listChanged":false}', $raw );
	}

	public function test_missing_or_mismatched_headers_are_header_mismatch(): void {
		foreach ( array(
			array( 'MCP-Protocol-Version' => null ),
			array( 'MCP-Protocol-Version' => '2025-11-25' ),
			array( 'Mcp-Method' => null ),
			array( 'Mcp-Method' => 'tools/list' ),
		) as $override ) {
			[ $status, $body ] = $this->modern( 'server/discover', array(), $override );
			$this->assertSame( 400, $status, wp_json_encode( $override ) );
			$this->assertSame( -32020, $body['error']['code'], wp_json_encode( $override ) );
		}
	}

	public function test_mcp_name_is_required_for_tools_call_and_base64_is_decoded(): void {
		[ $status, $body ] = $this->modern( 'tools/call', array( 'name' => 'kuduy-site-info' ), array( 'Mcp-Name' => null ) );
		$this->assertSame( 400, $status );
		$this->assertSame( -32020, $body['error']['code'] );

		[ $status, $body ] = $this->modern( 'tools/call', array( 'name' => 'kuduy-site-info' ), array( 'Mcp-Name' => 'other-tool' ) );
		$this->assertSame( -32020, $body['error']['code'] );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- spec's header encoding.
		[ $status, $body ] = $this->modern( 'tools/call', array( 'name' => 'kuduy-site-info' ), array( 'Mcp-Name' => '=?base64?' . base64_encode( 'kuduy-site-info' ) . '?=' ) );
		$this->assertSame( 200, $status );
		$this->assertFalse( $body['result']['isError'] );
	}

	public function test_unsupported_modern_version_lists_supported(): void {
		[ $status, $body ] = $this->modern( 'server/discover', array(), array(), '1900-01-01' );

		$this->assertSame( 400, $status );
		$this->assertSame( -32022, $body['error']['code'] );
		$this->assertSame( array( '2026-07-28', '2025-11-25' ), $body['error']['data']['supported'] );
		$this->assertSame( '1900-01-01', $body['error']['data']['requested'] );
	}

	public function test_unknown_modern_method_is_404_method_not_found(): void {
		[ $status, $body ] = $this->modern( 'resources/list' );

		$this->assertSame( 404, $status );
		$this->assertSame( -32601, $body['error']['code'] );
	}

	public function test_legacy_initialize_negotiates_without_session(): void {
		$init = static fn( string $v ) => array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => array(
				'protocolVersion' => $v,
				'capabilities'    => new \stdClass(),
				'clientInfo'      => array(
					'name'    => 'legacy',
					'version' => '1',
				),
			),
		);

		[ $status, $body, $raw ] = $this->post( $init( '2025-11-25' ) );
		$this->assertSame( 200, $status, $raw );
		$this->assertSame( '2025-11-25', $body['result']['protocolVersion'] );
		$this->assertSame( 'kuduy-mcp', $body['result']['serverInfo']['name'] );
		$this->assertStringContainsString( '"tools":{"listChanged":false}', $raw );

		[ , $body ] = $this->post( $init( '2025-06-18' ) );
		$this->assertSame( '2025-06-18', $body['result']['protocolVersion'] );

		[ , $body ] = $this->post( $init( '2099-01-01' ) );
		$this->assertSame( '2025-11-25', $body['result']['protocolVersion'] );
	}

	public function test_legacy_ping_tools_list_and_notifications(): void {
		$headers = array( 'MCP-Protocol-Version' => '2025-11-25' );

		[ $status, $body ] = $this->post(
			array(
				'jsonrpc' => '2.0',
				'method'  => 'notifications/initialized',
			),
			$headers
		);
		$this->assertSame( 202, $status );
		$this->assertNull( $body );

		[ $status, $body, $raw ] = $this->post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'ping',
			),
			$headers
		);
		$this->assertSame( 200, $status );
		$this->assertStringContainsString( '"result":{}', $raw );

		[ , $body ] = $this->post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 3,
				'method'  => 'tools/list',
			),
			$headers
		);
		$this->assertContains( 'kuduy-site-info', array_column( $body['result']['tools'], 'name' ) );
		$this->assertArrayNotHasKey( 'resultType', $body['result'] );
	}

	public function test_get_and_delete_are_405(): void {
		foreach ( array( 'GET', 'DELETE' ) as $verb ) {
			[ $status ] = $this->post( '', array(), $verb );
			$this->assertSame( 405, $status, $verb );
		}
	}

	public function test_foreign_origin_is_403_and_claude_origin_allowed(): void {
		[ $status ] = $this->modern( 'server/discover', array(), array( 'Origin' => 'https://evil.example' ) );
		$this->assertSame( 403, $status );

		[ $status ] = $this->modern( 'server/discover', array(), array( 'Origin' => 'https://claude.ai' ) );
		$this->assertSame( 200, $status );
	}

	public function test_bad_json_and_batches_are_rejected(): void {
		[ $status, $body ] = $this->post( '{"jsonrpc":' );
		$this->assertSame( 400, $status );
		$this->assertSame( -32700, $body['error']['code'] );

		[ $status, $body ] = $this->post( array( array( 'jsonrpc' => '2.0' ) ) );
		$this->assertSame( 400, $status );
		$this->assertSame( -32600, $body['error']['code'] );
	}

	public function test_tools_require_login(): void {
		wp_set_current_user( 0 );

		[ $status, $body ] = $this->modern( 'tools/list' );

		$this->assertSame( 401, $status );
		$this->assertArrayHasKey( 'error', $body );
	}

	public function test_tools_list_filters_by_capability_and_availability(): void {
		[ $status, $body, $raw ] = $this->modern( 'tools/list' );
		$this->assertSame( 200, $status, $raw );
		$names = array_column( $body['result']['tools'], 'name' );
		$this->assertContains( 'kuduy-site-info', $names );
		$this->assertContains( 'test-admin-only', $names );
		$this->assertNotContains( 'test-unavailable', $names );
		$this->assertSame( 'private', $body['result']['cacheScope'] );
		$this->assertStringContainsString( '"properties":{}', $raw, 'empty properties must encode as a JSON object' );

		$site = $body['result']['tools'][ array_search( 'kuduy-site-info', $names, true ) ];
		$this->assertSame( 'object', $site['inputSchema']['type'] );
		$this->assertTrue( $site['annotations']['readOnlyHint'] );

		wp_set_current_user( self::$subscriber );
		[ , $body ] = $this->modern( 'tools/list' );
		$this->assertNotContains( 'test-admin-only', array_column( $body['result']['tools'], 'name' ) );
	}

	public function test_site_info_returns_structured_and_text_content(): void {
		[ $status, $body, $raw ] = $this->modern( 'tools/call', array( 'name' => 'kuduy-site-info' ) );

		$this->assertSame( 200, $status, $raw );
		$result = $body['result'];
		$this->assertFalse( $result['isError'] );
		$this->assertSame( get_bloginfo( 'name' ), $result['structuredContent']['name'] );
		$this->assertSame( get_bloginfo( 'version' ), $result['structuredContent']['wordpress_version'] );
		$this->assertSame( self::$admin, $result['structuredContent']['user']['id'] );
		$this->assertSame( 'text', $result['content'][0]['type'] );
		$this->assertSame( $result['structuredContent'], json_decode( $result['content'][0]['text'], true ) );
	}

	public function test_call_errors_are_tool_errors_without_internals(): void {
		$call = fn( string $name, array $args = array() ) => $this->modern(
			'tools/call',
			array(
				'name'      => $name,
				'arguments' => $args,
			)
		)[1]['result'];

		$this->assertTrue( $call( 'test-echo' )['isError'], 'missing required arg' );
		$this->assertSame( 'hi', $call( 'test-echo', array( 'text' => 'hi' ) )['structuredContent']['echo'] );

		$thrown = $call( 'test-throws' );
		$this->assertTrue( $thrown['isError'] );
		$this->assertStringNotContainsString( 'secret internal detail', (string) wp_json_encode( $thrown ) );

		$this->assertSame( 'Post 42 not found.', $call( 'test-tool-error' )['content'][0]['text'] );

		wp_set_current_user( self::$subscriber );
		$this->assertTrue( $call( 'test-admin-only' )['isError'] );
	}

	public function test_unknown_tool_is_invalid_params(): void {
		[ , $body ] = $this->modern( 'tools/call', array( 'name' => 'no-such-tool' ) );

		$this->assertSame( -32602, $body['error']['code'] );
	}
}
