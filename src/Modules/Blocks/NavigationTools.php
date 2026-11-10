<?php
/**
 * Tools: kuduy-nav-menus, kuduy-nav-menu-get, kuduy-nav-menu-save, kuduy-nav-menu-trash.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Blocks;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Block navigation menus (post type wp_navigation, used by the Navigation block of block themes) through core's
 * wp/v2/navigation route (D18 A; every capability maps to edit_theme_options). Menus can be given as block markup or
 * as a simple list of links, which becomes core/navigation-link and core/navigation-submenu blocks. Changes and trash
 * have an undo; a new menu is undone with kuduy-nav-menu-trash. Classic menus keep their own kuduy-menu-* tools.
 */
final class NavigationTools implements Tool, Snapshottable {

	private const ROUTE = '/wp/v2/navigation';

	/**
	 * Mode.
	 *
	 * @param string $mode list | get | save | trash.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'list' === $this->mode ? 'kuduy-nav-menus' : 'kuduy-nav-menu-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'list'  => __( 'Navigation menus (blocks)', 'kuduy-mcp' ),
			'get'   => __( 'Read a navigation menu', 'kuduy-mcp' ),
			'save'  => __( 'Save a navigation menu', 'kuduy-mcp' ),
			default => __( 'Trash a navigation menu', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'list'  => 'Lists the block navigation menus (used by the Navigation block in block themes): id, title, status, number of links. Classic menus are listed by kuduy-menu-list.',
			'get'   => 'Returns one block navigation menu: its links as a tree (label, url, kind, children) and its block markup.',
			'save'  => 'Creates a block navigation menu (no id) or changes one (id): title, and either items (a list of links: label, url, optional children for a submenu) or content (block markup). Changes are snapshotted; undo with kuduy-snapshot-restore. Undo a new menu with kuduy-nav-menu-trash.',
			default => 'Moves a block navigation menu to the trash (not deleted). Navigation blocks that point at it fall back to WordPress\'s default menu. Snapshot first; undo with kuduy-snapshot-restore.',
		};
	}

	/**
	 * Schema of one link (recursive by convention: children use the same shape).
	 *
	 * @return array<string, mixed>
	 */
	private static function item_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'label'    => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'url'      => array(
					'type'      => 'string',
					'maxLength' => 2000,
				),
				'children' => array(
					'type'     => 'array',
					'maxItems' => 50,
					'items'    => array( 'type' => 'object' ),
				),
			),
			'required'   => array( 'label' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$id         = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$properties = array();
		$required   = array();
		if ( in_array( $this->mode, array( 'get', 'trash' ), true ) ) {
			$properties = array( 'id' => $id );
			$required   = array( 'id' );
		} elseif ( 'save' === $this->mode ) {
			$properties = array(
				'id'      => $id,
				'title'   => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'items'   => array(
					'type'     => 'array',
					'maxItems' => 100,
					'items'    => self::item_schema(),
				),
				'content' => array(
					'type'      => 'string',
					'maxLength' => 200000,
				),
			);
		}//end if
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
		if ( 'list' === $this->mode ) {
			return array(
				'type'       => 'object',
				'properties' => array(
					'menus' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
				),
				'required'   => array( 'menus' ),
			);
		}
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'           => array( 'type' => 'integer' ),
				'title'        => array( 'type' => 'string' ),
				'status'       => array( 'type' => 'string' ),
				'items'        => array( 'type' => 'array' ),
				'content'      => array( 'type' => 'string' ),
				'created'      => array( 'type' => 'boolean' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'id', 'title', 'status', 'items' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		if ( in_array( $this->mode, array( 'list', 'get' ), true ) ) {
			return Content::read_only();
		}
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => 'trash' === $this->mode,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return in_array( $this->mode, array( 'list', 'get' ), true ) ? 'kuduy:read' : 'kuduy:write';
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
		return post_type_exists( 'wp_navigation' ) ? true : 'This WordPress has no block navigation menus';
	}

	/**
	 * A navigation menu post, or a ToolError.
	 *
	 * @param int $id Menu id.
	 * @throws ToolError When there is none.
	 */
	private static function menu( int $id ): \WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof \WP_Post || 'wp_navigation' !== $post->post_type ) {
			throw new ToolError( 'No block navigation menu with id ' . (int) $id . '.' );
		}
		return $post;
	}

	/**
	 * Links of navigation markup as a tree.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return list<array<string, mixed>>
	 */
	public static function items( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			$name  = (string) ( $block['blockName'] ?? '' );
			$attrs = (array) ( $block['attrs'] ?? array() );
			if ( in_array( $name, array( 'core/navigation-link', 'core/navigation-submenu' ), true ) ) {
				$item     = array(
					'label' => wp_strip_all_tags( (string) ( $attrs['label'] ?? '' ) ),
					'url'   => (string) ( $attrs['url'] ?? '' ),
					'kind'  => (string) ( $attrs['kind'] ?? 'custom' ),
				);
				$children = self::items( (array) ( $block['innerBlocks'] ?? array() ) );
				if ( $children ) {
					$item['children'] = $children;
				}
				$out[] = $item;
			} elseif ( '' !== $name ) {
				$out[] = array(
					'block' => $name,
					'label' => (string) ( $attrs['label'] ?? '' ),
				);
			}
		}//end foreach
		return $out;
	}

	/**
	 * Block markup for a list of links.
	 *
	 * @param array<int, mixed> $items Links.
	 * @param int               $depth Nesting depth.
	 * @throws ToolError When a link is malformed or nesting is too deep.
	 */
	public static function markup( array $items, int $depth = 0 ): string {
		if ( $depth > 3 ) {
			throw new ToolError( 'Submenus can be nested at most 3 levels deep.' );
		}
		$out = '';
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || '' === trim( (string) ( $item['label'] ?? '' ) ) ) {
				throw new ToolError( 'Every link needs a label.' );
			}
			$url      = esc_url_raw( (string) ( $item['url'] ?? '' ) );
			$attrs    = array(
				'label' => sanitize_text_field( (string) $item['label'] ),
				'url'   => '' !== $url ? $url : '#',
				'kind'  => 'custom',
			);
			$children = (array) ( $item['children'] ?? array() );
			if ( $children ) {
				$out .= get_comment_delimited_block_content( 'core/navigation-submenu', $attrs, self::markup( $children, $depth + 1 ) );
			} else {
				$out .= get_comment_delimited_block_content( 'core/navigation-link', $attrs, '' );
			}
		}
		return $out;
	}

	/**
	 * One menu in the tool's shape.
	 *
	 * @param \WP_Post $post Menu.
	 * @return array<string, mixed>
	 */
	private static function shape( \WP_Post $post ): array {
		return array(
			'id'      => $post->ID,
			'title'   => (string) $post->post_title,
			'status'  => (string) $post->post_status,
			'items'   => self::items( parse_blocks( $post->post_content ) ),
			'content' => (string) $post->post_content,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the menu is missing.
	 */
	public function snapshot( array $input ): ?array {
		$id = (int) ( $input['id'] ?? 0 );
		if ( ! in_array( $this->mode, array( 'save', 'trash' ), true ) || $id <= 0 ) {
			return null;
		}
		$post = self::menu( $id );
		return array(
			'object_type' => 'wp_navigation',
			'object_id'   => (string) $id,
			'payload'     => array(
				'id'      => $id,
				'title'   => (string) $post->post_title,
				'content' => (string) $post->post_content,
				'status'  => (string) $post->post_status,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the menu is gone or core refuses.
	 */
	public function restore( array $payload ): void {
		$id   = (int) ( $payload['id'] ?? 0 );
		$post = self::menu( $id );
		if ( 'trash' === $post->post_status ) {
			wp_untrash_post( $id );
		}
		CoreRest::call(
			'POST',
			self::ROUTE . '/' . $id,
			array(
				'title'   => (string) ( $payload['title'] ?? '' ),
				'content' => (string) ( $payload['content'] ?? '' ),
				'status'  => (string) ( $payload['status'] ?? 'publish' ),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the menu is missing, the input incomplete or core refuses.
	 */
	public function execute( array $input ): array {
		if ( 'list' === $this->mode ) {
			$menus = array();
			foreach ( get_posts(
				array(
					'post_type'      => 'wp_navigation',
					'post_status'    => array( 'publish', 'draft' ),
					'posts_per_page' => 100,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			) as $post ) {
				$menus[] = array(
					'id'     => $post->ID,
					'title'  => (string) $post->post_title,
					'status' => (string) $post->post_status,
					'links'  => count( self::items( parse_blocks( $post->post_content ) ) ),
				);
			}
			return array( 'menus' => $menus );
		}
		$id = (int) ( $input['id'] ?? 0 );
		if ( 'get' === $this->mode ) {
			AuditLog::target( 'wp_navigation', (string) $id );
			return self::shape( self::menu( $id ) );
		}
		if ( 'trash' === $this->mode ) {
			self::menu( $id );
			AuditLog::target( 'wp_navigation', (string) $id );
			CoreRest::call( 'DELETE', self::ROUTE . '/' . $id );
			return self::shape( self::menu( $id ) );
		}
		$body = array();
		if ( isset( $input['title'] ) ) {
			$body['title'] = (string) $input['title'];
		}
		if ( isset( $input['items'] ) ) {
			$body['content'] = self::markup( (array) $input['items'] );
		} elseif ( isset( $input['content'] ) ) {
			$body['content'] = (string) $input['content'];
		}
		if ( $id > 0 ) {
			self::menu( $id );
			if ( ! $body ) {
				throw new ToolError( 'Give title, items or content. Nothing was changed.' );
			}
			AuditLog::target( 'wp_navigation', (string) $id );
			CoreRest::call( 'POST', self::ROUTE . '/' . $id, $body );
			return self::shape( self::menu( $id ) ) + array( 'created' => false );
		}
		if ( empty( $body['title'] ) ) {
			throw new ToolError( 'A new menu needs a title. Nothing was created.' );
		}
		$created = (array) CoreRest::call( 'POST', self::ROUTE, $body + array( 'status' => 'publish' ) );
		$new     = (int) ( $created['id'] ?? 0 );
		AuditLog::target( 'wp_navigation', (string) $new );
		return self::shape( self::menu( $new ) ) + array( 'created' => true );
	}
}
