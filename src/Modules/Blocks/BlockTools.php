<?php
/**
 * Tools: kuduy-block-types, kuduy-post-blocks.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Blocks;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Block types registered on the site (core's block-types route) and the block tree of one post (parse_blocks()), so
 * Claude can see which blocks exist and how a post is built before changing it with kuduy-post-update.
 */
final class BlockTools implements Tool {

	/**
	 * Longest text kept per block.
	 */
	private const TEXT = 300;

	/**
	 * Mode.
	 *
	 * @param string $mode types | post.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'types' === $this->mode ? 'kuduy-block-types' : 'kuduy-post-blocks';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'types' === $this->mode ? __( 'Block types', 'kuduy-mcp' ) : __( 'Blocks of a post', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'types' === $this->mode
			? 'Lists the block types registered on the site (core and plugins): name, title, category, description, attributes (name and type), supports, parent. Filter with prefix (e.g. "core/" or "woocommerce/").'
			: 'Returns the block tree of one post as WordPress parses it: path (e.g. "0.2.1"), block name, attributes, number of inner blocks and the start of its text. Change the content with kuduy-post-update.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$properties = 'types' === $this->mode
			? array(
				'prefix' => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
			)
			: array(
				'id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			);
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => 'types' === $this->mode ? array() : array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		$list = array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		);
		return 'types' === $this->mode
			? array(
				'type'       => 'object',
				'properties' => array( 'block_types' => $list ),
				'required'   => array( 'block_types' ),
			)
			: array(
				'type'       => 'object',
				'properties' => array(
					'id'          => array( 'type' => 'integer' ),
					'has_blocks'  => array( 'type' => 'boolean' ),
					'blocks'      => $list,
					'block_count' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'id', 'has_blocks', 'blocks', 'block_count' ),
			);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return Content::read_only();
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
		if ( 'types' === $this->mode ) {
			return 'edit_posts';
		}
		$post = Content::post( (int) ( $input['id'] ?? 0 ) );
		if ( null === $post ) {
			return 'read';
		}
		// Unpublished content is for those who can edit it (as kuduy-post-get).
		return 'publish' === $post->post_status ? array( 'read_post', $post->ID ) : array( 'edit_post', $post->ID );
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
	 * @throws ToolError When the post is missing or core refuses.
	 */
	public function execute( array $input ): array {
		if ( 'types' === $this->mode ) {
			$prefix = (string) ( $input['prefix'] ?? '' );
			$out    = array();
			foreach ( (array) CoreRest::call( 'GET', '/wp/v2/block-types' ) as $type ) {
				$type = (array) $type;
				$name = (string) ( $type['name'] ?? '' );
				if ( '' !== $prefix && ! str_starts_with( $name, $prefix ) ) {
					continue;
				}
				$attributes = array();
				foreach ( (array) ( $type['attributes'] ?? array() ) as $key => $definition ) {
					$attributes[ (string) $key ] = is_array( $definition ) ? (string) ( is_array( $definition['type'] ?? null ) ? implode( '|', $definition['type'] ) : ( $definition['type'] ?? '' ) ) : '';
				}
				$out[] = array(
					'name'        => $name,
					'title'       => (string) ( $type['title'] ?? '' ),
					'category'    => (string) ( $type['category'] ?? '' ),
					'description' => (string) ( $type['description'] ?? '' ),
					'attributes'  => (object) $attributes,
					'supports'    => array_keys( (array) ( $type['supports'] ?? array() ) ),
					'parent'      => array_values( (array) ( $type['parent'] ?? array() ) ),
				);
			}
			return array( 'block_types' => $out );
		}//end if
		$post = Content::post( (int) $input['id'] );
		if ( null === $post ) {
			throw new ToolError( 'Post ' . (int) $input['id'] . ' was not found (or is not a content post type).' );
		}
		AuditLog::target( 'post', (string) $post->ID );
		$blocks = array();
		self::flatten( parse_blocks( $post->post_content ), '', $blocks );
		return array(
			'id'          => $post->ID,
			'has_blocks'  => has_blocks( $post->post_content ),
			'blocks'      => $blocks,
			'block_count' => count( $blocks ),
		);
	}

	/**
	 * Parsed blocks as a flat list with paths (freeform whitespace between blocks is left out).
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param string                           $prefix Path of the parent.
	 * @param list<array<string, mixed>>       $out    Result.
	 * @param-out list<array<string, mixed>>   $out
	 */
	private static function flatten( array $blocks, string $prefix, array &$out ): void {
		$index = 0;
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;
			$html = (string) ( $block['innerHTML'] ?? '' );
			if ( null === $name && '' === trim( $html ) ) {
				continue;
			}
			$path  = '' === $prefix ? (string) $index : $prefix . '.' . $index;
			$text  = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
			$inner = (array) ( $block['innerBlocks'] ?? array() );
			$out[] = array(
				'path'         => $path,
				'name'         => null === $name ? 'core/freeform' : (string) $name,
				'attributes'   => (object) (array) ( $block['attrs'] ?? array() ),
				'inner_blocks' => count( $inner ),
				'text'         => mb_substr( $text, 0, self::TEXT ),
			);
			self::flatten( $inner, $path, $out );
			++$index;
		}
	}
}
