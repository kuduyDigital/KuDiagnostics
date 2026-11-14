<?php
/**
 * Shared Beaver Builder access: availability, permission, layout data, settings checks.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Beaver;

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Only Beaver Builder's own model (source of Beaver Builder Lite 2.11.1.2, D37): a layout is a flat list of nodes
 * (row, column-group, column, module) stored per post as "published" and "draft" data. Everything is read with
 * FLBuilderModel::get_layout_data() and changed with the model's own functions (add_row, add_col_group, add_module,
 * copy_*, move_node, reorder_node, delete_node, update_layout_data) on the draft, then published with
 * FLBuilderModel::save_layout(), exactly as the builder does. Settings are merged, processed and checked the way
 * FLBuilderModel::save_settings() does (process_node_settings, verify_settings) without its editor preview.
 */
final class Beaver {

	/**
	 * Column layouts add_row / add_col_group accept (FLBuilderModel::$row_layouts).
	 */
	public const LAYOUTS = array( '1-col', '2-cols', '3-cols', '4-cols', '5-cols', '6-cols', 'left-sidebar', 'right-sidebar', 'left-right-sidebar' );

	/**
	 * True when Beaver Builder is loaded, else the reason for the tool error.
	 */
	public static function available(): bool|string {
		return class_exists( 'FLBuilderModel' ) && class_exists( 'FLBuilder' ) && class_exists( 'FLBuilderUserAccess' )
			? true
			: 'Beaver Builder is not active on this site.';
	}

	/**
	 * Beaver Builder version, or ''.
	 */
	public static function version(): string {
		return defined( 'FL_BUILDER_VERSION' ) ? (string) constant( 'FL_BUILDER_VERSION' ) : '';
	}

	/**
	 * Post types the builder works with.
	 *
	 * @return list<string>
	 */
	public static function post_types(): array {
		return array_values( array_map( 'strval', (array) \FLBuilderModel::get_post_types() ) );
	}

