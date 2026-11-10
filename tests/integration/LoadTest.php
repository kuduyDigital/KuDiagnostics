<?php
/**
 * Phase 9 load check (test matrix Z3): times tools/list and common read calls through the endpoint in one PHP process
 * and records the numbers in the run's output folder (load.txt). Asserts generous ceilings only (Playground on a
 * laptop is slow); the recorded numbers are what TESTING.md reports.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class LoadTest extends TestCase {

	use CallsTools;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
	}

	/**
	 * Milliseconds per call for a JSON-RPC method, over $n calls.
	 *
	 * @param string               $method tools/list or tools/call.
	 * @param array<string, mixed> $params Params.
	 * @param int                  $n      Calls.
	 * @return array{avg: float, p95: float, max: float}
	 */
	private static function time_calls( string $method, array $params, int $n ): array {
		$times = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$request = new \WP_REST_Request( 'POST', '/kuduy-mcp/v1/mcp' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_header( 'MCP-Protocol-Version', '2026-07-28' );
			$request->set_header( 'Mcp-Method', $method );
			if ( isset( $params['name'] ) ) {
				$request->set_header( 'Mcp-Name', (string) $params['name'] );
			}
			$request->set_body(
				(string) wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'id'      => $i + 1,
						'method'  => $method,
						'params'  => $params + array( '_meta' => array( 'io.modelcontextprotocol/protocolVersion' => '2026-07-28' ) ),
					)
				)
			);
			$start = hrtime( true );
			rest_get_server()->dispatch( $request );
			$times[] = ( hrtime( true ) - $start ) / 1e6;
		}
		sort( $times );
		return array(
			'avg' => round( array_sum( $times ) / count( $times ), 1 ),
			'p95' => round( $times[ (int) floor( 0.95 * ( count( $times ) - 1 ) ) ], 1 ),
			'max' => round( (float) end( $times ), 1 ),
		);
	}

	public function test_tools_list_and_common_calls(): void {
		wp_set_current_user( self::make_user( 'administrator' ) );
		// The rate limit (120 per minute) applies per user and app; this measures the code path, so lift it.
		add_filter( 'kuduy_mcp_rate_limit', static fn() => 100000 );
		$results = array(
			'tools/list'      => self::time_calls( 'tools/list', array(), 50 ),
			'kuduy-site-info' => self::time_calls(
				'tools/call',
				array(
					'name'      => 'kuduy-site-info',
					'arguments' => new \stdClass(),
				),
				50
			),
			'kuduy-post-list' => self::time_calls(
				'tools/call',
				array(
					'name'      => 'kuduy-post-list',
					'arguments' => array( 'per_page' => 20 ),
				),
				50
			),
		);
		$memory  = round( memory_get_peak_usage( true ) / 1048576, 1 );
		$line    = gmdate( 'c' ) . ' ' . wp_json_encode( $results + array( 'peak_memory_mb' => $memory ) ) . "\n";
		if ( is_dir( '/kuduy-out' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test report into the run's output folder.
			file_put_contents( '/kuduy-out/load.txt', $line, FILE_APPEND );
		}
		foreach ( $results as $name => $r ) {
			$this->assertLessThan( 2000, $r['p95'], $name . ' p95 ' . $r['p95'] . ' ms' );
		}
		$this->assertLessThan( 512, $memory, 'Peak memory MB' );
	}
}
