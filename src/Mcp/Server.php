<?php
/**
 * JSON-RPC routing for MCP 2026-07-28 (stateless) and 2025-11-25 (legacy initialize).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Mcp;

use Kuduy\Mcp\Plugin;

/**
 * Transport-independent: takes the decoded body plus the mirrored headers, returns status and body.
 */
final class Server {

	public const MODERN = '2026-07-28';

	public const LEGACY = array( '2025-11-25', '2025-06-18', '2025-03-26' );

	public const SUPPORTED = array( self::MODERN, '2025-11-25' );

	private const VERSION_META = 'io.modelcontextprotocol/protocolVersion';

	private const NAMED_METHODS = array( 'tools/call', 'resources/read', 'prompts/get' );

	/**
	 * Handle one HTTP POST body.
	 *
	 * @param mixed                $message Decoded JSON body (associative arrays).
	 * @param array<string, mixed> $headers Lower-case header name => value (null when absent).
	 * @return array{status:int, body:array<string, mixed>|null}
	 */
	public function handle( mixed $message, array $headers ): array {
		if ( ! is_array( $message ) || array_is_list( $message ) ) {
			return self::fail( null, -32600, 'Invalid Request: expected one JSON-RPC object (batches are not supported).', 400 );
		}
		$id     = $message['id'] ?? null;
		$method = $message['method'] ?? null;
		$params = $message['params'] ?? array();
		if ( '2.0' !== ( $message['jsonrpc'] ?? null ) || ! is_string( $method ) || ! is_array( $params ) ) {
			return self::fail( $id, -32600, 'Invalid Request', 400 );
		}
		$is_notification = ! array_key_exists( 'id', $message );

		if ( 'initialize' === $method ) {
			return $this->initialize( $id, $params );
		}
		$version = $params['_meta'][ self::VERSION_META ] ?? null;
		if ( is_string( $version ) ) {
			return $this->modern( $id, $method, $params, $version, $headers, $is_notification );
		}
		return $this->legacy( $id, $method, $params, $headers, $is_notification );
	}

	/**
	 * Modern, stateless request.
	 *
	 * @param mixed                $id              Request id.
	 * @param string               $method          Method.
	 * @param array<string, mixed> $params          Params.
	 * @param string               $version         Version from `_meta`.
	 * @param array<string, mixed> $headers         Headers.
	 * @param bool                 $is_notification No id.
	 * @return array{status:int, body:array<string, mixed>|null}
	 */
	private function modern( mixed $id, string $method, array $params, string $version, array $headers, bool $is_notification ): array {
		if ( ( $headers['mcp-protocol-version'] ?? null ) !== $version ) {
			return self::fail( $id, -32020, 'Header mismatch: MCP-Protocol-Version is missing or does not match _meta.', 400 );
		}
		if ( ( $headers['mcp-method'] ?? null ) !== $method ) {
			return self::fail( $id, -32020, 'Header mismatch: Mcp-Method is missing or does not match the body.', 400 );
		}
		if ( in_array( $method, self::NAMED_METHODS, true ) ) {
			$name = $params['name'] ?? $params['uri'] ?? null;
			if ( ! is_string( $name ) || self::decode_header( $headers['mcp-name'] ?? null ) !== $name ) {
				return self::fail( $id, -32020, 'Header mismatch: Mcp-Name is missing or does not match the body.', 400 );
			}
		}
		if ( self::MODERN !== $version ) {
			return self::fail(
				$id,
				-32022,
				'Unsupported protocol version',
				400,
				array(
					'supported' => self::SUPPORTED,
					'requested' => $version,
				)
			);
		}
		if ( $is_notification ) {
			return array(
				'status' => 202,
				'body'   => null,
			);
		}

		switch ( $method ) {
			case 'server/discover':
				return self::ok(
					$id,
					self::modern_result(
						array(
							'supportedVersions' => self::SUPPORTED,
							'capabilities'      => self::capabilities(),
							'instructions'      => self::instructions(),
						),
						true
					)
				);
			case 'tools/list':
				return $this->authed( $id ) ?? self::ok( $id, self::modern_result( array( 'tools' => self::list_tools() ), true ) );
			case 'tools/call':
				return $this->authed( $id ) ?? $this->call( $id, $params, true );
		}
		return self::fail( $id, -32601, 'Method not found', 404 );
	}

	/**
	 * Legacy request after initialize. Sessions are optional in 2025-11-25, so none are kept.
	 *
	 * @param mixed                $id              Request id.
	 * @param string               $method          Method.
	 * @param array<string, mixed> $params          Params.
	 * @param array<string, mixed> $headers         Headers.
	 * @param bool                 $is_notification No id.
	 * @return array{status:int, body:array<string, mixed>|null}
	 */
	private function legacy( mixed $id, string $method, array $params, array $headers, bool $is_notification ): array {
		$header = $headers['mcp-protocol-version'] ?? null;
		if ( null !== $header && ! in_array( $header, self::LEGACY, true ) ) {
			return self::fail(
				$id,
				-32600,
				'Unsupported MCP-Protocol-Version',
				400,
				array( 'supported' => self::SUPPORTED )
			);
		}
		if ( $is_notification ) {
			return array(
				'status' => 202,
				'body'   => null,
			);
		}
		// Legacy clients read HTTP 404 as "session expired", so method errors stay HTTP 200.
		switch ( $method ) {
			case 'ping':
				return self::ok( $id, new \stdClass() );
			case 'tools/list':
				return $this->authed( $id ) ?? self::ok( $id, array( 'tools' => self::list_tools() ) );
			case 'tools/call':
				return $this->authed( $id ) ?? $this->call( $id, $params, false );
		}
		return self::fail( $id, -32601, 'Method not found', 200 );
	}

