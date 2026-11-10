<?php
/**
 * Runs one tool call.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Mcp;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Audit\MasksAuditArgs;
use Kuduy\Mcp\Audit\Redactor;
use Kuduy\Mcp\Execution\Snapshots;
use Kuduy\Mcp\Execution\Snapshottable;

/**
 * Availability → validation → capability → snapshot (destructive tools) → execute → redact → audit.
 */
final class Executor {

	/**
	 * Run a tool and build a CallToolResult. Every call is audited.
	 *
	 * @param Tool  $tool Tool.
	 * @param mixed $args Raw `arguments` from the request.
	 * @return array<string, mixed>
	 */
	public static function call( Tool $tool, mixed $args ): array {
		$start                      = hrtime( true );
		$snapshot                   = null;
		[ $result, $status, $code ] = self::run( $tool, $args, $snapshot );
		AuditLog::record( $tool->name(), self::audit_args( $tool, $args ), $status, $code, (int) ( ( hrtime( true ) - $start ) / 1000000 ), $snapshot );
		return $result;
	}

	/**
	 * The call itself.
	 *
	 * @param Tool     $tool     Tool.
	 * @param mixed    $args     Raw arguments.
	 * @param int|null $snapshot Set to the snapshot id when one was taken.
	 * @return array{0:array<string, mixed>, 1:string, 2:?string} Result, audit status, error code.
	 */
	private static function run( Tool $tool, mixed $args, ?int &$snapshot ): array {
		$available = $tool->is_available();
		if ( true !== $available ) {
			/* translators: %s: why the tool cannot run, e.g. "Elementor is not active". */
			return self::error( sprintf( __( 'This tool is not available: %s', 'kuduy-mcp' ), (string) $available ), 'error', 'unavailable' );
		}

		$args   = null === $args ? array() : $args;
		$schema = $tool->input_schema();
		$valid  = rest_validate_value_from_schema( $args, $schema, 'arguments' );
		if ( is_wp_error( $valid ) ) {
			return self::error( $valid->get_error_message(), 'error', 'invalid_arguments' );
		}
		$input = (array) rest_sanitize_value_from_schema( $args, $schema, 'arguments' );

		if ( ! self::allowed( $tool, $input ) ) {
			return self::error( __( 'Your WordPress account is not allowed to use this tool.', 'kuduy-mcp' ), 'denied', 'forbidden' );
		}

		try {
			if ( $tool instanceof Snapshottable && ( $tool->annotations()['destructiveHint'] ?? false ) ) {
				$snap = $tool->snapshot( $input );
				if ( null !== $snap ) {
					AuditLog::target( $snap['object_type'], $snap['object_id'] );
					$snapshot = Snapshots::store( $tool->name(), $snap, (array) self::audit_args( $tool, $input ) );
				}
			}
			$result = Redactor::redact_array( $tool->execute( $input ) );
		} catch ( ToolError $e ) {
			return self::error( $e->getMessage(), 'error', 'tool_error' );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator diagnostics; arguments are never logged.
			error_log( sprintf( 'KuDiagnostics: tool %s failed with %s in %s:%d', $tool->name(), get_class( $e ), $e->getFile(), $e->getLine() ) );
			return self::error( __( 'The tool failed unexpectedly. Details are in the site error log.', 'kuduy-mcp' ), 'error', 'exception' );
		}

		$extra = self::extra_content( $result['_content'] ?? array() );
		unset( $result['_content'] );
		if ( null !== $snapshot ) {
			$result['_snapshot_id'] = $snapshot;
		}
		return array(
			array(
				'content'           => array_merge(
					array(
						array(
							'type' => 'text',
							'text' => (string) wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
						),
					),
					$extra
				),
				'structuredContent' => $result ? $result : new \stdClass(),
				'isError'           => false,
			),
			'ok',
			null,
		);
	}

	/**
	 * Arguments as they may be stored (audit row, snapshot input): tools carrying personal data mask it (D12).
	 *
	 * @param Tool  $tool Tool.
	 * @param mixed $args Arguments.
	 */
	private static function audit_args( Tool $tool, mixed $args ): mixed {
		return $tool instanceof MasksAuditArgs && is_array( $args ) ? $tool->audit_args( $args ) : $args;
	}

	/**
	 * Whether the current user has the tool's capability for this input (object checks like [ 'edit_post', 42 ]).
	 *
	 * @param Tool                 $tool  Tool.
	 * @param array<string, mixed> $input Validated input; empty for listing.
	 */
	public static function allowed( Tool $tool, array $input ): bool {
		$cap = $tool->capability( $input );
		$cap = is_array( $cap ) ? array_values( $cap ) : array( $cap );
		return is_string( $cap[0] ?? null ) && current_user_can( ...$cap );
	}

	/**
	 * Extra MCP content blocks a tool returns under `_content` (e.g. an image for Claude to look at). Only image
	 * blocks with base64 data and a raster image MIME type pass; anything else is dropped.
	 *
	 * @param mixed $blocks Blocks from the tool.
	 * @return list<array{type:string, data:string, mimeType:string}>
	 */
	private static function extra_content( mixed $blocks ): array {
		$out = array();
		foreach ( is_array( $blocks ) ? $blocks : array() as $block ) {
			if ( is_array( $block ) && 'image' === ( $block['type'] ?? '' )
				&& in_array( $block['mimeType'] ?? '', array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true )
				&& is_string( $block['data'] ?? null ) && '' !== $block['data'] && false !== base64_decode( $block['data'], true ) ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- validates image data, nothing is executed.
				$out[] = array(
					'type'     => 'image',
					'data'     => $block['data'],
					'mimeType' => $block['mimeType'],
				);
			}
		}
		return $out;
	}

	/**
	 * Tool error result (the model sees it; not a protocol error).
	 *
	 * @param string $message Message.
	 * @param string $status  Audit status.
	 * @param string $code    Audit error code.
	 * @return array{0:array<string, mixed>, 1:string, 2:string}
	 */
	private static function error( string $message, string $status, string $code ): array {
		return array(
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => $message,
					),
				),
				'isError' => true,
			),
			$status,
			$code,
		);
	}
}
