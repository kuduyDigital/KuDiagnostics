<?php
/**
 * Tools: kuduy-beaver-status, kuduy-beaver-layouts, kuduy-beaver-get, kuduy-beaver-modules,
 * kuduy-beaver-module-schema (one class, five modes).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Beaver;

use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Read side of the Beaver Builder tools (D37): what is installed, which posts have a layout, a layout as a tree,
 * the registered modules and the settings each accepts (from the module's own settings form).
 */
final class BeaverRead implements Tool {

	/**
	 * Mode.
	 *
	 * @param string $mode status | layouts | get | modules | module-schema.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-beaver-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'status'  => __( 'Beaver Builder status', 'kuduy-mcp' ),
			'layouts' => __( 'List Beaver Builder pages', 'kuduy-mcp' ),
			'get'     => __( 'Read a Beaver Builder page', 'kuduy-mcp' ),
			'modules' => __( 'List Beaver Builder modules', 'kuduy-mcp' ),
			default   => __( 'Beaver Builder module settings', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'status'  => 'Beaver Builder on this site: version, free (Lite) or paid edition, the post types it works with, how many modules are registered and whether your role may use the builder.',
			'layouts' => 'Lists pages, posts and saved templates built with Beaver Builder: id, title, post type, status, link.',
			'get'     => 'Returns a Beaver Builder layout as a tree: rows > column groups > columns > modules, each with its node id; modules with their type and the settings that differ from the module\'s defaults. include_settings: false gives a compact outline. Reads the published layout.',
			'modules' => 'Lists the modules Beaver Builder can place on this site: slug, name, category, whether it is enabled in the builder settings.',
			default   => 'Returns the settings one module accepts (or "row" / "column"): name, type, label, default and options, from Beaver Builder\'s own settings form.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$props = match ( $this->mode ) {
			'layouts'       => array(
				'search' => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
			) + Content::paging_input(),
			'get'           => array(
				'id'               => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'include_settings' => array(
					'type'    => 'boolean',
					'default' => true,
				),
			),
			'module-schema' => array(
				'type' => array(
					'type'        => 'string',
					'description' => 'Module slug from kuduy-beaver-modules (e.g. "heading", "rich-text"), or "row" or "column".',
				),
			),
			default         => array(),
		};//end match
		return array(
			'type'                 => 'object',
			'properties'           => $props,
			'required'             => match ( $this->mode ) {
				'get'           => array( 'id' ),
				'module-schema' => array( 'type' ),
				default         => array(),
			},
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:read';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		$id = (int) ( $input['id'] ?? 0 );
		return 'get' === $this->mode && $id > 0 && get_post( $id ) ? array( 'edit_post', $id ) : 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return Beaver::available();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the post or module is unknown.
	 */
	public function execute( array $input ): array {
		return match ( $this->mode ) {
			'status'  => self::status(),
			'layouts' => self::layouts( $input ),
			'get'     => self::get( (int) $input['id'], ! ( false === ( $input['include_settings'] ?? true ) ) ),
			'modules' => self::modules(),
			default   => self::schema( (string) $input['type'] ),
		};
	}

	/**
	 * What is installed.
	 *
	 * @return array<string, mixed>
	 */
	private static function status(): array {
		return array(
			'version'    => Beaver::version(),
			'edition'    => defined( 'FL_BUILDER_LITE' ) && constant( 'FL_BUILDER_LITE' ) ? 'lite' : 'paid',
			'post_types' => Beaver::post_types(),
			'modules'    => count( (array) \FLBuilderModel::$modules ),
			'can_build'  => (bool) \FLBuilderUserAccess::current_user_can( 'builder_access' ),
		);
	}

	/**
	 * Posts with a Beaver Builder layout.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private static function layouts( array $input ): array {
		$args = array(
			'post_type'      => Beaver::post_types(),
			'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'posts_per_page' => (int) ( $input['per_page'] ?? 20 ),
			'paged'          => (int) ( $input['page'] ?? 1 ),
			'meta_key'       => '_fl_builder_enabled', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Beaver Builder's own marker for built pages.
			'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
			'orderby'        => 'modified',
		);
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = (string) $input['search'];
		}
		$query = new \WP_Query( $args );
		$out   = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$out[] = array(
				'id'        => $post->ID,
				'title'     => $post->post_title,
				'post_type' => $post->post_type,
				'status'    => $post->post_status,
				'link'      => (string) get_permalink( $post ),
			);
		}
		return array(
			'layouts' => $out,
			'total'   => (int) $query->found_posts,
		);
	}

	/**
	 * A layout as a tree.
	 *
	 * @param int  $id            Post id.
	 * @param bool $with_settings Include settings.
	 * @return array<string, mixed>
	 */
	private static function get( int $id, bool $with_settings ): array {
		$post  = Beaver::post( $id );
		$nodes = Beaver::nodes( $id );
		$types = array();
		foreach ( $nodes as $node ) {
			$type = Beaver::module_type( $node );
			if ( '' !== $type && null === Beaver::module( $type ) ) {
				$types[ $type ] = true;
			}
		}
		return array(
			'id'           => $id,
			'title'        => $post->post_title,
			'status'       => $post->post_status,
			'node_count'   => count( $nodes ),
			'rows'         => self::tree( $nodes, null, $with_settings ),
			'unregistered' => array_keys( $types ),
			'link'         => (string) get_permalink( $post ),
			'edit_url'     => (string) \FLBuilderModel::get_edit_url( $id ),
		);
	}