	/**
	 * Legacy initialize.
	 *
	 * @param mixed                $id     Request id.
	 * @param array<string, mixed> $params Params.
	 * @return array{status:int, body:array<string, mixed>}
	 */
	private function initialize( mixed $id, array $params ): array {
		$requested = $params['protocolVersion'] ?? null;
		return self::ok(
			$id,
			array(
				'protocolVersion' => in_array( $requested, self::LEGACY, true ) ? $requested : self::LEGACY[0],
				'capabilities'    => self::capabilities(),
				'serverInfo'      => self::server_info(),
				'instructions'    => self::instructions(),
			)
		);
	}

	/**
	 * Tools/call for either era.
	 *
	 * @param mixed                $id     Request id.
	 * @param array<string, mixed> $params Params.
	 * @param bool                 $modern Add modern result fields.
	 * @return array{status:int, body:array<string, mixed>}
	 */
	private function call( mixed $id, array $params, bool $modern ): array {
		$name = $params['name'] ?? null;
		$tool = is_string( $name ) ? ToolRegistry::create()->get( $name ) : null;
		if ( null === $tool || ( true !== $tool->is_available() && ! self::list_unavailable() ) ) {
			return self::fail( $id, -32602, 'Unknown tool: ' . ( is_string( $name ) ? $name : '' ), $modern ? 400 : 200 );
		}
		$result = Executor::call( $tool, $params['arguments'] ?? null );
		return self::ok( $id, $modern ? self::modern_result( $result, false ) : $result );
	}

	/**
	 * Tools the current user may see.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function list_tools(): array {
		$tools = array();
		foreach ( ToolRegistry::create()->all() as $tool ) {
			if ( ( true !== $tool->is_available() && ! self::list_unavailable() ) || ! current_user_can( $tool->capability( array() ) ) ) {
				continue;
			}
			$schema = $tool->input_schema();
			// An empty PHP array would encode as [], but JSON Schema needs an object.
			$schema['properties'] = (object) ( $schema['properties'] ?? array() );
			$tools[]              = array(
				'name'        => $tool->name(),
				'title'       => $tool->title(),
				'description' => $tool->description(),
				'inputSchema' => $schema,
				'annotations' => (object) $tool->annotations(),
			);
		}
		return $tools;
	}

	/**
	 * Whether unavailable integrations stay listed (architecture §6).
	 */
	private static function list_unavailable(): bool {
		return defined( 'KUDUY_MCP_LIST_UNAVAILABLE' ) && true === constant( 'KUDUY_MCP_LIST_UNAVAILABLE' );
	}

	/**
	 * Error response when nobody is logged in, else null. OAuth (step 3) adds WWW-Authenticate.
	 *
	 * @param mixed $id Request id.
	 * @return array{status:int, body:array<string, mixed>}|null
	 */
	private function authed( mixed $id ): ?array {
		return is_user_logged_in() ? null : self::fail( $id, -32001, 'Authentication required', 401 );
	}

	/**
	 * Add modern result fields.
	 *
	 * @param array<string, mixed> $result    Result.
	 * @param bool                 $cacheable Add ttlMs/cacheScope.
	 * @return array<string, mixed>
	 */
	private static function modern_result( array $result, bool $cacheable ): array {
		$result['resultType'] = 'complete';
		if ( $cacheable ) {
			$result['ttlMs']      = 0;
			$result['cacheScope'] = 'private';
		}
		$result['_meta'] = array( 'io.modelcontextprotocol/serverInfo' => self::server_info() );
		return $result;
	}

	/**
	 * Server capabilities.
	 *
	 * @return array<string, mixed>
	 */
	private static function capabilities(): array {
		return array( 'tools' => array( 'listChanged' => false ) );
	}

	/**
	 * Implementation info.
	 *
	 * @return array<string, string>
	 */
	private static function server_info(): array {
		return array(
			'name'    => 'kuduy-mcp',
			'title'   => 'KUDUY MCP',
			'version' => Plugin::VERSION,
		);
	}

	/**
	 * Server instructions for the model.
	 */
	private static function instructions(): string {
		return 'Tools act on this WordPress site as the connected WordPress user, limited by that user\'s capabilities. Start with kuduy-site-info.';
	}

	/**
	 * Decode the `=?base64?…?=` header sentinel.
	 *
	 * @param mixed $value Header value.
	 */
	private static function decode_header( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		if ( preg_match( '/^=\?base64\?(.*)\?=$/', $value, $m ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- MCP header encoding.
			$decoded = base64_decode( $m[1], true );
			return false === $decoded ? null : $decoded;
		}
		return $value;
	}

	/**
	 * Success response.
	 *
	 * @param mixed                       $id     Request id.
	 * @param array<string, mixed>|object $result Result.
	 * @return array{status:int, body:array<string, mixed>}
	 */
	private static function ok( mixed $id, array|object $result ): array {
		return array(
			'status' => 200,
			'body'   => array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			),
		);
	}

	/**
	 * JSON-RPC error response.
	 *
	 * @param mixed                     $id      Request id.
	 * @param int                       $code    Error code.
	 * @param string                    $message Message.
	 * @param int                       $status  HTTP status.
	 * @param array<string, mixed>|null $data    Error data.
	 * @return array{status:int, body:array<string, mixed>}
	 */
	public static function fail( mixed $id, int $code, string $message, int $status, ?array $data = null ): array {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);
		if ( null !== $data ) {
			$error['data'] = $data;
		}
		$body = array(
			'jsonrpc' => '2.0',
			'error'   => $error,
		);
		if ( null !== $id ) {
			$body = array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => $error,
			);
		}
		return array(
			'status' => $status,
			'body'   => $body,
		);
	}
}
