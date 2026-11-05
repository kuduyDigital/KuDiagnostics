<?php
/**
 * Tool: kuduy-snapshot-list.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Execution\Snapshots;
use Kuduy\Mcp\Mcp\Tool;

/**
 * Snapshots that can still be restored.
 */
final class SnapshotList implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-snapshot-list';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'List undo snapshots', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Lists snapshots taken before destructive changes that can still be undone with kuduy-snapshot-restore (your own; all of them for site managers), newest first.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'limit' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 20,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'  => true,
			'openWorldHint' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:read';
	}

	/**
	 * Any logged-in user; the list is filtered to what they may restore.
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
	 * List snapshots.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ): array {
		return array( 'snapshots' => Snapshots::visible( (int) ( $input['limit'] ?? 20 ) ) );
	}
}
