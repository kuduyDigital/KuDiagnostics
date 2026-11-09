<?php
/**
 * Forms plugins: the adapters KUDUY ships and lookups shared by the forms tools.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Forms;

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Registry of forms adapters (D15 A). Gravity Forms is added once a licensed copy has been tested.
 */
final class Forms {

	/**
	 * Adapter ids accepted in tool input.
	 */
	public const PLUGINS = array( 'cf7', 'fluentform', 'wpforms' );

	/**
	 * Entry statuses across plugins.
	 */
	public const STATUSES = array( 'active', 'spam', 'trash' );

	/**
	 * Every adapter, active or not.
	 *
	 * @return list<FormsAdapter>
	 */
	public static function all(): array {
		return array( new Cf7Adapter(), new FluentAdapter(), new WpformsAdapter() );
	}

	/**
	 * Adapters whose plugin is loaded.
	 *
	 * @return list<FormsAdapter>
	 */
	public static function active(): array {
		return array_values( array_filter( self::all(), static fn( FormsAdapter $a ) => $a->active() ) );
	}

	/**
	 * Availability for the forms tools.
	 */
	public static function available(): bool|string {
		return self::active() ? true : 'No supported forms plugin is active (Contact Form 7, Fluent Forms, WPForms)';
	}

	/**
	 * Active adapter by id, or a ToolError.
	 *
	 * @param string $slug Adapter id.
	 * @throws ToolError When the plugin is unknown or not active.
	 */
	public static function adapter( string $slug ): FormsAdapter {
		foreach ( self::all() as $adapter ) {
			if ( $adapter->slug() === $slug ) {
				if ( ! $adapter->active() ) {
					throw new ToolError( esc_html( $adapter->name() ) . ' is not active on this site.' );
				}
				return $adapter;
			}
		}
		throw new ToolError( 'Unknown forms plugin "' . esc_html( $slug ) . '". Use one of: ' . esc_html( implode( ', ', self::PLUGINS ) ) . '.' );
	}

	/**
	 * Input schema for the plugin id.
	 *
	 * @return array<string, mixed>
	 */
	public static function plugin_input(): array {
		return array(
			'type'        => 'string',
			'enum'        => self::PLUGINS,
			'description' => 'Forms plugin: cf7 (Contact Form 7; entries from Flamingo), fluentform (Fluent Forms), wpforms (WPForms).',
		);
	}

	/**
	 * Input schema for a form or entry id.
	 *
	 * @return array<string, mixed>
	 */
	public static function id_input(): array {
		return array(
			'type'    => 'integer',
			'minimum' => 1,
		);
	}

	/**
	 * Refuse: the plugin keeps no entries on the site.
	 *
	 * @param FormsAdapter $adapter Adapter.
	 * @param string       $why     Explanation.
	 * @throws ToolError Always.
	 */
	public static function no_entries( FormsAdapter $adapter, string $why ): never {
		throw new ToolError( esc_html( $adapter->name() ) . ' keeps no entries on this site: ' . esc_html( $why ) );
	}

	/**
	 * Paging from tool input.
	 *
	 * @param array<string, mixed> $query Query.
	 * @return array{0: int, 1: int} Page (1-based), per page.
	 */
	public static function paging( array $query ): array {
		$page = max( 1, (int) ( $query['page'] ?? 1 ) );
		$per  = min( 100, max( 1, (int) ( $query['per_page'] ?? 20 ) ) );
		return array( $page, $per );
	}
}
