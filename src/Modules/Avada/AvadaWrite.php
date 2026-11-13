<?php
/**
 * Tools: kuduy-avada-create, -add-container, -add-column, -add-element, -update-element, -update-container,
 * -update-column, -move-element, -duplicate-element, -delete-element, -replace, -page-css.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Avada;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;
use Kuduy\Mcp\Modules\Core\PostWriter;
use Kuduy\Mcp\Modules\WpBakery\Layout;
use Kuduy\Mcp\Modules\WpBakery\Shortcodes;
use Kuduy\Mcp\Modules\WpBakery\WpBakery;

/**
 * Avada Builder editing. Every tool parses the page, changes only the addressed node, validates the result (a layout
 * with errors is never saved), and with dry_run returns the change without saving. Otherwise it snapshots the page
 * (content, builder status, custom CSS), saves through wp_update_post() so Avada's own save hooks refresh its CSS and
 * JS caches, reads the page back to verify, and returns a before/after report and the new tree. Untouched elements,
 * including unknown and third-party shortcodes and global element references, keep their exact text. Pages built with
 * Elementor or WPBakery are refused; Library items and Layout sections need confirm_shared.
 */
final class AvadaWrite implements Tool, Snapshottable {

	/**
	 * Mode.
	 *
	 * @param string $mode create | add-container | add-column | add-element | update-element | update-container |
	 *                     update-column | move-element | duplicate-element | delete-element | replace | page-css.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-avada-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'create'            => __( 'Create an Avada page', 'kuduy-mcp' ),
			'add-container'     => __( 'Add an Avada container', 'kuduy-mcp' ),
			'add-column'        => __( 'Add an Avada column', 'kuduy-mcp' ),
			'add-element'       => __( 'Add an Avada element', 'kuduy-mcp' ),
			'update-element'    => __( 'Change an Avada element', 'kuduy-mcp' ),
			'update-container'  => __( 'Change an Avada container', 'kuduy-mcp' ),
			'update-column'     => __( 'Change an Avada column', 'kuduy-mcp' ),
			'move-element'      => __( 'Move an Avada element', 'kuduy-mcp' ),
			'duplicate-element' => __( 'Duplicate an Avada element', 'kuduy-mcp' ),
			'delete-element'    => __( 'Remove an Avada element', 'kuduy-mcp' ),
			'replace'           => __( 'Replace an Avada layout', 'kuduy-mcp' ),
			default             => __( 'Avada page custom CSS', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		$element = ' An element is {"tag": "fusion_title", "attrs": {...}, "responsive": {"medium": {...}, "small": {...}}, "content": "text or HTML"} or with "children": [elements] for parent elements (tabs, toggles, content boxes); see kuduy-avada-elements for tags and settings. Global elements are {"tag": "fusion_global", "attrs": {"id": 123}}.';
		$after   = ' dry_run: true shows the change without saving. The result is validated first (a layout with errors is not saved), read back after saving, and returned with a before/after report and the new tree (ids change after every edit). Snapshot first; undo with kuduy-snapshot-restore.';
		return match ( $this->mode ) {
			'create'            => 'Creates a page (or post) built with Avada Builder, a draft unless status says otherwise: title, slug, parent, template, and the layout as containers (each with layout such as "1_2 + 1_2" or columns with width, attrs, responsive, elements; container settings in attrs) or as content (raw Avada shortcodes).' . $element . ' Validated first; returns id, edit link and tree. SEO fields with kuduy-seo-update. Undo with kuduy-post-trash.',
			'add-container'     => 'Adds a container (with its row and columns) to an Avada page at a position (index, default last). Columns from layout ("1_1", "1_2 + 1_2", "1_3 + 2_3", "1_4 + 1_4 + 1_2") or columns (width, attrs, responsive, elements). Container settings (background, padding, margins, full width, admin_label, …) in attrs.' . $element . $after,
			'add-column'        => 'Adds a column to an existing container or row (parent_id), or nested columns inside a column (parent_id of a column; layout for the nested columns), at a position: width (1_2 or 1/2), attrs, responsive, elements. Other columns keep their widths.' . $element . $after,
			'add-element'       => 'Adds an element to a column (or a parent element such as tabs) of an Avada page, at a position (index, default last). Without parent_id it is wrapped in a new full-width container at the end.' . $element . $after,
			'update-element'    => 'Changes one element of an Avada page: attrs to set (null removes one, which falls back to Avada\'s default), responsive values (medium, small), content (text or HTML). Use it for headings, text, images (image_id plus the URL as content), buttons and links (link, target), spacing, typography and colours: they are all element settings. Only that element is rewritten. Pass tag to make sure the id still points at what you mean.' . $after,
			'update-container'  => 'Changes a container: attrs (background colour or image, padding, margins, border, full width, height, admin_label, …) and responsive values. Its columns and content are untouched.' . $after,
			'update-column'     => 'Changes a column or nested column: width (1_2 or 1/2), attrs (background, padding, margins, alignment, …) and responsive values such as widths per screen ({"medium": {"type": "1_1"}}).' . $after,
			'move-element'      => 'Moves an element, column or container of an Avada page with everything inside it to another parent (to_parent_id; omit for the top level) and position (index), or reorders it.' . $after,
			'duplicate-element' => 'Duplicates an element, column or container of an Avada page with its settings and everything inside it, right after the original.' . $after,
			'delete-element'    => 'Removes one element, column or container of an Avada page with everything inside it; the rest stays intact. Removing the last column of a row is refused: remove the container.' . $after,
			'replace'           => 'Replaces the whole Avada layout of a page with containers (as in kuduy-avada-add-container) or content (raw Avada shortcodes), after validating it.' . $element . $after,
			default             => 'Sets the custom CSS of one Avada page (the page\'s own CSS box; Global Options and other pages are not touched). Empty text removes it. CSS only: tags are stripped. dry_run shows the change. Snapshot first; undo with kuduy-snapshot-restore.',
		};
	}

	/**
	 * Schema pieces.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function pieces(): array {
		$element = array(
			'type'       => 'object',
			'properties' => array(
				'tag'        => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 64,
				),
				'attrs'      => array( 'type' => 'object' ),
				'responsive' => array( 'type' => 'object' ),
				'content'    => array( 'type' => 'string' ),
				'children'   => array(
					'type'     => 'array',
					'maxItems' => 200,
					'items'    => array( 'type' => 'object' ),
				),
			),
			'required'   => array( 'tag' ),
		);
		$columns = array(
			'type'     => 'array',
			'maxItems' => 12,
			'items'    => array(
				'type'       => 'object',
				'properties' => array(
					'width'      => array( 'type' => 'string' ),
					'attrs'      => array( 'type' => 'object' ),
					'responsive' => array( 'type' => 'object' ),
					'elements'   => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
				),
			),
		);
		return array(
			'post_id'        => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'id'             => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 100,
			),
			'element'        => $element,
			'elements'       => array(
				'type'     => 'array',
				'maxItems' => 200,
				'items'    => $element,
			),
			'attrs'          => array(
				'type'        => 'object',
				'description' => 'Settings by name (kuduy-avada-elements with tag). null removes one.',
			),
			'responsive'     => array(
				'type'        => 'object',
				'description' => 'Settings per screen size: {"medium": {...}, "small": {...}} (stored as name_medium / name_small).',
			),
			'width'          => array(
				'type'      => 'string',
				'maxLength' => 5,
			),
			'layout'         => array(
				'type'        => 'string',
				'maxLength'   => 100,
				'description' => 'Column widths joined by +, e.g. "1_2 + 1_2", "1_3 + 2_3".',
			),
			'columns'        => $columns,
			'containers'     => array(
				'type'     => 'array',
				'maxItems' => 100,
				'items'    => array(
					'type'       => 'object',
					'properties' => array(
						'layout'     => array( 'type' => 'string' ),
						'columns'    => $columns,
						'attrs'      => array( 'type' => 'object' ),
						'responsive' => array( 'type' => 'object' ),
					),
				),
			),
			'index'          => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'tag'            => array(
				'type'        => 'string',
				'maxLength'   => 64,
				'description' => 'Expected tag of the item; refused when the id points at something else.',
			),
			'content'        => array(
				'type'      => 'string',
				'maxLength' => 1000000,
			),
			'dry_run'        => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => 'Show the change and the resulting tree without saving.',
			),
			'if_hash'        => array(
				'type'        => 'string',
				'maxLength'   => 32,
				'description' => 'Hash from kuduy-avada-tree; refused when the page changed since.',
			),
			'ignore_lock'    => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'confirm_shared' => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => 'Needed to change an Avada Library item or Layout section, which shows on many pages.',
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$p                         = self::pieces();
		$common                    = array(
			'post_id'        => $p['post_id'],
			'dry_run'        => $p['dry_run'],
			'if_hash'        => $p['if_hash'],
			'ignore_lock'    => $p['ignore_lock'],
			'confirm_shared' => $p['confirm_shared'],
		);
		[ $properties, $required ] = match ( $this->mode ) {
			'create'            => array(
				array(
					'title'      => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 300,
					),
					'post_type'  => array(
						'type'    => 'string',
						'default' => 'page',
					),
					'status'     => array(
						'type'    => 'string',
						'enum'    => array( 'draft', 'publish', 'pending', 'private' ),
						'default' => 'draft',
					),
					'slug'       => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'parent'     => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'template'   => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'containers' => $p['containers'],
					'content'    => $p['content'],
					'dry_run'    => $p['dry_run'],
				),
				array( 'title' ),
			),
			'add-container'     => array(
				$common + array(
					'index'      => $p['index'],
					'layout'     => $p['layout'],
					'columns'    => $p['columns'],
					'attrs'      => $p['attrs'],
					'responsive' => $p['responsive'],
				),
				array( 'post_id' ),
			),
			'add-column'        => array(
				$common + array(
					'parent_id'  => $p['id'],
					'index'      => $p['index'],
					'width'      => $p['width'],
					'layout'     => $p['layout'],
					'attrs'      => $p['attrs'],
					'responsive' => $p['responsive'],
					'elements'   => $p['elements'],
				),
				array( 'post_id', 'parent_id' ),
			),
			'add-element'       => array(
				$common + array(
					'parent_id' => $p['id'],
					'index'     => $p['index'],
					'element'   => $p['element'],
				),
				array( 'post_id', 'element' ),
			),
			'update-element'    => array(
				$common + array(
					'element_id' => $p['id'],
					'tag'        => $p['tag'],
					'attrs'      => $p['attrs'],
					'responsive' => $p['responsive'],
					'content'    => $p['content'],
				),
				array( 'post_id', 'element_id' ),
			),
			'update-container'  => array(
				$common + array(
					'container_id' => $p['id'],
					'attrs'        => $p['attrs'],
					'responsive'   => $p['responsive'],
				),
				array( 'post_id', 'container_id' ),
			),
			'update-column'     => array(
				$common + array(
					'column_id'  => $p['id'],
					'width'      => $p['width'],
					'attrs'      => $p['attrs'],
					'responsive' => $p['responsive'],
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
					'containers' => $p['containers'],
					'content'    => $p['content'],
				),
				array( 'post_id' ),
			),
			'page-css'          => array(
				array(
					'post_id' => $p['post_id'],
					'css'     => array(
						'type'      => 'string',
						'maxLength' => 100000,
					),
					'dry_run' => $p['dry_run'],
				),
				array( 'post_id', 'css' ),
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
				'saved'        => array( 'type' => 'boolean' ),
				'verified'     => array( 'type' => 'boolean' ),
				'created'      => array( 'type' => 'boolean' ),
				'element_id'   => array( 'type' => 'string' ),
				'hash'         => array( 'type' => 'string' ),
				'elements'     => array( 'type' => 'integer' ),
				'tree'         => array( 'type' => 'string' ),
				'change'       => array( 'type' => 'object' ),
				'warnings'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'edit_url'     => array( 'type' => 'string' ),
				'link'         => array( 'type' => 'string' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'saved', 'change' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => 'create' !== $this->mode,
			'idempotentHint'  => in_array( $this->mode, array( 'update-element', 'update-container', 'update-column', 'replace', 'page-css' ), true ),
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
		return Avada::available();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'create' === $this->mode || ! empty( $input['dry_run'] ) ) {
			return null;
		}
		$post = Avada::post( (int) ( $input['post_id'] ?? 0 ), true );
		return array(
			'object_type' => $post->post_type,
			'object_id'   => (string) $post->ID,
			'payload'     => array(
				'id'      => $post->ID,
				'content' => $post->post_content,
				'status'  => (string) get_post_meta( $post->ID, Avada::META_STATUS, true ),
				'css'     => (string) get_post_meta( $post->ID, Avada::META_CSS, true ),
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
			Avada::META_STATUS => (string) ( $payload['status'] ?? '' ),
			Avada::META_CSS    => (string) ( $payload['css'] ?? '' ),
		) as $key => $value ) {
			if ( '' === $value ) {
				delete_post_meta( $id, $key );
			} else {
				update_post_meta( $id, $key, wp_slash( $value ) );
			}
		}
	}

	/**
	 * Containers or content from tool input as nodes.
	 *
	 * @param array<string, mixed> $input containers or content.
	 * @return list<array<string, mixed>>
	 * @throws ToolError When both or neither are given.
	 */
	private static function layout( array $input ): array {
		if ( isset( $input['containers'] ) && isset( $input['content'] ) ) {
			throw new ToolError( 'Give the layout either as containers or as content (shortcodes), not both.' );
		}
		if ( isset( $input['content'] ) ) {
			return Avada::parse( (string) $input['content'] );
		}
		$nodes = array();
		foreach ( (array) ( $input['containers'] ?? array() ) as $container ) {
			if ( ! is_array( $container ) ) {
				throw new ToolError( 'Each container is an object: layout or columns, and optionally attrs and responsive.' );
			}
			$nodes[] = AvadaLayout::container( $container );
		}
		return $nodes;
	}

