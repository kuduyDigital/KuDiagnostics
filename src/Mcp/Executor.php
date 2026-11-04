<?php
/**
 * Runs one tool call.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Mcp;

/**
 * Availability → input validation → capability → execute. Audit and snapshots join in step 4.
 */
final class Executor {

	/**
	 * Run a tool and build a CallToolResult.
	 *
	 * @param Tool  $tool Tool.
	 * @param mixed $args Raw `arguments` from the request.
	 * @return array<string, mixed>
	 */
	public static function call( Tool $tool, mixed $args ): array {
		$available = $tool->is_available();
		if ( true !== $available ) {
			/* translators: %s: why the tool cannot run, e.g. "Elementor is not active". */
			return self::error( sprintf( __( 'This tool is not available: %s', 'kuduy-mcp' ), (string) $available ) );
		}

		$args   = null === $args ? array() : $args;
		$schema = $tool->input_schema();
		$valid  = rest_validate_value_from_schema( $args, $schema, 'arguments' );
		if ( is_wp_error( $valid ) ) {
			return self::error( $valid->get_error_message() );
		}
		$input = (array) rest_sanitize_value_from_schema( $args, $schema, 'arguments' );

		if ( ! current_user_can( $tool->capability( $input ) ) ) {
			return self::error( __( 'Your WordPress account is not allowed to use this tool.', 'kuduy-mcp' ) );
		}

		try {
			$result = $tool->execute( $input );
		} catch ( ToolError $e ) {
			return self::error( $e->getMessage() );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator diagnostics; arguments are never logged.
			error_log( sprintf( 'KUDUY MCP: tool %s failed with %s in %s:%d', $tool->name(), get_class( $e ), $e->getFile(), $e->getLine() ) );
			return self::error( __( 'The tool failed unexpectedly. Details are in the site error log.', 'kuduy-mcp' ) );
		}

		return array(
			'content'           => array(
				array(
					'type' => 'text',
					'text' => (string) wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				),
			),
			'structuredContent' => $result ? $result : new \stdClass(),
			'isError'           => false,
		);
	}

	/**
	 * Tool error result (the model sees it; not a protocol error).
	 *
	 * @param string $message Message.
	 * @return array<string, mixed>
	 */
	private static function error( string $message ): array {
		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $message,
				),
			),
			'isError' => true,
		);
	}
}
