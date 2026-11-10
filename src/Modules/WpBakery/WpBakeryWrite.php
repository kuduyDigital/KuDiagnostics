<?php
/**
 * Tools: kuduy-wpbakery-create, -add-element, -add-row, -add-column, -update-element, -update-row, -update-column,
 * -delete-element, -move-element, -duplicate-element, -replace.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\WpBakery;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;
use Kuduy\Mcp\Modules\Core\PostWriter;

/**
 * WPBakery Page Builder editing. Every tool parses the page, changes only the addressed part of the tree, validates
 * the result and saves through wp_update_post() (revision, WordPress's own content filter, save hooks); a layout with
 * structure errors is never saved. Untouched elements, including unknown and third-party shortcodes, keep their
 * original text. Each change to an existing page is snapshotted (content and WPBakery's page meta) for undo; a new
 * page is undone with kuduy-post-trash. Pages built with Elementor are refused.
 */
final class WpBakeryWrite implements Tool, Snapshottable {

	/**
	 * Mode.
	 *
	 * @param string $mode create | add-element | add-row | add-column | update-element | update-row | update-column |
	 *                     delete-element | move-element | duplicate-element | replace.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-wpbakery-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'create'            => __( 'Create a WPBakery page', 'kuduy-mcp' ),
			'add-element'       => __( 'Add a WPBakery element', 'kuduy-mcp' ),
			'add-row'           => __( 'Add a WPBakery row', 'kuduy-mcp' ),
			'add-column'        => __( 'Add a WPBakery column', 'kuduy-mcp' ),
			'update-element'    => __( 'Change a WPBakery element', 'kuduy-mcp' ),
			'update-row'        => __( 'Change a WPBakery row', 'kuduy-mcp' ),
			'update-column'     => __( 'Change a WPBakery column', 'kuduy-mcp' ),
			'delete-element'    => __( 'Remove a WPBakery element', 'kuduy-mcp' ),
			'move-element'      => __( 'Move a WPBakery element', 'kuduy-mcp' ),
			'duplicate-element' => __( 'Duplicate a WPBakery element', 'kuduy-mcp' ),
			default             => __( 'Replace a WPBakery layout', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		$element = ' An element is {"tag": "vc_column_text", "attrs": {...}, "content": "text or HTML"} or with "children": [elements] for containers; see kuduy-wpbakery-elements for tags and settings. In attrs, links are objects (url, title, target, rel), images are media ids, repeating groups are lists of objects. "design" sets margins, padding, borders and background (Design Options).';
		$after   = ' Returns the new tree with ids (ids change after every edit). The result is validated first; a layout with errors is not saved. Snapshot first; undo with kuduy-snapshot-restore.';
		return match ( $this->mode ) {
			'create'            => 'Creates a page (or post) built with WPBakery Page Builder, as a draft unless status says otherwise. Give the layout as rows (each with a layout such as "1/2 + 1/2" or columns with width, design, responsive and elements), or as content (raw WPBakery shortcodes). Optional slug, template, parent.' . $element . ' The layout is validated first. Returns the page id, edit link and tree. Set SEO fields with kuduy-seo-update. Undo with kuduy-post-trash.',
			'add-element'       => 'Adds an element to a WPBakery page: into a column or other container (parent_id), at a position (index, default last). Without parent_id the element is wrapped in a new full-width row at the top level.' . $element . $after,
			'add-row'           => 'Adds a row to a WPBakery page: at the top level, inside a section, or inside a column (parent_id; it then becomes an inner row). Columns come from layout ("1/1", "1/2 + 1/2", "1/3 + 2/3", "1/4 + 1/4 + 1/2", any fractions) or from columns (width, attrs, design, responsive, elements). Row settings go in attrs (e.g. full_width: stretch_row, stretch_row_content; gap; equal_height; el_class) and design (background colour or image, padding, margin, border).' . $element . $after,
			'add-column'        => 'Adds a column to an existing row (row_id) at a position (index, default last): width such as 1/3, responsive widths per screen size (lg, md, sm, xs) and sizes to hide on, attrs, design and elements. The other columns keep their widths; change them with kuduy-wpbakery-update-column or kuduy-wpbakery-update-row layout.' . $element . $after,
			'update-element'    => 'Changes one element of a WPBakery page: attrs to set (null removes one), content (text or HTML of the element), design. Only that element is rewritten; everything else on the page keeps its exact text. Pass tag to make sure the id still points at the element you mean.' . $after,
			'update-row'        => 'Changes a row (or inner row): attrs (full width, gap, height, classes, id, ...), design (background, padding, margin, border), and layout to set the widths of its existing columns (e.g. "1/2 + 1/2" for a row with two columns).' . $after,
			'update-column'     => 'Changes a column: width (e.g. 1/3), responsive (width per screen size lg, md, sm, xs and hide: [sizes]), attrs, design (background, padding, margin, border).' . $after,
			'delete-element'    => 'Removes one item from a WPBakery page (element, column or row) with everything inside it; the surrounding layout stays intact. Removing the last column of a row is refused: remove the row.' . $after,
			'move-element'      => 'Moves an item of a WPBakery page (element, row or column) with everything inside it to another parent (to_parent_id; omit for the top level) and position (index, default last), or reorders it within its parent.' . $after,
			'duplicate-element' => 'Duplicates an item of a WPBakery page (element, column or row) with its attributes and everything inside it; the copy is placed right after the original.' . $after,
			default             => 'Replaces the whole WPBakery layout of a page with rows (see kuduy-wpbakery-add-row) or content (raw WPBakery shortcodes). The new layout is validated first; with errors nothing is saved.' . $element . $after,
		};
	}

	/**
	 * Schema pieces shared by the modes.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function pieces(): array {
		$element = array(
			'type'       => 'object',
			'properties' => array(
				'tag'      => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 64,
				),
				'attrs'    => array( 'type' => 'object' ),
				'design'   => array( 'type' => 'object' ),
				'content'  => array( 'type' => 'string' ),
				'children' => array(
					'type'     => 'array',
					'maxItems' => 200,
					'items'    => array( 'type' => 'object' ),
				),
			),
			'required'   => array( 'tag' ),
		);
		$id      = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 100,
		);
		return array(
			'post_id'     => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'id'          => $id,
			'element'     => $element,
			'elements'    => array(
				'type'     => 'array',
				'maxItems' => 200,
				'items'    => $element,
			),
			'attrs'       => array(
				'type'        => 'object',
				'description' => 'Settings by name (see kuduy-wpbakery-elements with tag). null removes a setting.',
			),
			'design'      => array(
				'type'        => 'object',
				'description' => 'Design Options: margin_top/right/bottom/left, padding_*, border_*_width, border_color, border_style, border_radius, background_color, background_image (media id), background_size, background_repeat, background_position. Numbers are px. null removes a value.',
			),
			'responsive'  => array(
				'type'        => 'object',
				'description' => 'Width per screen size, e.g. {"md": "1/2", "xs": "1/1", "hide": ["xs"]}. Sizes: lg, md, sm, xs.',
			),
			'width'       => array(
				'type'        => 'string',
				'maxLength'   => 5,
				'description' => 'Fraction of the row: 1/1, 1/2, 1/3, 2/3, 1/4, 3/4, 1/5, 1/6, 5/12, ...',
			),
			'layout'      => array(
				'type'        => 'string',
				'maxLength'   => 100,
				'description' => 'Column widths joined by +, e.g. "1/2 + 1/2", "1/3 + 2/3", "1/4 + 1/4 + 1/2".',
			),
			'columns'     => array(
				'type'     => 'array',
				'maxItems' => 12,
				'items'    => array(
					'type'       => 'object',
					'properties' => array(
						'width'      => array( 'type' => 'string' ),
						'attrs'      => array( 'type' => 'object' ),
						'design'     => array( 'type' => 'object' ),
						'responsive' => array( 'type' => 'object' ),
						'elements'   => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
				),
			),
			'index'       => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => 'Position among the parent\'s items, 0 = first. Default: last.',
			),
			'tag'         => array(
				'type'        => 'string',
				'maxLength'   => 64,
				'description' => 'Expected tag of the item, e.g. vc_btn. The call is refused when the id points at something else.',
			),
			'if_hash'     => array(
				'type'        => 'string',
				'maxLength'   => 32,
				'description' => 'Hash from kuduy-wpbakery-tree; the call is refused when the page changed since.',
			),
			'ignore_lock' => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => 'Save although another user has the page open in the editor.',
			),
			'content'     => array(
				'type'      => 'string',
				'maxLength' => Shortcodes::MAX_BYTES,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$p                         = self::pieces();
		$common                    = array(
			'post_id'     => $p['post_id'],
			'if_hash'     => $p['if_hash'],
			'ignore_lock' => $p['ignore_lock'],
		);
		$rows                      = array(
			'type'     => 'array',
			'maxItems' => 100,
			'items'    => array(
				'type'       => 'object',
				'properties' => array(
					'layout'  => $p['layout'],
					'columns' => $p['columns'],
					'attrs'   => array( 'type' => 'object' ),
					'design'  => array( 'type' => 'object' ),
				),
			),
		);
		[ $properties, $required ] = match ( $this->mode ) {
			'create'            => array(
				array(
					'title'     => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 300,
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
					'slug'      => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'parent'    => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'template'  => array(
						'type'        => 'string',
						'maxLength'   => 200,
						'description' => 'Page template file of the theme, e.g. "templates/full-width.php"; "default" for the default template.',
					),
					'rows'      => $rows,
					'content'   => $p['content'],
				),
				array( 'title' ),
			),
			'add-element'       => array(
				$common + array(
					'parent_id' => $p['id'],
					'index'     => $p['index'],
					'element'   => $p['element'],
				),
				array( 'post_id', 'element' ),
			),
			'add-row'           => array(
				$common + array(
					'parent_id' => $p['id'],
					'index'     => $p['index'],
					'layout'    => $p['layout'],
					'columns'   => $p['columns'],
					'attrs'     => $p['attrs'],
					'design'    => $p['design'],
				),
				array( 'post_id' ),
			),
			'add-column'        => array(
				$common + array(
					'row_id'     => $p['id'],
					'index'      => $p['index'],
					'width'      => $p['width'],
					'responsive' => $p['responsive'],
					'attrs'      => $p['attrs'],
					'design'     => $p['design'],
					'elements'   => $p['elements'],
				),
				array( 'post_id', 'row_id' ),
			),
			'update-element'    => array(
				$common + array(
					'element_id' => $p['id'],
					'tag'        => $p['tag'],
					'attrs'      => $p['attrs'],
					'design'     => $p['design'],
					'content'    => $p['content'],
				),
				array( 'post_id', 'element_id' ),
			),
			'update-row'        => array(
				$common + array(
					'row_id' => $p['id'],
					'attrs'  => $p['attrs'],
					'design' => $p['design'],
					'layout' => $p['layout'],
				),
				array( 'post_id', 'row_id' ),
			),
			'update-column'     => array(
				$common + array(
					'column_id'  => $p['id'],
					'width'      => $p['width'],
					'responsive' => $p['responsive'],
					'attrs'      => $p['attrs'],
					'design'     => $p['design'],
				),
				array( 'post_id', 'column_id' ),
			),
			'move-element'      => array(
				$common + array(
					'element_id'   => $p['id'],
					'tag'          => $p['tag'],
					'to_parent_id' => $p['id'],
					'index'        => $p['index'],
				),
				array( 'post_id', 'element_id' ),
			),
			'replace'           => array(
				$common + array(
					'rows'    => $rows,
					'content' => $p['content'],
				),
				array( 'post_id' ),
			),
			default             => array(
				$common + array(
					'element_id' => $p['id'],
					'tag'        => $p['tag'],
				),
				array( 'post_id', 'element_id' ),
			),
		};//end match
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
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
				'post_id'      => array( 'type' => 'integer' ),
				'created'      => array( 'type' => 'boolean' ),
				'status'       => array( 'type' => 'string' ),
				'element_id'   => array( 'type' => 'string' ),
				'hash'         => array( 'type' => 'string' ),
				'elements'     => array( 'type' => 'integer' ),
				'tree'         => array( 'type' => 'string' ),
				'warnings'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'edit_url'     => array( 'type' => 'string' ),
				'link'         => array( 'type' => 'string' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'post_id', 'hash', 'tree' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => 'create' !== $this->mode,
			'idempotentHint'  => in_array( $this->mode, array( 'update-element', 'update-row', 'update-column', 'replace' ), true ),
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
		if ( 'create' === $this->mode ) {
			$type = Content::post_types()[ (string) ( $input['post_type'] ?? 'page' ) ] ?? null;
			return null === $type ? 'edit_posts' : ( PostWriter::status_cap( $type, $input['status'] ?? null ) ?? $type->cap->create_posts );
		}
		$post = Content::post( (int) ( $input['post_id'] ?? 0 ) );
		return null === $post ? 'edit_posts' : array( 'edit_post', $post->ID );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return WpBakery::available();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'create' === $this->mode ) {
			return null;
		}
		$post = WpBakery::post( (int) ( $input['post_id'] ?? 0 ), true );
		return array(
			'object_type' => $post->post_type,
			'object_id'   => (string) $post->ID,
			'payload'     => array(
				'id'      => $post->ID,
				'content' => $post->post_content,
				'status'  => (string) get_post_meta( $post->ID, WpBakery::META_STATUS, true ),
				'css'     => (string) get_post_meta( $post->ID, WpBakery::META_CSS, true ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the post is gone or WordPress refuses.
	 */
	public function restore( array $payload ): void {
		$id = (int) ( $payload['id'] ?? 0 );
		if ( ! get_post( $id ) ) {
			throw new ToolError( 'The page no longer exists, so this change cannot be undone.' );
		}
		$result = wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => wp_slash( (string) ( $payload['content'] ?? '' ) ),
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			throw new ToolError( 'Undo failed: ' . esc_html( $result->get_error_message() ) );
		}
		foreach ( array(
			WpBakery::META_STATUS => (string) ( $payload['status'] ?? '' ),
			WpBakery::META_CSS    => (string) ( $payload['css'] ?? '' ),
		) as $key => $value ) {
			if ( '' === $value ) {
				delete_post_meta( $id, $key );
			} else {
				update_post_meta( $id, $key, wp_slash( $value ) );
			}
		}
	}

	/**
	 * Rows from tool input as nodes.
	 *
	 * @param array<string, mixed> $input rows or content.
	 * @return list<array<string, mixed>>
	 * @throws ToolError When neither is given or a row is malformed.
	 */
	private static function layout( array $input ): array {
		if ( isset( $input['rows'] ) && isset( $input['content'] ) ) {
			throw new ToolError( 'Give the layout either as rows or as content (shortcodes), not both.' );
		}
		if ( isset( $input['content'] ) ) {
			return WpBakery::parse( (string) $input['content'] );
		}
		$nodes = array();
		foreach ( (array) ( $input['rows'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				throw new ToolError( 'Each row is an object: layout or columns, and optionally attrs and design.' );
			}
			$nodes[] = Layout::row( $row, false );
		}
		return $nodes;
	}

	/**
	 * Refuse a layout with structure errors; return its warnings.
	 *
	 * @param list<array<string, mixed>> $nodes Layout.
	 * @return list<array<string, string>>
	 * @throws ToolError When the layout has errors.
	 */
	private static function checked( array $nodes ): array {
		$report = Layout::validate( $nodes );
		if ( $report['errors'] ) {
			$lines = array_map( static fn( array $e ) => $e['at'] . ': ' . $e['problem'], array_slice( $report['errors'], 0, 10 ) );
			throw new ToolError( 'This would leave the layout broken, so nothing was saved. ' . esc_html( implode( ' | ', $lines ) ) );
		}
		return $report['warnings'];
	}

	/**
	 * Result of a write.
	 *
	 * @param \WP_Post                    $post     Saved post.
	 * @param list<array<string, string>> $warnings Warnings.
	 * @param string                      $id       Id of the new or changed item, or ''.
	 * @return array<string, mixed>
	 */
	private static function result( \WP_Post $post, array $warnings, string $id = '' ): array {
		$nodes = WpBakery::parse( $post->post_content );
		return array(
			'post_id'  => $post->ID,
			'status'   => (string) $post->post_status,
			'hash'     => WpBakery::hash( $post->post_content ),
			'elements' => Layout::count( $nodes ),
			'tree'     => Layout::drawing( Layout::outline( $nodes ) ),
			'warnings' => array_slice( $warnings, 0, 50 ),
		) + ( '' !== $id ? array( 'element_id' => $id ) : array() ) + WpBakery::links( $post );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the page or item is missing, the input malformed or the result invalid.
	 */
	public function execute( array $input ): array {
		if ( 'create' === $this->mode ) {
			return self::create( $input );
		}
		$post = WpBakery::post( (int) ( $input['post_id'] ?? 0 ), true );
		Content::assert_writable( $post->post_type );
		AuditLog::target( $post->post_type, (string) $post->ID );
		WpBakery::guard_concurrent( $post, $input );
		$nodes = WpBakery::parse( $post->post_content );
		$id    = '';
		switch ( $this->mode ) {
			case 'replace':
				if ( ! isset( $input['rows'] ) && ! isset( $input['content'] ) ) {
					throw new ToolError( 'Give the new layout as rows or as content. Nothing was changed.' );
				}
				$nodes = self::layout( $input );
				break;
			case 'add-element':
				[ $nodes, $id ] = self::add_element( $nodes, $input );
				break;
			case 'add-row':
				[ $nodes, $id ] = self::add_row( $nodes, $input );
				break;
			case 'add-column':
				[ $path, $row ]   = Layout::find( $nodes, (string) $input['row_id'], array( 'role' => 'row' ) );
				$column           = Layout::column( $input, Layout::ROWS[ $row['tag'] ] );
				[ $nodes, $used ] = Layout::insert( $nodes, $path, isset( $input['index'] ) ? (int) $input['index'] : null, $column );
				$id               = Layout::child_id( (string) $input['row_id'], $used );
				break;
			case 'update-element':
			case 'update-row':
			case 'update-column':
				[ $nodes, $id ] = $this->update( $nodes, $input );
				break;
			case 'delete-element':
				[ $path, $node ] = Layout::find( $nodes, (string) $input['element_id'], array( 'tag' => (string) ( $input['tag'] ?? '' ) ) );
				if ( 'column' === Layout::role( $node ) && 1 === count( array_filter( Layout::at( $nodes, array_slice( $path, 0, -1 ) )['children'], static fn( array $c ) => 'element' === $c['type'] ) ) ) {
					throw new ToolError( 'This is the only column of its row. Remove the row instead (its id is the part before the last dot). Nothing was changed.' );
				}
				$nodes = Layout::put( $nodes, $path, null );
				break;
			case 'duplicate-element':
				[ $path, $node ] = Layout::find( $nodes, (string) $input['element_id'], array( 'tag' => (string) ( $input['tag'] ?? '' ) ) );
				$real            = array_pop( $path );
				$into            = $path ? Layout::at( $nodes, $path ) : array( 'children' => $nodes );
				array_splice( $into['children'], $real + 1, 0, array( $node ) );
				$nodes  = $path ? Layout::put( $nodes, $path, $into ) : array_values( $into['children'] );
				$dot    = strrpos( (string) $input['element_id'], '.' );
				$prefix = false === $dot ? '' : substr( (string) $input['element_id'], 0, $dot );
				$id     = Layout::child_id( $prefix, 1 + (int) substr( (string) $input['element_id'], false === $dot ? 0 : $dot + 1 ) );
				break;
			default:
				[ $nodes, $id ] = self::move( $nodes, $input );
		}//end switch
		$warnings = self::checked( $nodes );
		return self::result( WpBakery::save( $post, $nodes ), $warnings, $id );
	}

	/**
	 * Create a page with a layout.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the input is invalid or WordPress refuses.
	 */
	private static function create( array $input ): array {
		$type = Content::post_type( (string) ( $input['post_type'] ?? 'page' ) );
		Content::assert_writable( $type->name );
		if ( ! post_type_supports( $type->name, 'editor' ) ) {
			throw new ToolError( 'Posts of type "' . esc_html( $type->name ) . '" have no content editor.' );
		}
		if ( ! current_user_can( $type->cap->create_posts ) ) {
			throw new ToolError( 'Your account may not create ' . esc_html( $type->name ) . ' posts.' );
		}
		$nodes    = self::layout( $input );
		$warnings = self::checked( $nodes );
		$template = (string) ( $input['template'] ?? '' );
		if ( '' !== $template && 'default' !== $template && ! isset( wp_get_theme()->get_page_templates( null, $type->name )[ $template ] ) ) {
			throw new ToolError( 'The active theme has no template "' . esc_html( $template ) . '" for ' . esc_html( $type->name ) . '. Available: ' . esc_html( implode( ', ', array_keys( wp_get_theme()->get_page_templates( null, $type->name ) ) ) ) . '.' );
		}
		WpBakery::guard_raw( $nodes );
		$postarr = PostWriter::postarr( array_intersect_key( $input, array_flip( array( 'title', 'slug', 'parent' ) ) ), $type ) + array(
			'post_type'    => $type->name,
			'post_status'  => (string) ( $input['status'] ?? 'draft' ),
			'post_content' => Shortcodes::serialize( $nodes ),
		);
		$id      = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $id ) ) {
			throw new ToolError( 'WordPress did not create the page: ' . esc_html( $id->get_error_message() ) );
		}
		AuditLog::target( $type->name, (string) $id );
		if ( '' !== $template ) {
			update_post_meta( $id, '_wp_page_template', $template );
		}
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			throw new ToolError( 'The new page could not be read back.' );
		}
		WpBakery::after_save( $post->ID );
		return array( 'created' => true ) + self::result( $post, $warnings );
	}

	/**
	 * Add an element under a parent, or wrapped in a new row at the top level.
	 *
	 * @param list<array<string, mixed>> $nodes Layout.
	 * @param array<string, mixed>       $input Arguments.
	 * @return array{0: list<array<string, mixed>>, 1: string} Layout and the new element's id.
	 * @throws ToolError When the parent is missing or cannot hold elements.
	 */
	private static function add_element( array $nodes, array $input ): array {
		$element = Layout::element( $input['element'] ?? null );
		$index   = isset( $input['index'] ) ? (int) $input['index'] : null;
		$into    = (string) ( $input['parent_id'] ?? '' );
		$role    = Layout::role( $element );
		if ( '' === $into ) {
			if ( 'vc_row' !== $element['tag'] && 'vc_section' !== $element['tag'] ) {
				if ( 'element' !== $role ) {
					throw new ToolError( 'A ' . esc_html( $element['tag'] ) . ' needs a parent_id (columns go into rows, inner rows into columns).' );
				}
				$element          = Shortcodes::element( 'vc_row', array(), array( Shortcodes::element( 'vc_column', array(), array( $element ), true ) ), true );
				[ $nodes, $used ] = Layout::insert( $nodes, array(), $index, $element );
				return array( $nodes, $used . '.0.0' );
			}
			[ $nodes, $used ] = Layout::insert( $nodes, array(), $index, $element );
			return array( $nodes, (string) $used );
		}
		[ $path, $above ] = Layout::find( $nodes, $into );
		if ( 'element' !== $above['type'] ) {
			throw new ToolError( 'Item "' . esc_html( $into ) . '" is text and cannot hold elements.' );
		}
		if ( 'row' === Layout::role( $above ) && 'column' !== $role ) {
			throw new ToolError( 'Elements go into a column, not straight into a row. Use the id of one of the row\'s columns as parent_id.' );
		}
		[ $nodes, $used ] = Layout::insert( $nodes, $path, $index, $element );
		return array( $nodes, Layout::child_id( $into, $used ) );
	}

	/**
	 * Add a row at the top level, in a section, or (as an inner row) in a column.
	 *
	 * @param list<array<string, mixed>> $nodes Layout.
	 * @param array<string, mixed>       $input Arguments.
	 * @return array{0: list<array<string, mixed>>, 1: string} Layout and the new row's id.
	 * @throws ToolError When the parent cannot hold a row.
	 */
	private static function add_row( array $nodes, array $input ): array {
		$index = isset( $input['index'] ) ? (int) $input['index'] : null;
		$into  = (string) ( $input['parent_id'] ?? '' );
		$path  = array();
		$inner = false;
		if ( '' !== $into ) {
			[ $path, $above ] = Layout::find( $nodes, $into );
			$tag              = 'element' === $above['type'] ? $above['tag'] : '';
			if ( 'vc_column' === $tag ) {
				$inner = true;
			} elseif ( 'vc_section' !== $tag ) {
				throw new ToolError( 'A row can be added at the top level, inside a section, or inside a column of a top-level row (as an inner row). "' . esc_html( $into ) . '" is ' . esc_html( '' !== $tag ? $tag : 'text' ) . '. WPBakery does not nest rows deeper than that.' );
			}
		}
		[ $nodes, $used ] = Layout::insert( $nodes, $path, $index, Layout::row( $input, $inner ) );
		return array( $nodes, Layout::child_id( $into, $used ) );
	}

	/**
	 * Change attributes, design, content or column widths of one item.
	 *
	 * @param list<array<string, mixed>> $nodes Layout.
	 * @param array<string, mixed>       $input Arguments.
	 * @return array{0: list<array<string, mixed>>, 1: string} Layout and the item's id.
	 * @throws ToolError When the item is missing, of another kind, or nothing was asked.
	 */
	private function update( array $nodes, array $input ): array {
		[ $key, $expect ] = match ( $this->mode ) {
			'update-row'    => array( 'row_id', array( 'role' => 'row' ) ),
			'update-column' => array( 'column_id', array( 'role' => 'column' ) ),
			default         => array( 'element_id', array( 'tag' => (string) ( $input['tag'] ?? '' ) ) ),
		};
		$id              = (string) $input[ $key ];
		[ $path, $node ] = Layout::find( $nodes, $id, $expect );
		if ( 'element' !== $node['type'] ) {
			throw new ToolError( 'Item "' . esc_html( $id ) . '" is loose text, not an element. Replace it with kuduy-wpbakery-delete-element and kuduy-wpbakery-add-element.' );
		}
		$changes = array_intersect_key( $input, array_flip( 'update-column' === $this->mode ? array( 'attrs', 'design', 'width', 'responsive' ) : array( 'attrs', 'design' ) ) );
		if ( ! $changes && ! isset( $input['content'] ) && ! isset( $input['layout'] ) ) {
			throw new ToolError( 'Nothing to change: give attrs, design' . ( 'update-element' === $this->mode ? ' or content' : ( 'update-row' === $this->mode ? ' or layout' : ', width or responsive' ) ) . '.' );
		}
		if ( $changes ) {
			$atts = Layout::atts_from_input( $node['tag'], $changes, $node );
			if ( '1/1' === ( $atts['width'] ?? '' ) ) {
				$atts['width'] = null;
			}
			$node = Shortcodes::with_atts( $node, $atts );
		}
		if ( 'update-element' === $this->mode && isset( $input['content'] ) ) {
			$node['children'] = Layout::content_nodes( $node['tag'], (string) $input['content'] );
			if ( empty( $node['closed'] ) ) {
				$node['closed'] = true;
				$node['self']   = false;
				$node['open']   = null;
			}
		}
		if ( 'update-row' === $this->mode && isset( $input['layout'] ) ) {
			$widths  = Layout::widths( (string) $input['layout'] );
			$columns = array_keys( array_filter( $node['children'], static fn( array $c ) => 'column' === Layout::role( $c ) ) );
			if ( count( $widths ) !== count( $columns ) ) {
				throw new ToolError( 'This row has ' . count( $columns ) . ' column(s) but the layout names ' . count( $widths ) . '. Add or remove columns first (kuduy-wpbakery-add-column, kuduy-wpbakery-move-element, kuduy-wpbakery-delete-element). Nothing was changed.' );
			}
			foreach ( $columns as $n => $i ) {
				$node['children'][ $i ] = Shortcodes::with_atts( $node['children'][ $i ], array( 'width' => '1/1' === $widths[ $n ] ? null : $widths[ $n ] ) );
			}
		}
		return array( Layout::put( $nodes, $path, $node ), $id );
	}

	/**
	 * Move an item to another parent or position.
	 *
	 * @param list<array<string, mixed>> $nodes Layout.
	 * @param array<string, mixed>       $input Arguments.
	 * @return array{0: list<array<string, mixed>>, 1: string} Layout and the item's new id.
	 * @throws ToolError When the target is inside the item, or cannot hold it.
	 */
	private static function move( array $nodes, array $input ): array {
		$id              = (string) $input['element_id'];
		$to              = (string) ( $input['to_parent_id'] ?? '' );
		[ $path, $node ] = Layout::find( $nodes, $id, array( 'tag' => (string) ( $input['tag'] ?? '' ) ) );
		if ( $to === $id || str_starts_with( $to, $id . '.' ) ) {
			throw new ToolError( 'An item cannot be moved into itself. Nothing was changed.' );
		}
		// Mark the target before removing the item, because removing shifts positions.
		$marker = '__kuduy_target_' . wp_rand();
		if ( '' !== $to ) {
			[ $to_path, $target ] = Layout::find( $nodes, $to );
			if ( 'element' !== $target['type'] ) {
				throw new ToolError( 'Item "' . esc_html( $to ) . '" is text and cannot hold elements.' );
			}
			$target[ $marker ] = true;
			$nodes             = Layout::put( $nodes, $to_path, $target );
		}
		$nodes = Layout::put( $nodes, $path, null );
		$found = '' === $to ? array( array(), '' ) : self::marked( $nodes, $marker );
		if ( null === $found ) {
			throw new ToolError( 'The target could not be found after removing the item. Nothing was changed.' );
		}
		[ $to_path, $to_id ] = $found;
		if ( $to_path ) {
			$target = Layout::at( $nodes, $to_path );
			unset( $target[ $marker ] );
			$nodes = Layout::put( $nodes, $to_path, $target );
		}
		[ $nodes, $used ] = Layout::insert( $nodes, $to_path, isset( $input['index'] ) ? (int) $input['index'] : null, $node );
		return array( $nodes, Layout::child_id( $to_id, $used ) );
	}

	/**
	 * Path and id of the node carrying a marker.
	 *
	 * @param list<array<string, mixed>> $nodes  Nodes.
	 * @param string                     $marker Marker key.
	 * @param list<int>                  $path   Path so far.
	 * @param string                     $into Id so far.
	 * @return array{0: list<int>, 1: string}|null
	 */
	private static function marked( array $nodes, string $marker, array $path = array(), string $into = '' ): ?array {
		$index = 0;
		foreach ( $nodes as $i => $node ) {
			if ( 'element' !== $node['type'] ) {
				if ( '' !== trim( $node['raw'] ) ) {
					++$index;
				}
				continue;
			}
			$id   = Layout::child_id( $into, $index++ );
			$here = array_merge( $path, array( $i ) );
			if ( ! empty( $node[ $marker ] ) ) {
				return array( $here, $id );
			}
			$deeper = self::marked( $node['children'], $marker, $here, $id );
			if ( null !== $deeper ) {
				return $deeper;
			}
		}
		return null;
	}
}
