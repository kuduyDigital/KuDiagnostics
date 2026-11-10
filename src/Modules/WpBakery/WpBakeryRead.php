<?php
/**
 * Tools: kuduy-wpbakery-structure, -tree, -content, -elements, -validate.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\WpBakery;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Read-only WPBakery Page Builder tools: the layout of a page as a structure or a compact tree, its raw shortcodes,
 * the elements registered on this site with their settings (from WPBMap, never a fixed list), and a validator for a
 * page or for shortcode text that has not been saved yet.
 */
final class WpBakeryRead implements Tool {

	/**
	 * Mode.
	 *
	 * @param string $mode structure | tree | content | elements | validate.
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
			'structure' => __( 'WPBakery page structure', 'kuduy-mcp' ),
			'tree'      => __( 'WPBakery element tree', 'kuduy-mcp' ),
			'content'   => __( 'WPBakery page shortcodes', 'kuduy-mcp' ),
			'elements'  => __( 'WPBakery elements on this site', 'kuduy-mcp' ),
			default     => __( 'Validate a WPBakery layout', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'structure' => 'Returns the WPBakery Page Builder layout of a page as a structure: rows, columns, nested rows and elements, each with its id, tag, attributes and content, in page order. With element_id only that part is returned. Ids are positions such as "0.1.2" and change after every edit. Start with kuduy-wpbakery-tree for an overview.',
			'tree'      => 'Returns the WPBakery layout of a page as a compact tree: one line per row, column and element with its id, plus the tree as text and a hash to pass as if_hash when editing. Use the ids with the kuduy-wpbakery-* editing tools.',
			'content'   => 'Returns the complete raw WPBakery shortcode content of a page, exactly as stored, with the page\'s builder (wpbakery, elementor or none) and Design Options CSS.',
			'elements'  => 'Lists the WPBakery elements registered on this site (WPBakery\'s own, the theme\'s and add-ons\'): tag, title, category, description, whether it is a container. With tag it returns every setting of that element: name, type, heading, default, options, group, and which one holds the content. Filter the list with search or category. WPBakery has no "required" flag for settings.',
			default     => 'Checks a WPBakery layout without saving: shortcode syntax, closing tags, rows and columns in the right places, unknown elements, attributes that are not registered settings, option values. Give post_id to check a saved page, or content to check shortcode text before saving it. Errors would break the page; warnings are worth a look.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$post       = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$properties = match ( $this->mode ) {
			'structure' => array(
				'post_id'    => $post,
				'element_id' => array(
					'type'        => 'string',
					'maxLength'   => 100,
					'description' => 'Return only this item and what is inside it.',
				),
			),
			'elements'  => array(
				'tag'      => array(
					'type'        => 'string',
					'maxLength'   => 64,
					'description' => 'One element, with all its settings.',
				),
				'search'   => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
				'category' => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
			),
			'validate'  => array(
				'post_id' => $post,
				'content' => array(
					'type'        => 'string',
					'maxLength'   => Shortcodes::MAX_BYTES,
					'description' => 'Shortcode text to check instead of a saved page.',
				),
			),
			default     => array( 'post_id' => $post ),
		};//end match
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => in_array( $this->mode, array( 'structure', 'tree', 'content' ), true ) ? array( 'post_id' ) : array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		$list       = array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		);
		$properties = match ( $this->mode ) {
			'structure' => array(
				'post'      => array( 'type' => 'object' ),
				'builder'   => array( 'type' => 'string' ),
				'hash'      => array( 'type' => 'string' ),
				'structure' => $list,
			),
			'tree'      => array(
				'post'     => array( 'type' => 'object' ),
				'builder'  => array( 'type' => 'string' ),
				'hash'     => array( 'type' => 'string' ),
				'elements' => array( 'type' => 'integer' ),
				'items'    => $list,
				'tree'     => array( 'type' => 'string' ),
			),
			'content'   => array(
				'post'       => array( 'type' => 'object' ),
				'builder'    => array( 'type' => 'string' ),
				'hash'       => array( 'type' => 'string' ),
				'content'    => array( 'type' => 'string' ),
				'custom_css' => array( 'type' => 'string' ),
			),
			'elements'  => array(
				'wpbakery_version' => array( 'type' => 'string' ),
				'total'            => array( 'type' => 'integer' ),
				'elements'         => $list,
				'element'          => array( 'type' => 'object' ),
			),
			default     => array(
				'valid'    => array( 'type' => 'boolean' ),
				'elements' => array( 'type' => 'integer' ),
				'errors'   => $list,
				'warnings' => $list,
			),
		};//end match
		return array(
			'type'       => 'object',
			'properties' => $properties,
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
	 * @throws ToolError When the page or element is missing.
	 */
	public function execute( array $input ): array {
		if ( 'elements' === $this->mode ) {
			return self::elements( $input );
		}
		if ( 'validate' === $this->mode && isset( $input['content'] ) ) {
			$nodes = WpBakery::parse( (string) $input['content'] );
			return self::report( $nodes );
		}
		if ( empty( $input['post_id'] ) ) {
			throw new ToolError( 'Give post_id (a saved page) or content (shortcode text).' );
		}
		$post = WpBakery::post( (int) $input['post_id'] );
		AuditLog::target( $post->post_type, (string) $post->ID );
		$nodes = WpBakery::parse( $post->post_content );
		if ( 'validate' === $this->mode ) {
			return self::report( $nodes );
		}
		$head = array(
			'post'    => Content::summary( $post ) + WpBakery::links( $post ),
			'builder' => WpBakery::builder( $post ),
			'hash'    => WpBakery::hash( $post->post_content ),
		);
		if ( 'content' === $this->mode ) {
			return $head + array(
				'content'    => $post->post_content,
				'custom_css' => (string) get_post_meta( $post->ID, WpBakery::META_CSS, true ),
			);
		}
		if ( 'tree' === $this->mode ) {
			$outline = Layout::outline( $nodes );
			return $head + array(
				'elements' => Layout::count( $nodes ),
				'items'    => $outline,
				'tree'     => Layout::drawing( $outline ),
			);
		}
		$structure = Layout::structure( $nodes );
		$id        = (string) ( $input['element_id'] ?? '' );
		if ( '' !== $id ) {
			[ $path ] = Layout::find( $nodes, $id );
			$parent   = str_contains( $id, '.' ) ? substr( $id, 0, (int) strrpos( $id, '.' ) ) : '';
			$one      = Layout::structure( array( Layout::at( $nodes, $path ) ), $parent );
			// The single item is numbered 0 by structure(); give it its real id back.
			$structure = self::renumber( $one, Layout::child_id( $parent, 0 ), $id );
		}
		return $head + array( 'structure' => $structure );
	}

