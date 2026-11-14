<?php
/**
 * Tools: kuduy-beaver-edit, kuduy-beaver-create (one class, two modes).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Beaver;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Write side of the Beaver Builder tools (D37). A call works like a builder session: the published layout is copied
 * to the draft, every operation runs on the draft through FLBuilderModel's own functions, and only when all of them
 * succeeded FLBuilderModel::save_layout( false ) publishes the draft (layout data, post content for search, CSS/JS
 * cache) without changing the post status. A failing operation throws the draft away, so nothing is saved. A layout
 * with unpublished changes from the builder itself is refused, because the draft would be overwritten. Shared
 * (global) nodes are refused: they change other pages.
 */
final class BeaverWrite implements Tool, Snapshottable {

	private const MAX_OPERATIONS = 50;

	/**
	 * Mode.
	 *
	 * @param string $mode edit | create.
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
		return 'edit' === $this->mode ? __( 'Edit a Beaver Builder page', 'kuduy-mcp' ) : __( 'Create a Beaver Builder page', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'edit' === $this->mode
			? 'Applies 1–50 operations to one Beaver Builder layout and publishes the layout once: add_row (cols: 1-col … 6-cols or a sidebar layout; optional modules per column), add_columns (a new column group in a row), add_module (into a column), update (settings of a row, column or module; only the named settings change), move (a module to a column and position, or a row to a position), duplicate, remove. Node ids come from kuduy-beaver-get; settings names from kuduy-beaver-module-schema. If any operation fails, nothing is saved. Refused while the page has unpublished changes in the builder, and for shared (global) rows and modules. The post status does not change. Snapshot first; undo with kuduy-snapshot-restore.'
			: 'Creates a page (or another post type Beaver Builder is enabled for) built with Beaver Builder, as a draft by default, from rows: each row has a column layout and, per column, a list of modules with settings, e.g. {"cols": "2-cols", "columns": [[{"module": "heading", "settings": {"heading": "Hello"}}], [{"module": "rich-text", "settings": {"text": "<p>Hi</p>"}}]]}.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$columns = array(
			'type'        => 'array',
			'maxItems'    => 6,
			'description' => 'One list of modules per column, in column order: [[{"module": "heading", "settings": {...}}], [...]].',
			'items'       => array(
				'type'     => 'array',
				'maxItems' => 30,
				'items'    => array(
					'type'       => 'object',
					'properties' => array(
						'module'   => array( 'type' => 'string' ),
						'settings' => array( 'type' => 'object' ),
					),
					'required'   => array( 'module' ),
				),
			),
		);
		$cols    = array(
			'type'    => 'string',
			'enum'    => Beaver::LAYOUTS,
			'default' => '1-col',
		);
		$props   = 'edit' === $this->mode
			? array(
				'id'         => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'operations' => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => self::MAX_OPERATIONS,
					'items'    => array(
						'type'       => 'object',
						'properties' => array(
							'action'    => array(
								'type' => 'string',
								'enum' => array( 'add_row', 'add_columns', 'add_module', 'update', 'move', 'duplicate', 'remove' ),
							),
							'node_id'   => array(
								'type'        => 'string',
								'description' => 'Target node (update, move, duplicate, remove); the row for add_columns.',
							),
							'parent_id' => array(
								'type'        => 'string',
								'description' => 'add_module / move: the column that receives the module.',
							),
							'position'  => array(
								'type'    => 'integer',
								'minimum' => 0,
							),
							'cols'      => $cols,
							'columns'   => $columns,
							'module'    => array(
								'type'        => 'string',
								'description' => 'add_module: module slug from kuduy-beaver-modules.',
							),
							'settings'  => array( 'type' => 'object' ),
						),
						'required'   => array( 'action' ),
					),
				),
			)
			: array(
				'title'     => array(
					'type'      => 'string',
					'minLength' => 1,
				),
				'post_type' => array(
					'type'    => 'string',
					'default' => 'page',
				),
				'status'    => array(
					'type'    => 'string',
					'enum'    => array( 'draft', 'publish', 'pending', 'private' ),
					'default' => 'draft',
				),
				'rows'      => array(
					'type'     => 'array',
					'maxItems' => 30,
					'items'    => array(
						'type'       => 'object',
						'properties' => array(
							'cols'    => $cols,
							'columns' => $columns,
						),
					),
				),
			);
		return array(
			'type'                 => 'object',
			'properties'           => $props,
			'required'             => 'edit' === $this->mode ? array( 'id', 'operations' ) : array( 'title' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'           => array( 'type' => 'integer' ),
				'node_count'   => array( 'type' => 'integer' ),
				'changed'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'link'         => array( 'type' => 'string' ),
				'edit_url'     => array( 'type' => 'string' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'id', 'changed' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => 'edit' === $this->mode,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:write';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		if ( 'edit' === $this->mode ) {
			$id = (int) ( $input['id'] ?? 0 );
			return $id > 0 && get_post( $id ) ? array( 'edit_post', $id ) : 'edit_posts';
		}
		$type = get_post_type_object( (string) ( $input['post_type'] ?? 'page' ) );
		return $type ? (string) $type->cap->create_posts : 'edit_pages';
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
	 */
	public function snapshot( array $input ): ?array {
		$id   = (int) ( $input['id'] ?? 0 );
		$post = 'edit' === $this->mode && $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		return array(
			'object_type' => 'post',
			'object_id'   => (string) $id,
			'payload'     => array(
				'id'       => $id,
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Beaver Builder's node objects, kept exactly; read back with allowed_classes limited to stdClass.
				'data'     => base64_encode( serialize( get_post_meta( $id, '_fl_builder_data', true ) ) ),
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- as above.
				'settings' => base64_encode( serialize( get_post_meta( $id, '_fl_builder_data_settings', true ) ) ),
				'content'  => $post->post_content,
			),
		);
	}