	/**
	 * A post Beaver Builder may hold a layout for.
	 *
	 * @param int  $id    Post id.
	 * @param bool $built Require an enabled layout.
	 * @throws ToolError When the post is missing, of another type, not built, or the builder is closed to the user.
	 */
	public static function post( int $id, bool $built = true ): \WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::post_types(), true ) ) {
			throw new ToolError( 'No Beaver Builder page with this id. See kuduy-beaver-layouts.' );
		}
		if ( ! \FLBuilderUserAccess::current_user_can( 'builder_access' ) ) {
			throw new ToolError( 'Beaver Builder does not allow your role to use the builder (Settings > Beaver Builder > User Access).' );
		}
		if ( $built && ! get_post_meta( $post->ID, '_fl_builder_enabled', true ) ) {
			throw new ToolError( 'This post is not built with Beaver Builder.' );
		}
		return $post;
	}

	/**
	 * Run a callback with Beaver Builder pointed at a post and working on its draft, as in the builder.
	 *
	 * @template T
	 * @param int          $id Post id.
	 * @param callable():T $work Work.
	 * @return T
	 */
	public static function in( int $id, callable $work ) {
		$draft = static fn(): string => 'draft';
		\FLBuilderModel::set_post_id( $id );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Beaver Builder's own filter.
		add_filter( 'fl_builder_node_status', $draft, 999 );
		try {
			return $work();
		} finally {
			remove_filter( 'fl_builder_node_status', $draft, 999 );
			\FLBuilderModel::reset_post_id();
		}
	}

	/**
	 * Published nodes of a post, by id.
	 *
	 * @param int    $id     Post id.
	 * @param string $status published | draft.
	 * @return array<string, object>
	 */
	public static function nodes( int $id, string $status = 'published' ): array {
		$data = \FLBuilderModel::get_layout_data( $status, $id );
		return is_array( $data ) ? array_filter( $data, 'is_object' ) : array();
	}

	/**
	 * Module type of a node, or ''.
	 *
	 * @param object $node Node.
	 */
	public static function module_type( object $node ): string {
		return 'module' === ( $node->type ?? '' ) && is_object( $node->settings ?? null ) ? (string) ( $node->settings->type ?? '' ) : '';
	}

	/**
	 * Registered module object, or null.
	 *
	 * @param string $type Module slug.
	 */
	public static function module( string $type ): ?object {
		$module = '' !== $type ? ( \FLBuilderModel::$modules[ $type ] ?? null ) : null;
		return is_object( $module ) ? $module : null;
	}

	/**
	 * Default settings of a node kind: "row", "column" or a module slug.
	 *
	 * @param string $kind Kind.
	 * @return array<string, mixed>
	 * @throws ToolError When the module is not registered.
	 */
	public static function defaults( string $kind ): array {
		if ( 'row' === $kind ) {
			return (array) \FLBuilderModel::get_row_defaults();
		}
		if ( 'column' === $kind ) {
			return (array) \FLBuilderModel::get_col_defaults();
		}
		if ( null === self::module( $kind ) ) {
			throw new ToolError( 'Module "' . esc_html( $kind ) . '" is not available on this site. See kuduy-beaver-modules.' );
		}
		return (array) \FLBuilderModel::get_module_defaults( $kind );
	}

	/**
	 * Kind of a node for defaults(): row, column or the module slug; '' for column groups.
	 *
	 * @param object $node Node.
	 */
	public static function kind( object $node ): string {
		return match ( (string) ( $node->type ?? '' ) ) {
			'row'    => 'row',
			'column' => 'column',
			'module' => self::module_type( $node ),
			default  => '',
		};
	}

	/**
	 * Refuse settings a node kind does not have, and markup the user may not save.
	 *
	 * @param string               $kind     row | column | module slug.
	 * @param array<string, mixed> $settings Settings to set.
	 * @throws ToolError Naming the unknown keys or the refusal.
	 */
	public static function check( string $kind, array $settings ): void {
		$unknown = array_diff( array_map( 'strval', array_keys( $settings ) ), array_map( 'strval', array_keys( self::defaults( $kind ) ) ) );
		if ( isset( $settings['type'] ) ) {
			$unknown[] = 'type';
		}
		if ( $unknown ) {
			throw new ToolError( 'Unknown settings for "' . esc_html( $kind ) . '": ' . esc_html( implode( ', ', array_slice( array_unique( $unknown ), 0, 10 ) ) ) . '. See kuduy-beaver-module-schema.' );
		}
		if ( $settings && ! \FLBuilderModel::user_has_unfiltered_html() && true !== \FLBuilderModel::verify_settings( (object) $settings ) ) {
			throw new ToolError( 'These settings contain markup or code your account may not save (Beaver Builder needs unfiltered_html for it).' );
		}
	}

	/**
	 * Child nodes of a parent (null = top level) in position order.
	 *
	 * @param array<string, object> $nodes  Nodes.
	 * @param string|null           $parent_id Parent id.
	 * @return list<object>
	 */
	public static function children( array $nodes, ?string $parent_id ): array {
		$out = array_values( array_filter( $nodes, static fn( object $n ): bool => ( empty( $n->parent ) ? null : (string) $n->parent ) === $parent_id ) );
		usort( $out, static fn( object $a, object $b ): int => (int) ( $a->position ?? 0 ) <=> (int) ( $b->position ?? 0 ) );
		return $out;
	}

	/**
	 * Stored settings of a node that differ from the kind's defaults.
	 *
	 * @param object $node Node.
	 * @return array<string, mixed>
	 */
	public static function changed_settings( object $node ): array {
		$kind = self::kind( $node );
		if ( '' === $kind || ! is_object( $node->settings ?? null ) ) {
			return array();
		}
		try {
			$defaults = self::defaults( $kind );
		} catch ( ToolError $e ) {
			$defaults = array();
		}
		$out = array();
		foreach ( (array) $node->settings as $key => $value ) {
			// phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- stored values and defaults differ in type ("10" vs 10).
			if ( 'type' !== $key && ( ! array_key_exists( $key, $defaults ) || $defaults[ $key ] != $value ) ) {
				$out[ (string) $key ] = $value;
			}
		}
		return $out;
	}
}
