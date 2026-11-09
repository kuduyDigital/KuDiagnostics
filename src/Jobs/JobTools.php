<?php
/**
 * Tools: kuduy-job-start, kuduy-job-status, kuduy-job-cancel.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Jobs;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Mcp\ToolRegistry;
use Kuduy\Mcp\OAuth\Scopes;
use Kuduy\Mcp\OAuth\TokenAuth;

/**
 * Bulk work in the background (decision D9): start a job that runs one of Jobs::TOOLS over many inputs, follow its
 * progress, cancel it. Only the owner (or a site manager) sees or cancels a job.
 */
final class JobTools implements Tool {

	/**
	 * Mode.
	 *
	 * @param string $mode start | status | cancel.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-job-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'start'  => __( 'Start a background job', 'kuduy-mcp' ),
			'status' => __( 'Background job status', 'kuduy-mcp' ),
			default  => __( 'Cancel a background job', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'start'  => 'Runs one tool over many items in the background (up to 500): ' . implode( ', ', Jobs::TOOLS ) . '. Each item is the normal input of that tool (e.g. {"post_id": 12, "description": "…"}) and is checked like a single call, with its own undo snapshot. Returns a job id; follow it with kuduy-job-status. Use for more than a few items.',
			'status' => 'Progress of a background job: status (queued, running, done, cancelled, stopped, failed), counts, and per-item results with errors and undo snapshot ids.',
			default  => 'Stops a background job after the item it is working on; finished items stay changed (undo them with their snapshot ids).',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		if ( 'start' === $this->mode ) {
			return array(
				'type'                 => 'object',
				'properties'           => array(
					'tool'  => array(
						'type' => 'string',
						'enum' => Jobs::TOOLS,
					),
					'items' => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => Jobs::MAX_ITEMS,
						'items'    => array( 'type' => 'object' ),
					),
				),
				'required'             => array( 'tool', 'items' ),
				'additionalProperties' => false,
			);
		}
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'      => array( 'type' => 'integer' ),
				'tool'    => array( 'type' => 'string' ),
				'status'  => array( 'type' => 'string' ),
				'total'   => array( 'type' => 'integer' ),
				'done'    => array( 'type' => 'integer' ),
				'failed'  => array( 'type' => 'integer' ),
				'error'   => array( 'type' => 'string' ),
				'results' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'index'       => array(
								'type'        => 'integer',
								'description' => 'Position of the item in the items given to kuduy-job-start (0-based).',
							),
							'object_id'   => array(
								'type'        => 'integer',
								'description' => 'The object the item acted on (its id or post_id: post, image, product, variation, coupon, or the post/user/term of an ACF update).',
							),
							'ok'          => array( 'type' => 'boolean' ),
							'error'       => array( 'type' => 'string' ),
							'snapshot_id' => array( 'type' => 'integer' ),
						),
					),
				),
			),
			'required'   => array( 'id', 'tool', 'status', 'total', 'done', 'failed' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => 'status' === $this->mode,
			'destructiveHint' => false,
			'idempotentHint'  => 'start' !== $this->mode,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'status' === $this->mode ? 'kuduy:read' : 'kuduy:write';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the job or its items are not usable.
	 */
	public function execute( array $input ): array {
		if ( 'start' === $this->mode ) {
			return $this->start( $input );
		}
		$job = Jobs::get( (int) $input['id'] );
		if ( ! $job || ( get_current_user_id() !== (int) $job['user_id'] && ! current_user_can( 'manage_options' ) ) ) {
			throw new ToolError( 'No job with this id that your account can see.' );
		}
		AuditLog::target( 'job', (string) $job['id'] );
		if ( 'cancel' === $this->mode ) {
			Jobs::finish( (int) $job['id'], 'cancelled', 'Cancelled.' );
			$job = (array) Jobs::get( (int) $job['id'] );
		}
		return self::describe( $job );
	}

	/**
	 * Validate the items, then store and schedule the job.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the tool or an item is refused.
	 */
	private function start( array $input ): array {
		$tool = ToolRegistry::create()->get( (string) $input['tool'] );
		if ( ! $tool || true !== $tool->is_available() ) {
			throw new ToolError( 'This tool is not available on this site.' );
		}
		$scopes = TokenAuth::scopes();
		if ( null !== $scopes && ! Scopes::allows( $scopes, $tool->scope() ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( 'This connection does not allow ' . $tool->name() . ' (needs ' . $tool->scope() . ').' );
		}
		$items = array();
		foreach ( array_values( (array) $input['items'] ) as $index => $item ) {
			$valid = rest_validate_value_from_schema( $item, $tool->input_schema(), 'items[' . $index . ']' );
			if ( is_wp_error( $valid ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
				throw new ToolError( $valid->get_error_message() . ' Nothing was started.' );
			}
			$items[] = (array) $item;
		}
		$id = Jobs::create( $tool->name(), $items, get_current_user_id(), TokenAuth::client_id(), $scopes );
		AuditLog::target( 'job', (string) $id );
		return self::describe( (array) Jobs::get( $id ) );
	}

	/**
	 * Job as the tools return it.
	 *
	 * @param array<string, mixed> $job Job row.
	 * @return array<string, mixed>
	 */
	private static function describe( array $job ): array {
		return array(
			'id'      => (int) $job['id'],
			'tool'    => (string) $job['tool'],
			'status'  => (string) $job['status'],
			'total'   => (int) $job['total'],
			'done'    => (int) $job['done'],
			'failed'  => (int) $job['failed'],
			'error'   => (string) ( $job['error'] ?? '' ),
			'results' => self::results( $job ),
		);
	}

	/**
	 * Per-item results, each naming its item: position in the job and the object it acted on.
	 *
	 * @param array<string, mixed> $job Job row.
	 * @return list<array<string, mixed>>
	 */
	private static function results( array $job ): array {
		$items = array_values( (array) $job['items'] );
		$out   = array();
		foreach ( array_values( (array) $job['results'] ) as $index => $result ) {
			$item  = (array) ( $items[ $index ] ?? array() );
			$entry = array( 'index' => $index ) + (array) $result;
			$id    = $item['id'] ?? $item['post_id'] ?? null;
			if ( is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ) {
				$entry['object_id'] = (int) $id;
			}
			$out[] = $entry;
		}
		return $out;
	}
}