	/**
	 * Replace an id prefix in a structure.
	 *
	 * @param list<array<string, mixed>> $items Items.
	 * @param string                     $from  Prefix written by structure().
	 * @param string                     $to    Real id.
	 * @return list<array<string, mixed>>
	 */
	private static function renumber( array $items, string $from, string $to ): array {
		foreach ( $items as $i => $item ) {
			$items[ $i ]['id'] = $to . substr( (string) $item['id'], strlen( $from ) );
			if ( isset( $item['children'] ) ) {
				$items[ $i ]['children'] = self::renumber( $item['children'], $from, $to );
			}
		}
		return $items;
	}

	/**
	 * Validation result.
	 *
	 * @param list<array<string, mixed>> $nodes Layout.
	 * @return array<string, mixed>
	 */
	public static function report( array $nodes ): array {
		$report = Layout::validate( $nodes );
		return array(
			'valid'    => ! $report['errors'],
			'elements' => Layout::count( $nodes ),
			'errors'   => $report['errors'],
			'warnings' => $report['warnings'],
		);
	}

	/**
	 * Registered elements, or one with its settings.
	 *
	 * @param array<string, mixed> $input tag, search, category.
	 * @return array<string, mixed>
	 * @throws ToolError When the element is not registered.
	 */
	private static function elements( array $input ): array {
		$map = WpBakery::map();
		$tag = (string) ( $input['tag'] ?? '' );
		if ( '' !== $tag ) {
			if ( ! isset( $map[ $tag ] ) ) {
				throw new ToolError( 'No WPBakery element "' . esc_html( $tag ) . '" is registered on this site. See kuduy-wpbakery-elements without tag.' );
			}
			return array(
				'wpbakery_version' => WpBakery::version(),
				'total'            => count( $map ),
				'element'          => self::summary( $tag, $map[ $tag ] ) + array( 'params' => self::params( $map[ $tag ] ) ),
			);
		}
		$search   = strtolower( (string) ( $input['search'] ?? '' ) );
		$category = strtolower( (string) ( $input['category'] ?? '' ) );
		$out      = array();
		foreach ( $map as $name => $settings ) {
			$item = self::summary( $name, $settings );
			if ( '' !== $search && ! str_contains( strtolower( $name . ' ' . $item['title'] . ' ' . $item['description'] ), $search ) ) {
				continue;
			}
			if ( '' !== $category && ! str_contains( strtolower( $item['category'] ), $category ) ) {
				continue;
			}
			$out[] = $item + array( 'params' => array_column( self::params( $settings ), 'name' ) );
		}
		return array(
			'wpbakery_version' => WpBakery::version(),
			'total'            => count( $out ),
			'elements'         => $out,
		);
	}

