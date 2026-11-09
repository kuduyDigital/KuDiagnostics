<?php
/**
 * Tools whose arguments carry personal data (D12) mask them before they are logged.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Audit;

/**
 * The executor passes a tool's arguments through audit_args() before the Redactor and the audit log see them. Only
 * the audit row changes; the tool still receives the real arguments.
 */
interface MasksAuditArgs {

	/**
	 * Arguments as they may be stored in the audit log.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	public function audit_args( array $args ): array;
}
