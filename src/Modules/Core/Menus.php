<?php
/**
 * Tools: kuduy-menu-list, kuduy-menu-get, kuduy-menu-create, kuduy-menu-item-add, kuduy-menu-item-update,
 * kuduy-menu-item-delete, kuduy-menu-assign (one class, seven modes).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Classic navigation menus (Appearance > Menus), guarded by edit_theme_options like wp-admin. Block themes keep
 * navigation in wp_navigation posts instead; menus here still exist but only show where a theme location uses them.
 */
final class Menus implements Tool, Snapshottable {

	private const ITEM_FIELDS = array(
		'title'       => 'menu-item-title',
		'url'         => 'menu-item-url',
		'parent_item' => 'menu-item-parent-id',
		'position'    => 'menu-item-position',
		'target'      => 'menu-item-target',
		'classes'     => 'menu-item-classes',
		'description' => 'menu-item-description',
	);

	/**
	 * Mode.
	 *
	 * @param string $mode list | get | create | item-add | item-update | item-delete | assign.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-menu-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'list'        => __( 'List menus', 'kuduy-mcp' ),
			'get'         => __( 'Read a menu', 'kuduy-mcp' ),
			'create'      => __( 'Create a menu', 'kuduy-mcp' ),
			'item-add'    => __( 'Add a menu item', 'kuduy-mcp' ),
			'item-update' => __( 'Update a menu item', 'kuduy-mcp' ),
			'item-delete' => __( 'Remove a menu item', 'kuduy-mcp' ),
			default       => __( 'Assign a menu to a location', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'list'        => 'Lists classic navigation menus (Appearance > Menus) with item counts, and the theme\'s menu locations with the menu assigned to each. Block themes may have no locations; their navigation lives in wp_navigation posts.',
			'get'         => 'Returns a menu\'s items in order: id, title, type (post_type, taxonomy, custom), linked object, URL and parent item.',
			'create'      => 'Creates an empty classic menu. Add items with kuduy-menu-item-add and show it with kuduy-menu-assign.',
			'item-add'    => 'Adds an item to a menu: a link to a post/page (type post_type + object_id), a term (type taxonomy + taxonomy + object_id) or any URL (type custom + url). Optional parent_item for submenus and position.',
			'item-update' => 'Changes title, URL (custom links), parent, position, target, CSS classes or description of a menu item. Snapshot first; undo with kuduy-snapshot-restore.',
			'item-delete' => 'Removes an item from its menu (its sub-items move up). Undo re-adds it (new id).',
			default       => 'Shows a menu in a theme menu location (see kuduy-menu-list), or empties the location with menu_id 0. Snapshot first.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$id     = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$fields = array(
			'title'       => array(
				'type'      => 'string',
				'maxLength' => 250,
			),
			'url'         => array(
				'type'   => 'string',
				'format' => 'uri',
			),
			'parent_item' => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'position'    => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'target'      => array(
				'type' => 'string',
				'enum' => array( '', '_blank' ),
			),
			'classes'     => array(
				'type'      => 'string',
				'maxLength' => 250,
			),
			'description' => array( 'type' => 'string' ),
		);
		$schema = match ( $this->mode ) {
			'list'        => array( 'properties' => array() ),
			'get'         => array(
				'properties' => array( 'menu_id' => $id ),
				'required'   => array( 'menu_id' ),
			),
			'create'      => array(
				'properties' => array(
					'name' => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 200,
					),
				),
				'required'   => array( 'name' ),
			),
			'item-add'    => array(
				'properties' => array(
					'menu_id'   => $id,
					'type'      => array(
						'type' => 'string',
						'enum' => array( 'post_type', 'taxonomy', 'custom' ),
					),
					'object_id' => $id,
					'taxonomy'  => array( 'type' => 'string' ),
				) + $fields,
				'required'   => array( 'menu_id', 'type' ),
			),
			'item-update' => array(
				'properties' => array( 'item_id' => $id ) + $fields,
				'required'   => array( 'item_id' ),
			),
			'item-delete' => array(
				'properties' => array( 'item_id' => $id ),
				'required'   => array( 'item_id' ),
			),
			default       => array(
				'properties' => array(
					'location' => array( 'type' => 'string' ),
					'menu_id'  => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
				),
				'required'   => array( 'location', 'menu_id' ),
			),
		};//end match
		return array( 'type' => 'object' ) + $schema + array( 'additionalProperties' => false );
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		$item   = array(
			'type'       => 'object',
			'properties' => array(
				'id'          => array( 'type' => 'integer' ),
				'title'       => array( 'type' => 'string' ),
				'type'        => array( 'type' => 'string' ),
				'object'      => array( 'type' => 'string' ),
				'object_id'   => array( 'type' => 'integer' ),
				'url'         => array( 'type' => 'string' ),
				'parent_item' => array( 'type' => 'integer' ),
				'position'    => array( 'type' => 'integer' ),
				'target'      => array( 'type' => 'string' ),
			),
			'required'   => array( 'id', 'title', 'type', 'url', 'parent_item', 'position' ),
		);
		$menu   = array(
			'type'       => 'object',
			'properties' => array(
				'id'        => array( 'type' => 'integer' ),
				'name'      => array( 'type' => 'string' ),
				'slug'      => array( 'type' => 'string' ),
				'items'     => array( 'type' => 'integer' ),
				'locations' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'required'   => array( 'id', 'name', 'slug' ),
		);
		$schema = match ( $this->mode ) {
			'list'   => array(
				'properties' => array(
					'menus'     => array(
						'type'  => 'array',
						'items' => $menu,
					),
					'locations' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'location'    => array( 'type' => 'string' ),
								'description' => array( 'type' => 'string' ),
								'menu_id'     => array( 'type' => 'integer' ),
							),
						),
					),
				),
				'required'   => array( 'menus', 'locations' ),
			),
			'get'    => array(
				'properties' => array(
					'menu'  => $menu,
					'items' => array(
						'type'  => 'array',
						'items' => $item,
					),
				),
				'required'   => array( 'menu', 'items' ),
			),
			'create' => $menu,
			'assign' => array(
				'properties' => array(
					'location' => array( 'type' => 'string' ),
					'menu_id'  => array( 'type' => 'integer' ),
				),
				'required'   => array( 'location', 'menu_id' ),
			),
			default  => $item,
		};//end match
		$schema['type']                       = 'object';
		$schema['properties']['_snapshot_id'] = array( 'type' => 'integer' );
		return $schema;
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return in_array( $this->mode, array( 'list', 'get' ), true ) ? Content::read_only() : array(
			'readOnlyHint'    => false,
			'destructiveHint' => in_array( $this->mode, array( 'item-update', 'item-delete', 'assign' ), true ),
			'idempotentHint'  => in_array( $this->mode, array( 'item-update', 'assign' ), true ),
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return in_array( $this->mode, array( 'list', 'get' ), true ) ? 'kuduy:read' : 'kuduy:admin';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'edit_theme_options';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'assign' === $this->mode ) {
			return array(
				'object_type' => 'menu_location',
				'object_id'   => (string) $input['location'],
				'payload'     => array( 'locations' => (array) get_theme_mod( 'nav_menu_locations', array() ) ),
			);
		}
		$item = self::item( (int) ( $input['item_id'] ?? 0 ) );
		if ( null === $item ) {
			return null;
		}
		$menus = wp_get_object_terms( $item->ID, 'nav_menu', array( 'fields' => 'ids' ) );
		return array(
			'object_type' => 'menu_item',
			'object_id'   => (string) $item->ID,
			'payload'     => array(
				'mode'    => $this->mode,
				'item_id' => $item->ID,
				'menu_id' => is_array( $menus ) && $menus ? (int) $menus[0] : 0,
				'args'    => self::args( $item ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the menu or item cannot be put back.
	 */
	public function restore( array $payload ): void {
		if ( isset( $payload['locations'] ) ) {
			set_theme_mod( 'nav_menu_locations', array_map( 'intval', (array) $payload['locations'] ) );
			return;
		}
		$menu_id = (int) ( $payload['menu_id'] ?? 0 );
		if ( ! wp_get_nav_menu_object( $menu_id ) ) {
			throw new ToolError( 'The menu no longer exists.' );
		}
		$item_id = 'item-update' === $payload['mode'] ? (int) $payload['item_id'] : 0;
		$result  = wp_update_nav_menu_item( $menu_id, $item_id, wp_slash( (array) $payload['args'] ) );
		if ( is_wp_error( $result ) ) {
			throw new ToolError( 'Undo failed: ' . esc_html( $result->get_error_message() ) );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the menu, item or location is invalid or WordPress refuses.
	 */
	public function execute( array $input ): array {
		return match ( $this->mode ) {
			'list'        => self::list(),
			'get'         => self::get( (int) $input['menu_id'] ),
			'create'      => self::create( (string) $input['name'] ),
			'item-add'    => self::add( $input ),
			'item-update' => self::update( $input ),
			'item-delete' => self::delete( (int) $input['item_id'] ),
			default       => self::assign( (string) $input['location'], (int) $input['menu_id'] ),
		};
	}

	/**
	 * Menus and locations.
	 *
	 * @return array<string, mixed>
	 */
	private static function list(): array {
		$assigned  = (array) get_theme_mod( 'nav_menu_locations', array() );
		$menus     = array();
		$locations = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$menus[] = self::menu( $menu, $assigned );
		}
		foreach ( get_registered_nav_menus() as $location => $description ) {
			$locations[] = array(
				'location'    => (string) $location,
				'description' => (string) $description,
				'menu_id'     => (int) ( $assigned[ $location ] ?? 0 ),
			);
		}
		return array(
			'menus'     => $menus,
			'locations' => $locations,
		);
	}

	/**
	 * One menu with items.
	 *
	 * @param int $id Menu id.
	 * @return array<string, mixed>
	 * @throws ToolError When the menu does not exist.
	 */
	private static function get( int $id ): array {
		$menu = wp_get_nav_menu_object( $id );
		if ( ! $menu ) {
			throw new ToolError( 'Menu not found. See kuduy-menu-list.' );
		}
		AuditLog::target( 'menu', (string) $menu->term_id );
		$items = array();
		foreach ( (array) wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'any' ) ) as $item ) {
			$items[] = self::shape( $item );
		}
		return array(
			'menu'  => self::menu( $menu, (array) get_theme_mod( 'nav_menu_locations', array() ) ),
			'items' => $items,
		);
	}

	/**
	 * Create a menu.
	 *
	 * @param string $name Name.
	 * @return array<string, mixed>
	 * @throws ToolError When WordPress refuses (e.g. the name exists).
	 */
	private static function create( string $name ): array {
		$id = wp_create_nav_menu( $name );
		if ( is_wp_error( $id ) ) {
			throw new ToolError( 'WordPress refused the menu: ' . esc_html( $id->get_error_message() ) );
		}
		AuditLog::target( 'menu', (string) $id );
		$menu = wp_get_nav_menu_object( $id );
		if ( ! $menu ) {
			throw new ToolError( 'The menu was created but could not be read back.' );
		}
		return self::menu( $menu, array() );
	}

	/**
	 * Add an item.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the target object or menu is invalid.
	 */
	private static function add( array $input ): array {
		$menu = wp_get_nav_menu_object( (int) $input['menu_id'] );
		if ( ! $menu ) {
			throw new ToolError( 'Menu not found. See kuduy-menu-list.' );
		}
		$args = array(
			'menu-item-type'   => (string) $input['type'],
			'menu-item-status' => 'publish',
		);
		switch ( $input['type'] ) {
			case 'post_type':
				$post = Content::post( (int) ( $input['object_id'] ?? 0 ) );
				if ( null === $post || 'publish' !== $post->post_status ) {
					throw new ToolError( 'object_id must be a published post or page.' );
				}
				$args += array(
					'menu-item-object'    => $post->post_type,
					'menu-item-object-id' => $post->ID,
				);
				break;
			case 'taxonomy':
				$term = get_term( (int) ( $input['object_id'] ?? 0 ), (string) ( $input['taxonomy'] ?? '' ) );
				if ( ! $term instanceof \WP_Term || ! isset( Content::taxonomies()[ $term->taxonomy ] ) ) {
					throw new ToolError( 'taxonomy and object_id must name an existing term.' );
				}
				$args += array(
					'menu-item-object'    => $term->taxonomy,
					'menu-item-object-id' => $term->term_id,
				);
				break;
			default:
				if ( empty( $input['url'] ) || empty( $input['title'] ) ) {
					throw new ToolError( 'A custom link needs url and title.' );
				}
		}//end switch
		self::check_parent( (int) ( $input['parent_item'] ?? 0 ), $menu->term_id );
		$args += self::fields( $input );
		$id    = wp_update_nav_menu_item( $menu->term_id, 0, wp_slash( $args ) );
		if ( is_wp_error( $id ) ) {
			throw new ToolError( 'WordPress refused the item: ' . esc_html( $id->get_error_message() ) );
		}
		AuditLog::target( 'menu_item', (string) $id );
		return self::shape_id( $id );
	}

	/**
	 * Update an item, keeping every field that is not given.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the item is missing or WordPress refuses.
	 */
	private static function update( array $input ): array {
		$item = self::item( (int) $input['item_id'] );
		if ( null === $item ) {
			throw new ToolError( 'Menu item not found.' );
		}
		AuditLog::target( 'menu_item', (string) $item->ID );
		$menus = wp_get_object_terms( $item->ID, 'nav_menu', array( 'fields' => 'ids' ) );
		$menu  = is_array( $menus ) && $menus ? (int) $menus[0] : 0;
		if ( isset( $input['url'] ) && 'custom' !== ( self::props( $item )['type'] ?? '' ) ) {
			throw new ToolError( 'Only custom links have their own URL; links to posts and terms follow their target.' );
		}
		self::check_parent( (int) ( $input['parent_item'] ?? 0 ), $menu, $item->ID );
		$result = wp_update_nav_menu_item( $menu, $item->ID, wp_slash( self::fields( $input ) + self::args( $item ) ) );
		if ( is_wp_error( $result ) ) {
			throw new ToolError( 'WordPress refused the change: ' . esc_html( $result->get_error_message() ) );
		}
		return self::shape_id( $item->ID );
	}

	/**
	 * Remove an item.
	 *
	 * @param int $id Item id.
	 * @return array<string, mixed>
	 * @throws ToolError When the item is missing.
	 */
	private static function delete( int $id ): array {
		$item = self::item( $id );
		if ( null === $item ) {
			throw new ToolError( 'Menu item not found.' );
		}
		AuditLog::target( 'menu_item', (string) $item->ID );
		$shape = self::shape( $item );
		// Menu items skip the trash in wp-admin too.
		wp_delete_post( $item->ID, true );
		return $shape;
	}

	/**
	 * Assign a menu to a location (0 = none).
	 *
	 * @param string $location Location.
	 * @param int    $menu_id  Menu id.
	 * @return array<string, mixed>
	 * @throws ToolError When the location or menu is unknown.
	 */
	private static function assign( string $location, int $menu_id ): array {
		if ( ! isset( get_registered_nav_menus()[ $location ] ) ) {
			throw new ToolError( 'Unknown menu location. The active theme has: ' . esc_html( implode( ', ', array_keys( get_registered_nav_menus() ) ) ) . '.' );
		}
		if ( $menu_id > 0 && ! wp_get_nav_menu_object( $menu_id ) ) {
			throw new ToolError( 'Menu not found. See kuduy-menu-list.' );
		}
		$locations              = (array) get_theme_mod( 'nav_menu_locations', array() );
		$locations[ $location ] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );
		return array(
			'location' => $location,
			'menu_id'  => $menu_id,
		);
	}

	/**
	 * Menu item post, set up, or null.
	 *
	 * @param int $id Item id.
	 */
	private static function item( int $id ): ?\WP_Post {
		$post = $id > 0 && is_nav_menu_item( $id ) ? get_post( $id ) : null;
		return $post instanceof \WP_Post ? wp_setup_nav_menu_item( $post ) : null;
	}

	/**
	 * Parent must be another item of the same menu.
	 *
	 * @param int $parent_id Parent item id (0 = none).
	 * @param int $menu      Menu id.
	 * @param int $current   Item being edited.
	 * @throws ToolError When the parent is invalid.
	 */
	private static function check_parent( int $parent_id, int $menu, int $current = 0 ): void {
		if ( 0 === $parent_id ) {
			return;
		}
		$menus = is_nav_menu_item( $parent_id ) ? wp_get_object_terms( $parent_id, 'nav_menu', array( 'fields' => 'ids' ) ) : array();
		if ( $parent_id === $current || ! is_array( $menus ) || ! in_array( $menu, array_map( 'intval', $menus ), true ) ) {
			throw new ToolError( 'parent_item must be another item of the same menu.' );
		}
	}

	/**
	 * Given fields in wp_update_nav_menu_item() names.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private static function fields( array $input ): array {
		$out = array();
		foreach ( self::ITEM_FIELDS as $key => $field ) {
			if ( array_key_exists( $key, $input ) ) {
				$out[ $field ] = $input[ $key ];
			}
		}
		return $out;
	}

	/**
	 * Full current args of an item (wp_update_nav_menu_item() resets anything not passed).
	 *
	 * @param \WP_Post $item Set-up menu item.
	 * @return array<string, mixed>
	 */
	private static function args( \WP_Post $item ): array {
		$p = self::props( $item );
		return array(
			'menu-item-object-id'   => (int) ( $p['object_id'] ?? 0 ),
			'menu-item-object'      => (string) ( $p['object'] ?? '' ),
			'menu-item-parent-id'   => (int) ( $p['menu_item_parent'] ?? 0 ),
			'menu-item-position'    => $item->menu_order,
			'menu-item-type'        => (string) ( $p['type'] ?? 'custom' ),
			'menu-item-title'       => $item->post_title,
			'menu-item-url'         => 'custom' === ( $p['type'] ?? '' ) ? (string) ( $p['url'] ?? '' ) : '',
			'menu-item-description' => $item->post_content,
			'menu-item-attr-title'  => $item->post_excerpt,
			'menu-item-target'      => (string) ( $p['target'] ?? '' ),
			'menu-item-classes'     => implode( ' ', (array) ( $p['classes'] ?? array() ) ),
			'menu-item-xfn'         => (string) ( $p['xfn'] ?? '' ),
			'menu-item-status'      => 'publish',
		);
	}

	/**
	 * Properties wp_setup_nav_menu_item() adds to a menu item post (type, object, object_id, url, target, ...).
	 *
	 * @param \WP_Post $item Set-up menu item.
	 * @return array<string, mixed>
	 */
	private static function props( \WP_Post $item ): array {
		return get_object_vars( $item );
	}

	/**
	 * Menu for output.
	 *
	 * @param \WP_Term                 $menu     Menu term.
	 * @param array<string|int, mixed> $assigned Location => menu id.
	 * @return array<string, mixed>
	 */
	private static function menu( \WP_Term $menu, array $assigned ): array {
		return array(
			'id'        => $menu->term_id,
			'name'      => $menu->name,
			'slug'      => $menu->slug,
			'items'     => $menu->count,
			'locations' => array_map( 'strval', array_keys( array_filter( $assigned, static fn( $id ) => (int) $id === $menu->term_id ) ) ),
		);
	}

	/**
	 * Item for output by id.
	 *
	 * @param int $id Item id.
	 * @return array<string, mixed>
	 * @throws ToolError When the item cannot be read back.
	 */
	private static function shape_id( int $id ): array {
		$item = self::item( $id );
		if ( null === $item ) {
			throw new ToolError( 'The menu item was saved but could not be read back.' );
		}
		return self::shape( $item );
	}

	/**
	 * Item for output.
	 *
	 * @param \WP_Post $item Set-up menu item.
	 * @return array<string, mixed>
	 */
	private static function shape( \WP_Post $item ): array {
		$p = self::props( $item );
		return array(
			'id'          => $item->ID,
			'title'       => (string) ( $p['title'] ?? $item->post_title ),
			'type'        => (string) ( $p['type'] ?? '' ),
			'object'      => (string) ( $p['object'] ?? '' ),
			'object_id'   => (int) ( $p['object_id'] ?? 0 ),
			'url'         => (string) ( $p['url'] ?? '' ),
			'parent_item' => (int) ( $p['menu_item_parent'] ?? 0 ),
			'position'    => $item->menu_order,
			'target'      => (string) ( $p['target'] ?? '' ),
		);
	}
}