	/**
	 * Refuse a layout with errors; return its warnings.
	 *
	 * @param list<array<string, mixed>> $nodes Layout.
	 * @return list<array<string, string>>
	 * @throws ToolError When it has errors.
	 */
	private static function checked( array $nodes ): array {
		$report = AvadaLayout::validate( $nodes );
		if ( $report['errors'] ) {
			$lines = array_map( static fn( array $e ) => $e['at'] . ': ' . $e['problem'], array_slice( $report['errors'], 0, 10 ) );
			throw new ToolError( 'This would leave the Avada layout broken, so nothing was saved. ' . esc_html( implode( ' | ', $lines ) ) );
		}
		return array_slice( $report['warnings'], 0, 50 );
	}

	/**
	 * Find a node and check its role or tag.
	 *
	 * @param list<array<string, mixed>> $nodes  Layout.
	 * @param string                     $id     Id.
	 * @param list<string>               $roles  Allowed roles (empty = any).
	 * @param string                     $tag    Expected tag or ''.
	 * @return array{0: list<int>, 1: array<string, mixed>}
	 * @throws ToolError When missing or of another kind.
	 */
	private static function find( array $nodes, string $id, array $roles = array(), string $tag = '' ): array {
		$path = Layout::path( $nodes, $id );
		$node = Layout::at( $nodes, $path );
		$role = AvadaLayout::role( $node );
		$is   = 'text' === $role ? 'text' : (string) $node['tag'];
		if ( '' !== $tag && $tag !== $is ) {
			throw new ToolError( 'Item "' . esc_html( $id ) . '" is ' . esc_html( $is ) . ', not ' . esc_html( $tag ) . '. The page may have changed: read kuduy-avada-tree again. Nothing was changed.' );
		}
		if ( $roles && ! in_array( $role, $roles, true ) ) {
			throw new ToolError( 'Item "' . esc_html( $id ) . '" is ' . esc_html( $is ) . ' (' . esc_html( $role ) . '), not a ' . esc_html( implode( ' or ', $roles ) ) . '. Nothing was changed.' );
		}
		return array( $path, $node );
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
		$post = Avada::post( (int) ( $input['post_id'] ?? 0 ), true );
		Content::assert_writable( $post->post_type );
		Avada::guard_shared( $post, $input );
		AuditLog::target( $post->post_type, (string) $post->ID );
		if ( empty( $input['dry_run'] ) ) {
			WpBakery::guard_concurrent( $post, $input );
		}
		if ( 'page-css' === $this->mode ) {
			return self::page_css( $post, $input );
		}
		$nodes = Avada::parse( $post->post_content );
		$id    = '';
		switch ( $this->mode ) {
			case 'replace':
				if ( ! isset( $input['containers'] ) && ! isset( $input['content'] ) ) {
					throw new ToolError( 'Give the new layout as containers or as content. Nothing was changed.' );
				}
				$nodes = self::layout( $input );
				break;
			case 'add-container':
				[ $nodes, $used ] = Layout::insert( $nodes, array(), isset( $input['index'] ) ? (int) $input['index'] : null, AvadaLayout::container( $input ) );
				$id               = (string) $used;
				break;
			case 'add-column':
				[ $nodes, $id ] = self::add_column( $nodes, $input );
				break;
			case 'add-element':
				[ $nodes, $id ] = self::add_element( $nodes, $input );
				break;
			case 'update-element':
			case 'update-container':
			case 'update-column':
				[ $nodes, $id ] = $this->update( $nodes, $input );
				break;
			case 'delete-element':
				[ $path, $node ] = self::find( $nodes, (string) $input['element_id'], array(), (string) ( $input['tag'] ?? '' ) );
				$siblings        = Layout::at( $nodes, array_slice( $path, 0, -1 ) )['children'] ?? $nodes;
				if ( 'column' === AvadaLayout::role( $node ) && 1 === count( array_filter( $siblings, static fn( array $c ) => 'element' === $c['type'] ) ) ) {
					throw new ToolError( 'This is the only column of its row. Remove the container (or the nested columns) instead. Nothing was changed.' );
				}
				if ( 'row' === AvadaLayout::role( $node ) && 'fusion_builder_row' === $node['tag'] ) {
					throw new ToolError( 'A container needs its row. Remove the container instead (its id is the part before the last dot). Nothing was changed.' );
				}
				$nodes = Layout::put( $nodes, $path, null );
				break;
			case 'duplicate-element':
				[ $path, $node ] = self::find( $nodes, (string) $input['element_id'], array(), (string) ( $input['tag'] ?? '' ) );
				$real            = array_pop( $path );
				$parent          = $path ? Layout::at( $nodes, $path ) : array( 'children' => $nodes );
				array_splice( $parent['children'], $real + 1, 0, array( $node ) );
				$nodes = $path ? Layout::put( $nodes, $path, $parent ) : array_values( $parent['children'] );
				$dot   = strrpos( (string) $input['element_id'], '.' );
				$id    = Layout::child_id( false === $dot ? '' : substr( (string) $input['element_id'], 0, $dot ), 1 + (int) substr( (string) $input['element_id'], false === $dot ? 0 : $dot + 1 ) );
				break;
			default:
				[ $nodes, $id ] = self::move( $nodes, $input );
		}//end switch
		return self::finish( $post, $nodes, self::checked( $nodes ), $id, ! empty( $input['dry_run'] ) );
	}

