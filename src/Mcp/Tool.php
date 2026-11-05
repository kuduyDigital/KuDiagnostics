<?php
/**
 * Tool contract (architecture §6).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Mcp;

/**
 * One MCP tool. Names: `[a-z0-9-]`, max 64 chars, so `Mcp-Name` never needs base64.
 */
interface Tool {

	/**
	 * Tool name, e.g. `kuduy-site-info`.
	 */
	public function name(): string;

	/**
	 * Human-readable title.
	 */
	public function title(): string;

	/**
	 * What the tool does, for the model.
	 */
	public function description(): string;

	/**
	 * JSON Schema for `arguments`; must have `"type": "object"`.
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema(): array;

	/**
	 * JSON Schema of the structured result (`structuredContent`); must have `"type": "object"`.
	 * Destructive tools may add `_snapshot_id`, so do not set `additionalProperties: false` at the top level.
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema(): array;

	/**
	 * MCP annotations: readOnlyHint, destructiveHint, idempotentHint, openWorldHint.
	 *
	 * @return array<string, bool>
	 */
	public function annotations(): array;

	/**
	 * OAuth scope: kuduy:read | kuduy:write | kuduy:admin | kuduy:code.
	 */
	public function scope(): string;

	/**
	 * WordPress capability the current user needs: a capability name, or `[ capability, ...args ]` for an
	 * object check such as `[ 'edit_post', 42 ]`. With empty `$input` (tool listing) return the general capability.
	 *
	 * @param array<string, mixed> $input Validated arguments; empty when listing.
	 * @return string|array<int, mixed>
	 */
	public function capability( array $input ): string|array;

	/**
	 * True, or the reason it is unavailable (e.g. "Elementor is not active").
	 */
	public function is_available(): bool|string;

	/**
	 * Run the tool. Throw ToolError for problems the caller should see.
	 *
	 * @param array<string, mixed> $input Validated, sanitized arguments.
	 * @return array<string, mixed> Structured result.
	 */
	public function execute( array $input ): array;
}