	/**
	 * Stored value of a snapshot field.
	 *
	 * @param mixed $packed Packed value.
	 * @return mixed
	 */
	private static function unpack( $packed ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reverses snapshot().
		$raw = is_string( $packed ) ? base64_decode( $packed, true ) : false;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- our own snapshot; only stdClass objects are allowed.
		return false === $raw ? null : unserialize( $raw, array( 'allowed_classes' => array( 'stdClass' ) ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the post is gone.
	 */
	public function restore( array $payload ): void {
		$id   = (int) ( $payload['id'] ?? 0 );
		$post = Beaver::post( $id, false );
		$data = self::unpack( $payload['data'] ?? null );
		$data = is_array( $data ) ? $data : array();
		Beaver::in(
			$id,
			static function () use ( $id, $data, $payload, $post ): void {
				\FLBuilderModel::update_layout_data( $data, 'published', $id );
				\FLBuilderModel::update_layout_data( $data, 'draft', $id );
				$settings = self::unpack( $payload['settings'] ?? null );
				if ( is_object( $settings ) || is_array( $settings ) ) {
					\FLBuilderModel::update_layout_settings( $settings, 'published', $id );
					\FLBuilderModel::update_layout_settings( $settings, 'draft', $id );
				}
				wp_update_post(
					wp_slash(
						array(
							'ID'           => $post->ID,
							'post_content' => (string) ( $payload['content'] ?? '' ),
						)
					)
				);
				\FLBuilderModel::delete_all_asset_cache( $id );
			}
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When an operation is invalid or Beaver Builder refuses.
	 */
	public function execute( array $input ): array {
		return 'edit' === $this->mode ? self::edit( $input ) : self::create( $input );
	}

	/**
	 * Apply operations to the draft and publish the layout.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When an operation is invalid.
	 */
	private static function edit( array $input ): array {
		$id = (int) $input['id'];
		Beaver::post( $id );
		AuditLog::target( 'post', (string) $id );
		$published = Beaver::nodes( $id );
		$draft     = Beaver::nodes( $id, 'draft' );
		// phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- node objects are compared by value.
		if ( $draft && $draft != $published ) {
			throw new ToolError( 'This page has unpublished changes in Beaver Builder. Publish or discard them in the builder first; nothing was changed.' );
		}
		foreach ( $published as $node ) {
			$type = Beaver::module_type( $node );
			if ( '' !== $type && null === Beaver::module( $type ) ) {
				throw new ToolError( 'This page uses the module "' . esc_html( $type ) . '", which is not available right now (its plugin is inactive). Nothing was changed.' );
			}
		}
		return Beaver::in(
			$id,
			static function () use ( $id, $input ): array {
				\FLBuilderModel::update_layout_data( \FLBuilderModel::get_layout_data( 'published', $id ), 'draft', $id );
				\FLBuilderModel::update_layout_settings( \FLBuilderModel::get_layout_settings( 'published', $id ), 'draft', $id );
				$changed = array();
				foreach ( array_values( (array) $input['operations'] ) as $n => $op ) {
					try {
						$changed[] = self::apply( (array) $op );
					} catch ( ToolError $e ) {
						\FLBuilderModel::update_layout_data( \FLBuilderModel::get_layout_data( 'published', $id ), 'draft', $id );
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- JSON text; the inner message is escaped where it is built.
						throw new ToolError( sprintf( 'Operation %d (%s): %s Nothing was saved.', $n + 1, esc_html( (string) ( $op['action'] ?? '' ) ), $e->getMessage() ) );
					}
				}
				return self::publish( $id, $changed );
			}
		);
	}

	/**
	 * Publish the draft layout and report.
	 *
	 * @param int          $id      Post id.
	 * @param list<string> $changed Changes.
	 * @return array<string, mixed>
	 */
	private static function publish( int $id, array $changed ): array {
		\FLBuilderModel::save_layout( false );
		return array(
			'id'         => $id,
			'node_count' => count( Beaver::nodes( $id ) ),
			'changed'    => $changed,
			'link'       => (string) get_permalink( $id ),
			'edit_url'   => (string) \FLBuilderModel::get_edit_url( $id ),
		);
	}

	/**
	 * A node of the draft that may be changed.
	 *
	 * @param string $id Node id.
	 * @throws ToolError When it is missing or shared.
	 */
	private static function node( string $id ): object {
		$data = (array) \FLBuilderModel::get_layout_data();
		$node = '' !== $id ? ( $data[ $id ] ?? null ) : null;
		if ( ! is_object( $node ) ) {
			throw new ToolError( 'No node with id "' . esc_html( $id ) . '" on this page. See kuduy-beaver-get.' );
		}
		if ( ! empty( $node->template_id ) ) {
			throw new ToolError( 'Node "' . esc_html( $id ) . '" is a shared (global) row or module; changing it changes other pages. Edit it in Beaver Builder.' );
		}
		return $node;
	}

	/**
	 * A module's full settings from input: registered, enabled, known names, defaults filled in.
	 *
	 * @param string               $type Module slug.
	 * @param array<string, mixed> $set  Settings.
	 * @throws ToolError When the module or settings are invalid.
	 */
	private static function module_settings( string $type, array $set ): object {
		Beaver::check( $type, $set );
		$module = Beaver::module( $type );
		if ( null === $module || empty( $module->enabled ) ) {
			throw new ToolError( 'Module "' . esc_html( $type ) . '" is switched off on this site. See kuduy-beaver-modules.' );
		}
		return (object) array_merge( Beaver::defaults( $type ), $set );
	}

	/**
	 * Put modules into the columns of a column group, in order.
	 *
	 * @param object            $group   Column group node.
	 * @param array<int, mixed> $columns Modules per column.
	 * @return list<string> Column ids.
	 * @throws ToolError When there are more lists than columns or a module is invalid.
	 */
	private static function fill( object $group, array $columns ): array {
		$cols = array_values( (array) \FLBuilderModel::get_nodes( 'column', $group->node ) );
		if ( count( $columns ) > count( $cols ) ) {
			throw new ToolError( 'columns has ' . count( $columns ) . ' lists but this layout has ' . count( $cols ) . ' columns.' );
		}
		foreach ( array_values( $columns ) as $i => $modules ) {
			foreach ( (array) $modules as $item ) {
				$type = (string) ( $item['module'] ?? '' );
				if ( false === \FLBuilderModel::add_module( $type, self::module_settings( $type, (array) ( $item['settings'] ?? array() ) ), $cols[ $i ]->node ) ) {
					throw new ToolError( 'Beaver Builder could not add the module "' . esc_html( $type ) . '".' );
				}
			}
		}
		return array_map( static fn( object $col ): string => (string) $col->node, $cols );
	}

	/**
	 * Add a row with columns and modules.
	 *
	 * @param array<string, mixed> $spec cols, columns, position.
	 * @return string What changed.
	 * @throws ToolError When the layout or a module is invalid.
	 */
	private static function add_row( array $spec ): string {
		$cols = (string) ( $spec['cols'] ?? '1-col' );
		if ( ! in_array( $cols, Beaver::LAYOUTS, true ) ) {
			throw new ToolError( 'Unknown column layout "' . esc_html( $cols ) . '". Use one of: ' . esc_html( implode( ', ', Beaver::LAYOUTS ) ) . '.' );
		}
		$row    = \FLBuilderModel::add_row( $cols, isset( $spec['position'] ) ? (int) $spec['position'] : false );
		$groups = array_values( (array) \FLBuilderModel::get_nodes( 'column-group', $row->node ) );
		if ( ! is_object( $row ) || ! $groups ) {
			throw new ToolError( 'Beaver Builder could not add the row.' );
		}
		return 'added row ' . $row->node . ' (columns ' . implode( ', ', self::fill( $groups[0], (array) ( $spec['columns'] ?? array() ) ) ) . ')';
	}

	/**
	 * One operation on the draft.
	 *
	 * @param array<string, mixed> $op Operation.
	 * @return string What changed.
	 * @throws ToolError When the operation is invalid.
	 */
	private static function apply( array $op ): string {
		$action   = (string) ( $op['action'] ?? '' );
		$id       = (string) ( $op['node_id'] ?? '' );
		$position = isset( $op['position'] ) ? (int) $op['position'] : null;

		switch ( $action ) {
			case 'add_row':
				return self::add_row( $op );
			case 'add_columns':
				$row = self::node( $id );
				if ( 'row' !== $row->type ) {
					throw new ToolError( 'add_columns needs the id of a row in node_id.' );
				}
				$cols = (string) ( $op['cols'] ?? '1-col' );
				if ( ! in_array( $cols, Beaver::LAYOUTS, true ) ) {
					throw new ToolError( 'Unknown column layout "' . esc_html( $cols ) . '".' );
				}
				$group = \FLBuilderModel::add_col_group( $id, $cols, null === $position ? false : $position );
				if ( ! is_object( $group ) ) {
					throw new ToolError( 'Beaver Builder could not add the columns.' );
				}
				return 'added columns ' . implode( ', ', self::fill( $group, (array) ( $op['columns'] ?? array() ) ) ) . ' to row ' . $id;
			case 'add_module':
				$parent = self::node( (string) ( $op['parent_id'] ?? '' ) );
				if ( 'column' !== $parent->type ) {
					throw new ToolError( 'parent_id must be a column. See kuduy-beaver-get.' );
				}
				$type   = (string) ( $op['module'] ?? '' );
				$module = \FLBuilderModel::add_module( $type, self::module_settings( $type, (array) ( $op['settings'] ?? array() ) ), $parent->node, null === $position ? false : $position );
				if ( ! is_object( $module ) ) {
					throw new ToolError( 'Beaver Builder could not add the module.' );
				}
				return 'added ' . $type . ' module ' . $module->node;
			case 'update':
				$node = self::node( $id );
				$kind = Beaver::kind( $node );
				$set  = (array) ( $op['settings'] ?? array() );
				if ( '' === $kind || ! $set ) {
					throw new ToolError( 'update needs a row, column or module and at least one setting.' );
				}
				if ( 'column' === $kind && isset( $set['size'] ) ) {
					throw new ToolError( 'Column widths depend on each other; resize columns in Beaver Builder.' );
				}
				Beaver::check( $kind, $set );
				$data                  = (array) \FLBuilderModel::get_layout_data();
				$data[ $id ]->settings = \FLBuilderModel::process_node_settings( $data[ $id ], (object) array_merge( (array) $data[ $id ]->settings, $set ) );
				\FLBuilderModel::update_layout_data( $data );
				return 'updated ' . $id;
			case 'move':
				$node = self::node( $id );
				if ( 'row' === $node->type ) {
					\FLBuilderModel::reorder_node( $id, (int) $position );
					return 'moved row ' . $id . ' to position ' . (int) $position;
				}
				if ( 'module' !== $node->type ) {
					throw new ToolError( 'Only modules and rows can be moved; columns are arranged in Beaver Builder.' );
				}
				$parent = self::node( (string) ( $op['parent_id'] ?? $node->parent ) );
				if ( 'column' !== $parent->type ) {
					throw new ToolError( 'parent_id must be a column.' );
				}
				\FLBuilderModel::move_node( $id, $parent->node, (int) $position );
				return 'moved ' . $id . ' to column ' . $parent->node;
			case 'duplicate':
				$node = self::node( $id );
				$copy = match ( (string) $node->type ) {
					'row'    => \FLBuilderModel::copy_row( $id ),
					'column' => \FLBuilderModel::copy_col( $id ),
					'module' => \FLBuilderModel::copy_module( $id ),
					default  => null,
				};
				if ( ! is_object( $copy ) ) {
					throw new ToolError( 'Only rows, columns and modules can be duplicated.' );
				}
				return 'duplicated ' . $id . ' as ' . $copy->node;
			case 'remove':
				$node = self::node( $id );
				if ( 'column' === $node->type ) {
					\FLBuilderModel::delete_col( $id );
				} else {
					\FLBuilderModel::delete_node( $id );
				}
				return 'removed ' . $id;
		}//end switch
		throw new ToolError( 'Unknown action.' );
	}

	/**
	 * Create a post with a Beaver Builder layout.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the post type, status or a row is invalid.
	 */
	private static function create( array $input ): array {
		$type = get_post_type_object( (string) ( $input['post_type'] ?? 'page' ) );
		if ( ! $type || ! in_array( $type->name, Beaver::post_types(), true ) || 'fl-builder-template' === $type->name ) {
			throw new ToolError( 'Beaver Builder is not enabled for this post type. See kuduy-beaver-status.' );
		}
		if ( ! \FLBuilderUserAccess::current_user_can( 'builder_access' ) ) {
			throw new ToolError( 'Beaver Builder does not allow your role to use the builder (Settings > Beaver Builder > User Access).' );
		}
		$status = (string) ( $input['status'] ?? 'draft' );
		if ( in_array( $status, array( 'publish', 'private' ), true ) && ! current_user_can( $type->cap->publish_posts ) ) {
			throw new ToolError( 'Your account cannot publish this post type; create it as a draft.' );
		}
		// Check every module before anything is created.
		foreach ( (array) ( $input['rows'] ?? array() ) as $row ) {
			foreach ( (array) ( $row['columns'] ?? array() ) as $modules ) {
				foreach ( (array) $modules as $item ) {
					self::module_settings( (string) ( $item['module'] ?? '' ), (array) ( $item['settings'] ?? array() ) );
				}
			}
		}
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'   => $type->name,
					'post_title'  => (string) $input['title'],
					'post_status' => $status,
					'post_author' => get_current_user_id(),
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new ToolError( 'WordPress refused the post: ' . esc_html( $id->get_error_message() ) );
		}
		AuditLog::target( 'post', (string) $id );
		return Beaver::in(
			$id,
			static function () use ( $id, $input ): array {
				$changed = array( 'created ' . $id );
				foreach ( (array) ( $input['rows'] ?? array() ) as $row ) {
					$changed[] = self::add_row( array_diff_key( (array) $row, array( 'position' => true ) ) );
				}
				return self::publish( $id, $changed );
			}
		);
	}
}
