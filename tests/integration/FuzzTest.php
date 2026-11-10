<?php
/**
 * Phase 9 fuzzing (test matrix Z1–Z2): every available tool gets hostile input. As an administrator only
 * schema-invalid input is sent (wrong types, unknown properties), which must be refused before anything runs; as a
 * subscriber, well-typed but hostile values are sent (huge strings, traversal and SQL-like text, negative and huge
 * numbers, deep nesting). Every call must end in a JSON-RPC result — a tool error or a refusal — never a PHP error,
 * an exception leaking through, or an HTTP error from the endpoint.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Mcp\ToolRegistry;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class FuzzTest extends TestCase {

	use CallsTools;

	private const HOSTILE = array(
		'../../../../wp-config.php',
		"'; DROP TABLE wp_posts; --",
		'<script>alert(1)</script>',
		"\0null\0byte",
		'%2e%2e%2f%2e%2e%2fetc%2fpasswd',
		'{prefix}users',
		'http://127.0.0.1/admin',
		'😀 ünïcödé',
	);

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
	}

	/**
	 * Available tools.
	 *
	 * @return list<\Kuduy\Mcp\Mcp\Tool>
	 */
	private static function tools(): array {
		return array_values( array_filter( ToolRegistry::create()->all(), static fn( $t ) => true === $t->is_available() ) );
	}

	/**
	 * Call through the endpoint and return the raw JSON-RPC data (no assertions on success).
	 *
	 * @param string $tool Tool.
	 * @param mixed  $args Arguments.
	 * @return array<string, mixed>
	 */
	private function raw( string $tool, mixed $args ): array {
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
		$response = rest_ensure_response( rest_get_server()->dispatch( $request ) );
		$this->assertLessThan( 500, $response->get_status(), $tool . ': HTTP ' . $response->get_status() );
		return (array) $response->get_data();
	}

	/**
	 * A value of the wrong type for a schema.
	 *
	 * @param array<string, mixed> $schema Property schema.
	 * @return mixed
	 */
	private static function wrong_type( array $schema ): mixed {
		$type = (array) ( $schema['type'] ?? 'string' );
		if ( ! in_array( 'object', $type, true ) && ! in_array( 'array', $type, true ) ) {
			return array( 'nested' => array( 'deep' => true ) );
		}
		return in_array( 'string', $type, true ) ? 12.5 : 'not-an-object';
	}

	/**
	 * A hostile value of the right type.
	 *
	 * @param array<string, mixed> $schema Property schema.
	 * @param int                  $i      Variant.
	 * @return mixed
	 */
	private static function hostile( array $schema, int $i ): mixed {
		$type = (array) ( $schema['type'] ?? 'string' );
		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && $schema['enum'] ) {
			return $schema['enum'][ $i % count( $schema['enum'] ) ];
		}
		return match ( true ) {
			in_array( 'integer', $type, true ) => array( -1, 0, PHP_INT_MAX, 999999999 )[ $i % 4 ],
			in_array( 'number', $type, true )  => array( -1.5, 1e308, 0 )[ $i % 3 ],
			in_array( 'boolean', $type, true ) => 0 === $i % 2,
			in_array( 'array', $type, true )   => array_fill( 0, 3, self::HOSTILE[ $i % count( self::HOSTILE ) ] ),
			in_array( 'object', $type, true )  => array( 'a' => array( 'b' => array( 'c' => self::HOSTILE[ $i % count( self::HOSTILE ) ] ) ) ),
			default                            => 0 === $i % 5 ? str_repeat( 'A', 5000 ) : self::HOSTILE[ $i % count( self::HOSTILE ) ],
		};
	}

	public function test_admin_schema_invalid_input_is_refused_before_running(): void {
		wp_set_current_user( self::make_user( 'administrator' ) );
		$checked = 0;
		foreach ( self::tools() as $tool ) {
			$schema = $tool->input_schema();
			$props  = (array) ( $schema['properties'] ?? array() );
			$cases  = array( array( 'kuduy_unknown_property' => 1 ) );
			foreach ( $props as $name => $prop ) {
				$cases[] = array( (string) $name => self::wrong_type( (array) $prop ) );
			}
			foreach ( $cases as $args ) {
				$data = $this->raw( $tool->name(), $args );
				$this->assertTrue(
					! empty( $data['result']['isError'] ) || isset( $data['error'] ),
					$tool->name() . ' accepted schema-invalid input ' . wp_json_encode( $args ) . ': ' . wp_json_encode( $data )
				);
				++$checked;
			}
		}
		$this->assertGreaterThan( 100, $checked );
	}

	public function test_subscriber_hostile_values_never_crash(): void {
		wp_set_current_user( self::make_user( 'subscriber' ) );
		foreach ( self::tools() as $tool ) {
			$props = (array) ( $tool->input_schema()['properties'] ?? array() );
			for ( $i = 0; $i < 4; $i++ ) {
				$args = array();
				foreach ( $props as $name => $prop ) {
					$args[ (string) $name ] = self::hostile( (array) $prop, $i + strlen( (string) $name ) );
				}
				$data = $this->raw( $tool->name(), $args ? $args : new \stdClass() );
				$this->assertTrue( isset( $data['result'] ) || isset( $data['error'] ), $tool->name() . ': no JSON-RPC result' );
				$text = (string) wp_json_encode( $data );
				$this->assertStringNotContainsString( 'Fatal error', $text, $tool->name() );
				$this->assertStringNotContainsString( 'Stack trace', $text, $tool->name() );
				$secret = defined( 'DB_PASSWORD' ) ? (string) constant( 'DB_PASSWORD' ) : '';
				if ( strlen( $secret ) >= 4 ) {
					$this->assertStringNotContainsString( $secret, $text, $tool->name() . ' leaked the database password' );
				}
			}
		}
	}
}