	/**
	 * Summary of one element.
	 *
	 * @param string               $tag      Tag.
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, mixed>
	 */
	private static function summary( string $tag, array $settings ): array {
		$category = $settings['category'] ?? '';
		$only     = $settings['as_parent']['only'] ?? '';
		return array(
			'tag'               => $tag,
			'title'             => wp_strip_all_tags( (string) ( $settings['name'] ?? $tag ) ),
			'category'          => wp_strip_all_tags( is_array( $category ) ? implode( ', ', array_map( 'strval', $category ) ) : (string) $category ),
			'description'       => wp_strip_all_tags( (string) ( $settings['description'] ?? '' ) ),
			'container'         => ! empty( $settings['is_container'] ) || ! empty( $settings['as_parent'] ) || isset( Layout::ROWS[ $tag ] ) || in_array( $tag, Layout::COLUMNS, true ),
			'holds_only'        => is_string( $only ) ? $only : '',
			'has_content'       => null !== WpBakery::param( $tag, 'content' ),
			'deprecated'        => ! empty( $settings['deprecated'] ),
			'in_element_picker' => ! isset( $settings['content_element'] ) || false !== $settings['content_element'],
		);
	}

	/**
	 * Settings (parameters) of one element.
	 *
	 * @param array<string, mixed> $settings Element settings.
	 * @return list<array<string, mixed>>
	 */
	private static function params( array $settings ): array {
		$out = array();
		foreach ( (array) ( $settings['params'] ?? array() ) as $param ) {
			if ( ! is_array( $param ) || ! is_string( $param['param_name'] ?? null ) ) {
				continue;
			}
			$type  = (string) ( $param['type'] ?? '' );
			$value = $param['value'] ?? null;
			$item  = array(
				'name'       => $param['param_name'],
				'type'       => $type,
				'heading'    => wp_strip_all_tags( (string) ( $param['heading'] ?? '' ) ),
				'is_content' => 'content' === $param['param_name'],
			);
			if ( '' !== (string) ( $param['description'] ?? '' ) ) {
				$item['description'] = wp_strip_all_tags( (string) $param['description'] );
			}
			if ( '' !== (string) ( $param['group'] ?? '' ) ) {
				$item['group'] = wp_strip_all_tags( (string) $param['group'] );
			}
			if ( is_array( $value ) && in_array( $type, array( 'dropdown', 'checkbox' ), true ) ) {
				$options = array();
				foreach ( $value as $label => $option ) {
					if ( is_scalar( $option ) ) {
						$options[] = array(
							'label' => is_string( $label ) ? wp_strip_all_tags( $label ) : (string) $option,
							'value' => (string) $option,
						);
					} elseif ( is_array( $option ) && isset( $option['value'] ) && is_scalar( $option['value'] ) ) {
						$options[] = array(
							'label' => wp_strip_all_tags( (string) ( $option['label'] ?? $option['value'] ) ),
							'value' => (string) $option['value'],
						);
					}
				}
				$item['options'] = array_slice( $options, 0, 200 );
			}
			if ( isset( $param['std'] ) && is_scalar( $param['std'] ) ) {
				$item['default'] = (string) $param['std'];
			} elseif ( is_scalar( $value ) ) {
				$item['default'] = (string) $value;
			} elseif ( 'dropdown' === $type && ! empty( $item['options'] ) ) {
				$item['default'] = $item['options'][0]['value'];
			}
			if ( isset( $param['dependency'] ) && is_array( $param['dependency'] ) && isset( $param['dependency']['element'] ) ) {
				$item['shown_when'] = array_intersect_key( $param['dependency'], array_flip( array( 'element', 'value', 'not_empty', 'value_not_equal_to' ) ) );
			}
			$item['how_to_set'] = match ( $type ) {
				'vc_link'       => 'object: url, title, target, rel',
				'param_group'   => 'list of objects (one per row)',
				'attach_image'  => 'media id',
				'attach_images' => 'list of media ids',
				'css_editor'    => 'use the design input (margins, padding, border, background)',
				'checkbox'      => 'true/false, or the option value',
				default         => 'text',
			};
			$out[] = $item;
		}//end foreach
		return $out;
	}
}