	/**
	 * Save (or preview) and build the result.
	 *
	 * @param \WP_Post                    $post     Post.
	 * @param list<array<string, mixed>>  $nodes    New layout.
	 * @param list<array<string, string>> $warnings Warnings.
	 * @param string                      $id       Id of the new or changed item.
	 * @param bool                        $dry      Dry run.
	 * @return array<string, mixed>
	 * @throws ToolError When WordPress refuses the save.
	 */
	private static function finish( \WP_Post $post, array $nodes, array $warnings, string $id, bool $dry ): array {
		$new      = Shortcodes::serialize( $nodes );
		$change   = Avada::diff( $post->post_content, $new );
		$verified = false;
		if ( ! $dry && $change['changed'] ) {
			$saved    = Avada::save( $post, $nodes );
			$post     = $saved['post'];
			$verified = $saved['verified'];
			$nodes    = Avada::parse( $post->post_content );
			$new      = $post->post_content;
		}
		return array(
			'post_id'  => $post->ID,
			'saved'    => ! $dry && $change['changed'],
			'verified' => $dry ? false : ( $change['changed'] ? $verified : true ),
			'hash'     => WpBakery::hash( $dry ? $post->post_content : $new ),
			'elements' => AvadaLayout::count( $nodes ),
			'tree'     => Layout::drawing( AvadaLayout::outline( $nodes ) ),
			'change'   => $change,
			'warnings' => $warnings,
		) + ( '' !== $id ? array( 'element_id' => $id ) : array() ) + WpBakery::links( $post );
	}

