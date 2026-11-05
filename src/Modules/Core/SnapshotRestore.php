<?php
/**
 * Tool: kuduy-snapshot-restore.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Execution\Snapshots;
use Kuduy\Mcp\Mcp\Tool;

/**
 * Undo one destructive change.
 */
final class SnapshotRestore implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-snapshot-restore';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Undo a change', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Restores the state saved in a snapshot (see kuduy-snapshot-list), undoing the change made after it. Each snapshot can be restored once, by the user who made the change or a site manager.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'snapshot_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'snapshot_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Structured result.
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'restored'    => array(
					'type'        => 'integer',
					'description' => 'Snapshot id that was restored.',
				),
				'object_type' => array( 'type' => 'string' ),
				'object_id'   => array( 'type' => 'string' ),
			),
			'required'   => array( 'restored', 'object_type', 'object_id' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:write';
	}

	/**
	 * Ownership and the original tool's capability are checked in Snapshots::restore().
	 *
	 * @param array<string, mixed> $input Unused.
	 */
	public function capability( array $input ): string {
		return 'read';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return true;
	}

	/**
	 * Restore.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ): array {
		return Snapshots::restore( (int) $input['snapshot_id'] );
	}
}
