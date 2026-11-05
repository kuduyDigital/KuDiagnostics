<?php
/**
 * Holds the tools for one request.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Mcp;

use Kuduy\Mcp\Modules\Core\CheckUpdate;
use Kuduy\Mcp\Modules\Core\Comments;
use Kuduy\Mcp\Modules\Core\Diagnostics;
use Kuduy\Mcp\Modules\Core\Menus;
use Kuduy\Mcp\Modules\Core\Plugins;
use Kuduy\Mcp\Modules\Core\PostCreate;
use Kuduy\Mcp\Modules\Core\PostGet;
use Kuduy\Mcp\Modules\Core\PostList;
use Kuduy\Mcp\Modules\Core\PostMeta;
use Kuduy\Mcp\Modules\Core\PostTrash;
use Kuduy\Mcp\Modules\Core\PostTypes;
use Kuduy\Mcp\Modules\Core\PostUpdate;
use Kuduy\Mcp\Modules\Core\Revisions;
use Kuduy\Mcp\Modules\Core\Settings;
use Kuduy\Mcp\Modules\Core\SiteInfo;
use Kuduy\Mcp\Modules\Core\SnapshotList;
use Kuduy\Mcp\Modules\Core\SnapshotRestore;
use Kuduy\Mcp\Modules\Core\TermList;
use Kuduy\Mcp\Modules\Core\Themes;
use Kuduy\Mcp\Modules\Core\TermWrite;
use Kuduy\Mcp\Modules\Core\Users;
use Kuduy\Mcp\Modules\Elementor\ElementorRead;

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
		$registry->register( new CheckUpdate() );
		$registry->register( new PostTypes() );
		$registry->register( new PostList() );
		$registry->register( new PostGet() );
		$registry->register( new TermList() );
		$registry->register( new PostCreate() );
		$registry->register( new PostUpdate() );
		$registry->register( new PostTrash() );
		foreach ( array( 'create', 'update', 'delete' ) as $mode ) {
			$registry->register( new TermWrite( $mode ) );
		}
		foreach ( array( 'list', 'moderate', 'reply' ) as $mode ) {
			$registry->register( new Comments( $mode ) );
		}
		$registry->register( new Revisions( 'list' ) );
		$registry->register( new Revisions( 'restore' ) );
		$registry->register( new PostMeta( 'get' ) );
		$registry->register( new PostMeta( 'set' ) );
		foreach ( array( 'list', 'get', 'create', 'update', 'roles' ) as $mode ) {
			$registry->register( new Users( $mode ) );
		}
		foreach ( array( 'list', 'get', 'create', 'item-add', 'item-update', 'item-delete', 'assign' ) as $mode ) {
			$registry->register( new Menus( $mode ) );
		}
		$registry->register( new Settings( 'get' ) );
		$registry->register( new Settings( 'update' ) );
		foreach ( array( 'list', 'install', 'activate', 'deactivate', 'update' ) as $mode ) {
			$registry->register( new Plugins( $mode ) );
		}
		foreach ( array( 'list', 'install', 'switch', 'update' ) as $mode ) {
			$registry->register( new Themes( $mode ) );
		}
		foreach ( array( 'health', 'cron', 'rewrite', 'rewrite-flush', 'hooks' ) as $mode ) {
			$registry->register( new Diagnostics( $mode ) );
		}
		foreach ( array( 'documents', 'get', 'widgets', 'schema', 'kit' ) as $mode ) {
			$registry->register( new ElementorRead( $mode ) );
		}
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