	/**
	 * Create a page.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When invalid or refused.
	 */
	private static function create( array $input ): array {
		$type = Content::post_type( (string) ( $input['post_type'] ?? 'page' ) );
		Content::assert_writable( $type->name );
		if ( ! post_type_supports( $type->name, 'editor' ) || 'fusion_tb_layout' === $type->name ) {
			throw new ToolError( 'Avada layouts cannot be created as "' . esc_html( $type->name ) . '" posts.' );
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
		$content = Shortcodes::serialize( $nodes );
		if ( ! empty( $input['dry_run'] ) ) {
			return array(
				'saved'    => false,
				'verified' => false,
				'created'  => false,
				'hash'     => WpBakery::hash( $content ),
				'elements' => AvadaLayout::count( $nodes ),
				'tree'     => Layout::drawing( AvadaLayout::outline( $nodes ) ),
				'change'   => Avada::diff( '', $content ),
				'warnings' => $warnings,
			);
		}
		$postarr = PostWriter::postarr( array_intersect_key( $input, array_flip( array( 'title', 'slug', 'parent' ) ) ), $type ) + array(
			'post_type'    => $type->name,
			'post_status'  => (string) ( $input['status'] ?? 'draft' ),
			'post_content' => $content,
		);
		$id      = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $id ) ) {
			throw new ToolError( 'WordPress did not create the page: ' . esc_html( $id->get_error_message() ) );
		}
		AuditLog::target( $type->name, (string) $id );
		update_post_meta( $id, Avada::META_STATUS, 'active' );
		if ( '' !== $template ) {
			update_post_meta( $id, '_wp_page_template', $template );
		}
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			throw new ToolError( 'The new page could not be read back.' );
		}
		$saved = Avada::parse( $post->post_content );
		return array(
			'post_id'  => $post->ID,
			'saved'    => true,
			'verified' => $post->post_content === $content,
			'created'  => true,
			'hash'     => WpBakery::hash( $post->post_content ),
			'elements' => AvadaLayout::count( $saved ),
			'tree'     => Layout::drawing( AvadaLayout::outline( $saved ) ),
			'change'   => Avada::diff( '', $post->post_content ),
			'warnings' => $warnings,
		) + WpBakery::links( $post );
	}

	/**
	 * Add a column to a container or row, or nested columns to a column.
	 *
	 * @param list<array<string, mixed>> $nodes Layout.
	 * @param array<string, mixed>       $input Arguments.
	 * @return array{0: list<array<string, mixed>>, 1: string}
	 * @throws ToolError When the parent cannot hold a column.
	 */
	private static function add_column( array $nodes, array $input ): array {
		$parent           = (string) $input['parent_id'];
		[ $path, $above ] = self::find( $nodes, $parent, array( 'container', 'row', 'column' ) );
		$role             = AvadaLayout::role( $above );
		$index            = isset( $input['index'] ) ? (int) $input['index'] : null;
		if ( 'fusion_builder_column_inner' === $above['tag'] ) {
			throw new ToolError( 'Avada nests columns only one level deep: a nested column cannot hold more columns. Nothing was changed.' );
		}
		if ( 'column' === $role ) {
			// Nested columns: an inner row with inner columns, added as an element of the column.
			$inner            = AvadaLayout::inner_row( isset( $input['layout'] ) ? $input : array( 'columns' => array( array_intersect_key( $input, array_flip( array( 'width', 'attrs', 'responsive', 'elements' ) ) ) ) ) );
			[ $nodes, $used ] = Layout::insert( $nodes, $path, $index, $inner );
			return array( $nodes, Layout::child_id( $parent, $used ) );
		}
		if ( 'container' === $role ) {
			$row = null;
			foreach ( $above['children'] as $i => $child ) {
				if ( 'element' === $child['type'] && 'fusion_builder_row' === $child['tag'] ) {
					$row = $i;
					break;
				}
			}
			if ( null === $row ) {
				throw new ToolError( 'This container has no row to add a column to. Nothing was changed.' );
			}
			$path[] = $row;
			$parent = Layout::child_id( $parent, count( array_filter( array_slice( $above['children'], 0, $row ), static fn( array $c ) => 'element' === $c['type'] || '' !== trim( $c['raw'] ) ) ) );
			$above  = $above['children'][ $row ];
		}
		$column           = AvadaLayout::column( $input, 'fusion_builder_row_inner' === $above['tag'] );
		[ $nodes, $used ] = Layout::insert( $nodes, $path, $index, $column );
		return array( $nodes, Layout::child_id( $parent, $used ) );
	}

	/**
	 * Add an element to a column or parent element, or in a new container at the end.
	 *
	 * @param list<array<string, mixed>> $nodes Layout.
	 * @param array<string, mixed>       $input Arguments.
	 * @return array{0: list<array<string, mixed>>, 1: string}
	 * @throws ToolError When the parent cannot hold elements.
	 */
	private static function add_element( array $nodes, array $input ): array {
		$element = AvadaLayout::element( $input['element'] ?? null );
		if ( in_array( AvadaLayout::role( $element ), array( 'container', 'row', 'column' ), true ) ) {
			throw new ToolError( 'Add containers with kuduy-avada-add-container and columns with kuduy-avada-add-column.' );
		}
		$parent = (string) ( $input['parent_id'] ?? '' );
		$index  = isset( $input['index'] ) ? (int) $input['index'] : null;
		if ( '' === $parent ) {
			$container = AvadaLayout::container( array( 'columns' => array( array( 'width' => '1_1' ) ) ) );
			$container['children'][0]['children'][0]['children'] = array( $element );
			[ $nodes, $used ]                                    = Layout::insert( $nodes, array(), null, $container );
			return array( $nodes, $used . '.0.0.0' );
		}
		[ $path, $above ] = self::find( $nodes, $parent, array( 'column', 'element' ) );
		[ $nodes, $used ] = Layout::insert( $nodes, $path, $index, $element );
		return array( $nodes, Layout::child_id( $parent, $used ) );
	}

	/**
	 * Change settings, responsive values, width or content of one node.
	 *
	 * @param list<array<string, mixed>> $nodes Layout.
	 * @param array<string, mixed>       $input Arguments.
	 * @return array{0: list<array<string, mixed>>, 1: string}
	 * @throws ToolError When the item is missing or of another kind, or nothing was asked.
	 */
	private function update( array $nodes, array $input ): array {
		[ $key, $roles ] = match ( $this->mode ) {
			'update-container' => array( 'container_id', array( 'container' ) ),
			'update-column'    => array( 'column_id', array( 'column' ) ),
			default            => array( 'element_id', array( 'element', 'global', 'row' ) ),
		};
		$id              = (string) $input[ $key ];
		[ $path, $node ] = self::find( $nodes, $id, $roles, (string) ( $input['tag'] ?? '' ) );
		$changes         = array_intersect_key( $input, array_flip( 'update-column' === $this->mode ? array( 'attrs', 'responsive', 'width' ) : array( 'attrs', 'responsive' ) ) );
		if ( ! $changes && ! isset( $input['content'] ) ) {
			throw new ToolError( 'Nothing to change: give attrs or responsive' . ( 'update-element' === $this->mode ? ', or content' : ( 'update-column' === $this->mode ? ', or width' : '' ) ) . '.' );
		}
		if ( $changes ) {
			$node = AvadaLayout::with_atts( $node, AvadaLayout::atts_from_input( $changes ) );
		}
		if ( 'update-element' === $this->mode && isset( $input['content'] ) ) {
			if ( 'global' === AvadaLayout::role( $node ) ) {
				throw new ToolError( 'A global element has no content on the page: change the Library item itself (its id is in attrs.id) with confirm_shared. Nothing was changed.' );
			}
			$node['children'] = AvadaLayout::content_nodes( (string) $input['content'] );
			if ( empty( $node['closed'] ) ) {
				$node['closed'] = true;
				$node['self']   = false;
				$node['open']   = null;
			}
		}
		return array( Layout::put( $nodes, $path, $node ), $id );
	}

	/**
	 * Move an item.
	 *
	 * @param list<array<string, mixed>> $nodes Layout.
	 * @param array<string, mixed>       $input Arguments.
	 * @return array{0: list<array<string, mixed>>, 1: string}
	 * @throws ToolError When the target is inside the item or cannot hold it.
	 */
	private static function move( array $nodes, array $input ): array {
		$id              = (string) $input['element_id'];
		$to              = (string) ( $input['to_parent_id'] ?? '' );
		[ $path, $node ] = self::find( $nodes, $id, array(), (string) ( $input['tag'] ?? '' ) );
		if ( $to === $id || str_starts_with( $to, $id . '.' ) ) {
			throw new ToolError( 'An item cannot be moved into itself. Nothing was changed.' );
		}
		$marker = '__kuduy_target_' . wp_rand();
		if ( '' !== $to ) {
			[ $to_path, $target ] = self::find( $nodes, $to );
			$target[ $marker ]    = true;
			$nodes                = Layout::put( $nodes, $to_path, $target );
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
	 * @param string                     $into   Id so far.
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

	/**
	 * Set or remove the page's custom CSS.
	 *
	 * @param \WP_Post             $post  Post.
	 * @param array<string, mixed> $input css, dry_run.
	 * @return array<string, mixed>
	 */
	private static function page_css( \WP_Post $post, array $input ): array {
		$old = (string) get_post_meta( $post->ID, Avada::META_CSS, true );
		$new = trim( sanitize_textarea_field( wp_strip_all_tags( (string) $input['css'] ) ) );
		$dry = ! empty( $input['dry_run'] );
		if ( ! $dry && $new !== $old ) {
			if ( '' === $new ) {
				delete_post_meta( $post->ID, Avada::META_CSS );
			} else {
				update_post_meta( $post->ID, Avada::META_CSS, wp_slash( $new ) );
			}
			// A normal WordPress update runs save_post, where Avada refreshes the page's cached styles.
			wp_update_post( array( 'ID' => $post->ID ) );
		}
		$stored = (string) get_post_meta( $post->ID, Avada::META_CSS, true );
		return array(
			'post_id'  => $post->ID,
			'saved'    => ! $dry && $new !== $old,
			'verified' => $dry ? false : $stored === $new,
			'change'   => Avada::diff( $old, $new ),
		) + WpBakery::links( $post );
	}
}
