<?php
/**
 * Holds the tools for one request.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Mcp;

use Kuduy\Mcp\Modules\Core\SiteInfo;
use Kuduy\Mcp\Modules\Core\SnapshotList;
use Kuduy\Mcp\Modules\Core\SnapshotRestore;

/**
 * Built per request so tools and integrations reflect the current state.
 */
final class ToolRegistry {

	/**
	 * Tools by name.
	 *
	 * @var array<string, Tool>
	 */
	private array $tools = array();

	/**
	 * Registry with the built-in tools plus those added on `kuduy_mcp_register_tools`.
	 */
	public static function create(): self {
		$registry = new self();
		$registry->register( new SiteInfo() );
		$registry->register( new SnapshotList() );
		$registry->register( new SnapshotRestore() );
		do_action( 'kuduy_mcp_register_tools', $registry );
		return $registry;
	}

	/**
	 * Add a tool; a later registration with the same name replaces the earlier one.
	 *
	 * @param Tool $tool Tool.
	 */
	public function register( Tool $tool ): void {
		$this->tools[ $tool->name() ] = $tool;
	}

	/**
	 * Tool by name, or null.
	 *
	 * @param string $name Tool name.
	 */
	public function get( string $name ): ?Tool {
		return $this->tools[ $name ] ?? null;
	}

	/**
	 * All registered tools.
	 *
	 * @return array<string, Tool>
	 */
	public function all(): array {
		return $this->tools;
	}
}
