<?php
/**
 * Tools whose changes can be rolled back.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Execution;

/**
 * Implemented by destructive tools: the executor stores snapshot() before execute(), restore() undoes it.
 */
interface Snapshottable {

	/**
	 * State that execute() is about to change.
	 *
	 * @param array<string, mixed> $input Validated arguments.
	 * @return array{object_type:string, object_id:string, payload:array<string, mixed>}|null Null = nothing to save.
	 */
	public function snapshot( array $input ): ?array;

	/**
	 * Put the saved state back. Throw ToolError if it cannot be restored.
	 *
	 * @param array<string, mixed> $payload Payload from snapshot().
	 */
	public function restore( array $payload ): void;
}