	/**
	 * Children of a parent as tree rows.
	 *
	 * @param array<string, object> $nodes         Nodes.
	 * @param string|null           $parent_id     Parent id.
	 * @param bool                  $with_settings Include settings.
	 * @return list<array<string, mixed>>
	 */
	private static function tree( array $nodes, ?string $parent_id, bool $with_settings ): array {
		$out = array();
		foreach ( Beaver::children( $nodes, $parent_id ) as $node ) {
			$row = array(
				'id'   => (string) $node->node,
				'type' => (string) $node->type,
			);
			if ( 'module' === $node->type ) {
				$row['module'] = Beaver::module_type( $node );
			}
			if ( 'column' === $node->type && is_object( $node->settings ?? null ) && isset( $node->settings->size ) ) {
				$row['width'] = (float) $node->settings->size;
			}
			if ( $with_settings ) {
				$settings = Beaver::changed_settings( $node );
				if ( $settings ) {
					$row['settings'] = $settings;
				}
			}
			$children = self::tree( $nodes, (string) $node->node, $with_settings );
			if ( $children ) {
				$row['children'] = $children;
			}
			$out[] = $row;
		}//end foreach
		return $out;
	}

	/**
	 * Registered modules.
	 *
	 * @return array<string, mixed>
	 */
	private static function modules(): array {
		$enabled = array_map( 'strval', (array) \FLBuilderModel::get_enabled_modules() );
		$all     = in_array( 'all', $enabled, true );
		$out     = array();
		foreach ( (array) \FLBuilderModel::$modules as $slug => $module ) {
			if ( ! is_object( $module ) ) {
				continue;
			}
			$out[] = array(
				'slug'     => (string) $slug,
				'name'     => wp_strip_all_tags( (string) ( $module->name ?? $slug ) ),
				'category' => wp_strip_all_tags( (string) ( $module->category ?? '' ) ),
				'enabled'  => ! empty( $module->enabled ) && ( $all || in_array( (string) $slug, $enabled, true ) ),
			);
		}
		return array( 'modules' => $out );
	}

	/**
	 * Settings of a module, a row or a column.
	 *
	 * @param string $type Module slug, "row" or "column".
	 * @return array<string, mixed>
	 * @throws ToolError When the module is not registered.
	 */
	private static function schema( string $type ): array {
		$defaults = Beaver::defaults( $type );
		$fields   = match ( $type ) {
			'row'    => (array) \FLBuilderModel::get_settings_form_fields( 'row', 'general' ),
			'column' => (array) \FLBuilderModel::get_settings_form_fields( 'col', 'general' ),
			default  => (array) \FLBuilderModel::get_settings_form_fields( $type, 'module' ),
		};
		$rows = array();
		foreach ( $fields as $name => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$row = array(
				'name'    => (string) $name,
				'type'    => (string) ( $field['type'] ?? '' ),
				'label'   => wp_strip_all_tags( (string) ( $field['label'] ?? '' ) ),
				'default' => $defaults[ $name ] ?? ( $field['default'] ?? null ),
			);
			if ( ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
				$row['options'] = array_map( 'strval', array_keys( $field['options'] ) );
			}
			if ( 'dimension' === $row['type'] ) {
				$row['keys'] = array_map( static fn( string $side ): string => $name . '_' . $side, isset( $field['keys'] ) && is_array( $field['keys'] ) ? array_map( 'strval', array_keys( $field['keys'] ) ) : array( 'top', 'right', 'bottom', 'left' ) );
				unset( $row['default'] );
			}
			$rows[] = $row;
		}
		return array(
			'type'     => $type,
			'settings' => $rows,
			'accepted' => array_values( array_diff( array_map( 'strval', array_keys( $defaults ) ), array( 'type' ) ) ),
		);
	}
}
