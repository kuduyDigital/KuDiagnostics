<?php
/**
 * Shared helpers: call a tool through the real MCP endpoint and check its result against the tool's output schema.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Mcp\ToolRegistry;

trait CallsTools {

	/**
	 * Create a user with a role.
	 *
	 * @param string $role Role.
	 */
	private static function make_user( string $role ): int {
		$id = wp_insert_user(
			array(
				'user_login' => 'kuduy_' . $role . '_' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => wp_generate_password( 8, false ) . '@example.test',
				'role'       => $role,
			)
		);
		return is_wp_error( $id ) ? 0 : $id;
	}

	/**
	 * Call a tool through the MCP endpoint; returns the CallToolResult.
	 *
	 * @param string               $tool Tool.
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private function call( string $tool, array $args = array() ): array {
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
						'arguments' => $args ? $args : new \stdClass(),
						'_meta'     => array( 'io.modelcontextprotocol/protocolVersion' => '2026-07-28' ),
					),
				)
			)
		);
		$data = rest_ensure_response( rest_get_server()->dispatch( $request ) )->get_data();
		$this->assertIsArray( $data['result'] ?? null, (string) wp_json_encode( $data ) );
		return json_decode( (string) wp_json_encode( $data['result'] ), true );
	}

	/**
	 * Successful call's structured result, validated against the tool's output schema.
	 *
	 * @param string               $tool Tool.
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private function ok( string $tool, array $args = array() ): array {
		$result = $this->call( $tool, $args );
		$this->assertFalse( $result['isError'], $tool . ': ' . (string) wp_json_encode( $result ) );
		$valid = rest_validate_value_from_schema( $result['structuredContent'], ToolRegistry::create()->get( $tool )->output_schema(), $tool );
		$this->assertTrue( true === $valid, $tool . ' output schema: ' . ( is_wp_error( $valid ) ? $valid->get_error_message() : '' ) );
		return $result['structuredContent'];
	}

	/**
	 * Assert a call fails as a tool error (denied or invalid) and return its message.
	 *
	 * @param string               $tool    Tool.
	 * @param array<string, mixed> $args    Arguments.
	 * @param string               $because Assertion message.
	 */
	private function fails( string $tool, array $args, string $because ): string {
		$result = $this->call( $tool, $args );
		$this->assertTrue( $result['isError'], $because . ': ' . (string) wp_json_encode( $result ) );
		return (string) ( $result['content'][0]['text'] ?? '' );
	}
}
